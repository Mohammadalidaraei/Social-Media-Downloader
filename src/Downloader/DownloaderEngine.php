<?php
declare(strict_types=1);

namespace MediaBot\Downloader;

use MediaBot\Cache\CacheManager;

/**
 * DownloaderEngine — wraps yt-dlp (via Python) and direct cURL downloads.
 * Supports multi-process execution, progress callbacks, and smart routing.
 */
class DownloaderEngine
{
    private array $config;
    private CacheManager $cache;
    private string $downloadDir;
    private string $tempDir;

    public function __construct(array $config, CacheManager $cache)
    {
        $this->config      = $config;
        $this->cache       = $cache;
        $this->downloadDir = rtrim($config['download_dir'], '/') . '/';
        $this->tempDir     = rtrim($config['temp_dir'], '/') . '/';

        foreach ([$this->downloadDir, $this->tempDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    // ─── Main Entry Point ────────────────────────────────────────────────────

    /**
     * Download media from URL.
     * Returns array: ['path' => '/tmp/file.mp4', 'type' => 'video', 'meta' => [...]]
     */
    public function download(string $url, array $options = [], ?callable $progressCallback = null): array
    {
        $platform = $options['platform'] ?? 'unknown';
        $format   = $options['format']   ?? 'video';
        $quality  = $options['quality']  ?? '720';

        // Route to correct downloader
        if ($this->isDirectLink($url)) {
            return $this->downloadDirect($url, $options, $progressCallback);
        }

        return $this->downloadWithYtDlp($url, $options, $progressCallback);
    }

    // ─── yt-dlp Downloader ───────────────────────────────────────────────────

    public function downloadWithYtDlp(string $url, array $options = [], ?callable $progressCallback = null): array
    {
        $format  = $options['format']  ?? 'video';
        $quality = $options['quality'] ?? '720';
        $jobId   = $options['job_id']  ?? uniqid('dl_');

        $outputTemplate = $this->tempDir . $jobId . '.%(ext)s';
        $infoFile       = $this->tempDir . $jobId . '_info.json';

        // Build yt-dlp format string
        $formatStr = $this->buildFormatString($format, $quality);

        $args = [
            $this->config['ytdlp_path'],
            '--no-playlist',
            '--no-warnings',
            '--newline',
            '--progress',
            '--merge-output-format', 'mp4',
            '--max-filesize', $this->config['max_size_bytes'],
            '--format', escapeshellarg($formatStr),
            '--output', escapeshellarg($outputTemplate),
            '--write-info-json',
            '--print-json',
            '--user-agent', escapeshellarg($this->config['user_agent']),
            '--socket-timeout', '30',
            '--retries', '3',
            '--fragment-retries', '3',
            '--concurrent-fragments', '4',
        ];

        // Add ffmpeg path if configured
        if (!empty($this->config['ffmpeg_path'])) {
            $args[] = '--ffmpeg-location';
            $args[] = escapeshellarg(dirname($this->config['ffmpeg_path']));
        }

        // Add cookies for restricted content
        $cookiesFile = __DIR__ . '/../../config/cookies.txt';
        if (file_exists($cookiesFile)) {
            $args[] = '--cookies';
            $args[] = escapeshellarg($cookiesFile);
        }

        $args[] = escapeshellarg($url);
        $cmd    = implode(' ', $args);

        // Execute with progress parsing
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start yt-dlp process');
        }

        fclose($pipes[0]);

        $stdout   = '';
        $stderr   = '';
        $progress = 0;

        // Non-blocking reads with progress parsing
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $startTime = time();
        while (true) {
            $out = fgets($pipes[1]);
            $err = fgets($pipes[2]);

            if ($out !== false) {
                $stdout .= $out;
                $newProgress = $this->parseProgress($out);
                if ($newProgress > $progress) {
                    $progress = $newProgress;
                    if ($progressCallback) {
                        $progressCallback($progress, 'downloading');
                    }
                }
            }

            if ($err !== false) {
                $stderr .= $err;
            }

            if (feof($pipes[1]) && feof($pipes[2])) break;

            // Timeout guard
            if (time() - $startTime > $this->config['worker_timeout'] ?? 300) {
                proc_terminate($process);
                throw new \RuntimeException('yt-dlp download timeout');
            }

            usleep(50000); // 50ms
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $reason = $this->parseYtDlpError($stderr);
            throw new \RuntimeException($reason);
        }

        // Find the output file
        $outputFile = $this->findOutputFile($this->tempDir, $jobId);
        if (!$outputFile) {
            throw new \RuntimeException('Download completed but output file not found');
        }

        // Parse metadata
        $meta = $this->parseInfoJson($infoFile);

        // Cleanup info json
        @unlink($infoFile);

        return [
            'path'   => $outputFile,
            'type'   => ($format === 'audio') ? 'audio' : 'video',
            'meta'   => array_merge($meta, [
                'quality'  => $quality,
                'format'   => $format,
                'platform' => $options['platform'] ?? $meta['platform'] ?? 'unknown',
            ]),
        ];
    }

    // ─── Direct cURL Downloader ──────────────────────────────────────────────

    public function downloadDirect(string $url, array $options = [], ?callable $progressCallback = null): array
    {
        $jobId    = $options['job_id'] ?? uniqid('direct_');
        $ext      = $this->guessExtension($url);
        $outPath  = $this->tempDir . $jobId . '.' . $ext;

        $fp = fopen($outPath, 'wb');
        if (!$fp) {
            throw new \RuntimeException("Cannot open output file: {$outPath}");
        }

        $totalSize    = 0;
        $downloaded   = 0;
        $lastProgress = 0;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => $this->config['worker_timeout'] ?? 300,
            CURLOPT_USERAGENT      => $this->config['user_agent'],
            CURLOPT_FILE           => $fp,
            CURLOPT_HEADERFUNCTION => function($ch, $header) use (&$totalSize) {
                if (preg_match('/Content-Length:\s*(\d+)/i', $header, $m)) {
                    $totalSize = (int)$m[1];
                }
                return strlen($header);
            },
            CURLOPT_PROGRESSFUNCTION => function($ch, $dlTotal, $dlNow, $ulTotal, $ulNow)
                use (&$downloaded, &$lastProgress, &$totalSize, $progressCallback) {
                $downloaded = $dlNow;
                if ($dlTotal > 0) {
                    $pct = (int)(($dlNow / $dlTotal) * 100);
                    if ($pct > $lastProgress + 4 && $progressCallback) {
                        $lastProgress = $pct;
                        $progressCallback($pct, 'downloading');
                    }
                }
                return 0;
            },
            CURLOPT_NOPROGRESS => false,
        ]);

        $result = curl_exec($ch);
        $error  = curl_error($ch);
        $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (!$result || $error) {
            @unlink($outPath);
            throw new \RuntimeException("Direct download failed: {$error}");
        }

        if ($code >= 400) {
            @unlink($outPath);
            throw new \RuntimeException("HTTP {$code} — resource not available");
        }

        $fileSize = filesize($outPath);
        if ($fileSize > $this->config['max_size_bytes']) {
            @unlink($outPath);
            throw new \RuntimeException("File too large: " . $this->formatBytes($fileSize));
        }

        $type = in_array($ext, ['mp3','ogg','m4a','flac','wav','aac']) ? 'audio' : 'video';

        return [
            'path' => $outPath,
            'type' => $type,
            'meta' => [
                'title'     => basename($url),
                'duration'  => null,
                'file_size' => $fileSize,
                'platform'  => 'direct',
                'quality'   => 'original',
                'format'    => $type,
            ],
        ];
    }

