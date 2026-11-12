# Phân tách dữ liệu MySQL App Store và PostgreSQL Telegram Bot

Repository là monorepo nhưng không dùng chung bảng hoặc ghi kép giữa hai hệ quản trị.

| Kết nối | Biến môi trường | Phạm vi dữ liệu |
|---|---|---|
| MySQL App Store | DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS | app_store: tài khoản email, apps, screenshots, services |
| PostgreSQL Telegram | DATABASE_URL, DATABASE_SSL | Telegram user_id, ví, giao dịch, orders, referrals, VIP, voucher, giftcode, menu, realtime |

## Quy tắc
- Không trỏ DB_NAME của App Store vào PostgreSQL; driver và schema khác nhau.
- Không tạo bản sao users/orders/transactions trong MySQL để giả lập dữ liệu nghiệp vụ Telegram.
- Hai ứng dụng chạy độc lập: App Store cần MySQL; Bot chỉ bật khi TELEGRAM_RUNTIME_ENABLED=true, có DATABASE_URL, BOT1_TOKEN và Admin ID.
- Không ghi số dư, nạp tiền hoặc đơn hàng vào cả hai database. Muốn chia sẻ hồ sơ cần API và ID mapping được xác minh.
- Migration từ MySQL cũ phải backup, map Telegram ID sang TEXT, đối soát ledger/giao dịch và chặn nhập trùng trước khi cutover.

## Health và deploy
/healthz kiểm tra mọi database được khai báo mà không tiết lộ credentials. Nếu không có database nào, endpoint trả trạng thái degraded (HTTP 200) để website vẫn khởi động. Nếu database đã khai báo nhưng kết nối thất bại, endpoint trả HTTP 503.
