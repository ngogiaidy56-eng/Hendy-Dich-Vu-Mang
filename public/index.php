<?php
declare(strict_types=1);

use Hendy\ApiController;
use Hendy\BotService;
use Hendy\Database;
use Hendy\Env;
use Hendy\HttpException;
use Hendy\TelegramApi;
use Hendy\TelegramAuth;
use Hendy\WebhookSecurity;

require dirname(__DIR__) . '/app/Env.php';
require dirname(__DIR__) . '/app/Database.php';
require dirname(__DIR__) . '/app/TelegramApi.php';
require dirname(__DIR__) . '/app/TelegramAuth.php';
require dirname(__DIR__) . '/app/WebhookSecurity.php';
require dirname(__DIR__) . '/app/TelegramLink.php';
require dirname(__DIR__) . '/app/BotMenuDefinition.php';
require dirname(__DIR__) . '/app/PaymentService.php';
require dirname(__DIR__) . '/app/VideoJobService.php';
require dirname(__DIR__) . '/app/VoucherService.php';
require dirname(__DIR__) . '/app/VipService.php';
require dirname(__DIR__) . '/app/OperationsService.php';
require dirname(__DIR__) . '/app/ProxyService.php';
require dirname(__DIR__) . '/app/BotService.php';
require dirname(__DIR__) . '/app/Realtime.php';
require dirname(__DIR__) . '/app/ModuleRegistry.php';
require dirname(__DIR__) . '/app/WebServiceContract.php';
require dirname(__DIR__) . '/app/WebServiceKernel.php';
require dirname(__DIR__) . '/app/SystemWebService.php';
require dirname(__DIR__) . '/app/ApiController.php';

