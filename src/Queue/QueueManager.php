<?php
declare(strict_types=1);

namespace MediaBot\Queue;

use MediaBot\Database\DatabaseManager;

/**
 * QueueManager — manages the async download queue stored in SQLite.
 * Supports priority queuing, retry logic, and worker coordination.
 */
class QueueManager
{
    private DatabaseManager $db;
    private array $config;

    public function __construct(DatabaseManager $db, array $config)
    {
        $this->db     = $db;
        $this->config = $config;
    }

    // ─── Enqueue ────────────────────────────────────────────────────────────

    public function enqueue(array $job): int
    {
        // Check queue size limit
        $queueSize = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM download_queue WHERE status IN ('pending','processing')"
        );

        $maxSize = (int)($this->db->fetchColumn("SELECT value FROM settings WHERE key='max_queue_size'") ?: 1000);

        if ($queueSize >= $maxSize) {
            throw new \OverflowException("Queue is full ({$queueSize}/{$maxSize})");
        }

        // Check for duplicate pending jobs from same user
        $existing = $this->db->fetchOne(
            "SELECT id FROM download_queue WHERE user_id=? AND url=? AND status IN ('pending','processing') LIMIT 1",
            [$job['user_id'], $job['url']]
        );
        if ($existing) {
            return $existing['id'];
        }

        return $this->db->insert('download_queue', [
            'user_id'    => $job['user_id'],
            'chat_id'    => $job['chat_id'],
            'message_id' => $job['message_id'] ?? null,
            'url'        => $job['url'],
            'platform'   => $job['platform'] ?? 'unknown',
            'format'     => $job['format'] ?? 'best',
            'quality'    => $job['quality'] ?? '720',
            'priority'   => $job['priority'] ?? 5,
            'status'     => 'pending',
        ]);
    }

    // ─── Worker: Claim next job ─────────────────────────────────────────────

    public function claimNextJob(int $workerId): ?array
    {
        // Use SQLite WAL + BEGIN IMMEDIATE for safe concurrent claiming
        $this->db->beginTransaction();
        try {
            $job = $this->db->fetchOne(
                "SELECT * FROM download_queue
                 WHERE status = 'pending'
                 AND retry_count < ?
                 ORDER BY priority DESC, created_at ASC
                 LIMIT 1",
                [$this->config['retry_attempts']]
            );

            if (!$job) {
                $this->db->rollBack();
                return null;
            }

            $this->db->update(
                'download_queue',
                ['status' => 'processing', 'worker_pid' => $workerId, 'started_at' => date('Y-m-d H:i:s')],
                'id = ?',
                [$job['id']]
            );

            $this->db->commit();
            return $job;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ─── Job Status Updates ─────────────────────────────────────────────────

    public function markCompleted(int $jobId): void
    {
        $this->db->update(
            'download_queue',
            ['status' => 'completed', 'completed_at' => date('Y-m-d H:i:s'), 'progress' => 100],
            'id = ?', [$jobId]
        );
    }

    public function markFailed(int $jobId, string $error): void
    {
        $job = $this->db->fetchOne("SELECT retry_count FROM download_queue WHERE id=?", [$jobId]);
        $retries = ($job['retry_count'] ?? 0) + 1;

        if ($retries >= $this->config['retry_attempts']) {
            $this->db->update(
                'download_queue',
                ['status' => 'failed', 'error_message' => $error, 'retry_count' => $retries, 'completed_at' => date('Y-m-d H:i:s')],
                'id = ?', [$jobId]
            );
        } else {
            // Re-queue with backoff delay
            $this->db->update(
                'download_queue',
                [
                    'status'        => 'pending',
                    'error_message' => $error,
                    'retry_count'   => $retries,
                    'worker_pid'    => null,
                    'started_at'    => null,
                    // Lower priority on retry
                    'priority'      => 3,
                ],
                'id = ?', [$jobId]
            );
        }
    }

    public function updateProgress(int $jobId, int $progress): void
    {
        $this->db->update(
            'download_queue',
            ['progress' => $progress],
            'id = ?', [$jobId]
        );
    }

    // ─── Stale Job Recovery ──────────────────────────────────────────────────

    public function recoverStaleJobs(): int
    {
        // Jobs that have been processing for longer than worker_timeout
        $threshold = date('Y-m-d H:i:s', time() - $this->config['worker_timeout']);

        $stale = $this->db->fetchAll(
            "SELECT id FROM download_queue
             WHERE status = 'processing'
             AND started_at < ?",
            [$threshold]
        );

        foreach ($stale as $job) {
            $this->markFailed($job['id'], 'Worker timeout — job re-queued');
        }

        return count($stale);
    }

    // ─── Queries ─────────────────────────────────────────────────────────────

    public function getQueuePosition(int $jobId): int
    {
        $job = $this->db->fetchOne("SELECT priority, created_at FROM download_queue WHERE id=?", [$jobId]);
        if (!$job) return 0;

        return (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM download_queue
             WHERE status = 'pending'
             AND (priority > ? OR (priority = ? AND created_at < ?))",
            [$job['priority'], $job['priority'], $job['created_at']]
        ) + 1;
    }

    public function getQueueStats(): array
    {
        return $this->db->fetchOne(
            "SELECT
                COUNT(*) FILTER (WHERE status='pending')    AS pending,
                COUNT(*) FILTER (WHERE status='processing') AS processing,
                COUNT(*) FILTER (WHERE status='completed')  AS completed,
                COUNT(*) FILTER (WHERE status='failed')     AS failed,
                COUNT(*) AS total
             FROM download_queue
             WHERE created_at > datetime('now', '-1 day')"
        ) ?? [];
    }

    public function getUserQueueCount(int $userId): int
    {
        return (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM download_queue WHERE user_id=? AND status IN ('pending','processing')",
            [$userId]
        );
    }

    public function cleanup(): int
    {
        $threshold = date('Y-m-d H:i:s', strtotime("-{$this->config['cleanup_after_days']} days"));
        $stmt = $this->db->query(
            "DELETE FROM download_queue WHERE status IN ('completed','failed') AND created_at < ?",
            [$threshold]
        );
        return $stmt->rowCount();
    }

    public function getRecentJobs(int $limit = 50, int $offset = 0): array
    {
        return $this->db->fetchAll(
            "SELECT q.*, u.username, u.first_name
             FROM download_queue q
             LEFT JOIN users u ON q.user_id = u.telegram_id
             ORDER BY q.created_at DESC
             LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    public function cancelJob(int $jobId): bool
    {
        $rows = $this->db->update(
            'download_queue',
            ['status' => 'cancelled', 'completed_at' => date('Y-m-d H:i:s')],
            'id=? AND status=\'pending\'',
            [$jobId]
        );
        return $rows > 0;
    }
}
