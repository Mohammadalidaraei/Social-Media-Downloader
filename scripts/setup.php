#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * MediaBot Setup Script
 * Run: php setup.php [command]
 * Commands: install, webhook, test, worker-start
 */

define('BOT_ROOT', dirname(__DIR__));
require_once BOT_ROOT . '/src/autoload.php';

use MediaBot\Database\DatabaseManager;
use MediaBot\Bot\TelegramAPI;

$config  = require BOT_ROOT . '/config/config.php';
$command = $argv[1] ?? 'help';

echo "\n🤖 MediaBot Setup v2.0\n";
echo str_repeat('─', 50) . "\n\n";

match($command) {
    'install'      => cmdInstall($config),
    'webhook'      => cmdWebhook($config),
    'webhook-del'  => cmdWebhookDelete($config),
    'test'         => cmdTest($config),
    'worker-start' => cmdWorkerStart($config),
    'env'          => cmdEnv(),
    default        => cmdHelp(),
};

// ─── Commands ────────────────────────────────────────────────────────────────

function cmdInstall(array $config): void
{
    echo "📦 Installing MediaBot...\n\n";

    // 1. Create directories
    $dirs = [
        $config['downloader']['download_dir'],
        $config['downloader']['temp_dir'],
        $config['cache']['file_cache_dir'],
        $config['logging']['dir'],
        dirname($config['database']['path']),
    ];

    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
            echo "  ✅ Created: {$dir}\n";
        } else {
            echo "  ✓  Exists:  {$dir}\n";
        }
    }

    // 2. Init database
    echo "\n🗄️  Initializing database...\n";
    $db = DatabaseManager::getInstance($config['database']);
    echo "  ✅ SQLite database created with WAL mode\n";

    // 3. Check dependencies
    echo "\n🔍 Checking dependencies...\n";
    checkDependency('php', '--version', '8.0');
    checkDependency('python3', '--version', '3.8');
    checkDependency($config['downloader']['ytdlp_path'], '--version', '2023');
    checkDependency($config['downloader']['ffmpeg_path'], '-version', 'ffmpeg');

    // 4. Set file permissions
    @chmod(BOT_ROOT . '/worker/worker.php', 0755);
    @chmod(BOT_ROOT . '/scripts/downloader.py', 0755);
    echo "\n✅ Permissions set\n";

    // 5. Create .env from example if needed
    $envFile = BOT_ROOT . '/.env';
    $envExample = BOT_ROOT . '/.env.example';
    if (!file_exists($envFile) && file_exists($envExample)) {
        copy($envExample, $envFile);
        echo "📝 .env file created from .env.example — please fill in your values!\n";
    }

    echo "\n" . str_repeat('─', 50) . "\n";
    echo "✅ Installation complete!\n\n";
    echo "Next steps:\n";
    echo "  1. Edit .env with your BOT_TOKEN and other settings\n";
    echo "  2. Run: php scripts/setup.php webhook\n";
    echo "  3. Add cron: * * * * * php " . BOT_ROOT . "/worker/worker.php\n\n";
}

function cmdWebhook(array $config): void
{
    echo "🔗 Setting webhook...\n\n";

    $api = new TelegramAPI($config['telegram']);

    try {
        $result = $api->setWebhook($config['telegram']['webhook_url'], [
            'allowed_updates' => ['message', 'callback_query', 'inline_query'],
            'drop_pending_updates' => true,
            'max_connections' => 100,
        ]);

        echo "  ✅ Webhook set to: {$config['telegram']['webhook_url']}\n";

        // Verify
        $info = $api->getWebhookInfo();
        echo "  📊 Status: " . ($info['url'] ? "Active" : "Inactive") . "\n";
        echo "  📬 Pending updates: " . ($info['pending_update_count'] ?? 0) . "\n";

    } catch (\Throwable $e) {
        echo "  ❌ Error: " . $e->getMessage() . "\n";
    }
}

