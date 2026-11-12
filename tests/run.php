<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Env.php';
require dirname(__DIR__) . '/app/TelegramAuth.php';
require dirname(__DIR__) . '/app/TelegramLink.php';
require dirname(__DIR__) . '/app/BotMenuDefinition.php';
require dirname(__DIR__) . '/app/WebhookSecurity.php';
require dirname(__DIR__) . '/app/TelegramApi.php';
require dirname(__DIR__) . '/app/PaymentService.php';
require dirname(__DIR__) . '/app/VideoJobService.php';
require dirname(__DIR__) . '/app/WebServiceContract.php';
require dirname(__DIR__) . '/app/WebServiceKernel.php';
require dirname(__DIR__) . '/app/SystemWebService.php';
require dirname(__DIR__) . '/app/OperationsService.php';
require dirname(__DIR__) . '/app/ProxyService.php';

use Hendy\VideoJobService;
use Hendy\TelegramAuth;
use Hendy\TelegramLink;
use Hendy\BotMenuDefinition;
use Hendy\WebhookSecurity;
use Hendy\WebServiceKernel;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException('Test thất bại: ' . $message);
    }
};

$assert(TelegramAuth::safeEquals('same-secret', 'same-secret'), 'so sánh chuỗi đúng');
$assert(!TelegramAuth::safeEquals('different', 'same-secret'), 'so sánh chuỗi khác');
$assert(!TelegramAuth::safeEquals('', ''), 'secret rỗng bị từ chối');
$assert(TelegramLink::normalize('https://t.me/HendySupportGroup') === 'https://t.me/HendySupportGroup', 'chấp nhận liên kết Telegram HTTPS hợp lệ');
$assert(TelegramLink::normalize('https://t.me/+Invite_Code-123') === 'https://t.me/+Invite_Code-123', 'chấp nhận liên kết mời Telegram');
$assert(TelegramLink::normalize('http://t.me/HendySupportGroup') === '', 'từ chối liên kết Telegram không HTTPS');
$assert(TelegramLink::normalize('https://t.me.evil.example/HendySupportGroup') === '', 'từ chối domain Telegram giả mạo');
$assert(TelegramLink::normalize('https://user@t.me/HendySupportGroup') === '', 'từ chối URL Telegram có userinfo');
$assert(TelegramLink::normalize('https://t.me/hendy_cyber_bot?start=old') === 'https://t.me/hendy_cyber_bot', 'chuẩn hóa public URL bot và loại query có sẵn');
$assert(TelegramLink::normalize('https://example.com/hendy_cyber_bot') === '', 'từ chối URL public bot ngoài Telegram');