    // ─── Metadata Probe (via Python + yt-dlp) ───────────────────────────────

    public function getMediaInfo(string $url): array
    {
        $cmd = sprintf(
            '%s %s --dump-json --no-playlist --no-warnings --skip-download %s 2>/dev/null',
            escapeshellarg($this->config['ytdlp_path']),
            '--socket-timeout 15',
            escapeshellarg($url)
        );

        $output = shell_exec($cmd);
        if (!$output) return [];

        $info = json_decode($output, true);
        if (!$info) return [];

        return [
            'title'      => $info['title'] ?? 'Unknown',
            'duration'   => $info['duration'] ?? null,
            'uploader'   => $info['uploader'] ?? null,
            'view_count' => $info['view_count'] ?? null,
            'like_count' => $info['like_count'] ?? null,
            'thumbnail'  => $info['thumbnail'] ?? null,
            'formats'    => $this->parseAvailableFormats($info['formats'] ?? []),
            'platform'   => $info['extractor_key'] ?? 'unknown',
        ];
    }

    // ─── Python Bridge for advanced operations ───────────────────────────────

    public function runPythonHelper(string $script, array $args = []): string
    {
        $scriptPath = __DIR__ . '/../../scripts/' . $script;
        if (!file_exists($scriptPath)) {
            throw new \RuntimeException("Python helper not found: {$script}");
        }

        $argStr = implode(' ', array_map('escapeshellarg', $args));
        $cmd    = sprintf('%s %s %s 2>&1',
            escapeshellarg($this->config['python_path']),
            escapeshellarg($scriptPath),
            $argStr
        );

        $output   = '';
        $exitCode = 0;
        exec($cmd, $lines, $exitCode);
        $output = implode("\n", $lines);

        if ($exitCode !== 0) {
            throw new \RuntimeException("Python helper failed: {$output}");
        }

        return $output;
    }

    // ─── Cleanup ─────────────────────────────────────────────────────────────

    public function cleanup(string $filePath): void
    {
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
        // Remove any sidecar files
        $base = pathinfo($filePath, PATHINFO_DIRNAME) . '/' . pathinfo($filePath, PATHINFO_FILENAME);
        foreach (['.json', '.part', '.ytdl', '.vtt', '.srt'] as $ext) {
            if (file_exists($base . $ext)) @unlink($base . $ext);
        }
    }

