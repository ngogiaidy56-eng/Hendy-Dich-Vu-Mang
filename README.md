# Hendy Dịch Vụ Mạng — Neon Edition

Website PHP 8.3 cho danh mục dịch vụ, tài khoản thành viên và trang quản trị. Backend dùng Neon PostgreSQL, Neon Object Storage (S3), Neon Functions và Neon Realtime; Render có thể tiếp tục chạy PHP container.

## 1. Tích hợp Neon đã khai báo trong mã nguồn

- **PostgreSQL:** PDO `pdo_pgsql`, pooled `DATABASE_URL` cho request ứng dụng; `DATABASE_URL_UNPOOLED` dành cho migration/schema.
- **Object Storage:** bucket `hendy-screenshots` ở chế độ `public_read`; PHP gửi PUT/DELETE theo AWS Signature V4 bằng các biến `AWS_*`. Cần cài PHP cURL. Khi chưa cấu hình credentials, ứng dụng dùng `uploads/screenshots/` làm phương án local dự phòng.
- **Functions:** function slug `api`, source `functions/api.ts`; `/health` kiểm tra PostgreSQL và `/services/live` trả sealed query chỉ gồm danh mục dịch vụ đang public.
- **Realtime:** `preview.realtime: true`; trang chủ lấy sealed query từ Function và subscribe bằng `@neon/realtime/client`. Chỉ `NEON_REALTIME_URL` được đưa xuống trình duyệt; không công khai `NEON_REALTIME_SECRET`.

Cấu hình `.neon` ban đầu trỏ đến org `org-silent-waterfall-43882566`, project `calm-forest-79251169`, branch `production`. File này đã được thêm vào `.gitignore`.

## 2. Provision Neon

Yêu cầu Node.js 20.19+ (khuyến nghị Node.js 22 LTS mới nhất, tối thiểu 22.20+ để dùng đầy đủ Neon agent skills) và tài khoản Neon có quyền với project đã chỉ định.

```bash
npm i -g neon@latest
neon init -y --org-id org-silent-waterfall-43882566 --project-id calm-forest-79251169 --branch production --services postgres,object-storage,functions,realtime
neon config plan
npm run neon:deploy
```

Nếu cài global thất bại, thay `neon` bằng `npx --yes neon@latest`. Nếu CLI mở trình duyệt đăng nhập, hoàn tất đăng nhập trong trình duyệt. Nếu đăng nhập timeout/thất bại, chạy `neon login` rồi thử lại.

> Neon Object Storage, Functions và Realtime chỉ khả dụng ở một số region. Kiểm tra region của project trước khi deploy.

## 3. Cài dependencies và build

```bash
npm install
npm run build
```

Build kiểm tra cú pháp PHP, JavaScript và cấu hình/schema. `neon deploy` là bước validate và deploy thật đối với `neon.ts` cùng Function TypeScript.

## 4. Áp dụng schema PostgreSQL

`neon deploy` provision các dịch vụ nhưng không tự chạy `database.sql`. Sau khi deploy và lấy biến môi trường, áp dụng schema bằng direct/unpooled connection:

```bash
if [ -f .env ]; then ENV_FILE=.env; else ENV_FILE=.env.local; fi
set -a
. "$ENV_FILE"
set +a
psql "$DATABASE_URL_UNPOOLED" -v ON_ERROR_STOP=1 -f database.sql
```

Tạo tài khoản trên website trước, sau đó cấp quyền quản trị cho email đã xác minh:

```sql
UPDATE users SET is_admin = TRUE WHERE email = 'admin@example.com';
```

## 5. Chạy ứng dụng

Neon CLI ghi biến vào `.env` nếu file này đã tồn tại, nếu không ghi vào `.env.local`. Sao chép `.env.example` thành `.env.local` chỉ khi cần khai báo thêm cấu hình; không ghi đè credentials do Neon CLI cấp.

```bash
npm start
```

Mở `http://localhost:8080/`. Health checks: `/healthz.php` và `/readyz.php`.

## 6. Kiểm thử end-to-end

Sau khi `npm install`, `neon deploy`, áp dụng schema và cấu hình đủ env vars:

```bash
npm run smoke:neon
```

Smoke test kiểm tra PostgreSQL, Object Storage PUT/GET/DELETE, Neon Function và Realtime notification. Nó tạo rồi dọn object thử nghiệm cùng bản ghi service tạm.

## 7. Render Docker Web Service

- Runtime: Docker, Dockerfile ở repository root.
- Health Check Path: `/healthz.php`.
- Khai báo trong Render các biến Neon cần thiết, tối thiểu `DATABASE_URL`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_ENDPOINT_URL_S3`, `AWS_REGION`, `NEON_BRANCH_ID`, `NEON_FUNCTION_HOST_SUFFIX`, `NEON_REALTIME_URL`.
- Không đưa `NEON_REALTIME_SECRET` xuống trình duyệt.
- Khi dùng local upload fallback, cần Persistent Disk tại `/var/www/html/uploads`; Object Storage giúp tránh phụ thuộc filesystem ephemeral.

## 8. File chính

| File | Vai trò |
|---|---|
| `includes/db.php` | Env loader, PDO PostgreSQL, session/CSRF, Neon S3 helpers |
| `database.sql` | PostgreSQL schema + trigger update timestamp |
| `neon.ts` | Neon Object Storage, Function API, Realtime configuration |
| `functions/api.ts` | Function health và sealed live catalog query |
| `assets/neon-realtime.js` | Browser Realtime subscription |
| `healthz.php` / `readyz.php` | Liveness/readiness endpoints |
| `scripts/smoke-neon.mjs` | End-to-end checks cho Neon services |

## 9. Security

- `.env`, `.env.local`, `.neon` và `node_modules/` không được commit.
- Dùng pooled connection cho traffic web, unpooled connection cho migration.
- `NEON_REALTIME_SECRET` không gửi xuống trình duyệt; sealed query chỉ chứa trường dịch vụ công khai.
- Bucket `public_read` đồng nghĩa ảnh có thể được đọc công khai; không tải ảnh riêng tư hoặc dữ liệu nhạy cảm lên bucket này.
- PHP kiểm tra MIME, giới hạn ảnh 5 MB mỗi file và tối đa 8 ảnh mỗi lần tải.