$assert(array_column(BotMenuDefinition::ITEMS, 'action') === ['live','tools','codes','wallet','otp','viptools','customer','miniapp','matchmaking','ref','vip','group','news','gift','support'], 'menu có nút Mini App riêng bên cạnh Trung tâm khách hàng');
$assert(BotMenuDefinition::LAYOUT === [['live','tools'],['codes'],['wallet','otp'],['viptools'],['customer'],['miniapp'],['matchmaking'],['ref','vip'],['group','news'],['gift','support'],['admin']], 'layout có hàng mở Mini App riêng trong Inline Menu');
$botServiceSource = file_get_contents(dirname(__DIR__) . '/app/BotService.php');
$assert(str_contains($botServiceSource, "'menu_mode' => 'inline_keyboard'"), 'menu mặc định là Inline Keyboard');
$assert(str_contains($botServiceSource, "return ['inline_keyboard' => \$rows];"), 'menu luôn gửi Inline Keyboard, kể cả cấu hình cũ');
$assert(str_contains($botServiceSource, "if (\$item['action'] === 'miniapp')") && str_contains($botServiceSource, "'web_app' => ['url' => \$url]"), 'nút Mở Mini App dùng Telegram Web App trực tiếp');
$assert(str_contains($botServiceSource, '$this->telegram->answerCallback($callbackId);') && !str_contains($botServiceSource, 'Đã nhận, đang xử lý…'), 'callback được xác nhận ngầm, không hiện thông báo kỹ thuật');
$assert(str_contains($botServiceSource, '👋 Chào mừng bạn đến với HENDY BOT!') && !str_contains($botServiceSource, '♻️ Menu HENDY đã chuyển sang Inline Menu mới.'), 'gỡ Reply Keyboard bằng lời chào tự nhiên thay cho thông báo kỹ thuật');
$assert(str_contains($botServiceSource, 'https://i.postimg.cc/rcD583NW/anh-quan-cao.png?dl=1'), 'banner Telegram dùng direct-image URL');
$assert(str_contains($botServiceSource, 'TÍNH NĂNG ĐANG PHÁT TRIỂN'), 'mục chưa triển khai có thông báo trạng thái rõ ràng');
$assert(str_contains($botServiceSource, 'OperationsService::forBot') && str_contains($botServiceSource, '/status'), 'bot kiểm tra trạng thái bảo trì/nâng cấp và cho phép xem /status');
$operationsSource = file_get_contents(dirname(__DIR__) . '/app/OperationsService.php');
$assert(str_contains($operationsSource, 'maintenance') && str_contains($operationsSource, 'upgrade') && str_contains($operationsSource, 'Asia/Ho_Chi_Minh'), 'dịch vụ vận hành hỗ trợ bảo trì, nâng cấp và giờ Việt Nam');
$assert(str_contains($operationsSource, '$effectiveMode = $active ? $mode : \'off\'') && str_contains($operationsSource, '$effectiveUntil = $active'), 'chế độ đã hết hạn trở lại trạng thái hoạt động và xóa lịch cũ trên giao diện');
$proxyServiceSource = file_get_contents(dirname(__DIR__) . '/app/ProxyService.php');
$assert(str_contains($proxyServiceSource, 'aes-256-gcm') && str_contains($proxyServiceSource, 'checkOnly') && str_contains($proxyServiceSource, 'rotatev2'), 'HomeProxy dùng AES-256-GCM, checkOnly và API xoay IP');
$customerUiSource = file_get_contents(dirname(__DIR__) . '/apps/website/index.html');
$assert(str_contains($customerUiSource, 'class="cc-dashboard"') && str_contains($customerUiSource, 'cc-order-modal'), 'trung tâm khách hàng dùng layout tham chiếu và chi tiết đơn hàng');
$assert(str_contains($customerUiSource, 'name="hendy-build" content="2026.11.07.5"') && str_contains($customerUiSource, 'const MINI_APP_ROUTE'), 'Customer portal giữ marker ẩn và nhận diện route Mini App');
$assert(!str_contains($customerUiSource, 'build-label') && !str_contains($customerUiSource, 'BUILD 2026.11.07.3'), 'không hiển thị nhãn build trên giao diện thành viên');
$assert(str_contains($customerUiSource, "if(MINI_APP_ROUTE)") && str_contains($customerUiSource, "MINI_APP_ROUTE?'':'<div class=\"cc-page-heading\""), 'Mini App không hiển thị tiêu đề Customer Center hoặc cổng login web');
$assert(str_contains($customerUiSource, "const accountAndStats=MINI_APP_ROUTE?'':'<section class=\"cc-summary\"") && str_contains($customerUiSource, "linkedAccountsRequest=MINI_APP_ROUTE?Promise.resolve"), 'Mini App ẩn thống kê/quản lý tài khoản Mini Game và không gọi API liên kết');
$assert(str_contains($customerUiSource, "api('/api/customer-center')") && str_contains($customerUiSource, "api('/api/wallet/payment-requests')") && str_contains($customerUiSource, "api('/api/vouchers')"), 'Customer Center lấy số liệu ví, đơn hàng và voucher từ API thật');
$assert(!preg_match('/<input[^>]+type=["\']password/i', $customerUiSource) && !str_contains($customerUiSource, 'name="abuse_account_name"'), 'Customer Center không thu thập mật khẩu tài khoản game');
$assert(!str_contains($customerUiSource, '8639286417') && !str_contains($customerUiSource, 'Hendyngok') && !str_contains($customerUiSource, 'AVATAR/5.gif'), 'Customer Center không hard-code Telegram ID, username hoặc avatar cá nhân');
$frontControllerSource = file_get_contents(dirname(__DIR__) . '/public/index.php');
$pollingSource = file_get_contents(dirname(__DIR__) . '/bin/polling.php');
$assert(str_contains($frontControllerSource, "'/app/OperationsService.php'") && str_contains($frontControllerSource, "'/app/ProxyService.php'"), 'HTTP front controller explicitly loads operations and proxy services');
$assert(str_contains($pollingSource, "'/app/OperationsService.php'"), 'polling entrypoint explicitly loads maintenance service');
$adminUiSource = file_get_contents(dirname(__DIR__) . '/apps/admin-dashboard/index.html');
$assert(!str_contains($adminUiSource, 'telegram-web-app.js'), 'Admin không tải Telegram Mini App SDK');
$assert(str_contains($adminUiSource, 'value="inline_keyboard"'), 'trình quản lý menu chỉ lưu Inline Keyboard');
$assert(str_contains($adminUiSource, 'name="hendy-build" content="2026.11.07.2"') && str_contains($adminUiSource, '--primary:#ff315d'), 'Admin có release marker và giao diện HENDY đỏ đen');
$assert(str_contains($adminUiSource, 'data-admin-tab="operations"') && str_contains($adminUiSource, 'adminOperations()'), 'Admin có bảng điều khiển bảo trì/nâng cấp cho từng bot');
$assert(str_contains($adminUiSource, 'data-admin-tab="proxy"') && str_contains($adminUiSource, 'adminProxy()'), 'Admin có trang quản lý HomeProxy');
$assert(str_contains($adminUiSource, 'homeproxy-connect-form') && str_contains($adminUiSource, 'data-homeproxy-action="check"') && str_contains($adminUiSource, 'data-homeproxy-action="rotate"'), 'Mini App có luồng kết nối, kiểm tra và xoay IP HomeProxy');
$landingUiSource = file_get_contents(dirname(__DIR__) . '/apps/website/landing.html');
$assert(str_contains($landingUiSource, 'hendy-landing.css?v=2026.11.07.4') && str_contains($landingUiSource, 'name="hendy-build" content="2026.11.07.4"'), 'trang chủ có cache-busting và release marker');
$assert(str_contains($landingUiSource, 'orbit-wrapper') && str_contains($landingUiSource, 'hero-bot-link') && str_contains($landingUiSource, 'group-link') && str_contains($landingUiSource, 'channel-link'), 'trang chủ giữ bố cục HENDY, liên kết Bot, Group và Channel');
$assert(!str_contains($landingUiSource, 'Features Tags Grid') && !str_contains($landingUiSource, 'feature-tag'), 'trang chủ đã bỏ hoàn toàn khối Features Tags Grid');
$publicRouterSource = file_get_contents(dirname(__DIR__) . '/public/index.php');
$assert(str_contains($publicRouterSource, "header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');"), 'trang chủ không giữ HTML cũ trong cache');
$productionHost = 'https://hendy-bot-cybertech-1.onrender.com';
$landingSource = file_get_contents(dirname(__DIR__) . '/apps/website/landing.html');
$privacySource = file_get_contents(dirname(__DIR__) . '/apps/website/privacy.html');
$termsSource = file_get_contents(dirname(__DIR__) . '/apps/website/terms.html');
$renderSource = file_get_contents(dirname(__DIR__) . '/render.yaml');
$envManifest = file_get_contents(dirname(__DIR__) . '/config/env.schema.json');
$runtimeWorkflow = file_get_contents(dirname(__DIR__) . '/.github/workflows/sync-runtime-config.yml');
$sitemapSource = file_get_contents(dirname(__DIR__) . '/public/sitemap.xml');
$robotsSource = file_get_contents(dirname(__DIR__) . '/public/robots.txt');
$databaseSchema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
$deployScript = file_get_contents(dirname(__DIR__) . '/scripts/deploy-verify-render.sh');
$syncScript = file_get_contents(dirname(__DIR__) . '/scripts/sync-render-env.sh');
$assert(str_contains($landingSource, $productionHost) && str_contains($privacySource, $productionHost) && str_contains($termsSource, $productionHost), 'canonical và OG URL dùng hostname Render production');
$assert(str_contains($renderSource, 'value: ' . $productionHost) && str_contains($renderSource, 'value: wss://hendy-bot-cybertech-1.onrender.com/ws'), 'Render Blueprint dùng đúng HTTPS/WSS production');
$assert(str_contains($renderSource, 'key: HOMEPROXY_ENCRYPTION_KEY') && str_contains($envManifest, 'HOMEPROXY_ENCRYPTION_KEY'), 'HomeProxy encryption secret được khai báo trong Render manifest');
$assert(str_contains($envManifest, $productionHost) && str_contains($runtimeWorkflow, $productionHost), 'manifest và workflow đồng bộ runtime dùng đúng hostname');
$assert(str_contains($sitemapSource, $productionHost) && str_contains($robotsSource, $productionHost), 'sitemap và robots dùng đúng hostname');
$assert(str_contains($databaseSchema, $productionHost) && str_contains($databaseSchema, "'https://hendy-bot-tele.onrender.com'"), 'migration đổi URL mặc định cũ nhưng giữ điều kiện nhận diện an toàn');
$assert(str_contains($deployScript, $productionHost) && str_contains($syncScript, $productionHost), 'script đồng bộ và xác minh deploy dùng URL production');
$assert(str_contains($botServiceSource, $productionHost) && !str_contains($botServiceSource, 'https://hendy-bot-cybertech.onrender.com'), 'fallback link bot dùng hostname production mới');
$webhookSecret = 'sample-webhook-secret-for-tests-000001';
$assert(WebhookSecurity::verifySecret($webhookSecret, $webhookSecret), 'webhook secret đúng được chấp nhận');
$assert(!WebhookSecurity::verifySecret($webhookSecret, 'wrong-webhook-secret-for-tests-000001'), 'webhook secret sai bị từ chối');
$hmacBody = '{"update_id":123,"message":{"text":"hello"}}';
$hmac = 'sha256=' . hash_hmac('sha256', $hmacBody, $webhookSecret);
$assert(WebhookSecurity::verifyHmac($hmacBody, $hmac, $webhookSecret), 'HMAC callback hợp lệ được chấp nhận');
$assert(!WebhookSecurity::verifyHmac($hmacBody, 'sha256=' . str_repeat('0', 64), $webhookSecret), 'HMAC callback sai bị từ chối');
$assert(WebhookSecurity::decodeJson($hmacBody, 1024)['update_id'] === 123, 'JSON webhook được giải mã');

