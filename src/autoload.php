<?php
declare(strict_types=1);

/**
 * MediaBot PSR-4 Autoloader
 */
spl_autoload_register(function (string $class): void {
    $prefix   = 'MediaBot\\';
    $baseDir  = __DIR__ . '/';

    if (!str_starts_with($class, $prefix)) return;

    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file     = $baseDir . $relative . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// Load environment variables from .env if it exists
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if ($key && !getenv($key)) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}
