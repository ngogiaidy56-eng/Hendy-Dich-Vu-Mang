# HENDY DỊCH VỤ MẠNG — Telegram Bot + Mini App + App Store

Monorepo PHP 8.3 / Apache chạy trên Render Docker. Mã nguồn Hendy-Bot-Tele được tích hợp với App Store PHP hiện tại, giữ phần App Store và tách riêng các kết nối dữ liệu.

## Thành phần và đường dẫn
- Website giới thiệu: /
- Telegram Mini App và trung tâm khách hàng: /miniapp, /customer, /customer-center
- Admin Dashboard: /admin
- API: /api/v1/* (và các route /api/*)
- Telegram webhook: /webhook/telegram và /webhook/telegram/secondary
- App Store PHP/MySQL cũ: /app_store/
- Dịch vụ mạng App Store: /app_store/dichvumang.php

## Hai database độc lập

### MySQL — App Store
Thiết lập DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS và DB_CHARSET trong Render. Tạo database MySQL, sau đó import app_store/database.sql. Các bảng users/apps/screenshots/services trong MySQL chỉ thuộc App Store.

### PostgreSQL — Telegram Bot
PostgreSQL là nguồn dữ liệu chuẩn cho Telegram user, ví, giao dịch, đơn hàng, referral, VIP, voucher, giftcode, menu và realtime. Thiết lập DATABASE_URL (URI PostgreSQL) và DATABASE_SSL. Schema database/schema.sql được cập nhật bằng php bin/migrate.php.

Không trộn schema hai hệ. Không dual-write số dư hoặc đơn hàng sang MySQL App Store. Muốn chia sẻ hồ sơ/nghiệp vụ giữa hai hệ phải thiết kế API và migration riêng, có backup và đối soát.

## Bật Telegram runtime
App Store/MySQL có thể hoạt động độc lập. Để bật bot, đặt TELEGRAM_RUNTIME_ENABLED=true, DATABASE_URL hợp lệ, BOT1_TOKEN, ít nhất một ADMIN_ID hoặc ADMIN_IDS, APP_URL=https://hendy-dich-vu-mang.onrender.com và WEBHOOK_AUTO_REGISTER=true. BOT2_TOKEN là tùy chọn cho bot thử nghiệm.

Webhook secret có thể để trống để backend sinh và lưu secret ngẫu nhiên trong PostgreSQL. Không commit token, mật khẩu hoặc secret. BANK_WEBHOOK_ENABLED phải giữ false cho tới khi adapter chữ ký nhà cung cấp được triển khai và kiểm thử.

WebSocket mặc định tắt; chỉ bật WEBSOCKET_ENABLED=true sau khi PostgreSQL, WSS URL và origin allowlist đã sẵn sàng. Nếu thiếu DATABASE_URL, Telegram runtime không được bật; website và App Store vẫn có thể chạy.

## Render
- Runtime: Docker
- Dockerfile: ./Dockerfile
- Docker Context: .
- Branch: main
- Health check: /healthz
- Port: Render injects PORT; script khởi động đồng bộ Apache theo PORT.

render.yaml chỉ mô tả một Web Service, không tự tạo hoặc thay thế MySQL/PostgreSQL hiện có. Cấu hình các biến DB_* cho MySQL và DATABASE_URL cho PostgreSQL trong Render Environment.

## Kiểm tra
composer install
composer lint
composer test
php scripts/audit-env.php --check
php bin/validate-config.php

## Lưu trữ upload
Ảnh App Store nằm trong app_store/uploads/screenshots/ và module artifacts trong storage/modules/. Filesystem Render không bền qua deploy/restart nếu không có persistent disk; production nên dùng object storage cho file cần lưu lâu dài.