$previousAdminId = getenv('ADMIN_ID');
$previousAdminIds = getenv('ADMIN_IDS');
putenv('ADMIN_ID=9500000001');
putenv('ADMIN_IDS=9500000002,9500000001,invalid');
$assert(TelegramAuth::adminIds() === ['9500000001', '9500000002'], 'admin allowlist merges and deduplicates ADMIN_ID and ADMIN_IDS');
$assert(TelegramAuth::isAdmin('9500000002'), 'secondary admin is authorized by shared admin allowlist');
if ($previousAdminId === false) putenv('ADMIN_ID'); else putenv('ADMIN_ID=' . $previousAdminId);
if ($previousAdminIds === false) putenv('ADMIN_IDS'); else putenv('ADMIN_IDS=' . $previousAdminIds);


$assert(WebServiceKernel::handles('/api/services/system/health'), 'Web Service kernel nhận route đã đăng ký');
$assert(!WebServiceKernel::handles('/api/not-services/system/health'), 'Web Service kernel không nhận route ngoài namespace');
$assert(in_array('system', WebServiceKernel::registeredServices(), true), 'System Web Service được đăng ký trong allow-list');
$invalidServicePathRejected = false;
try {
    WebServiceKernel::dispatch('GET', '/api/services/unknown/health', [], []);
} catch (RuntimeException) {
    $invalidServicePathRejected = true;
}
$assert($invalidServicePathRejected, 'Web Service không được đăng ký sẽ bị từ chối');

