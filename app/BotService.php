<?php
declare(strict_types=1);

namespace Hendy;

use RuntimeException;
use Throwable;

final class BotService
{
    private const TTL_MINUTES = 10;
    private const BRANDS = ['QQ88', 'HI88', '78WIN', 'RR88', 'MM88', 'GG88', 'ABCVIP', 'XX8', 'U888', 'J88', 'ABC8', '88CLB', 'AD88', 'SC88', 'C168', 'CM88', 'F8BET'];

    private const MINIGAME_GROUPS = [
        'group1' => ['label' => 'Nhóm 1 · QQ88 HI88 78WIN', 'brands' => ['QQ88', 'HI88', '78WIN']],
        'group2' => ['label' => 'Nhóm 2 · RR88 MM88 GG88', 'brands' => ['RR88', 'MM88', 'GG88']],
        'group3' => ['label' => 'Nhóm 3 · [ABCVIP] XX8 U888 J88 ABC8 88CLB', 'brands' => ['ABCVIP', 'XX8', 'U888', 'J88', 'ABC8', '88CLB']],
    ];


    private TelegramApi $telegram;
    private string $botKey;

    public function __construct(string $botKey)
    {
        $this->botKey = $botKey === 'secondary' ? 'secondary' : 'primary';
        $token = TelegramAuth::token($this->botKey);
        if ($token === '') {
            throw new RuntimeException('Token cho ' . $this->botKey . ' chưa được cấu hình.');
        }
        $this->telegram = new TelegramApi($token);
    }

    public function api(): TelegramApi
    {
        return $this->telegram;
    }

    public function handle(array $update): void
    {
        $updateId = isset($update['update_id']) ? (int) $update['update_id'] : null;
        if ($updateId !== null) {
            $processed = Database::one(
                'SELECT update_id FROM telegram_updates WHERE bot_key = ? AND update_id = ?',
                [$this->botKey, $updateId]
            );
            if ($processed !== null) {
                return;
            }
        }

        try {
            if (isset($update['message']) && is_array($update['message'])) {
                $this->handleMessage($update['message']);
            } elseif (isset($update['callback_query']) && is_array($update['callback_query'])) {
                $this->handleCallback($update['callback_query']);
            }
        } catch (Throwable $error) {
            error_log('[telegram:' . $this->botKey . '] ' . $error->getMessage());
            $chatId = (string) (
                $update['message']['chat']['id']
                ?? $update['callback_query']['message']['chat']['id']
                ?? $update['callback_query']['from']['id']
                ?? ''
            );
            if ($chatId !== '') {
                try {
                    $this->telegram->sendMessage($chatId, '❌ Hệ thống chưa thể xử lý yêu cầu này. Vui lòng thử lại sau hoặc liên hệ CSKH.');
                } catch (Throwable $notifyError) {
                    error_log('[telegram:' . $this->botKey . '] Không gửi được thông báo lỗi: ' . $notifyError->getMessage());
                }
            }
        }

        Realtime::publish('bot.update', ['botKey' => $this->botKey, 'updateId' => $updateId, 'kind' => isset($update['message']) ? 'message' : (isset($update['callback_query']) ? 'callback' : 'other')]);
        Realtime::publish('user.data.changed', ['botKey' => $this->botKey], 'users');

        if ($updateId !== null) {
            Database::run(
                'INSERT INTO telegram_updates (bot_key, update_id) VALUES (?, ?) ON CONFLICT DO NOTHING',
                [$this->botKey, $updateId]
            );
        }
    }

    private function handleMessage(array $message): void
    {
        $from = $message['from'] ?? [];
        $chat = $message['chat'] ?? [];
        $userId = (string) ($from['id'] ?? '');
        $chatId = (string) ($chat['id'] ?? '');
        $text = trim((string) ($message['text'] ?? ''));

        if (!preg_match('/^\d{5,20}$/', $userId) || $chatId === '' || $text === '') {
            return;
        }
        if (preg_match('/^\/status(?:@\w+)?$/u', $text)) { $this->sendOperationalNotice($chatId); return; }
        $operation=OperationsService::forBot($this->botKey);
        if ($operation['active'] && !TelegramAuth::isAdmin($userId)) { $this->sendOperationalNotice($chatId); return; }

        $firstName = trim((string) ($from['first_name'] ?? ''));
        $lastName = trim((string) ($from['last_name'] ?? ''));
        $username = trim((string) ($from['username'] ?? ''));

        if (preg_match('/^\/start(?:@\w+)?(?:\s+(.+))?$/u', $text, $matches)) {
            $payload = trim((string) ($matches[1] ?? ''));
            $referrer = preg_match('/^ref_(\d{5,20})$/', $payload, $refMatch) ? $refMatch[1] : null;
            if ($referrer === $userId) {
                $referrer = null;
            }
            Database::run(
                'INSERT INTO users (user_id, name, username, ref_by) VALUES (?, ?, ?, ?) ' .
                'ON CONFLICT (user_id) DO UPDATE SET name = CASE WHEN EXCLUDED.name <> \'\' THEN EXCLUDED.name ELSE users.name END, ' .
                'username = CASE WHEN EXCLUDED.username <> \'\' THEN EXCLUDED.username ELSE users.username END',
                [$userId, trim($firstName . ' ' . $lastName), $username, $referrer]
            );
            if ($payload === 'web_login') {
                $this->sendLegacyLoginLink($chatId, $userId);
                return;
            }
            $this->clearPending($userId);
            $this->removeLegacyReplyKeyboard($chatId);
            $this->sendHome($chatId, $from);
            return;
        }

        if (preg_match('/^\/menu(?:@\w+)?$/u', $text)) {
            $this->removeLegacyReplyKeyboard($chatId);
            $this->sendHome($chatId, $from);
            return;
        }

        if (preg_match('/^\/help(?:@\w+)?$/u', $text)) {
            $this->telegram->sendMessage($chatId, implode("\n", [
                '🤖 <b>HENDY BOT</b>',
                '/start — Mở menu chính',
                '/menu — Hiển thị lại menu',
                '/balance — Kiểm tra số dư ví',
                '/deposit [số tiền] — Tạo yêu cầu nạp và mã VietQR',
                '/history — Xem lịch sử ví và video jobs',
                '/vietsub — Gửi link video để tạo job phụ đề',
                '/jobs — Xem trạng thái video jobs',
                '/orders — Xem đơn hàng',
                '/admin — Mở bảng quản trị (chỉ Admin)',
                '/admin_payments — Duyệt yêu cầu nạp (Admin)',
                '/admin_jobs — Xem video jobs (Admin)',
                '/help — Hướng dẫn sử dụng',
                '',
                'Chọn một nút bên dưới tin nhắn để mở chức năng.',
            ]));
            return;
        }

        if (preg_match('/^\/balance(?:@\w+)?$/u', $text)) {
            $this->showBalance($chatId, $userId);
            return;
        }

        if (preg_match('/^\/deposit(?:@\w+)?(?:\s+(\d+))?$/u', $text, $matches)) {
            $amount = isset($matches[1]) && $matches[1] !== '' ? (int) $matches[1] : 0;
            if ($amount > 0) {
                $this->showDeposit($chatId, $userId, $amount);
            } else {
                $this->showWalletMenu($chatId, $userId);
            }
            return;
        }

        if (preg_match('/^\/vietsub(?:@\w+)?$/u', $text)) {
            $this->startVideoRequest($chatId, $userId);
            return;
        }

        if (preg_match('/^\/(?:history|jobs)(?:@\w+)?$/u', $text, $matches)) {
            if (str_starts_with($text, '/jobs')) {
                $this->showVideoJobs($chatId, $userId);
            } else {
                $this->showUserHistory($chatId, $userId);
            }
            return;
        }

        if (preg_match('/^\/admin_payments(?:@\w+)?$/u', $text)) {
            $this->showAdminPayments($chatId, $userId);
            return;
        }

        if (preg_match('/^\/admin_jobs(?:@\w+)?$/u', $text)) {
            $this->showAdminVideoJobs($chatId, $userId);
            return;
        }

        if (preg_match('/^\/orders(?:@\w+)?$/u', $text)) {
            $this->showOrders($chatId, $userId);
            return;
        }
        if (preg_match('/^\/(?:customer|cskh)(?:@\w+)?$/u', $text)) {
            $this->showCustomer($chatId, $userId);
            return;
        }
        if (preg_match('/^\/minigame(?:@\w+)?$/u', $text)) {
            $this->showMinigameGroups($chatId, $userId);
            return;
        }
        if (preg_match('/^\/otp(?:@\w+)?$/u', $text)) {
            $this->showOtpCenter($chatId, $userId);
            return;
        }
        if (preg_match('/^\/proxy(?:@\w+)?$/u', $text)) {
            $this->showProxyCenter($chatId, $userId);
            return;
        }

        if (preg_match('/^\/admin(?:@\w+)?$/u', $text)) {
            $this->openAdmin($chatId, $userId);
            return;
        }

        if (preg_match('/^\/addmoney(?:@\w+)?\s+(\d{5,20})\s+(\d+)$/u', $text, $matches)) {
            $this->addMoney($chatId, $userId, $matches[1], (int) $matches[2]);
            return;
        }

        if (str_starts_with($text, '/')) {
            return;
        }

        $pending = $this->getPending($userId);
        if ($pending !== null && $this->processPending($chatId, $userId, $text, $pending)) {
            return;
        }

        $action = $this->actionForText($text, $userId);
        if ($action !== null) {
            // Reply Keyboard sends a normal message, not a callback query.
            // Show Telegram's typing indicator while the catalog/database request runs.
            try {
                $this->telegram->sendChatAction($chatId, 'typing');
            } catch (Throwable $actionError) {
                error_log('[telegram:' . $this->botKey . '] Chat action failed: ' . $actionError->getMessage());
            }
            $this->dispatchAction($chatId, $userId, $action);
        }
    }

    private function handleCallback(array $callback): void
    {
        $callbackId = (string) ($callback['id'] ?? '');
        $from = $callback['from'] ?? [];
        $userId = (string) ($from['id'] ?? '');
        $message = $callback['message'] ?? [];
        $chatId = (string) ($message['chat']['id'] ?? $userId);
        $data = (string) ($callback['data'] ?? '');

        try {
            if ($callbackId !== '') {
                try {
                    $this->telegram->answerCallback($callbackId);
                } catch (Throwable $ackError) {
                    // Callback acknowledgement failure must not cancel the requested business action.
                    error_log('[telegram:' . $this->botKey . '] Callback acknowledgement failed: ' . $ackError->getMessage());
                }
            }
            if (!preg_match('/^\d{5,20}$/', $userId)) {
                return;
            }
            $operation=OperationsService::forBot($this->botKey);
            if ($operation['active'] && !TelegramAuth::isAdmin($userId)) { $this->sendOperationalNotice($chatId); return; }
            if (str_starts_with($data, 'member:')) {
                $this->dispatchAction($chatId, $userId, substr($data, 7));
                return;
            }

            $legacyActions = [
                'back_home' => 'home',
                'menu_minigame' => 'live',
                'rr88_game' => 'live-game:RR88',
                'mm88_game' => 'live-game:MM88',
                'gg88_game' => 'live-game:GG88',
                'ad88_game' => 'minigame-products',
                'abcvip_kho' => 'live-game:ABCVIP',
                'menu_tool' => 'tools',
                'menu_code' => 'codes',
                'menu_nap' => 'wallet',
                'menu_otp' => 'otp',
                'shop_otp' => 'otp-products',
                'shop_proxy' => 'proxy-products',
                'get_free_proxy' => 'proxy-products',
                'proxy_xoay' => 'proxy-products',
                'otp_abcvip' => 'otp-products',
                'otp_fb' => 'otp-products',
                'otp_tele' => 'otp-products',
                'otp_sc88' => 'otp-products',
                'menu_kho_cc' => 'viptools',
                'menu_cskh' => 'customer',
                'ref' => 'ref',
                'vip' => 'vip',
                'giftcode' => 'gift',
                'cskh' => 'support',
                'tool_1' => 'tools',
                'tool_2' => 'tools',
                'tool_3' => 'tools',
                // Legacy tool_4 was labelled bulk SMS spam; this only opens the
                // normal tool catalogue and does not execute or send messages.
                'tool_4' => 'tools',
            ];
            if (!isset($legacyActions[$data])) {
                return;
            }
            $this->dispatchAction($chatId, $userId, $legacyActions[$data]);
        } catch (Throwable $error) {
            error_log('[telegram:' . $this->botKey . '] ' . $error->getMessage());
            $this->telegram->sendMessage($chatId, '❌ ' . self::escape($error->getMessage()));
        }
    }

    private static function isHttpsUrl(string $url): bool
    {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && !empty($parts['host']);
    }

    private function sendOperationalNotice(string $chatId): void
    {
        $operation=OperationsService::forBot($this->botKey);
        if (!$operation['active']) { $this->telegram->sendMessage($chatId,'✅ HENDY BOT đang hoạt động bình thường.'); return; }
        $title=$operation['mode']==='upgrade'?'🚀 <b>HENDY BOT ĐANG NÂNG CẤP</b>':'🛠️ <b>HENDY BOT ĐANG BẢO TRÌ</b>';
        $lines=[$title,'━━━━━━━━━━━━━━━━━━━━',self::escape($operation['message'])];
        if (!empty($operation['until'])) {
            try {
                $until=(new \DateTimeImmutable((string)$operation['until']))->setTimezone(new \DateTimeZone('Asia/Ho_Chi_Minh'))->format('H:i d/m/Y');
                $lines[]='⏰ Dự kiến hoàn tất: <b>'.$until.' (giờ Việt Nam)</b>';
            } catch (Throwable) { /* notice stays available if a stored date is invalid */ }
        }
        $lines[]='Vui lòng thử lại sau. Cảm ơn bạn đã thông cảm!';
        $this->telegram->sendMessage($chatId,implode("\n",$lines));
    }

    private function removeLegacyReplyKeyboard(string $chatId): void
    {
        try {
            $this->telegram->sendMessage(
                $chatId,
                '👋 Chào mừng bạn đến với HENDY BOT!',
                ['reply_markup' => ['remove_keyboard' => true]]
            );
        } catch (Throwable $error) {
            error_log('[telegram:' . $this->botKey . '] Không thể gỡ Reply Keyboard cũ: ' . $error->getMessage());
        }
    }

