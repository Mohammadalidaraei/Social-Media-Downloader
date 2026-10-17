<?php
declare(strict_types=1);

namespace MediaBot\Bot;

/**
 * Logger — PSR-3 inspired file logger with daily rotation.
 */
class Logger
{
    private static string $logDir   = '';
    private static string $level    = 'info';
    private static bool   $enabled  = true;
    private static array  $levels   = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    public static function init(array $config): void
    {
        self::$logDir  = rtrim($config['dir'], '/') . '/';
        self::$level   = $config['level'] ?? 'info';
        self::$enabled = $config['enabled'] ?? true;

        if (!is_dir(self::$logDir)) {
            mkdir(self::$logDir, 0755, true);
        }
    }

    public static function debug(string $msg, array $ctx = []): void   { self::log('debug', $msg, $ctx); }
    public static function info(string $msg, array $ctx = []): void    { self::log('info', $msg, $ctx); }
    public static function warning(string $msg, array $ctx = []): void { self::log('warning', $msg, $ctx); }
    public static function error(string $msg, array $ctx = []): void   { self::log('error', $msg, $ctx); }

    private static function log(string $level, string $msg, array $ctx = []): void
    {
        if (!self::$enabled) return;
        if ((self::$levels[$level] ?? 0) < (self::$levels[self::$level] ?? 1)) return;

        $date    = date('Y-m-d');
        $time    = date('H:i:s');
        $file    = self::$logDir . "mediabot-{$date}.log";
        $ctxStr  = empty($ctx) ? '' : ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE);
        $line    = "[{$date} {$time}] [{$level}] {$msg}{$ctxStr}" . PHP_EOL;

        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    public static function cleanOldLogs(int $keepDays = 30): int
    {
        if (!is_dir(self::$logDir)) return 0;
        $count     = 0;
        $threshold = time() - ($keepDays * 86400);

        foreach (glob(self::$logDir . 'mediabot-*.log') as $file) {
            if (filemtime($file) < $threshold) {
                @unlink($file);
                $count++;
            }
        }

        return $count;
    }
}
