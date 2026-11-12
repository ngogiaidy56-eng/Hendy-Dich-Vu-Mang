<?php
declare(strict_types=1);

namespace Hendy;

/** Canonical Telegram Inline Keyboard shared by both bot instances. */
final class BotMenuDefinition
{
    public const ITEMS = [
        ['action' => 'live', 'label' => '🚀 MINIGAME LIVE', 'description' => 'Chọn nhóm Mini Game Live và quản lý tài khoản.', 'style' => 'danger'],
        ['action' => 'tools', 'label' => '🧰 THUÊ TOOL (NEW)', 'description' => 'Mở kho công cụ và xem thời hạn thuê.', 'style' => 'primary'],
        ['action' => 'codes', 'label' => '🎁 MUA LÌ XÌ - CODE MÀN - CODE TELE', 'description' => 'Mở kho lì xì, mã màn hình và mã Telegram.', 'style' => 'success'],
        ['action' => 'wallet', 'label' => '💳 NẠP TIỀN', 'description' => 'Tạo yêu cầu nạp và xem trạng thái VietQR.', 'style' => 'danger'],
        ['action' => 'otp', 'label' => '🛡️ OTP & PROXY', 'description' => 'Mở trung tâm OTP, Proxy và ví NYC.', 'style' => 'primary'],
        ['action' => 'viptools', 'label' => '👑 KHO CÔNG CỤ ĐỘC QUYỀN', 'description' => 'Mở danh mục công cụ và dịch vụ VIP.', 'style' => 'success'],
        ['action' => 'customer', 'label' => '💠 TRUNG TÂM KHÁCH HÀNG HENDY', 'description' => 'Hồ sơ, ví, đơn hàng và hỗ trợ khách hàng.', 'style' => 'primary'],
        ['action' => 'miniapp', 'label' => '📱 MỞ MINI APP HENDY', 'description' => 'Mở Mini App trực tiếp trong Telegram, tự xác thực thành viên.', 'style' => 'success'],
        ['action' => 'matchmaking', 'label' => '⚔️ HỆ THỐNG GHÉP ĐỐI ĐẤU', 'description' => 'Khu vực ghép trận và đối đầu cộng đồng HENDY.', 'style' => 'danger'],
        ['action' => 'ref', 'label' => '🎊 GIỚI THIỆU REF', 'description' => 'Chia sẻ liên kết giới thiệu và theo dõi hoa hồng.', 'style' => 'primary'],
        ['action' => 'vip', 'label' => '👑 TRUNG TÂM VIP', 'description' => 'Xem cấp VIP, quyền lợi và điểm danh.', 'style' => 'success'],
        ['action' => 'group', 'label' => '👥 NHÓM CHAT ↗', 'description' => 'Mở liên kết nhóm chat HENDY.', 'style' => 'primary'],
        ['action' => 'news', 'label' => '📢 KÊNH THÔNG BÁO ↗', 'description' => 'Mở kênh thông báo HENDY.', 'style' => 'primary'],
        ['action' => 'gift', 'label' => '🎟️ NHẬP GIFTCODE', 'description' => 'Nhập Giftcode để nhận phần thưởng.', 'style' => 'success'],
        ['action' => 'support', 'label' => '🧑🏻‍💻 LIÊN HỆ CSKH ↗', 'description' => 'Gửi yêu cầu và nhận hỗ trợ từ HENDY.', 'style' => 'primary'],
    ];

    public const LAYOUT = [
        ['live', 'tools'],
        ['codes'],
        ['wallet', 'otp'],
        ['viptools'],
        ['customer'],
        ['miniapp'],
        ['matchmaking'],
        ['ref', 'vip'],
        ['group', 'news'],
        ['gift', 'support'],
        ['admin'],
    ];

    // Keep legacy keys accepted by the admin JSON editor for compatibility.
    public const ACTIONS = [
        'live', 'otp', 'wallet', 'vip', 'codes', 'customer',
        'group', 'news', 'vip-coming-soon', 'admin',
        'tools', 'viptools', 'miniapp', 'ref', 'vietsub',
        'history', 'jobs', 'abcvip', 'gift', 'support', 'matchmaking',
    ];
}
