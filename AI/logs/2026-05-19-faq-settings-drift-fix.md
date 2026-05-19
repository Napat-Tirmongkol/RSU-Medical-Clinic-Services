# FAQ Auto-reply Settings Drift Fix — 2026-05-19

## Context

User รายงานว่า toggle "FAQ ตอบอัตโนมัติ — เวลาเปิด/ปิด" ใน AI QA Lab
(`portal/_partials/ai_qa_lab.php` tab `autoreply`) **ค่ารีเซ็ตเอง "ทุกครั้งที่ git pull"** —
ตั้งค่า `enabled`, `only_when_closed`, `default_reply_enabled` แล้วเปิดมาเจอ off อีก

ตารางเก็บค่าคือ `sys_line_faq_settings` (singleton row id=1), save handler อยู่ที่
`portal/ajax_line_faq.php` → `save_clinic_faq_settings()` ใน `includes/clinic_status_helper.php`

## Investigation

Static scan ครบทุกที่ที่ touch ตารางนี้ — **ไม่เจอ migration / cron / seed / setup**
script ที่ reset row id=1 เลย:

- `database/migrations/` — ไม่มีไฟล์ใดที่ touch `sys_line_faq_settings`
- `cron/` — ไม่มี script ที่เขียนตารางนี้
- `scripts/` — ไม่มี
- `ensure_clinic_faq_tables()` ใช้ `CREATE TABLE IF NOT EXISTS` + `ALTER TABLE ADD COLUMN` —
  ไม่ DROP, ไม่ TRUNCATE

ดังนั้น root cause **ไม่ใช่ deploy step** ตรงๆ — แต่เจอ 3 bugs ที่ทำให้ symptom เป็นไปได้
และไม่มีทางจะ diagnose:

### Bug 1 — Silent overwrite ของ missing keys (root cause หลัก)

`save_clinic_faq_settings()` วน loop `foreach (CLINIC_FAQ_DEFAULTS as $k => $default)`
แล้ว — ถ้า key ไม่อยู่ใน `$data` → ตั้งเป็น `$default`. แปลว่า **caller ใดก็ตามที่ส่ง
partial data จะลบค่าของ key อื่น**ทั้งหมดให้กลายเป็น default

ไม่มี caller ปัจจุบันใน codebase ที่ทำเช่นนี้ (POST handler ส่งครบทุก key) แต่ pattern
เปราะมาก — refactor / migration / new endpoint ใดๆ ที่เรียก `save_*()` ด้วย partial data
จะ silent-clobber

### Bug 2 — Silent PDOException

```php
} catch (PDOException) {
    return false;
}
```

Save ล้มเหลวเงียบ — admin เห็น "บันทึกแล้ว" จาก optimistic UI ของ frontend (ที่ใช้
SweetAlert2) แล้ว reload แล้วเจอค่าหาย → คิดว่า "git pull ลบ"

### Bug 3 — ไม่มี audit trail

ตัวตารางไม่มี audit log ของ "ใครเขียนเข้ามาเมื่อไหร่" ทำให้ debug ไม่ได้ว่า reset เกิดจาก
admin / script / API call ภายนอก

## Decision

แก้ทั้ง 3 bugs พร้อมกันเพื่อ:
(a) ป้องกัน drift ในอนาคต
(b) ทำให้ tractable เวลาเกิดอีก

### 1. Patch-merge semantics

`save_clinic_faq_settings()` อ่าน row ปัจจุบันก่อน (ผ่าน `get_clinic_faq_settings()`) แล้ว
patch ค่าใหม่ลงไป — key ที่ caller **ไม่ส่ง** จะเก็บค่าเดิมไว้ (ไม่ใช่ DEFAULTS)

```php
$current = get_clinic_faq_settings($pdo);
foreach (CLINIC_FAQ_DEFAULTS as $k => $default) {
    if (!array_key_exists($k, $data)) {
        $values[$k] = $current[$k] ?? $default;  // ← key หาย ใช้ค่าเดิม
        continue;
    }
    // ... normalize $data[$k]
}
```

### 2. Audit table

