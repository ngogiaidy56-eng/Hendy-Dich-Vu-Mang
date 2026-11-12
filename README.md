# Hendy App Store & Dịch vụ mạng

PHP 8.3 + Apache + MySQL 8 (PDO). Bao gồm kho ứng dụng/trò chơi, tìm kiếm/lọc, trang chi tiết và ảnh chụp màn hình, đăng ký/đăng nhập, dịch vụ mạng và admin panel.

## Cấu trúc
Mã nguồn web nằm trong `app_store/`; Dockerfile ở root cấu hình Apache DocumentRoot tới thư mục này.

## Chạy local
1. Tạo MySQL database và import `app_store/database.sql`.
2. Thiết lập biến môi trường theo `.env.example`.
3. Build và chạy: `docker build -t hendy-app-store .` rồi `docker run --env-file .env -p 8080:80 hendy-app-store`.
4. Đăng ký tài khoản, sau đó cấp quyền admin bằng SQL: `UPDATE users SET role='admin' WHERE email='you@example.com';`.

## Render
Web Service: Runtime Docker, Dockerfile Path `./Dockerfile`, Docker Context `.`, branch `main`. Thêm DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, APP_URL trong Environment. Dùng MySQL 8 tương thích và import schema trước khi truy cập ứng dụng.

## Lưu ý production
Không commit file .env hoặc mật khẩu thật. Upload screenshot hiện lưu trong filesystem container; filesystem Render Free không bền qua deploy/restart. Dùng object storage cho ảnh nếu cần lưu bền vững. Đăng ký tài khoản đầu tiên không tự cấp admin; hãy nâng quyền bằng SQL sau khi đăng ký.