# 🤖 MediaBot — Advanced Social Media Downloader Telegram Bot

<div align="center">

![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?style=for-the-badge&logo=php)
![Python](https://img.shields.io/badge/Python-3.8+-3776AB?style=for-the-badge&logo=python)
![SQLite](https://img.shields.io/badge/SQLite-WAL-003B57?style=for-the-badge&logo=sqlite)
![Telegram](https://img.shields.io/badge/Telegram-Bot-26A5E4?style=for-the-badge&logo=telegram)
![License](https://img.shields.io/badge/License-MIT-green?style=for-the-badge)

**Ultra-fast · Free · Multi-platform · Scalable**

[English](#english) • [فارسی](#persian)

</div>

---

<a name="english"></a>
# 🇬🇧 English Documentation

## 📋 Table of Contents
1. [Features](#features)
2. [Architecture Overview](#architecture)
3. [Requirements](#requirements)
4. [Installation](#installation)
5. [Configuration](#configuration)
6. [Setting up the Webhook](#webhook)
7. [Running the Worker](#worker)
8. [Admin Panel](#admin-panel)
9. [Supported Platforms](#platforms)
10. [Project Structure](#structure)
11. [Troubleshooting](#troubleshooting)

---

## ✨ Features <a name="features"></a>

| Feature | Description |
|---------|-------------|
| 🚀 **Async Queue** | Non-blocking download queue — webhook never times out |
| ⚡ **Smart Cache** | Forwards existing Telegram `file_id` instantly on repeated requests |
| 🎬 **Multi-Platform** | 30+ platforms via `yt-dlp` (YouTube, Instagram, TikTok, Twitter/X, ...) |
| 🌐 **7 Languages** | English, Persian, Russian, Chinese, Spanish, German, Arabic |
| 🛡️ **Admin Panel** | Real-time web dashboard: users, queue, cache, statistics |
| 🐍 **Python Bridge** | Python + yt-dlp for advanced download features |
| 🗄️ **SQLite WAL** | Concurrent-safe SQLite with WAL mode and 64MB page cache |
| 🔄 **Auto Retry** | Failed downloads retry up to 3× with backoff |
| 📊 **Statistics** | Per-user stats, platform analytics, daily snapshots |
| 🔒 **Rate Limiting** | Per-user hourly download limits with ban system |

---

## 🏗️ Architecture Overview <a name="architecture"></a>

```
┌─────────────────────────────────────────────────────────────┐
│                    Telegram Servers                          │
└─────────────────────┬───────────────────────────────────────┘
                      │ HTTPS POST (webhook)
                      ▼
┌─────────────────────────────────────────────────────────────┐
│  webhook.php  — receives update, responds 200 OK instantly   │
│  ┌──────────────────────────────────────────────────────┐   │
│  │  BotHandler — routes messages, callbacks, commands   │   │
│  │  • URL detection  • Language selection               │   │
│  │  • Cache HIT? ──► Forward file_id instantly (⚡)     │   │
│  │  • Cache MISS? ─► Enqueue job ──► "Added to queue"   │   │
│  └──────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
                      │
                      │ SQLite WAL (download_queue table)
                      ▼
┌─────────────────────────────────────────────────────────────┐
│  worker.php  — Background CLI process (cron / supervisor)    │
│  ┌──────────────────────────────────────────────────────┐   │
│  │  QueueManager.claimNextJob()  ─►  lock + claim job   │   │
│  │  DownloaderEngine.download()  ─►  yt-dlp / cURL      │   │
│  │       │                                              │   │
│  │       ├── PHP proc_open() ──► yt-dlp (subprocess)   │   │
│  │       │        OR                                   │   │
│  │       └── Python helper   ──► downloader.py         │   │
│  │                                                      │   │
│  │  TelegramAPI.sendVideo/Audio()  ─►  Upload file      │   │
│  │  CacheManager.set(url, file_id) ─►  Store in cache   │   │
│  │  QueueManager.markCompleted()   ─►  Job done         │   │
│  └──────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────┐
│  SQLite Database (WAL mode)                                  │
│  • users           • download_queue  • file_cache           │
│  • download_history • rate_limits    • stats_daily          │
│  • settings        • admin_sessions                         │
└─────────────────────────────────────────────────────────────┘
```

### Key Design Decisions

- **Webhook → 200 OK immediately**: PHP flushes HTTP response before processing, preventing Telegram retries
- **SQLite WAL mode**: Allows concurrent reads during worker writes — no blocking
- **file_id caching**: Telegram stores files permanently by `file_id`; we reuse them instead of re-downloading
- **Python bridge**: `downloader.py` handles complex yt-dlp interactions with real-time progress piping

---

## 📦 Requirements <a name="requirements"></a>

### Server Requirements
| Component | Minimum | Recommended |
|-----------|---------|-------------|
| **PHP**   | 8.1     | 8.3         |
| **Python**| 3.8     | 3.11+       |
| **RAM**   | 512 MB  | 2 GB+       |
| **Storage**| 10 GB  | 50 GB+      |
| **CPU**   | 1 vCPU  | 2+ vCPU     |
| **OS**    | Ubuntu 20.04 | Ubuntu 22.04 |

### PHP Extensions Required
```
pdo pdo_sqlite curl json mbstring fileinfo openssl
```

### External Tools
```bash
yt-dlp   ≥ 2024.x
ffmpeg   ≥ 4.x
python3  ≥ 3.8
```

---

## 🚀 Installation <a name="installation"></a>

### Step 1 — Update System

```bash
sudo apt update && sudo apt upgrade -y
```

### Step 2 — Install PHP 8.3

```bash
sudo add-apt-repository ppa:ondrej/php -y
sudo apt install php8.3-cli php8.3-fpm php8.3-curl php8.3-mbstring \
     php8.3-xml php8.3-sqlite3 php8.3-intl -y
```

### Step 3 — Install Python 3 & pip

```bash
sudo apt install python3 python3-pip -y
```

### Step 4 — Install yt-dlp

```bash
sudo curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp \
     -o /usr/local/bin/yt-dlp
sudo chmod a+rx /usr/local/bin/yt-dlp
yt-dlp --version
```

### Step 5 — Install ffmpeg

```bash
sudo apt install ffmpeg -y
ffmpeg -version
```

### Step 6 — Install Web Server (Nginx recommended)

```bash
sudo apt install nginx -y
```

### Step 7 — Clone / Upload Project

```bash
sudo mkdir -p /var/www/mediabot
# Upload your files or clone:
# git clone https://github.com/yourrepo/mediabot.git /var/www/mediabot
sudo chown -R www-data:www-data /var/www/mediabot
sudo chmod -R 755 /var/www/mediabot
```

### Step 8 — Run Installer

```bash
cd /var/www/mediabot
php scripts/setup.php install
```

### Step 9 — Configure Environment

```bash
cp .env.example .env
nano .env
```

Fill in:
```env
BOT_TOKEN=1234567890:ABCdefGHIjklMNOpqrsTUVwxyz
WEBHOOK_URL=https://yourdomain.com/webhook.php
ADMIN_IDS=123456789
ADMIN_PASSWORD=YourSecurePassword@2025
YTDLP_PATH=/usr/local/bin/yt-dlp
PYTHON_PATH=/usr/bin/python3
FFMPEG_PATH=/usr/bin/ffmpeg
```

### Step 10 — Configure Nginx

```nginx
# /etc/nginx/sites-available/mediabot
server {
    listen 443 ssl http2;
    server_name yourdomain.com;

    ssl_certificate     /etc/letsencrypt/live/yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yourdomain.com/privkey.pem;

    root /var/www/mediabot;
    index webhook.php;

    # Only webhook.php is publicly accessible
    location = /webhook.php {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 30;
    }

    # Admin panel
    location /public/admin/ {
        # Restrict by IP for extra security (optional):
        # allow 1.2.3.4;
        # deny all;
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Block everything else
    location / {
        return 404;
    }

    # Block sensitive directories
    location ~* ^/(storage|config|src|worker|scripts|lang)/ {
        deny all;
        return 403;
    }
}

server {
    listen 80;
    server_name yourdomain.com;
    return 301 https://$host$request_uri;
}
```

```bash
sudo ln -s /etc/nginx/sites-available/mediabot /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### Step 11 — SSL Certificate (Let's Encrypt)

```bash
sudo apt install certbot python3-certbot-nginx -y
sudo certbot --nginx -d yourdomain.com
```

### Step 12 — Set Directory Permissions

```bash
sudo mkdir -p /var/www/mediabot/storage/{downloads,cache,logs}
sudo chown -R www-data:www-data /var/www/mediabot/storage
sudo chmod -R 755 /var/www/mediabot/storage
```

---

## ⚙️ Configuration <a name="configuration"></a>

All configuration is in `config/config.php`. Key settings:

```php
// Max concurrent workers
'queue' => ['max_workers' => 4, 'retry_attempts' => 3],

// Cache TTL (hours)
'cache' => ['ttl_hours' => 72],

// Rate limiting
'rate_limit' => ['downloads_per_hour' => 30],

// Video quality cap
'downloader' => ['max_video_quality' => '1080'],
```

---

## 🔗 Setting up the Webhook <a name="webhook"></a>

```bash
php scripts/setup.php webhook
```

Or manually with curl:
```bash
curl "https://api.telegram.org/bot<TOKEN>/setWebhook" \
  -d "url=https://yourdomain.com/webhook.php" \
  -d "allowed_updates=[\"message\",\"callback_query\"]" \
  -d "drop_pending_updates=true"
```

Verify:
```bash
php scripts/setup.php test
```

---

## ⚙️ Running the Worker <a name="worker"></a>

### Option A — Supervisor (Recommended for production)

```bash
sudo apt install supervisor -y
sudo cp scripts/mediabot-worker.conf /etc/supervisor/conf.d/
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start mediabot:
sudo supervisorctl status mediabot:
```

### Option B — Cron (Simple)

```bash
crontab -e
# Add:
* * * * * /usr/bin/php /var/www/mediabot/worker/worker.php >> /var/www/mediabot/storage/logs/cron.log 2>&1
```

### Option C — Screen / tmux (Development)

```bash
screen -S mediabot-worker
php worker/worker.php --daemon
# Ctrl+A, D to detach
```

---

## 🛡️ Admin Panel <a name="admin-panel"></a>

Access at: `https://yourdomain.com/public/admin/`

Default password is set in `.env` → `ADMIN_PASSWORD`.

**Features:**
- 📊 Real-time dashboard with stats cards
- 🔄 Queue monitoring with status filters
- 👥 User management (ban/unban, search)
- ⚡ Cache viewer and cleanup
- 📈 Daily statistics and platform analytics
- 🔧 Maintenance mode toggle

---

## 🌐 Supported Platforms <a name="platforms"></a>

| Platform | Type | Notes |
|----------|------|-------|
| YouTube | Video/Audio | All qualities up to 4K |
| Instagram | Video/Reels/Stories | Public posts only |
| TikTok | Video | With/without watermark |
| Twitter/X | Video/GIF | |
| Facebook | Video | Public posts |
| Reddit | Video | |
| Vimeo | Video | |
| Twitch | Clips | |
| Pinterest | Video/Image | |
| SoundCloud | Audio | |
| Dailymotion | Video | |
| Direct Links | Video/Audio | .mp4, .mp3, .m3u8, etc. |
| **+1000 more** | | Powered by yt-dlp |

---

## 📁 Project Structure <a name="structure"></a>

```
mediabot/
├── config/
│   └── config.php          # Main configuration
├── lang/
│   ├── en.php              # English
│   ├── fa.php              # Persian (فارسی)
│   ├── ru.php              # Russian
│   ├── zh.php              # Chinese
│   ├── es.php              # Spanish
│   ├── de.php              # German
│   └── ar.php              # Arabic
├── public/
│   └── admin/
│       └── index.php       # Admin panel
├── scripts/
│   ├── setup.php           # CLI installer
│   ├── maintenance.php     # Maintenance tasks
│   ├── setup-cron.sh       # Cron setup
│   ├── downloader.py       # Python yt-dlp bridge
│   └── mediabot-worker.conf# Supervisor config
├── src/
│   ├── autoload.php        # PSR-4 autoloader
│   ├── Bot/
│   │   ├── BotHandler.php  # Main update handler
│   │   ├── TelegramAPI.php # Telegram API client
│   │   └── Logger.php      # File logger
│   ├── Cache/
│   │   └── CacheManager.php# file_id cache
│   ├── Database/
│   │   └── DatabaseManager.php # SQLite manager
│   ├── Downloader/
│   │   ├── DownloaderEngine.php# yt-dlp + cURL
│   │   └── PlatformDetector.php# URL platform detection
│   ├── I18n/
│   │   └── Translator.php  # Multi-language system
│   └── Queue/
│       └── QueueManager.php# Async job queue
├── storage/                # Runtime data (git-ignored)
│   ├── downloads/
│   ├── cache/
│   ├── logs/
│   └── mediabot.db         # SQLite database
├── worker/
│   └── worker.php          # Background worker
├── webhook.php             # Entry point (Telegram webhook)
├── .env.example
├── .env                    # Your config (git-ignored)
├── .htaccess
└── README.md
```

---

## 🔧 Troubleshooting <a name="troubleshooting"></a>

### Webhook not receiving updates
```bash
# Check webhook status
curl "https://api.telegram.org/bot<TOKEN>/getWebhookInfo"
# Check Nginx error log
sudo tail -f /var/log/nginx/error.log
```

### Worker not processing jobs
```bash
# Check supervisor status
sudo supervisorctl status mediabot:
# View worker logs
tail -f /var/www/mediabot/storage/logs/worker-stderr.log
# Reset stuck jobs manually
php scripts/maintenance.php reset-queue
```

### yt-dlp download failures
```bash
# Update yt-dlp
sudo yt-dlp --update
# Test manually
yt-dlp -v "https://youtube.com/watch?v=dQw4w9WgXcQ"
```

### SQLite "database is locked" errors
```bash
# Check if WAL mode is enabled
sqlite3 storage/mediabot.db "PRAGMA journal_mode;"
# Should return: wal
```

### PHP permission errors
```bash
sudo chown -R www-data:www-data /var/www/mediabot/storage
sudo chmod -R 755 /var/www/mediabot/storage
```

---

---

<a name="persian"></a>
# 🇮🇷 مستندات فارسی

## 📋 فهرست مطالب
1. [ویژگی‌ها](#fa-features)
2. [معماری سیستم](#fa-architecture)
3. [پیش‌نیازها](#fa-requirements)
4. [نصب و راه‌اندازی](#fa-installation)
5. [تنظیمات](#fa-configuration)
6. [تنظیم Webhook](#fa-webhook)
7. [اجرای Worker](#fa-worker)
8. [پنل ادمین](#fa-admin)
9. [پلتفرم‌های پشتیبانی‌شده](#fa-platforms)
10. [ساختار پروژه](#fa-structure)
11. [رفع مشکلات رایج](#fa-troubleshoot)

---

## ✨ ویژگی‌ها <a name="fa-features"></a>

| ویژگی | توضیح |
|-------|-------|
| 🚀 **صف ناهمزمان** | سیستم صف غیرمسدودکننده — وبهوک هرگز Timeout نمی‌شود |
| ⚡ **کش هوشمند** | ارسال فوری `file_id` برای لینک‌های تکراری بدون دانلود مجدد |
| 🎬 **چند پلتفرمه** | بیش از ۳۰ پلتفرم از طریق `yt-dlp` |
| 🌐 **۷ زبان** | انگلیسی، فارسی، روسی، چینی، اسپانیایی، آلمانی، عربی |
| 🛡️ **پنل ادمین** | داشبورد وب Real-time برای مدیریت |
| 🐍 **Python Bridge** | ادغام Python + yt-dlp برای ویژگی‌های پیشرفته |
| 🗄️ **SQLite WAL** | پایگاه داده SQLite با حالت WAL برای همزمانی ایمن |
| 🔄 **تلاش مجدد** | تلاش مجدد خودکار تا ۳ بار در صورت شکست |

---

## 🏗️ معماری سیستم <a name="fa-architecture"></a>

```
┌─────────────────────────────────────────────────────────────┐
│                     سرورهای تلگرام                           │
└──────────────────────┬──────────────────────────────────────┘
                       │ HTTPS POST (وبهوک)
                       ▼
┌─────────────────────────────────────────────────────────────┐
│  webhook.php — دریافت آپدیت، پاسخ فوری 200 OK               │
│  ┌───────────────────────────────────────────────────────┐  │
│  │  BotHandler — مسیریابی پیام‌ها، کال‌بک‌ها، دستورات    │  │
│  │  • تشخیص URL   • انتخاب زبان                          │  │
│  │  • کش وجود دارد؟ ──► ارسال فوری file_id (⚡)           │  │
│  │  • کش ندارد؟ ──► ثبت در صف ──► "به صف اضافه شد"       │  │
│  └───────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
                       │
                       │ SQLite WAL (جدول download_queue)
                       ▼
┌─────────────────────────────────────────────────────────────┐
│  worker.php — پردازش پس‌زمینه (cron / supervisor)           │
│  ┌───────────────────────────────────────────────────────┐  │
│  │  برداشت کار از صف  ──► قفل‌گذاری ایمن SQLite          │  │
│  │  DownloaderEngine ──► yt-dlp یا cURL مستقیم           │  │
│  │  آپلود فایل ──► TelegramAPI.sendVideo/Audio()          │  │
│  │  ذخیره در کش ──► CacheManager.set(url, file_id)        │  │
│  │  تکمیل کار ──► QueueManager.markCompleted()            │  │
│  └───────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
```

### نکات کلیدی طراحی

- **پاسخ فوری 200 OK**: PHP خروجی HTTP را قبل از پردازش flush می‌کند تا تلگرام Retry نزند
- **حالت WAL در SQLite**: خواندن همزمان حین نوشتن Worker — بدون انسداد
- **کش file_id**: تلگرام فایل‌ها را با `file_id` نگهداری می‌کند؛ ما از آن‌ها استفاده مجدد می‌کنیم
- **Python Bridge**: فایل `downloader.py` با yt-dlp ارتباط برقرار می‌کند

---

## 📦 پیش‌نیازها <a name="fa-requirements"></a>

### نیازهای سرور
| مؤلفه | حداقل | پیشنهادی |
|-------|--------|----------|
| **PHP** | 8.1 | 8.3 |
| **Python** | 3.8 | 3.11+ |
| **RAM** | 512 مگابایت | 2 گیگابایت+ |
| **فضای دیسک** | 10 گیگابایت | 50 گیگابایت+ |
| **CPU** | 1 هسته | 2+ هسته |
| **سیستم‌عامل** | Ubuntu 20.04 | Ubuntu 22.04 |

### افزونه‌های PHP مورد نیاز
```
pdo  pdo_sqlite  curl  json  mbstring  fileinfo  openssl
```

---

## 🚀 نصب و راه‌اندازی <a name="fa-installation"></a>

### مرحله ۱ — به‌روزرسانی سیستم

```bash
sudo apt update && sudo apt upgrade -y
```

### مرحله ۲ — نصب PHP 8.3

```bash
sudo add-apt-repository ppa:ondrej/php -y
sudo apt install php8.3-cli php8.3-fpm php8.3-curl php8.3-mbstring \
     php8.3-xml php8.3-sqlite3 php8.3-intl -y

# بررسی نصب
php -v
```

### مرحله ۳ — نصب Python و pip

```bash
sudo apt install python3 python3-pip -y
python3 --version
```

### مرحله ۴ — نصب yt-dlp

```bash
sudo curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp \
     -o /usr/local/bin/yt-dlp
sudo chmod a+rx /usr/local/bin/yt-dlp

# تأیید نصب
yt-dlp --version
```

### مرحله ۵ — نصب ffmpeg

```bash
sudo apt install ffmpeg -y
ffmpeg -version
```

### مرحله ۶ — نصب Nginx

```bash
sudo apt install nginx -y
```

### مرحله ۷ — آپلود پروژه

```bash
sudo mkdir -p /var/www/mediabot
# فایل‌های پروژه را آپلود کنید یا از git clone استفاده کنید
sudo chown -R www-data:www-data /var/www/mediabot
sudo chmod -R 755 /var/www/mediabot
```

### مرحله ۸ — اجرای نصب‌کننده

```bash
cd /var/www/mediabot
php scripts/setup.php install
```

خروجی باید شبیه این باشد:
```
📦 Installing MediaBot...
  ✅ Created: /var/www/mediabot/storage/downloads/
  ✅ Created: /var/www/mediabot/storage/logs/
  ✅ SQLite database created with WAL mode
  ✅ Checking PHP 8.3... ✅
  ✅ Checking yt-dlp... ✅ v2024.x
  ✅ Checking ffmpeg... ✅
```

### مرحله ۹ — تنظیم متغیرهای محیطی

```bash
cp .env.example .env
nano .env
```

پر کردن مقادیر:
```env
# توکن ربات از @BotFather
BOT_TOKEN=1234567890:ABCdefGHIjklMNOpqrsTUVwxyz

# آدرس HTTPS سرور شما
WEBHOOK_URL=https://yourdomain.com/webhook.php

# شناسه عددی تلگرام ادمین‌ها (با کاما جدا کنید)
ADMIN_IDS=123456789

# رمز ورود پنل ادمین
ADMIN_PASSWORD=YourSecurePassword@2025

# مسیر ابزارها
YTDLP_PATH=/usr/local/bin/yt-dlp
PYTHON_PATH=/usr/bin/python3
FFMPEG_PATH=/usr/bin/ffmpeg
```

> **⚠️ هشدار**: `BOT_TOKEN` و `ADMIN_PASSWORD` را هرگز در مخزن git قرار ندهید!

### مرحله ۱۰ — تنظیم Nginx

فایل زیر را بسازید:
```bash
sudo nano /etc/nginx/sites-available/mediabot
```

```nginx
server {
    listen 443 ssl http2;
    server_name yourdomain.com;

    ssl_certificate     /etc/letsencrypt/live/yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yourdomain.com/privkey.pem;

    root /var/www/mediabot;

    # توجه: ربات با Polling کار می‌کند و دیگر نیازی به webhook نیست.

    # پنل ادمین
    location /public/admin/ {
        # محدودیت IP برای امنیت بیشتر (اختیاری):
        # allow YOUR_IP;
        # deny all;
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # مسدود کردن بقیه مسیرها
    location / { return 404; }
    location ~* ^/(storage|config|src|worker|scripts|lang)/ {
        deny all; return 403;
    }
}

server {
    listen 80;
    server_name yourdomain.com;
    return 301 https://$host$request_uri;
}
```

```bash
sudo ln -s /etc/nginx/sites-available/mediabot /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### مرحله ۱۱ — گواهی SSL (Let's Encrypt)

```bash
sudo apt install certbot python3-certbot-nginx -y
sudo certbot --nginx -d yourdomain.com
# گواهی هر ۹۰ روز خودکار تمدید می‌شود
```

### مرحله ۱۲ — تنظیم مجوزهای پوشه

```bash
sudo mkdir -p /var/www/mediabot/storage/{downloads,cache,logs}
sudo chown -R www-data:www-data /var/www/mediabot/storage
sudo chmod -R 755 /var/www/mediabot/storage
sudo chmod 600 /var/www/mediabot/.env
```

---

## ⚙️ تنظیمات <a name="fa-configuration"></a>

فایل اصلی: `config/config.php`

```php
// حداکثر تعداد Worker های همزمان
'queue' => [
    'max_workers'    => 4,   // با 2 هسته CPU توصیه: 4
    'retry_attempts' => 3,   // تعداد تلاش مجدد
    'worker_timeout' => 300, // ثانیه (5 دقیقه)
],

// مدت نگهداری کش (ساعت)
'cache' => ['ttl_hours' => 72],  // 3 روز

// محدودیت سرعت برای کاربران
'rate_limit' => [
    'downloads_per_hour' => 30,  // تعداد دانلود در ساعت
    'ban_threshold'      => 100, // آستانه بن خودکار
],

// حداکثر کیفیت ویدیو
'downloader' => ['max_video_quality' => '1080'],
```

---

## 🔗 تنظیم Polling <a name="fa-polling"></a>

این ربات به حالت **Polling** تغییر یافته است. برای اجرای آن، نیازی به تنظیم Webhook ندارید. کافیست در ترمینال دستور زیر را وارد کنید:

```bash
php bot.php
```

با این کار ربات به طور مداوم آپدیت‌ها را از سرور تلگرام دریافت می‌کند و اسکریپت Worker نیز به صورت خودکار در پس‌زمینه (Background) راه‌اندازی می‌شود.

---

## ⚙️ اجرای Worker <a name="fa-worker"></a>

### روش الف — Supervisor (پیشنهادی برای سرور پروداکشن)

```bash
# نصب Supervisor
sudo apt install supervisor -y

# کپی کردن تنظیمات
sudo cp scripts/mediabot-worker.conf /etc/supervisor/conf.d/

# بارگذاری مجدد و اجرا
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start mediabot:

# بررسی وضعیت
sudo supervisorctl status mediabot:
# خروجی: mediabot:mediabot-worker_00 RUNNING   pid 12345, uptime 0:00:05
```

### روش ب — Cron (ساده)

```bash
crontab -e
# اضافه کنید:
* * * * * /usr/bin/php /var/www/mediabot/worker/worker.php >> /var/www/mediabot/storage/logs/cron.log 2>&1
```

> **نکته**: در حالت Cron، Worker هر دقیقه اجرا می‌شود. Supervisor بهتر است چون Worker دائماً در حال اجرا است.

### روش ج — Screen (برای توسعه)

```bash
screen -S mediabot-worker
php worker/worker.php --daemon
# برای جدا شدن: Ctrl+A سپس D
# برای بازگشت: screen -r mediabot-worker
```

---

## 🛡️ پنل ادمین <a name="fa-admin"></a>

آدرس دسترسی: `https://yourdomain.com/public/admin/`

رمز ورود در فایل `.env` تنظیم می‌شود (`ADMIN_PASSWORD`).

**قابلیت‌ها:**
- 📊 داشبورد Real-time با کارت‌های آماری
- 🔄 مانیتورینگ صف با فیلتر وضعیت
- 👥 مدیریت کاربران (بن/رفع بن، جستجو)
- ⚡ مشاهده و پاکسازی کش
- 📈 آمار روزانه و آنالیتیکس پلتفرم
- 🔧 تغییر حالت تعمیر و نگهداری

---

## 🌐 پلتفرم‌های پشتیبانی‌شده <a name="fa-platforms"></a>

| پلتفرم | نوع | توضیح |
|--------|-----|-------|
| YouTube | ویدیو/صدا | تمام کیفیت‌ها تا 4K |
| Instagram | ویدیو/ریلز/استوری | فقط پست‌های عمومی |
| TikTok | ویدیو | با/بدون واترمارک |
| Twitter/X | ویدیو/GIF | |
| Facebook | ویدیو | پست‌های عمومی |
| Reddit | ویدیو | |
| Vimeo | ویدیو | |
| Twitch | کلیپ | |
| SoundCloud | صدا | |
| لینک مستقیم | ویدیو/صدا | .mp4, .mp3, .m3u8, ... |
| **+1000 مورد دیگر** | | با استفاده از yt-dlp |

---

## 📁 ساختار پروژه <a name="fa-structure"></a>

```
mediabot/
├── config/config.php          ← تنظیمات اصلی
├── lang/                      ← فایل‌های زبان
│   ├── fa.php                 ← فارسی
│   ├── en.php                 ← انگلیسی
│   └── ...                    ← سایر زبان‌ها
├── public/admin/index.php     ← پنل ادمین
├── scripts/
│   ├── setup.php              ← نصب‌کننده CLI
│   ├── maintenance.php        ← وظایف نگهداری
│   ├── downloader.py          ← پل Python-yt-dlp
│   └── mediabot-worker.conf   ← تنظیمات Supervisor
├── src/
│   ├── autoload.php           ← بارگذار خودکار
│   ├── Bot/BotHandler.php     ← پردازش آپدیت‌ها
│   ├── Bot/TelegramAPI.php    ← کلاینت API تلگرام
│   ├── Cache/CacheManager.php ← کش file_id
│   ├── Database/DatabaseManager.php ← مدیر SQLite
│   ├── Downloader/DownloaderEngine.php ← موتور دانلود
│   ├── Downloader/PlatformDetector.php ← تشخیص پلتفرم
│   ├── I18n/Translator.php    ← سیستم چند زبانه
│   └── Queue/QueueManager.php ← مدیریت صف
├── storage/                   ← داده‌های Runtime
│   ├── downloads/             ← فایل‌های موقت
│   ├── cache/                 ← کش فایل
│   ├── logs/                  ← لاگ‌ها
│   └── mediabot.db            ← پایگاه داده SQLite
├── worker/worker.php          ← Worker پس‌زمینه
├── webhook.php                ← نقطه ورود وبهوک
├── .env.example               ← نمونه تنظیمات
└── .htaccess                  ← امنیت Apache
```

---

## 🔧 رفع مشکلات رایج <a name="fa-troubleshoot"></a>

### وبهوک آپدیت دریافت نمی‌کند
```bash
# بررسی وضعیت وبهوک
curl "https://api.telegram.org/bot<TOKEN>/getWebhookInfo"

# بررسی لاگ Nginx
sudo tail -f /var/log/nginx/error.log
sudo tail -f /var/log/nginx/access.log
```

### Worker کارها را پردازش نمی‌کند
```bash
# بررسی وضعیت Supervisor
sudo supervisorctl status mediabot:

# مشاهده لاگ Worker
tail -f /var/www/mediabot/storage/logs/worker-stderr.log
tail -f /var/www/mediabot/storage/logs/mediabot-$(date +%Y-%m-%d).log

# ریست کردن کارهای گیر کرده
php scripts/maintenance.php reset-queue
```

### خطای دانلود yt-dlp
```bash
# به‌روزرسانی yt-dlp
sudo yt-dlp --update

# تست دستی
yt-dlp -v "https://youtube.com/watch?v=dQw4w9WgXcQ"
```

### خطای "database is locked" در SQLite
```bash
# بررسی فعال بودن WAL
sqlite3 storage/mediabot.db "PRAGMA journal_mode;"
# باید wal برگرداند

# ریست Worker های گیر کرده
php scripts/maintenance.php reset-queue
```

### خطاهای مجوز PHP
```bash
sudo chown -R www-data:www-data /var/www/mediabot/storage
sudo chmod -R 755 /var/www/mediabot/storage

# بررسی مجوز فایل لاگ
ls -la /var/www/mediabot/storage/logs/
```

### پیدا نشدن yt-dlp یا ffmpeg
```bash
# پیدا کردن مسیر
which yt-dlp   # /usr/local/bin/yt-dlp
which ffmpeg   # /usr/bin/ffmpeg
which python3  # /usr/bin/python3

# تنظیم مسیرها در .env
YTDLP_PATH=/usr/local/bin/yt-dlp
FFMPEG_PATH=/usr/bin/ffmpeg
```

### آپدیت yt-dlp برای رفع مشکل پلتفرم‌ها
```bash
# yt-dlp باید هفتگی آپدیت شود
sudo yt-dlp --update

# یا با cron خودکار
php scripts/maintenance.php update-ytdlp
```

---

## 🔐 نکات امنیتی

1. **محدود کردن دسترسی به پنل ادمین** بر اساس IP در Nginx
2. **استفاده از رمز عبور قوی** برای `ADMIN_PASSWORD`
3. **عدم قرار دادن فایل `.env`** در مخزن git
4. **بک‌آپ منظم** از فایل `storage/mediabot.db`
5. **آپدیت منظم** yt-dlp، PHP و سیستم‌عامل

```bash
# بک‌آپ خودکار دیتابیس
0 2 * * * cp /var/www/mediabot/storage/mediabot.db /backup/mediabot-$(date +\%Y\%m\%d).db
```

---

## 📄 مجوز

این پروژه تحت مجوز **MIT** منتشر شده است.

---

<div align="center">
<b>ساخته شده با ❤️ برای جامعه توسعه‌دهندگان ایرانی</b>
</div>
