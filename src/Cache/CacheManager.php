<?php
declare(strict_types=1);

namespace MediaBot\Cache;

use MediaBot\Database\DatabaseManager;

/**
 * CacheManager — stores Telegram file_ids for already-downloaded URLs.
 * Cache key = SHA256(url + quality + format).
 * On hit, we forward the existing file_id instantly — no re-download needed.
 */
class CacheManager
{
    private DatabaseManager $db;
    private int $ttlHours;

    public function __construct(DatabaseManager $db, array $config)
    {
        $this->db       = $db;
        $this->ttlHours = $config['ttl_hours'];
    }

    public function getCacheKey(string $url, string $quality = 'best', string $format = 'video'): string
    {
        // Normalize URL (strip tracking params for better hit rate)
        $url = $this->normalizeUrl($url);
        return hash('sha256', $url . '|' . $quality . '|' . $format);
    }

    public function get(string $url, string $quality = 'best', string $format = 'video'): ?array
    {
        $hash = $this->getCacheKey($url, $quality, $format);

        $entry = $this->db->fetchOne(
            "SELECT * FROM file_cache WHERE url_hash=? AND expires_at > datetime('now')",
            [$hash]
        );

        if ($entry) {
            // Increment hit counter
            $this->db->update('file_cache', ['hit_count' => $entry['hit_count'] + 1], 'id=?', [$entry['id']]);
            return $entry;
        }

        return null;
    }

    public function set(string $url, string $fileId, string $fileType, array $meta = []): void
    {
        $quality = $meta['quality'] ?? 'best';
        $format  = ($fileType === 'audio') ? 'audio' : 'video';
        $hash    = $this->getCacheKey($url, $quality, $format);
        $expires = date('Y-m-d H:i:s', time() + ($this->ttlHours * 3600));

        $this->db->upsert('file_cache', [
            'url_hash'  => $hash,
            'url'       => $url,
            'file_id'   => $fileId,
            'file_type' => $fileType,
            'platform'  => $meta['platform'] ?? null,
            'title'     => $meta['title'] ?? null,
            'duration'  => $meta['duration'] ?? null,
            'file_size' => $meta['file_size'] ?? null,
            'quality'   => $quality,
            'hit_count' => 1,
            'expires_at'=> $expires,
        ], ['url_hash']);
    }

    public function invalidate(string $url): void
    {
        $hashes = [];
        foreach (['best','2160','1440','1080','720','480','360'] as $q) {
            foreach (['video','audio'] as $f) {
                $hashes[] = $this->getCacheKey($url, $q, $f);
            }
        }
        $in = implode(',', array_fill(0, count($hashes), '?'));
        $this->db->query("DELETE FROM file_cache WHERE url_hash IN ({$in})", $hashes);
    }

    public function cleanup(): int
    {
        $stmt = $this->db->query("DELETE FROM file_cache WHERE expires_at <= datetime('now')");
        return $stmt->rowCount();
    }

    public function getStats(): array
    {
        return [
            'total_entries' => (int)$this->db->fetchColumn("SELECT COUNT(*) FROM file_cache"),
            'active_entries'=> (int)$this->db->fetchColumn("SELECT COUNT(*) FROM file_cache WHERE expires_at > datetime('now')"),
            'total_hits'    => (int)$this->db->fetchColumn("SELECT SUM(hit_count) FROM file_cache") ?: 0,
            'hit_rate'      => $this->calculateHitRate(),
        ];
    }

    private function calculateHitRate(): float
    {
        $total    = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM download_history") ?: 1;
        $fromCache= (int)$this->db->fetchColumn("SELECT COUNT(*) FROM download_history WHERE from_cache=1") ?: 0;
        return round(($fromCache / $total) * 100, 2);
    }

    private function normalizeUrl(string $url): string
    {
        $parsed = parse_url($url);
        if (!$parsed) return $url;

        // Remove tracking/session params
        $stripParams = ['utm_source','utm_medium','utm_campaign','utm_content','utm_term',
                        'fbclid','gclid','ref','si','feature','app','t','igshid'];

        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $params);
            foreach ($stripParams as $p) {
                unset($params[$p]);
            }
            $parsed['query'] = http_build_query($params);
        }

        $url  = ($parsed['scheme'] ?? 'https') . '://';
        $url .= strtolower($parsed['host'] ?? '');
        $url .= ($parsed['path'] ?? '/');
        if (!empty($parsed['query'])) {
            $url .= '?' . $parsed['query'];
        }

        return rtrim($url, '/');
    }
}
