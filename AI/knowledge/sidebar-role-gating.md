# Sidebar Group Toggle Role Gating Pattern

> Last updated: 2026-05-23 · Triggered by audit `AI/logs/2026-05-23-sidebar-role-gates.md`

## Rule

**Group toggle (`.psb-section-toggle`) ต้องมี role gate ที่เป็น OR ของทุก inner-item gate**

- ถ้า item ไหนเห็น → toggle เห็น
- ถ้าไม่มี item เห็นเลย → toggle ต้องไม่เห็น
- ห้าม render group toggle ที่ user เปิดแล้วเจอ empty list

## Implementation pattern

```php
// 1. ที่ block role flags (portal/index.php ~line 1162-1175)
$hasFooGroup = $isSuper
            || !empty($_SESSION['access_foo'])
            || !empty($_SESSION['access_bar']);   // ตามที่ items ภายในใช้

// 2. ที่ group toggle render
<?php if ($hasFooGroup): ?>
    <button type="button" class="psb-section-toggle" data-group="foo" onclick="togglePsbGroup('foo',this)">
        <i class="fa-solid fa-XXX" style="color:#XXX"></i>
        <span>ชื่อกลุ่ม</span>
        <i class="fa-solid fa-chevron-down psb-chevron"></i>
    </button>
    <div class="psb-group" data-group="foo">
        <?php if ($isSuper || !empty($_SESSION['access_foo'])): ?>
            <button class="psb-item" ...>...</button>
        <?php endif; ?>
        <?php if (!empty($_SESSION['access_bar'])): ?>
            <button class="psb-item" ...>...</button>
        <?php endif; ?>
    </div>
<?php endif; ?>
```

## Common pitfall — `!$registryOnly` ที่ผิดความหมาย

`!$registryOnly` แปลว่า "ไม่ใช่ partner ภายนอกที่เข้ามาแค่อัพโหลดทะเบียน"
**ไม่ใช่** "user คนนี้มี role เพียงพอที่จะเห็น group นี้"

ผิด:
```php
// ❌ Group ปรากฏให้ทุก non-registry user แม้ไม่มีสิทธิ์ดู items ข้างใน
<?php if (!$registryOnly): ?>
    <button class="psb-section-toggle" data-group="security">...</button>
    <div class="psb-group" data-group="security">
        <?php if ($isSuper || access_identity): ?>...<?php endif; ?>
    </div>
<?php endif; ?>
```

ถูก:
```php
// ✓ Group ปรากฏเฉพาะคนที่เห็น item ภายในได้อย่างน้อย 1 อัน
$hasSecurityGroup = $isSuper || !empty($_SESSION['access_identity']);
<?php if (!$registryOnly && $hasSecurityGroup): ?>
    ...
<?php endif; ?>
```

## Checklist เมื่อเพิ่ม group หรือ item ใหม่

1. **เพิ่ม item ใหม่ในกลุ่มที่มีอยู่แล้ว** — เช็คว่า gate ของ item ใหม่ subset ของ group toggle gate หรือไม่
   - ถ้า subset แล้ว → OK
   - ถ้า item ใหม่ require สิทธิ์ที่ group ไม่ครอบคลุม → ต้องขยาย `$has{Group}Group` flag

2. **สร้าง group ใหม่** — ทำตาม pattern ข้างบนทุกข้อ:
   - คำนวณ `$has{Group}Group` flag ใน block role flags
   - Gate `psb-section-toggle` ด้วย flag
   - Gate ทุก item ด้วย role check ของแต่ละ item

3. **ลบ item** — เช็คว่ายังมี item เหลือใน group หรือไม่ ถ้าหมดแล้วต้องลบ `$has{Group}Group` flag ทิ้งหรือลด condition

## Reference

- Audit ครั้งแรก: `AI/logs/2026-05-23-sidebar-role-gates.md` (เจอ bug 2/13 groups)
- Bug fix commits: `0fecb3c` (security) + (audit log commit) (insurance)
- Sidebar conventions: `CLAUDE.md` section "Portal Sidebar — กฎการจัดกลุ่มเมนู"

## Related access flags table (2026-05-23)

| Flag | Default Role | ใช้กับ group |
|------|-------------|--------------|
| `access_identity` | superadmin | security |
| `access_ai` | superadmin | ai |
| `access_insurance` | superadmin | insurance (4 main items) |
| `access_registry` | superadmin | insurance (registry_upload) |
| `access_edms` | superadmin | comm (EDMS sub) |
| `access_asset`, `access_consumables` | admin/editor + superadmin | inventory |
| `access_finance` | admin + superadmin | finance |
| `access_system_logs` | superadmin | monitor |
| `access_monthly_report`, `access_director_view`, `access_nurse_productivity`, `access_daily_summary` | role-mixed | reports |
| `access_site_settings` | superadmin | masterdata, settings |
| `access_edms_sla_admin` | superadmin | comm (SLA policies sub) |

`$adminRole === 'superadmin'` bypasses ทุก gate