    private function sendHome(string $chatId, array $from): void
    {
        $userId = (string) ($from['id'] ?? '');
        $firstName = trim((string) ($from['first_name'] ?? ''));
        $lastName = trim((string) ($from['last_name'] ?? ''));
        $username = trim((string) ($from['username'] ?? ''));
        $displayName = trim($firstName . ' ' . $lastName);
        $user = $this->getUser($userId, $displayName, $username);
        $config = $this->menuConfig();
        $items = $this->menuItems($config, $userId);

        $settings = $this->settings(['website_url', 'GROUP_URL']);
        $appBaseUrl = rtrim((string) (Env::get('APP_URL') ?: Env::get('RENDER_EXTERNAL_URL', '')), '/');
        $websiteUrl = trim((string) ($settings['website_url'] ?? ''));
        if (!self::isHttpsUrl($websiteUrl)) $websiteUrl = self::isHttpsUrl($appBaseUrl) ? $appBaseUrl . '/' : 'https://hendy-bot-cybertech-1.onrender.com/';
        $groupUrl = trim((string) ($settings['GROUP_URL'] ?? Env::get('GROUP_URL', 'https://t.me/Hendy_Support_Group')));
        if (!self::isHttpsUrl($groupUrl)) $groupUrl = 'https://t.me/Hendy_Support_Group';

        $hour = (int) (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Ho_Chi_Minh')))->format('G');
        $greeting = match (true) {
            $hour >= 5 && $hour < 11 => 'buổi sáng',
            $hour >= 11 && $hour < 13 => 'buổi trưa',
            $hour >= 13 && $hour < 18 => 'buổi chiều',
            $hour >= 18 && $hour < 22 => 'buổi tối',
            default => 'buổi đêm',
        };
        $name = $firstName !== '' ? $firstName : ($username !== '' ? '@' . $username : 'bạn');
        $botPublicUrl = TelegramLink::normalize((string) Env::get(
            $this->botKey === 'secondary' ? 'BOT2_PUBLIC_URL' : 'BOT1_PUBLIC_URL',
            ''
        ));
        $botLabel = 'HENDY BOT';
        $vipLevel = (int) ($user['vip_level'] ?? 0);
        $intro = self::truncate(trim((string) ($config['intro_text'] ?? '')), 220);

        $lines = [
            '💠 <b>⩤ HENDY DIGITAL ECOSYSTEM ⩥</b>',
            '✨ <i>Công nghệ kết nối giá trị thật</i>',
            '━━━━━━━━━━━━━━━━━━━━',
            '👋 Chúc ' . $greeting . ' vui vẻ, <b>' . self::escape($name) . '</b>!',
            '🤖 <b>' . $botLabel . '</b> · Luồng dịch vụ riêng',
            '🆔 Telegram ID: <code>' . self::escape($userId) . '</code>',
            '━━━━━━━━━━━━━━━━━━━━',
            '💰 Ví Chính: <b>' . self::money($user['balance'] ?? 0) . '</b>',
            '🪙 Ví NYC: <b>' . self::money($user['nyc_wallet'] ?? 0) . '</b>',
            '🎟️ Ví Voucher: <b>' . self::money($user['voucher_wallet'] ?? 0) . '</b>',
            '📈 Tổng nạp: <b>' . self::money($user['total_deposited'] ?? 0) . '</b>',
            '👑 Thành viên: <b>' . ($vipLevel > 0 ? 'VIP ' . $vipLevel : 'HENDY MEMBER') . '</b>',
            '━━━━━━━━━━━━━━━━━━━━',
            '🚀 <b>CHỌN DỊCH VỤ CỦA BẠN</b>',
            'Mini Game Live · Tool · Code · OTP · Proxy · Vietsub · VIP · CSKH.',
        ];
        if ($intro !== '') $lines[] = self::escape($intro);
        $links = ['<a href="' . self::escape($websiteUrl) . '">🌐 Website HENDY</a>'];
        if (self::isHttpsUrl($groupUrl)) $links[] = '<a href="' . self::escape($groupUrl) . '">💬 Nhóm hỗ trợ</a>';
        if ($botPublicUrl !== '') {
            $links[] = '<a href="' . self::escape($botPublicUrl) . '">🤖 Mở Telegram Bot</a>';
        }
        $lines[] = implode(' · ', $links);

        $caption = implode("\n", $lines);
        $replyMarkup = ['reply_markup' => $this->menuMarkup($items, $config)];
        $homePhoto = trim((string) Env::get('BOT_HOME_PHOTO', ''));
        if ($homePhoto === '') $homePhoto = 'https://i.postimg.cc/rcD583NW/anh-quan-cao.png?dl=1';
        $validPhoto = self::isHttpsUrl($homePhoto)
            || preg_match('/\\A[A-Za-z0-9_-]{30,512}\\z/D', $homePhoto) === 1;
        if ($validPhoto && mb_strlen($caption, 'UTF-8') <= 1000) {
            try {
                $this->telegram->sendPhoto($chatId, $homePhoto, $caption, $replyMarkup);
                return;
            } catch (Throwable $error) {
                error_log('[telegram:' . $this->botKey . '] Không gửi được banner trang chủ; chuyển sang tin nhắn văn bản: ' . $error->getMessage());
            }
        }
        $this->telegram->sendMessage($chatId, $caption, $replyMarkup);
    }

    private function menuConfig(): array
    {
        $row = Database::one('SELECT * FROM bot_menu_configs WHERE bot_key = ?', [$this->botKey]);
        return $row ?? [
            'bot_key' => $this->botKey,
            'menu_mode' => 'inline_keyboard',
            'intro_text' => 'Chào mừng bạn đến với HENDY BOT. Chọn dịch vụ ở bàn phím phía dưới để bắt đầu.',
            'menu_columns' => 2,
            'persistent_keyboard' => true,
            'resize_keyboard' => true,
            'button_labels' => [],
            'button_descriptions' => [],
            'button_styles' => [],
            'button_icons' => [],
        ];
    }

    private function menuItems(array $config, string $userId): array
    {
        $labels = self::jsonArray($config['button_labels'] ?? []);
        $descriptions = self::jsonArray($config['button_descriptions'] ?? []);
        $styles = self::jsonArray($config['button_styles'] ?? []);
        $icons = self::jsonArray($config['button_icons'] ?? []);
        $items = [];

        foreach (BotMenuDefinition::ITEMS as $item) {
            $action = $item['action'];
            $configuredLabel = (string) ($labels[$action] ?? $item['label']);
            if ($action === 'customer' && (str_contains($configuredLabel, 'TRUNG TÂM KHÁCH HÀNG') || str_contains($configuredLabel, 'TRUNG TÂM QUẢN LÝ TÀI KHOẢN'))) {
                $configuredLabel = '💠 TRUNG TÂM KHÁCH HÀNG HENDY';
            }
            $items[] = [
                ...$item,
                'label' => self::truncate($configuredLabel, 60),
                'description' => self::truncate((string) ($descriptions[$action] ?? $item['description']), 160),
                'style' => in_array($styles[$action] ?? '', ['primary', 'success', 'danger'], true) ? $styles[$action] : ($item['style'] ?? 'primary'),
                'iconCustomEmojiId' => preg_match('/^[0-9]{5,32}$/', (string) ($icons[$action] ?? '')) ? (string) $icons[$action] : '',
            ];
        }

        if (TelegramAuth::isAdmin($userId)) {
            $items[] = [
                'action' => 'admin',
                'label' => self::truncate((string) ($labels['admin'] ?? '🛡️ BẢNG ĐIỀU KHIỂN ADMIN'), 60),
                'description' => self::truncate((string) ($descriptions['admin'] ?? 'Mở bảng quản trị dành riêng cho Admin.'), 160),
                'style' => in_array($styles['admin'] ?? '', ['primary', 'success', 'danger'], true) ? $styles['admin'] : 'primary',
                'iconCustomEmojiId' => preg_match('/^[0-9]{5,32}$/', (string) ($icons['admin'] ?? '')) ? (string) $icons['admin'] : '',
            ];
        }
        return $items;
    }

    private function menuRows(array $items, int $columns): array
    {
        $byAction = [];
        foreach ($items as $item) $byAction[(string) $item['action']] = $item;
        $rows = [];
        $used = [];
        foreach (BotMenuDefinition::LAYOUT as $layout) {
            $row = [];
            foreach ($layout as $action) {
                if (isset($byAction[$action])) {
                    $row[] = $byAction[$action];
                    $used[$action] = true;
                }
            }
            if ($row !== []) $rows[] = $row;
        }
        $remaining = [];
        foreach ($items as $item) {
            $action = (string) $item['action'];
            if (!isset($used[$action])) $remaining[] = $item;
        }
        foreach (array_chunk($remaining, max(1, min(3, $columns))) as $row) $rows[] = $row;
        return $rows;
    }

    private function menuMarkup(array $items, array $config): array
    {
        $columns = max(1, min(3, (int) ($config['menu_columns'] ?? 2)));
        $rows = [];
        foreach ($this->menuRows($items, $columns) as $group) {
            $row = [];
            foreach ($group as $item) {
                $button = ['text' => $item['label'], 'style' => $item['style']];
                if (($item['iconCustomEmojiId'] ?? '') !== '') $button['icon_custom_emoji_id'] = $item['iconCustomEmojiId'];
                if ($item['action'] === 'miniapp') {
                    $url = $this->miniAppUrl();
                    if ($url !== '') $button['web_app'] = ['url' => $url];
                    else $button['callback_data'] = 'member:miniapp';
                } else {
                    $button['callback_data'] = 'member:' . $item['action'];
                }
                $row[] = $button;
            }
            if ($row !== []) $rows[] = $row;
        }
        // Ignore legacy Reply Keyboard settings; the bot home menu is Inline-only.
        return ['inline_keyboard' => $rows];
    }

    private function replyRows(array $items, array $config): array
    {
        $rows = [];
        foreach ($this->menuRows($items, (int) ($config['menu_columns'] ?? 2)) as $group) {
            $row = [];
            foreach ($group as $item) {
                $button = ['text' => $item['label'], 'style' => $item['style']];
                if (($item['iconCustomEmojiId'] ?? '') !== '') $button['icon_custom_emoji_id'] = $item['iconCustomEmojiId'];
                if ($item['action'] === 'miniapp') {
                    $url = $this->miniAppUrl();
                    if ($url !== '') $button['web_app'] = ['url' => $url];
                }
                $row[] = $button;
            }
            if ($row !== []) $rows[] = $row;
        }
        return $rows;
    }

    private function inlineMenu(array $rows): array
    {
        return ['inline_keyboard' => $rows];
    }

    private function actionForText(string $text, string $userId): ?string
    {
        foreach ($this->menuItems($this->menuConfig(), $userId) as $item) {
            if ($item['label'] === $text) {
                return $item['action'];
            }
        }
        return null;
    }

    private function dispatchAction(string $chatId, string $userId, string $action): void
    {
        if (preg_match('/^live-group:(group[1-3])$/', $action, $matches)) {
            $this->showMinigameGroupGames($chatId, $userId, $matches[1]);
            return;
        }
        if (preg_match('/^live-game:([A-Z0-9]+)$/', $action, $matches)) {
            $brand = strtoupper($matches[1]);
            if ($this->minigameGroupForBrand($brand) === null) {
                throw new RuntimeException('Game Mini Game không thuộc nhóm được hỗ trợ.');
            }
            $this->showMinigameGame($chatId, $userId, $brand);
            return;
        }
        if (preg_match('/^live-warehouse:([A-Z0-9]+)$/', $action, $matches)) {
            $brand = strtoupper($matches[1]);
            if ($this->minigameGroupForBrand($brand) === null) {
                throw new RuntimeException('Kho ACC không hợp lệ.');
            }
            $this->showMinigamePackageManager($chatId, $userId, $brand, 0);
            return;
        }
        if (preg_match('/^live-package:([A-Z0-9]+):(\d{1,18})$/', $action, $matches)) {
            $brand = strtoupper($matches[1]);
            if ($this->minigameGroupForBrand($brand) === null) {
                throw new RuntimeException('Gói Mini Game không hợp lệ.');
            }
            $this->showMinigamePackageManager($chatId, $userId, $brand, (int) $matches[2]);
            return;
        }
        if (preg_match('/^live-add-acc:([A-Z0-9]+):(\d{1,18})$/', $action, $matches)) {
            $brand = strtoupper($matches[1]);
            if ($this->minigameGroupForBrand($brand) === null) {
                throw new RuntimeException('Nhà cái không hợp lệ.');
            }
            $productId = (int) $matches[2];
            $this->setPending($userId, 'live_acc_username', ['brand' => $brand, 'product_id' => $productId]);
            $this->telegram->sendMessage(
                $chatId,
                '➕ <b>THÊM ACC ' . self::escape($brand) . '</b>' . "\n" .
                'Hãy gửi duy nhất username cần lưu vào kho cá nhân (3–64 ký tự, không có khoảng trắng).' . "\n" .
                'Không gửi mật khẩu, PIN, OTP hoặc mã xác minh. Yêu cầu hết hạn sau ' . self::TTL_MINUTES . ' phút.'
            );
            return;
        }
        if (preg_match('/^deposit:(\d{4,12})$/', $action, $matches)) {
            $this->showDeposit($chatId, $userId, (int) $matches[1]);
            return;
        }
        if (preg_match('/^payment-approve:([A-Z0-9]{6,24})$/', $action, $matches)) {
            $this->approvePaymentRequest($chatId, $userId, $matches[1]);
            return;
        }
        if (preg_match('/^payment-reject:([A-Z0-9]{6,24})$/', $action, $matches)) {
            $this->rejectPaymentRequest($chatId, $userId, $matches[1]);
            return;
        }
        if (preg_match('/^video-retry:([0-9a-f-]{36})$/i', $action, $matches)) {
            $this->retryVideoJob($chatId, $userId, $matches[1]);
            return;
        }

        if (preg_match('/^order:(\d{1,18})$/', $action, $matches)) {
            $this->createOrder($chatId, $userId, $matches[1]);
            return;
        }

        if (preg_match('/^select-brand:([A-Z0-9]+)$/', $action, $matches)) {
            $brand = strtoupper($matches[1]);
            if (!in_array($brand, self::BRANDS, true)) throw new RuntimeException('Thương hiệu không hợp lệ.');
            if ($brand === 'ABCVIP') {
                $this->showAbcvipConnect($chatId);
                return;
            }
            $this->setPending($userId, 'link_username', ['brand' => $brand]);
            $this->telegram->sendMessage($chatId, 'Bạn đã chọn <b>' . self::escape($brand) . '</b>. Hãy nhập username. Không gửi mật khẩu, PIN hoặc OTP.');
            return;
        }

        if (preg_match('/^live-delete-acc:([A-Z0-9]+):(\d{1,18}):(\d{1,18})$/', $action, $matches)) {
            $brand = strtoupper($matches[1]);
            if ($this->minigameGroupForBrand($brand) === null) {
                throw new RuntimeException('Nhà cái không hợp lệ.');
            }
            Database::run(
                'DELETE FROM linked_accounts WHERE id = ? AND user_id = ? AND brand = ?',
                [$matches[2], $userId, $brand]
            );
            $this->showMinigamePackageManager($chatId, $userId, $brand, (int) $matches[3]);
            return;
        }
        if (preg_match('/^link-delete:(\d{1,18})$/', $action, $matches)) {
            Database::run('DELETE FROM linked_accounts WHERE id = ? AND user_id = ?', [$matches[1], $userId]);
            $this->showLiveAccounts($chatId, $userId);
            return;
        }

        switch ($action) {
            case 'home':
                $this->sendHome($chatId, ['id' => $userId]);
                return;
            case 'live':
                $this->showMinigameGroups($chatId, $userId);
                return;
            case 'link-new':
                $rows = [];
                foreach (array_chunk(self::BRANDS, 3) as $chunk) {
                    $row = [];
                    foreach ($chunk as $brand) {
                        $row[] = ['text' => $brand, 'callback_data' => 'member:select-brand:' . $brand];
                    }
                    $rows[] = $row;
                }
                $this->telegram->sendMessage($chatId, '👉 <b>CHỌN HỆ THỐNG TÀI KHOẢN</b>' . "\nChỉ lưu username. ABCVIP sẽ mở cổng SSO chính thức nếu được cấu hình; không gửi mật khẩu, OTP hoặc mã xác minh.", [
                    'reply_markup' => $this->inlineMenu($rows),
                ]);
                return;
            case 'minigame-products':
                $this->showProducts($chatId, ['minigame'], '🎮 KHO SẢN PHẨM MINI GAME', $userId);
                return;
            case 'otp-products':
                $this->showProducts($chatId, ['otp'], '📱 KHO OTP SMS', $userId);
                return;
            case 'proxy':
            case 'proxy-center':
                $this->showProxyCenter($chatId, $userId);
                return;
            case 'proxy-products':
                $this->showProducts($chatId, ['proxy'], '🌐 KHO PROXY', $userId);
                return;
            case 'proxy-free':
                $this->showFreeProxyProducts($chatId, $userId);
                return;
            case 'proxy-rentals':
                $this->showProxyRentals($chatId, $userId);
                return;
            case 'otp-history':
            case 'otp-rentals':
                $this->showOtpHistory($chatId, $userId);
                return;
            case 'tools':
                $this->showProducts($chatId, ['tools'], '🧰 THUÊ TOOL', $userId);
                return;
            case 'codes':
                $this->showProducts($chatId, ['codes'], '🎁 KHO MÃ CODE', $userId);
                return;
            case 'matchmaking':
                $this->telegram->sendMessage($chatId, '⚔️ <b>HỆ THỐNG GHÉP ĐỐI ĐẤU HENDY</b>' . "\n" .
                    'Giao diện menu đã sẵn sàng. Hệ thống ghép trận, xác nhận kết quả và xếp hạng đang được hoàn thiện; bot chưa đưa người chơi vào hàng chờ để tránh tạo trận chưa có cơ chế xử lý đầy đủ.',
                    ['reply_markup' => $this->inlineMenu([
                        [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']],
                        [['text' => '🎧 LIÊN HỆ CSKH', 'callback_data' => 'member:support']],
                    ])]
                );
                return;
            case 'otp':
                $this->showOtpCenter($chatId, $userId);
                return;
            case 'nyc-transfer':
                $this->setPending($userId, 'nyc_transfer');
                $this->telegram->sendMessage($chatId, '🪙 Nhập số tiền chuyển từ Ví Chính sang Ví NYC (từ 1.000 đến 500.000.000 VNĐ). Đây là chuyển nội bộ, không phải nạp ngân hàng.');
                return;
            case 'otp-catalog':
                $this->showProducts($chatId, ['otp'], '📱 KHO DỊCH VỤ OTP', $userId);
                return;
            case 'viptools':
                $this->showProducts($chatId, ['vip'], '🏆 KHO VIP', $userId);
                return;
            case 'wallet':
                $this->showWalletMenu($chatId, $userId);
                return;
            case 'deposit:custom':
                $this->setPending($userId, 'wallet');
                $this->telegram->sendMessage($chatId, '✏️ Nhập số tiền cần nạp từ 10.000 đến 500.000.000 VNĐ. Ví dụ: <code>50000</code>.');
                return;
            case 'vietsub':
                $this->startVideoRequest($chatId, $userId);
                return;
            case 'history':
                $this->showUserHistory($chatId, $userId);
                return;
            case 'jobs':
                $this->showVideoJobs($chatId, $userId);
                return;
            case 'deposit-history':
                $this->showDepositHistory($chatId, $userId);
                return;
            case 'orders':
                $this->showOrders($chatId, $userId);
                return;
            case 'customer':
                $this->showCustomer($chatId, $userId);
                return;
            case 'linked-accounts':
                $this->showLiveAccounts($chatId, $userId);
                return;
            case 'voucher72':
                $this->startVoucherRequest($chatId, $userId);
                return;
            case 'live-auto-reorder':
                $this->startLiveIssue($chatId, $userId, 'live_auto_reorder');
                return;
            case 'live-no-reward':
                $this->startLiveIssue($chatId, $userId, 'live_win_no_reward');
                return;
            case 'legacy-login':
                $this->sendLegacyLoginLink($chatId, $userId);
                return;
            case 'abcvip':
                $this->showAbcvipConnect($chatId);
                return;
            case 'ref':
                $this->showReferral($chatId, $userId);
                return;
            case 'vip':
                $this->showVip($chatId, $userId);
                return;
            case 'vip-checkin':
                $this->handleVipCheckin($chatId, $userId);
                return;
            case 'vip-info':
                $this->showVipInfo($chatId, $userId);
                return;
            case 'vip-coming-soon':
                $this->telegram->sendMessage(
                    $chatId,
                    '🚧 <b>VIP DỊCH VỤ ĐANG ĐƯỢC TRIỂN KHAI</b>' . "\n" .
                    'Các gói dịch vụ VIP mới đang được hoàn thiện. HENDY sẽ thông báo khi mở bán chính thức.',
                    ['reply_markup' => $this->inlineMenu([
                        [['text' => '👑 TRUNG TÂM VIP', 'callback_data' => 'member:vip']],
                        [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']],
                    ])]
                );
                return;
            case 'group':
            case 'news':
                $this->showCommunityLink($chatId, $action);
                return;
            case 'gift':
                $this->setPending($userId, 'giftcode');
                $this->telegram->sendMessage($chatId, '🎟️ Vui lòng nhập mã Giftcode trong vòng 10 phút.');
                return;
            case 'support-abuse':
                $this->setPending($userId, 'support_abuse', ['stage' => 'order']);
                $this->telegram->sendMessage($chatId, '🆘 <b>HỖ TRỢ DÍNH LẠM DỤNG (60%)</b>' . "\n" . 'Vui lòng nhập mã đơn hàng đã hoàn tất để CSKH kiểm tra. Yêu cầu chỉ được ghi nhận sau khi đối chiếu đơn; không tự động hoàn tiền hoặc cộng voucher.');
                return;
            case 'support':
                $this->setPending($userId, 'support');
                $this->telegram->sendMessage($chatId, '🧑‍💻 Hãy mô tả vấn đề cần hỗ trợ. Bot sẽ tạo ticket và gửi thông tin cho Admin.');
                return;
            case 'miniapp':
                $url = $this->miniAppUrl();
                if ($url === '') {
                    $this->telegram->sendMessage($chatId, 'Mini App chưa được cấu hình APP_URL HTTPS.');
                    return;
                }
                $this->telegram->sendMessage($chatId, '🌈 <b>MINI APP HENDY</b> — Mở giao diện dịch vụ của bot này.', ['reply_markup' => $this->inlineMenu([[['text' => '🚀 MỞ MINI APP', 'web_app' => ['url' => $url]]]])]);
                return;
            case 'admin':
                $this->openAdmin($chatId, $userId);
                return;
            case 'admin-payments':
                $this->showAdminPayments($chatId, $userId);
                return;
            case 'admin-jobs':
                $this->showAdminVideoJobs($chatId, $userId);
                return;
            case 'admin-stats':
                $this->showAdminStats($chatId, $userId);
                return;
            default:
                $this->telegram->sendMessage(
                    $chatId,
                    '🚧 <b>TÍNH NĂNG ĐANG PHÁT TRIỂN</b>' . "\n" .
                    'Mục này chưa được phát hành. HENDY sẽ thông báo khi hoàn thiện và mở sử dụng chính thức.',
                    ['reply_markup' => $this->inlineMenu([
                        [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']],
                        [['text' => '🎧 LIÊN HỆ CSKH', 'callback_data' => 'member:support']],
                    ])]
                );
        }
    }

    private function minigameGroupForBrand(string $brand): ?string
    {
        foreach (self::MINIGAME_GROUPS as $groupKey => $group) {
            if (in_array($brand, $group['brands'], true)) {
                return $groupKey;
            }
        }
        return null;
    }

    private function minigameProductsForBrand(string $brand): array
    {
        $pattern = '%' . strtoupper($brand) . '%';
        return Database::all(
            "SELECT id, name, price, description, stock, duration_days, vip_level FROM products " .
            "WHERE category = 'minigame' AND is_active = TRUE " .
            "AND (stock IS NULL OR stock > 0) " .
            "AND (UPPER(COALESCE(metadata->>'brand', '')) = ? OR UPPER(slug) LIKE ? OR UPPER(name) LIKE ?) " .
            'ORDER BY created_at DESC LIMIT 10',
            [$brand, $pattern, $pattern]
        );
    }

    private function showMinigameGroups(string $chatId, string $userId): void
    {
        $counts = Database::all(
            'SELECT brand, COUNT(*) AS account_count FROM linked_accounts WHERE user_id = ? GROUP BY brand',
            [$userId]
        );
        $countByBrand = [];
        foreach ($counts as $count) {
            $countByBrand[strtoupper((string) $count['brand'])] = (int) $count['account_count'];
        }

        $lines = [
            '🚀 <b>MINIGAME LIVE · CHỌN NHÓM NHÀ CÁI</b>',
            '━━━━━━━━━━━━━━━━━━━━',
            'Chọn nhóm để xem danh sách game. Kho ACC cá nhân được thống kê riêng theo username đã lưu.',
        ];
        $rows = [];
        foreach (self::MINIGAME_GROUPS as $groupKey => $group) {
            $total = 0;
            foreach ($group['brands'] as $brand) {
                $total += $countByBrand[$brand] ?? 0;
            }
            $lines[] = '• ' . self::escape($group['label']) . ' — <b>' . $total . ' ACC</b>';
            $rows[] = [[
                'text' => $group['label'],
                'callback_data' => 'member:live-group:' . $groupKey,
            ]];
        }
        $rows[] = [['text' => '« QUAY LẠI MENU CHÍNH', 'callback_data' => 'member:home']];
        $this->telegram->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->inlineMenu($rows),
        ]);
    }

    private function showMinigameGroupGames(string $chatId, string $userId, string $groupKey): void
    {
        $group = self::MINIGAME_GROUPS[$groupKey] ?? null;
        if ($group === null) {
            throw new RuntimeException('Nhóm nhà cái không tồn tại.');
        }

        $lines = [
            '🎮 <b>' . self::escape($group['label']) . '</b>',
            '━━━━━━━━━━━━━━━━━━━━',
            'Chọn game cụ thể để xem gói dịch vụ, điều kiện Code Live và kho ACC cá nhân.',
        ];
        $rows = [];
        foreach ($group['brands'] as $brand) {
            $count = (int) Database::value(
                'SELECT COUNT(*) FROM linked_accounts WHERE user_id = ? AND brand = ?',
                [$userId, $brand]
            );
            $rows[] = [[
                'text' => '🎮 ' . $brand . ' MINI GAME',
                'callback_data' => 'member:live-game:' . $brand,
            ]];
            $lines[] = '• ' . self::escape($brand) . ': <b>' . $count . ' ACC cá nhân</b>';
        }
        $rows[] = [['text' => '« QUAY LẠI', 'callback_data' => 'member:live']];
        $this->telegram->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->inlineMenu($rows),
        ]);
    }

