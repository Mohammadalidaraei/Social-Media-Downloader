#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────
# MediaBot Cron Jobs
# Add to crontab: crontab -e
# ─────────────────────────────────────────────────────────────
# ┌─ min  ┬─ hour  ┬─ dom  ┬─ mon  ┬─ dow  ─ command
# │       │        │       │       │
# Worker: runs every minute (if not using supervisor)
# * * * * * /usr/bin/php /var/www/mediabot/worker/worker.php >> /var/www/mediabot/storage/logs/cron.log 2>&1

# Cleanup: runs daily at 3:00 AM
# 0 3 * * * /usr/bin/php /var/www/mediabot/scripts/maintenance.php cleanup >> /var/www/mediabot/storage/logs/maintenance.log 2>&1

# Update yt-dlp: runs weekly on Sunday at 4:00 AM
# 0 4 * * 0 /usr/local/bin/yt-dlp --update >> /var/www/mediabot/storage/logs/ytdlp-update.log 2>&1

# ─────────────────────────────────────────────────────────────
# Quick cron setup script — run as root or with sudo
# ─────────────────────────────────────────────────────────────

BOT_PATH="/var/www/mediabot"
PHP_BIN="/usr/bin/php"
YTDLP_BIN="/usr/local/bin/yt-dlp"

# Add cron entries
(crontab -l 2>/dev/null; echo "* * * * * ${PHP_BIN} ${BOT_PATH}/worker/worker.php >> ${BOT_PATH}/storage/logs/cron.log 2>&1") | sort -u | crontab -
(crontab -l 2>/dev/null; echo "0 3 * * * ${PHP_BIN} ${BOT_PATH}/scripts/maintenance.php cleanup >> ${BOT_PATH}/storage/logs/maintenance.log 2>&1") | sort -u | crontab -
(crontab -l 2>/dev/null; echo "0 4 * * 0 ${YTDLP_BIN} --update >> ${BOT_PATH}/storage/logs/ytdlp-update.log 2>&1") | sort -u | crontab -

echo "✅ Cron jobs added successfully!"
crontab -l
