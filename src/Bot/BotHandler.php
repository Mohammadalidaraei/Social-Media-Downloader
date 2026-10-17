<?php
declare(strict_types=1);

namespace MediaBot\Bot;

use MediaBot\Database\DatabaseManager;
use MediaBot\Queue\QueueManager;
use MediaBot\Cache\CacheManager;
use MediaBot\Downloader\PlatformDetector;
use MediaBot\I18n\Translator;
use MediaBot\Bot\TelegramAPI;

/**
 * BotHandler — processes all incoming Telegram updates:
 * messages, callback queries, inline queries.
 * Designed to respond within 5s (webhook constraint).
 */
class BotHandler
{
    private TelegramAPI       $api;
    private DatabaseManager   $db;
    private QueueManager      $queue;
    private CacheManager      $cache;
    private PlatformDetector  $detector;
    private Translator        $i18n;
    private array             $config;
    private array             $adminIds;

    public function __construct(
        TelegramAPI $api,
        DatabaseManager $db,
        QueueManager $queue,
        CacheManager $cache,
        PlatformDetector $detector,
        Translator $i18n,
        array $config
    ) {
        $this->api      = $api;
        $this->db       = $db;
        $this->queue    = $queue;
        $this->cache    = $cache;
        $this->detector = $detector;
        $this->i18n     = $i18n;
        $this->config   = $config;
        $this->adminIds = $config['telegram']['admin_ids'];
    }

    // ─── Main Dispatcher ────────────────────────────────────────────────────

