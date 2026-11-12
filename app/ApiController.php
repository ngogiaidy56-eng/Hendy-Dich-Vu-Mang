<?php
declare(strict_types=1);

namespace Hendy;

use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class HttpException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}

final class ApiController
{
    private const CATEGORIES = ['minigame', 'tools', 'codes', 'otp', 'proxy', 'vip'];
    private const ORDER_STATUSES = ['pending', 'processing', 'completed', 'rejected', 'cancelled', 'refunded'];
    private const SETTINGS = [
        'homepage_title', 'announcement', 'website_url', 'VIETQR_BANK_ID', 'VIETQR_ACCOUNT_NO',
        'VIETQR_ACCOUNT_NAME', 'GROUP_URL', 'CHANNEL_URL', 'ABCVIP_CONNECT_URL',
        'referral_commission_percent', 'support_message', 'vip_checkin_points', 'replay_live_url',
    ];
    private const MENU_ACTIONS = BotMenuDefinition::ACTIONS;

    private array $query;
    private array $body;
    private array $headers;
    private ?array $user = null;
    private string $botKey = 'primary';

    public function __construct(array $query, array $body, array $headers)
    {
        $this->query = $query;
        $this->body = $body;
        $this->headers = $headers;
    }

    private function publicBotUrl(?string $botKey = null): string
    {
        $key = $botKey ?? $this->botKey;
        $variable = $key === 'secondary' ? 'BOT2_PUBLIC_URL' : 'BOT1_PUBLIC_URL';
        return TelegramLink::normalize((string) Env::get($variable, ''));
    }

    public function uploadModule(array $file, string $module): array
    {
        try { $this->user = TelegramAuth::authenticateRequest(); } catch (Throwable $error) { throw new HttpException(401, 'Yêu cầu xác thực Telegram.'); }
        $this->requireAdmin();
        try { $artifact = ModuleRegistry::stage($file, $module, (string) $this->user['id']); }
        catch (Throwable $error) { throw new HttpException(400, $error->getMessage()); }
        $this->audit('module.upload', (string) ($artifact['id'] ?? ''), ['module' => $module, 'status' => 'pending_review']);
        return self::response(['artifact' => $artifact, 'message' => 'Tệp đã vào vùng chờ kiểm duyệt; chưa được thực thi hoặc phát hành.'], 201);
    }