    private function showMinigameGame(string $chatId, string $userId, string $brand): void
    {
        $groupKey = $this->minigameGroupForBrand($brand);
        if ($groupKey === null) {
            throw new RuntimeException('Game Mini Game không hợp lệ.');
        }

        $count = (int) Database::value(
            'SELECT COUNT(*) FROM linked_accounts WHERE user_id = ? AND brand = ?',
            [$userId, $brand]
        );
        $products = $this->minigameProductsForBrand($brand);
        $lines = [
            '🎮 <b>' . self::escape($brand) . ' MINI GAME</b>',
            '━━━━━━━━━━━━━━━━━━━━',
            '📌 <b>ĐIỀU KIỆN NHẬN CODE LIVE</b>',
            'Đọc điều kiện trong mô tả gói do Admin cấu hình trước khi sử dụng. HENDY chỉ lưu username; không nhập mật khẩu, PIN hoặc OTP.',
            '',
            '📦 <b>KHO ACC CÁ NHÂN</b>: ' . $count . ' tài khoản',
            'Chọn gói dịch vụ hoặc mở kho ACC để quản lý username của bạn.',
        ];
        $rows = [];

        if ($brand === 'RR88') {
            $rows[] = [[
                'text' => '📦 RR88 MINI | 88 ĐIỂM - 9,000đ',
                'callback_data' => 'member:live-package:RR88:0',
            ]];
            $lines[] = '• Gói mặc định: <b>88 điểm · 9.000đ</b> (thông tin hiển thị, chưa tạo giao dịch).';
        }

        foreach ($products as $product) {
            $label = self::truncate(
                '📦 ' . (string) $product['name'] . ' · ' . self::money($product['price']),
                55
            );
            $rows[] = [[
                'text' => $label,
                'callback_data' => 'member:live-package:' . $brand . ':' . (int) $product['id'],
            ]];
            if (trim((string) ($product['description'] ?? '')) !== '') {
                $lines[] = '• ' . self::escape((string) $product['name']) . ': ' .
                    self::escape(self::truncate((string) $product['description'], 160));
            }
        }

        if ($products === [] && $brand !== 'RR88') {
            $lines[] = 'ℹ️ Chưa có gói dịch vụ cho game này trong catalog. Admin cần tạo sản phẩm loại minigame và gắn brand trong metadata/slug/tên sản phẩm.';
        }
        $rows[] = [[
            'text' => '📦 KHO ACC ' . $brand,
            'callback_data' => 'member:live-warehouse:' . $brand,
        ]];
        $rows[] = [['text' => '« QUAY LẠI', 'callback_data' => 'member:live-group:' . $groupKey]];
        $this->telegram->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->inlineMenu($rows),
        ]);
    }

    private function showMinigamePackageManager(string $chatId, string $userId, string $brand, int $productId): void
    {
        if ($this->minigameGroupForBrand($brand) === null) {
            throw new RuntimeException('Nhà cái không hợp lệ.');
        }

        $packageName = 'KHO ACC ' . $brand;
        $packageDescription = 'Quản lý username Mini Game cá nhân.';
        $packagePrice = '';
        if ($productId > 0) {
            $product = null;
            foreach ($this->minigameProductsForBrand($brand) as $candidate) {
                if ((int) $candidate['id'] === $productId) {
                    $product = $candidate;
                    break;
                }
            }
            if ($product === null) {
                throw new RuntimeException('Gói dịch vụ không còn khả dụng hoặc không thuộc game đã chọn.');
            }
            $packageName = (string) $product['name'];
            $packageDescription = trim((string) ($product['description'] ?? ''));
            $packagePrice = self::money($product['price']);
        } elseif ($brand === 'RR88') {
            $packageName = 'RR88 MINI | 88 ĐIỂM';
            $packageDescription = 'Thông tin gói mẫu do bạn cung cấp. Điều kiện nhận Code Live cần được Admin xác nhận trước khi sử dụng.';
            $packagePrice = '9.000đ';
        }

        $user = $this->getUser($userId);
        $accountCount = (int) Database::value(
            'SELECT COUNT(*) FROM linked_accounts WHERE user_id = ? AND brand = ?',
            [$userId, $brand]
        );
        $accounts = Database::all(
            'SELECT id, username, created_at FROM linked_accounts WHERE user_id = ? AND brand = ? ORDER BY created_at DESC LIMIT 50',
            [$userId, $brand]
        );

        $lines = [
            '📦 <b>QUẢN LÝ KHO ACC CÁ NHÂN</b>',
            '━━━━━━━━━━━━━━━━━━━━',
            'Game: <b>' . self::escape($brand) . ' MINI GAME</b>',
            'Gói: <b>' . self::escape($packageName) . '</b>',
        ];
        if ($packagePrice !== '') {
            $lines[] = 'Giá tham khảo: <b>' . self::escape($packagePrice) . '</b>';
        }
        if ($packageDescription !== '') {
            $lines[] = 'Thông tin: ' . self::escape(self::truncate($packageDescription, 220));
        }
        $lines[] = '💰 Số dư Ví Chính: <b>' . self::money($user['balance'] ?? 0) . '</b>';
        $lines[] = '📊 ACC khả dụng trong kho cá nhân: <b>' . $accountCount . '</b>';
        $lines[] = 'Chỉ lưu username thuộc tài khoản của bạn. Mở màn hình này không tự trừ ví hoặc tạo đơn hàng.';
        if ($accounts === []) {
            $lines[] = '';
            $lines[] = 'Kho đang trống. Bấm ➕ THÊM ACC để lưu username đầu tiên.';
        } else {
            $lines[] = '';
            $lines[] = '<b>DANH SÁCH ACC</b>';
            foreach ($accounts as $account) {
                $lines[] = '• <code>' . self::escape((string) $account['username']) . '</code>';
            }
        }

        $rows = [[[
            'text' => '➕ THÊM ACC',
            'callback_data' => 'member:live-add-acc:' . $brand . ':' . $productId,
        ]]];
        foreach (array_chunk($accounts, 2) as $chunk) {
            $row = [];
            foreach ($chunk as $account) {
                $row[] = [
                    'text' => '🗑 XÓA ACC #' . (int) $account['id'],
                    'callback_data' => 'member:live-delete-acc:' . $brand . ':' . (int) $account['id'] . ':' . $productId,
                ];
            }
            $rows[] = $row;
        }
        $rows[] = [['text' => '« QUAY LẠI', 'callback_data' => 'member:live-game:' . $brand]];
        $rows[] = [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']];
        $this->telegram->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->inlineMenu($rows),
        ]);
    }

    private function showLiveAccounts(string $chatId, string $userId): void
    {
        $accounts = Database::all('SELECT id, brand, username, created_at FROM linked_accounts WHERE user_id = ? ORDER BY created_at DESC LIMIT 30', [$userId]);
        $counts = Database::all('SELECT brand, COUNT(*) AS account_count FROM linked_accounts WHERE user_id = ? GROUP BY brand ORDER BY brand', [$userId]);
        $countByBrand = array_fill_keys(self::BRANDS, 0);
        foreach ($counts as $count) {
            $brand = strtoupper((string) $count['brand']);
            if (array_key_exists($brand, $countByBrand)) $countByBrand[$brand] = (int) $count['account_count'];
        }
        $lines = [
            '🚀 <b>MINIGAME LIVE · KHO TÀI KHOẢN</b>',
            '━━━━━━━━━━━━━━━━━━━━',
            'Chọn thương hiệu cần sử dụng hoặc liên kết. HENDY chỉ lưu username gắn với Telegram ID; không lưu mật khẩu/OTP.',
            '',
            '<b>THỐNG KÊ TÀI KHOẢN ĐÃ LIÊN KẾT</b>',
        ];
        foreach (self::BRANDS as $brand) if (($countByBrand[$brand] ?? 0) > 0) $lines[] = '• ' . self::escape($brand) . ': <b>' . $countByBrand[$brand] . '</b>';
        $lines[] = 'Tổng tài khoản: <b>' . count($accounts) . '</b>';
        foreach ($accounts as $account) $lines[] = '• <b>' . self::escape($account['brand']) . '</b> — <code>' . self::escape($account['username']) . '</code>';
        if ($accounts === []) $lines[] = 'Bạn chưa liên kết tài khoản Mini Game nào.';

        $rows = [];
        foreach (array_chunk(self::BRANDS, 3) as $chunk) {
            $row = [];
            foreach ($chunk as $brand) $row[] = ['text' => $brand, 'callback_data' => 'member:select-brand:' . $brand];
            $rows[] = $row;
        }
        $rows[] = [
            ['text' => '⭐ AUTO ĐẶT LẠI', 'callback_data' => 'member:live-auto-reorder'],
            ['text' => '🔥 TRÚNG KHÔNG PHÁT', 'callback_data' => 'member:live-no-reward'],
        ];
        foreach (array_chunk($accounts, 2) as $chunk) {
            $row = [];
            foreach ($chunk as $account) $row[] = ['text' => 'Xóa ' . $account['brand'] . ' #' . $account['id'], 'callback_data' => 'member:link-delete:' . $account['id']];
            $rows[] = $row;
        }
        $rows[] = [['text' => '＋ LIÊN KẾT TÀI KHOẢN', 'callback_data' => 'member:link-new']];
        $rows[] = [['text' => '🏠 TRỞ VỀ MENU CHÍNH', 'callback_data' => 'member:home']];
        $this->telegram->sendMessage($chatId, implode("\n", $lines), ['reply_markup' => $this->inlineMenu($rows)]);
    }

    private function showProducts(string $chatId, array $categories, string $title, ?string $userId = null): void
    {
        $placeholders = implode(',', array_fill(0, count($categories), '?'));
        $memberVip = $userId !== null ? (int) (Database::value('SELECT vip_level FROM users WHERE user_id = ?', [$userId]) ?? 0) : 0;
        $products = Database::all(
            "SELECT id, name, price, description, stock, duration_days, vip_level FROM products " .
            "WHERE category IN ($placeholders) AND is_active = TRUE AND (stock IS NULL OR stock > 0) " .
            'ORDER BY created_at DESC LIMIT 30',
            $categories
        );
        $lines = [$title, '━━━━━━━━━━━━━━━━━━━━', 'Danh mục và giá được lấy từ kho sản phẩm PostgreSQL:'];
        $rows = [];
        foreach ($products as $product) {
            if ((int) ($product['vip_level'] ?? 0) > $memberVip) continue;
            $stock = $product['stock'] === null ? 'Còn hàng' : 'Còn ' . number_format((int) $product['stock'], 0, ',', '.') . ' sản phẩm';
            $rows[] = [['text' => self::truncate($product['name'] . ' · ' . self::money($product['price']) . ' · ' . $stock, 55), 'callback_data' => 'member:order:' . $product['id']]];
            $lines[] = '• <b>' . self::escape($product['name']) . '</b> — ' . self::money($product['price']);
            $lines[] = '  Kho: <b>' . self::escape($stock) . '</b>' .
                ((int) ($product['duration_days'] ?? 0) > 0 ? ' · Thời hạn: ' . (int) $product['duration_days'] . ' ngày' : '') .
                ((int) ($product['vip_level'] ?? 0) > 0 ? ' · Yêu cầu VIP ' . (int) $product['vip_level'] : '');
            if (trim((string) $product['description']) !== '') $lines[] = '  ' . self::escape(self::truncate((string) $product['description'], 140));
        }
        if ($rows === []) {
            $lines[] = '';
            $lines[] = '🚧 <b>TÍNH NĂNG ĐANG PHÁT TRIỂN / CHƯA MỞ BÁN</b>';
            $lines[] = 'Danh mục hiện chưa có sản phẩm khả dụng cho tài khoản này. HENDY sẽ thông báo khi dịch vụ được mở.';
        }
        $rows[] = [['text' => '🧾 ĐƠN HÀNG CỦA TÔI', 'callback_data' => 'member:orders']];
        $rows[] = [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']];
        $this->telegram->sendMessage($chatId, implode("\n", $lines), ['reply_markup' => $this->inlineMenu($rows)]);
    }

    private function createOrder(string $chatId, string $userId, string $productId): void
    {
        $order = Database::transaction(function () use ($userId, $productId): array {
            $product = Database::one('SELECT * FROM products WHERE id = ? AND is_active = TRUE FOR UPDATE', [$productId]);
            if ($product === null) throw new RuntimeException('Sản phẩm không còn được bán.');
            if ($product['stock'] !== null && (int) $product['stock'] <= 0) throw new RuntimeException('Sản phẩm đã hết hàng.');

            $user = Database::one('SELECT balance, nyc_wallet, vip_level FROM users WHERE user_id = ? FOR UPDATE', [$userId]);
            if ($user === null) throw new RuntimeException('Không tìm thấy hồ sơ thành viên. Hãy gửi /start trước.');
            if ((int) ($product['vip_level'] ?? 0) > (int) ($user['vip_level'] ?? 0)) {
                throw new RuntimeException('Sản phẩm yêu cầu VIP ' . (int) $product['vip_level'] . '. Hãy nâng cấp hạng thành viên trước.');
            }

            $walletType = in_array((string) $product['category'], ['otp', 'proxy'], true) ? 'NYC' : 'MAIN';
            $walletColumn = $walletType === 'NYC' ? 'nyc_wallet' : 'balance';
            $available = (int) ($user[$walletColumn] ?? 0);
            $price = (int) $product['price'];
            if ($available < $price) {
                throw new RuntimeException('Số dư ví ' . $walletType . ' không đủ. Cần ' . self::money($price) . '.');
            }

            $orderCode = 'HD' . strtoupper(base_convert((string) time(), 10, 36)) . strtoupper(bin2hex(random_bytes(3)));
            Database::run("UPDATE users SET {$walletColumn} = {$walletColumn} - ? WHERE user_id = ?", [$price, $userId]);
            $created = Database::one(
                "INSERT INTO orders (order_code, user_id, product_id, product_name, category, amount, status) " .
                "VALUES (?, ?, ?, ?, ?, ?, 'pending') RETURNING order_code, product_name, amount, status",
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
            if ($product['stock'] !== null) {
                Database::run('UPDATE products SET stock = stock - 1, updated_at = NOW() WHERE id = ?', [$product['id']]);
            }
            $remaining = (int) Database::value("SELECT {$walletColumn} FROM users WHERE user_id = ?", [$userId]);
            return [...($created ?? []), 'balance' => $remaining, 'wallet_type' => $walletType];
        });

        $this->telegram->sendMessage($chatId, implode("\n", [
            '✅ <b>ĐÃ TẠO ĐƠN HÀNG</b>',
            'Sản phẩm: <b>' . self::escape((string) $order['product_name']) . '</b>',
            'Mã đơn: <code>' . self::escape((string) $order['order_code']) . '</code>',
            'Số tiền: ' . self::money($order['amount']) . ' · Ví ' . self::escape((string) $order['wallet_type']),
            'Trạng thái: Chờ Admin xử lý.',
            'Số dư ví còn lại: ' . self::money($order['balance']),
        ]), [
            'reply_markup' => $this->inlineMenu([
                [['text' => '🧾 LỊCH SỬ ĐƠN HÀNG', 'callback_data' => 'member:orders']],
                [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']],
            ]),
        ]);
    }

    private function showOrders(string $chatId, string $userId): void
    {
        $orders = Database::all(
            'SELECT order_code, product_name, amount, status, delivery_text, created_at FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 15',
            [$userId]
        );
        $lines = ['🧾 <b>ĐƠN HÀNG CỦA TÔI</b>', '━━━━━━━━━━━━━━━━━━━━'];
        foreach ($orders as $order) {
            $lines[] = '• <b>' . self::escape($order['order_code']) . '</b> — ' . self::escape($order['product_name']);
            $lines[] = '  ' . self::money($order['amount']) . ' · ' . self::statusLabel($order['status']);
            if ($order['status'] === 'completed' && trim((string) $order['delivery_text']) !== '') {
                $lines[] = '  <b>Bàn giao:</b> ' . self::escape(self::truncate((string) $order['delivery_text'], 300));
            }
        }
        if ($orders === []) {
            $lines[] = 'Bạn chưa có đơn hàng.';
        }
        $this->telegram->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->inlineMenu([[['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']]]),
        ]);
    }

    private function showCustomer(string $chatId, string $userId): void
    {
        $user = $this->getUser($userId);
        $stats = Database::one(
            "SELECT COUNT(*) AS total_orders,
                    COUNT(*) FILTER (WHERE status = 'processing') AS processing_orders,
                    COUNT(*) FILTER (WHERE status = 'completed') AS completed_orders,
                    COUNT(*) FILTER (WHERE status = 'cancelled') AS cancelled_orders
             FROM orders WHERE user_id = ?",
            [$userId]
        ) ?? [];
        $ticketCount = (int) Database::value('SELECT COUNT(*) FROM support_tickets WHERE user_id = ?', [$userId]);
        $linkedCount = (int) Database::value('SELECT COUNT(*) FROM linked_accounts WHERE user_id = ?', [$userId]);

        $miniAppUrl = $this->miniAppUrl();
        $rows = [];
        if ($miniAppUrl !== '') {
            $rows[] = [[
                'text' => '📱 MỞ MINI APP HENDY',
                'web_app' => ['url' => $miniAppUrl],
            ]];
        }
        $rows[] = [['text' => '↩️ TRỞ VỀ MENU CHÍNH', 'callback_data' => 'member:home']];

        $lines = [
            '💠 <b>TRUNG TÂM KHÁCH HÀNG HENDY</b>',
            '',
            'Mở Mini App để quản lý hồ sơ, ví tiền, đơn hàng và yêu cầu hỗ trợ ngay trong Telegram.',
            '',
            '━━━━━━━━━━━━━━━━━━━━',
            '👤 Thành viên: <b>' . self::escape($user['name'] ?: 'Thành viên') . '</b>',
            '🆔 Telegram ID: <code>' . self::escape($userId) . '</code>',
            '💰 Ví chính: <b>' . self::money($user['balance'] ?? 0) . '</b>',
            '🪙 Ví NYC: <b>' . self::money($user['nyc_wallet'] ?? 0) . '</b>',
            '🎟 Ví Voucher: <b>' . self::money($user['voucher_wallet'] ?? 0) . '</b>',
            '💳 Tổng nạp: <b>' . self::money($user['total_deposited'] ?? 0) . '</b>',
            '👑 Trạng thái: <b>' . ((int) ($user['vip_level'] ?? 0) > 0 ? 'VIP ' . (int) $user['vip_level'] : 'Bình thường') . '</b>',
            '🔗 Tài khoản Mini Game đã liên kết: <b>' . $linkedCount . '</b>',
            '🧾 Đơn hàng: <b>' . (int) ($stats['total_orders'] ?? 0) . '</b> · Đang xử lý: <b>' . (int) ($stats['processing_orders'] ?? 0) . '</b>',
            '✅ Hoàn tất: <b>' . (int) ($stats['completed_orders'] ?? 0) . '</b> · Đã hủy: <b>' . (int) ($stats['cancelled_orders'] ?? 0) . '</b>',
            '🎧 Ticket CSKH: <b>' . $ticketCount . '</b>',
        ];
        if ($miniAppUrl === '') {
            $lines[] = '';
            $lines[] = '⚠️ Mini App chưa khả dụng: cần cấu hình APP_URL HTTPS hoặc RENDER_EXTERNAL_URL.';
        }
        $this->telegram->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->inlineMenu($rows),
            'disable_web_page_preview' => true,
        ]);
    }

    private function createWebsiteLoginUrl(string $userId, string $scope = 'customer'): string
    {
        if (!in_array($scope, ['customer', 'admin'], true)) {
            throw new RuntimeException('Phạm vi đăng nhập website không hợp lệ.');
        }
        if ($scope === 'admin' && !TelegramAuth::isAdmin($userId)) {
            throw new RuntimeException('Chỉ Admin được phép tạo phiên đăng nhập Dashboard.');
        }
        $baseUrl = rtrim((string) (Env::get('APP_URL') ?: Env::get('RENDER_EXTERNAL_URL', '')), '/');
        if (!self::isHttpsUrl($baseUrl)) {
            throw new RuntimeException('APP_URL HTTPS chưa được cấu hình; chưa thể tạo link đăng nhập an toàn.');
        }
        $ticket = bin2hex(random_bytes(32));
        Database::run(
            'DELETE FROM legacy_login_tickets WHERE user_id = ? AND bot_key = ? AND (consumed_at IS NOT NULL OR expires_at <= NOW())',
            [$userId, $this->botKey]
        );
        Database::run(
            "INSERT INTO legacy_login_tickets (token_hash, user_id, bot_key, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL '5 minutes')",
            [hash('sha256', $ticket), $userId, $this->botKey]
        );
        return $baseUrl . '/legacy-login?scope=' . rawurlencode($scope) . '&ticket=' . rawurlencode($ticket);
    }

    private function sendLegacyLoginLink(string $chatId, string $userId): void
    {
        $url = $this->createWebsiteLoginUrl($userId);
        $this->telegram->sendMessage($chatId, implode("\n", [
            '🔑 <b>ĐĂNG NHẬP WEBSITE HENDY</b>',
            'Link chỉ sử dụng được <b>một lần</b> và hết hạn sau <b>5 phút</b>.',
            'Sau khi xác minh, website sẽ mở thẳng Trung tâm khách hàng của bạn.',
            'Không chuyển tiếp liên kết này cho người khác.',
            '',
            '<a href="' . self::escape($url) . '">👉 MỞ TRUNG TÂM KHÁCH HÀNG</a>',
        ]), [
            'reply_markup' => $this->inlineMenu([
                [['text' => '👤 MỞ TRUNG TÂM KHÁCH HÀNG HENDY', 'url' => $url]],
                [['text' => '↩️ QUAY LẠI', 'callback_data' => 'member:customer']],
            ]),
            'disable_web_page_preview' => true,
        ]);
    }

    private function showAbcvipConnect(string $chatId): void
    {
        $settings = $this->settings(['ABCVIP_CONNECT_URL']);
        $connectUrl = trim((string) ($settings['ABCVIP_CONNECT_URL'] ?? ''));
        $rows = [];
        if (self::isHttpsUrl($connectUrl)) {
            $rows[] = [['text' => '🔐 ĐĂNG NHẬP ABCVIP ĐỂ LIÊN KẾT', 'url' => $connectUrl]];
        }
        $rows[] = [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']];
        $message = [
            '🏦 <b>KHO TÀI KHOẢN ABCVIP</b>',
            '━━━━━━━━━━━━━━━━━━━━',
            'Liên kết tài khoản qua trang đăng nhập chính thức của nhà cung cấp.',
            'HENDY không yêu cầu bạn gửi mật khẩu, mã OTP hoặc PIN trong cuộc trò chuyện Telegram.',
        ];
        if (!self::isHttpsUrl($connectUrl)) {
            $message[] = '';
            $message[] = '⏳ Cổng đăng nhập ABCVIP chưa được cấu hình. Admin cần cung cấp URL OAuth/SSO chính thức và địa chỉ callback được nhà cung cấp hỗ trợ.';
            $message[] = 'Hiện tại chức năng liên kết ABCVIP đang tạm khóa; không nhập thông tin đăng nhập vào bot.';
        }
        $this->telegram->sendMessage($chatId, implode("\n", $message), [
            'reply_markup' => $this->inlineMenu($rows),
            'disable_web_page_preview' => true,
        ]);
    }

    private function startLiveIssue(string $chatId, string $userId, string $issueType): void
    {
        $title = $issueType === 'live_auto_reorder' ? 'AUTO ĐẶT LẠI' : 'TRÚNG KHÔNG PHÁT';
        $this->setPending($userId, 'live_order_issue', ['ticket_type' => $issueType]);
        $this->telegram->sendMessage($chatId, '🆘 <b>YÊU CẦU ' . self::escape($title) . '</b>' . "\n" .
            'Gửi mã đơn Mini Game để CSKH kiểm tra. Yêu cầu sẽ được Admin xem xét; bot không tự tạo đơn mới hoặc tự cộng tiền.');
    }

    private function startVoucherRequest(string $chatId, string $userId): void
    {
        $orders = VoucherService::eligibleOrders($userId, 20);
        if ($orders === []) {
            $this->telegram->sendMessage($chatId, '🎟️ Hiện chưa có đơn nào đủ điều kiện Voucher 72H. Điều kiện: đơn đang chờ/đang xử lý và đã quá 72 giờ.');
            return;
        }
        $lines = ['🎟️ <b>YÊU CẦU VOUCHER 72H</b>', 'Gửi mã đơn đủ điều kiện trong danh sách dưới đây:', ''];
        foreach ($orders as $order) $lines[] = '• <code>' . self::escape($order['order_code']) . '</code> · ' . self::escape($order['product_name']) . ' · ' . self::money($order['amount']);
        $this->setPending($userId, 'voucher_order');
        $this->telegram->sendMessage($chatId, implode("\n", $lines) . "\n\nYêu cầu cần Admin duyệt; chỉ cộng Ví Voucher sau khi được chấp thuận.");
    }

    private function showOtpCenter(string $chatId, string $userId): void
    {
        $nycBalance = (int) (Database::value('SELECT nyc_wallet FROM users WHERE user_id = ?', [$userId]) ?? 0);
        $message = "⚙️ <b>OTP & PROXY CENTER</b>\nSố dư Ví NYC: <b>" . self::money($nycBalance) . "</b>\nChọn dịch vụ. Sản phẩm/giá lấy từ kho PostgreSQL; kết nối nhà cung cấp OTP cần cấu hình riêng.";
        $this->telegram->sendMessage($chatId, $message, [
            'reply_markup' => $this->inlineMenu([
                [['text' => '🪙 NẠP VÍ NYC TỪ VÍ CHÍNH', 'callback_data' => 'member:nyc-transfer']],
                [['text' => '📱 THUÊ OTP SMS', 'callback_data' => 'member:otp-catalog']],
                [['text' => '🌐 PROXY CENTER', 'callback_data' => 'member:proxy-center']],
                [['text' => '📜 LỊCH SỬ OTP', 'callback_data' => 'member:otp-history']],
                [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']],
            ]),
        ]);
    }

    private function showProxyCenter(string $chatId, string $userId): void
    {
        $stats = Database::one(
            "SELECT COUNT(*) FILTER (WHERE o.status = 'completed' AND (COALESCE(p.duration_days, 0) = 0 OR o.created_at + (COALESCE(p.duration_days, 0) * INTERVAL '1 day') > NOW())) AS active_count,
                    COUNT(*) FILTER (WHERE o.status = 'completed' AND p.duration_days > 0 AND o.created_at + (p.duration_days * INTERVAL '1 day') <= NOW()) AS expired_count,
                    COUNT(*) AS total_count
             FROM orders o LEFT JOIN products p ON p.id = o.product_id
             WHERE o.user_id = ? AND o.category = 'proxy'",
            [$userId]
        ) ?? [];
        $user = $this->getUser($userId);
        $this->telegram->sendMessage($chatId, implode("\n", [
            '🌐 <b>PROXY CENTER HENDY</b>',
            '━━━━━━━━━━━━━━━━━━━━',
            '👤 Thành viên: <b>' . self::escape($user['name'] ?: 'Thành viên') . '</b>',
            '🪙 Ví NYC: <b>' . self::money($user['nyc_wallet'] ?? 0) . '</b>',
            '📦 Tổng đơn Proxy: <b>' . (int) ($stats['total_count'] ?? 0) . '</b>',
            '🟢 Proxy còn hạn/không kỳ hạn: <b>' . (int) ($stats['active_count'] ?? 0) . '</b>',
            '🔴 Proxy hết hạn: <b>' . (int) ($stats['expired_count'] ?? 0) . '</b>',
            '',
            'Proxy Free chỉ hiển thị khi kho có sản phẩm miễn phí và tài khoản đạt cấp VIP yêu cầu.',
        ]), [
            'reply_markup' => $this->inlineMenu([
                [['text' => '🎁 PROXY FREE (VIP 1+)', 'callback_data' => 'member:proxy-free']],
                [['text' => '🔄 THUÊ PROXY XOAY / TĨNH', 'callback_data' => 'member:proxy-products']],
                [['text' => '📦 KHO PROXY ĐÃ THUÊ', 'callback_data' => 'member:proxy-rentals']],
                [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']],
            ]),
        ]);
    }

    private function showFreeProxyProducts(string $chatId, string $userId): void
    {
        $vip = (int) ($this->getUser($userId)['vip_level'] ?? 0);
        if ($vip < 1) {
            $this->telegram->sendMessage($chatId, '🔒 Proxy Free yêu cầu từ VIP 1. Hãy xem Trung tâm VIP để biết mốc nâng hạng.');
            return;
        }
        $products = Database::all(
            "SELECT id, name, description, stock, duration_days, vip_level FROM products
             WHERE category = 'proxy' AND price = 0 AND vip_level BETWEEN 1 AND ?
               AND is_active = TRUE AND (stock IS NULL OR stock > 0)
             ORDER BY created_at DESC LIMIT 20",
            [$vip]
        );
        $rows = [];
        $lines = ['🎁 <b>KHO PROXY FREE · VIP 1+</b>', '━━━━━━━━━━━━━━━━━━━━'];
        foreach ($products as $product) {
            $rows[] = [['text' => self::truncate($product['name'] . ' · NHẬN MIỄN PHÍ', 55), 'callback_data' => 'member:order:' . $product['id']]];
            $lines[] = '• <b>' . self::escape($product['name']) . '</b> · VIP ' . (int) $product['vip_level'];
            if (trim((string) $product['description']) !== '') $lines[] = '  ' . self::escape($product['description']);
        }
        if ($products === []) $lines[] = 'Kho chưa có sản phẩm Proxy Free đang mở bán. Vui lòng quay lại sau.';
        $rows[] = [['text' => '🔙 PROXY CENTER', 'callback_data' => 'member:proxy-center']];
        $this->telegram->sendMessage($chatId, implode("\n", $lines), ['reply_markup' => $this->inlineMenu($rows)]);
    }

    private function showProxyRentals(string $chatId, string $userId): void
    {
        $orders = Database::all(
            "SELECT o.order_code, o.product_name, o.amount, o.status, o.delivery_text, o.created_at,
                    COALESCE(p.duration_days, 0) AS duration_days
             FROM orders o LEFT JOIN products p ON p.id = o.product_id
             WHERE o.user_id = ? AND o.category = 'proxy' ORDER BY o.created_at DESC LIMIT 20",
            [$userId]
        );
        $lines = ['📦 <b>KHO PROXY ĐÃ THUÊ</b>', '━━━━━━━━━━━━━━━━━━━━'];
        foreach ($orders as $order) {
            $lines[] = '• <b>' . self::escape($order['product_name']) . '</b> · <code>' . self::escape($order['order_code']) . '</code>';
            $lines[] = '  ' . self::money($order['amount']) . ' · ' . self::escape(self::statusLabel((string) $order['status']));
            if ((int) $order['duration_days'] > 0) {
                $expires = (new \DateTimeImmutable((string) $order['created_at']))->modify('+' . (int) $order['duration_days'] . ' days');
                $lines[] = '  Hạn dự kiến: ' . $expires->setTimezone(new \DateTimeZone('Asia/Ho_Chi_Minh'))->format('d/m/Y H:i');
            }
            if ($order['status'] === 'completed' && trim((string) $order['delivery_text']) !== '') {
                $lines[] = '  Thông tin bàn giao: <code>' . self::escape(self::truncate((string) $order['delivery_text'], 240)) . '</code>';
            }
        }
        if ($orders === []) $lines[] = 'Bạn chưa có đơn Proxy.';
        $this->telegram->sendMessage($chatId, implode("\n", $lines), ['reply_markup' => $this->inlineMenu([
            [['text' => '🌐 THUÊ THÊM PROXY', 'callback_data' => 'member:proxy-products']],
            [['text' => '🔙 PROXY CENTER', 'callback_data' => 'member:proxy-center']],
        ])]);
    }

    private function showOtpHistory(string $chatId, string $userId): void
    {
        $orders = Database::all(
            "SELECT order_code, product_name, amount, status, created_at FROM orders
             WHERE user_id = ? AND category = 'otp' ORDER BY created_at DESC LIMIT 20",
            [$userId]
        );
        $lines = ['📱 <b>LỊCH SỬ DỊCH VỤ OTP</b>', '━━━━━━━━━━━━━━━━━━━━'];
        foreach ($orders as $order) {
            $lines[] = '• <code>' . self::escape($order['order_code']) . '</code> · ' . self::escape($order['product_name']);
            $lines[] = '  ' . self::money($order['amount']) . ' · ' . self::escape(self::statusLabel((string) $order['status']));
        }
        if ($orders === []) $lines[] = 'Bạn chưa có yêu cầu OTP.';
        $this->telegram->sendMessage($chatId, implode("\n", $lines), ['reply_markup' => $this->inlineMenu([
            [['text' => '📱 MỞ KHO OTP', 'callback_data' => 'member:otp-catalog']],
            [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']],
        ])]);
    }

    private function showBalance(string $chatId, string $userId): void
    {
        $user = $this->getUser($userId);
        $this->telegram->sendMessage($chatId, '💰 <b>SỐ DƯ VÍ HENDY</b>' . "\n" .
            'Ví Chính: <b>' . self::money($user['balance'] ?? 0) . '</b>' . "\n" .
            'Ví NYC: <b>' . self::money($user['nyc_wallet'] ?? 0) . '</b>' . "\n" .
            'Ví Voucher: <b>' . self::money($user['voucher_wallet'] ?? 0) . '</b>', [
            'reply_markup' => $this->inlineMenu([
                [['text' => '＋ NẠP TIỀN', 'callback_data' => 'member:wallet']],
                [['text' => '📜 LỊCH SỬ GIAO DỊCH', 'callback_data' => 'member:deposit-history']],
                [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']],
            ]),
        ]);
    }

    private function showWalletMenu(string $chatId, string $userId): void
    {
        $user = $this->getUser($userId);
        $this->telegram->sendMessage($chatId, '💳 <b>NẠP TIỀN VÀO VÍ CHÍNH</b>' . "\n" .
            'Số dư hiện tại: <b>' . self::money($user['balance'] ?? 0) . '</b>' . "\n" .
            'Chọn nhanh số tiền hoặc nhập số tiền khác. Admin sẽ đối soát sao kê ngân hàng trước khi cộng ví.', [
            'reply_markup' => $this->inlineMenu([
                [
                    ['text' => '10.000đ', 'callback_data' => 'member:deposit:10000'],
                    ['text' => '20.000đ', 'callback_data' => 'member:deposit:20000'],
                ],
                [
                    ['text' => '50.000đ', 'callback_data' => 'member:deposit:50000'],
                    ['text' => '100.000đ', 'callback_data' => 'member:deposit:100000'],
                ],
                [['text' => '✏️ NHẬP SỐ TIỀN KHÁC', 'callback_data' => 'member:deposit:custom']],
                [['text' => '📜 LỊCH SỬ NẠP', 'callback_data' => 'member:deposit-history']],
                [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']],
            ]),
        ]);
    }

    private function startVideoRequest(string $chatId, string $userId): void
    {
        $this->setPending($userId, 'video_url');
        $this->telegram->sendMessage($chatId,
            '🎬 <b>YÊU CẦU VIETSUB</b>' . "\n" .
            'Gửi link video HTTPS từ nền tảng đã được cho phép (mặc định: YouTube, TikTok, Facebook).' . "\n" .
            'Hệ thống sẽ tạo job và đưa vào hàng chờ. Việc xử lý thực tế cần cấu hình VIDEO_PROCESSOR_URL và VIDEO_PROCESSOR_SECRET.', [
            'reply_markup' => $this->inlineMenu([[['text' => 'HỦY / MENU CHÍNH', 'callback_data' => 'member:home']]]),
        ]);
    }

    private function showUserHistory(string $chatId, string $userId): void
    {
        $ledger = Database::all(
            'SELECT amount, transaction_type, reference_code, description, created_at FROM wallet_ledger WHERE user_id = ? ORDER BY created_at DESC LIMIT 5',
            [$userId]
        );
        $payments = PaymentService::forUser($userId, 5);
        $jobs = VideoJobService::forUser($userId, 5);

        $lines = ['📜 <b>LỊCH SỬ HOẠT ĐỘNG</b>', '━━━━━━━━━━━━━━━━━━━━', '<b>💰 Giao dịch ví</b>'];
        if ($ledger === []) {
            $lines[] = '• Chưa có biến động số dư.';
        } else {
            foreach ($ledger as $item) {
                $sign = (int) $item['amount'] >= 0 ? '+' : '';
                $lines[] = '• ' . $sign . self::money($item['amount']) . ' · ' . self::escape((string) $item['transaction_type']);
            }
        }

        $lines[] = '';
        $lines[] = '<b>💳 Yêu cầu nạp gần đây</b>';
        if ($payments === []) {
            $lines[] = '• Chưa tạo yêu cầu nạp.';
        } else {
            foreach ($payments as $payment) {
                $lines[] = '• <code>' . self::escape((string) $payment['payment_id']) . '</code> · ' .
                    self::money($payment['amount']) . ' · ' . self::escape(self::statusLabel((string) $payment['status']));
            }
        }

        $lines[] = '';
        $lines[] = '<b>🎬 Video jobs gần đây</b>';
        if ($jobs === []) {
            $lines[] = '• Chưa có yêu cầu Vietsub.';
        } else {
            foreach ($jobs as $job) {
                $lines[] = '• <code>' . self::escape(substr((string) $job['id'], 0, 8)) . '</code> · ' .
                    self::escape(self::statusLabel((string) $job['status'])) . ' (' . (int) $job['progress'] . '%)';
            }
        }

        $this->telegram->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->inlineMenu([
                [['text' => '🎬 VIDEO JOBS', 'callback_data' => 'member:jobs']],
                [['text' => '💳 LỊCH SỬ NẠP', 'callback_data' => 'member:deposit-history']],
                [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']],
            ]),
        ]);
    }

    private function showVideoJobs(string $chatId, string $userId): void
    {
        $jobs = VideoJobService::forUser($userId, 10);
        $lines = ['🎬 <b>VIDEO JOBS CỦA BẠN</b>', '━━━━━━━━━━━━━━━━━━━━'];
        foreach ($jobs as $job) {
            $lines[] = '• <code>' . self::escape(substr((string) $job['id'], 0, 8)) . '</code> · ' .
                self::escape(self::statusLabel((string) $job['status'])) . ' · ' . (int) $job['progress'] . '%';
            $lines[] = '  Nguồn: ' . self::escape(self::truncate((string) $job['source_url'], 100));
            if ($job['status'] === 'completed' && $job['output_url'] !== '') {
                $lines[] = '  Kết quả: ' . self::escape((string) $job['output_url']);
            }
            if ($job['status'] === 'failed' && $job['error_message'] !== '') {
                $lines[] = '  Lỗi: ' . self::escape(self::truncate((string) $job['error_message'], 160));
            }
        }
        if ($jobs === []) {
            $lines[] = 'Chưa có video job nào. Bấm DỊCH VỤ VIETSUB để gửi link video.';
        }
        $this->telegram->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->inlineMenu([
                [['text' => '🎬 TẠO YÊU CẦU VIETSUB', 'callback_data' => 'member:vietsub']],
                [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']],
            ]),
        ]);
    }

    private function notifyUserOnBot(string $botKey, string $userId, string $message): void
    {
        $token = TelegramAuth::token($botKey);
        if ($token === '') {
            return;
        }
        try {
            (new TelegramApi($token))->sendMessage($userId, $message);
        } catch (Throwable $error) {
            error_log('Không gửi được thông báo user qua bot ' . $botKey . ': ' . $error->getMessage());
        }
    }

    private function notifyAdmins(string $message, array $replyMarkup = []): void
    {
        $botOrder = [$this->botKey, $this->botKey === 'primary' ? 'secondary' : 'primary'];
        $options = $replyMarkup !== [] ? ['reply_markup' => $replyMarkup] : [];

        foreach (TelegramAuth::adminIds() as $adminId) {
            $sent = false;
            foreach ($botOrder as $botKey) {
                $token = TelegramAuth::token($botKey);
                if ($token === '') {
                    continue;
                }
                try {
                    (new TelegramApi($token))->sendMessage($adminId, $message, $options);
                    $sent = true;
                    break;
                } catch (Throwable $error) {
                    // An admin may have started only the other bot. Try it once
                    // before leaving the notification for the admin dashboard.
                    error_log('Không gửi được thông báo admin qua ' . $botKey . ': ' . $error->getMessage());
                }
            }
            if (!$sent) {
                error_log('Không gửi được thông báo admin ' . $adminId . ' qua bất kỳ bot Telegram nào.');
            }
        }
    }

    private function showAdminStats(string $chatId, string $userId): void
    {
        if (!TelegramAuth::isAdmin($userId)) {
            $this->telegram->sendMessage($chatId, '⛔ Bạn không có quyền quản trị.');
            return;
        }
        $payments = PaymentService::stats();
        $jobs = VideoJobService::stats();
        $users = (int) Database::value('SELECT COUNT(*) FROM users');
        $orders = (int) Database::value("SELECT COUNT(*) FROM orders WHERE status IN ('pending','processing')");
        $tickets = (int) Database::value("SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','processing')");
        $adminDashboardUrl = '';
        try {
            $adminDashboardUrl = $this->createWebsiteLoginUrl($userId, 'admin');
        } catch (Throwable $error) {
            error_log('[admin-login] Không tạo được link Dashboard từ thống kê: ' . $error->getMessage());
        }
        $this->telegram->sendMessage($chatId, implode("\n", [
            '🛡️ <b>HENDY BOT — TỔNG QUAN QUẢN TRỊ</b>',
            '👥 Thành viên: <b>' . $users . '</b>',
            '🧾 Đơn hàng cần xử lý: <b>' . $orders . '</b>',
            '🎧 Ticket CSKH mở: <b>' . $tickets . '</b>',
            '💳 Yêu cầu nạp chờ duyệt: <b>' . $payments['pending'] . '</b>',
            '🎬 Video jobs: <b>' . $jobs['queued'] . '</b> chờ · <b>' . $jobs['processing'] . '</b> xử lý · <b>' . $jobs['failed'] . '</b> lỗi',
        ]), [
            'reply_markup' => $this->inlineMenu([
                [['text' => '💳 DUYỆT NẠP TIỀN', 'callback_data' => 'member:admin-payments']],
                [['text' => '🎬 VIDEO JOBS', 'callback_data' => 'member:admin-jobs']],
                ...($adminDashboardUrl !== '' ? [[['text' => '🖥️ MỞ DASHBOARD ADMIN', 'url' => $adminDashboardUrl]]] : []),
                [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']],
            ]),
        ]);
    }

    private function showAdminPayments(string $chatId, string $userId): void
    {
        if (!TelegramAuth::isAdmin($userId)) {
            $this->telegram->sendMessage($chatId, '⛔ Bạn không có quyền duyệt nạp tiền.');
            return;
        }
        $pending = PaymentService::pending(15);
        if ($pending === []) {
            $this->telegram->sendMessage($chatId, '✅ Không có yêu cầu nạp tiền đang chờ.', [
                'reply_markup' => $this->inlineMenu([[['text' => '🔙 QUẢN TRỊ', 'callback_data' => 'member:admin-stats']]]),
            ]);
            return;
        }
        $this->telegram->sendMessage($chatId, '💳 <b>CÓ ' . count($pending) . ' YÊU CẦU NẠP CHỜ DUYỆT</b>' . "\n" .
            'Chỉ bấm Duyệt sau khi đã đối soát giao dịch trên sao kê ngân hàng.');
        foreach ($pending as $payment) {
            $name = trim((string) ($payment['name'] ?? '')) ?: (string) ($payment['username'] ?? 'Thành viên');
            $text = implode("\n", [
                '💳 <b>YÊU CẦU ' . self::escape((string) $payment['payment_id']) . '</b>',
                'Thành viên: <b>' . self::escape($name) . '</b>',
                'Bot nguồn: <b>' . self::escape(strtoupper((string) ($payment['bot_key'] ?? 'primary'))) . '</b>',
                'Telegram ID: <code>' . self::escape((string) $payment['user_id']) . '</code>',
                'Số tiền: <b>' . self::money($payment['amount']) . '</b>',
                'Nội dung chuyển khoản: <code>' . self::escape((string) $payment['transfer_content']) . '</code>',
                'Tài khoản nhận: <b>' . self::escape((string) $payment['bank_id']) . '</b> · <code>' . self::escape((string) $payment['account_number']) . '</code>',
                'Chủ tài khoản: ' . self::escape((string) $payment['account_holder']),
                'Tạo lúc: ' . self::escape((string) $payment['created_at']),
                '',
                '⚠️ Đây mới là yêu cầu của người dùng, không phải bằng chứng ngân hàng đã nhận tiền.',
            ]);
            $this->telegram->sendMessage($chatId, $text, [
                'reply_markup' => $this->inlineMenu([[
                    ['text' => '✅ ĐÃ ĐỐI SOÁT — DUYỆT', 'callback_data' => 'member:payment-approve:' . $payment['payment_id']],
                    ['text' => '❌ TỪ CHỐI', 'callback_data' => 'member:payment-reject:' . $payment['payment_id']],
                ]]),
            ]);
        }
    }

    private function approvePaymentRequest(string $chatId, string $adminId, string $paymentId): void
    {
        if (!TelegramAuth::isAdmin($adminId)) {
            throw new RuntimeException('Bạn không có quyền duyệt yêu cầu nạp.');
        }
        $result = PaymentService::approve($paymentId, $adminId);
        $this->telegram->sendMessage($chatId, '✅ Đã duyệt yêu cầu <code>' . self::escape($paymentId) . '</code>. Cộng ' .
            self::money($result['amountVnd']) . '. Số dư mới: <b>' . self::money($result['newBalance']) . '</b>.');
        $this->notifyUserOnBot(
            (string) ($result['botKey'] ?? $this->botKey),
            (string) $result['telegramId'],
            '🎉 <b>NẠP TIỀN ĐÃ ĐƯỢC ADMIN DUYỆT</b>' . "\n" .
            'Mã: <code>' . self::escape($paymentId) . '</code>' . "\n" .
            'Số tiền: <b>+' . self::money($result['amountVnd']) . '</b>' . "\n" .
            'Số dư mới: <b>' . self::money($result['newBalance']) . '</b>'
        );
    }

    private function rejectPaymentRequest(string $chatId, string $adminId, string $paymentId): void
    {
        if (!TelegramAuth::isAdmin($adminId)) {
            throw new RuntimeException('Bạn không có quyền từ chối yêu cầu nạp.');
        }
        $result = PaymentService::reject($paymentId, $adminId, 'Admin từ chối sau khi đối soát.');
        $this->telegram->sendMessage($chatId, '❌ Đã từ chối yêu cầu <code>' . self::escape($paymentId) . '</code>. Ví không thay đổi.');
        $this->notifyUserOnBot(
            (string) ($result['botKey'] ?? $this->botKey),
            (string) $result['telegramId'],
            '❌ Yêu cầu nạp <code>' . self::escape($paymentId) . '</code> chưa được duyệt. Vui lòng kiểm tra nội dung chuyển khoản hoặc liên hệ CSKH.'
        );
    }

    private function showAdminVideoJobs(string $chatId, string $userId): void
    {
        if (!TelegramAuth::isAdmin($userId)) {
            $this->telegram->sendMessage($chatId, '⛔ Bạn không có quyền quản lý video jobs.');
            return;
        }
        $stats = VideoJobService::stats();
        $jobs = VideoJobService::recent(15);
        $this->telegram->sendMessage($chatId, '🎬 <b>VIDEO JOBS</b>' . "\n" .
            'Chờ: ' . $stats['queued'] . ' · Đang xử lý: ' . $stats['processing'] . ' · Hoàn tất: ' . $stats['completed'] . ' · Lỗi: ' . $stats['failed'] . "\n" .
            'Worker chỉ dispatch khi cấu hình VIDEO_PROCESSOR_URL và VIDEO_PROCESSOR_SECRET.');
        foreach ($jobs as $job) {
            $name = trim((string) ($job['name'] ?? '')) ?: (string) ($job['username'] ?? '');
            $lines = [
                '🎬 <code>' . self::escape(substr((string) $job['id'], 0, 8)) . '</code> · <b>' . self::escape(self::statusLabel((string) $job['status'])) . '</b>',
                'User: <code>' . self::escape((string) $job['user_id']) . '</code> ' . self::escape($name),
                'Tiến độ: ' . (int) $job['progress'] . '%',
                'Nguồn: ' . self::escape(self::truncate((string) $job['source_url'], 150)),
            ];
            if ($job['error_message'] !== '') {
                $lines[] = 'Lỗi: ' . self::escape(self::truncate((string) $job['error_message'], 180));
            }
            $buttons = [];
            if ($job['status'] === 'failed') {
                $buttons[] = ['text' => '♻️ ĐƯA LẠI VÀO HÀNG CHỜ', 'callback_data' => 'member:video-retry:' . $job['id']];
            }
            $buttons[] = ['text' => '🔄 TẢI LẠI', 'callback_data' => 'member:admin-jobs'];
            $this->telegram->sendMessage($chatId, implode("\n", $lines), [
                'reply_markup' => $this->inlineMenu([$buttons]),
            ]);
        }
    }

    private function retryVideoJob(string $chatId, string $adminId, string $jobId): void
    {
        if (!TelegramAuth::isAdmin($adminId)) {
            throw new RuntimeException('Bạn không có quyền chạy lại video job.');
        }
        $job = VideoJobService::retry($jobId);
        $this->telegram->sendMessage($chatId, '♻️ Đã đưa job <code>' . self::escape(substr((string) $job['id'], 0, 8)) . '</code> về hàng chờ. Cần worker/provider đang hoạt động để xử lý.');
        $this->notifyUserOnBot(
            (string) ($job['bot_key'] ?? $this->botKey),
            (string) $job['user_id'],
            'Video job của bạn đã được đưa lại vào hàng chờ. Mã job: ' . substr((string) $job['id'], 0, 8) . '.'
        );
    }

    private function showDepositHistory(string $chatId, string $userId): void
    {
        $requests = PaymentService::forUser($userId, 10);
        $transactions = Database::all(
            "SELECT amount, type, reference_code, wallet_type, status, created_at
             FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 20",
            [$userId]
        );
        $lines = ['💳 <b>LỊCH SỬ NẠP TIỀN & GIAO DỊCH</b>', '━━━━━━━━━━━━━━━━━━━━', '<b>Yêu cầu QR / chuyển khoản</b>'];
        foreach ($requests as $request) {
            $lines[] = '• <code>' . self::escape((string) $request['payment_id']) . '</code> · ' .
                self::money($request['amount']) . ' · ' . self::escape(self::statusLabel((string) $request['status']));
            $lines[] = '  Ngân hàng: <b>' . self::escape((string) $request['bank_id']) . '</b> · STK <code>' . self::escape((string) $request['account_number']) . '</code>';
            $lines[] = '  Chủ TK: <b>' . self::escape((string) $request['account_holder']) . '</b>';
            $lines[] = '  Nội dung CK: <code>' . self::escape((string) $request['transfer_content']) . '</code>';
        }
        if ($requests === []) $lines[] = 'Chưa có yêu cầu QR/chuyển khoản.';
        $lines[] = '';
        $lines[] = '<b>Giao dịch đã ghi nhận</b>';
        foreach ($transactions as $transaction) {
            $sign = in_array((string) $transaction['type'], ['DEPOSIT', 'GIFTCODE', 'REFERRAL_REWARD', 'VOUCHER_CREDIT'], true) ? '+' : '';
            $lines[] = '• ' . $sign . self::money($transaction['amount']) . ' · ' . self::escape((string) $transaction['type']) .
                ' · Ví ' . self::escape((string) ($transaction['wallet_type'] ?? 'MAIN')) .
                ' · ' . self::escape((string) ($transaction['reference_code'] ?? '—'));
        }
        if ($transactions === []) $lines[] = 'Chưa có giao dịch phát sinh.';
        $this->telegram->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->inlineMenu([
                [['text' => '＋ TẠO YÊU CẦU NẠP', 'callback_data' => 'member:wallet']],
                [['text' => '📜 LỊCH SỬ VÍ CHI TIẾT', 'callback_data' => 'member:history']],
                [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']],
            ]),
        ]);
    }

    private function showReferral(string $chatId, string $userId): void
    {
        $botPublicUrl = TelegramLink::normalize((string) Env::get(
            $this->botKey === 'secondary' ? 'BOT2_PUBLIC_URL' : 'BOT1_PUBLIC_URL',
            ''
        ));
        $count = (int) Database::value('SELECT COUNT(*) FROM users WHERE ref_by = ?', [$userId]);
        $reward = (int) Database::value(
            "SELECT COALESCE(SUM(amount), 0) FROM wallet_ledger WHERE user_id = ? AND transaction_type = 'REFERRAL_REWARD'",
            [$userId]
        );
        $rate = (float) (Database::value("SELECT value FROM app_settings WHERE key = 'referral_commission_percent'") ?? '0');
        $link = $botPublicUrl !== ''
            ? $botPublicUrl . '?start=ref_' . rawurlencode($userId)
            : 'Chưa cấu hình liên kết Telegram Bot công khai.';
        $this->telegram->sendMessage($chatId, implode("\n", [
            '🎊 <b>GIỚI THIỆU BẠN BÈ</b>',
            'Số người đã mời: <b>' . $count . '</b>',
            'Hoa hồng đã nhận: <b>' . self::money($reward) . '</b>',
            'Tỷ lệ hiện tại: <b>' . $rate . '%</b>',
            '',
            '<b>Đường dẫn giới thiệu:</b>',
            '<code>' . self::escape($link) . '</code>',
        ]), [
            'reply_markup' => $this->inlineMenu([[['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']]]),
        ]);
    }

    private function showVip(string $chatId, string $userId): void
    {
        $this->showVipWelcome($chatId, $userId);
    }

    private function showVipWelcome(string $chatId, string $userId): void
    {
        $user = $this->getUser($userId);
        $status = VipService::status($userId);
        $next = $status['nextThreshold'] === null
            ? 'Bạn đang ở cấp VIP cao nhất.'
            : 'Cần nạp thêm <b>' . self::money($status['amountToNext']) . '</b> để đạt mốc tiếp theo (' . self::money($status['nextThreshold']) . ').';
        $checkinText = $status['checkedInToday'] ? '✅ Hôm nay bạn đã điểm danh.' : '🎁 Hôm nay bạn chưa điểm danh.';

        $barLength = 10;
        $filled = (int) round(((int) ($status['progressPercent'] ?? 0)) / 10);
        $progressBar = str_repeat('🟦', max(0, min($barLength, $filled))) . str_repeat('⬛', $barLength - max(0, min($barLength, $filled)));
        $this->telegram->sendMessage($chatId, implode("\n", [
            '💠 <b>⩤ HENDY VIP PRO ⩥</b> 👑',
            '✨ <i>Đặc quyền hội viên · Nâng tầm trải nghiệm</i>',
            '━━━━━━━━━━━━━━━━━━━━',
            'Xin chào <b>' . self::escape($user['name'] ?: 'Thành viên') . '</b>!',
            '🆔 Telegram ID: <code>' . self::escape($userId) . '</code>',
            '🏆 Hạng hiện tại: <b>VIP ' . $status['currentLevel'] . '</b>',
            '💳 Tổng nạp tích lũy: <b>' . self::money($status['totalDeposited']) . '</b>',
            '⭐ Điểm thành viên: <b>' . number_format($status['pointOld'], 0, ',', '.') . '</b>',
            '📈 Tiến trình lên hạng: ' . $progressBar . ' <b>' . (int) ($status['progressPercent'] ?? 0) . '%</b>',
            $next,
            $checkinText,
            '🔥 Chuỗi điểm danh: <b>' . $status['streak'] . ' ngày</b>',
        ]), [
            'reply_markup' => $this->inlineMenu([
                [['text' => $status['checkedInToday'] ? '✅ ĐÃ ĐIỂM DANH HÔM NAY' : '🎁 ĐIỂM DANH VIP', 'callback_data' => 'member:vip-checkin', 'style' => 'success']],
                [['text' => 'ℹ️ THÔNG TIN TẤT CẢ VIP', 'callback_data' => 'member:vip-info', 'style' => 'primary']],
                [['text' => '🔙 TRỞ VỀ', 'callback_data' => 'member:home']],
            ]),
        ]);
    }

    private function showVipInfo(string $chatId, string $userId): void
    {
        $status = VipService::status($userId);
        $lines = [
            '👑 <b>THÔNG TIN TẤT CẢ CẤP VIP</b>',
            'Mốc xét hạng dựa trên tổng nạp tích lũy:',
            '',
        ];
        foreach (VipService::levels() as $level) {
            $current = (int) $level['level'] === $status['currentLevel'] ? '  ← HẠNG HIỆN TẠI' : '';
            $lines[] = '• <b>VIP ' . (int) $level['level'] . '</b> — ' . self::money($level['threshold']) . $current;
        }
        $lines[] = '';
        $lines[] = 'Điểm danh mỗi ngày nhận <b>' . $status['checkinPoints'] . ' điểm</b> vào điểm thành viên, không phải số dư tiền.';
        $lines[] = 'Điểm hiện có: <b>' . number_format($status['pointOld'], 0, ',', '.') . '</b>.';
        $lines[] = 'Quyền lợi chi tiết và gói nâng cấp được hiển thị theo các sản phẩm VIP do Admin cấu hình.';

        $this->telegram->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->inlineMenu([
                [['text' => '🎁 ĐIỂM DANH VIP', 'callback_data' => 'member:vip-checkin']],
                [['text' => '🔙 TRỞ VỀ TRUNG TÂM VIP', 'callback_data' => 'member:vip']],
                [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']],
            ]),
        ]);
    }

    private function handleVipCheckin(string $chatId, string $userId): void
    {
        $result = VipService::checkin($userId);
        $message = $result['alreadyCheckedIn']
            ? '✅ Bạn đã điểm danh hôm nay. Hệ thống không cộng điểm lần thứ hai.'
            : '🎉 Điểm danh thành công! +' . $result['pointsAwarded'] .
                ' điểm thành viên. Tổng điểm hiện có: ' . number_format($result['pointsTotal'], 0, ',', '.') .
                '. Chuỗi điểm danh: ' . $result['streak'] . ' ngày.';
        $this->telegram->sendMessage($chatId, $message);
        $this->showVipWelcome($chatId, $userId);
    }

    private function showCommunityLink(string $chatId, string $action): void
    {
        $key = $action === 'group' ? 'GROUP_URL' : 'CHANNEL_URL';
        $url = trim((string) (Database::value('SELECT value FROM app_settings WHERE key = ?', [$key]) ?: Env::get($key, '')));
        if (!preg_match('/^https:\/\/\S+$/i', $url)) {
            throw new RuntimeException('Admin chưa cấu hình ' . $key . '.');
        }
        $label = $action === 'group' ? '🗣️ MỞ NHÓM CHAT' : '📢 MỞ KÊNH THÔNG BÁO';
        $this->telegram->sendMessage($chatId, $action === 'group' ? '🗣️ <b>NHÓM CHAT HENDY</b>' : '📢 <b>KÊNH THÔNG BÁO HENDY</b>', [
            'reply_markup' => $this->inlineMenu([[['text' => $label, 'url' => $url]]]),
        ]);
    }


    private function showDeposit(string $chatId, string $userId, int $amount): void
    {
        if ($amount < 10000 || $amount > 500000000) {
            throw new RuntimeException('Số tiền nạp phải từ 10.000 đến 500.000.000 VNĐ.');
        }
        $settings = $this->settings(['VIETQR_BANK_ID', 'VIETQR_ACCOUNT_NO', 'VIETQR_ACCOUNT_NAME']);
        $bank = trim((string) ($settings['VIETQR_BANK_ID'] ?? '')) ?: (string) Env::get('VIETQR_BANK_ID', '');
        $account = trim((string) ($settings['VIETQR_ACCOUNT_NO'] ?? '')) ?: (string) Env::get('VIETQR_ACCOUNT_NO', '');
        $holder = trim((string) ($settings['VIETQR_ACCOUNT_NAME'] ?? '')) ?: (string) Env::get('VIETQR_ACCOUNT_NAME', '');
        if ($bank === '' || $account === '' || $holder === '') {
            throw new RuntimeException('Admin chưa cấu hình tài khoản VietQR.');
        }

        // Create a pending request with a unique transfer reference. It does
        // not credit the wallet; admins must verify the incoming bank transfer.
        $request = PaymentService::create($userId, $amount, $this->botKey, $bank, $account, $holder);
        $path = rawurlencode($bank) . '-' . rawurlencode($account) . '-compact2.png';
        $query = http_build_query([
            'amount' => $amount,
            'addInfo' => $request['transfer_content'],
            'accountName' => $holder,
        ]);
        $url = 'https://img.vietqr.io/image/' . $path . '?' . $query;

        $caption = implode("\n", [
            '💳 <b>YÊU CẦU NẠP ĐÃ ĐƯỢC TẠO</b>',
            'Mã yêu cầu: <code>' . self::escape((string) $request['payment_id']) . '</code>',
            'Số tiền: <b>' . self::money($amount) . '</b>',
            'Ngân hàng: <b>' . self::escape($bank) . '</b>',
            'Số tài khoản: <code>' . self::escape($account) . '</code>',
            'Chủ tài khoản: <b>' . self::escape($holder) . '</b>',
            'Nội dung chuyển khoản: <code>' . self::escape((string) $request['transfer_content']) . '</code>',
            '',
            '⏳ Trạng thái: <b>Chờ Admin đối soát</b>.',
            'Ví chỉ được cộng sau khi Admin kiểm tra sao kê và bấm Duyệt. Tạo yêu cầu không đồng nghĩa tiền đã được nhận.',
        ]);

        $markup = ['reply_markup' => $this->inlineMenu([
            [['text' => '📜 KIỂM TRA LỊCH SỬ NẠP', 'callback_data' => 'member:deposit-history']],
            [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']],
        ])];
        try {
            $this->telegram->sendPhoto($chatId, $url, $caption, $markup);
        } catch (Throwable $error) {
            $this->telegram->sendMessage($chatId, $caption . "\n\nKhông tải được ảnh QR. Vui lòng dùng đúng thông tin chuyển khoản phía trên.", $markup);
        }

        $this->notifyAdmins(
            '🔔 <b>YÊU CẦU NẠP MỚI</b>' . "\n" .
            'Mã: <code>' . self::escape((string) $request['payment_id']) . '</code> · ' .
            self::money($amount) . "\n" .
            'User: <code>' . self::escape($userId) . '</code>' . "\n" .
            'Nội dung CK: <code>' . self::escape((string) $request['transfer_content']) . '</code>' . "\n" .
            'Chỉ duyệt sau khi đối chiếu sao kê.',
            ['inline_keyboard' => [[
                ['text' => '✅ MỞ DANH SÁCH DUYỆT', 'callback_data' => 'member:admin-payments'],
            ]]]
        );
    }

    private function processPending(string $chatId, string $userId, string $text, array $pending): bool
    {
        if (strtotime((string) $pending['expires_at']) < time()) {
            $this->clearPending($userId);
            return false;
        }

        $payload = self::jsonArray($pending['payload'] ?? []);
        switch ((string) $pending['input_type']) {
            case 'wallet':
                if (!preg_match('/^\d{4,12}$/', $text) || (int) $text < 10000 || (int) $text > 500000000) {
                    $this->telegram->sendMessage($chatId, 'Số tiền hợp lệ từ 10.000 đến 500.000.000 VNĐ. Ví dụ: 50000.');
                    return true;
                }
                try {
                    $this->showDeposit($chatId, $userId, (int) $text);
                    $this->clearPending($userId);
                } catch (Throwable $error) {
                    $this->telegram->sendMessage($chatId, 'Chưa thể tạo thông tin nạp tiền: ' . self::escape($error->getMessage()));
                }
                return true;

            case 'video_url':
                try {
                    $job = VideoJobService::create($userId, $text, $this->botKey);
                    $this->clearPending($userId);
                    $shortId = substr((string) $job['id'], 0, 8);
                    $this->telegram->sendMessage($chatId, '✅ <b>ĐÃ TIẾP NHẬN VIDEO JOB</b>' . "\n" .
                        'Mã job: <code>' . self::escape($shortId) . '</code>' . "\n" .
                        'Trạng thái: <b>Chờ xử lý</b>.' . "\n" .
                        'Job đã vào hàng chờ PostgreSQL. Việc tạo phụ đề tự động cần worker và VIDEO_PROCESSOR_URL/VIDEO_PROCESSOR_SECRET được cấu hình.');
                    $this->notifyAdmins(
                        '🎬 <b>VIDEO JOB MỚI</b>' . "\n" .
                        'Job: <code>' . self::escape($shortId) . '</code>' . "\n" .
                        'User: <code>' . self::escape($userId) . '</code>' . "\n" .
                        'Nguồn: ' . self::escape(self::truncate($text, 180)),
                        ['inline_keyboard' => [[['text' => '🎬 XEM VIDEO JOBS', 'callback_data' => 'member:admin-jobs']]]]
                    );
                } catch (Throwable $error) {
                    $this->telegram->sendMessage($chatId, '❌ Không thể tạo video job: ' . self::escape($error->getMessage()));
                }
                return true;

            case 'giftcode':
                try {
                    $reward = $this->redeemGiftcode($userId, $text);
                    $this->telegram->sendMessage($chatId, '🎉 Kích hoạt Giftcode thành công. Ví được cộng <b>' . self::money($reward) . '</b>.');
                    $this->clearPending($userId);
                } catch (Throwable $error) {
                    $this->telegram->sendMessage($chatId, '❌ Giftcode không hợp lệ: ' . self::escape($error->getMessage()));
                }
                return true;

            case 'support_abuse':
                $stage = (string) ($payload['stage'] ?? 'order');
                if ($stage === 'order') {
                    if (!preg_match('/^[A-Za-z0-9_-]{4,40}$/', $text)) {
                        $this->telegram->sendMessage($chatId, 'Mã đơn hàng không đúng định dạng. Hãy nhập đúng mã hiển thị trong mục Đơn hàng.');
                        return true;
                    }
                    $order = Database::one(
                        "SELECT id, order_code, product_name FROM orders WHERE order_code = ? AND user_id = ? AND status = 'completed'",
                        [$text, $userId]
                    );
                    if ($order === null) {
                        $this->telegram->sendMessage($chatId, 'Không tìm thấy đơn đã hoàn tất thuộc tài khoản của bạn. Hãy kiểm tra mã đơn hoặc chọn Ticket hỗ trợ chung.');
                        return true;
                    }
                    $payload['stage'] = 'message';
                    $payload['order_id'] = (int) $order['id'];
                    $payload['order_code'] = (string) $order['order_code'];
                    $payload['product_name'] = (string) $order['product_name'];
                    $this->setPending($userId, 'support_abuse', $payload);
                    $this->telegram->sendMessage($chatId, '✅ Đã xác nhận đơn <b>' . self::escape((string) $order['order_code']) . '</b>. Hãy mô tả lỗi dính lạm dụng/không nhận điểm (ít nhất 5 ký tự). Không gửi mật khẩu hoặc OTP.');
                    return true;
                }

                if ($stage === 'message') {
                    if (mb_strlen($text) < 5) {
                        $this->telegram->sendMessage($chatId, 'Nội dung cần ít nhất 5 ký tự. Hãy mô tả rõ vấn đề bạn gặp phải.');
                        return true;
                    }
                    $orderCode = (string) ($payload['order_code'] ?? '');
                    $subject = 'Hỗ trợ dính lạm dụng (60%) · Đơn ' . $orderCode;
                    $message = 'Đơn hàng: ' . $orderCode . "\n" .
                        'Sản phẩm: ' . (string) ($payload['product_name'] ?? '') . "\n\n" .
                        mb_substr($text, 0, 2600);
                    $ticket = Database::one(
                        "INSERT INTO support_tickets (user_id, ticket_type, subject, message) VALUES (?, 'dinh_lam', ?, ?) RETURNING id",
                        [$userId, $subject, mb_substr($message, 0, 3000)]
                    );
                    $this->clearPending($userId);
                    $this->notifyAdmins(
                        '🆘 <b>YÊU CẦU DÍNH LẠM DỤNG (60%)</b>' . "\n" .
                        'Ticket: <code>#' . (int) $ticket['id'] . '</code>' . "\n" .
                        'User: <code>' . self::escape($userId) . '</code>' . "\n" .
                        'Đơn: <code>' . self::escape($orderCode) . '</code>' . "\n" .
                        'Nội dung: ' . self::escape(mb_substr($text, 0, 1600)),
                        ['inline_keyboard' => [[['text' => '🎧 MỞ QUẢN LÝ TICKET', 'callback_data' => 'member:admin']]]]
                    );
                    $this->telegram->sendMessage($chatId, '✅ Đã tạo ticket hỗ trợ dính lạm dụng #' . (int) $ticket['id'] . ' cho đơn ' . self::escape($orderCode) . '. CSKH sẽ kiểm tra và phản hồi. Việc bồi hoàn (nếu có) cần Admin xác minh thủ công.');
                    return true;
                }

                $this->clearPending($userId);
                return true;

            case 'support':
                $this->clearPending($userId);
                if (mb_strlen($text) < 5) {
                    $this->telegram->sendMessage($chatId, 'Nội dung cần ít nhất 5 ký tự. Hãy chọn LIÊN HỆ CSKH để gửi lại.');
                    return true;
                }
                $ticket = Database::one(
                    "INSERT INTO support_tickets (user_id, subject, message) VALUES (?, 'Yêu cầu hỗ trợ Telegram', ?) RETURNING id",
                    [$userId, mb_substr($text, 0, 3000)]
                );
                $this->notifyAdmins('🆕 YÊU CẦU CSKH #' . $ticket['id'] . "\nTelegram ID: " . $userId . "\nNội dung:\n" . self::escape(mb_substr($text, 0, 2500)));
                $this->telegram->sendMessage($chatId, '✅ Đã tạo yêu cầu hỗ trợ #' . $ticket['id'] . '. CSKH sẽ phản hồi qua Telegram.');
                return true;

            case 'live_order_issue':
                if (!preg_match('/^[A-Za-z0-9_-]{4,40}$/', $text)) {
                    $this->telegram->sendMessage($chatId, 'Mã đơn không đúng định dạng. Hãy nhập lại mã trong mục Đơn hàng.');
                    return true;
                }
                $order = Database::one(
                    "SELECT id, order_code, product_name FROM orders WHERE order_code = ? AND user_id = ? AND category = 'minigame'",
                    [$text, $userId]
                );
                if ($order === null) {
                    $this->telegram->sendMessage($chatId, 'Không tìm thấy đơn Mini Game thuộc tài khoản Telegram của bạn. Hãy kiểm tra lại mã đơn.');
                    return true;
                }
                $payload['order_id'] = (int) $order['id'];
                $payload['order_code'] = (string) $order['order_code'];
                $payload['product_name'] = (string) $order['product_name'];
                $this->setPending($userId, 'live_issue_message', $payload);
                $this->telegram->sendMessage($chatId, '✅ Đã nhận diện đơn <b>' . self::escape($order['order_code']) . '</b>. Hãy mô tả tình trạng bạn gặp phải (ít nhất 5 ký tự).');
                return true;

            case 'live_issue_message':
                if (mb_strlen($text) < 5) {
                    $this->telegram->sendMessage($chatId, 'Vui lòng mô tả ít nhất 5 ký tự để CSKH có thể kiểm tra.');
                    return true;
                }
                $issueType = (string) ($payload['ticket_type'] ?? 'live_win_no_reward');
                $title = $issueType === 'live_auto_reorder' ? 'Yêu cầu Auto đặt lại' : 'Trúng nhưng chưa phát thưởng';
                $orderCode = (string) ($payload['order_code'] ?? '');
                $ticket = Database::one(
                    'INSERT INTO support_tickets (user_id, subject, message, status, priority, ticket_type) VALUES (?, ?, ?, \'open\', \'normal\', ?) RETURNING id',
                    [$userId, $title . ' · Đơn ' . $orderCode, 'Đơn: ' . $orderCode . "\nSản phẩm: " . (string) ($payload['product_name'] ?? '') . "\nMô tả: " . $text, $issueType]
                );
                $this->clearPending($userId);
                $ticketId = (string) ($ticket['id'] ?? '');
                $this->telegram->sendMessage($chatId, '✅ Đã tạo ticket <b>#' . self::escape($ticketId) . '</b>. CSKH sẽ kiểm tra đơn và phản hồi. Yêu cầu chưa tự động tạo đơn mới hoặc cộng thưởng.');
                $this->notifyAdmins('🆘 <b>TICKET MINI GAME MỚI</b>' . "\nMã ticket: <code>" . self::escape($ticketId) . '</code>' . "\nUser: <code>" . self::escape($userId) . '</code>' . "\nĐơn: <code>" . self::escape($orderCode) . '</code>' . "\nLoại: " . self::escape($title) . "\nNội dung: " . self::escape(self::truncate($text, 500)));
                return true;

            case 'voucher_order':
                if (!preg_match('/^[A-Za-z0-9_-]{4,40}$/', $text)) {
                    $this->telegram->sendMessage($chatId, 'Mã đơn không đúng định dạng. Hãy nhập lại.');
                    return true;
                }
                $eligible = VoucherService::eligibleOrders($userId, 100);
                $selected = null;
                foreach ($eligible as $candidate) {
                    if (strcasecmp((string) $candidate['order_code'], $text) === 0) { $selected = $candidate; break; }
                }
                if ($selected === null) {
                    $this->telegram->sendMessage($chatId, 'Mã đơn chưa đủ điều kiện 72 giờ, đã xử lý, hoặc không thuộc tài khoản của bạn.');
                    return true;
                }
                try {
                    $request = VoucherService::create($userId, (int) $selected['id'], 'Đề nghị Voucher 72H qua menu Telegram.');
                    $this->clearPending($userId);
                    $this->telegram->sendMessage($chatId, '✅ Đã gửi yêu cầu Voucher 72H cho đơn <code>' . self::escape($text) . '</code>. Chờ Admin xét duyệt.');
                    $this->notifyAdmins('🎟️ <b>YÊU CẦU VOUCHER 72H</b>' . "\nTicket: <code>" . self::escape((string) $request['id']) . '</code>' . "\nUser: <code>" . self::escape($userId) . '</code>' . "\nĐơn: <code>" . self::escape($text) . '</code>');
                } catch (Throwable $error) {
                    $this->telegram->sendMessage($chatId, '❌ Không thể tạo yêu cầu Voucher: ' . self::escape($error->getMessage()));
                }
                return true;

            case 'nyc_transfer':
                if (!preg_match('/^\\d{1,12}$/', $text) || (int) $text < 1000 || (int) $text > 500000000) {
                    $this->telegram->sendMessage($chatId, 'Số tiền hợp lệ từ 1.000 đến 500.000.000 VNĐ. Hãy nhập lại.');
                    return true;
                }
                $amount = (int) $text;
                try {
                    $transfer = Database::transaction(function () use ($userId, $amount): array {
                        $pending = Database::one(
                            "DELETE FROM bot_pending_inputs WHERE user_id = ? AND bot_key = ? AND input_type = 'nyc_transfer' AND expires_at > NOW() RETURNING user_id",
                            [$userId, $this->botKey]
                        );
                        if ($pending === null) {
                            throw new RuntimeException('Yêu cầu chuyển ví đã hết hạn hoặc đã được xử lý. Hãy tạo yêu cầu mới.');
                        }
                        $user = Database::one('SELECT balance, nyc_wallet FROM users WHERE user_id = ? FOR UPDATE', [$userId]);
                        if ($user === null || (int) $user['balance'] < $amount) throw new RuntimeException('Ví Chính không đủ số dư để chuyển.');
                        Database::run('UPDATE users SET balance = balance - ?, nyc_wallet = nyc_wallet + ? WHERE user_id = ?', [$amount, $amount, $userId]);
                        $reference = 'nyc-transfer:' . bin2hex(random_bytes(12));
                        Database::run(
                            "INSERT INTO wallet_ledger (reference_code, user_id, amount, transaction_type, description, wallet_type) VALUES (?, ?, ?, 'WALLET_TRANSFER', ?, 'MAIN')",
                            [$reference . ':out', $userId, -$amount, 'Chuyển từ Ví Chính sang Ví NYC']
                        );
                        Database::run(
                            "INSERT INTO wallet_ledger (reference_code, user_id, amount, transaction_type, description, wallet_type) VALUES (?, ?, ?, 'WALLET_TRANSFER', ?, 'NYC')",
                            [$reference . ':in', $userId, $amount, 'Nhận từ Ví Chính']
                        );
                        Database::run(
                            "INSERT INTO transactions (user_id, amount, type, reference_code, status, wallet_type) VALUES (?, ?, 'WALLET_TRANSFER_OUT', ?, 'paid', 'MAIN'), (?, ?, 'WALLET_TRANSFER_IN', ?, 'paid', 'NYC')",
                            [$userId, $amount, $reference, $userId, $amount, $reference]
                        );
                        $balances = Database::one('SELECT balance, nyc_wallet FROM users WHERE user_id = ?', [$userId]);
                        return ['main' => (int) $balances['balance'], 'nyc' => (int) $balances['nyc_wallet']];
                    });
                    $this->clearPending($userId);
                    $this->telegram->sendMessage($chatId, '✅ <b>CHUYỂN VÍ THÀNH CÔNG</b>' . "\nĐã chuyển: <b>" . self::money($amount) . "</b>" . "\nVí Chính: " . self::money($transfer['main']) . "\nVí NYC: " . self::money($transfer['nyc']));
                } catch (Throwable $error) {
                    $this->telegram->sendMessage($chatId, '❌ Không thể chuyển ví: ' . self::escape($error->getMessage()));
                }
                return true;

            case 'live_acc_username':
                $brand = strtoupper((string) ($payload['brand'] ?? ''));
                $productId = (int) ($payload['product_id'] ?? 0);
                if ($this->minigameGroupForBrand($brand) === null
                    || mb_strlen($text) < 3
                    || mb_strlen($text) > 64
                    || preg_match('/[\x00-\x20]/', $text)) {
                    $this->telegram->sendMessage($chatId, 'Username không hợp lệ. Nhập 3–64 ký tự, không có khoảng trắng. Không gửi mật khẩu/PIN/OTP.');
                    return true;
                }
                $inserted = Database::run(
                    'INSERT INTO linked_accounts (user_id, brand, username) VALUES (?, ?, ?) ' .
                    'ON CONFLICT (user_id, brand, username) DO NOTHING RETURNING id',
                    [$userId, $brand, $text]
                );
                $this->clearPending($userId);
                if ($inserted->rowCount() === 0) {
                    $this->telegram->sendMessage($chatId, 'Tài khoản này đã có trong kho ' . self::escape($brand) . '.');
                } else {
                    $this->telegram->sendMessage($chatId, '✅ Đã thêm username vào kho ACC ' . self::escape($brand) . '.');
                }
                $this->showMinigamePackageManager($chatId, $userId, $brand, $productId);
                return true;

            case 'link_username':
                $brand = strtoupper((string) ($payload['brand'] ?? ''));
                if (!in_array($brand, self::BRANDS, true) || mb_strlen($text) < 3 || mb_strlen($text) > 64 || preg_match('/[\x00-\x1F]/', $text)) {
                    $this->telegram->sendMessage($chatId, 'Tên đăng nhập không hợp lệ. Không gửi mật khẩu hoặc OTP.');
                    return true;
                }
                Database::run(
                    'INSERT INTO linked_accounts (user_id, brand, username) VALUES (?, ?, ?) ON CONFLICT DO NOTHING',
                    [$userId, $brand, $text]
                );
                $this->clearPending($userId);
                $this->telegram->sendMessage($chatId, '🎉 Đã liên kết ' . self::escape($brand) . ' · <code>' . self::escape($text) . '</code>.');
                return true;
        }
        return false;
    }

    private function redeemGiftcode(string $userId, string $rawCode): int
    {
        $code = strtoupper(trim($rawCode));
        if (!preg_match('/^[A-Z0-9_-]{4,64}$/', $code)) {
            throw new RuntimeException('Mã Giftcode không đúng định dạng.');
        }

        return Database::transaction(function () use ($userId, $code): int {
            $gift = Database::one('SELECT * FROM giftcodes WHERE code = ? FOR UPDATE', [$code]);
            if ($gift === null) {
                throw new RuntimeException('Giftcode không tồn tại.');
            }
            if (!$this->toBool($gift['is_active']) || ($gift['expires_at'] && strtotime($gift['expires_at']) < time())) {
                throw new RuntimeException('Giftcode đã hết hạn hoặc bị khóa.');
            }
            if ((int) $gift['redeemed_count'] >= (int) $gift['max_redemptions']) {
                throw new RuntimeException('Giftcode đã hết lượt.');
            }

            $insert = Database::run(
                'INSERT INTO giftcode_redemptions (code, user_id, reward_amount) VALUES (?, ?, ?) ON CONFLICT DO NOTHING RETURNING code',
                [$code, $userId, $gift['reward_amount']]
            );
            if ($insert->rowCount() === 0) {
                throw new RuntimeException('Bạn đã sử dụng Giftcode này rồi.');
            }

            $reward = (int) $gift['reward_amount'];
            if ($reward > 0) {
                Database::run('UPDATE users SET balance = balance + ? WHERE user_id = ?', [$reward, $userId]);
                Database::run(
                    "INSERT INTO wallet_ledger (reference_code, user_id, amount, transaction_type, description) VALUES (?, ?, ?, 'GIFTCODE', ?)",
                    ['giftcode:' . $code . ':' . $userId, $userId, $reward, 'Nhận thưởng Giftcode ' . $code]
                );
                Database::run(
                    "INSERT INTO transactions (user_id, amount, type, reference_code, status) VALUES (?, ?, 'GIFTCODE', ?, 'paid')",
                    [$userId, $reward, $code]
                );
            }
            Database::run('UPDATE giftcodes SET redeemed_count = redeemed_count + 1 WHERE code = ?', [$code]);
            return $reward;
        });
    }

    private function addMoney(string $chatId, string $senderId, string $targetId, int $amount): void
    {
        if (!TelegramAuth::isAdmin($senderId)) {
            $this->telegram->sendMessage($chatId, 'Bạn không có quyền sử dụng lệnh này.');
            return;
        }
        if ($amount <= 0 || $amount > 9000000000000000) {
            $this->telegram->sendMessage($chatId, 'Số tiền cộng không hợp lệ.');
            return;
        }
        Database::transaction(function () use ($targetId, $amount): void {
            Database::run('INSERT INTO users (user_id) VALUES (?) ON CONFLICT DO NOTHING', [$targetId]);
            Database::run('UPDATE users SET balance = balance + ? WHERE user_id = ?', [$amount, $targetId]);
            $reference = 'botadmin:' . bin2hex(random_bytes(12));
            Database::run(
                "INSERT INTO wallet_ledger (reference_code, user_id, amount, transaction_type, description) VALUES (?, ?, ?, 'ADMIN_CREDIT', ?)",
                [$reference, $targetId, $amount, 'Cộng tiền qua lệnh Telegram /addmoney']
            );
            Database::run(
                "INSERT INTO transactions (user_id, amount, type, reference_code, status) VALUES (?, ?, 'ADMIN_CREDIT', ?, 'paid')",
                [$targetId, $amount, $reference]
            );
        });
        $this->telegram->sendMessage($chatId, 'Đã cộng ' . self::money($amount) . ' cho tài khoản ' . $targetId . '.');
        try {
            $this->telegram->sendMessage($targetId, 'Quản trị viên đã cộng ' . self::money($amount) . ' vào số dư tài khoản của bạn.');
        } catch (Throwable $error) {
            error_log('Không gửi được thông báo cộng tiền: ' . $error->getMessage());
        }
    }

    private function openAdmin(string $chatId, string $userId): void
    {
        if (!TelegramAuth::isAdmin($userId)) {
            $this->telegram->sendMessage($chatId, '⛔ Bạn không có quyền quản trị.');
            return;
        }
        try {
            $url = $this->createWebsiteLoginUrl($userId, 'admin');
        } catch (Throwable $error) {
            error_log('[admin-login] Không tạo được link đăng nhập Admin: ' . $error->getMessage());
            $this->telegram->sendMessage($chatId, 'Không thể tạo phiên Admin lúc này. Vui lòng thử lại sau.');
            return;
        }
        $this->telegram->sendMessage($chatId, implode("\n", [
            '🛡️ <b>HENDY ADMIN DASHBOARD</b>',
            'Mở bảng điều khiển trực tiếp trên trình duyệt. Không cần Telegram Mini App.',
            'Liên kết bảo mật chỉ dùng một lần và hết hạn sau <b>5 phút</b>.',
            'Chỉ tài khoản nằm trong danh sách ADMIN_ID / ADMIN_IDS mới được cấp phiên Admin.',
            '',
            'Không chuyển tiếp liên kết này cho người khác.',
        ]), [
            'reply_markup' => $this->inlineMenu([
                [['text' => '🌐 MỞ BẢNG ĐIỀU KHIỂN ADMIN', 'url' => $url]],
            ]),
            'disable_web_page_preview' => true,
        ]);
    }

    private function miniAppUrl(): string
    {
        $base = rtrim((string) (Env::get('APP_URL') ?: Env::get('RENDER_EXTERNAL_URL', '')), '/');
        if (!str_starts_with($base, 'https://')) return '';
        return $base . '/miniapp?bot=' . rawurlencode($this->botKey);
    }

    private function webAppUrl(string $section): string
    {
        $base = rtrim((string) (Env::get('APP_URL') ?: Env::get('RENDER_EXTERNAL_URL', '')), '/');
        if (!str_starts_with($base, 'https://')) return '';
        // The only Telegram Web App route retained is the admin-only dashboard.
        return $base . '/admin?section=' . rawurlencode($section) . '&view=admin&bot=' . rawurlencode($this->botKey);
    }

    private function setPending(string $userId, string $type, array $payload = []): void
    {
        Database::run(
            'INSERT INTO bot_pending_inputs (user_id, bot_key, input_type, payload, expires_at) VALUES (?, ?, ?, ?::jsonb, NOW() + INTERVAL \'' . self::TTL_MINUTES . ' minutes\') ' .
            'ON CONFLICT (user_id, bot_key) DO UPDATE SET input_type = EXCLUDED.input_type, payload = EXCLUDED.payload, expires_at = EXCLUDED.expires_at',
            [$userId, $this->botKey, $type, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
        );
    }

    private function getPending(string $userId): ?array
    {
        $pending = Database::one('SELECT input_type, payload, expires_at FROM bot_pending_inputs WHERE user_id = ? AND bot_key = ?', [$userId, $this->botKey]);
        if ($pending === null) {
            return null;
        }
        if (strtotime((string) $pending['expires_at']) < time()) {
            $this->clearPending($userId);
            return null;
        }
        return $pending;
    }

    private function clearPending(string $userId): void
    {
        Database::run('DELETE FROM bot_pending_inputs WHERE user_id = ? AND bot_key = ?', [$userId, $this->botKey]);
    }

    private function getUser(string $userId, string $name = '', string $username = ''): array
    {
        return Database::one(
            "INSERT INTO users (user_id, name, username) VALUES (?, ?, ?) ON CONFLICT (user_id) DO UPDATE SET " .
            "name = CASE WHEN EXCLUDED.name <> '' THEN EXCLUDED.name ELSE users.name END, " .
            "username = CASE WHEN EXCLUDED.username <> '' THEN EXCLUDED.username ELSE users.username END " .
            'RETURNING user_id, name, username, balance, vip_level, total_deposited, nyc_wallet, voucher_wallet, point_old',
            [$userId, $name, $username]
        ) ?? [];
    }

    private function settings(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $rows = Database::all("SELECT key, value FROM app_settings WHERE key IN ($placeholders)", $keys);
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['key']] = $row['value'];
        }
        return $settings;
    }

    private function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private static function jsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private static function money(mixed $amount): string
    {
        return number_format((float) $amount, 0, ',', '.') . ' VNĐ';
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'Chờ xử lý',
            'processing' => 'Đang xử lý',
            'completed' => 'Hoàn tất',
            'rejected' => 'Từ chối',
            'cancelled' => 'Đã hủy',
            default => $status,
        };
    }

    private static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function truncate(string $value, int $length): string
    {
        return mb_substr($value, 0, $length);
    }
}
