<?php
declare(strict_types=1);

/**
 * MediaBot — Main Polling Entry Point
 * Pulls updates using getUpdates and dispatches them to BotHandler.
 */

define('BOT_ROOT', __DIR__);

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

// ─── Config ────────────────────────────────────────────────────────────────
$config = require BOT_ROOT . '/config/config.php';

// Init Logger
Logger::init($config['logging']);

// Init Database
$db = DatabaseManager::getInstance($config['database']);

// Init Services
$queue    = new QueueManager($db, $config['queue']);
$cache    = new CacheManager($db, $config['cache']);
$engine   = new DownloaderEngine($config['downloader'], $cache);
$detector = new PlatformDetector($config['platforms']);
$api      = new TelegramAPI($config['telegram']);
$i18n     = Translator::getInstance($config['i18n']);

$handler = new BotHandler($api, $db, $queue, $cache, $detector, $i18n, $config);

// ─── Pre-flight ────────────────────────────────────────────────────────────

// Clear existing webhook to enable polling
try {
    $api->deleteWebhook();
    Logger::info("Webhook deleted, starting polling mode.");
    echo "Webhook deleted, starting polling mode.\n";
} catch (\Throwable $e) {
    Logger::error("Failed to delete webhook: " . $e->getMessage());
    exit(1);
}

// Ensure the worker is running in the background for async downloads
$workerScript = BOT_ROOT . '/worker/worker.php';
$workerLog = BOT_ROOT . '/storage/logs/worker.log';
// We redirect output and run it in background if not already running
$isWorkerRunning = (int)shell_exec("ps aux | grep worker.php | grep -v grep | wc -l");
if ($isWorkerRunning == 0) {
    $cmd = "php " . escapeshellarg($workerScript) . " --daemon >> " . escapeshellarg($workerLog) . " 2>&1 &";
    exec($cmd);
    Logger::info("Started worker in background.");
    echo "Started background worker.\n";
} else {
    echo "Worker is already running.\n";
}

// ─── Polling Loop ──────────────────────────────────────────────────────────

$offset = 0;
echo "Starting polling loop. Press Ctrl+C to stop.\n";

while (true) {
    try {
        $updates = $api->getUpdates($offset, 100, 30); // 30s timeout for long polling

        if ($updates && is_array($updates)) {
            foreach ($updates as $update) {
                $offset = $update['update_id'] + 1;

                // Process the update via BotHandler
                try {
                    $handler->handle($update);
                } catch (\Throwable $e) {
                    Logger::error("Failed to handle update {$update['update_id']}: " . $e->getMessage());
                }
            }
        }
    } catch (\Throwable $e) {
        Logger::error("Polling error: " . $e->getMessage());
        sleep(2); // Pause briefly on error before retrying
    }
}
