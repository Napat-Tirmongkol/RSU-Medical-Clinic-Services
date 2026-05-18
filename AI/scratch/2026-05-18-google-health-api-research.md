# Google Health API — Research & Integration Plan

> **Status**: Research / prototype only — no code committed
> **Date**: 2026-05-18
> **Branch**: `claude/google-health-api-HjLjp`
> **Scope (per user)**: ค้นข้อมูล + วาง plan; ไม่ต้องเขียน production code

---

## 1. สรุป "Google Health API" คืออะไรกันแน่

Google ไม่มี API ตัวเดียวชื่อ "Google Health API" — มี ecosystem หลายตัวที่เกี่ยวกับสุขภาพ:

| API | ลักษณะ | เหมาะกับเรา? |
|---|---|---|
| **Health Connect** (เลือกแล้ว) | On-device Android API · เก็บข้อมูลสุขภาพ/ฟิตเนส/medical records แบบรวมศูนย์ในเครื่องผู้ป่วย | ✅ ต้องมี mobile app ประกอบ |
| Google Fit REST API | Cloud REST · แต่ **deprecate ปี 2026** บังคับ migrate ไป Health Connect | ❌ ตายแล้ว |
| Cloud Healthcare API (GCP) | Server-side FHIR/HL7v2/DICOM store · ต้องมี GCP project + billing | ทางเลือก — แต่คนละทิศกับที่ user เลือก |
| MedLM / Vertex AI medical | Clinical LLM | ไม่ใช่ scope ของ task นี้ |

**ผู้ใช้เลือก**: Health Connect (Android)
**Use case ที่เลือก**: Just research / prototype

---

## 2. Health Connect — ข้อเท็จจริงสำคัญ (พฤษภาคม 2026)

### ลักษณะของ API
- **เป็น on-device Android API ล้วน ๆ** — ไม่มี cloud REST endpoint, ไม่มี server-side SDK
  - Implication: PHP backend ของเราเรียกตรงไม่ได้ ต้องมี **Android companion app** เป็นตัวกลาง
- เข้าผ่าน Jetpack SDK: `androidx.health.connect:connect-client` (ปัจจุบัน 1.2.0-alpha04, stable 1.1.0)
- Entry point: `HealthConnectClient` (Kotlin/Java)
- Min SDK: Android 8 (API 26) สำหรับ SDK · Android 9 (API 28) สำหรับตัว Health Connect app

### ข้อมูลที่อ่าน/เขียนได้ (6 หมวด)
| หมวด | record types ที่เกี่ยวกับคลินิก |
|---|---|
| **Vitals** ⭐ | `BloodGlucoseRecord` · `BloodPressureRecord` · `BodyTemperatureRecord` · `HeartRateRecord` · `HeartRateVariabilityRmssdRecord` · `OxygenSaturationRecord` · `RespiratoryRateRecord` · `RestingHeartRateRecord` · `SkinTemperatureRecord` |
| **Body Measurement** ⭐ | `WeightRecord` · `HeightRecord` · `BodyFatRecord` · `BoneMassRecord` · `BasalMetabolicRateRecord` |
| **Activity** | `StepsRecord` · `ExerciseSessionRecord` · `DistanceRecord` · `TotalCaloriesBurnedRecord` · `Vo2MaxRecord` |
| **Sleep** | `SleepSessionRecord` (รวม sleep stages) |
| **Nutrition** | `HydrationRecord` · `NutritionRecord` |
| **Cycle Tracking** | OB/GYN data (sensitive — ต้องดูเรื่อง consent ละเอียด) |

⭐ = relevant ที่สุดกับ workflow ของคลินิก (pre-visit / chronic care monitoring)

### Medical Records (FHIR) — feature ใหม่
- Health Connect รองรับ **medical records ในรูป FHIR** แล้ว
- มี sub-section "Write Medical Data" / "Read Medical Data" ในเอกสาร
- น่าจะรองรับ Observation / MedicationStatement / Immunization / Condition (รายละเอียดเต็มต้องดู docs เพิ่ม)
- คาดว่าจะต้องผ่าน **Google review process** ก่อนปล่อย app ที่อ่าน medical records (เหมือน Sensitive Permissions policy ของ Play Store)

### Permission model
- ทุก data type มี read + write permission แยกกัน เช่น
  - `android.permission.health.READ_HEART_RATE` / `WRITE_HEART_RATE`
  - `android.permission.health.READ_BLOOD_PRESSURE` / `WRITE_BLOOD_PRESSURE`
- Special permissions:
  - `READ_HEALTH_DATA_IN_BACKGROUND` — อ่าน background ได้ (สำหรับ sync)
  - `READ_HEALTH_DATA_HISTORY` — อ่านย้อนหลัง

