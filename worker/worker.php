#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * MediaBot Worker — Background queue processor.
 *
 * Run via cron every minute:
 *   * * * * * /usr/bin/php /path/to/mediabot/worker/worker.php >> /dev/null 2>&1
 *
 * Or run continuously with supervisor:
 *   php worker.php --daemon
 */

// ─── Bootstrap ──────────────────────────────────────────────────────────────

define('WORKER_ROOT', dirname(__DIR__));
define('WORKER_START', microtime(true));

require_once WORKER_ROOT . '/src/autoload.php';

use MediaBot\Database\DatabaseManager;
use MediaBot\Queue\QueueManager;
use MediaBot\Cache\CacheManager;
use MediaBot\Downloader\DownloaderEngine;
use MediaBot\Downloader\PlatformDetector;
use MediaBot\Bot\TelegramAPI;
use MediaBot\Bot\Logger;

$config = require WORKER_ROOT . '/config/config.php';

// Init logger
Logger::init($config['logging']);
Logger::info("Worker started", ['pid' => getmypid(), 'time' => date('Y-m-d H:i:s')]);

// Init services
$db       = DatabaseManager::getInstance($config['database']);
$queue    = new QueueManager($db, $config['queue']);
$cache    = new CacheManager($db, $config['cache']);
$engine   = new DownloaderEngine($config['downloader'], $cache);
$detector = new PlatformDetector($config['platforms']);
$api      = new TelegramAPI($config['telegram']);

// Parse CLI args
$isDaemon = in_array('--daemon', $argv ?? []);
$maxJobs  = (int)($argv[1] ?? $config['queue']['max_workers']);
$workerId = getmypid();

// ─── Recover stale jobs ──────────────────────────────────────────────────────
$recovered = $queue->recoverStaleJobs();
if ($recovered > 0) {
    Logger::warning("Recovered {$recovered} stale jobs");
}

// ─── Main Loop ───────────────────────────────────────────────────────────────
$jobsProcessed = 0;
$maxRunTime    = $isDaemon ? PHP_INT_MAX : 55; // 55s max for cron mode
$pollInterval  = $config['queue']['poll_interval'];

do {
    $job = $queue->claimNextJob($workerId);

    if (!$job) {
        if ($isDaemon) {
            sleep($pollInterval);
            continue;
        }
        break; // No jobs, exit cron run
    }

    Logger::info("Processing job #{$job['id']}", [
        'url'      => $job['url'],
        'platform' => $job['platform'],
        'user'     => $job['user_id'],
        'quality'  => $job['quality'],
        'format'   => $job['format'],
    ]);

    processJob($job, $queue, $cache, $engine, $detector, $api, $db, $config);
    $jobsProcessed++;

} while ($isDaemon || (microtime(true) - WORKER_START < $maxRunTime));

Logger::info("Worker finished", ['jobs' => $jobsProcessed, 'runtime' => round(microtime(true) - WORKER_START, 2) . 's']);

// ─── Job Processor ───────────────────────────────────────────────────────────

