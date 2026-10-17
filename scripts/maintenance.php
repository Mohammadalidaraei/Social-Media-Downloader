#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * MediaBot Maintenance Script
 * Run: php scripts/maintenance.php [command]
 * Commands: cleanup, vacuum, stats, update-ytdlp
 */

define('BOT_ROOT', dirname(__DIR__));
require_once BOT_ROOT . '/src/autoload.php';

use MediaBot\Database\DatabaseManager;
use MediaBot\Queue\QueueManager;
use MediaBot\Cache\CacheManager;
use MediaBot\Downloader\DownloaderEngine;
use MediaBot\Bot\Logger;

$config  = require BOT_ROOT . '/config/config.php';
$command = $argv[1] ?? 'cleanup';

Logger::init($config['logging']);
$db    = DatabaseManager::getInstance($config['database']);
$queue = new QueueManager($db, $config['queue']);
$cache = new CacheManager($db, $config['cache']);

$startTime = microtime(true);
echo date('[Y-m-d H:i:s]') . " Running maintenance: {$command}\n";

switch ($command) {

    case 'cleanup':
        // 1. Cleanup expired cache
        $cacheDeleted = $cache->cleanup();
        echo "  ✅ Cache: deleted {$cacheDeleted} expired entries\n";

        // 2. Cleanup old queue jobs
        $queueDeleted = $queue->cleanup();
        echo "  ✅ Queue: deleted {$queueDeleted} old jobs\n";

        // 3. Recover stale worker jobs
        $recovered = $queue->recoverStaleJobs();
        if ($recovered > 0) {
            echo "  ⚠️  Queue: recovered {$recovered} stale jobs\n";
        }

        // 4. Cleanup temp download files
        $tempDir  = $config['downloader']['temp_dir'];
        $dlDir    = $config['downloader']['download_dir'];
        $oldFiles = 0;
        $threshold = time() - 3600; // Files older than 1 hour

        foreach ([$tempDir, $dlDir] as $dir) {
            if (!is_dir($dir)) continue;
            foreach (glob($dir . '*') as $file) {
                if (is_file($file) && filemtime($file) < $threshold) {
                    @unlink($file);
                    $oldFiles++;
                }
            }
        }
        echo "  ✅ Files: deleted {$oldFiles} old temp files\n";

        // 5. Cleanup old URL mappings from settings table
        $cutoff = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $mapDeleted = $db->query(
            "DELETE FROM settings WHERE key LIKE 'url_map:%' AND updated_at < ?",
            [$cutoff]
        )->rowCount();
        echo "  ✅ Settings: cleaned {$mapDeleted} old URL mappings\n";

        // 6. Cleanup old rate limit entries
        $rlDeleted = $db->query(
            "DELETE FROM rate_limits WHERE window_key < ?",
            [date('Y-m-d-H', strtotime('-2 hours'))]
        )->rowCount();
        echo "  ✅ Rate limits: cleaned {$rlDeleted} old entries\n";

        // 7. Cleanup old admin sessions
        $sessDeleted = $db->query(
            "DELETE FROM admin_sessions WHERE expires_at < datetime('now')"
        )->rowCount();
        echo "  ✅ Sessions: cleaned {$sessDeleted} expired\n";

        // 8. Cleanup old log files
        $logsDeleted = Logger::cleanOldLogs($config['logging']['max_files']);
        echo "  ✅ Logs: cleaned {$logsDeleted} old log files\n";
        break;

    case 'vacuum':
        echo "  🗜️  Running VACUUM ANALYZE on database...\n";
        $sizeBefore = filesize($config['database']['path']);
        $db->vacuum();
        $sizeAfter  = filesize($config['database']['path']);
        $saved      = $sizeBefore - $sizeAfter;
        echo "  ✅ Vacuum complete. Saved: " . formatBytes($saved) . "\n";
        echo "  📊 DB size: " . formatBytes($sizeAfter) . "\n";
        break;

    case 'stats':
        $users     = $db->fetchColumn("SELECT COUNT(*) FROM users");
        $downloads = $db->fetchColumn("SELECT COUNT(*) FROM download_history");
        $cacheSize = $db->fetchColumn("SELECT COUNT(*) FROM file_cache WHERE expires_at > datetime('now')");
        $pending   = $db->fetchColumn("SELECT COUNT(*) FROM download_queue WHERE status='pending'");
        $dbSize    = formatBytes((int)filesize($config['database']['path']));

        echo "  📊 Users:        {$users}\n";
        echo "  📥 Downloads:    {$downloads}\n";
        echo "  ⚡ Cache entries:{$cacheSize}\n";
        echo "  ⏳ Queue pending:{$pending}\n";
        echo "  🗄️  DB size:      {$dbSize}\n";
        break;

    case 'update-ytdlp':
        $ytdlp = $config['downloader']['ytdlp_path'];
        echo "  🔄 Updating yt-dlp...\n";
        $output = shell_exec(escapeshellarg($ytdlp) . ' --update 2>&1');
        echo "  " . trim($output) . "\n";
        break;

    case 'reset-queue':
        $db->query("UPDATE download_queue SET status='pending', worker_pid=NULL, started_at=NULL WHERE status='processing'");
        echo "  ✅ All processing jobs reset to pending\n";
        break;

    default:
        echo "  Unknown command. Available: cleanup, vacuum, stats, update-ytdlp, reset-queue\n";
        break;
}

$elapsed = round(microtime(true) - $startTime, 3);
echo date('[Y-m-d H:i:s]') . " Done in {$elapsed}s\n";

function formatBytes(int $bytes): string
{
    if ($bytes <= 0)           return '0 B';
    if ($bytes >= 1073741824)  return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)     return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)        return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}