    public function handle(array $update): void
    {
        try {
            if (isset($update['message'])) {
                $this->handleMessage($update['message']);
            } elseif (isset($update['callback_query'])) {
                $this->handleCallback($update['callback_query']);
            } elseif (isset($update['inline_query'])) {
                $this->handleInline($update['inline_query']);
            }
        } catch (\Throwable $e) {
            Logger::error('BotHandler exception: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        }
    }

    // ─── Message Handler ────────────────────────────────────────────────────

    private function handleMessage(array $msg): void
    {
        $userId   = $msg['from']['id'];
        $chatId   = $msg['chat']['id'];
        $text     = trim($msg['text'] ?? '');
        $msgId    = $msg['message_id'];

        // Register/update user
        $user = $this->upsertUser($msg['from']);

        // Set language for this request
        $this->i18n->setLang($user['lang_pref'] ?? 'en');

        // Check maintenance mode
        if ($this->isMaintenanceMode() && !$this->isAdmin($userId)) {
            $this->api->sendMessage($chatId, $this->i18n->t('maintenance.message'));
            return;
        }

        // Check ban
        if ($user['is_banned']) {
            $this->api->sendMessage($chatId, $this->i18n->t('download.banned'));
            return;
        }

        // Route commands
        if (str_starts_with($text, '/')) {
            $this->handleCommand($text, $userId, $chatId, $msgId, $user);
            return;
        }

        // Check for URL
        $urls = $this->detector->extractUrls($text);
        if (!empty($urls)) {
            $this->handleDownloadRequest($urls[0], $userId, $chatId, $msgId, $user);
            return;
        }

        // Unknown text
        $this->api->sendMessage($chatId, $this->i18n->t('download.invalid_url'));
    }

    // ─── Command Router ──────────────────────────────────────────────────────

    private function handleCommand(string $text, int $userId, int $chatId, int $msgId, array $user): void
    {
        $parts   = explode(' ', $text, 2);
        $command = strtolower(explode('@', $parts[0])[0]);
        $args    = $parts[1] ?? '';

        match($command) {
            '/start'    => $this->cmdStart($userId, $chatId, $user),
            '/help'     => $this->cmdHelp($chatId),
            '/settings' => $this->cmdSettings($chatId, $user),
            '/language', '/lang' => $this->cmdLanguage($chatId),
            '/history'  => $this->cmdHistory($chatId, $userId, 1),
            '/stats'    => $this->cmdStats($chatId, $userId),
            '/cancel'   => $this->cmdCancel($chatId, $userId),
            // Admin commands
            '/admin'    => $this->cmdAdmin($chatId, $userId, $args),
            '/broadcast'=> $this->cmdBroadcast($chatId, $userId, $args),
            '/ban'      => $this->cmdBan($chatId, $userId, $args),
            '/unban'    => $this->cmdUnban($chatId, $userId, $args),
            '/qstats'   => $this->cmdQueueStats($chatId, $userId),
            default     => $this->api->sendMessage($chatId, $this->i18n->t('download.invalid_url')),
        };
    }

    // ─── Download Request ────────────────────────────────────────────────────

    private function handleDownloadRequest(string $url, int $userId, int $chatId, int $msgId, array $user): void
    {
        // Rate limit check
        if ($this->isRateLimited($userId)) {
            $limit = $this->config['rate_limit']['downloads_per_hour'];
            $this->api->sendMessage($chatId, $this->i18n->t('download.rate_limited', [
                'limit' => $limit,
                'wait'  => '60',
            ]));
            return;
        }

        // Detect platform
        $platform = $this->detector->detect($url);

        if (!$platform['supported']) {
            $this->api->sendMessage($chatId, $this->i18n->t('download.unsupported'));
            return;
        }

        // Instant cache hit check (best quality, video)
        $cached = $this->cache->get($url);
        if ($cached) {
            $this->sendCachedFile($chatId, $cached, $userId);
            return;
        }

        // Show format selection keyboard
        $this->showFormatKeyboard($chatId, $url, $platform);
    }

    private function showFormatKeyboard(int $chatId, string $url, array $platform): void
    {
        $encodedUrl = base64_encode($url);

        // Truncate for callback_data (64 byte limit)
        $urlHash = substr(hash('sha256', $url), 0, 16);
        $this->storeUrlMapping($urlHash, $url);

        $keyboard = TelegramAPI::inlineKeyboard([
            [
                ['text' => $this->i18n->t('quality.1080'), 'callback_data' => "dl:{$urlHash}:1080:video"],
                ['text' => $this->i18n->t('quality.720'),  'callback_data' => "dl:{$urlHash}:720:video"],
            ],
            [
                ['text' => $this->i18n->t('quality.480'),  'callback_data' => "dl:{$urlHash}:480:video"],
                ['text' => $this->i18n->t('quality.360'),  'callback_data' => "dl:{$urlHash}:360:video"],
            ],
            [
                ['text' => '⭐ ' . $this->i18n->t('download.best_quality'), 'callback_data' => "dl:{$urlHash}:best:video"],
                ['text' => $this->i18n->t('download.audio_only'),           'callback_data' => "dl:{$urlHash}:best:audio"],
            ],
            [
                ['text' => $this->i18n->t('buttons.cancel'), 'callback_data' => 'cancel'],
            ],
        ]);

        $text = "{$platform['icon']} <b>{$platform['name']}</b>\n\n" . $this->i18n->t('download.choose_quality');
        $this->api->sendMessage($chatId, $text, ['reply_markup' => $keyboard]);
    }

    // ─── Callback Handler ────────────────────────────────────────────────────

    private function handleCallback(array $cb): void
    {
        $userId  = $cb['from']['id'];
        $chatId  = $cb['message']['chat']['id'];
        $msgId   = $cb['message']['message_id'];
        $data    = $cb['data'];
        $cbId    = $cb['id'];

        $user = $this->db->fetchOne("SELECT * FROM users WHERE telegram_id=?", [$userId]);
        if ($user) $this->i18n->setLang($user['lang_pref'] ?? 'en');

        $this->api->answerCallbackQuery($cbId);

        match(true) {
            $data === 'cancel'               => $this->handleCancelCallback($chatId, $msgId),
            str_starts_with($data, 'dl:')    => $this->handleDownloadCallback($data, $userId, $chatId, $msgId, $user),
            str_starts_with($data, 'lang:')  => $this->handleLangCallback($data, $userId, $chatId, $msgId, $user),
            str_starts_with($data, 'hist:')  => $this->handleHistoryCallback($data, $chatId, $userId),
            str_starts_with($data, 'admin:') => $this->handleAdminCallback($data, $userId, $chatId, $msgId),
            default => null,
        };
    }

    private function handleDownloadCallback(string $data, int $userId, int $chatId, int $msgId, ?array $user): void
    {
        // dl:{urlHash}:{quality}:{format}
        $parts   = explode(':', $data, 4);
        $urlHash = $parts[1] ?? '';
        $quality = $parts[2] ?? '720';
        $format  = $parts[3] ?? 'video';

        $url = $this->resolveUrlMapping($urlHash);
        if (!$url) {
            $this->api->editMessageText($chatId, $msgId, $this->i18n->t('errors.general'));
            return;
        }

        // Check cache with specific quality/format
        $cached = $this->cache->get($url, $quality, $format);
        if ($cached) {
            $this->api->deleteMessage($chatId, $msgId);
            $this->sendCachedFile($chatId, $cached, $userId);
            return;
        }

        // Enqueue download
        $platform = $this->detector->detect($url);

        try {
            $jobId    = $this->queue->enqueue([
                'user_id'    => $userId,
                'chat_id'    => $chatId,
                'message_id' => $msgId,
                'url'        => $url,
                'platform'   => $platform['name'],
                'format'     => $format,
                'quality'    => $quality,
                'priority'   => $this->isAdmin($userId) ? 10 : 5,
            ]);

            $position = $this->queue->getQueuePosition($jobId);
            $eta      = $this->estimateETA($position);

            $this->api->editMessageText($chatId, $msgId,
                $this->i18n->t('download.queued', [
                    'position' => $position,
                    'eta'      => $eta,
                ])
            );

        } catch (\OverflowException $e) {
            $this->api->editMessageText($chatId, $msgId, $this->i18n->t('download.queue_full'));
        }
    }

    private function handleLangCallback(string $data, int $userId, int $chatId, int $msgId, ?array $user): void
    {
        $lang = explode(':', $data)[1] ?? 'en';
        $this->db->update('users', ['lang_pref' => $lang], 'telegram_id=?', [$userId]);
        $this->i18n->setLang($lang);

        $this->api->editMessageText($chatId, $msgId,
            $this->i18n->t('settings.lang_changed') . "\n\n" . Translator::langFlag($lang)
        );
    }

    private function handleCancelCallback(int $chatId, int $msgId): void
    {
        $this->api->deleteMessage($chatId, $msgId);
    }

    private function handleHistoryCallback(string $data, int $chatId, int $userId): void
    {
        $page = (int)(explode(':', $data)[1] ?? 1);
        $this->cmdHistory($chatId, $userId, $page);
    }

    // ─── Bot Commands ─────────────────────────────────────────────────────────

    private function cmdStart(int $userId, int $chatId, array $user): void
    {
        $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        $isNew = (strtotime($user['created_at']) > time() - 5);

        $text = $isNew
            ? $this->i18n->t('welcome.message')
            : $this->i18n->t('welcome.returning', ['name' => $name]);

        $keyboard = TelegramAPI::inlineKeyboard([
            [
                ['text' => $this->i18n->t('commands.language'), 'callback_data' => 'lang_menu'],
                ['text' => $this->i18n->t('commands.settings'),  'callback_data' => 'settings'],
            ],
            [
                ['text' => $this->i18n->t('commands.help'),    'callback_data' => 'help'],
                ['text' => $this->i18n->t('commands.history'), 'callback_data' => 'hist:1'],
            ],
        ]);

        $this->api->sendMessage($chatId, $text, ['reply_markup' => $keyboard]);
    }

    private function cmdHelp(int $chatId): void
    {
        $this->api->sendMessage($chatId, $this->i18n->t('help.message'));
    }

    private function cmdSettings(int $chatId, array $user): void
    {
        $quality = $user['default_quality'] ?? '720';
        $text    = $this->i18n->t('settings.title') . "\n\n";
        $text   .= $this->i18n->t('settings.quality', ['quality' => $quality]);

        $keyboard = TelegramAPI::inlineKeyboard([
            [['text' => $this->i18n->t('settings.language'), 'callback_data' => 'lang_menu']],
            [['text' => '720p HD (Default)', 'callback_data' => 'setq:720'],
             ['text' => '1080p Full HD',     'callback_data' => 'setq:1080']],
            [['text' => $this->i18n->t('buttons.close'), 'callback_data' => 'cancel']],
        ]);

        $this->api->sendMessage($chatId, $text, ['reply_markup' => $keyboard]);
    }

    private function cmdLanguage(int $chatId): void
    {
        $keyboard = TelegramAPI::inlineKeyboard([
            [
                ['text' => '🇬🇧 English',  'callback_data' => 'lang:en'],
                ['text' => '🇮🇷 فارسی',    'callback_data' => 'lang:fa'],
            ],
            [
                ['text' => '🇷🇺 Русский',  'callback_data' => 'lang:ru'],
                ['text' => '🇨🇳 中文',      'callback_data' => 'lang:zh'],
            ],
            [
                ['text' => '🇪🇸 Español',  'callback_data' => 'lang:es'],
                ['text' => '🇩🇪 Deutsch',  'callback_data' => 'lang:de'],
            ],
            [
                ['text' => '🇸🇦 العربية',  'callback_data' => 'lang:ar'],
            ],
            [
                ['text' => $this->i18n->t('buttons.back'), 'callback_data' => 'cancel'],
            ],
        ]);

        $this->api->sendMessage($chatId, $this->i18n->t('settings.language_choose'), ['reply_markup' => $keyboard]);
    }

    private function cmdHistory(int $chatId, int $userId, int $page = 1): void
    {
        $perPage = 8;
        $offset  = ($page - 1) * $perPage;
        $total   = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM download_history WHERE user_id=?", [$userId]
        );
        $items   = $this->db->fetchAll(
            "SELECT * FROM download_history WHERE user_id=? ORDER BY created_at DESC LIMIT ? OFFSET ?",
            [$userId, $perPage, $offset]
        );

        if (empty($items)) {
            $this->api->sendMessage($chatId, $this->i18n->t('history.empty'));
            return;
        }

        $text = $this->i18n->t('history.title') . "\n\n";
        foreach ($items as $item) {
            $size     = $item['file_size'] ? $this->formatBytes((int)$item['file_size']) : 'N/A';
            $date     = date('Y-m-d', strtotime($item['created_at']));
            $title    = mb_substr($item['title'] ?? 'Unknown', 0, 35);
            $platform = $item['platform'] ?? '?';
            $text    .= "• <b>{$title}</b>\n  📅 {$date} | 💾 {$size} | {$platform}\n\n";
        }

        $totalPages = (int)ceil($total / $perPage);
        $text      .= $this->i18n->t('history.total', ['count' => $total]);

        $navRow = [];
        if ($page > 1)          $navRow[] = ['text' => $this->i18n->t('history.prev'), 'callback_data' => "hist:" . ($page - 1)];
        if ($page < $totalPages) $navRow[] = ['text' => $this->i18n->t('history.next'), 'callback_data' => "hist:" . ($page + 1)];

        $keyboard = $navRow ? TelegramAPI::inlineKeyboard([$navRow]) : null;
        $extra    = $keyboard ? ['reply_markup' => $keyboard] : [];
        $this->api->sendMessage($chatId, $text, $extra);
    }

    private function cmdStats(int $chatId, int $userId): void
    {
        $total   = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM download_history WHERE user_id=?", [$userId]);
        $week    = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM download_history WHERE user_id=? AND created_at > datetime('now','-7 days')", [$userId]);
        $fav     = $this->db->fetchColumn("SELECT platform FROM download_history WHERE user_id=? GROUP BY platform ORDER BY COUNT(*) DESC LIMIT 1", [$userId]) ?: 'N/A';
        $bytes   = (int)$this->db->fetchColumn("SELECT SUM(file_size) FROM download_history WHERE user_id=?", [$userId]);
        $user    = $this->db->fetchOne("SELECT created_at FROM users WHERE telegram_id=?", [$userId]);
        $since   = $user ? date('Y-m-d', strtotime($user['created_at'])) : 'N/A';

        $text = $this->i18n->t('stats.title') . "\n\n"
            . $this->i18n->t('stats.total',         ['count'    => $total]) . "\n"
            . $this->i18n->t('stats.this_week',      ['count'    => $week]) . "\n"
            . $this->i18n->t('stats.favorite',       ['platform' => $fav]) . "\n"
            . $this->i18n->t('stats.data_received',  ['size'     => $this->formatBytes($bytes)]) . "\n"
            . $this->i18n->t('stats.member_since',   ['date'     => $since]);

        $this->api->sendMessage($chatId, $text);
    }

    private function cmdCancel(int $chatId, int $userId): void
    {
        $cancelled = $this->db->query(
            "UPDATE download_queue SET status='cancelled' WHERE user_id=? AND status='pending'",
            [$userId]
        )->rowCount();

        $msg = $cancelled > 0
            ? "✅ Cancelled {$cancelled} pending download(s)."
            : "ℹ️ No pending downloads to cancel.";
        $this->api->sendMessage($chatId, $msg);
    }

    // ─── Admin Commands ───────────────────────────────────────────────────────

    private function cmdAdmin(int $chatId, int $userId, string $args): void
    {
        if (!$this->isAdmin($userId)) {
            $this->api->sendMessage($chatId, $this->i18n->t('admin.unauthorized'));
            return;
        }

        $stats   = $this->getAdminStats();
        $keyboard = TelegramAPI::inlineKeyboard([
            [
                ['text' => '📊 Queue Stats',     'callback_data' => 'admin:queue'],
                ['text' => '👥 Users',            'callback_data' => 'admin:users'],
            ],
            [
                ['text' => '🔧 Maintenance ON',  'callback_data' => 'admin:maint_on'],
                ['text' => '✅ Maintenance OFF',  'callback_data' => 'admin:maint_off'],
            ],
            [
                ['text' => '🧹 Cleanup Cache',   'callback_data' => 'admin:cleanup'],
                ['text' => '📢 Broadcast',        'callback_data' => 'admin:broadcast'],
            ],
        ]);

        $text = $this->i18n->t('admin.stats', $stats);
        $this->api->sendMessage($chatId, $text, ['reply_markup' => $keyboard]);
    }

    private function handleAdminCallback(string $data, int $userId, int $chatId, int $msgId): void
    {
        if (!$this->isAdmin($userId)) return;

        $action = explode(':', $data)[1] ?? '';

        switch ($action) {
            case 'queue':
                $stats = $this->queue->getQueueStats();
                $text  = "📊 <b>Queue Stats (24h)</b>\n\n"
                    . "⏳ Pending: <b>{$stats['pending']}</b>\n"
                    . "⚙️ Processing: <b>{$stats['processing']}</b>\n"
                    . "✅ Completed: <b>{$stats['completed']}</b>\n"
                    . "❌ Failed: <b>{$stats['failed']}</b>";
                $this->api->editMessageText($chatId, $msgId, $text);
                break;

            case 'maint_on':
                $this->db->update('settings', ['value' => '1'], "key='maintenance_mode'");
                $this->api->editMessageText($chatId, $msgId, '🔧 Maintenance mode <b>ENABLED</b>');
                break;

            case 'maint_off':
                $this->db->update('settings', ['value' => '0'], "key='maintenance_mode'");
                $this->api->editMessageText($chatId, $msgId, '✅ Maintenance mode <b>DISABLED</b>');
                break;

            case 'cleanup':
                $deleted = $this->cache->cleanup();
                $this->api->editMessageText($chatId, $msgId, "🧹 Cleaned <b>{$deleted}</b> expired cache entries.");
                break;
        }
    }

    private function cmdBroadcast(int $chatId, int $userId, string $message): void
    {
        if (!$this->isAdmin($userId)) return;
        if (empty($message)) {
            $this->api->sendMessage($chatId, "Usage: /broadcast <message>");
            return;
        }

        $users = $this->db->fetchAll("SELECT telegram_id FROM users WHERE is_banned=0");
        $sent  = 0; $failed = 0;

        foreach ($users as $u) {
            try {
                $this->api->sendMessage($u['telegram_id'], $message);
                $sent++;
                usleep(50000); // Rate limit: 20 msg/s
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        $this->api->sendMessage($chatId, "📢 Broadcast complete: <b>{$sent}</b> sent, <b>{$failed}</b> failed.");
    }

    private function cmdBan(int $chatId, int $userId, string $args): void
    {
        if (!$this->isAdmin($userId)) return;
        $parts    = explode(' ', trim($args), 2);
        $targetId = (int)($parts[0] ?? 0);
        $reason   = $parts[1] ?? 'Admin ban';

        if (!$targetId) {
            $this->api->sendMessage($chatId, "Usage: /ban <user_id> [reason]");
            return;
        }

        $this->db->update('users', ['is_banned' => 1, 'ban_reason' => $reason], 'telegram_id=?', [$targetId]);
        $this->api->sendMessage($chatId, "🚫 User <code>{$targetId}</code> banned.");
    }

    private function cmdUnban(int $chatId, int $userId, string $args): void
    {
        if (!$this->isAdmin($userId)) return;
        $targetId = (int)trim($args);
        if (!$targetId) {
            $this->api->sendMessage($chatId, "Usage: /unban <user_id>");
            return;
        }
        $this->db->update('users', ['is_banned' => 0, 'ban_reason' => null], 'telegram_id=?', [$targetId]);
        $this->api->sendMessage($chatId, "✅ User <code>{$targetId}</code> unbanned.");
    }

    private function cmdQueueStats(int $chatId, int $userId): void
    {
        if (!$this->isAdmin($userId)) return;
        $stats = $this->queue->getQueueStats();
        $text  = "📊 <b>Queue Statistics</b>\n\n"
            . "⏳ Pending: <b>{$stats['pending']}</b>\n"
            . "⚙️ Processing: <b>{$stats['processing']}</b>\n"
            . "✅ Completed: <b>{$stats['completed']}</b>\n"
            . "❌ Failed: <b>{$stats['failed']}</b>\n"
            . "📦 Total (24h): <b>{$stats['total']}</b>";
        $this->api->sendMessage($chatId, $text);
    }

    // ─── Inline Query Handler ────────────────────────────────────────────────

    private function handleInline(array $inline): void
    {
        // Basic inline support: detect URL in query
        $query = $inline['query'] ?? '';
        // For now, just return an empty result set
        $this->api->request('answerInlineQuery', [
            'inline_query_id' => $inline['id'],
            'results'         => [],
            'cache_time'      => 5,
        ]);
    }

    // ─── Cache Delivery ───────────────────────────────────────────────────────

    private function sendCachedFile(int $chatId, array $cached, int $userId): void
    {
        $caption = $this->i18n->t('download.from_cache') . "\n\n"
            . "📌 <b>" . htmlspecialchars($cached['title'] ?? 'Media') . "</b>\n"
            . "🌐 " . ($cached['platform'] ?? 'unknown');

        try {
            $result = $this->api->forwardFile($chatId, $cached['file_id'], $cached['file_type'], ['caption' => $caption]);

            // Log to history
            $this->db->insert('download_history', [
                'user_id'   => $userId,
                'url'       => $cached['url'],
                'platform'  => $cached['platform'] ?? '',
                'title'     => $cached['title'] ?? '',
                'file_id'   => $cached['file_id'],
                'file_type' => $cached['file_type'],
                'file_size' => $cached['file_size'] ?? 0,
                'duration'  => $cached['duration'] ?? null,
                'quality'   => $cached['quality'] ?? '',
                'from_cache'=> 1,
                'status'    => 'success',
            ]);

        } catch (\Throwable $e) {
            // Cache entry might be stale — invalidate
            $this->cache->invalidate($cached['url']);
            $this->api->sendMessage($chatId, $this->i18n->t('errors.general'));
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function upsertUser(array $from): array
    {
        $existing = $this->db->fetchOne(
            "SELECT * FROM users WHERE telegram_id=?", [$from['id']]
        );

        $data = [
            'telegram_id' => $from['id'],
            'username'    => $from['username'] ?? null,
            'first_name'  => $from['first_name'] ?? '',
            'last_name'   => $from['last_name'] ?? '',
            'last_seen'   => date('Y-m-d H:i:s'),
        ];

        if (!$existing) {
            $data['lang_pref'] = $from['language_code'] ?? 'en';
            $this->db->upsert('users', $data, ['telegram_id']);
            $this->updateDailyStats('new_users');
            return array_merge($data, ['is_banned' => 0, 'created_at' => date('Y-m-d H:i:s')]);
        }

        $this->db->update('users', [
            'username'   => $data['username'],
            'first_name' => $data['first_name'],
            'last_name'  => $data['last_name'],
            'last_seen'  => $data['last_seen'],
        ], 'telegram_id=?', [$from['id']]);

        return array_merge($existing, $data);
    }

    private function isAdmin(int $userId): bool
    {
        return in_array($userId, $this->adminIds, true);
    }

    private function isRateLimited(int $userId): bool
    {
        if ($this->isAdmin($userId)) return false;

        $windowKey = date('Y-m-d-H');
        $limit     = $this->config['rate_limit']['downloads_per_hour'];

        $count = (int)$this->db->fetchColumn(
            "SELECT count FROM rate_limits WHERE user_id=? AND window_type='hour' AND window_key=?",
            [$userId, $windowKey]
        );

        if ($count >= $limit) return true;

        $this->db->upsert('rate_limits', [
            'user_id'     => $userId,
            'window_type' => 'hour',
            'window_key'  => $windowKey,
            'count'       => $count + 1,
        ], ['user_id', 'window_type', 'window_key']);

        return false;
    }

    private function isMaintenanceMode(): bool
    {
        return (bool)(int)($this->db->fetchColumn("SELECT value FROM settings WHERE key='maintenance_mode'") ?: '0');
    }

    private function estimateETA(int $position): string
    {
        $avgSeconds = 45; // avg 45s per download
        $total      = $position * $avgSeconds;
        if ($total < 60)   return "{$total}s";
        if ($total < 3600) return round($total / 60) . " min";
        return round($total / 3600, 1) . " hr";
    }

    private function storeUrlMapping(string $hash, string $url): void
    {
        // Store in settings table temporarily
        $this->db->upsert('settings', [
            'key'        => "url_map:{$hash}",
            'value'      => $url,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['key']);
    }

    private function resolveUrlMapping(string $hash): ?string
    {
        return $this->db->fetchColumn(
            "SELECT value FROM settings WHERE key=?",
            ["url_map:{$hash}"]
        ) ?: null;
    }

    private function updateDailyStats(string $field): void
    {
        $today = date('Y-m-d');
        $this->db->upsert('stats_daily', [
            'date'  => $today,
            $field  => 1,
        ], ['date']);

        $this->db->query(
            "UPDATE stats_daily SET {$field} = {$field} + 1 WHERE date = ?",
            [$today]
        );
    }

    private function getAdminStats(): array
    {
        $users     = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM users");
        $downloads = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM download_history");
        $cStats    = $this->cache->getStats();
        $qStats    = $this->queue->getQueueStats();
        $newUsers  = (int)$this->db->fetchColumn("SELECT new_users FROM stats_daily WHERE date=?", [date('Y-m-d')]) ?: 0;
        $todayDl   = (int)$this->db->fetchColumn("SELECT total_downloads FROM stats_daily WHERE date=?", [date('Y-m-d')]) ?: 0;

        return [
            'users'      => $users,
            'downloads'  => $downloads,
            'cache_rate' => $cStats['hit_rate'],
            'queue'      => $qStats['pending'],
            'workers'    => $qStats['processing'],
            'new_users'  => $newUsers,
            'today_dl'   => $todayDl,
        ];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576)    return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024)       return round($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }
}
