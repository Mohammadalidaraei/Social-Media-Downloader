<?php
declare(strict_types=1);

namespace MediaBot\I18n;

/**
 * Translator — loads language files and resolves translation keys
 * with parameter interpolation and RTL detection.
 */
class Translator
{
    private static ?Translator $instance = null;
    private array $translations = [];
    private string $currentLang;
    private string $defaultLang;
    private array $rtlLangs;
    private string $langDir;

    private function __construct(array $config)
    {
        $this->langDir     = $config['lang_dir'];
        $this->defaultLang = $config['default_lang'];
        $this->rtlLangs    = $config['rtl_langs'];
        $this->currentLang = $config['default_lang'];
    }

    public static function getInstance(array $config = []): self
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    public function setLang(string $lang): void
    {
        $supported = ['en', 'fa', 'ru', 'zh', 'es', 'de', 'ar'];
        $this->currentLang = in_array($lang, $supported) ? $lang : $this->defaultLang;
        $this->loadLang($this->currentLang);
    }

    public function getLang(): string { return $this->currentLang; }

    public function isRTL(): bool { return in_array($this->currentLang, $this->rtlLangs); }

    private function loadLang(string $lang): void
    {
        if (isset($this->translations[$lang])) return;

        $path = $this->langDir . $lang . '.php';
        if (!file_exists($path)) {
            $path = $this->langDir . $this->defaultLang . '.php';
        }

        $this->translations[$lang] = require $path;
    }

    public function t(string $key, array $params = [], ?string $lang = null): string
    {
        $lang = $lang ?? $this->currentLang;
        $this->loadLang($lang);

        $parts = explode('.', $key);
        $value = $this->translations[$lang] ?? [];

        foreach ($parts as $part) {
            if (!isset($value[$part])) {
                // fallback to English
                $this->loadLang($this->defaultLang);
                $value = $this->translations[$this->defaultLang] ?? [];
                foreach ($parts as $p) {
                    $value = $value[$p] ?? null;
                    if ($value === null) return $key;
                }
                break;
            }
            $value = $value[$part];
        }

        if (!is_string($value)) return $key;

        // Interpolate {param} placeholders
        foreach ($params as $k => $v) {
            $value = str_replace('{' . $k . '}', (string)$v, $value);
        }

        return $value;
    }

    public function getLanguageMenu(): string
    {
        return implode('  ', [
            '🇬🇧', '🇮🇷', '🇷🇺', '🇨🇳', '🇪🇸', '🇩🇪', '🇸🇦'
        ]);
    }

    public static function langFlag(string $code): string
    {
        return match($code) {
            'en' => '🇬🇧 English',
            'fa' => '🇮🇷 فارسی',
            'ru' => '🇷🇺 Русский',
            'zh' => '🇨🇳 中文',
            'es' => '🇪🇸 Español',
            'de' => '🇩🇪 Deutsch',
            'ar' => '🇸🇦 العربية',
            default => $code,
        };
    }
}