    public function handle(string $method, string $path): array
    {
        if ($method === 'GET' && $path === '/api/health') {
            return self::response([
                'service' => 'hendy-bot-tele-php',
                'status' => Database::check() ? 'ok' : 'degraded',
                'database' => 'ok',
                'telegram' => [
                    'primaryConfigured' => TelegramAuth::token('primary') !== '',
                    'secondaryConfigured' => TelegramAuth::token('secondary') !== '',
                ],
                'timestamp' => gmdate(DATE_ATOM),
            ]);
        }

        if ($method === 'POST' && $path === '/api/legacy/session/exchange') {
            return $this->exchangeLegacySession();
        }

        if (!str_starts_with($path, '/api/')) {
            throw new HttpException(404, 'Không tìm thấy API.');
        }

        try {
            $this->user = TelegramAuth::authenticateRequest();
            $this->botKey = (string) ($this->user['_bot_key'] ?? 'primary');
        } catch (Throwable $error) {
            throw new HttpException(401, $error->getMessage() ?: 'Yêu cầu xác thực Telegram.');
        }

        $userId = (string) $this->user['id'];

        if (WebServiceKernel::handles($path)) {
            try {
                $result = WebServiceKernel::dispatch($method, $path, $this->body, [
                    'userId' => $userId,
                    'botKey' => $this->botKey,
                    'isAdmin' => $this->isAdmin(),
                ]);
                return self::response($result);
            } catch (HttpException $error) {
                throw $error;
            } catch (Throwable $error) {
                throw new HttpException(400, $error->getMessage() ?: 'Không thể xử lý Web Service.');
            }
        }

        if ($method === 'GET' && $path === '/api/bootstrap') {
            $user = $this->userRow();
            $referrals = (int) Database::value('SELECT COUNT(*) FROM users WHERE ref_by = ?', [$userId]);
            $publicSettings = $this->settings(self::SETTINGS, true);
            return self::response([
                'user' => $user,
                'isAdmin' => $this->isAdmin(),
                'botKey' => $this->botKey,
                'products' => $this->listProducts(null, false),
                'settings' => $publicSettings,
                'referralCount' => $referrals,
                'botUrl' => $this->publicBotUrl(),
            ]);
        }

        if ($method === 'GET' && $path === '/api/products') {
            $category = $this->text($this->query['category'] ?? '', 32);
            return self::response(['products' => $this->listProducts($category !== '' ? $category : null, false)]);
        }

        if ($method === 'GET' && $path === '/api/me') {
            return self::response(['user' => $this->userRow(), 'isAdmin' => $this->isAdmin()]);
        }

        if ($method === 'GET' && $path === '/api/customer-center') {
            $profile = $this->userRow();
            $stats = Database::one(
                "SELECT COUNT(*) AS total_orders,
                        COUNT(*) FILTER (WHERE status = 'processing') AS processing_orders,
                        COUNT(*) FILTER (WHERE status = 'completed') AS completed_orders,
                        COUNT(*) FILTER (WHERE status = 'cancelled') AS cancelled_orders,
                        COUNT(*) FILTER (
                            WHERE status = 'refunded'
                               OR EXISTS (
                                   SELECT 1 FROM transactions tx
                                   WHERE tx.user_id = orders.user_id
                                     AND tx.reference_code = orders.order_code
                                     AND tx.type = 'ORDER_REFUND'
                               )
                        ) AS refunded_orders
                 FROM orders WHERE user_id = ?",
                [$userId]
            ) ?? [];
            $ticketCounts = Database::one(
                "SELECT COUNT(*) AS total,
                        COUNT(*) FILTER (WHERE ticket_type = 'dinh_lam') AS abuse,
                        COUNT(*) FILTER (WHERE ticket_type = 'support') AS support
                 FROM support_tickets WHERE user_id = ?",
                [$userId]
            ) ?? [];
            return self::response([
                'user' => $profile,
                'statistics' => [
                    'totalOrders' => (int) ($stats['total_orders'] ?? 0),
                    'processingOrders' => (int) ($stats['processing_orders'] ?? 0),
                    'completedOrders' => (int) ($stats['completed_orders'] ?? 0),
                    'cancelledOrders' => (int) ($stats['cancelled_orders'] ?? 0),
                    'refundedOrders' => (int) ($stats['refunded_orders'] ?? 0),
                ],
                'tickets' => [
                    'total' => (int) ($ticketCounts['total'] ?? 0),
                    'dinhLam' => (int) ($ticketCounts['abuse'] ?? 0),
                    'support' => (int) ($ticketCounts['support'] ?? 0),
                ],
                'recentOrders' => Database::all(
                    'SELECT id, order_code, product_name, amount, status, created_at FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 5',
                    [$userId]
                ),
                'recentTransactions' => Database::all(
                    'SELECT id, amount, type, reference_code, status, wallet_type, created_at FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 10',
                    [$userId]
                ),
                'walletHistory' => Database::all(
                    'SELECT id, amount, transaction_type, wallet_type, description, reference_code, created_at FROM wallet_ledger WHERE user_id = ? ORDER BY created_at DESC LIMIT 10',
                    [$userId]
                ),
            ]);
        }

        if ($path === '/api/linked-accounts' && $method === 'GET') {
            return self::response(['accounts' => Database::all(
                'SELECT id, brand, username, created_at FROM linked_accounts WHERE user_id = ? ORDER BY created_at DESC',
                [$userId]
            )]);
        }
        if ($path === '/api/linked-accounts' && $method === 'POST') {
            $brand = strtoupper($this->text($this->body['brand'] ?? '', 16));
            $username = $this->text($this->body['username'] ?? '', 64);
            if (!in_array($brand, ['QQ88', 'HI88', '78WIN', 'RR88', 'MM88', 'GG88', 'ABCVIP', 'XX8', 'U888', 'J88', 'ABC8', '88CLB', 'AD88', 'SC88', 'C168', 'CM88', 'F8BET'], true)) {
                throw new HttpException(400, 'Thương hiệu không nằm trong danh sách hỗ trợ.');
            }
            if (mb_strlen($username) < 3 || preg_match('/[\x00-\x1F]/', $username)) {
                throw new HttpException(400, 'Tên đăng nhập phải từ 3 đến 64 ký tự.');
            }
            $row = Database::one(
                'INSERT INTO linked_accounts (user_id, brand, username) VALUES (?, ?, ?) ' .
                'ON CONFLICT (user_id, brand, username) DO NOTHING RETURNING id, brand, username, created_at',
                [$userId, $brand, $username]
            );
            if ($row === null) {
                throw new HttpException(409, 'Tài khoản này đã được liên kết.');
            }
            return self::response(['account' => $row], 201);
        }
        if (preg_match('#^/api/linked-accounts/(\d{1,18})$#', $path, $match) && $method === 'DELETE') {
            $deleted = Database::run('DELETE FROM linked_accounts WHERE id = ? AND user_id = ? RETURNING id', [$match[1], $userId]);
            if ($deleted->rowCount() === 0) {
                throw new HttpException(404, 'Không tìm thấy tài khoản liên kết.');
            }
            return self::response(['success' => true]);
        }

        if ($path === '/api/orders' && $method === 'GET') {
            return self::response(['orders' => Database::all(
                "SELECT id, order_code, product_name, category, amount, status, admin_note, " .
                "CASE WHEN status = 'completed' THEN delivery_text ELSE '' END AS delivery_text, created_at, updated_at " .
                'FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 100',
                [$userId]
            )]);
        }
        if ($path === '/api/orders' && $method === 'POST') {
            $productId = (string) ($this->body['productId'] ?? '');
            if (!preg_match('/^\d{1,18}$/', $productId)) {
                throw new HttpException(400, 'Sản phẩm không hợp lệ.');
            }
            $order = Database::transaction(function () use ($userId, $productId): array {
                $product = Database::one('SELECT * FROM products WHERE id = ? AND is_active = TRUE FOR UPDATE', [$productId]);
                if ($product === null) throw new HttpException(404, 'Sản phẩm hiện không còn bán.');
                if ($product['stock'] !== null && (int) $product['stock'] <= 0) throw new HttpException(409, 'Sản phẩm đã hết hàng.');
                Database::run(
                    "INSERT INTO users (user_id, name, username) VALUES (?, ?, ?) ON CONFLICT (user_id) DO NOTHING",
                    [$userId, $this->text($this->user['first_name'] ?? '', 120), $this->text($this->user['username'] ?? '', 64)]
                );
                $member = Database::one('SELECT balance, nyc_wallet, vip_level FROM users WHERE user_id = ? FOR UPDATE', [$userId]);
                if ($member === null) throw new HttpException(404, 'Không tìm thấy hồ sơ thành viên.');
                if ((int) ($product['vip_level'] ?? 0) > (int) ($member['vip_level'] ?? 0)) {
                    throw new HttpException(403, 'Sản phẩm yêu cầu VIP ' . (int) $product['vip_level'] . '.');
                }
                $walletType = in_array((string) $product['category'], ['otp', 'proxy'], true) ? 'NYC' : 'MAIN';
                $walletColumn = $walletType === 'NYC' ? 'nyc_wallet' : 'balance';
                $price = (int) $product['price'];
                if ((int) $member[$walletColumn] < $price) {
                    throw new HttpException(402, 'Số dư Ví ' . $walletType . ' không đủ. Vui lòng nạp/chuyển ví.');
                }
                $orderCode = self::orderCode();
                Database::run("UPDATE users SET {$walletColumn} = {$walletColumn} - ? WHERE user_id = ?", [$price, $userId]);
                $created = Database::one(
                    "INSERT INTO orders (order_code, user_id, product_id, product_name, category, amount, status) " .
                    "VALUES (?, ?, ?, ?, ?, ?, 'pending') RETURNING id, order_code, product_name, category, amount, status, created_at",
                    [$orderCode, $userId, $product['id'], $product['name'], $product['category'], $price]
                );
                Database::run(
                    "INSERT INTO wallet_ledger (reference_code, user_id, amount, transaction_type, description, wallet_type) VALUES (?, ?, ?, 'PURCHASE', ?, ?)",
                    ['order:' . $orderCode, $userId, -$price, 'Thanh toán đơn ' . $orderCode, $walletType]
                );
                Database::run(
                    "INSERT INTO transactions (user_id, amount, type, reference_code, status, wallet_type) VALUES (?, ?, 'PURCHASE', ?, 'paid', ?)",
                    [$userId, $price, $orderCode, $walletType]
                );
                if ($product['stock'] !== null) Database::run('UPDATE products SET stock = stock - 1, updated_at = NOW() WHERE id = ?', [$product['id']]);
                $created['balance'] = (int) Database::value("SELECT {$walletColumn} FROM users WHERE user_id = ?", [$userId]);
                $created['walletType'] = $walletType;
                return $created;
            });
            $this->publishUserDataChanged('order');
            return self::response(['order' => $order, 'message' => 'Đơn hàng đã được tạo và trừ đúng loại ví thành công.'], 201);
        }

        if ($path === '/api/wallet' && $method === 'GET') {
            $user = $this->userRow();
            $history = Database::all(
                'SELECT id, amount, transaction_type, description, reference_code, created_at FROM wallet_ledger WHERE user_id = ? ORDER BY created_at DESC LIMIT 60',
                [$userId]
            );
            return self::response(['balance' => $user['balance'], 'history' => $history]);
        }

        if ($path === '/api/wallet/payment-requests' && $method === 'GET') {
            return self::response(['requests' => PaymentService::forUser($userId, 20)]);
        }
        if ($path === '/api/wallet/payment-requests' && $method === 'POST') {
            $amount = $this->parseMoney($this->body['amount'] ?? null, false);
            if ($amount < 10000 || $amount > 500000000) {
                throw new HttpException(400, 'Số tiền nạp phải từ 10.000 đến 500.000.000 VNĐ.');
            }
            $settings = $this->settings(['VIETQR_BANK_ID', 'VIETQR_ACCOUNT_NO', 'VIETQR_ACCOUNT_NAME'], false);
            $bank = $settings['VIETQR_BANK_ID'] ?: (string) Env::get('VIETQR_BANK_ID', '');
            $account = $settings['VIETQR_ACCOUNT_NO'] ?: (string) Env::get('VIETQR_ACCOUNT_NO', '');
            $holder = $settings['VIETQR_ACCOUNT_NAME'] ?: (string) Env::get('VIETQR_ACCOUNT_NAME', '');
            if ($bank === '' || $account === '' || $holder === '') {
                throw new HttpException(409, 'Quản trị viên chưa cấu hình tài khoản VietQR.');
            }
            try {
                $request = PaymentService::create($userId, $amount, $this->botKey, $bank, $account, $holder);
            } catch (RuntimeException $error) {
                throw new HttpException(400, $error->getMessage());
            }
            $params = http_build_query([
                'amount' => $amount,
                'addInfo' => $request['transfer_content'],
                'accountName' => $holder,
            ]);
            $qrUrl = 'https://img.vietqr.io/image/' . rawurlencode($bank) . '-' . rawurlencode($account) . '-compact2.png?' . $params;
            $this->notifyAdmins(
                '🔔 YÊU CẦU NẠP MỚI ' . $request['payment_id'] . ' · ' . number_format($amount, 0, ',', '.') .
                ' VNĐ · User ' . $userId . ' · Nội dung CK: ' . $request['transfer_content'] . '. Cần đối soát sao kê trước khi duyệt.'
            );
            $this->publishUserDataChanged('payment-request');
            Realtime::publish('admin.payment.created', ['paymentId' => $request['payment_id'], 'botKey' => $this->botKey], 'admin');
            return self::response(['request' => $request, 'qrUrl' => $qrUrl, 'message' => 'Yêu cầu đã tạo và đang chờ Admin đối soát.'], 201);
        }
        if ($path === '/api/wallet/payment-info' && $method === 'GET') {
            $amount = $this->parseMoney($this->query['amount'] ?? 50000, false);
            if ($amount < 10000 || $amount > 500000000) {
                throw new HttpException(400, 'Số tiền nạp phải từ 10.000 đến 500.000.000 VNĐ.');
            }
            $settings = $this->settings(['VIETQR_BANK_ID', 'VIETQR_ACCOUNT_NO', 'VIETQR_ACCOUNT_NAME'], false);
            $bank = $settings['VIETQR_BANK_ID'] ?: (string) Env::get('VIETQR_BANK_ID', '');
            $account = $settings['VIETQR_ACCOUNT_NO'] ?: (string) Env::get('VIETQR_ACCOUNT_NO', '');
            $accountName = $settings['VIETQR_ACCOUNT_NAME'] ?: (string) Env::get('VIETQR_ACCOUNT_NAME', '');
            if ($bank === '' || $account === '' || $accountName === '') {
                throw new HttpException(409, 'Quản trị viên chưa cấu hình tài khoản VietQR.');
            }
            $params = http_build_query([
                'amount' => $amount,
                'addInfo' => 'NAP ' . $userId,
                'accountName' => $accountName,
            ]);
            return self::response([
                'amount' => $amount,
                'accountName' => $accountName,
                'accountNo' => $account,
                'bankId' => $bank,
                'transferContent' => 'NAP ' . $userId,
                'qrUrl' => 'https://img.vietqr.io/image/' . rawurlencode($bank) . '-' . rawurlencode($account) . '-compact2.png?' . $params,
            ]);
        }

        if ($path === '/api/referrals' && $method === 'GET') {
            $count = (int) Database::value('SELECT COUNT(*) FROM users WHERE ref_by = ?', [$userId]);
            $reward = (int) Database::value(
                "SELECT COALESCE(SUM(amount), 0) FROM wallet_ledger WHERE user_id = ? AND transaction_type = 'REFERRAL_REWARD'",
                [$userId]
            );
            $commission = $this->settings(['referral_commission_percent'], true);
            $botUrl = $this->publicBotUrl();
            $link = $botUrl !== '' ? $botUrl . '?start=ref_' . rawurlencode($userId) : '';
            return self::response([
                'totalInvited' => $count,
                'totalReward' => $reward,
                'commissionPercent' => (float) ($commission['referral_commission_percent'] ?? 0),
                'referralLink' => $link,
            ]);
        }

        if ($path === '/api/giftcodes/redeem' && $method === 'POST') {
            $code = strtoupper($this->text($this->body['code'] ?? '', 64));
            if (!preg_match('/^[A-Z0-9_-]{4,64}$/', $code)) {
                throw new HttpException(400, 'Mã giftcode không hợp lệ.');
            }
            $reward = Database::transaction(function () use ($code, $userId): int {
                $gift = Database::one('SELECT * FROM giftcodes WHERE code = ? FOR UPDATE', [$code]);
                if ($gift === null) {
                    throw new HttpException(404, 'Giftcode không tồn tại.');
                }
                if (!$this->toBool($gift['is_active']) || ($gift['expires_at'] && strtotime($gift['expires_at']) < time())) {
                    throw new HttpException(410, 'Giftcode đã hết hạn hoặc bị khóa.');
                }
                if ((int) $gift['redeemed_count'] >= (int) $gift['max_redemptions']) {
                    throw new HttpException(409, 'Giftcode đã được sử dụng hết lượt.');
                }
                $inserted = Database::run(
                    'INSERT INTO giftcode_redemptions (code, user_id, reward_amount) VALUES (?, ?, ?) ON CONFLICT DO NOTHING RETURNING code',
                    [$code, $userId, $gift['reward_amount']]
                );
                if ($inserted->rowCount() === 0) {
                    throw new HttpException(409, 'Bạn đã sử dụng giftcode này rồi.');
                }
                $reward = (int) $gift['reward_amount'];
                if ($reward > 0) {
                    Database::run('UPDATE users SET balance = balance + ? WHERE user_id = ?', [$reward, $userId]);
                    Database::run(
                        "INSERT INTO wallet_ledger (reference_code, user_id, amount, transaction_type, description) VALUES (?, ?, ?, 'GIFTCODE', ?)",
                        ['giftcode:' . $code . ':' . $userId, $userId, $reward, 'Nhận thưởng giftcode ' . $code]
                    );
                    Database::run(
                        "INSERT INTO transactions (user_id, amount, type, reference_code, status) VALUES (?, ?, 'GIFTCODE', ?, 'paid')",
                        [$userId, $reward, $code]
                    );
                }
                Database::run('UPDATE giftcodes SET redeemed_count = redeemed_count + 1 WHERE code = ?', [$code]);
                return $reward;
            });
            return self::response([
                'success' => true,
                'reward' => $reward,
                'balance' => $this->userRow()['balance'],
                'message' => $reward > 0 ? 'Đã cộng thưởng vào ví của bạn.' : 'Giftcode đã được ghi nhận.',
            ]);
        }

        if ($path === '/api/support/tickets' && $method === 'GET') {
            return self::response(['tickets' => Database::all(
                'SELECT id, ticket_type, subject, message, status, priority, admin_reply, created_at, updated_at FROM support_tickets WHERE user_id = ? ORDER BY created_at DESC LIMIT 50',
                [$userId]
            )]);
        }
        if ($path === '/api/support/tickets' && $method === 'POST') {
            $ticketType = $this->text($this->body['ticketType'] ?? 'support', 24);
            if (!in_array($ticketType, ['dinh_lam', 'support'], true)) {
                throw new HttpException(400, 'Loại yêu cầu hỗ trợ không hợp lệ.');
            }
            $subject = $this->text($this->body['subject'] ?? '', 120);
            $message = $this->text($this->body['message'] ?? '', 3000);
            if (mb_strlen($subject) < 3 || mb_strlen($message) < 5) {
                throw new HttpException(400, 'Vui lòng nhập tiêu đề và nội dung yêu cầu.');
            }
            $ticket = Database::one(
                'INSERT INTO support_tickets (user_id, ticket_type, subject, message) VALUES (?, ?, ?, ?) RETURNING id, ticket_type, subject, message, status, created_at',
                [$userId, $ticketType, $subject, $message]
            );
            $this->publishUserDataChanged('support-ticket');
            Realtime::publish('admin.support.created', ['ticketId' => (int) $ticket['id'], 'type' => $ticketType], 'admin');
            return self::response(['ticket' => $ticket, 'message' => 'Đã gửi yêu cầu hỗ trợ.'], 201);
        }

        if ($path === '/api/vouchers' && $method === 'GET') {
            return self::response([
                'eligibleOrders' => VoucherService::eligibleOrders($userId),
                'requests' => VoucherService::forUser($userId),
            ]);
        }
        if ($path === '/api/vouchers' && $method === 'POST') {
            $orderId = filter_var($this->body['orderId'] ?? null, FILTER_VALIDATE_INT);
            $reason = $this->text($this->body['reason'] ?? '', 1000);
            if ($orderId === false || $orderId < 1 || mb_strlen($reason) < 5) {
                throw new HttpException(400, 'Chọn đơn hàng và mô tả lý do yêu cầu voucher (ít nhất 5 ký tự).');
            }
            try {
                $request = VoucherService::create($userId, (int) $orderId, $reason);
                $this->publishUserDataChanged('voucher-request');
                Realtime::publish('admin.voucher.created', ['voucherId' => (int) $request['id'], 'orderId' => (int) $orderId], 'admin');
            } catch (RuntimeException $error) {
                throw new HttpException(409, $error->getMessage());
            }
            $this->notifyAdmins('🎟️ YÊU CẦU VOUCHER 72H #' . $request['id'] . ' · Đơn ' . $request['order_code'] . ' · User ' . $userId . '. Vui lòng kiểm tra lịch sử đơn trước khi duyệt.');
            return self::response(['request' => $request, 'message' => 'Đã gửi yêu cầu voucher để Admin kiểm tra.'], 201);
        }

        if ($path === '/api/vip' && $method === 'GET') {
            return self::response([
                ...VipService::status($userId),
                'products' => $this->listProducts('vip', false),
            ]);
        }

        if ($path === '/api/vip/checkin' && $method === 'POST') {
            try {
                $result = VipService::checkin($userId);
            } catch (RuntimeException $error) {
                throw new HttpException(409, $error->getMessage());
            }
            return self::response([
                'checkin' => $result,
                'message' => $result['alreadyCheckedIn']
                    ? 'Bạn đã điểm danh hôm nay.'
                    : 'Điểm danh thành công; điểm đã được cộng vào điểm thành viên.',
            ]);
        }

        if ($path === '/api/proxy/homeproxy/status' && $method === 'GET') return self::response(ProxyService::userStatus($userId));
        if ($path === '/api/proxy/homeproxy/connect' && $method === 'POST') {
            try { $status=ProxyService::connect($userId,(string)($this->body['apiKey']??'')); }
            catch (RuntimeException $error) { throw new HttpException(409,$error->getMessage()); }
            return self::response(['status'=>$status,'message'=>'Đã lưu kết nối HomeProxy an toàn. API Key được mã hóa ở máy chủ.']);
        }
        if ($path === '/api/proxy/homeproxy/connection' && $method === 'DELETE') return self::response(['status'=>ProxyService::disconnect($userId),'message'=>'Đã ngắt kết nối HomeProxy.']);
        if (in_array($path,['/api/proxy/homeproxy/check','/api/proxy/homeproxy/rotate'],true) && $method === 'POST') {
            try { $result=$path==='/api/proxy/homeproxy/check'?ProxyService::check($userId):ProxyService::rotate($userId); }
            catch (RuntimeException $error) { throw new HttpException(409,$error->getMessage()); }
            return self::response(['result'=>$result]);
        }

        if (str_starts_with($path, '/api/admin/')) {
            $this->requireAdmin();
            return $this->handleAdmin($method, $path);
        }

        throw new HttpException(404, 'Không tìm thấy API endpoint.');
    }

