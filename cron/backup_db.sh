#!/bin/bash
# cron/backup_db.sh
# สำรองข้อมูล Database อัตโนมัติ (e-campaignv2_db)
#
# ══════════════════════════════════════════════════════
#  วิธีติดตั้ง cron job:
#  1. เปิด crontab:  crontab -e
#  2. เพิ่มบรรทัดนี้ (สำรองทุกวัน ตี 2):
#     0 2 * * * /bin/bash /var/www/html/e-campaignv2/cron/backup_db.sh >> /var/www/html/e-campaignv2/cron/logs/backup.log 2>&1
#  3. บันทึกและออก
# ══════════════════════════════════════════════════════

# ── Config ────────────────────────────────────────────
DB_HOST="localhost"
DB_USER="your_db_user"           # ← เปลี่ยนเป็น username จริง
DB_PASS="your_db_password"       # ← เปลี่ยนเป็น password จริง
DB_NAME="e-campaignv2_db"        # ← ชื่อ database หลัก

BACKUP_DIR="/var/www/html/e-campaignv2/cron/backups"
KEEP_DAYS=14                      # เก็บ backup ย้อนหลัง 14 วัน
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
BACKUP_FILE="${BACKUP_DIR}/${DB_NAME}_${TIMESTAMP}.sql.gz"
LOG_TIME=$(date '+%Y-%m-%d %H:%M:%S')

# ── สร้างโฟลเดอร์ถ้ายังไม่มี ─────────────────────────
mkdir -p "$BACKUP_DIR"
mkdir -p "$(dirname "$BACKUP_DIR")/logs"

echo "[${LOG_TIME}] Starting backup: ${DB_NAME}"

# ── Dump + Compress ───────────────────────────────────
mysqldump \
    --host="$DB_HOST" \
    --user="$DB_USER" \
    --password="$DB_PASS" \
    --single-transaction \
    --routines \
    --triggers \
    --add-drop-table \
    "$DB_NAME" | gzip > "$BACKUP_FILE"

if [ $? -eq 0 ]; then
    SIZE=$(du -sh "$BACKUP_FILE" | cut -f1)
    echo "[${LOG_TIME}] SUCCESS: ${BACKUP_FILE} (${SIZE})"
else
    echo "[${LOG_TIME}] ERROR: mysqldump failed for ${DB_NAME}"
    exit 1
fi

# ── ลบ backup เก่ากว่า KEEP_DAYS ──────────────────────
DELETED=$(find "$BACKUP_DIR" -name "*.sql.gz" -mtime +${KEEP_DAYS} -print -delete | wc -l)
echo "[${LOG_TIME}] Cleaned up ${DELETED} old backup(s) older than ${KEEP_DAYS} days"

echo "[${LOG_TIME}] Done."