สร้าง `sys_line_faq_settings_audit` (auto-create on first write):
- `action` (save / reset / seed / future actions)
- `values_json` — snapshot **หลัง** save
- `previous_json` — snapshot **ก่อน** save (สำคัญที่สุดสำหรับ debug "ใครเปลี่ยน")
- `actor_id`, `actor_name` (จาก `$_SESSION`)
- `ip_addr`
- `created_at`

Append-only, never block — wrap ทั้ง helper ใน `try { ... } catch (Throwable) {}`

### 3. แทน silent catch ด้วย error_log

```php
} catch (PDOException $e) {
    error_log('[clinic_faq][save] PDO error: ' . $e->getMessage());
    return false;
}
```

ครั้งหน้าถ้า save fail — admin/dev จะเจอใน server log ทันที

### 4. POST handler cleanup

`ajax_line_faq.php` save handler เดิมส่ง `enabled` มาเสมอ (ทั้ง 0 และ 1) ทำให้
patch-merge ไม่มีประโยชน์ — เปลี่ยนเป็นใส่ key **เฉพาะที่ `$_POST` มีจริง** เพื่อให้
caller ใหม่ที่ใช้ partial-update flow ได้ประโยชน์จาก patch-merge

```php
$data = [];
foreach (['enabled', 'only_when_closed', 'default_reply_enabled'] as $k) {
    if (isset($_POST[$k])) $data[$k] = $boolish($k) ? 1 : 0;
}
```

(หมายเหตุ: ปัจจุบัน frontend ตั้ง `fd.set('enabled', '0')` เมื่อ unchecked → `$_POST['enabled']`
อยู่เสมอ — แต่ pattern ที่ถูกคือ "อย่าใส่ default แทนค่าที่ form ไม่มี")

## Alternatives Considered

1. **REPLACE INTO row id=1 ทั้งแถวจาก DEFAULTS ถ้า save fail** — แย่กว่าเดิม จะ
   wipe row แม้ schema valid
2. **เปลี่ยน column DEFAULT ของ `enabled` เป็น 0** — ไม่ช่วย เพราะปัญหาคือ overwrite
   ตอน save ไม่ใช่ตอน CREATE
3. **เพิ่ม versioning ของ settings (optimistic lock)** — overkill, singleton row
   conflict โอกาสน้อย; audit log ก็เพียงพอ
4. **Bypass `save_clinic_faq_settings()` ด้วย direct UPDATE per key** — เสีย
   centralization, validation; patch-merge ใน save เป็น centralized point ที่ดีกว่า

## Consequences

- **ทุก save ใหม่** จะ snapshot ลง `sys_line_faq_settings_audit` — ดู trail ได้ทันที
- **Schema migration**: audit table auto-create on first write — ไม่ต้องรัน migration เอง
- **Frontend ไม่ต้องแก้** — patch-merge backward-compat กับ POST shape เดิม
- **ครั้งหน้าถ้า user รายงานอีก** → query
  `SELECT * FROM sys_line_faq_settings_audit ORDER BY id DESC LIMIT 20;`
  จะเห็น actor + IP + previous_json → ระบุ root cause ตรงๆ ได้
- **Pattern เผยแพร่** → `AI/knowledge/settings-singleton-patch-merge.md` สำหรับใช้กับ
  ตาราง settings อื่นๆ (clinic_profile, ai_prompts, etc.)

## Reference

- Fix commit: `ca4d2ce` — main, 2026-05-19
- Files changed:
  - `includes/clinic_status_helper.php` (`save_clinic_faq_settings`, new `_faq_settings_audit`)
  - `portal/ajax_line_faq.php` (cleaner save handler)
- Pattern doc: `AI/knowledge/settings-singleton-patch-merge.md`
- ต่อเนื่องจาก: AI restoration Phase A (`5752680`) + Phase B (`1166275`) ของวันที่
  2026-05-18/19 — เป็นชุดบูรณะ AI auto-reply ทั้งระบบ

## ที่ยังเหลือ (เปิด follow-up)

- ตรวจ singleton-settings tables อื่นว่ามี pattern เดียวกันไหม (รายการใน knowledge doc)
- Phase C ของ AI restoration ยังไม่ได้เริ่ม — daily answer cache + fallback chain + telemetry
- ถ้า user รายงาน "ค่าหายอีก" หลัง patch นี้ → ดู audit table เป็นหลักก่อน static analysis