### Sample code shape (เผื่อทบทวน)
```kotlin
// Read recent heart rate
val response = healthConnectClient.readRecords(
    ReadRecordsRequest(
        HeartRateRecord::class,
        timeRangeFilter = TimeRangeFilter.between(startTime, endTime)
    )
)

// Aggregate steps (don't use readRecords for cumulative types — double-count risk)
val agg = healthConnectClient.aggregate(
    AggregateRequest(
        metrics = setOf(StepsRecord.COUNT_TOTAL),
        timeRangeFilter = TimeRangeFilter.between(startTime, endTime)
    )
)
val totalSteps = agg[StepsRecord.COUNT_TOTAL] ?: 0L
```

---

## 3. ปัญหา / ข้อจำกัดที่ต้องตัดสินใจก่อนเดินหน้า

### 3.1 Architecture mismatch (สำคัญสุด)
RSU Medical Clinic เป็น **PHP/MySQL web app** — Health Connect เป็น **Android-only API**

ทางออกที่เป็นไปได้:
1. **สร้าง Android companion app** (Kotlin) ที่ผู้ป่วยติดตั้ง → app อ่าน Health Connect → POST ไป PHP backend
   - effort: 4-8 สัปดาห์สำหรับ MVP (app + backend ingestion + portal viewer)
   - ต้องมี Play Store account, signing keys, release pipeline
2. **ใช้ Web-based PWA / LIFF แทน** (ระบบเรามี LINE webhook อยู่แล้ว) → ผู้ป่วยกรอกเอง
   - ไม่ใช้ Health Connect จริง ๆ — แต่ลด effort 80%
3. **ปล่อย task นี้ไป** ถ้ายังไม่มี roadmap mobile app
   - บันทึก finding นี้ใน knowledge แล้วรอ Phase ที่พร้อม

### 3.2 ผู้ป่วยยังไม่มี user account ในระบบ
- ปัจจุบัน portal มีแค่ staff (sys_admins, sys_staff)
- ถ้าจะรับข้อมูลจาก Health Connect ต้องมี:
  - Patient account + auth (อาจ federate ผ่าน LINE login เพราะมี LINE infra แล้ว)
  - การ map device → patient → HN record
- เพิ่ม table `sys_patients` + `sys_patient_devices` + OAuth-ish token store

### 3.3 PDPA + ISO 27001 implications
- ข้อมูลสุขภาพเป็น "sensitive personal data" ตาม PDPA — ต้องมี:
  - Explicit consent UI (Android app เก็บลายเซ็นยินยอม) + log
  - Encrypted-at-rest (MySQL column encryption หรือ application-layer)
  - Audit log ทุก access (เหมือน pattern ของ finance audit)
  - Right to erasure (DELETE endpoint สำหรับผู้ป่วย)
- ระบบมี ISO Governance partial อยู่แล้ว — ใส่ control เพิ่มได้

### 3.4 Google Play approval
- App ที่ขอ Health Connect permissions ต้องผ่าน Play Console review
- ต้องระบุ use case + privacy policy URL + data handling แบบ public
- **Medical records permission** น่าจะ review หนักกว่า fitness data ปกติ

---

## 4. ถ้าทำจริง — สถาปัตยกรรมที่แนะนำ

```
┌──────────────────────────────┐
│ Android Companion App        │
│ (Kotlin + Jetpack Compose)   │
│                              │
│ HealthConnectClient ◀─── HC platform (on-device)
│      │                       │
│      ▼                       │
│ Local sync queue             │
│ (Room DB)                    │
│      │                       │
└──────┼───────────────────────┘
       │ HTTPS + Bearer token (per-patient JWT)
       ▼
┌──────────────────────────────┐
│ PHP Backend (existing)       │
│                              │
│ POST /api/health/sync.php    │ ← new endpoint
│   - validate patient token   │
│   - dedupe (record_uid)      │
│   - insert sys_patient_health_metrics
│   - audit log                │
│                              │
│ Portal partial:              │
│ "Patient Vitals Trend"       │ ← clinician view
│   - line charts per metric   │
│   - threshold alerts         │
└──────────────────────────────┘
       ▲
       │ AJAX
       │
┌──────────────────────────────┐
│ Portal → ผู้ป่วยรายคน        │
│ → tab "Health data"          │
└──────────────────────────────┘
```

