# RSU Health Platform — Claude Guidelines

## Project Overview

ระบบบริหารจัดการคลินิกมหาวิทยาลัยรังสิต (RSU Medical Clinic Services)
กำลังอยู่ระหว่างการ migrate จาก **PHP Procedural → Laravel 11**

- **PHP Codebase (เดิม)**: `/home/user/RSU-Medical-Clinic-Services` (หรือ `rsu-health-platform` หลัง rename)
- **Laravel Codebase (ใหม่)**: repo `Napat-Tirmongkol/rsu-health-platform` บน GitHub
- **Strategy**: Strangler Fig — PHP ยังรันได้ปกติระหว่าง migrate ทีละส่วน

---

## Target Stack (Laravel)

| Layer | Technology |
|-------|-----------|
| Framework | Laravel 11 |
| Auth Scaffolding | Laravel Jetstream (Teams mode + Livewire) |
| Frontend | Blade + Tailwind CSS 3 + Livewire 3 |
| Database | MySQL (Single DB, Multi-tenant via `clinic_id`) |
| Server | Plesk (PHP-FPM, Let's Encrypt, Supervisor for queue) |
| Queue | Laravel Queue (database driver → Redis ในอนาคต) |
| Scheduler | Laravel Scheduler (แทน cron php script เดิม) |

---

## Multi-Tenant Architecture

**Pattern**: Single Database, Multiple Clinics via `clinic_id`

- ทุกตารางที่ scoped ต่อ clinic มี column `clinic_id INT UNSIGNED NOT NULL DEFAULT 1`
- `clinic_id = 0` = global default (สำหรับ `sys_site_settings`)
- `clinic_id = 1` = RSU Medical Clinic (default/fallback)
- Tenant resolved จาก **subdomain** → `sys_clinics.slug` lookup

### Tenant Resolution Order
1. Session cache (`$_SESSION['clinic_id']`) — performance
2. Subdomain lookup (`medical.rsu.ac.th` → slug=`medical` → clinic_id=1)
3. Fallback → `1`

### Laravel Implementation
```php
// app/Models/Traits/BelongsToClinic.php
trait BelongsToClinic {
    protected static function bootBelongsToClinic(): void {
        static::addGlobalScope(new TenantScope());
        static::creating(fn($m) => $m->clinic_id ??= auth()->user()?->currentTeam?->id ?? 1);
    }
}

// app/Scopes/TenantScope.php
class TenantScope implements Scope {
    public function apply(Builder $builder, Model $model): void {
        $builder->where($model->getTable().'.clinic_id', currentClinicId());
    }
}
```

### Jetstream Teams = Clinics
- `Team` = Clinic (1:1 mapping)
- `team_id` ใน Jetstream = `clinic_id` ในระบบเดิม
- User เข้าได้หลาย clinic ผ่าน `team_user` pivot table

---

## Authentication Guards (4 Guards)

| Guard | User Type | Method | Session Key |
|-------|-----------|--------|-------------|
| `user` | ผู้ป่วย/นักศึกษา | LINE OAuth | `line_user_id` |
| `admin` | ผู้ดูแลระบบ | Google OAuth | `admin_id` |
| `staff` | เจ้าหน้าที่คลินิก | Password | `staff_id` |
| `portal` | Superadmin | Password | `portal_id` |

### Laravel Guards Config (config/auth.php)
```php
'guards' => [
    'user'   => ['driver' => 'session', 'provider' => 'users'],
    'admin'  => ['driver' => 'session', 'provider' => 'admins'],
    'staff'  => ['driver' => 'session', 'provider' => 'staff'],
    'portal' => ['driver' => 'session', 'provider' => 'portals'],
],
```

### LINE OAuth Package
```bash
composer require laravel/socialite socialiteproviders/line
```

---

## Migration Roadmap (8 Phases — ~19 Weeks)

### Phase A — Foundation (Week 1–2)
- [ ] Laravel 11 + Jetstream install
- [ ] Multi-tenant middleware (`SetTenantFromSubdomain`)
- [ ] `BelongsToClinic` trait + `TenantScope` global scope
- [ ] Migrate `sys_clinics` table + seed RSU clinic
- [ ] `currentClinicId()` helper function

### Phase B — Data Layer (Week 3–5)
- [ ] Migrate ทุกตาราง (sys_users, camp_list, camp_bookings, camp_slots, vac_appointments ฯลฯ)
- [ ] Eloquent Models พร้อม relationships
- [ ] Repository pattern สำหรับ complex queries
- [ ] Database seeders

### Phase C — Authentication (Week 6–7)
- [ ] LINE OAuth (socialiteproviders/line)
- [ ] Google OAuth สำหรับ Admin
- [ ] Staff password login
- [ ] Portal/Superadmin login
- [ ] Session guard middleware ครบ 4 guards

### Phase D — User Portal (Week 8–10)
- [ ] Livewire: BookingCalendar
- [ ] Livewire: TimeSlotPicker
- [ ] Livewire: MyBookings
- [ ] Livewire: SupportChat
- [ ] Profile management
- [ ] Hub page

### Phase E — Admin Panel (Week 11–13)
- [ ] Campaign management (CRUD)
- [ ] Booking management + approve/cancel
- [ ] KPI Dashboard (Livewire)
- [ ] Time slots management
- [ ] User history
- [ ] Staff management
- [ ] Activity logs
- [ ] Reports + Excel export (maatwebsite/excel)

### Phase F — Portal / Superadmin (Week 14–15)
- [ ] Cross-clinic dashboard
- [ ] Clinic management CRUD
- [ ] Superadmin impersonate clinic
- [ ] Per-clinic settings & branding
- [ ] Site settings management

### Phase G — Integrations (Week 16–17)
- [ ] LINE Notify (ส่งแจ้งเตือนนัดหมาย)
- [ ] Laravel Mailable (แทน custom smtp_send())
- [ ] QR Code generation (simplesoftwareio/simple-qrcode)
- [ ] Sentry error tracking (sentry/sentry-laravel)
- [ ] Pusher realtime (support chat)

### Phase H — Testing & Deploy (Week 18–19)
- [ ] Feature tests (Pest PHP)
- [ ] Multi-tenant isolation tests
- [ ] Deploy บน Plesk
- [ ] Supervisor queue worker
- [ ] Cron scheduler
- [ ] SSL + domain config
- [ ] Cutover จาก PHP → Laravel

---

## Required Packages

```bash
# Core
composer require laravel/jetstream
php artisan jetstream:install livewire --teams

# Auth
composer require laravel/socialite socialiteproviders/line

# QR Code
composer require simplesoftwareio/simple-qrcode

# Excel Export
composer require maatwebsite/excel

# Permissions
composer require spatie/laravel-permission

# Error Tracking
composer require sentry/sentry-laravel
```

---

## PHP Codebase Status (สิ่งที่ทำไปแล้ว)

### Folder Structure (Refactored)
- `portal/ajax/` — 15 ajax files ย้ายจาก `portal/ajax_*.php`
- `user/ajax/` — ajax_chat.php, ajax_mark_announcement_read.php
- `staff/ajax/` — ajax_scan_checkin.php
- `dev/` — debug/diagnostic scripts (เดิมชื่อ `scratch/`)
- `design/` — React/design files (เดิมชื่อ `design_handoff_user_hub/`)

### Multi-Tenant Migration (PHP — Phase 1–3 Done)

**Phase 1 - Foundation (Done)**
- `config/tenant.php` — resolve CLINIC_ID จาก subdomain
- `database/migrations/001_multitenant_foundation.php` — idempotent migration
- `config.php` — two-tier site settings, log_activity() scoped, clinic_id() helper

**Phase 2 - Auth (Done)**
- admin/auth/login.php, staff_login.php, google_callback.php — `$_SESSION['clinic_id'] = CLINIC_ID`
- line_api/callback.php — user lookup scoped to clinic_id
- user/index.php, profile.php, save_profile.php — clinic-scoped queries
- includes/session_guard.php — tenant mismatch guard

**Phase 3 - Data Layer (Partial — 16 files done)**
Done: admin/campaigns.php, bookings.php, campaign_overview.php, kpi.php, user_history.php, manage_staff.php, activity_logs.php, time_slots.php, ajax/ajax_dashboard.php, user/hub.php, booking_campaign.php, booking_date.php, booking_time.php, my_bookings.php, cancel_booking.php

**Phase 3 - Remaining (ยังค้างอยู่)**
- [ ] `user/submit_booking.php`
- [ ] `user/api_get_slots.php`
- [ ] `admin/reports.php`
- [ ] `admin/ajax/ajax_approve_booking.php`
- [ ] `admin/ajax/ajax_bulk_cancel_bookings.php`
- [ ] `admin/ajax/ajax_force_cancel.php`
- [ ] `admin/ajax/ajax_get_daily_slots.php`
- [ ] `admin/ajax/ajax_get_month_bookings.php`

**Phase 4–6 (ยังไม่ได้ทำ)**
- Phase 4: Per-clinic Settings & Branding
- Phase 5: Portal Enhancement (cross-clinic dashboard)
- Phase 6: Testing & Production Deploy

---

## Key Tables

| Table | Description |
|-------|-------------|
| `sys_clinics` | Clinic registry (id, name, slug, is_active) |
| `sys_users` | ผู้ป่วย/นักศึกษา (LINE OAuth) |
| `sys_admins` | Admin (Google OAuth) |
| `sys_staff` | เจ้าหน้าที่ (password) |
| `camp_list` | รายการ Campaign วัคซีน |
| `camp_bookings` | การจองของผู้ป่วย |
| `camp_slots` | Time slots ของแต่ละ campaign |
| `vac_appointments` | นัดหมายวัคซีน |
| `sys_site_settings` | Settings ต่อ clinic (clinic_id=0 = global) |
| `sys_activity_logs` | Activity log ต่อ clinic |
| `sys_announcements` | ประกาศต่อ clinic |
| `sys_chat_messages` | Support chat ต่อ clinic |
| `satisfaction_surveys` | แบบสอบถามความพึงพอใจ |
| `sys_email_logs` | Log การส่ง email |
| `insurance_members` | สมาชิกประกัน |

---

## Coding Conventions

### Tables / Data Grids
- **ทุกครั้งที่สร้างตารางแสดงข้อมูล ต้องทำเป็น Pagination เสมอ**
- ค่า default: **20 รายการ/หน้า**
- ต้องมีปุ่มนำทางครบ: หน้าแรก `«` / ก่อนหน้า `‹` / เลขหน้า (window ±2) / ถัดไป `›` / สุดท้าย `»`
- Pagination ต้องทำงานร่วมกับ search/filter ได้เสมอ
- ใช้ `LIMIT` + `OFFSET` ใน SQL query (PHP) หรือ `->paginate(20)` (Laravel)
- แสดง "หน้า X / Y · รวม N รายการ" เหนือ/ใต้ pagination controls

### General
- ไม่เพิ่ม comment ที่อธิบายว่าโค้ดทำอะไร — ใช้ชื่อตัวแปร/ฟังก์ชันที่สื่อความหมายแทน
- ไม่เพิ่ม feature ที่ไม่ได้ถูกขอ
- Validate input ที่ system boundary เท่านั้น (user input, external API)
- ใช้ prepared statements เสมอ (PHP) / Eloquent/Query Builder (Laravel)
