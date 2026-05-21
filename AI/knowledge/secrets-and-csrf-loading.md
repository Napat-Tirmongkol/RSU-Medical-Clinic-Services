# Secrets + CSRF Loading — Canonical Patterns

> **ที่มา:** session 2026-05-21 ผมพลาด 2 ครั้งซ้อน (CSRF token + LINE access token) เพราะตีความ CLAUDE.md ผิดและเดาว่า config เป็น `defined('...')` constant แต่จริงๆ ใช้ array จาก `require`
> drop ไว้กัน future agent ตกหลุมเดิม

---

## 1. Secrets — `config/secrets.php`

### โครงสร้าง
ไฟล์ `config/secrets.php` **return PHP array** (ไม่ใช่ define constants):
```php
<?php
return [
    'DB_HOST' => '...',
    'LINE_MESSAGING_CHANNEL_ACCESS_TOKEN' => '...',
    'GEMINI_API_KEY' => '...',
    // ... etc
];
```

template เต็มอยู่ที่ `config/secrets.template.php` — copy ไปเป็น `secrets.php` แล้วเติมค่า

### วิธีโหลด — 3 patterns ที่ใช้ในโปรเจกต์

```php
// (1) Global ตอน config.php boot — private variable
$__secrets = require __DIR__ . '/config/secrets.php';

// (2) Re-require ใน script เฉพาะที่ต้องใช้ (เช่น api/line_webhook.php)
$secrets = require __DIR__ . '/../config/secrets.php';
$token = $secrets['LINE_MESSAGING_CHANNEL_ACCESS_TOKEN'] ?? '';

// (3) Defensive load (กรณีไฟล์อาจหาย — admin endpoints)
$secrets = file_exists(__DIR__ . '/../../config/secrets.php')
    ? require __DIR__ . '/../../config/secrets.php'
    : [];
```

### Wrap แบบ static cache (สำหรับ helper)
```php
function my_load_token(): string {
    static $cached = null;
    if ($cached !== null) return $cached;
    $cached = '';
    $path = __DIR__ . '/../config/secrets.php';
    if (is_file($path)) {
        try {
            $s = require $path;
            if (is_array($s)) $cached = (string)($s['KEY_NAME'] ?? '');
        } catch (Throwable $e) {
            error_log('[helper] load_token failed: ' . $e->getMessage());
        }
    }
    return $cached;
}
```
ตัวอย่าง: `includes/line_chat_helper.php::line_chat_load_access_token()`

### Anti-pattern — ❌ อย่าทำ

```php
// ❌ ไม่ใช่ constant
if (defined('LINE_CHANNEL_ACCESS_TOKEN')) ...

// ❌ ชื่อ key ผิด — ไม่มี LINE_CHANNEL_ACCESS_TOKEN
$secrets['LINE_CHANNEL_ACCESS_TOKEN']

// ❌ $_SESSION — secrets ไม่ได้ไปอยู่ใน session
$_SESSION['LINE_TOKEN']
```

### Keys ทั้งหมด (จาก `secrets.template.php`)

**App + DB**
- `APP_BASE_URL` · `DB_HOST` · `DB_PORT` · `DB_USER` · `DB_PASS` · `DB_NAME`
- `EBORROW_DB_*` (override สำหรับ e_Borrow connection — ว่าง = inherit main DB)

**LINE**
- `LINE_LOGIN_CHANNEL_ID` · `LINE_LOGIN_CHANNEL_SECRET` · `LINE_LIFF_ID`
- `LINE_MESSAGING_CHANNEL_ACCESS_TOKEN` ← **token ที่ใช้ push/reply API**
- `LINE_MESSAGING_CHANNEL_SECRET` ← **secret ที่ใช้ verify webhook signature**
- `LINE_LOGIN_CHANNEL_ID_NEW` · `LINE_LOGIN_CHANNEL_SECRET_NEW` · `LINE_LIFF_ID_NEW` (migration provider)

**OAuth + AI**
- `GOOGLE_CLIENT_ID` · `GOOGLE_CLIENT_SECRET` · `GOOGLE_REDIRECT_URI`
- `GEMINI_API_KEY`

**Monitoring**
- `SENTRY_DSN` · `SENTRY_WEBHOOK_SECRET`
- `GITHUB_TOKEN` · `GITHUB_REPO`

**Email + Alerts**
- `SMTP_HOST` · `SMTP_PORT` · `SMTP_USER` · `SMTP_PASS` · `SMTP_FROM_EMAIL` · `SMTP_FROM_NAME`
- `ADMIN_ALERT_EMAIL`

**Operations**
- `MIGRATION_TOKEN` (one-shot browser migration auth — clear หลังใช้)
- `EBORROW_CRON_SECRET` (X-Cron-Secret header)

---

## 2. CSRF Token

### PHP side — `get_csrf_token()` จาก `includes/csrf.php`

```php
$token = get_csrf_token();   // returns string · auto-generates ถ้ายังไม่มี
echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
```

Session key ที่ใช้จริง = **`$_SESSION['csrf_token']`** (ไม่ใช่ `portal_CSRF`!)

### Validate — `validate_csrf_or_die()`
```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_die();   // looks for $_POST['csrf_token']
}
```
ถ้า mismatch → HTTP 403 + die

### JS side — `portal_CSRF` global

`portal/index.php` ประกาศ JS global:
```php
const portal_CSRF = <?= json_encode(get_csrf_token()) ?>;
```

→ partial ทุกตัวที่ load ใต้ `portal/index.php` ใช้ `portal_CSRF` ได้ทันที:
```js
const fd = new FormData();
fd.append('csrf_token', portal_CSRF);
fetch('ajax_my.php', { method: 'POST', body: fd });
```

### Pattern ที่ recommended สำหรับ partial ใหม่
```php
// ตอนต้นของไฟล์
$csrfToken = function_exists('get_csrf_token') ? get_csrf_token() : '';
```
```js
// ใน <script>
const MY_CSRF = '<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>';
```
→ explicit, self-contained, ไม่พึ่ง global

### Anti-pattern — ❌ อย่าทำ

```php
// ❌ session key นี้ไม่มี
$_SESSION['portal_CSRF']

// ❌ portal_CSRF เป็น JS global ไม่ใช่ PHP variable
$portal_CSRF
```

---

## 3. กฎเหล็ก: verify ก่อนสมมุติ

CLAUDE.md อาจเขียนไว้กว้างๆ ("portal_CSRF พร้อมใช้") — **ไม่ใช่ contract** ให้ตีความว่าเป็น session key

ก่อนใช้ secret/token/CSRF ตัวใด:
1. **grep ของจริง** — `grep -rn "TOKEN_NAME\|key_name" includes/ api/ config/`
2. **ดู usage หนึ่งตัวอย่าง** — copy pattern จาก endpoint ที่ใช้แล้ว
3. **อย่าเดา constant vs array vs session** — เช็คก่อนเสมอ

### Reference endpoints ให้ดู pattern
- LINE token + secret loading → `api/line_webhook.php` lines 17-19
- Gemini key loading → `includes/ai_qa_helper.php::ai_qa_load_gemini_key()`
- CSRF on AJAX (PHP) → `portal/ajax_finance.php` line 7 (`require auth.php`) + `validate_csrf_or_die()`
- CSRF on AJAX (JS) → `portal/_partials/finance.php` line 408 (`const CSRF = ...`) + `fd.append('csrf_token', CSRF)`
- Static cache pattern → `includes/line_chat_helper.php::line_chat_load_access_token()`