    public function cleanupOldFiles(int $maxAgeSeconds = 3600): int
    {
        $count     = 0;
        $threshold = time() - $maxAgeSeconds;

        foreach ([$this->tempDir, $this->downloadDir] as $dir) {
            if (!is_dir($dir)) continue;
            foreach (glob($dir . '*') as $file) {
                if (is_file($file) && filemtime($file) < $threshold) {
                    @unlink($file);
                    $count++;
                }
            }
        }

        return $count;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function buildFormatString(string $format, string $quality): string
    {
        if ($format === 'audio') {
            return 'bestaudio[ext=m4a]/bestaudio/best';
        }

        $height = (int)$quality;
        if ($height <= 0) return 'bestvideo+bestaudio/best';

        return "bestvideo[height<={$height}][ext=mp4]+bestaudio[ext=m4a]/"
             . "bestvideo[height<={$height}]+bestaudio/"
             . "best[height<={$height}]/best";
    }

    private function parseProgress(string $line): int
    {
        // [download]  45.2% of 123.45MiB
        if (preg_match('/\[download\]\s+([\d.]+)%/', $line, $m)) {
            return (int)floor((float)$m[1]);
        }
        return 0;
    }

    private function parseYtDlpError(string $stderr): string
    {
        $patterns = [
            '/Private video/'           => 'This video is private',
            '/Video unavailable/'       => 'Video unavailable',
            '/age.restrict/'            => 'Age-restricted content',
            '/geo.?block/'              => 'Content geo-blocked in your region',
            '/not available/'           => 'Content not available',
            '/login required/i'         => 'Login required — private content',
            '/copyright/i'              => 'Content removed due to copyright',
            '/HTTP Error 404/'          => 'Content not found (404)',
            '/HTTP Error 403/'          => 'Access denied (403)',
            '/File is larger than/'     => 'File too large',
            '/Unsupported URL/'         => 'Unsupported platform or URL',
        ];

        foreach ($patterns as $pattern => $message) {
            if (preg_match($pattern, $stderr)) {
                return $message;
            }
        }

        // Extract last error line
        $lines = array_filter(explode("\n", trim($stderr)));
        $last  = end($lines);
        return $last ?: 'Download failed — unknown error';
    }

    private function findOutputFile(string $dir, string $jobId): ?string
    {
        $extensions = ['mp4','mkv','webm','mp3','m4a','ogg','flac','wav','mov','avi'];
        foreach ($extensions as $ext) {
            $path = $dir . $jobId . '.' . $ext;
            if (file_exists($path) && filesize($path) > 0) {
                return $path;
            }
        }

        // Glob fallback for complex filenames
        $files = glob($dir . $jobId . '.*');
        foreach ($files as $file) {
            if (!str_ends_with($file, '.json') && !str_ends_with($file, '.part') && filesize($file) > 0) {
                return $file;
            }
        }

        return null;
    }

    private function parseInfoJson(string $path): array
    {
        if (!file_exists($path)) return [];
        $data = json_decode(file_get_contents($path), true);
        if (!$data) return [];

        return [
            'title'      => $data['title'] ?? 'Unknown',
            'duration'   => $data['duration'] ?? null,
            'uploader'   => $data['uploader'] ?? null,
            'thumbnail'  => $data['thumbnail'] ?? null,
            'platform'   => $data['extractor_key'] ?? 'unknown',
            'view_count' => $data['view_count'] ?? null,
            'file_size'  => $data['filesize'] ?? $data['filesize_approx'] ?? null,
        ];
    }

    private function parseAvailableFormats(array $formats): array
    {
        $qualities = [];
        foreach ($formats as $f) {
            if (isset($f['height']) && $f['height'] > 0) {
                $q = (string)$f['height'];
                if (!in_array($q, $qualities)) {
                    $qualities[] = $q;
                }
            }
        }
        rsort($qualities, SORT_NUMERIC);
        return $qualities;
    }

    private function isDirectLink(string $url): bool
    {
        $directExts = ['.mp4','.mkv','.webm','.avi','.mov','.mp3','.ogg','.m4a','.flac','.wav','.m3u8'];
        $urlLower   = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
        foreach ($directExts as $ext) {
            if (str_ends_with($urlLower, $ext)) return true;
        }
        return false;
    }

    private function guessExtension(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $valid = ['mp4','mkv','webm','mp3','m4a','ogg','wav','flac','mov','avi','m3u8'];
        return in_array($ext, $valid) ? $ext : 'mp4';
    }

    public function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576)    return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024)       return round($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }

    public function formatDuration(?int $seconds): string
    {
        if (!$seconds) return 'N/A';
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        if ($h > 0) return sprintf('%d:%02d:%02d', $h, $m, $s);
        return sprintf('%d:%02d', $m, $s);
    }
}
