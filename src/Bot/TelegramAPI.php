<?php
declare(strict_types=1);

namespace MediaBot\Bot;

/**
 * TelegramAPI — async cURL-based Telegram Bot API client.
 * Supports fire-and-forget (non-blocking) and blocking requests.
 */
class TelegramAPI
{
    private string $token;
    private string $baseUrl;
    private int $timeout;
    private array $curlOptions;

    public function __construct(array $config)
    {
        $this->token   = $config['bot_token'];
        $this->baseUrl = $config['api_base'] . $config['bot_token'];
        $this->timeout = $config['timeout'];

        $this->curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_USERAGENT      => 'MediaBot/2.0 (PHP/8.x)',
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ];
    }

    // ─── Core Request Methods ───────────────────────────────────────────────

    public function request(string $method, array $params = [], bool $async = false): ?array
    {
        $url  = "{$this->baseUrl}/{$method}";
        $json = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        curl_setopt_array($ch, $this->curlOptions + [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => $json,
        ]);

        if ($async) {
            // Non-blocking: set very low timeout, don't wait for response
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, 500);
            curl_exec($ch);
            curl_close($ch);
            return null;
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException("cURL error for {$method}: {$error}");
        }

        $data = json_decode($response, true);

        if (!$data || !$data['ok']) {
            $errDesc = $data['description'] ?? 'Unknown Telegram error';
            $errCode = $data['error_code'] ?? $httpCode;
            throw new \RuntimeException("Telegram API error [{$errCode}]: {$errDesc}");
        }

        return $data['result'];
    }

    // Multi-request with cURL multi handle (async batch)
    public function requestBatch(array $requests): array
    {
        $mh      = curl_multi_init();
        $handles = [];
        $results = [];

        foreach ($requests as $key => ['method' => $method, 'params' => $params]) {
            $url  = "{$this->baseUrl}/{$method}";
            $json = json_encode($params, JSON_UNESCAPED_UNICODE);
            $ch   = curl_init($url);
            curl_setopt_array($ch, $this->curlOptions + [
                CURLOPT_POST       => true,
                CURLOPT_POSTFIELDS => $json,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh);
        } while ($running > 0);

        foreach ($handles as $key => $ch) {
            $response      = curl_multi_getcontent($ch);
            $data          = json_decode($response, true);
            $results[$key] = ($data && $data['ok']) ? $data['result'] : null;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);
        return $results;
    }

    // ─── Send Methods ───────────────────────────────────────────────────────

    public function sendMessage(int|string $chatId, string $text, array $extra = []): ?array
    {
        return $this->request('sendMessage', array_merge([
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
        ], $extra));
    }

    public function editMessageText(int|string $chatId, int $messageId, string $text, array $extra = []): ?array
    {
        return $this->request('editMessageText', array_merge([
            'chat_id'                  => $chatId,
            'message_id'               => $messageId,
            'text'                     => $text,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
        ], $extra));
    }

    public function deleteMessage(int|string $chatId, int $messageId): ?array
    {
        return $this->request('deleteMessage', [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
        ]);
    }

    public function sendVideo(int|string $chatId, string $fileIdOrPath, array $extra = []): ?array
    {
        $params = array_merge([
            'chat_id'   => $chatId,
            'video'     => $fileIdOrPath,
            'parse_mode'=> 'HTML',
        ], $extra);

        // If it's a file path, use multipart upload
        if (file_exists($fileIdOrPath)) {
            return $this->uploadFile('sendVideo', $chatId, 'video', $fileIdOrPath, $extra);
        }

        return $this->request('sendVideo', $params);
    }

    public function sendAudio(int|string $chatId, string $fileIdOrPath, array $extra = []): ?array
    {
        if (file_exists($fileIdOrPath)) {
            return $this->uploadFile('sendAudio', $chatId, 'audio', $fileIdOrPath, $extra);
        }
        return $this->request('sendAudio', array_merge(['chat_id' => $chatId, 'audio' => $fileIdOrPath], $extra));
    }

    public function sendDocument(int|string $chatId, string $fileIdOrPath, array $extra = []): ?array
    {
        if (file_exists($fileIdOrPath)) {
            return $this->uploadFile('sendDocument', $chatId, 'document', $fileIdOrPath, $extra);
        }
        return $this->request('sendDocument', array_merge(['chat_id' => $chatId, 'document' => $fileIdOrPath], $extra));
    }

    public function sendChatAction(int|string $chatId, string $action = 'upload_video'): void
    {
        $this->request('sendChatAction', ['chat_id' => $chatId, 'action' => $action], true);
    }

    public function forwardFile(int|string $chatId, string $fileId, string $type, array $extra = []): ?array
    {
        $methodMap = [
            'video'    => 'sendVideo',
            'audio'    => 'sendAudio',
            'document' => 'sendDocument',
            'photo'    => 'sendPhoto',
            'animation'=> 'sendAnimation',
        ];
        $method = $methodMap[$type] ?? 'sendDocument';
        $key    = ($type === 'document') ? 'document' : $type;

        return $this->request($method, array_merge(['chat_id' => $chatId, $key => $fileId], $extra));
    }

    public function answerCallbackQuery(string $callbackId, string $text = '', bool $showAlert = false): ?array
    {
        return $this->request('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text'              => $text,
            'show_alert'        => $showAlert,
        ]);
    }

    public function setWebhook(string $url, array $extra = []): ?array
    {
        return $this->request('setWebhook', array_merge(['url' => $url], $extra));
    }

    public function deleteWebhook(): ?array
    {
        return $this->request('deleteWebhook');
    }

    public function getMe(): ?array
    {
        return $this->request('getMe');
    }

    public function getWebhookInfo(): ?array
    {
        return $this->request('getWebhookInfo');
    }

    public function getUpdates(int $offset = 0, int $limit = 100, int $timeout = 0): ?array
    {
        return $this->request('getUpdates', [
            'offset'  => $offset,
            'limit'   => $limit,
            'timeout' => $timeout,
        ]);
    }

    public function sendPhoto(int|string $chatId, string $fileIdOrPath, array $extra = []): ?array
    {
        return $this->request('sendPhoto', array_merge(['chat_id' => $chatId, 'photo' => $fileIdOrPath, 'parse_mode' => 'HTML'], $extra));
    }

    // ─── File Upload (multipart/form-data) ─────────────────────────────────

    private function uploadFile(string $method, int|string $chatId, string $field, string $filePath, array $extra = []): ?array
    {
        $url = "{$this->baseUrl}/{$method}";

        $postFields = array_merge([
            'chat_id'    => $chatId,
            'parse_mode' => 'HTML',
            $field       => new \CURLFile($filePath, $this->getMimeType($filePath), basename($filePath)),
        ], $extra);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 300, // 5 min for file uploads
            CURLOPT_USERAGENT      => 'MediaBot/2.0',
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) throw new \RuntimeException("Upload cURL error: {$error}");

        $data = json_decode($response, true);
        if (!$data || !$data['ok']) {
            throw new \RuntimeException("Telegram upload error: " . ($data['description'] ?? 'Unknown'));
        }

        return $data['result'];
    }

    private function getMimeType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match($ext) {
            'mp4', 'mkv', 'webm' => 'video/mp4',
            'mp3'                => 'audio/mpeg',
            'ogg'                => 'audio/ogg',
            'm4a'                => 'audio/mp4',
            'jpg', 'jpeg'        => 'image/jpeg',
            'png'                => 'image/png',
            'gif'                => 'image/gif',
            'pdf'                => 'application/pdf',
            default              => 'application/octet-stream',
        };
    }

    // ─── Keyboard Helpers ───────────────────────────────────────────────────

    public static function inlineKeyboard(array $buttons): array
    {
        return ['inline_keyboard' => $buttons];
    }

    public static function replyKeyboard(array $buttons, bool $resize = true): array
    {
        return [
            'keyboard'        => $buttons,
            'resize_keyboard' => $resize,
            'one_time_keyboard'=> false,
        ];
    }

    public static function removeKeyboard(): array
    {
        return ['remove_keyboard' => true];
    }
}
