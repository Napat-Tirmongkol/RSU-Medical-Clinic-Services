# Settings Singleton — Patch-Merge Pattern

## ปัญหา / Pattern ที่เจอ

ตาราง settings ที่เป็น **singleton** (row id=1 เก็บทั้งหมด เช่น `sys_line_faq_settings`,
`sys_clinic_profile`, `sys_finance_recurring` config row) มี save handler ที่
**overwrite ค่า missing keys เป็น default** — ทำให้ caller ที่ส่งมา **บางส่วน** ลบค่าของ
caller อื่น **โดยไม่ตั้งใจ**

อาการที่ user รายงาน:
- "ตั้งค่าไว้แล้ว แต่ทำไมหายตอน reload"
- "ทุกครั้งที่ deploy/pull ค่ารีเซ็ตเอง"
- "toggle ตัวอื่นที่ไม่ได้แตะกลับเป็น off ตอน save form อีกฟอร์มหนึ่ง"

Root cause ใน 99% ของเคส = save function เขียนแบบ "build full row จาก DEFAULTS" แทน
"patch onto current row"

## วิธี — Patch-Merge Template

```php
function save_xxx_settings(PDO $pdo, array $data): bool
{
    ensure_xxx_schema($pdo);

    // 1. อ่านค่าปัจจุบันก่อน — ใช้เป็น base สำหรับ patch
    $current = get_xxx_settings($pdo);

    $values = [];
    foreach (XXX_DEFAULTS as $k => $default) {
        if (!array_key_exists($k, $data)) {
            // 2. caller ไม่ส่ง key นี้ → เก็บค่าเดิม (ไม่ใช่ DEFAULTS!)
            $values[$k] = $current[$k] ?? $default;
            continue;
        }
        // 3. caller ส่งมา → validate + cast + ใช้ค่าใหม่
        $values[$k] = normalize_value($k, $data[$k]);
    }
    // INSERT ... ON DUPLICATE KEY UPDATE ...
}
```

และที่ **callsite** (POST handler) — ใส่ key เฉพาะที่ form ส่งมาจริง อย่าใส่
`$_POST['x'] ?? default`:

```php
// ❌ ผิด — ส่ง enabled = 0 ทุกครั้งแม้ form ไม่มี checkbox นี้
$data = ['enabled' => !empty($_POST['enabled']) ? 1 : 0];

// ✓ ถูก — ใส่ key เฉพาะที่ POST มีจริง
$data = [];
if (isset($_POST['enabled'])) $data['enabled'] = boolish($_POST['enabled']) ? 1 : 0;
```

## Audit Trail — ติดทุกครั้ง

ทุก singleton settings table ที่ user-facing ต้องมี audit table append-only:

```sql
CREATE TABLE sys_xxx_settings_audit (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    action VARCHAR(20) NOT NULL,         -- save / reset / seed / auto-heal
    values_json TEXT NULL,                -- snapshot หลัง save
    previous_json TEXT NULL,              -- snapshot ก่อน save (สำคัญ — debug "ใครเปลี่ยน")
    actor_id INT UNSIGNED NULL,
    actor_name VARCHAR(120) NULL,
    ip_addr VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_action_time (action, created_at)
);
```

Auto-create ตอน first write ใน `_xxx_audit()` helper, never block on failure
(`try { ... } catch (Throwable) {}` รอบ INSERT)

## Gotchas

1. **อย่า silent-catch PDOException** — ทุก save handler ต้อง `error_log()` ก่อน
   `return false` ไม่งั้น admin จะเห็น "บันทึกแล้ว" จาก optimistic UI แต่จริงๆ ล้มเหลว
2. **JS checkbox unchecked ≠ false POST** — browser ไม่ส่ง key ของ unchecked
   checkbox เลย → caller side ต้อง `fd.set('enabled', '0')` ถ้าจะให้ "ปิดชัดเจน",
   หรือใช้ patch-merge ฝั่ง server ที่จะเก็บค่าเดิม
3. **`(int)!empty($_POST['x']) && $_POST['x'] !== '0'`** — pattern operator precedence
   อ่านยาก ใช้ helper:
   ```php
   $boolish = static fn(string $k): bool =>
       isset($_POST[$k]) && in_array((string)$_POST[$k], ['1','on','true'], true);
   ```
4. **`get_xxx_settings()` ต้อง return defaults-merged shape** — เพื่อให้ caller โทรซ้อนได้
   โดยไม่ต้อง null-check ทุก key (`$cur['enabled'] ?? 0`)
5. **`ensure_xxx_schema()` ต้อง self-heal ALTER TABLE** สำหรับ column ใหม่ — ห้ามใช้
   `DROP/CREATE` ที่จะ wipe row id=1

## Reference

- **fix commit**: `ca4d2ce` (2026-05-19) — `fix(line-faq): stop clobbering unsupplied settings`
- **incident log**: `AI/logs/2026-05-19-faq-settings-drift-fix.md`
- **reference implementation**: `includes/clinic_status_helper.php:124` — `save_clinic_faq_settings()`
  (patch-merge + audit + error_log)
- **callsite pattern**: `portal/ajax_line_faq.php:41-66` — POST handler ที่ใส่ key
  เฉพาะที่ส่งมาจริง

## เกี่ยวข้องกับตารางอื่นที่ต้องตรวจ (audit candidates)

ตารางเหล่านี้เป็น singleton หรือ near-singleton — น่าจะมี pattern เดียวกันถ้ายังไม่ patch:

- `sys_clinic_profile` (id=1)
- `sys_finance_recurring` (เป็น list แต่ template — ดู `fin_run_recurring()`)
- `sys_ai_prompts` (key-value, แต่ caller อาจส่ง partial)
- `sys_line_faq_settings` ✓ patched 2026-05-19
- ทุกตาราง `*_settings` ที่ pattern คล้ายๆ กัน

เวลาเจอ "ค่าหายเอง" ในตารางใหม่ — เช็ค save handler ตามกฎ patch-merge นี้ก่อน
