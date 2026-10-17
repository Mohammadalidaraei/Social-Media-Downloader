<?php
declare(strict_types=1);

namespace MediaBot\Database;

/**
 * DatabaseManager — SQLite connection manager with WAL mode,
 * prepared statement caching, and automatic schema migration.
 */
class DatabaseManager
{
    private static ?DatabaseManager $instance = null;
    private \PDO $pdo;
    private array $stmtCache = [];
    private array $config;

    private function __construct(array $config)
    {
        $this->config = $config;
        $this->connect();
        $this->configure();
        $this->migrate();
    }

    public static function getInstance(array $config = []): self
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    private function connect(): void
    {
        $dir = dirname($this->config['path']);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->pdo = new \PDO(
            'sqlite:' . $this->config['path'],
            null,
            null,
            [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }

    private function configure(): void
    {
        $pragmas = [
            "PRAGMA journal_mode = WAL",
            "PRAGMA synchronous = NORMAL",
            "PRAGMA foreign_keys = ON",
            "PRAGMA cache_size = {$this->config['cache_size']}",
            "PRAGMA busy_timeout = {$this->config['busy_timeout']}",
            "PRAGMA temp_store = MEMORY",
            "PRAGMA mmap_size = 268435456",
            "PRAGMA page_size = 4096",
        ];

        foreach ($pragmas as $pragma) {
            $this->pdo->exec($pragma);
        }
    }

    private function migrate(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS schema_versions (
                version     INTEGER PRIMARY KEY,
                applied_at  TEXT DEFAULT (datetime('now'))
            );
        ");

        $currentVersion = (int)($this->pdo->query("SELECT MAX(version) FROM schema_versions")->fetchColumn() ?: 0);
        $migrations = $this->getMigrations();

        foreach ($migrations as $version => $sql) {
            if ($version > $currentVersion) {
                $this->pdo->beginTransaction();
                try {
                    $this->pdo->exec($sql);
                    $this->prepare("INSERT INTO schema_versions (version) VALUES (?)")->execute([$version]);
                    $this->pdo->commit();
                } catch (\Throwable $e) {
                    $this->pdo->rollBack();
                    throw new \RuntimeException("Migration v{$version} failed: " . $e->getMessage());
                }
            }
        }
    }

    private function getMigrations(): array
    {
        return [
            1 => "
                -- Users table
                CREATE TABLE IF NOT EXISTS users (
                    id              INTEGER PRIMARY KEY,
                    telegram_id     INTEGER UNIQUE NOT NULL,
                    username        TEXT,
                    first_name      TEXT,
                    last_name       TEXT,
                    language_code   TEXT DEFAULT 'en',
                    lang_pref       TEXT DEFAULT 'en',
                    is_banned       INTEGER DEFAULT 0,
                    ban_reason      TEXT,
                    total_downloads INTEGER DEFAULT 0,
                    last_seen       TEXT,
                    created_at      TEXT DEFAULT (datetime('now'))
                );
                CREATE INDEX IF NOT EXISTS idx_users_telegram_id ON users(telegram_id);
                CREATE INDEX IF NOT EXISTS idx_users_is_banned ON users(is_banned);

                -- Download queue
                CREATE TABLE IF NOT EXISTS download_queue (
                    id              INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id         INTEGER NOT NULL,
                    chat_id         INTEGER NOT NULL,
                    message_id      INTEGER,
                    url             TEXT NOT NULL,
                    platform        TEXT,
                    format          TEXT DEFAULT 'best',
                    quality         TEXT DEFAULT '720',
                    status          TEXT DEFAULT 'pending',
                    worker_pid      INTEGER,
                    progress        INTEGER DEFAULT 0,
                    error_message   TEXT,
                    retry_count     INTEGER DEFAULT 0,
                    priority        INTEGER DEFAULT 5,
                    created_at      TEXT DEFAULT (datetime('now')),
                    started_at      TEXT,
                    completed_at    TEXT,
                    FOREIGN KEY (user_id) REFERENCES users(telegram_id)
                );
                CREATE INDEX IF NOT EXISTS idx_queue_status ON download_queue(status, priority DESC, created_at ASC);
                CREATE INDEX IF NOT EXISTS idx_queue_user ON download_queue(user_id);

                -- File cache (stores Telegram file_id for re-use)
                CREATE TABLE IF NOT EXISTS file_cache (
                    id          INTEGER PRIMARY KEY AUTOINCREMENT,
                    url_hash    TEXT UNIQUE NOT NULL,
                    url         TEXT NOT NULL,
                    file_id     TEXT NOT NULL,
                    file_type   TEXT NOT NULL,
                    platform    TEXT,
                    title       TEXT,
                    duration    INTEGER,
                    file_size   INTEGER,
                    quality     TEXT,
                    hit_count   INTEGER DEFAULT 1,
                    expires_at  TEXT NOT NULL,
                    created_at  TEXT DEFAULT (datetime('now'))
                );
                CREATE INDEX IF NOT EXISTS idx_cache_url_hash ON file_cache(url_hash);
                CREATE INDEX IF NOT EXISTS idx_cache_expires ON file_cache(expires_at);

                -- Download history
                CREATE TABLE IF NOT EXISTS download_history (
                    id              INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id         INTEGER NOT NULL,
                    url             TEXT NOT NULL,
                    platform        TEXT,
                    title           TEXT,
                    file_id         TEXT,
                    file_type       TEXT,
                    file_size       INTEGER DEFAULT 0,
                    duration        INTEGER,
                    quality         TEXT,
                    from_cache      INTEGER DEFAULT 0,
                    status          TEXT DEFAULT 'success',
                    download_time   REAL DEFAULT 0,
                    created_at      TEXT DEFAULT (datetime('now'))
                );
                CREATE INDEX IF NOT EXISTS idx_history_user ON download_history(user_id);
                CREATE INDEX IF NOT EXISTS idx_history_created ON download_history(created_at);

                -- Rate limiting
                CREATE TABLE IF NOT EXISTS rate_limits (
                    user_id     INTEGER NOT NULL,
                    window_type TEXT NOT NULL,
                    window_key  TEXT NOT NULL,
                    count       INTEGER DEFAULT 1,
                    PRIMARY KEY (user_id, window_type, window_key)
                );

                -- Admin sessions
                CREATE TABLE IF NOT EXISTS admin_sessions (
                    token       TEXT PRIMARY KEY,
                    user_id     INTEGER NOT NULL,
                    ip_address  TEXT,
                    created_at  TEXT DEFAULT (datetime('now')),
                    expires_at  TEXT NOT NULL
                );

                -- Bot statistics (daily snapshots)
                CREATE TABLE IF NOT EXISTS stats_daily (
                    date            TEXT PRIMARY KEY,
                    new_users       INTEGER DEFAULT 0,
                    active_users    INTEGER DEFAULT 0,
                    total_downloads INTEGER DEFAULT 0,
                    cache_hits      INTEGER DEFAULT 0,
                    failed_downloads INTEGER DEFAULT 0,
                    data_served_bytes INTEGER DEFAULT 0
                );

                -- Settings key-value store
                CREATE TABLE IF NOT EXISTS settings (
                    key         TEXT PRIMARY KEY,
                    value       TEXT,
                    updated_at  TEXT DEFAULT (datetime('now'))
                );

                -- Insert default settings
                INSERT OR IGNORE INTO settings (key, value) VALUES
                    ('maintenance_mode', '0'),
                    ('max_queue_size', '1000'),
                    ('broadcast_message', ''),
                    ('welcome_bonus_downloads', '0');
            ",
        ];
    }

    public function prepare(string $sql): \PDOStatement
    {
        $key = md5($sql);
        if (!isset($this->stmtCache[$key])) {
            $this->stmtCache[$key] = $this->pdo->prepare($sql);
        }
        return $this->stmtCache[$key];
    }

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $result = $this->query($sql, $params)->fetch();
        return $result ?: null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchColumn(string $sql, array $params = [], int $column = 0): mixed
    {
        return $this->query($sql, $params)->fetchColumn($column);
    }

    public function insert(string $table, array $data): int
    {
        $cols   = implode(', ', array_keys($data));
        $places = implode(', ', array_fill(0, count($data), '?'));
        $this->query("INSERT INTO {$table} ({$cols}) VALUES ({$places})", array_values($data));
        return (int)$this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($data)));
        $stmt = $this->query(
            "UPDATE {$table} SET {$sets} WHERE {$where}",
            array_merge(array_values($data), $whereParams)
        );
        return $stmt->rowCount();
    }

    public function upsert(string $table, array $data, array $conflictCols): void
    {
        $cols        = implode(', ', array_keys($data));
        $places      = implode(', ', array_fill(0, count($data), '?'));
        $conflictStr = implode(', ', $conflictCols);
        $updates     = implode(', ', array_map(fn($k) => "{$k}=excluded.{$k}", array_keys($data)));

        $this->query(
            "INSERT INTO {$table} ({$cols}) VALUES ({$places})
             ON CONFLICT({$conflictStr}) DO UPDATE SET {$updates}",
            array_values($data)
        );
    }

    public function beginTransaction(): void { $this->pdo->beginTransaction(); }
    public function commit(): void           { $this->pdo->commit(); }
    public function rollBack(): void         { $this->pdo->rollBack(); }

    public function getPdo(): \PDO { return $this->pdo; }

    public function vacuum(): void
    {
        $this->pdo->exec("VACUUM");
        $this->pdo->exec("ANALYZE");
    }
}
