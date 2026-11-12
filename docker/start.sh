#!/usr/bin/env bash
set -euo pipefail

cd /var/www/app

# Render terminates TLS and forwards all public HTTP/WebSocket traffic to PORT.
port="${PORT:-10000}"
if ! [[ "$port" =~ ^[0-9]{1,5}$ ]] || (( port < 1 || port > 65535 )); then
    echo "PORT phải là số nguyên từ 1 đến 65535." >&2
    exit 1
fi

sed -i -E "s/^Listen [0-9]+$/Listen ${port}/" /etc/apache2/ports.conf
sed -i "s/:10000>/:${port}>/" /etc/apache2/sites-available/000-default.conf

mkdir -p storage/modules
chown -R www-data:www-data storage
chmod 750 storage storage/modules

php bin/validate-config.php
php bin/wait-for-database.php
if [[ "${RUN_MIGRATIONS_ON_START:-true}" == "true" ]]; then
    php bin/migrate.php
else
    echo "RUN_MIGRATIONS_ON_START=false; bỏ qua migration lúc khởi động."
fi

# Telegram webhook registration failure must not prevent the website from starting.
if ! php bin/register-webhooks.php; then
    echo "CẢNH BÁO: Không thể đăng ký webhook lúc khởi động; website vẫn sẽ được chạy." >&2
fi

if [[ "${WEBSOCKET_ENABLED:-false}" == "true" ]]; then
    if [[ -z "${WEBSOCKET_ALLOWED_ORIGINS:-}" ]]; then
        echo "WEBSOCKET_ALLOWED_ORIGINS bắt buộc khi bật WebSocket." >&2
        exit 1
    fi
    php bin/websocket.php &
    websocket_pid=$!
    apache2-foreground &
    apache_pid=$!

    cleanup() {
        kill "$websocket_pid" "$apache_pid" 2>/dev/null || true
        wait "$websocket_pid" "$apache_pid" 2>/dev/null || true
    }
    trap cleanup EXIT INT TERM

    echo "HENDY website + WebSocket started; Apache port=$port, internal WS port=${WEBSOCKET_PORT:-10001}."
    # Restart the Render service if either essential process exits unexpectedly.
    set +e
    wait -n "$websocket_pid" "$apache_pid"
    status=$?
    set -e
    echo "Một tiến trình HENDY đã dừng (exit=$status); kết thúc container để Render khởi động lại." >&2
    exit "$status"
fi

exec apache2-foreground