function processJob(
    array $job,
    QueueManager $queue,
    CacheManager $cache,
    DownloaderEngine $engine,
    PlatformDetector $detector,
    TelegramAPI $api,
    DatabaseManager $db,
    array $config
): void {
    $jobId     = $job['id'];
    $chatId    = $job['chat_id'];
    $userId    = $job['user_id'];
    $url       = $job['url'];
    $quality   = $job['quality'];
    $format    = $job['format'];
    $platform  = $job['platform'];
    $msgId     = $job['message_id'];

    $startTime = microtime(true);

    try {
        // Send "processing" notification
        $statusMsgId = null;
        try {
            if ($msgId) {
                $api->editMessageText($chatId, $msgId, "⚙️ Processing your download...\n\n🔗 " . mb_substr($url, 0, 50));
                $statusMsgId = $msgId;
            } else {
                $sent = $api->sendMessage($chatId, "⚙️ Processing your download...");
                $statusMsgId = $sent['message_id'] ?? null;
            }
        } catch (\Throwable $e) {
            Logger::warning("Could not update status message: " . $e->getMessage());
        }

        // Check cache first (in case it was cached between enqueue and now)
        $cached = $cache->get($url, $quality, $format);
        if ($cached) {
            Logger::info("Cache hit for job #{$jobId}");
            $result = sendFileToUser($api, $chatId, $cached['file_id'], $cached['file_type'], $cached, true);
            if ($statusMsgId) {
                try { $api->deleteMessage($chatId, $statusMsgId); } catch (\Throwable $e) {}
            }
            $queue->markCompleted($jobId);
            logHistory($db, $userId, $url, $platform, $cached, true, microtime(true) - $startTime);
            return;
        }

        // Download with progress updates
        $lastProgressUpdate = 0;
        $downloadResult = $engine->download($url, [
            'platform' => $platform,
            'format'   => $format,
            'quality'  => $quality,
            'job_id'   => "job_{$jobId}",
        ], function(int $progress, string $phase) use ($api, $chatId, $statusMsgId, $queue, $jobId, &$lastProgressUpdate) {
            // Throttle progress updates (max every 5%)
            if ($progress - $lastProgressUpdate >= 5) {
                $lastProgressUpdate = $progress;
                $queue->updateProgress($jobId, $progress);

                // Update Telegram message with progress bar
                if ($statusMsgId) {
                    $bar  = buildProgressBar($progress);
                    $text = "📥 Downloading...\n\n{$bar} {$progress}%";
                    try {
                        $api->editMessageText($chatId, $statusMsgId, $text);
                    } catch (\Throwable $e) {}
                }
            }
        });

        $filePath = $downloadResult['path'];
        $fileType = $downloadResult['type'];
        $meta     = $downloadResult['meta'];

        // Notify uploading
        if ($statusMsgId) {
            try { $api->editMessageText($chatId, $statusMsgId, "📤 Uploading to Telegram..."); } catch (\Throwable $e) {}
        }

        $api->sendChatAction($chatId, $fileType === 'audio' ? 'upload_voice' : 'upload_video');

        // Build caption
        $duration = $engine->formatDuration($meta['duration'] ?? null);
        $size     = $engine->formatBytes((int)filesize($filePath));
        $title    = mb_substr($meta['title'] ?? 'Media', 0, 200);
        $caption  = "✅ <b>{$title}</b>\n\n"
                  . "⏱ {$duration} | 💾 {$size}\n"
                  . "🌐 {$platform} | 🎯 {$quality}";

        // Send file to Telegram
        $sentMsg = sendDownloadedFile($api, $chatId, $filePath, $fileType, $caption);

        if (!$sentMsg) {
            throw new \RuntimeException("Failed to send file to Telegram");
        }

        // Extract file_id from response
        $fileId = extractFileId($sentMsg, $fileType);

        // Store in cache
        if ($fileId) {
            $cache->set($url, $fileId, $fileType, [
                'quality'   => $quality,
                'platform'  => $platform,
                'title'     => $title,
                'duration'  => $meta['duration'] ?? null,
                'file_size' => filesize($filePath),
            ]);
        }

        // Delete status message
        if ($statusMsgId) {
            try { $api->deleteMessage($chatId, $statusMsgId); } catch (\Throwable $e) {}
        }

        // Cleanup downloaded file
        $engine->cleanup($filePath);

        // Mark job complete
        $queue->markCompleted($jobId);

        // Log to history
        logHistory($db, $userId, $url, $platform, [
            'title'     => $title,
            'file_id'   => $fileId,
            'file_type' => $fileType,
            'file_size' => (int)($meta['file_size'] ?? 0),
            'duration'  => $meta['duration'] ?? null,
            'quality'   => $quality,
        ], false, microtime(true) - $startTime);

        // Update daily stats
        $today = date('Y-m-d');
        $db->query("INSERT INTO stats_daily (date, total_downloads) VALUES (?, 1)
                    ON CONFLICT(date) DO UPDATE SET total_downloads = total_downloads + 1", [$today]);

        Logger::info("Job #{$jobId} completed in " . round(microtime(true) - $startTime, 2) . "s");

    } catch (\Throwable $e) {
        Logger::error("Job #{$jobId} failed: " . $e->getMessage());

        $queue->markFailed($jobId, $e->getMessage());

        // Notify user
        $errorMsg  = mapErrorToMessage($e->getMessage());
        $failText  = "❌ <b>Download failed</b>\n\nReason: {$errorMsg}";

        try {
            if ($statusMsgId ?? null) {
                $api->editMessageText($chatId, $statusMsgId, $failText);
            } else {
                $api->sendMessage($chatId, $failText);
            }
        } catch (\Throwable $notifyErr) {
            Logger::warning("Could not notify user of failure: " . $notifyErr->getMessage());
        }

        // Cleanup temp file if exists
        if (!empty($downloadResult['path'] ?? null)) {
            $engine->cleanup($downloadResult['path']);
        }

        $today = date('Y-m-d');
        $db->query("INSERT INTO stats_daily (date, failed_downloads) VALUES (?, 1)
                    ON CONFLICT(date) DO UPDATE SET failed_downloads = failed_downloads + 1", [$today]);
    }
}

// ─── Utilities ───────────────────────────────────────────────────────────────

function sendDownloadedFile(TelegramAPI $api, int $chatId, string $filePath, string $type, string $caption): ?array
{
    return match($type) {
        'audio' => $api->sendAudio($chatId, $filePath, ['caption' => $caption]),
        'video' => $api->sendVideo($chatId, $filePath, ['caption' => $caption, 'supports_streaming' => true]),
        default => $api->sendDocument($chatId, $filePath, ['caption' => $caption]),
    };
}

function sendFileToUser(TelegramAPI $api, int $chatId, string $fileId, string $type, array $meta, bool $fromCache): ?array
{
    $title   = mb_substr($meta['title'] ?? 'Media', 0, 200);
    $caption = ($fromCache ? "⚡ <b>Instant!</b> " : "✅ ") . "<b>{$title}</b>";
    return $api->forwardFile($chatId, $fileId, $type, ['caption' => $caption]);
}

function extractFileId(array $message, string $type): ?string
{
    return match($type) {
        'video'  => $message['video']['file_id'] ?? null,
        'audio'  => $message['audio']['file_id'] ?? $message['voice']['file_id'] ?? null,
        default  => $message['document']['file_id'] ?? null,
    };
}

function buildProgressBar(int $progress, int $length = 20): string
{
    $filled  = (int)floor($progress / 100 * $length);
    $empty   = $length - $filled;
    return '▓' . str_repeat('█', $filled) . str_repeat('░', $empty) . '▒';
}

function mapErrorToMessage(string $error): string
{
    $map = [
        'private'       => '🔒 Content is private',
        'geo'           => '🌍 Content not available in this region',
        'age'           => '🔞 Age-restricted content',
        'copyright'     => '©️ Copyright restricted',
        'unavailable'   => '❌ Content unavailable',
        'too large'     => '📦 File too large',
        'timeout'       => '⏰ Download timed out',
        'unsupported'   => '❓ Unsupported platform',
        '404'           => '🔍 Content not found',
        '403'           => '🚫 Access denied',
    ];

    $errorLower = strtolower($error);
    foreach ($map as $key => $msg) {
        if (str_contains($errorLower, $key)) return $msg;
    }

    return mb_substr($error, 0, 200);
}

function logHistory(DatabaseManager $db, int $userId, string $url, string $platform, array $data, bool $fromCache, float $elapsed): void
{
    try {
        $db->insert('download_history', [
            'user_id'      => $userId,
            'url'          => $url,
            'platform'     => $platform,
            'title'        => mb_substr($data['title'] ?? 'Unknown', 0, 255),
            'file_id'      => $data['file_id'] ?? null,
            'file_type'    => $data['file_type'] ?? 'document',
            'file_size'    => $data['file_size'] ?? 0,
            'duration'     => $data['duration'] ?? null,
            'quality'      => $data['quality'] ?? '',
            'from_cache'   => $fromCache ? 1 : 0,
            'status'       => 'success',
            'download_time'=> round($elapsed, 2),
        ]);

        $db->query(
            "UPDATE users SET total_downloads = total_downloads + 1 WHERE telegram_id=?",
            [$userId]
        );
    } catch (\Throwable $e) {
        Logger::warning("logHistory failed: " . $e->getMessage());
    }
}