function cmdWebhookDelete(array $config): void
{
    echo "🗑️  Deleting webhook...\n";
    $api = new TelegramAPI($config['telegram']);
    $api->deleteWebhook();
    echo "  ✅ Webhook deleted (polling mode)\n";
}

function cmdTest(array $config): void
{
    echo "🧪 Running tests...\n\n";

    // Test DB
    echo "  Testing database... ";
    try {
        $db = DatabaseManager::getInstance($config['database']);
        $db->fetchColumn("SELECT 1");
        echo "✅\n";
    } catch (\Throwable $e) { echo "❌ " . $e->getMessage() . "\n"; }

    // Test Telegram API
    echo "  Testing Telegram API... ";
    try {
        $api    = new TelegramAPI($config['telegram']);
        $botInfo = $api->getMe();
        echo "✅ Bot: @{$botInfo['username']} ({$botInfo['first_name']})\n";
    } catch (\Throwable $e) { echo "❌ " . $e->getMessage() . "\n"; }

    // Test yt-dlp
    echo "  Testing yt-dlp... ";
    $output = shell_exec($config['downloader']['ytdlp_path'] . ' --version 2>/dev/null');
    echo $output ? "✅ v" . trim($output) . "\n" : "❌ Not found\n";

    // Test ffmpeg
    echo "  Testing ffmpeg... ";
    $output = shell_exec($config['downloader']['ffmpeg_path'] . ' -version 2>/dev/null | head -1');
    echo $output ? "✅\n" : "❌ Not found\n";

    // Test Python
    echo "  Testing Python... ";
    $output = shell_exec($config['downloader']['python_path'] . ' --version 2>/dev/null');
    echo $output ? "✅ " . trim($output) . "\n" : "❌ Not found\n";

    echo "\n";
}

function cmdWorkerStart(array $config): void
{
    echo "⚙️  Starting worker...\n";
    $workerPath = BOT_ROOT . '/worker/worker.php';
    echo "  Command: php {$workerPath} --daemon\n";
    echo "  Use supervisor or screen to keep it running.\n";
    echo "\n  Starting now (Ctrl+C to stop)...\n\n";
    passthru("php {$workerPath} --daemon");
}

function cmdEnv(): void
{
    echo "📝 Generating .env.example...\n";
    $content = <<<ENV
# MediaBot Environment Variables

# Telegram Bot Token (from @BotFather)
BOT_TOKEN=your_bot_token_here

# Webhook URL (your server's public HTTPS URL)
WEBHOOK_URL=https://yourdomain.com/webhook.php

# Admin Telegram User IDs (comma-separated)
ADMIN_IDS=123456789,987654321

# Admin Dashboard Password
ADMIN_PASSWORD=ChangeMe@2025!

# Paths
YTDLP_PATH=/usr/local/bin/yt-dlp
PYTHON_PATH=/usr/bin/python3
FFMPEG_PATH=/usr/bin/ffmpeg
ENV;

    file_put_contents(BOT_ROOT . '/.env.example', $content);
    echo "  ✅ .env.example created\n";
}

function cmdHelp(): void
{
    echo "Available commands:\n\n";
    echo "  install       — Create directories, init DB, check dependencies\n";
    echo "  webhook       — Set Telegram webhook URL\n";
    echo "  webhook-del   — Delete webhook (switch to polling)\n";
    echo "  test          — Test all dependencies and connections\n";
    echo "  worker-start  — Start background worker in daemon mode\n";
    echo "  env           — Generate .env.example file\n\n";
    echo "Example:\n";
    echo "  php scripts/setup.php install\n\n";
}

function checkDependency(string $bin, string $arg, string $expectStr): void
{
    echo "  Checking {$bin}... ";
    $output = shell_exec(escapeshellarg($bin) . " {$arg} 2>/dev/null");
    if ($output && str_contains(strtolower($output), strtolower($expectStr))) {
        echo "✅\n";
    } else {
        echo "⚠️  Not found or version too old\n";
    }
}