Env::load(dirname(__DIR__) . '/.env');

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function processBankDeposit(array $body): array
{
    $secret = (string) Env::get('BANK_WEBHOOK_SECRET', '');
    $received = (string) ($_SERVER['HTTP_X_BANK_WEBHOOK_SECRET'] ?? '');
    if (!WebhookSecurity::verifySecret($secret, $received)) {
        throw new HttpException(401, 'Webhook secret không hợp lệ.');
    }

    $amount = filter_var($body['amountIn'] ?? null, FILTER_VALIDATE_INT);
    $reference = trim((string) ($body['referenceCode'] ?? ''));
    $content = trim((string) ($body['content'] ?? ''));
    if (!preg_match('/^NAP\s+(\d{5,20})$/i', $content, $match) ||
        $amount === false || $amount <= 0 || $amount > 9000000000000000 ||
        $reference === '' || mb_strlen($reference) > 128) {
        throw new HttpException(400, 'Dữ liệu giao dịch không hợp lệ.');
    }

    $userId = $match[1];
    $result = Database::transaction(function () use ($userId, $amount, $reference): array {
        $inserted = Database::run(
            'INSERT INTO deposit_events (reference_code, user_id, amount) VALUES (?, ?, ?) ON CONFLICT (reference_code) DO NOTHING RETURNING reference_code',
            [$reference, $userId, $amount]
        );
        if ($inserted->rowCount() === 0) {
            return ['duplicate' => true];
        }

        Database::run('INSERT INTO users (user_id) VALUES (?) ON CONFLICT DO NOTHING', [$userId]);
        $user = Database::one(
            'UPDATE users SET balance = balance + ?, total_deposited = total_deposited + ?, ' .
            'vip_level = CASE WHEN total_deposited + ? >= 5000000 THEN 3 WHEN total_deposited + ? >= 2000000 THEN 2 WHEN total_deposited + ? >= 500000 THEN 1 ELSE 0 END ' .
            'WHERE user_id = ? RETURNING balance, vip_level, total_deposited, ref_by',
            [$amount, $amount, $amount, $amount, $amount, $userId]
        );

        Database::run(
            "INSERT INTO transactions (user_id, amount, type, reference_code, status) VALUES (?, ?, 'DEPOSIT', ?, 'paid')",
            [$userId, $amount, $reference]
        );
        Database::run(
            "INSERT INTO wallet_ledger (reference_code, user_id, amount, transaction_type, description) VALUES (?, ?, ?, 'DEPOSIT', ?)",
            ['deposit:' . $reference, $userId, $amount, 'Nạp tiền qua ngân hàng']
        );

        $rate = max(0.0, min(30.0, (float) (Database::value("SELECT value FROM app_settings WHERE key = 'referral_commission_percent'") ?? 0)));
        $referrer = (string) ($user['ref_by'] ?? '');
        $reward = (int) floor($amount * $rate / 100);
        if ($referrer !== '' && $reward > 0) {
            Database::run('UPDATE users SET balance = balance + ? WHERE user_id = ?', [$reward, $referrer]);
            Database::run(
                "INSERT INTO wallet_ledger (reference_code, user_id, amount, transaction_type, description) VALUES (?, ?, ?, 'REFERRAL_REWARD', ?)",
                ['referral:' . $reference, $referrer, $reward, 'Hoa hồng giới thiệu từ giao dịch ' . $reference]
            );
            Database::run(
                "INSERT INTO transactions (user_id, amount, type, reference_code, status) VALUES (?, ?, 'REFERRAL_REWARD', ?, 'paid')",
                [$referrer, $reward, $reference]
            );
        }

        return ['duplicate' => false, 'balance' => (int) $user['balance']];
    });

    if ($result['duplicate']) {
        return ['status' => 'duplicate', 'referenceCode' => $reference];
    }

    try {
        $token = TelegramAuth::token('primary');
        if ($token !== '') {
            (new TelegramApi($token))->sendMessage(
                $userId,
                '🎉 <b>NẠP TIỀN THÀNH CÔNG</b>' . "\n" .
                'Số tiền nhận: <b>+' . number_format($amount, 0, ',', '.') . ' VNĐ</b>' . "\n" .
                'Mã giao dịch: <code>' . htmlspecialchars($reference, ENT_QUOTES, 'UTF-8') . '</code>' . "\n" .
                'Số dư hiện tại: <b>' . number_format((int) $result['balance'], 0, ',', '.') . ' VNĐ</b>'
            );
        }
    } catch (Throwable $error) {
        error_log('Đã ghi nhận nạp tiền nhưng không gửi được Telegram: ' . $error->getMessage());
    }

    return ['status' => 'success', 'referenceCode' => $reference];
}