### Schema ที่จะเพิ่ม (เสนอ)
```sql
CREATE TABLE sys_patients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  national_id_hash CHAR(64) NULL,  -- SHA256, ไม่เก็บเลขตรง
  display_name VARCHAR(200) NOT NULL,
  line_user_id VARCHAR(64) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_national (national_id_hash),
  KEY idx_line (line_user_id)
);

CREATE TABLE sys_patient_devices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NOT NULL,
  device_uid VARCHAR(128) NOT NULL,  -- จาก app installation
  platform ENUM('android') DEFAULT 'android',
  paired_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  last_sync_at DATETIME NULL,
  refresh_token_hash CHAR(64) NULL,
  UNIQUE KEY uniq_device (device_uid),
  KEY idx_patient (patient_id),
  CONSTRAINT fk_pd_patient FOREIGN KEY (patient_id) REFERENCES sys_patients(id)
);

CREATE TABLE sys_patient_health_metrics (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NOT NULL,
  metric_type VARCHAR(40) NOT NULL,   -- heart_rate / blood_pressure_sys / blood_pressure_dia / glucose / steps / weight / spo2 / sleep_minutes ...
  numeric_value DECIMAL(10,2) NULL,
  unit VARCHAR(20) NULL,
  recorded_at DATETIME NOT NULL,
  source_app VARCHAR(100) NULL,        -- เช่น com.samsung.health
  source_device VARCHAR(80) NULL,      -- WATCH / PHONE / MEDICAL_DEVICE
  hc_record_uid VARCHAR(128) NOT NULL, -- กัน dedupe (Health Connect Record.metadata.id)
  raw_json JSON NULL,
  synced_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_hc_uid (hc_record_uid),
  KEY idx_patient_metric_time (patient_id, metric_type, recorded_at)
);

CREATE TABLE sys_patient_health_audit (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NOT NULL,
  action ENUM('sync_in','clinician_view','export','delete','consent_granted','consent_revoked') NOT NULL,
  actor_type ENUM('patient','admin','system') NOT NULL,
  actor_id VARCHAR(60) NULL,
  meta_json JSON NULL,
  ip_addr VARCHAR(45) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_patient_time (patient_id, created_at)
);
```

### Portal partial ที่จะเพิ่ม
- ใส่ใน sidebar group **ประกันสุขภาพ** (icon `fa-hospital-user`) หรือ **ข้อมูลหลัก** — หรือ section ใหม่ "Patient Care" ถ้ามี module อื่นตามมา
- Section gate: `superadmin || access_patient_vitals` (เพิ่ม flag ใหม่ ตาม 7-spot checklist ใน CLAUDE.md)
- หน้า: list ผู้ป่วยที่ pair device → คลิกดู trend chart (Chart.js theme-aware) ของ vitals ย้อนหลัง 7/30/90 วัน · บอก threshold (BP > 140/90 highlight แดง, BG > 180 mg/dL highlight)
- Print A4 summary สำหรับใส่แฟ้มผู้ป่วย

### Android app stack
- Kotlin + Jetpack Compose UI
- `androidx.health.connect:connect-client` (1.1.0 stable)
- `Room` สำหรับ local queue
- `Retrofit` + OkHttp สำหรับ sync ไป backend
- `WorkManager` สำหรับ periodic sync
- LINE Login SDK สำหรับ auth (ถ้า federate ผ่าน LINE)

---

## 5. Decision matrix — ทำ vs ไม่ทำ vs ทางอ้อม

| ทางเลือก | Effort | Value to clinic | Risk |
|---|---|---|---|
| **A. Full Android app + backend** | 6-10 สัปดาห์ | สูง (chronic care, pre-visit data) | สูง — Play Store review, Android team need, PDPA exposure |
| **B. PWA / LIFF ที่ผู้ป่วยกรอกเอง** (ไม่ใช้ HC) | 1-2 สัปดาห์ | กลาง (ไม่ auto จาก wearable) | ต่ำ |
| **C. Defer — รอ mobile roadmap** | 0 | 0 ตอนนี้ | 0 — แค่บันทึก finding |
| **D. Hybrid: เริ่ม PWA ก่อน + วาง schema รองรับ HC** | 2-3 สัปดาห์ | กลาง+ (ขยายต่อได้) | ต่ำ |

**คำแนะนำ**: ถ้ายังไม่มี mandate ชัดเจนเรื่อง mobile app → ทาง **D** ดีที่สุด — สร้าง schema + portal viewer ที่รับข้อมูลจาก PWA (input form) ไปก่อน, ออกแบบให้ backend API endpoint เข้ากันได้กับ Android sync ในอนาคต. ลด lock-in กับ Google ecosystem ด้วย — เผื่อ Apple HealthKit หรือ FHIR direct จะตามมา

---

## 6. ถ้าจะทำต่อ — Next steps แบบเรียงลำดับ

