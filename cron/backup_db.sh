#!/bin/bash
# cron/backup_db.sh
# สำรองข้อมูล Database อัตโนมัติ — อ่าน credentials จาก config/db_connect.php โดยตรง
#
# ══════════════════════════════════════════════════════
#  วิธีติดตั้ง cron job:
#  1. เปิด crontab:  crontab -e
#  2. เพิ่มบรรทัดนี้ (สำรองทุกวัน ตี 2):
#     0 2 * * * /bin/bash /var/www/html/e-campaignv2/cron/backup_db.sh >> /var/www/html/e-campaignv2/cron/logs/backup.log 2>&1
# ══════════════════════════════════════════════════════

# ── หา root path ของโปรเจกต์ (1 ระดับเหนือ cron/) ────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
PHP_CONFIG="${PROJECT_ROOT}/config/db_connect.php"

LOG_TIME=$(date '+%Y-%m-%d %H:%M:%S')

# ── ตรวจสอบว่า config ไฟล์มีอยู่ ─────────────────────
if [ ! -f "$PHP_CONFIG" ]; then
    echo "[${LOG_TIME}] ERROR: Cannot find ${PHP_CONFIG}"
    exit 1
fi

# ── อ่าน credentials จาก PHP config ด้วย PHP CLI ─────
# ให้ PHP parse ไฟล์แล้ว echo ค่าออกมาเป็น KEY=VALUE
eval "$(php -r "
    require '${PHP_CONFIG}';
    // db() ถูก define ใน db_connect.php — เราแค่ต้องการ \$db_* variables
    // ใช้ get_defined_vars() เพื่อดึงค่า
    \$v = get_defined_vars();
    echo 'DB_HOST=' . escapeshellarg(\$v['db_host'] ?? 'localhost') . PHP_EOL;
    echo 'DB_USER=' . escapeshellarg(\$v['db_user'] ?? '') . PHP_EOL;
    echo 'DB_PASS=' . escapeshellarg(\$v['db_pass'] ?? '') . PHP_EOL;
    echo 'DB_NAME=' . escapeshellarg(\$v['db_name'] ?? '') . PHP_EOL;
    echo 'DB_PORT=' . escapeshellarg((string)(\$v['db_port'] ?? '3306')) . PHP_EOL;
")"

# ── ตรวจสอบว่าได้ค่ามา ────────────────────────────────
if [ -z "$DB_USER" ] || [ -z "$DB_NAME" ]; then
    echo "[${LOG_TIME}] ERROR: Could not read DB credentials from ${PHP_CONFIG}"
    exit 1
fi

# ── Backup Config ─────────────────────────────────────
BACKUP_DIR="${PROJECT_ROOT}/cron/backups"
KEEP_DAYS=14
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
BACKUP_FILE="${BACKUP_DIR}/${DB_NAME}_${TIMESTAMP}.sql.gz"

mkdir -p "$BACKUP_DIR"
mkdir -p "${PROJECT_ROOT}/cron/logs"

echo "[${LOG_TIME}] Starting backup: ${DB_NAME} @ ${DB_HOST}"

# ── Dump + Compress ───────────────────────────────────
mysqldump \
    --host="$DB_HOST" \
    --port="$DB_PORT" \
    --user="$DB_USER" \
    --password="$DB_PASS" \
    --single-transaction \
    --routines \
    --triggers \
    --add-drop-table \
    "$DB_NAME" | gzip > "$BACKUP_FILE"

if [ $? -eq 0 ]; then
    SIZE=$(du -sh "$BACKUP_FILE" | cut -f1)
    echo "[${LOG_TIME}] SUCCESS: $(basename "$BACKUP_FILE") (${SIZE})"
else
    echo "[${LOG_TIME}] ERROR: mysqldump failed"
    rm -f "$BACKUP_FILE"
    exit 1
fi

# ── ลบ backup เก่ากว่า KEEP_DAYS ──────────────────────
DELETED=$(find "$BACKUP_DIR" -name "*.sql.gz" -mtime +"$KEEP_DAYS" -print -delete | wc -l)
echo "[${LOG_TIME}] Cleaned up ${DELETED} old backup(s) older than ${KEEP_DAYS} days"

echo "[${LOG_TIME}] Done."
