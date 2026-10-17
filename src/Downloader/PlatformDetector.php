<?php
declare(strict_types=1);

namespace MediaBot\Downloader;

/**
 * PlatformDetector — identifies the platform from a URL
 * and validates/normalizes the URL before processing.
 */
class PlatformDetector
{
    private array $platforms;

    public function __construct(array $platformsConfig)
    {
        $this->platforms = $platformsConfig;
    }

    public function detect(string $url): array
    {
        $url    = trim($url);
        $parsed = parse_url($url);
        $host   = strtolower($parsed['host'] ?? '');
        $path   = strtolower($parsed['path'] ?? '');

        // Remove www.
        $host = preg_replace('/^www\./', '', $host);

        foreach ($this->platforms as $name => $data) {
            foreach ($data['patterns'] as $pattern) {
                if (str_contains($host, $pattern) || str_ends_with($path, $pattern)) {
                    return [
                        'name'      => $name,
                        'icon'      => $data['icon'],
                        'supported' => true,
                        'url'       => $this->normalizeUrl($url, $name),
                    ];
                }
            }
        }

        // Check if it's a direct media link
        if ($this->isMediaUrl($url)) {
            return ['name' => 'direct', 'icon' => '🔗', 'supported' => true, 'url' => $url];
        }

        return ['name' => 'unknown', 'icon' => '❓', 'supported' => false, 'url' => $url];
    }

    public function isValidUrl(string $text): bool
    {
        $text = trim($text);
        return (bool)filter_var($text, FILTER_VALIDATE_URL)
            && preg_match('/^https?:\/\//i', $text);
    }

    public function extractUrls(string $text): array
    {
        preg_match_all('/https?:\/\/[^\s<>"{}|\\^`\[\]]+/i', $text, $matches);
        return array_unique($matches[0] ?? []);
    }

    private function isMediaUrl(string $url): bool
    {
        $mediaExts = ['mp4','webm','mkv','avi','mov','mp3','m4a','ogg','flac','wav','m3u8','ts'];
        $path      = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
        $ext       = pathinfo($path, PATHINFO_EXTENSION);
        return in_array($ext, $mediaExts);
    }

    private function normalizeUrl(string $url, string $platform): string
    {
        return match($platform) {
            'youtube'  => $this->normalizeYouTube($url),
            'twitter'  => $this->normalizeTwitter($url),
            default    => $url,
        };
    }

    private function normalizeYouTube(string $url): string
    {
        // Convert youtu.be short links
        if (str_contains($url, 'youtu.be/')) {
            $id = basename(parse_url($url, PHP_URL_PATH));
            return "https://www.youtube.com/watch?v={$id}";
        }
        // Remove playlist from single video
        if (str_contains($url, 'watch?') && str_contains($url, '&list=')) {
            $parsed = parse_url($url);
            parse_str($parsed['query'] ?? '', $params);
            return "https://www.youtube.com/watch?v=" . ($params['v'] ?? '');
        }
        return $url;
    }

    private function normalizeTwitter(string $url): string
    {
        // Normalize x.com → twitter.com for yt-dlp compatibility
        return str_replace('//x.com/', '//twitter.com/', $url);
    }
}
