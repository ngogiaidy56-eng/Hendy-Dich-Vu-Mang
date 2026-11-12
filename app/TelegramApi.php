<?php
declare(strict_types=1);

namespace Hendy;

use RuntimeException;

final class TelegramApi
{
    public function __construct(private readonly string $token)
    {
        if (!preg_match('/\A[0-9]{5,12}:[A-Za-z0-9_-]{30,}\z/D', $token)) {
            throw new RuntimeException('Token Telegram chưa được cấu hình hoặc không đúng định dạng.');
        }
    }

    public function call(
        string $method,
        array $parameters = [],
        int $timeoutSeconds = 35,
        int $connectTimeoutSeconds = 8
    ): array {
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9]{1,63}\z/D', $method)) {
            throw new RuntimeException('Tên phương thức Telegram API không hợp lệ.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP extension curl chưa được cài đặt.');
        }

        $body = json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $handle = curl_init('https://api.telegram.org/bot' . $this->token . '/' . $method);
        if ($handle === false) {
            throw new RuntimeException('Không thể khởi tạo kết nối Telegram.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => max(1, min(15, $connectTimeoutSeconds)),
            CURLOPT_TIMEOUT => max(2, min(60, $timeoutSeconds)),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_USERAGENT => 'HendyBot-Cybertech/2.0',
        ]);

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $curlError = curl_error($handle);
        curl_close($handle);

        if ($response === false) {
            throw new RuntimeException('Kết nối Telegram thất bại: ' . ($curlError !== '' ? $curlError : 'network error'));
        }
        if (strlen((string) $response) > 5_242_880) {
            throw new RuntimeException('Phản hồi Telegram vượt quá giới hạn cho phép.');
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded) || !($decoded['ok'] ?? false) || $status < 200 || $status >= 300) {
            $description = is_array($decoded)
                ? (string) ($decoded['description'] ?? 'Telegram API trả về lỗi.')
                : 'Phản hồi Telegram không hợp lệ.';
            $errorCode = is_array($decoded) ? (int) ($decoded['error_code'] ?? $status) : $status;
            throw new RuntimeException('Telegram API lỗi ' . $errorCode . ': ' . mb_substr($description, 0, 300));
        }

        return is_array($decoded['result'] ?? null)
            ? $decoded['result']
            : ['value' => $decoded['result'] ?? null];
    }

    public function sendMessage(string $chatId, string $text, array $options = []): array
    {
        return $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            ...$options,
        ]);
    }

    public function sendPhoto(string $chatId, string $photo, string $caption, array $options = []): array
    {
        return $this->call('sendPhoto', [
            'chat_id' => $chatId,
            'photo' => $photo,
            'caption' => $caption,
            'parse_mode' => 'HTML',
            ...$options,
        ]);
    }

    public function answerCallback(string $callbackId, string $text = ''): void
    {
        $this->call('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => $text,
            'show_alert' => false,
        ], 4, 2);
    }

    public function sendChatAction(string $chatId, string $action = 'typing'): void
    {
        $allowed = ['typing', 'upload_photo', 'upload_document', 'record_video', 'upload_video'];
        if (!in_array($action, $allowed, true)) {
            $action = 'typing';
        }
        $this->call('sendChatAction', ['chat_id' => $chatId, 'action' => $action], 2, 1);
    }

    public function setWebhook(string $url, string $secret): void
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || !preg_match('/\A[A-Za-z0-9_-]{32,256}\z/D', $secret)) {
            throw new RuntimeException('HTTPS webhook URL hoặc webhook secret không hợp lệ.');
        }

        $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'callback_query'],
            'drop_pending_updates' => false,
        ]);
    }

    public function deleteWebhook(): void
    {
        $this->call('deleteWebhook', ['drop_pending_updates' => false]);
    }

    public function getUpdates(int $offset = 0, int $timeout = 20): array
    {
        $result = $this->call('getUpdates', [
            'offset' => $offset,
            'timeout' => $timeout,
            'allowed_updates' => ['message', 'callback_query'],
        ]);
        return array_is_list($result) ? $result : [];
    }

}
