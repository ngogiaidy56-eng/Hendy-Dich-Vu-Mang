#!/usr/bin/env bash
set -euo pipefail
cd /var/www/app

port="${PORT:-10000}"
if ! [[ "$port" =~ ^[0-9]{1,5}$ ]] || (( port < 1 || port > 65535 )); then
    echo "PORT phải là số nguyên từ 1 đến 65535." >&2
    exit 1
fi

sed -i -E "s/^Listen [0-9]+$/Listen ${port}/" /etc/apache2/ports.conf
sed -i "s/:10000>/: ${port}>/" /etc/apache2/sites-available/000-default.conf

mkdir -p storage/modules app_store/uploads/screenshots
chown -R www-data:www-data storage app_store/uploads
chmod 750 storage storage/modules
chmod -R 755 app_store/uploads

php bin/validate-config.php

if [[ -n "${DATABASE_URL:-}" ]]; then
    php bin/wait-for-database.php
    if [[ "${RUN_MIGRATIONS_ON_START:-true}" == "true" ]]; then
        php bin/migrate.php
    else
        echo "RUN_MIGRATIONS_ON_START=false; bỏ qua PostgreSQL migration."
    fi

    if [[ "${TELEGRAM_RUNTIME_ENABLED:-false}" == "true" && "${WEBHOOK_AUTO_REGISTER:-false}" == "true" ]]; then
        if ! php bin/register-webhooks.php; then
            echo "CẢNH BÁO: không thể đăng ký Telegram webhook; Apache vẫn tiếp tục khởi động." >&2
        fi
    else
        echo "Telegram runtime/webhook chưa được bật; bỏ qua tự đăng ký webhook."
    fi
else
    if [[ "${TELEGRAM_RUNTIME_ENABLED:-false}" == "true" ]]; then
        echo "TELEGRAM_RUNTIME_ENABLED=true nhưng thiếu DATABASE_URL PostgreSQL." >&2
        exit 1
    fi
    echo "DATABASE_URL chưa cấu hình: bỏ qua PostgreSQL migration và Telegram webhook. App Store MySQL chạy độc lập."
fi

if [[ "${WEBSOCKET_ENABLED:-false}" == "true" ]]; then
    if [[ -z "${DATABASE_URL:-}" ]]; then
        echo "WEBSOCKET_ENABLED=true yêu cầu DATABASE_URL PostgreSQL." >&2
        exit 1
    fi
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
    set +e
    wait -n "$websocket_pid" "$apache_pid"
    status=$?
    set -e
    echo "Một tiến trình HENDY đã dừng (exit=$status); kết thúc container để Render khởi động lại." >&2
    exit "$status"
fi

exec apache2-foreground
