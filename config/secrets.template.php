<?php
// config/secrets.template.php
// ไฟล์แม่แบบสำหรับการตั้งค่า Secrets
// คัดลอกไฟล์นี้ไปเป็น config/secrets.php และเติมค่าจริงให้ครบถ้วน

return [
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

    // --- Admin Panel (Google OAuth2) ---
    'GOOGLE_CLIENT_ID'                    => '',
    'GOOGLE_CLIENT_SECRET'                => '',
    'GOOGLE_REDIRECT_URI'                  => '',

    // --- Gemini AI (get key from https://aistudio.google.com/app/apikey) ---
    'GEMINI_API_KEY'                      => '',

    // --- Sentry error monitoring (get DSN: sentry.io → Project → Settings → Client Keys) ---
    'SENTRY_DSN'                          => '', // e.g. https://abc123@o0.ingest.sentry.io/456

    // --- Email System (SMTP) ---
    'SMTP_HOST'                           => '', // e.g., smtp.gmail.com
    'SMTP_PORT'                           => 587,
    'SMTP_USER'                           => '',
    'SMTP_PASS'                           => '',
    'SMTP_FROM_EMAIL'                     => '',
    'SMTP_FROM_NAME'                      => 'RSU Medical Clinic Services',

    // --- Admin Alert ---
    'ADMIN_ALERT_EMAIL'                   => '', // อีเมลที่รับแจ้งเตือน Error Digest (ว่างเปล่า = ปิดการแจ้งเตือน)
];
