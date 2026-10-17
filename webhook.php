<?php
declare(strict_types=1);

/**
 * MediaBot — Main Webhook Entry Point
 * Receives Telegram updates and dispatches them to BotHandler.
 * Must respond within 5 seconds (Telegram requirement).
 */

define('BOT_ROOT', __DIR__);
define('REQUEST_START', microtime(true));

require_once BOT_ROOT . '/src/autoload.php';

use MediaBot\Database\DatabaseManager;
use MediaBot\Queue\QueueManager;
use MediaBot\Cache\CacheManager;
use MediaBot\Downloader\DownloaderEngine;
use MediaBot\Downloader\PlatformDetector;
use MediaBot\Bot\TelegramAPI;
use MediaBot\Bot\BotHandler;
use MediaBot\Bot\Logger;
use MediaBot\I18n\Translator;

// ─── Security: Only accept POST from Telegram ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// Optional: Validate secret token header
$config = require BOT_ROOT . '/config/config.php';

// ─── Read Update ─────────────────────────────────────────────────────────────
$rawInput = file_get_contents('php://input');
if (empty($rawInput)) {
    http_response_code(400);
    exit;
}

$update = json_decode($rawInput, true);
if (!$update || !is_array($update)) {
    http_response_code(400);
    exit;
}

// Respond 200 OK to Telegram immediately (prevents retries)
http_response_code(200);
header('Content-Type: application/json');
echo '{"ok":true}';

// Flush output buffer so Telegram gets the response
if (ob_get_level()) ob_end_flush();
flush();

// ─── Close connection (allows us to process without timeout) ─────────────────
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// ─── Now process the update (background) ─────────────────────────────────────
try {
    // Init logger
    Logger::init($config['logging']);

    // Init database
    $db = DatabaseManager::getInstance($config['database']);

    // Init services
    $queue    = new QueueManager($db, $config['queue']);
    $cache    = new CacheManager($db, $config['cache']);
    $engine   = new DownloaderEngine($config['downloader'], $cache);
    $detector = new PlatformDetector($config['platforms']);
    $api      = new TelegramAPI($config['telegram']);
    $i18n     = Translator::getInstance($config['i18n']);

    // Handle update
    $handler = new BotHandler($api, $db, $queue, $cache, $detector, $i18n, $config);
    $handler->handle($update);

    Logger::debug("Update processed in " . round((microtime(true) - REQUEST_START) * 1000, 1) . "ms");

} catch (\Throwable $e) {
    Logger::error("Webhook fatal: " . $e->getMessage(), [
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
    ]);
}
