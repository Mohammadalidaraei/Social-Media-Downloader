<?php
declare(strict_types=1);

/**
 * MediaBot Configuration
 * Ultra-fast Social Media Downloader Bot
 */

return [

    // ─── Telegram ───────────────────────────────────────────────────────────
    'telegram' => [
        'bot_token'     => getenv('BOT_TOKEN')    ?: 'YOUR_BOT_TOKEN_HERE',
        'webhook_url'   => getenv('WEBHOOK_URL')  ?: 'https://yourdomain.com/webhook.php',
        'admin_ids'     => array_map('intval', explode(',', getenv('ADMIN_IDS') ?: '123456789')),
        'admin_password'=> getenv('ADMIN_PASSWORD') ?: 'admin@MediaBot2025!',
        'api_base'      => 'https://api.telegram.org/bot',
        'timeout'       => 30,
        'max_file_size' => 2000, // MB
    ],

    // ─── Database (SQLite) ──────────────────────────────────────────────────
    'database' => [
        'path'      => __DIR__ . '/../storage/mediabot.db',
        'wal_mode'  => true,
        'cache_size'=> -64000, // 64MB page cache
        'busy_timeout' => 5000,
    ],

    // ─── Queue ──────────────────────────────────────────────────────────────
    'queue' => [
        'max_workers'       => 4,
        'worker_timeout'    => 300,  // seconds
        'retry_attempts'    => 3,
        'retry_delay'       => 10,   // seconds
        'cleanup_after_days'=> 7,
        'poll_interval'     => 2,    // worker poll interval (seconds)
    ],

    // ─── Downloader ─────────────────────────────────────────────────────────
    'downloader' => [
        'ytdlp_path'        => getenv('YTDLP_PATH') ?: '/usr/local/bin/yt-dlp',
        'python_path'       => getenv('PYTHON_PATH') ?: '/usr/bin/python3',
        'ffmpeg_path'       => getenv('FFMPEG_PATH') ?: '/usr/bin/ffmpeg',
        'download_dir'      => __DIR__ . '/../storage/downloads/',
        'temp_dir'          => sys_get_temp_dir() . '/mediabot/',
        'max_video_quality' => '1080',
        'audio_quality'     => '192',  // kbps
        'max_duration'      => 3600,   // 1 hour max
        'max_size_bytes'    => 2000 * 1024 * 1024, // 2GB
        'concurrent_downloads' => 3,
        'user_agent'        => 'Mozilla/5.0 (compatible; MediaBot/2.0)',
    ],

    // ─── Cache ──────────────────────────────────────────────────────────────
    'cache' => [
        'ttl_hours'     => 72,       // 3 days
        'max_entries'   => 50000,
        'file_cache_dir'=> __DIR__ . '/../storage/cache/',
    ],

    // ─── Supported Platforms ────────────────────────────────────────────────
    'platforms' => [
        'youtube'   => ['patterns' => ['youtube.com', 'youtu.be'], 'icon' => '▶️'],
        'instagram' => ['patterns' => ['instagram.com', 'instagr.am'], 'icon' => '📸'],
        'twitter'   => ['patterns' => ['twitter.com', 'x.com', 't.co'], 'icon' => '🐦'],
        'tiktok'    => ['patterns' => ['tiktok.com', 'vm.tiktok.com'], 'icon' => '🎵'],
        'facebook'  => ['patterns' => ['facebook.com', 'fb.watch', 'fb.com'], 'icon' => '👍'],
        'reddit'    => ['patterns' => ['reddit.com', 'redd.it', 'v.redd.it'], 'icon' => '🤖'],
        'vimeo'     => ['patterns' => ['vimeo.com'], 'icon' => '🎬'],
        'twitch'    => ['patterns' => ['twitch.tv', 'clips.twitch.tv'], 'icon' => '🟣'],
        'pinterest' => ['patterns' => ['pinterest.com', 'pin.it'], 'icon' => '📌'],
        'soundcloud'=> ['patterns' => ['soundcloud.com'], 'icon' => '🎧'],
        'spotify'   => ['patterns' => ['spotify.com', 'open.spotify.com'], 'icon' => '💚'],
        'dailymotion'=> ['patterns' => ['dailymotion.com', 'dai.ly'], 'icon' => '🎦'],
        'telegram'  => ['patterns' => ['t.me'], 'icon' => '✈️'],
        'direct'    => ['patterns' => ['.mp4', '.mp3', '.webm', '.mkv', '.m3u8'], 'icon' => '🔗'],
    ],

    // ─── Rate Limiting ───────────────────────────────────────────────────────
    'rate_limit' => [
        'requests_per_minute'   => 10,
        'downloads_per_hour'    => 30,
        'ban_threshold'         => 100,
    ],

    // ─── i18n ────────────────────────────────────────────────────────────────
    'i18n' => [
        'default_lang'  => 'en',
        'supported'     => ['en', 'fa', 'ru', 'zh', 'es', 'de', 'ar'],
        'lang_dir'      => __DIR__ . '/../lang/',
        'rtl_langs'     => ['fa', 'ar'],
    ],

    // ─── Admin Panel ─────────────────────────────────────────────────────────
    'admin' => [
        'session_lifetime' => 3600,
        'csrf_token_ttl'   => 1800,
        'items_per_page'   => 25,
    ],

    // ─── Logging ─────────────────────────────────────────────────────────────
    'logging' => [
        'enabled'   => true,
        'level'     => 'info', // debug, info, warning, error
        'dir'       => __DIR__ . '/../storage/logs/',
        'max_files' => 30,
    ],
];
