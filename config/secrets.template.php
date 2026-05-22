<?php
// config/secrets.template.php
// ไฟล์แม่แบบสำหรับการตั้งค่า Secrets
// คัดลอกไฟล์นี้ไปเป็น config/secrets.php และเติมค่าจริงให้ครบถ้วน

return [
    // --- Application Base URL (REQUIRED for password-reset emails, OAuth) ---
    // Absolute URL of the deployed app, e.g. 'https://clinic.rsu.ac.th/e-campaignv2'
    // Leave empty in dev = password-reset emails will refuse to send (fail-closed
    // anti-host-header-injection). Always set this for production.
    'APP_BASE_URL' => '',

    // --- Main Database ---
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => 3306,
    'DB_USER' => '',
    'DB_PASS' => '',
    'DB_NAME' => '',

    // --- e-Borrow Database (leave empty to inherit DB_* above) ---
    'EBORROW_DB_HOST' => '',
    'EBORROW_DB_PORT' => 3306,
    'EBORROW_DB_USER' => '',
    'EBORROW_DB_PASS' => '',
    'EBORROW_DB_NAME' => 'e_Borrow',

    'LINE_LOGIN_CHANNEL_ID'               => '',
    'LINE_LOGIN_CHANNEL_SECRET'           => '',
    'LINE_LIFF_ID'                       => '',
    'LINE_MESSAGING_CHANNEL_ACCESS_TOKEN' => '',
    'LINE_MESSAGING_CHANNEL_SECRET'       => '',

    // --- LINE Login (NEW provider — same provider as Messaging API) ---
    // ใช้สำหรับ migrate UID จาก provider เดิมไปยัง provider ใหม่
    'LINE_LOGIN_CHANNEL_ID_NEW'           => '',
    'LINE_LOGIN_CHANNEL_SECRET_NEW'       => '',
    'LINE_LIFF_ID_NEW'                    => '',

    // --- Admin Panel (Google OAuth2) ---
    'GOOGLE_CLIENT_ID'                    => '',
    'GOOGLE_CLIENT_SECRET'                => '',
    'GOOGLE_REDIRECT_URI'                  => '',

    // --- Gemini AI (get key from https://aistudio.google.com/app/apikey) ---
    'GEMINI_API_KEY'                      => '',

    // --- Sentry error monitoring (get DSN: sentry.io → Project → Settings → Client Keys) ---
    'SENTRY_DSN'                          => '', // e.g. https://abc123@o0.ingest.sentry.io/456

    // --- Sentry Internal Integration webhook (Settings → Developer Settings → Internal Integration) ---
    // Client Secret ของ Internal Integration — ใช้ verify signature ของ webhook ที่ Sentry ส่งเข้ามา
    'SENTRY_WEBHOOK_SECRET'               => '',

    // --- GitHub bridge (auto-create issue from Sentry webhook) ---
    // Fine-grained PAT with "Issues: Read and write" scope on the target repo
    // https://github.com/settings/tokens?type=beta
    // Leave empty to disable GitHub issue auto-creation (events still log to DB)
    'GITHUB_TOKEN'                        => '',
    'GITHUB_REPO'                         => '', // e.g., 'napat-tirmongkol/rsu-medical-clinic-services'

    // --- Email System (SMTP) ---
    'SMTP_HOST'                           => '', // e.g., smtp.gmail.com
    'SMTP_PORT'                           => 587,
    'SMTP_USER'                           => '',
    'SMTP_PASS'                           => '',
    'SMTP_FROM_EMAIL'                     => '',
    'SMTP_FROM_NAME'                      => 'RSU Medical Clinic Services',

    // --- Admin Alert ---
    'ADMIN_ALERT_EMAIL'                   => '', // อีเมลที่รับแจ้งเตือน Error Digest (ว่างเปล่า = ปิดการแจ้งเตือน)

    // --- Migration Token (สำหรับรัน migration scripts ผ่าน browser ชั่วคราว) ---
    // ตั้งค่าเป็น random string ยาวๆ ตอนต้องรัน แล้วเคลียร์เป็น '' หลังเสร็จ
    // สร้าง token: bin2hex(random_bytes(32)) หรือ openssl rand -hex 32
    'MIGRATION_TOKEN'                     => '',

    // --- e-Borrow cron secret (sent in X-Cron-Secret header by scheduler) ---
    // ใช้กับ e_Borrow/process/send_reminders.php — ตั้งเป็น random hex 32+ chars
    // ห้ามใส่ใน URL query string เพราะจะ leak ไปยัง access log.
    // ตัวอย่าง cron entry:
    //   curl -fsS -H "X-Cron-Secret: <secret>" https://.../e_Borrow/process/send_reminders.php
    'EBORROW_CRON_SECRET'                 => '',
];