$assert(VideoJobService::isAllowedMediaUrl('https://youtu.be/dQw4w9WgXcQ'), 'chấp nhận URL YouTube HTTPS');
$assert(VideoJobService::isAllowedMediaUrl('https://www.tiktok.com/@creator/video/123'), 'chấp nhận URL TikTok HTTPS');
$assert(!VideoJobService::isAllowedMediaUrl('http://youtube.com/watch?v=abc'), 'từ chối URL video HTTP');
$assert(!VideoJobService::isAllowedMediaUrl('https://youtube.com.evil.example/watch?v=abc'), 'từ chối domain giả mạo');
$assert(!VideoJobService::isAllowedMediaUrl('https://127.0.0.1/admin'), 'từ chối IP nội bộ thay vì domain video');

$token = sprintf('123456789:%s', str_repeat('A', 40));
$authDate = time();
$user = json_encode(['id' => 12345678, 'first_name' => 'Hendy'], JSON_UNESCAPED_UNICODE);
$params = [
    'auth_date' => (string) $authDate,
    'query_id' => 'AAEAAAE',
    'user' => (string) $user,
];
ksort($params, SORT_STRING);
$checkString = implode("\n", array_map(
    static fn ($key, $value): string => $key . '=' . $value,
    array_keys($params),
    array_values($params)
));
$secret = hash_hmac('sha256', $token, 'WebAppData', true);
$hash = hash_hmac('sha256', $checkString, $secret);
$initData = http_build_query([...$params, 'hash' => $hash]);
$validated = TelegramAuth::validateInitData($initData, $token);
$assert((string) $validated['id'] === '12345678', 'xác thực Telegram initData hợp lệ');

$invalid = str_replace($hash, str_repeat('0', 64), $initData);
$thrown = false;
try {
    TelegramAuth::validateInitData($invalid, $token);
} catch (Throwable) {
    $thrown = true;
}
$assert($thrown, 'initData bị sửa phải bị từ chối');

$expiredParams = [
    'auth_date' => (string) ($authDate - 90000),
    'user' => (string) $user,
];
ksort($expiredParams, SORT_STRING);
$expiredString = implode("\n", array_map(
    static fn ($key, $value): string => $key . '=' . $value,
    array_keys($expiredParams),
    array_values($expiredParams)
));
$expiredHash = hash_hmac('sha256', $expiredString, $secret);
$expiredData = http_build_query([...$expiredParams, 'hash' => $expiredHash]);
$thrown = false;
try {
    TelegramAuth::validateInitData($expiredData, $token);
} catch (Throwable) {
    $thrown = true;
}
$assert($thrown, 'initData quá hạn phải bị từ chối');

echo "Đã chạy {$checks} kiểm tra PHP.\n";