try {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || strtolower((string) ($_SERVER['HTTPS'] ?? '')) === 'on') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    $allowedOrigins = array_filter(array_map(
        static fn (string $value): string => rtrim(trim($value), '/'),
        explode(',', (string) Env::get('ALLOWED_ORIGINS', ''))
    ));
    $baseUrl = rtrim((string) (Env::get('APP_URL') ?: Env::get('RENDER_EXTERNAL_URL', '')), '/');
    if ($baseUrl !== '') {
        $allowedOrigins[] = $baseUrl;
    }
    if ($origin !== '') {
        $localAllowed = Env::get('APP_ENV', 'production') !== 'production'
            && (bool) preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#i', $origin);
        if (!in_array(rtrim($origin, '/'), $allowedOrigins, true) && !$localAllowed) {
            respond(['error' => 'Origin không được phép.'], 403);
        }
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, x-telegram-init-data, x-telegram-bot-key, x-bank-webhook-secret, x-telegram-bot-api-secret-token');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

    if ($method === 'GET' && preg_match('#^/+KHACH-HANG/TRUNG-TAM-KHACH-HANG/index\\.php/?$#i', $path)) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        readfile(dirname(__DIR__) . '/apps/website/index.html');
        exit;
    }

    if ($method === 'GET' && $path === '/legacy-login') {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        readfile(dirname(__DIR__) . '/apps/admin-dashboard/legacy-login.html');
        exit;
    }

    // Public marketing homepage. Assets are self-hosted and isolated from customer/admin apps.
    if ($method === 'GET' && in_array($path, ['/', '/index.html'], true)) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data:; connect-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'");
        readfile(dirname(__DIR__) . '/apps/website/landing.html');
        exit;
    }

    // Public legal pages share the public design but remain outside the authenticated Mini App.
    if ($method === 'GET' && in_array($path, ['/privacy', '/privacy/'], true)) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: public, max-age=1800');
        header("Content-Security-Policy: default-src 'self'; style-src 'self'; img-src 'self' data:; base-uri 'self'; object-src 'none'; frame-ancestors 'self'");
        readfile(dirname(__DIR__) . '/apps/website/privacy.html');
        exit;
    }
    if ($method === 'GET' && in_array($path, ['/terms', '/terms/'], true)) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: public, max-age=1800');
        header("Content-Security-Policy: default-src 'self'; style-src 'self'; img-src 'self' data:; base-uri 'self'; object-src 'none'; frame-ancestors 'self'");
        readfile(dirname(__DIR__) . '/apps/website/terms.html');
        exit;
    }


    // Customer portal and Telegram Mini App share the authenticated customer application.
    if ($method === 'GET' && in_array($path, [
        '/customer', '/customer/', '/customer-center', '/customer-center/',
        '/miniapp', '/miniapp/', '/mini-app', '/mini-app/', '/app', '/app/'
    ], true)) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Robots-Tag: noindex, nofollow');
        readfile(dirname(__DIR__) . '/apps/website/index.html');
        exit;
    }

    // Admin application is isolated at /admin and performs server-side Telegram auth.
    if ($method === 'GET' && in_array($path, ['/admin', '/admin/'], true)) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Robots-Tag: noindex, nofollow');
        readfile(dirname(__DIR__) . '/apps/admin-dashboard/index.html');
        exit;
    }

    if ($method === 'GET' && in_array($path, ['/api/v1', '/api/v1/'], true)) {
        respond([
            'name' => 'HENDY CYBERTECH API',
            'version' => 'v1',
            'routes' => [
                'publicConfig' => '/api/v1/public/config',
                'health' => '/api/v1/health',
                'bootstrap' => '/api/v1/bootstrap',
                'customerCenter' => '/api/v1/customer-center',
                'products' => '/api/v1/products',
                'orders' => '/api/v1/orders',
                'wallet' => '/api/v1/wallet',
                'admin' => '/api/v1/admin/*',
            ],
            'web' => ['home' => '/', 'admin' => '/admin', 'customer' => '/customer', 'miniapp' => '/miniapp'],
        ]);
    }

    // Public website config. Telegram entry links are configured explicitly in deployment environment.
    if ($method === 'GET' && $path === '/api/v1/public/config') {
        $primaryBotUrl = Hendy\TelegramLink::normalize((string) Env::get('BOT1_PUBLIC_URL', ''));
        $secondaryBotUrl = Hendy\TelegramLink::normalize((string) Env::get('BOT2_PUBLIC_URL', ''));
        $groupUrl = Hendy\TelegramLink::normalize((string) (Env::get('GROUP_URL') ?: Env::get('GROUP_CHAT_URL', '')));
        $channelUrl = Hendy\TelegramLink::normalize((string) (Env::get('CHANNEL_URL') ?: Env::get('NEWS_CHANNEL_URL', '')));
        $supportUrl = Hendy\TelegramLink::normalize((string) (Env::get('SUPPORT_URL') ?: $groupUrl));

        respond([
            'brand' => 'HENDY BOT | HENDY CYBERTECH',
            'botConfigured' => $primaryBotUrl !== '',
            'botUrl' => $primaryBotUrl,
            'secondaryBotConfigured' => $secondaryBotUrl !== '',
            'secondaryBotUrl' => $secondaryBotUrl,
            'groupUrl' => $groupUrl,
            'channelUrl' => $channelUrl,
            'supportUrl' => $supportUrl,
            'routes' => [
                'home' => '/',
                'admin' => '/admin',
                'customer' => '/customer',
                'miniapp' => '/miniapp',
                'api' => '/api/v1',
                'health' => '/health',
                'privacy' => '/privacy',
                'terms' => '/terms',
            ],
        ]);
    }

    if ($method === 'POST' && $path === '/auth/logout') {
        setcookie('hendy_web_session', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        respond(['ok' => true]);
    }
    if ($method === 'GET' && $path === '/styles/buttons.css') {
        header('Content-Type: text/css; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        readfile(dirname(__DIR__) . '/apps/admin-dashboard/styles/buttons.css');
        exit;
    }
    if ($method === 'GET' && in_array($path, ['/health', '/healthz', '/api/health'], true)) {
        Database::check();
        respond(['status' => 'ok', 'service' => 'hendy-bot-cybertech']);
    }

    // --- ROUTE TÙY CHỈNH MỞ RỘNG ---
    if ($method === 'POST' && $path === '/api/custom-action') {
        $input = json_decode((string) file_get_contents('php://input'), true) ?? [];
        $name = trim((string) ($input['name'] ?? 'Khách'));
        respond([
            'status' => 'success',
            'message' => 'Xin chào ' . $name . ', tuyến đường tùy chỉnh đã hoạt động thành công!'
        ]);
    }

    if ($method === 'POST' && $path === '/webhook/video') {
        $secret = (string) Env::get('VIDEO_PROCESSOR_SECRET', '');
        if ($secret === '') {
            throw new HttpException(503, 'Video processor callback chưa được cấu hình.');
        }
        $rawBody = (string) file_get_contents('php://input');
        if ($rawBody === '' || strlen($rawBody) > 131072) {
            throw new HttpException(400, 'Payload video callback không hợp lệ.');
        }
        $received = trim((string) ($_SERVER['HTTP_X_HENDY_VIDEO_SIGNATURE'] ?? ''));
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
        if (!hash_equals($expected, $received)) {
            throw new HttpException(401, 'Chữ ký video callback không hợp lệ.');
        }
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            throw new HttpException(400, 'JSON video callback không hợp lệ.');
        }
        try {
            $job = VideoJobService::updateFromCallback($payload);
        } catch (Throwable $error) {
            throw new HttpException(400, $error->getMessage());
        }

        if (empty($job['duplicate']) && in_array(($job['status'] ?? ''), ['completed', 'failed'], true)) {
            $token = TelegramAuth::token((string) ($job['bot_key'] ?? 'primary'));
            if ($token !== '') {
                try {
                    $api = new TelegramApi($token);
                    if ($job['status'] === 'completed') {
                        $api->sendMessage(
                            (string) $job['user_id'],
                            '✅ <b>VIDEO VIETSUB ĐÃ HOÀN TẤT</b>' . "\n" .
                            'Mã job: <code>' . htmlspecialchars(substr((string) $job['id'], 0, 8), ENT_QUOTES, 'UTF-8') . '</code>' . "\n" .
                            'Kết quả: ' . htmlspecialchars((string) ($job['output_url'] ?? ''), ENT_QUOTES, 'UTF-8')
                        );
                    } else {
                        $api->sendMessage(
                            (string) $job['user_id'],
                            '❌ <b>VIDEO VIETSUB CHƯA THỂ HOÀN TẤT</b>' . "\n" .
                            'Mã job: <code>' . htmlspecialchars(substr((string) $job['id'], 0, 8), ENT_QUOTES, 'UTF-8') . '</code>' . "\n" .
                            'Lý do: ' . htmlspecialchars((string) ($job['error_message'] ?? 'Nhà xử lý video báo lỗi.'), ENT_QUOTES, 'UTF-8')
                        );
                    }
                } catch (Throwable $error) {
                    error_log('Không gửi được thông báo video job: ' . $error->getMessage());
                }
            }
        }
        respond(['ok' => true, 'jobId' => $job['id'] ?? null, 'status' => $job['status'] ?? 'unknown']);
    }

    if ($method === 'POST' && $path === '/webhook/bank') {
        if (!Env::bool('BANK_WEBHOOK_ENABLED', false)) {
            throw new HttpException(503, 'Webhook nạp tiền đang tạm khóa cho đến khi cấu hình adapter chữ ký của nhà cung cấp ngân hàng.');
        }
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body)) {
            throw new HttpException(400, 'Dữ liệu JSON không hợp lệ.');
        }
        respond(processBankDeposit($body));
    }

    if ($method === 'POST' && in_array($path, ['/webhook/telegram', '/webhook/telegram/secondary'], true)) {
        $botKey = $path === '/webhook/telegram/secondary' ? 'secondary' : 'primary';
        $token = TelegramAuth::token($botKey);
        if ($token === '') {
            throw new HttpException(404, 'Bot được yêu cầu chưa được bật.');
        }
        $expected = TelegramAuth::webhookSecret($botKey);
        $received = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
        if (!WebhookSecurity::verifySecret($expected, $received)) {
            throw new HttpException(401, 'Webhook secret không hợp lệ.');
        }

        $rawUpdate = (string) file_get_contents('php://input');
        $update = json_decode($rawUpdate, true);
        if (!is_array($update)) {
            throw new HttpException(400, 'Telegram update không hợp lệ.');
        }
        (new BotService($botKey))->handle($update);
        respond(['ok' => true]);
    }

    if ($method === 'POST' && $path === '/api/admin/modules/upload') {
        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        if (empty($_SERVER['HTTP_AUTHORIZATION'])) {
            foreach ($headers as $name => $value) if (strcasecmp((string) $name, 'Authorization') === 0) $_SERVER['HTTP_AUTHORIZATION'] = (string) $value;
        }
        $controller = new ApiController($_GET, [], $headers);
        $result = $controller->uploadModule($_FILES['file'] ?? [], (string) ($_POST['module'] ?? ''));
        respond($result['body'] ?? [], (int) ($result['status'] ?? 200));
    }

    // Versioned public API aliases the stable internal /api routes.
    if (str_starts_with($path, '/api/v1/')) {
        $path = '/api/' . substr($path, strlen('/api/v1/'));
    }

    if ($method === 'GET' && $path === '/api/health') {
        respond(['status' => 'ok', 'service' => 'hendy-bot-cybertech', 'apiVersion' => 'v1']);
    }

    if (str_starts_with($path, '/api/')) {
        $raw = (string) file_get_contents('php://input');
        if (strlen($raw) > 262144) {
            throw new HttpException(413, 'Dữ liệu gửi lên quá lớn.');
        }
        $body = $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($body)) {
            throw new HttpException(400, 'Dữ liệu JSON không hợp lệ.');
        }
        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        if (empty($_SERVER['HTTP_AUTHORIZATION'])) {
            foreach ($headers as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    $_SERVER['HTTP_AUTHORIZATION'] = (string) $value;
                    break;
                }
            }
        }
        if (empty($_SERVER['HTTP_AUTHORIZATION']) && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $_SERVER['HTTP_AUTHORIZATION'] = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        $controller = new ApiController($_GET, $body, $headers);
        $result = $controller->handle($method, $path);
        respond($result['body'] ?? [], (int) ($result['status'] ?? 200));
    }

    throw new HttpException(404, 'Không tìm thấy trang.');
} catch (HttpException $error) {
    respond(['error' => $error->getMessage()], $error->status);
} catch (Throwable $error) {
    error_log('[hendy-bot-tele] ' . $error::class . ': ' . $error->getMessage());
    $status = $error instanceof PDOException ? 503 : 500;
    respond(['error' => $status === 503 ? 'Dịch vụ dữ liệu tạm thời không khả dụng.' : 'Lỗi máy chủ. Vui lòng thử lại.'], $status);
}