### Phase 0 — Decision (ตอนนี้)
- [ ] ตัดสินใจระหว่าง option A / B / C / D ข้างบน
- [ ] ถามผู้บริหารคลินิก: มี budget สำหรับ Android dev ไหม? ผู้ป่วยกลุ่มเป้าหมายเป็นใคร (chronic / pre-op / general)?
- [ ] PDPA compliance officer ของ RSU เห็นชอบไหม (ขอข้อมูลสุขภาพรายวันถือเป็น sensitive processing)

### Phase 1 — Backend schema + ingestion endpoint (ถ้าเลือก D)
- [ ] Migration: `sys_patients`, `sys_patient_devices`, `sys_patient_health_metrics`, `sys_patient_health_audit`
- [ ] Auto-migrate runner ใน `database/migrations/`
- [ ] Endpoint `api/patient_health_sync.php` — Bearer token auth, batch insert, dedupe by `hc_record_uid`
- [ ] Audit hook (เหมือน `fin_audit_log()` pattern)
- [ ] Rate limit + size cap

### Phase 2 — Portal viewer
- [ ] Partial `portal/_partials/patient_vitals.php`
- [ ] Section ใน sidebar (group "ประกันสุขภาพ" หรือ section ใหม่ "Patient Care")
- [ ] Access flag `access_patient_vitals` (7-spot checklist)
- [ ] Chart.js trend (theme-aware) + threshold alert + A4 print
- [ ] Pagination 20/หน้า ใน list view

### Phase 3 — Patient onboarding UI (PWA หรือ Android)
- [ ] LINE Login federation (ใช้ LINE webhook ที่มีอยู่)
- [ ] Consent flow (PDPA-compliant + audit log)
- [ ] Device pair flow → ออก JWT

### Phase 4 — Android companion app (ถ้าเลือก A)
- [ ] Kotlin project skeleton
- [ ] HealthConnectClient permission request + UI
- [ ] WorkManager periodic sync
- [ ] Play Console internal track + Google review submission

### Phase 5 — Chronic care alert
- [ ] Threshold rule engine (BP, BG, HR)
- [ ] LINE Notify ส่งหา clinician เมื่อ critical
- [ ] Trend deterioration detection

---

## 7. คำถามที่ยังต้องตอบก่อนเขียน code จริง

1. **กลุ่มผู้ป่วยเป้าหมาย** — ทุกคนที่มาคลินิก? หรือเฉพาะ chronic (DM/HT)? เฉพาะ pre-op?
2. **ใครจะ maintain Android app** — มี Android dev ในทีม? Outsource?
3. **Data retention** — เก็บข้อมูลย้อนหลังกี่ปี (PDPA กำหนด minimum necessary)
4. **Integration กับ EMR / OPD record** — ผูกกับ visit record ที่ไหน
5. **Wearable ที่รองรับ** — Samsung Health / Mi Band / Fitbit / Apple Watch (HealthKit สำหรับ iOS ต้องสร้างแยก)
6. **Insurance partner involvement** — ส่งข้อมูลให้บริษัทประกันด้วยไหม (ถ้าใช่ → consent ต้องระบุ)

---

## 8. References

- Health Connect overview: https://developer.android.com/health-and-fitness/guides/health-connect
- Get started: https://developer.android.com/health-and-fitness/guides/health-connect/develop/get-started
- Data types: https://developer.android.com/health-and-fitness/guides/health-connect/data-and-data-types/data-types
- Migration guide (from Google Fit): https://developer.android.com/health-and-fitness/guides/health-connect/migrate/migration-guide
- Jetpack release notes: https://developer.android.com/jetpack/androidx/releases/health-connect

---

## 9. ข้อสรุปสำหรับ user

- **Google ไม่มี "Health API" ตัวเดียวสำหรับ web backend** — Health Connect คือทางที่ Google ผลักดัน แต่เป็น **Android-only API**
- ระบบเราเป็น PHP web — ถ้าจะใช้จริงต้องมี **Android companion app** ที่ผู้ป่วยติดตั้ง → app เป็น proxy ส่งข้อมูลเข้า backend
- effort สำหรับทำให้ครบจริง ๆ ประเมินไว้ที่ **6-10 สัปดาห์** (รวม Android dev + backend + portal viewer + Play review)
- **ทางเลือก low-risk**: สร้าง schema + portal viewer + PWA input ก่อน (Phase 1-2 ใน plan) เผื่อขยาย Android ทีหลัง — ไม่ lock เข้า Google ecosystem ทันที
- **ยังไม่ commit code อะไรในรอบนี้** — รอ user decision ว่าจะเดินทางไหน