    private function handleAdmin(string $method, string $path): array
    {
        if ($method === 'GET' && $path === '/api/admin/payments') {
            return self::response(['payments' => PaymentService::pending(100), 'stats' => PaymentService::stats()]);
        }
        if (preg_match('#^/api/admin/payments/([A-Z0-9]{6,24})/(approve|reject)$#', $path, $paymentMatch) && $method === 'POST') {
            $paymentId = $paymentMatch[1];
            try {
                if ($paymentMatch[2] === 'approve') {
                    $result = PaymentService::approve($paymentId, (string) $this->user['id']);
                    $this->notify((string) $result['telegramId'],
                        '🎉 Yêu cầu nạp ' . $paymentId . ' đã được Admin duyệt. Cộng +' .
                        number_format($result['amountVnd'], 0, ',', '.') . ' VNĐ. Số dư mới: ' .
                        number_format($result['newBalance'], 0, ',', '.') . ' VNĐ.',
                        (string) ($result['botKey'] ?? $this->botKey));
                } else {
                    $result = PaymentService::reject($paymentId, (string) $this->user['id'], $this->text($this->body['note'] ?? 'Admin từ chối sau khi đối soát.', 500));
                    $this->notify((string) $result['telegramId'], 'Yêu cầu nạp ' . $paymentId . ' chưa được duyệt. Vui lòng liên hệ CSKH nếu cần hỗ trợ.', (string) ($result['botKey'] ?? $this->botKey));
                }
            } catch (RuntimeException $error) {
                throw new HttpException(409, $error->getMessage());
            }
            $this->audit('payment.' . $paymentMatch[2], $paymentId, ['amount' => $result['amountVnd']]);
            $this->publishUserDataChanged('payment-status');
            return self::response(['payment' => $result, 'message' => $paymentMatch[2] === 'approve' ? 'Đã duyệt và cập nhật ví.' : 'Đã từ chối; ví không thay đổi.']);
        }
        if ($method === 'GET' && $path === '/api/admin/video-jobs') {
            return self::response(['jobs' => VideoJobService::recent(100), 'stats' => VideoJobService::stats()]);
        }
        if (preg_match('#^/api/admin/video-jobs/([0-9a-f-]{36})/retry$#i', $path, $jobMatch) && $method === 'POST') {
            try {
                $job = VideoJobService::retry($jobMatch[1]);
            } catch (RuntimeException $error) {
                throw new HttpException(409, $error->getMessage());
            }
            $this->audit('video_job.retry', $jobMatch[1], []);
            $this->notify((string) $job['user_id'], 'Video job ' . substr((string) $job['id'], 0, 8) . ' đã được đưa lại vào hàng chờ.', (string) ($job['bot_key'] ?? $this->botKey));
            return self::response(['job' => $job, 'message' => 'Đã đưa video job vào hàng chờ.']);
        }

        if ($method === 'GET' && $path === '/api/admin/modules') {
            return self::response(['artifacts' => Database::all('SELECT id, module_key, original_name, mime_type, sha256, status, created_at FROM module_artifacts ORDER BY created_at DESC LIMIT 100')]);
        }
        if ($method === 'POST' && $path === '/api/realtime-ticket') {
            $audience = $this->isAdmin() ? 'admin' : 'users';
            $ticket = Realtime::issueTicket($userId, $audience);
            $url = trim((string) Env::get('WEBSOCKET_URL', ''));
            if ($url === '' || !preg_match('#^wss://#i', $url)) throw new HttpException(503, 'WebSocket WSS chưa được cấu hình.');
            return self::response(['ticket' => $ticket, 'url' => $url, 'audience' => $audience, 'expiresInSeconds' => 60]);
        }
        if ($method === 'POST' && $path === '/api/admin/realtime-ticket') {
            $ticket = Realtime::issueAdminTicket($userId);
            $url = trim((string) Env::get('WEBSOCKET_URL', ''));
            if ($url === '' || !preg_match('#^wss://#i', $url)) throw new HttpException(503, 'WebSocket WSS chưa được cấu hình.');
            return self::response(['ticket' => $ticket, 'url' => $url, 'audience' => 'admin', 'expiresInSeconds' => 60]);
        }
        if ($method === 'GET' && $path === '/api/admin/audit-log') {
            $limit = max(10, min(100, (int) ($this->query['limit'] ?? 50)));
            return self::response(['events' => Database::all(
                'SELECT id, admin_user_id, action, target, details, created_at FROM admin_audit ORDER BY created_at DESC LIMIT ?',
                [$limit]
            )]);
        }
        if ($method === 'GET' && $path === '/api/admin/overview') {
            $paymentStats = PaymentService::stats();
            $videoStats = VideoJobService::stats();
            return self::response([
                'users' => (int) Database::value('SELECT COUNT(*) FROM users'),
                'activeProducts' => (int) Database::value('SELECT COUNT(*) FROM products WHERE is_active = TRUE'),
                'ordersLast30Days' => (int) Database::value("SELECT COUNT(*) FROM orders WHERE created_at >= NOW() - INTERVAL '30 days'"),
                'completedOrderValue' => (int) Database::value("SELECT COALESCE(SUM(amount), 0) FROM orders WHERE status = 'completed'"),
                'openTickets' => (int) Database::value("SELECT COUNT(*) FROM support_tickets WHERE status IN ('open', 'processing')"),
                'pendingPayments' => $paymentStats['pending'],
                'queuedVideoJobs' => $videoStats['queued'],
                'activeVideoJobs' => $videoStats['processing'],
            ]);
        }
        if ($method === 'GET' && $path === '/api/admin/runtime') {
            $primary = TelegramAuth::token('primary') !== '';
            $secondary = TelegramAuth::token('secondary') !== '';
            return self::response([
                'bots' => [
                    ['key' => 'primary', 'status' => $primary ? 'webhook-configured' : 'missing-token', 'configured' => $primary],
                    ['key' => 'secondary', 'status' => $secondary ? 'webhook-configured' : 'disabled', 'configured' => $secondary],
                ],
                'timestamp' => gmdate(DATE_ATOM),
            ]);
        }
        if ($method === 'GET' && $path === '/api/admin/operations') return self::response(OperationsService::all());
        if ($method === 'PATCH' && $path === '/api/admin/operations') {
            try { $result=OperationsService::update($this->body); }
            catch (RuntimeException $error) { throw new HttpException(400,$error->getMessage()); }
            $modes=[];
            foreach (($this->body['bots']??[]) as $botKey=>$settings) if(is_array($settings)) $modes[$botKey]=['mode'=>$settings['mode']??'off','until'=>$settings['until']??null];
            $this->audit('operations.update','telegram-bots',$modes);
            return self::response(['operations'=>$result,'message'=>'Đã cập nhật chế độ vận hành bot.']);
        }
        if ($method === 'GET' && $path === '/api/admin/proxy') return self::response(ProxyService::adminStatus());
        if ($method === 'PATCH' && $path === '/api/admin/proxy') {
            $enabled=$this->toBool($this->body['enabled']??false);
            $result=ProxyService::setEnabled($enabled);
            $this->audit('proxy.homeproxy.toggle','homeproxy',['enabled'=>$enabled]);
            return self::response(['proxy'=>$result,'message'=>$enabled?'Đã bật tích hợp HomeProxy.':'Đã tạm tắt tích hợp HomeProxy.']);
        }

        if ($method === 'GET' && $path === '/api/admin/products') {
            $category = $this->text($this->query['category'] ?? '', 32);
            return self::response(['products' => $this->listProducts($category !== '' ? $category : null, true)]);
        }
        if ($method === 'POST' && $path === '/api/admin/products') {
            $name = $this->text($this->body['name'] ?? '', 120);
            $category = $this->text($this->body['category'] ?? '', 32);
            $description = $this->text($this->body['description'] ?? '', 1500);
            $price = $this->parseMoney($this->body['price'] ?? null, true);
            $days = (int) ($this->body['durationDays'] ?? 0);
            $stock = ($this->body['stock'] ?? null) === '' || ($this->body['stock'] ?? null) === null ? null : (int) $this->body['stock'];
            $vip = (int) ($this->body['vipLevel'] ?? 0);
            if (mb_strlen($name) < 2 || !in_array($category, self::CATEGORIES, true)) {
                throw new HttpException(400, 'Tên sản phẩm hoặc danh mục không hợp lệ.');
            }
            if ($days < 0 || $days > 3650 || $stock !== null && ($stock < 0 || $stock > 10000000) || $vip < 0 || $vip > 3) {
                throw new HttpException(400, 'Thời hạn, tồn kho hoặc cấp VIP không hợp lệ.');
            }
            $slug = self::slug($this->body['slug'] ?? $name);
            $image = $this->text($this->body['imageUrl'] ?? '', 500);
            self::validateHttps($image, 'Ảnh sản phẩm phải dùng HTTPS.');
            $product = Database::one(
                'INSERT INTO products (slug, name, category, description, price, duration_days, stock, vip_level, image_url, is_active) ' .
                'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE) RETURNING *',
                [$slug, $name, $category, $description, $price, $days, $stock, $vip, $image ?: null]
            );
            $this->audit('product.create', $slug, ['category' => $category, 'price' => $price]);
            return self::response(['product' => self::publicProduct($product)], 201);
        }

        if (preg_match('#^/api/admin/products/(\d{1,18})$#', $path, $match)) {
            $id = $match[1];
            if ($method === 'PATCH') {
                $current = Database::one('SELECT * FROM products WHERE id = ?', [$id]);
                if ($current === null) {
                    throw new HttpException(404, 'Không tìm thấy sản phẩm.');
                }
                $name = array_key_exists('name', $this->body) ? $this->text($this->body['name'], 120) : $current['name'];
                $category = array_key_exists('category', $this->body) ? $this->text($this->body['category'], 32) : $current['category'];
                $description = array_key_exists('description', $this->body) ? $this->text($this->body['description'], 1500) : $current['description'];
                $price = array_key_exists('price', $this->body) ? $this->parseMoney($this->body['price'], true) : (int) $current['price'];
                $days = array_key_exists('durationDays', $this->body) ? (int) $this->body['durationDays'] : (int) $current['duration_days'];
                $stock = array_key_exists('stock', $this->body)
                    ? (($this->body['stock'] === '' || $this->body['stock'] === null) ? null : (int) $this->body['stock'])
                    : ($current['stock'] === null ? null : (int) $current['stock']);
                $vip = array_key_exists('vipLevel', $this->body) ? (int) $this->body['vipLevel'] : (int) $current['vip_level'];
                $active = array_key_exists('isActive', $this->body) ? $this->toBool($this->body['isActive']) : $this->toBool($current['is_active']);
                $image = array_key_exists('imageUrl', $this->body) ? $this->text($this->body['imageUrl'], 500) : (string) ($current['image_url'] ?? '');
                if (mb_strlen($name) < 2 || !in_array($category, self::CATEGORIES, true) || $days < 0 || $days > 3650 || $stock !== null && $stock < 0 || $vip < 0 || $vip > 3) {
                    throw new HttpException(400, 'Thông tin sản phẩm không hợp lệ.');
                }
                self::validateHttps($image, 'Ảnh sản phẩm phải dùng HTTPS.');
                $product = Database::one(
                    'UPDATE products SET name = ?, category = ?, description = ?, price = ?, duration_days = ?, stock = ?, vip_level = ?, image_url = ?, is_active = ?, updated_at = NOW() WHERE id = ? RETURNING *',
                    [$name, $category, $description, $price, $days, $stock, $vip, $image ?: null, $active, $id]
                );
                $this->audit('product.update', $id, ['isActive' => $active, 'category' => $category, 'price' => $price]);
                return self::response(['product' => self::publicProduct($product)]);
            }
            if ($method === 'DELETE') {
                $deleted = Database::run('UPDATE products SET is_active = FALSE, updated_at = NOW() WHERE id = ? RETURNING id', [$id]);
                if ($deleted->rowCount() === 0) {
                    throw new HttpException(404, 'Không tìm thấy sản phẩm.');
                }
                $this->audit('product.disable', $id, []);
                return self::response(['success' => true]);
            }
        }

        if ($method === 'GET' && $path === '/api/admin/orders') {
            return self::response(['orders' => Database::all(
                'SELECT id, order_code, user_id, product_name, category, amount, status, admin_note, delivery_text, created_at, updated_at FROM orders ORDER BY created_at DESC LIMIT 200'
            )]);
        }
        if (preg_match('#^/api/admin/orders/(\d{1,18})$#', $path, $match) && $method === 'PATCH') {
            return $this->updateOrder($match[1]);
        }

        if ($method === 'GET' && $path === '/api/admin/users') {
            $search = $this->text($this->query['search'] ?? '', 80);
            $users = Database::all(
                "SELECT user_id, name, username, balance, total_deposited, vip_level, ref_by, created_at FROM users " .
                "WHERE (? = '' OR user_id ILIKE ? OR name ILIKE ? OR username ILIKE ?) ORDER BY created_at DESC LIMIT 100",
                [$search, '%' . $search . '%', '%' . $search . '%', '%' . $search . '%']
            );
            foreach ($users as &$user) {
                $user['balance'] = (int) $user['balance'];
                $user['total_deposited'] = (int) $user['total_deposited'];
            }
            unset($user);
            return self::response(['users' => $users]);
        }
        if (preg_match('#^/api/admin/users/(\d{5,20})/adjust-balance$#', $path, $match) && $method === 'POST') {
            $amount = $this->parseMoney($this->body['amount'] ?? null, false);
            $direction = $this->text($this->body['direction'] ?? '', 12);
            $description = $this->text($this->body['description'] ?? '', 300);
            if (!in_array($direction, ['credit', 'debit'], true)) {
                throw new HttpException(400, 'Loại điều chỉnh không hợp lệ.');
            }
            $userId = $match[1];
            $signed = $direction === 'credit' ? $amount : -$amount;
            $balance = Database::transaction(function () use ($amount, $signed, $userId, $description): int {
                $updated = Database::one(
                    'UPDATE users SET balance = balance + ? WHERE user_id = ? AND balance + ? >= 0 RETURNING balance',
                    [$signed, $userId, $signed]
                );
                if ($updated === null) {
                    throw new HttpException(409, 'Không tìm thấy tài khoản hoặc số dư không đủ.');
                }
                $reference = 'admin:' . bin2hex(random_bytes(12));
                Database::run(
                    "INSERT INTO wallet_ledger (reference_code, user_id, amount, transaction_type, description) VALUES (?, ?, ?, 'ADMIN_ADJUSTMENT', ?)",
                    [$reference, $userId, $signed, $description !== '' ? $description : 'Điều chỉnh số dư bởi quản trị viên']
                );
                Database::run(
                    "INSERT INTO transactions (user_id, amount, type, reference_code, status) VALUES (?, ?, 'ADMIN_ADJUSTMENT', ?, 'paid')",
                    [$userId, $amount, $reference]
                );
                return (int) $updated['balance'];
            });
            $this->audit('user.balance', $userId, ['amount' => $signed]);
            $this->notify($userId, 'Số dư của bạn vừa được điều chỉnh: ' . ($signed > 0 ? '+' : '') . self::moneyFormat($signed) . '.');
            return self::response(['success' => true, 'balance' => $balance]);
        }

        if ($method === 'GET' && $path === '/api/admin/vouchers') {
            return self::response(['requests' => VoucherService::pending(200)]);
        }
        if (preg_match('#^/api/admin/vouchers/(\\d+)/(approve|reject)$#', $path, $match) && $method === 'POST') {
            $action = $match[2];
            $reward = $action === 'approve' ? $this->parseMoney($this->body['rewardAmount'] ?? null, false) : 0;
            $note = $this->text($this->body['adminNote'] ?? '', 1000);
            try {
                $result = VoucherService::review((int) $match[1], $action, (string) $this->user['id'], $reward, $note);
            } catch (RuntimeException $error) {
                throw new HttpException(409, $error->getMessage());
            }
            $this->audit('voucher.' . $action, (string) $match[1], ['rewardAmount' => $reward]);
            $message = $action === 'approve'
                ? '✅ Yêu cầu voucher cho đơn ' . $result['request']['order_code'] . ' đã được duyệt. Ví Voucher được cộng ' . self::moneyFormat($reward) . '.'
                : 'Yêu cầu voucher cho đơn ' . $result['request']['order_code'] . ' đã bị từ chối. Ghi chú: ' . ($note !== '' ? $note : 'Không có.');
            $this->notify($result['userId'], $message);
            return self::response(['request' => $result['request'], 'message' => $action === 'approve' ? 'Đã duyệt và cộng ví Voucher.' : 'Đã từ chối yêu cầu.']);
        }

        if ($method === 'GET' && $path === '/api/admin/tickets') {
            return self::response(['tickets' => Database::all(
                "SELECT * FROM support_tickets ORDER BY CASE WHEN status = 'open' THEN 0 ELSE 1 END, created_at DESC LIMIT 200"
            )]);
        }
        if (preg_match('#^/api/admin/tickets/(\d{1,18})$#', $path, $match) && $method === 'PATCH') {
            $status = $this->text($this->body['status'] ?? '', 24);
            $reply = $this->text($this->body['reply'] ?? '', 3000);
            if (!in_array($status, ['open', 'processing', 'resolved', 'closed'], true)) {
                throw new HttpException(400, 'Trạng thái hỗ trợ không hợp lệ.');
            }
            $ticket = Database::one(
                'UPDATE support_tickets SET status = ?, admin_reply = ?, updated_at = NOW() WHERE id = ? RETURNING *',
                [$status, $reply, $match[1]]
            );
            if ($ticket === null) {
                throw new HttpException(404, 'Không tìm thấy yêu cầu hỗ trợ.');
            }
            $this->audit('support.reply', $match[1], ['status' => $status]);
            if ($reply !== '') {
                $this->notify((string) $ticket['user_id'], "🎧 Phản hồi từ Trung tâm khách hàng:\n" . htmlspecialchars($reply, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            }
            return self::response(['ticket' => $ticket]);
        }

        if ($method === 'GET' && $path === '/api/admin/settings') {
            return self::response(['settings' => $this->settings(self::SETTINGS, false)]);
        }
        if ($method === 'PATCH' && $path === '/api/admin/settings') {
            $keys = array_keys($this->body);
            if ($keys === [] || array_diff($keys, self::SETTINGS) !== []) {
                throw new HttpException(400, 'Có khóa cấu hình không được phép cập nhật. Secret hệ thống chỉ quản lý trong Environment.');
            }
            $values = [];
            foreach ($this->body as $key => $value) {
                $text = $this->text($value, 500);
                if ($key === 'vip_checkin_points') {
                    $points = filter_var($text, FILTER_VALIDATE_INT);
                    if ($points === false || $points < 0 || $points > 1000) {
                        throw new HttpException(400, 'Điểm danh VIP phải là số nguyên từ 0 đến 1.000 điểm.');
                    }
                    $text = (string) $points;
                }
                if ($key === 'referral_commission_percent') {
                    $percent = (float) $text;
                    if (!is_finite($percent) || $percent < 0 || $percent > 30) {
                        throw new HttpException(400, 'Tỷ lệ hoa hồng phải từ 0 đến 30%.');
                    }
                }
                if (in_array($key, ['website_url', 'GROUP_URL', 'CHANNEL_URL', 'ABCVIP_CONNECT_URL'], true)) {
                    self::validateHttps($text, 'Liên kết website/nhóm/kênh phải sử dụng HTTPS.');
                    if ($text !== '' && filter_var($text, FILTER_VALIDATE_URL) === false) {
                        throw new HttpException(400, 'Liên kết website phải là URL hợp lệ.');
                    }
                }
                $values[$key] = $text;
            }
            Database::transaction(function () use ($values): void {
                foreach ($values as $key => $value) {
                    $isPublic = !in_array($key, ['VIETQR_BANK_ID', 'VIETQR_ACCOUNT_NO', 'VIETQR_ACCOUNT_NAME'], true);
                    Database::run(
                        'INSERT INTO app_settings (key, value, is_public, updated_at) VALUES (?, ?, ?, NOW()) ' .
                        'ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, is_public = EXCLUDED.is_public, updated_at = NOW()',
                        [$key, $value, $isPublic]
                    );
                }
            });
            $this->audit('settings.update', 'public-settings', ['keys' => $keys]);
            return self::response(['success' => true, 'settings' => $this->settings(self::SETTINGS, false)]);
        }

        if (preg_match('#^/api/admin/giftcodes/([A-Z0-9_-]{4,64})$#i', $path, $match) && $method === 'PATCH') {
            $code = strtoupper($match[1]);
            $active = $this->toBool($this->body['isActive'] ?? false);
            $gift = Database::one('UPDATE giftcodes SET is_active = ? WHERE code = ? RETURNING code, is_active', [$active, $code]);
            if ($gift === null) {
                throw new HttpException(404, 'Không tìm thấy giftcode.');
            }
            $this->audit('giftcode.toggle', $code, ['isActive' => $active]);
            return self::response(['giftcode' => $gift]);
        }

        if ($method === 'GET' && $path === '/api/admin/bot-menus') {
            $menus = Database::all('SELECT * FROM bot_menu_configs ORDER BY bot_key');
            return self::response(['menus' => array_map(fn (array $row): array => $this->publicMenu($row), $menus)]);
        }
        if (preg_match('#^/api/admin/bot-menus/(primary|secondary)$#', $path, $match) && $method === 'PATCH') {
            return $this->saveBotMenu($match[1]);
        }

        if ($method === 'GET' && $path === '/api/admin/giftcodes') {
            return self::response(['giftcodes' => Database::all('SELECT * FROM giftcodes ORDER BY created_at DESC LIMIT 200')]);
        }
        if ($method === 'POST' && $path === '/api/admin/giftcodes') {
            $code = strtoupper($this->text($this->body['code'] ?? bin2hex(random_bytes(5)), 64));
            $reward = $this->parseMoney($this->body['rewardAmount'] ?? 0, true);
            $max = (int) ($this->body['maxRedemptions'] ?? 1);
            $expires = $this->body['expiresAt'] ?? null;
            if (!preg_match('/^[A-Z0-9_-]{4,64}$/', $code)) {
                throw new HttpException(400, 'Mã giftcode chỉ gồm A-Z, số, _ hoặc -.');
            }
            if ($max < 1 || $max > 100000) {
                throw new HttpException(400, 'Số lượt sử dụng không hợp lệ.');
            }
            if ($expires !== null && $expires !== '') {
                try {
                    $expires = (new DateTimeImmutable((string) $expires))->format(DATE_ATOM);
                } catch (Throwable) {
                    throw new HttpException(400, 'Ngày hết hạn không hợp lệ.');
                }
            } else {
                $expires = null;
            }
            $gift = Database::one(
                'INSERT INTO giftcodes (code, reward_amount, max_redemptions, expires_at) VALUES (?, ?, ?, ?) RETURNING *',
                [$code, $reward, $max, $expires]
            );
            $this->audit('giftcode.create', $code, ['reward' => $reward, 'maxRedemptions' => $max]);
            return self::response(['giftcode' => $gift], 201);
        }

        throw new HttpException(404, 'Không tìm thấy API quản trị.');
    }

    private function updateOrder(string $id): array
    {
        $status = $this->text($this->body['status'] ?? '', 24);
        $note = $this->text($this->body['adminNote'] ?? '', 1000);
        $delivery = $this->text($this->body['deliveryText'] ?? '', 5000);
        if (!in_array($status, self::ORDER_STATUSES, true)) {
            throw new HttpException(400, 'Trạng thái đơn hàng không hợp lệ.');
        }

        [$order, $refunded] = Database::transaction(function () use ($id, $status, $note, $delivery): array {
            $current = Database::one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$id]);
            if ($current === null) {
                throw new HttpException(404, 'Không tìm thấy đơn hàng.');
            }
            $terminal = ['completed', 'rejected', 'cancelled', 'refunded'];
            if (in_array($current['status'], $terminal, true) && $current['status'] !== $status) {
                throw new HttpException(409, 'Đơn hàng đã kết thúc và không thể thay đổi trạng thái.');
            }

            $order = Database::one(
                'UPDATE orders SET status = ?, admin_note = ?, delivery_text = ?, updated_at = NOW() WHERE id = ? RETURNING *',
                [$status, $note, $delivery, $id]
            );
            $refunded = false;

            if (in_array($status, ['rejected', 'cancelled', 'refunded'], true) && !in_array($current['status'], $terminal, true)) {
                $amount = (int) $current['amount'];
                if ($amount > 0) {
                    $credit = Database::run(
                        "INSERT INTO wallet_ledger (reference_code, user_id, amount, transaction_type, description) VALUES (?, ?, ?, 'ORDER_REFUND', ?) ON CONFLICT (reference_code) DO NOTHING RETURNING id",
                        ['refund:' . $current['order_code'], $current['user_id'], $amount, 'Hoàn tiền đơn ' . $current['order_code']]
                    );
                    if ($credit->rowCount() > 0) {
                        Database::run('UPDATE users SET balance = balance + ? WHERE user_id = ?', [$amount, $current['user_id']]);
                        Database::run(
                            "INSERT INTO transactions (user_id, amount, type, reference_code, status) VALUES (?, ?, 'ORDER_REFUND', ?, 'paid')",
                            [$current['user_id'], $amount, $current['order_code']]
                        );
                        $refunded = true;
                    }
                }
                if ($current['product_id'] !== null) {
                    Database::run('UPDATE products SET stock = stock + 1, updated_at = NOW() WHERE id = ? AND stock IS NOT NULL', [$current['product_id']]);
                }
            }

            if ($status === 'completed' && $current['status'] !== 'completed' && $current['product_id'] !== null) {
                $product = Database::one('SELECT vip_level FROM products WHERE id = ?', [$current['product_id']]);
                if ($product !== null && (int) $product['vip_level'] > 0) {
                    Database::run('UPDATE users SET vip_level = GREATEST(vip_level, ?) WHERE user_id = ?', [(int) $product['vip_level'], $current['user_id']]);
                }
            }

            return [$order, $refunded];
        });

        $this->audit('order.update', (string) $order['order_code'], ['status' => $status, 'refunded' => $refunded]);
        if ($status === 'completed') {
            $message = 'Đơn hàng ' . $order['order_code'] . ' đã hoàn tất.' . ($delivery !== '' ? "\nThông tin bàn giao:\n" . htmlspecialchars($delivery, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '');
        } elseif ($refunded) {
            $message = 'Đơn hàng ' . $order['order_code'] . ' đã bị hủy/từ chối. Số tiền ' . self::moneyFormat($order['amount']) . ' đã được hoàn vào ví.';
        } else {
            $message = 'Đơn hàng ' . $order['order_code'] . ' đã cập nhật trạng thái: ' . $status;
        }
        $this->notify((string) $order['user_id'], $message);
        return self::response(['order' => $order, 'refunded' => $refunded]);
    }

    private function saveBotMenu(string $botKey): array
    {
        if ($botKey === 'secondary' && TelegramAuth::token('secondary') === '') {
            throw new HttpException(409, 'BOT2_TOKEN chưa được cấu hình trong Environment.');
        }
        // Always normalize saved menu configuration to Inline Keyboard.
        $mode = 'inline_keyboard';
        $columns = (int) ($this->body['menuColumns'] ?? 0);
        if ($columns < 1 || $columns > 3) throw new HttpException(400, 'Số cột menu phải từ 1 đến 3.');
        $labels = $this->validateMap($this->body['buttonLabels'] ?? [], 'Tên nút', 60);
        $descriptions = $this->validateMap($this->body['buttonDescriptions'] ?? [], 'Mô tả nút', 160);
        $styles = $this->validateMap($this->body['buttonStyles'] ?? [], 'Màu nút', 12, ['primary', 'success', 'danger']);
        $icons = $this->validateMap($this->body['buttonIcons'] ?? [], 'Custom Emoji ID', 32);
        foreach ($icons as $iconId) {
            if (!preg_match('/^[0-9]{5,32}$/', (string) $iconId)) {
                throw new HttpException(400, 'Custom Emoji ID phải là chuỗi số từ 5 đến 32 ký tự.');
            }
        }
        $intro = $this->text($this->body['introText'] ?? '', 600);
        $persistent = array_key_exists('persistentKeyboard', $this->body) ? $this->toBool($this->body['persistentKeyboard']) : true;
        $resize = array_key_exists('resizeKeyboard', $this->body) ? $this->toBool($this->body['resizeKeyboard']) : true;
        $saved = Database::one(
            'INSERT INTO bot_menu_configs (bot_key, menu_mode, intro_text, menu_columns, persistent_keyboard, resize_keyboard, button_labels, button_descriptions, button_styles, button_icons, updated_at) ' .
            'VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?::jsonb, ?::jsonb, NOW()) ' .
            'ON CONFLICT (bot_key) DO UPDATE SET menu_mode = EXCLUDED.menu_mode, intro_text = EXCLUDED.intro_text, menu_columns = EXCLUDED.menu_columns, ' .
            'persistent_keyboard = EXCLUDED.persistent_keyboard, resize_keyboard = EXCLUDED.resize_keyboard, button_labels = EXCLUDED.button_labels, ' .
            'button_descriptions = EXCLUDED.button_descriptions, button_styles = EXCLUDED.button_styles, button_icons = EXCLUDED.button_icons, updated_at = NOW() RETURNING *',
            [$botKey, $mode, $intro, $columns, $persistent, $resize, json_encode($labels, JSON_UNESCAPED_UNICODE), json_encode($descriptions, JSON_UNESCAPED_UNICODE), json_encode($styles, JSON_UNESCAPED_UNICODE), json_encode($icons, JSON_UNESCAPED_UNICODE)]
        );
        $this->audit('bot-menu.update', $botKey, ['menuMode' => $mode, 'menuColumns' => $columns, 'customEmojiCount' => count($icons)]);
        return self::response([
            'success' => true,
            'menu' => $this->publicMenu($saved),
            'message' => 'Đã lưu cấu hình riêng của bot. Custom Emoji ID chỉ hoạt động khi tài khoản sở hữu bot đủ điều kiện Telegram Premium.',
        ]);
    }

    private function exchangeLegacySession(): array
    {
        $ticket = $this->text($this->body['ticket'] ?? '', 128);
        $scope = $this->text($this->body['scope'] ?? 'customer', 16);
        if (!preg_match('/^[a-f0-9]{64}$/i', $ticket) || !in_array($scope, ['customer', 'admin'], true)) {
            throw new HttpException(400, 'Login ticket hoặc phạm vi đăng nhập không hợp lệ.');
        }

        $identity = Database::transaction(function () use ($ticket, $scope): array {
            $hash = hash('sha256', $ticket);
            $found = Database::one(
                'SELECT user_id, bot_key FROM legacy_login_tickets WHERE token_hash = ? AND consumed_at IS NULL AND expires_at > NOW() FOR UPDATE',
                [$hash]
            );
            if ($found === null) {
                throw new HttpException(401, 'Link đăng nhập đã hết hạn hoặc đã được sử dụng.');
            }
            Database::run('UPDATE legacy_login_tickets SET consumed_at = NOW() WHERE token_hash = ?', [$hash]);
            $user = Database::one('SELECT user_id, name, username, vip_level, balance FROM users WHERE user_id = ?', [$found['user_id']]);
            if ($user === null) {
                throw new HttpException(404, 'Không tìm thấy hồ sơ thành viên.');
            }
            if ($scope === 'admin' && !TelegramAuth::isAdmin((string) $user['user_id'])) {
                throw new HttpException(403, 'Tài khoản này không nằm trong danh sách Admin được phép.');
            }
            return ['identity' => $found, 'user' => $user];
        });

        $botKey = (string) $identity['identity']['bot_key'];
        $secret = TelegramAuth::websiteSessionSecret($botKey);

        $now = time();
        $header = self::base64Url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $sessionTtl = max(900, min(43200, Env::int('WEBSITE_SESSION_TTL_SECONDS', 43200)));
        $payload = self::base64Url(json_encode([
            'sub' => (string) $identity['user']['user_id'],
            'name' => (string) $identity['user']['name'],
            'username' => (string) $identity['user']['username'],
            'bot' => (string) $identity['identity']['bot_key'],
            'aud' => $scope === 'admin' ? 'hendy-admin-dashboard' : 'hendy-legacy-account',
            'iat' => $now,
            'exp' => $now + $sessionTtl,
        ], JSON_UNESCAPED_UNICODE));
        $unsigned = $header . '.' . $payload;
        $signature = self::base64Url(hash_hmac('sha256', $unsigned, $secret, true));
        $sessionToken = $unsigned . '.' . $signature;
        $cookieSecure = true;
        setcookie('hendy_web_session', $sessionToken, [
            'expires' => $now + $sessionTtl,
            'path' => '/',
            'secure' => $cookieSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        return self::response([
            'authenticated' => true,
            'scope' => $scope,
            'botKey' => (string) $identity['identity']['bot_key'],
            'expiresInSeconds' => $sessionTtl,
            'user' => [
                'id' => (string) $identity['user']['user_id'],
                'name' => (string) $identity['user']['name'],
                'username' => (string) $identity['user']['username'],
                'vipLevel' => (int) $identity['user']['vip_level'],
                'balance' => (int) $identity['user']['balance'],
            ],
        ]);
    }

    private function userRow(): array
    {
        $userId = (string) $this->user['id'];
        $name = $this->text(trim((string) ($this->user['first_name'] ?? '') . ' ' . (string) ($this->user['last_name'] ?? '')), 120);
        $username = $this->text($this->user['username'] ?? '', 64);
        $row = Database::one(
            "INSERT INTO users (user_id, name, username) VALUES (?, ?, ?) ON CONFLICT (user_id) DO UPDATE SET " .
            "name = CASE WHEN EXCLUDED.name <> '' THEN EXCLUDED.name ELSE users.name END, " .
            "username = CASE WHEN EXCLUDED.username <> '' THEN EXCLUDED.username ELSE users.username END " .
            'RETURNING user_id, name, username, balance, vip_level, total_deposited, nyc_wallet, voucher_wallet, point_old, created_at',
            [$userId, $name, $username]
        ) ?? [];
        return [
            'id' => $userId,
            'name' => $row['name'] ?: ($username ?: 'Thành viên'),
            'username' => $row['username'],
            'balance' => (int) $row['balance'],
            'mainWallet' => (int) $row['balance'],
            'nycWallet' => (int) $row['nyc_wallet'],
            'voucherWallet' => (int) $row['voucher_wallet'],
            'vipLevel' => (int) $row['vip_level'],
            'totalDeposited' => (int) $row['total_deposited'],
            'totalRecharge' => (int) $row['total_deposited'],
            'main_wallet' => (int) $row['balance'],
            'total_recharge' => (int) $row['total_deposited'],
            'pointOld' => (int) $row['point_old'],
            'createdAt' => $row['created_at'],
        ];
    }

    private function listProducts(?string $category, bool $includeInactive): array
    {
        $where = [];
        $parameters = [];
        if ($category !== null && $category !== '') {
            if (!in_array($category, self::CATEGORIES, true)) {
                throw new HttpException(400, 'Danh mục không hợp lệ.');
            }
            $where[] = 'category = ?';
            $parameters[] = $category;
        }
        if (!$includeInactive) {
            $where[] = 'is_active = TRUE';
        }
        $sql = 'SELECT * FROM products' . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY category, created_at DESC LIMIT 250';
        return array_map(self::publicProduct(...), Database::all($sql, $parameters));
    }

    private function settings(array $keys, bool $publicOnly): array
    {
        if ($keys === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $sql = "SELECT key, value FROM app_settings WHERE key IN ($placeholders)" . ($publicOnly ? ' AND is_public = TRUE' : '');
        $result = [];
        foreach (Database::all($sql, $keys) as $row) {
            $result[$row['key']] = $row['value'];
        }
        return $result;
    }

    private function publishUserDataChanged(string $kind): void
    {
        $allowed = ['order', 'payment-request', 'payment-status', 'support-ticket', 'voucher-request', 'wallet', 'giftcode', 'video-job'];
        if (in_array($kind, $allowed, true)) {
            Realtime::publish('user.data.changed', ['kind' => $kind], 'users');
        }
    }

    private function audit(string $action, string $target, array $details): void
    {
        Realtime::publish('admin.' . $action, ['target' => $target, 'details' => $details]);
        Database::run(
            'INSERT INTO admin_audit (admin_user_id, action, target, details) VALUES (?, ?, ?, ?::jsonb)',
            [(string) $this->user['id'], $action, $target, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
        );
        Realtime::publish('user.data.changed', ['resource' => $action], 'users');
    }

    private function notify(string $userId, string $message, ?string $botKey = null): void
    {
        $token = TelegramAuth::token($botKey ?? $this->botKey);
        if ($token === '') {
            return;
        }
        try {
            (new TelegramApi($token))->sendMessage($userId, $message);
        } catch (Throwable $error) {
            error_log('Không gửi được thông báo Telegram: ' . $error->getMessage());
        }
    }

    private function notifyAdmins(string $message): void
    {
        foreach (TelegramAuth::adminIds() as $adminId) {
            $this->notify($adminId, $message);
        }
    }

    private function requireAdmin(): void
    {
        if (!$this->isAdmin()) {
            throw new HttpException(403, 'Bạn không có quyền quản trị.');
        }
    }

    private function isAdmin(): bool
    {
        return (bool) ($this->user['_is_admin'] ?? false);
    }

    private function publicMenu(array $row): array
    {
        return [
            'botKey' => $row['bot_key'],
            'configured' => TelegramAuth::token($row['bot_key']) !== '',
            'menuMode' => $row['menu_mode'],
            'introText' => $row['intro_text'],
            'menuColumns' => (int) $row['menu_columns'],
            'persistentKeyboard' => $this->toBool($row['persistent_keyboard']),
            'resizeKeyboard' => $this->toBool($row['resize_keyboard']),
            'buttonLabels' => self::jsonArray($row['button_labels']),
            'buttonDescriptions' => self::jsonArray($row['button_descriptions']),
            'buttonStyles' => self::jsonArray($row['button_styles']),
            'buttonIcons' => self::jsonArray($row['button_icons'] ?? []),
            'updatedAt' => $row['updated_at'],
        ];
    }

    private function validateMap(mixed $value, string $label, int $maximum, ?array $allowed = null): array
    {
        if ($value === null) {
            return [];
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (!is_array($decoded)) {
                throw new HttpException(400, $label . ' phải là JSON hợp lệ.');
            }
            $value = $decoded;
        }
        if (!is_array($value) || array_is_list($value) && $value !== []) {
            throw new HttpException(400, $label . ' phải là đối tượng JSON.');
        }
        $result = [];
        foreach ($value as $key => $raw) {
            if (!in_array((string) $key, self::MENU_ACTIONS, true)) {
                throw new HttpException(400, 'Khóa nút không hợp lệ: ' . $key);
            }
            $text = trim((string) $raw);
            if (mb_strlen($text) > $maximum) {
                throw new HttpException(400, $label . ' có giá trị quá dài.');
            }
            if ($allowed !== null && $text !== '' && !in_array($text, $allowed, true)) {
                throw new HttpException(400, 'Màu nút không hợp lệ.');
            }
            if ($text !== '') {
                $result[(string) $key] = $text;
            }
        }
        return $result;
    }

    private function text(mixed $value, int $maximum): string
    {
        if (!is_scalar($value) && $value !== null) {
            throw new HttpException(400, 'Dữ liệu văn bản không hợp lệ.');
        }
        return mb_substr(trim((string) ($value ?? '')), 0, $maximum);
    }

    private function parseMoney(mixed $value, bool $allowZero): int
    {
        if (!is_numeric($value) || (string) $value === '') {
            throw new HttpException(400, 'Số tiền không hợp lệ.');
        }
        $amount = filter_var($value, FILTER_VALIDATE_INT);
        if ($amount === false || $amount < ($allowZero ? 0 : 1) || $amount > 9000000000000000) {
            throw new HttpException(400, 'Số tiền không hợp lệ.');
        }
        return (int) $amount;
    }

    private function toBool(mixed $value): bool
    {
        return in_array(strtolower((string) $value), ['1', 't', 'true', 'yes', 'on'], true);
    }

    private static function jsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function publicProduct(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'slug' => $row['slug'],
            'name' => $row['name'],
            'category' => $row['category'],
            'description' => $row['description'],
            'price' => (int) $row['price'],
            'durationDays' => (int) $row['duration_days'],
            'stock' => $row['stock'] === null ? null : (int) $row['stock'],
            'vipLevel' => (int) $row['vip_level'],
            'imageUrl' => $row['image_url'] ?? '',
            'isActive' => filter_var($row['is_active'], FILTER_VALIDATE_BOOLEAN),
            'metadata' => self::jsonArray($row['metadata'] ?? []),
        ];
    }

    private static function slug(mixed $value): string
    {
        $value = mb_strtolower(trim((string) $value));
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', (string) ($normalized ?: $value)), '-');
        return mb_substr($slug !== '' ? $slug : bin2hex(random_bytes(4)), 0, 70);
    }

    private static function validateHttps(string $url, string $message): void
    {
        if ($url !== '' && !preg_match('/^https:\/\/\S+$/i', $url)) {
            throw new HttpException(400, $message);
        }
    }

    private static function orderCode(): string
    {
        return 'HD' . strtoupper(base_convert((string) time(), 10, 36)) . strtoupper(bin2hex(random_bytes(3)));
    }

    private static function moneyFormat(mixed $amount): string
    {
        return number_format((float) $amount, 0, ',', '.') . ' VNĐ';
    }


    private static function base64Url(string|false $value): string
    {
        return rtrim(strtr(base64_encode((string) $value), '+/', '-_'), '=');
    }

    private static function response(array $body, int $status = 200): array
    {
        return ['status' => $status, 'body' => $body];
    }
}
