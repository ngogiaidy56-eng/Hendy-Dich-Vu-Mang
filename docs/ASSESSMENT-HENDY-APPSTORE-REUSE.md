# Đánh giá tái sử dụng HendyAppStoreBotTelegram cho Hendy Bot Tele

Ngày kiểm tra: 2026-11-03

## Kết luận

**Có thể tận dụng đáng kể kiến trúc và module của repository này, nhưng không nên nhập nguyên monorepo hoặc chuyển đồng thời toàn bộ backend.** Hướng ít rủi ro nhất là giữ PostgreSQL hiện tại làm nguồn dữ liệu chuẩn cho ví/đơn hàng, tận dụng các mẫu Cloudflare cho Edge/API routing, quản lý cấu hình, feature flags, queue retry/DLQ và frontend. Chỉ di chuyển dữ liệu nghiệp vụ sang D1 sau một dự án migration riêng, có đối soát và cutover rõ ràng.

Repository `HendyAppStoreBotTelegram` hiện là monorepo PNPM với React/Vite, Cloudflare Workers, D1, R2, Queues và Pages. Repository `Hendy-Bot-Tele` hiện dùng PHP 8.3, PostgreSQL, Render, Telegram BOT1/BOT2, Mini App và Admin Dashboard. Đây là hai runtime/kiểu lưu trữ khác nhau.

## Phần có thể tái sử dụng

| Repository A | Tận dụng vào HENDY hiện tại | Cách tích hợp khuyến nghị |
|---|---|---|
| `packages/types`, `packages/config` | Chuẩn hóa kiểu dữ liệu API, bot key, đơn hàng, event và config | Port các interface/schema vào package TypeScript hoặc OpenAPI; PHP vẫn kiểm tra server-side |
| `packages/feature-flags` | Bật/tắt từng tính năng, rollout theo nhóm user | Port logic evaluator; lưu config trong PostgreSQL trước |
| `workers/queue-processor` | Retry backoff, claim job, dead-letter queue, broadcast | Port state machine/job semantics vào PostgreSQL worker hiện tại |
| `workers/edge-router` | Điều phối URL, bảo trì, chuyển hướng, chống cache nhầm | Dùng Cloudflare Worker ở lớp edge trước Render hoặc phục vụ static Pages |
| `apps/web-admin/src/pages/CentralConfig.tsx` | Trung tâm domain, config, secrets, tự động triển khai | Tái dùng UX và allowlist; giữ secret value ở Render/GitHub Environment |
| `apps/mini-app`, `apps/web-admin`, `apps/web-user` | Thiết kế React/Vite, form, shared UI, component tests | Tái dùng từng component/page, không ghi đè giao diện HENDY mới hiện tại |
| `workers/platform-api/src/auth/telegram.js` | Mẫu xác thực raw Telegram initData | Port thuật toán/kiểm thử; phải hỗ trợ BOT1_TOKEN và BOT2_TOKEN riêng |
| `R2 multipart upload` | Upload release/file lớn theo phần, retry/cleanup | Tích hợp sau nếu cần kho phát hành riêng |

## Không nên nhập trực tiếp

1. `workers/platform-api` hiện dùng D1 và `env.BOT_TOKEN` đơn. HENDY hiện có hai bot và PostgreSQL. Nếu copy thẳng, token BOT1/BOT2 sẽ chưa được phân tách và có nguy cơ tạo một nguồn dữ liệu thứ hai.
2. A đang lưu ví, đơn hàng, kho mã và giao dịch trong D1; B đang authoritative trên PostgreSQL. Không được dual-write hai nơi cho số dư thật khi chưa có transaction coordinator/đối soát.
3. Repo A không có mã WebSocket/Durable Object chuyên dụng. `LiveSyncStatusLogs.tsx` chỉ fetch audit một lần khi component mount; không phải live stream.
4. Bot Worker A chỉ có một webhook/token. Chuyển sang hai bot cần refactor dispatcher, webhook secret, username, session và auth claims theo bot key.
5. Các dịch vụ ngân hàng, Vietsub và native signing trong A vẫn cần adapter/credential thật; không được xem module placeholder là tích hợp production.

## Kết quả kiểm tra CI/Deploy của repository A

Commit `34ebbcf47b17626c2f1aee3a4384e6d7665077f8`:

- CI Test: thành công — [workflow run](https://github.com/hendyngo-servers/HendyAppStoreBotTelegram/actions/runs/41628798394).
- Security Checks: thành công.
- Deploy Workers: thất bại tại bước cấu hình vì thiếu `CLOUDFLARE_D1_DATABASE_ID`.
- Deploy Mini App và Deploy Web: build Vite thành công, nhưng deploy Pages thất bại vì `CLOUDFLARE_API_TOKEN` chưa được thiết lập.

Vì thế source A hiện có thể kiểm thử/build trong CI, nhưng chưa có bằng chứng đã deploy production trên Cloudflare. Cần tạo D1/R2/KV/Queues/Pages và cài đúng GitHub Environment secrets/variables theo deployment runbook.

## WebSocket và độ trễ nút bấm

### Trạng thái HENDY PHP hiện tại

Mã nguồn B đã có:
- `app/Realtime.php`: event log PostgreSQL và one-time admin ticket 60 giây.
- `bin/websocket.php`: Ratchet WebSocket server.
- `POST /api/admin/realtime-ticket`: cấp WSS URL + ticket sau khi xác thực Admin.
- Admin UI có WebSocket client và reconnect.

Nhưng tại tài khoản Render lần kiểm tra này chỉ tồn tại Web Service chính `Hendy-Bot-Tele`; service `hendy-bot-tele-realtime` trong Blueprint chưa tồn tại. Vì vậy WSS chưa chạy production.

Các cập nhật code mới:
- Event được ghi vào PostgreSQL và phát `NOTIFY` trong cùng câu lệnh.
- Realtime process `LISTEN` event channel, poll notification không blocking mỗi 100 ms, giữ cursor poll dự phòng 5 giây.
- Cleanup chuyển sang 60 giây; tránh query xoá event mỗi giây.
- Origin allowlist, max connections, application heartbeat 25 giây, idle timeout 90 giây, giới hạn message và reconnect backoff.
- Ticket dùng chung allowlist `ADMIN_ID` + `ADMIN_IDS`.
- Telegram Inline Keyboard ACK ngay; Reply Keyboard hiển thị typing; Mini App vẽ loading/haptic ngay lúc nhấn.

Mục tiêu phía server là đánh thức event loop trong khoảng 100 ms sau PostgreSQL NOTIFY. Đây là thiết kế, chưa phải benchmark p50/p95 thực tế; còn phụ thuộc mạng, Render plan, PostgreSQL và trình duyệt.

### Lộ trình mở WebSocket production

1. Tạo Render Web Service riêng tên `hendy-bot-tele-realtime`, cùng Docker image, region Singapore, command `php bin/websocket.php`, plan luôn chạy.
2. Cấp cùng `DATABASE_URL`, `DATABASE_SSL`, `ADMIN_ID`, `ADMIN_IDS`; đặt `WEBSOCKET_ALLOWED_ORIGINS` theo origin Admin/mini app thật.
3. Đặt `WEBSOCKET_URL=wss://hendy-bot-tele-realtime.onrender.com` trong main Web Service.
4. Deploy, quan sát log LISTEN/NOTIFY, kết nối Admin bằng ticket, xác minh `realtime.ready`, publish một event test, quan sát client nhận event và reconnect.
5. Chỉ sau khi có tải thật mới cân nhắc nhiều instance/Redis PubSub hoặc Cloudflare Durable Objects.

Việc tạo service WebSocket riêng trên Render có thể phát sinh phí; source đã khai báo service nhưng chưa tự tạo trong tài khoản.

## Kiến trúc mục tiêu đề xuất

- **Cloudflare Edge Worker:** WAF/rate limiting/routing/static cache; không làm nguồn dữ liệu ví.
- **HENDY PHP API + PostgreSQL:** tiếp tục là nguồn chuẩn cho tài khoản, ví, giao dịch, đơn, menu và trạng thái hai bot.
- **Render realtime service:** WebSocket Admin dùng PostgreSQL NOTIFY; không xử lý request nghiệp vụ nặng.
- **Queue worker:** Vietsub, broadcast, tác vụ nền, retry/DLQ; HTTP/Telegram nhận xác nhận nhanh rồi đọc trạng thái qua job ID.
- **Cloudflare Pages/React:** chỉ tách frontend khi có nhu cầu quản lý build độc lập; có thể tái dùng UI của repo A nhưng phải giữ contract/API bot-key riêng.
- **D1:** chỉ dùng cho một sản phẩm/dịch vụ mới độc lập, hoặc sau khi có kế hoạch migration hoàn chỉnh; không tạo bản sao ví/đơn hàng đang chạy.

## Tiêu chí đo trước khi tuyên bố tối ưu

Theo dõi ít nhất:
- Thời gian Telegram `answerCallbackQuery` và thời gian phản hồi nghiệp vụ.
- Mini App click-to-first-feedback, click-to-API-response.
- WebSocket event publish-to-client.
- p50/p95 latency theo endpoint, tỉ lệ timeout/retry, reconnect count, active connection count.
- PostgreSQL query latency, connection pool saturation, slow query và CPU/RAM.

Chỉ dùng số liệu đo thật để đặt SLA; hiện chưa có phép đo production p50/p95 được ghi nhận.
