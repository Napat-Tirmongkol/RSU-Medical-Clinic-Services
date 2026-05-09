<?php
/**
 * portal/_partials/scholarship.php
 * จัดการระบบนักศึกษาทุน — students, shifts, approvals, reports, settings
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/scholarship_helper.php';

$pdo = db();
ensure_scholarship_schema($pdo);

$settings = get_scholarship_settings($pdo);

// Counters
$cntStudents = (int)$pdo->query("SELECT COUNT(*) FROM sys_scholarship_students WHERE status='active'")->fetchColumn();
$cntPending  = (int)$pdo->query("SELECT COUNT(*) FROM sys_scholarship_clock_logs WHERE status='pending'")->fetchColumn();
$today = date('Y-m-d');
$cntTodayShifts = (int)$pdo->query("SELECT COUNT(*) FROM sys_scholarship_shifts WHERE shift_date = '$today' AND status != 'cancelled'")->fetchColumn();

require_once __DIR__ . '/../../includes/csrf.php';
$portalCsrf = get_csrf_token();
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<style>
    .sch-card { background:#fff; border:1.5px solid #e2e8f0; border-radius:1.25rem; padding:1.5rem; }
    .sch-kpi {
        background:#fff; border:1.5px solid #e2e8f0; border-radius:1.25rem;
        padding:1.25rem; position:relative; overflow:hidden;
    }
    .sch-kpi-icon {
        position:absolute; top:1rem; right:1rem; width:2.5rem; height:2.5rem;
        border-radius:.75rem; display:flex; align-items:center; justify-content:center;
        font-size:1.05rem;
    }
    .sch-kpi-label { font-size:.7rem; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.05em; }
    .sch-kpi-value { font-size:1.875rem; font-weight:900; color:#0f172a; margin-top:.5rem; line-height:1; }
    .sch-kpi-foot { font-size:.72rem; color:#94a3b8; margin-top:.4rem; }
    .sch-rank-pill {
        display:inline-flex; align-items:center; justify-content:center;
        width:1.5rem; height:1.5rem; border-radius:99px;
        font-size:.7rem; font-weight:900; color:#fff;
    }
    .sch-tab {
        padding:.65rem 1.1rem; border-radius:.85rem; font-size:.85rem; font-weight:800;
        color:#475569; cursor:pointer; transition:all .15s; white-space:nowrap;
        background:transparent; border:1.5px solid transparent;
    }
    .sch-tab:hover { background:#f1f5f9; color:#1e293b; }
    .sch-tab.active { background:#10b981; color:#fff; border-color:#10b981; box-shadow:0 4px 10px rgba(16,185,129,.25); }
    .sch-tab .sch-badge {
        display:inline-flex; align-items:center; justify-content:center;
        min-width:20px; height:18px; padding:0 5px; margin-left:.4rem;
        border-radius:99px; background:#f43f5e; color:#fff; font-size:10px; font-weight:900;
    }
    .sch-tab.active .sch-badge { background:#fff; color:#10b981; }
    .sch-input {
        width:100%; padding:.6rem .9rem; background:#f9fafb;
        border:1.5px solid #e5e7eb; border-radius:.65rem;
        font-size:.85rem; font-weight:500; color:#111827; outline:none; transition:all .15s;
    }
    .sch-input:focus { background:#fff; border-color:#10b981; box-shadow:0 0 0 3px rgba(16,185,129,.1); }
    .sch-label { display:block; font-size:.7rem; font-weight:800; color:#4b5563; text-transform:uppercase; letter-spacing:.05em; margin-bottom:.35rem; }
    .sch-table { width:100%; border-collapse:collapse; font-size:.85rem; }
    .sch-table th { text-align:left; padding:.65rem .8rem; background:#f8fafc; font-size:.7rem; font-weight:900; color:#64748b; text-transform:uppercase; letter-spacing:.05em; border-bottom:1.5px solid #e2e8f0; }
    .sch-table td { padding:.7rem .8rem; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
    .sch-table tbody tr:hover { background:#f8fafc; }
    .sch-btn {
        display:inline-flex; align-items:center; gap:.4rem;
        padding:.55rem 1rem; border-radius:.65rem; font-size:.8rem; font-weight:800;
        background:#10b981; color:#fff; border:none; cursor:pointer; transition:all .15s;
    }
    .sch-btn:hover { background:#059669; transform:translateY(-1px); box-shadow:0 6px 14px rgba(16,185,129,.25); }
    .sch-btn--ghost { background:#f1f5f9; color:#475569; }
    .sch-btn--ghost:hover { background:#e2e8f0; color:#1e293b; box-shadow:none; transform:none; }
    .sch-btn--danger { background:#f43f5e; }
    .sch-btn--danger:hover { background:#e11d48; box-shadow:0 6px 14px rgba(244,63,94,.25); }
    .sch-btn--xs { padding:.35rem .65rem; font-size:.72rem; }
    .sch-status-badge {
        display:inline-flex; align-items:center; padding:.2rem .55rem; border-radius:99px;
        font-size:.7rem; font-weight:900; text-transform:uppercase; letter-spacing:.04em;
    }
    .sch-modal-backdrop { position:fixed; inset:0; background:rgba(15,23,42,.5); z-index:200; display:none; align-items:center; justify-content:center; padding:1rem; }
    .sch-modal-backdrop.show { display:flex; }
    .sch-modal-box { background:#fff; border-radius:1.5rem; max-width:560px; width:100%; max-height:90vh; overflow-y:auto; padding:1.75rem; }
    .pg-btn {
        min-width:34px; height:34px; display:inline-flex; align-items:center; justify-content:center;
        border-radius:.6rem; background:#fff; border:1.5px solid #e2e8f0;
        font-weight:800; font-size:13px; color:#475569; transition:all .15s; text-decoration:none; cursor:pointer;
    }
    .pg-btn:hover:not(:disabled) { background:#f1f5f9; border-color:#cbd5e1; }
    .pg-btn:disabled, .pg-btn.disabled { opacity:.35; pointer-events:none; }
    .pg-btn.active { background:#10b981; color:#fff; border-color:#10b981; }
</style>

<div class="max-w-[1400px] mx-auto px-5 md:px-8 py-8">

    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-black text-slate-900">นักศึกษาทุน — เก็บชั่วโมงทำงาน</h1>
            <p class="text-sm text-slate-500 mt-1">จัดการนักศึกษา · ตารางกะ · อนุมัติเข้า-ออกงาน · รายงาน</p>
        </div>
        <div class="flex items-center gap-3">
            <div class="text-right">
                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">นักศึกษาทุน</p>
                <p class="text-lg font-black text-slate-900"><?= number_format($cntStudents) ?> คน</p>
            </div>
            <div class="w-px h-10 bg-slate-200"></div>
            <div class="text-right">
                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">รออนุมัติ</p>
                <p class="text-lg font-black text-rose-600"><?= number_format($cntPending) ?></p>
            </div>
            <div class="w-px h-10 bg-slate-200"></div>
            <div class="text-right">
                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">กะวันนี้</p>
                <p class="text-lg font-black text-emerald-600"><?= number_format($cntTodayShifts) ?></p>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="flex gap-2 mb-5 overflow-x-auto pb-2">
        <button class="sch-tab active" data-tab="dashboard">
            <i class="fa-solid fa-chart-pie mr-1.5"></i>Dashboard
        </button>
        <button class="sch-tab" data-tab="approvals">
            <i class="fa-solid fa-circle-check mr-1.5"></i>รออนุมัติ
            <?php if ($cntPending > 0): ?><span class="sch-badge"><?= $cntPending > 99 ? '99+' : $cntPending ?></span><?php endif; ?>
        </button>
        <button class="sch-tab" data-tab="students">
            <i class="fa-solid fa-graduation-cap mr-1.5"></i>นักศึกษาทุน
        </button>
        <button class="sch-tab" data-tab="shifts">
            <i class="fa-solid fa-calendar-days mr-1.5"></i>ตารางกะ
        </button>
        <button class="sch-tab" data-tab="reports">
            <i class="fa-solid fa-chart-line mr-1.5"></i>รายงาน
        </button>
        <button class="sch-tab" data-tab="settings">
            <i class="fa-solid fa-gear mr-1.5"></i>ตั้งค่า
        </button>
    </div>

    <!-- ─── TAB: DASHBOARD ─── -->
    <div class="sch-pane" data-pane="dashboard">

        <!-- KPI cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-3 mb-5">
            <div class="sch-kpi">
                <div class="sch-kpi-icon" style="background:#dbeafe;color:#2563eb"><i class="fa-solid fa-graduation-cap"></i></div>
                <p class="sch-kpi-label">นักศึกษา Active</p>
                <p class="sch-kpi-value" id="kpi-students">–</p>
                <p class="sch-kpi-foot">คน</p>
            </div>
            <div class="sch-kpi">
                <div class="sch-kpi-icon" style="background:#fee2e2;color:#dc2626"><i class="fa-solid fa-bell"></i></div>
                <p class="sch-kpi-label">รออนุมัติ</p>
                <p class="sch-kpi-value text-rose-600" id="kpi-pending">–</p>
                <p class="sch-kpi-foot">รายการ</p>
            </div>
            <div class="sch-kpi">
                <div class="sch-kpi-icon" style="background:#cffafe;color:#0891b2"><i class="fa-solid fa-calendar-day"></i></div>
                <p class="sch-kpi-label">กะวันนี้</p>
                <p class="sch-kpi-value text-cyan-600" id="kpi-today">–</p>
                <p class="sch-kpi-foot">กะ</p>
            </div>
            <div class="sch-kpi">
                <div class="sch-kpi-icon" style="background:#d1fae5;color:#059669"><i class="fa-solid fa-graduation-cap"></i></div>
                <p class="sch-kpi-label">ชม.ทุน เดือนนี้</p>
                <p class="sch-kpi-value text-emerald-600" id="kpi-month-hours">–</p>
                <p class="sch-kpi-foot">ชั่วโมง</p>
            </div>
            <div class="sch-kpi">
                <div class="sch-kpi-icon" style="background:#fef3c7;color:#d97706"><i class="fa-solid fa-coins"></i></div>
                <p class="sch-kpi-label">ชม.ค่าตอบแทน เดือนนี้</p>
                <p class="sch-kpi-value text-amber-600" id="kpi-month-paid">–</p>
                <p class="sch-kpi-foot">ชั่วโมง</p>
            </div>
        </div>

        <!-- Charts row -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 mb-5">
            <div class="sch-card lg:col-span-2">
                <h3 class="text-sm font-black text-slate-900 mb-3">ชั่วโมงรายวัน 30 วันล่าสุด</h3>
                <div style="position:relative;height:280px"><canvas id="chart-daily"></canvas></div>
            </div>
            <div class="sch-card">
                <h3 class="text-sm font-black text-slate-900 mb-3">สัดส่วนเดือนนี้</h3>
                <div style="position:relative;height:280px"><canvas id="chart-split"></canvas></div>
            </div>
        </div>

        <!-- Lower row: top + today status -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 mb-5">
            <div class="sch-card">
                <h3 class="text-sm font-black text-slate-900 mb-3"><i class="fa-solid fa-trophy text-amber-500 mr-1"></i>Top 5 เดือนนี้</h3>
                <div id="dash-top-wrap"></div>
            </div>
            <div class="sch-card">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-black text-slate-900"><i class="fa-solid fa-calendar-day text-cyan-500 mr-1"></i>กะวันนี้</h3>
                </div>
                <div id="dash-today-wrap"></div>
            </div>
        </div>

        <!-- Recent activity -->
        <div class="sch-card">
            <h3 class="text-sm font-black text-slate-900 mb-3"><i class="fa-solid fa-clock-rotate-left text-slate-400 mr-1"></i>กิจกรรมล่าสุด</h3>
            <div id="dash-recent-wrap"></div>
        </div>
    </div>

    <!-- ─── TAB: APPROVALS ─── -->
    <div class="sch-pane hidden" data-pane="approvals">
        <div class="sch-card">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-black text-slate-900">รายการรออนุมัติ</h3>
                <div class="flex gap-2">
                    <input type="text" id="appr-search" placeholder="ค้นหาชื่อ/รหัส" class="sch-input" style="width:240px">
                    <button class="sch-btn sch-btn--ghost" onclick="loadApprovals()">
                        <i class="fa-solid fa-rotate"></i>รีเฟรช
                    </button>
                </div>
            </div>
            <div id="appr-table-wrap">
                <p class="text-center text-sm text-slate-400 py-10"><i class="fa-solid fa-spinner fa-spin mr-2"></i>กำลังโหลด…</p>
            </div>
        </div>
    </div>

    <!-- ─── TAB: STUDENTS ─── -->
    <div class="sch-pane hidden" data-pane="students">
        <div class="sch-card">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-black text-slate-900">รายชื่อนักศึกษาทุน</h3>
                <div class="flex gap-2">
                    <input type="text" id="stu-search" placeholder="ค้นหา ชื่อ/รหัส/คณะ" class="sch-input" style="width:280px">
                    <select id="stu-filter-status" class="sch-input" style="width:140px">
                        <option value="">ทุกสถานะ</option>
                        <option value="active">ใช้งาน</option>
                        <option value="inactive">ระงับ</option>
                    </select>
                    <button class="sch-btn" onclick="openStudentModal()">
                        <i class="fa-solid fa-plus"></i>เพิ่มนักศึกษา
                    </button>
                </div>
            </div>
            <div id="stu-table-wrap">
                <p class="text-center text-sm text-slate-400 py-10"><i class="fa-solid fa-spinner fa-spin mr-2"></i>กำลังโหลด…</p>
            </div>
        </div>
    </div>

    <!-- ─── TAB: SHIFTS ─── -->
    <div class="sch-pane hidden" data-pane="shifts">
        <div class="sch-card">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-black text-slate-900">ตารางกะ</h3>
                <div class="flex gap-2">
                    <select id="shift-filter-student" class="sch-input" style="width:220px">
                        <option value="">ทุกคน</option>
                    </select>
                    <input type="date" id="shift-from" class="sch-input" style="width:160px">
                    <input type="date" id="shift-to" class="sch-input" style="width:160px">
                    <button class="sch-btn sch-btn--ghost" onclick="loadShifts()">กรอง</button>
                    <button class="sch-btn" onclick="openShiftModal()">
                        <i class="fa-solid fa-plus"></i>เพิ่มกะ
                    </button>
                </div>
            </div>
            <div id="shift-table-wrap">
                <p class="text-center text-sm text-slate-400 py-10"><i class="fa-solid fa-spinner fa-spin mr-2"></i>กำลังโหลด…</p>
            </div>
        </div>
    </div>

    <!-- ─── TAB: REPORTS ─── -->
    <div class="sch-pane hidden" data-pane="reports">
        <div class="sch-card">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-black text-slate-900">สรุปชั่วโมงทำงาน</h3>
                <div class="flex gap-2">
                    <input type="date" id="rep-from" class="sch-input" style="width:160px" value="<?= date('Y-m-01') ?>">
                    <input type="date" id="rep-to" class="sch-input" style="width:160px" value="<?= date('Y-m-t') ?>">
                    <button class="sch-btn sch-btn--ghost" onclick="loadReports()">กรอง</button>
                    <button class="sch-btn" onclick="exportReportCSV()">
                        <i class="fa-solid fa-file-csv"></i>Export CSV
                    </button>
                </div>
            </div>
            <div id="rep-table-wrap">
                <p class="text-center text-sm text-slate-400 py-10"><i class="fa-solid fa-spinner fa-spin mr-2"></i>กำลังโหลด…</p>
            </div>
        </div>
    </div>

    <!-- ─── TAB: SETTINGS ─── -->
    <div class="sch-pane hidden" data-pane="settings">
        <div class="sch-card max-w-2xl">
            <h3 class="text-base font-black text-slate-900 mb-4">ตั้งค่าระบบ</h3>
            <div class="space-y-4">

                <!-- GPS toggle (master switch) -->
                <label class="flex items-start gap-3 cursor-pointer p-4 rounded-xl bg-emerald-50 border border-emerald-100">
                    <input type="checkbox" id="set-gps-required" <?= (int)$settings['gps_required'] ? 'checked' : '' ?> class="mt-0.5">
                    <div>
                        <p class="text-sm font-black text-slate-800">ตรวจ GPS ตำแหน่งทำงาน</p>
                        <p class="text-xs text-slate-500 mt-0.5">เปิด: บังคับให้ user อยู่ในรัศมีคลินิก · ปิด: เจ้าหน้าที่อนุมัติด้วยตนเอง (ไม่ขอ GPS)</p>
                    </div>
                </label>

                <!-- GPS-related fields (disabled when GPS off) -->
                <div id="gps-fieldset" class="space-y-4 pl-1">
                    <div>
                        <label class="sch-label">พิกัดคลินิก (Latitude)</label>
                        <input type="number" step="any" id="set-lat" class="sch-input" placeholder="เช่น 13.7563" value="<?= htmlspecialchars((string)($settings['clinic_lat'] ?? ''), ENT_QUOTES) ?>">
                    </div>
                    <div>
                        <label class="sch-label">พิกัดคลินิก (Longitude)</label>
                        <input type="number" step="any" id="set-lng" class="sch-input" placeholder="เช่น 100.5018" value="<?= htmlspecialchars((string)($settings['clinic_lng'] ?? ''), ENT_QUOTES) ?>">
                    </div>
                    <div class="flex items-end gap-2">
                        <button class="sch-btn sch-btn--ghost" onclick="useCurrentLocation()" type="button">
                            <i class="fa-solid fa-location-crosshairs"></i>ใช้ตำแหน่งปัจจุบัน
                        </button>
                        <button class="sch-btn sch-btn--ghost" onclick="openMapPreview()" type="button">
                            <i class="fa-solid fa-map"></i>ดูในแผนที่
                        </button>
                    </div>
                    <div>
                        <label class="sch-label">รัศมี GPS (เมตร)</label>
                        <input type="number" min="10" id="set-radius" class="sch-input" value="<?= (int)$settings['radius_m'] ?>">
                    </div>
                </div>

                <div>
                    <label class="sch-label">เข้างานก่อนกะได้ (นาที)</label>
                    <input type="number" min="0" id="set-grace" class="sch-input" value="<?= (int)$settings['grace_before_min'] ?>">
                </div>

                <label class="flex items-center gap-3 cursor-pointer p-3 rounded-xl bg-slate-50">
                    <input type="checkbox" id="set-require-approval" <?= (int)$settings['require_approval'] ? 'checked' : '' ?>>
                    <span class="text-sm font-bold text-slate-700">ต้องให้พนักงานอนุมัติทุกครั้ง</span>
                </label>
                <div class="flex gap-2 pt-2">
                    <button class="sch-btn" onclick="saveSettings()">
                        <i class="fa-solid fa-floppy-disk"></i>บันทึกการตั้งค่า
                    </button>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- ─── MODAL: STUDENT ─── -->
<div class="sch-modal-backdrop" id="student-modal">
    <div class="sch-modal-box">
        <h3 class="text-lg font-black mb-4" id="student-modal-title">เพิ่มนักศึกษาทุน</h3>
        <input type="hidden" id="stu-id">
        <div class="space-y-3">
            <div>
                <label class="sch-label">User Account (พิมพ์เพื่อค้นหา)</label>
                <input type="text" id="stu-user-search" class="sch-input" placeholder="ชื่อ/เบอร์/อีเมล/เลขบัตร" autocomplete="off">
                <div id="stu-user-suggest" class="mt-2 max-h-44 overflow-y-auto rounded-xl border border-slate-200 hidden bg-white"></div>
                <input type="hidden" id="stu-user-id">
                <p id="stu-user-selected" class="text-xs text-emerald-600 font-bold mt-1 hidden"></p>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="sch-label">รหัสนักศึกษา</label>
                    <input type="text" id="stu-code" class="sch-input">
                </div>
                <div>
                    <label class="sch-label">คณะ/หน่วยงาน</label>
                    <input type="text" id="stu-faculty" class="sch-input">
                </div>
            </div>
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="sch-label">ประเภททุน</label>
                    <input type="text" id="stu-type" class="sch-input" placeholder="เช่น ทุนทำงาน">
                </div>
                <div>
                    <label class="sch-label">ภาคเรียน</label>
                    <input type="text" id="stu-semester" class="sch-input" placeholder="เช่น 1/2568">
                </div>
                <div>
                    <label class="sch-label">เป้าชั่วโมง</label>
                    <input type="number" min="0" id="stu-max-hours" class="sch-input" placeholder="0 = ไม่กำหนด">
                </div>
            </div>
            <div>
                <label class="sch-label">หมายเหตุ</label>
                <input type="text" id="stu-notes" class="sch-input">
            </div>
            <div>
                <label class="sch-label">สถานะ</label>
                <select id="stu-status" class="sch-input">
                    <option value="active">ใช้งาน</option>
                    <option value="inactive">ระงับ</option>
                </select>
            </div>
        </div>
        <div class="flex justify-end gap-2 mt-5">
            <button class="sch-btn sch-btn--ghost" onclick="closeModal('student-modal')">ยกเลิก</button>
            <button class="sch-btn" onclick="saveStudent()"><i class="fa-solid fa-floppy-disk"></i>บันทึก</button>
        </div>
    </div>
</div>

<!-- ─── MODAL: MANUAL ADJUSTMENT ─── -->
<div class="sch-modal-backdrop" id="adjust-modal">
    <div class="sch-modal-box" style="max-width:640px">
        <h3 class="text-lg font-black mb-1">ปรับชั่วโมงด้วยมือ</h3>
        <p class="text-xs text-slate-500 mb-4">บวก/ลบ ชั่วโมงสะสมของนักศึกษา (ไม่กระทบ clock log เดิม)</p>
        <input type="hidden" id="adj-student-id">
        <div class="bg-slate-50 rounded-xl px-4 py-3 mb-4">
            <p class="text-xs text-slate-500">นักศึกษา</p>
            <p class="font-black text-slate-900" id="adj-student-name">-</p>
            <p class="text-xs text-slate-500 mt-1">ชั่วโมงสะสมปัจจุบัน: <span class="font-black text-emerald-600" id="adj-current-hours">0</span> ชั่วโมง</p>
        </div>

        <div class="space-y-3">
            <div>
                <label class="sch-label">ประเภท</label>
                <div class="grid grid-cols-2 gap-2">
                    <label class="cursor-pointer rounded-xl border-2 border-emerald-200 bg-emerald-50 p-2.5 text-center text-sm font-black text-emerald-700" id="adj-ct-hours-lbl">
                        <input type="radio" name="adj-ct" value="hours" checked class="hidden">
                        <i class="fa-solid fa-graduation-cap mr-1"></i>ส่งชั่วโมงทุน
                    </label>
                    <label class="cursor-pointer rounded-xl border-2 border-amber-200 bg-amber-50 p-2.5 text-center text-sm font-black text-amber-700" id="adj-ct-paid-lbl">
                        <input type="radio" name="adj-ct" value="paid" class="hidden">
                        <i class="fa-solid fa-coins mr-1"></i>ค่าตอบแทน
                    </label>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="sch-label">จำนวนชั่วโมง (+/-)</label>
                    <input type="number" step="0.25" id="adj-delta" class="sch-input" placeholder="เช่น 5 หรือ -2.5">
                    <p class="text-[10px] text-slate-500 mt-1">ใส่ค่าลบ (-) เพื่อหักออก</p>
                </div>
                <div>
                    <label class="sch-label">วันที่บันทึก</label>
                    <input type="date" id="adj-date" class="sch-input">
                </div>
            </div>
            <div>
                <label class="sch-label">เหตุผล <span class="text-rose-500">*</span></label>
                <input type="text" id="adj-reason" class="sch-input" placeholder="เช่น ชดเชยกะที่ระบบไม่บันทึก, หักจากการลา">
            </div>
        </div>

        <div class="flex justify-end gap-2 mt-5">
            <button class="sch-btn sch-btn--ghost" onclick="closeModal('adjust-modal')">ปิด</button>
            <button class="sch-btn" onclick="saveAdjustment()"><i class="fa-solid fa-floppy-disk"></i>บันทึกการปรับ</button>
        </div>

        <hr class="my-5 border-slate-100">
        <h4 class="text-sm font-black text-slate-700 mb-3">ประวัติการปรับ</h4>
        <div id="adj-history-wrap" class="max-h-64 overflow-y-auto"></div>
    </div>
</div>

<!-- ─── MODAL: SHIFT ─── -->
<div class="sch-modal-backdrop" id="shift-modal">
    <div class="sch-modal-box">
        <h3 class="text-lg font-black mb-4" id="shift-modal-title">เพิ่มกะ</h3>
        <input type="hidden" id="shift-id">
        <div class="space-y-3">
            <div>
                <label class="sch-label">นักศึกษา</label>
                <select id="shift-student" class="sch-input"></select>
            </div>
            <div>
                <label class="sch-label">ประเภทเวลา</label>
                <div class="grid grid-cols-2 gap-2">
                    <label class="cursor-pointer rounded-xl border-2 border-emerald-200 bg-emerald-50 p-2.5 text-center text-sm font-black text-emerald-700" id="shift-ct-hours-lbl">
                        <input type="radio" name="shift-ct" value="hours" checked class="hidden">
                        <i class="fa-solid fa-graduation-cap mr-1"></i>ส่งชั่วโมงทุน
                    </label>
                    <label class="cursor-pointer rounded-xl border-2 border-amber-200 bg-amber-50 p-2.5 text-center text-sm font-black text-amber-700" id="shift-ct-paid-lbl">
                        <input type="radio" name="shift-ct" value="paid" class="hidden">
                        <i class="fa-solid fa-coins mr-1"></i>ค่าตอบแทน
                    </label>
                </div>
            </div>
            <div>
                <label class="sch-label">วันที่</label>
                <input type="date" id="shift-date" class="sch-input">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="sch-label">เวลาเริ่ม</label>
                    <input type="time" id="shift-start" class="sch-input">
                </div>
                <div>
                    <label class="sch-label">เวลาสิ้นสุด</label>
                    <input type="time" id="shift-end" class="sch-input">
                </div>
            </div>
            <div>
                <label class="sch-label">หมายเหตุ</label>
                <input type="text" id="shift-notes" class="sch-input">
            </div>
        </div>
        <div class="flex justify-end gap-2 mt-5">
            <button class="sch-btn sch-btn--ghost" onclick="closeModal('shift-modal')">ยกเลิก</button>
            <button class="sch-btn sch-btn--danger" id="shift-delete-btn" onclick="deleteShift()" style="display:none">ลบกะ</button>
            <button class="sch-btn" onclick="saveShift()"><i class="fa-solid fa-floppy-disk"></i>บันทึก</button>
        </div>
    </div>
</div>

<script>
(function() {
    const PORTAL_CSRF = <?= json_encode($portalCsrf, JSON_UNESCAPED_SLASHES) ?>;
    const AJAX = '<?= isset($_SERVER['SCRIPT_NAME']) ? rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') : '' ?>/ajax_scholarship.php';

    let studentsCache = [];

    // Tabs
    document.querySelectorAll('.sch-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.sch-tab').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            const tab = btn.dataset.tab;
            document.querySelectorAll('.sch-pane').forEach(p => {
                p.classList.toggle('hidden', p.dataset.pane !== tab);
            });
            const loader = ({
                dashboard: loadDashboard,
                approvals: loadApprovals,
                students: loadStudents,
                shifts: loadShifts,
                reports: loadReports,
            })[tab];
            if (loader) loader();
        });
    });

    // ── API helper
    async function api(entity, action, data = {}, method = 'POST') {
        const fd = new FormData();
        fd.append('csrf_token', PORTAL_CSRF);
        fd.append('entity', entity);
        fd.append('action', action);
        for (const [k, v] of Object.entries(data)) fd.append(k, v == null ? '' : String(v));
        const r = await fetch(AJAX, { method, body: fd });
        return r.json();
    }
    window.__schApi = api;

    // ────── DASHBOARD ──────
    let dashChartDaily = null, dashChartSplit = null;

    async function loadDashboard() {
        const j = await api('dashboard', 'get', {});
        if (!j.ok) {
            document.getElementById('kpi-students').textContent = 'Err';
            return;
        }
        // KPIs
        document.getElementById('kpi-students').textContent = j.kpis.active_students;
        document.getElementById('kpi-pending').textContent = j.kpis.pending;
        document.getElementById('kpi-today').textContent = j.kpis.today_shifts;
        document.getElementById('kpi-month-hours').textContent = j.kpis.month_hours.toFixed(1);
        document.getElementById('kpi-month-paid').textContent = j.kpis.month_paid.toFixed(1);

        // Daily chart
        const labels = j.daily.map(d => d.label);
        const dataHours = j.daily.map(d => d.hours);
        const dataPaid = j.daily.map(d => d.paid);
        if (dashChartDaily) dashChartDaily.destroy();
        dashChartDaily = new Chart(document.getElementById('chart-daily'), {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    { label: 'ส่งชั่วโมงทุน', data: dataHours, backgroundColor: '#10b981', stack: 'h' },
                    { label: 'ค่าตอบแทน',     data: dataPaid,  backgroundColor: '#f59e0b', stack: 'h' },
                ],
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { font: { weight: 'bold' } } } },
                scales: {
                    x: { stacked: true, ticks: { font: { size: 10 } } },
                    y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } },
                },
            },
        });

        // Split donut
        if (dashChartSplit) dashChartSplit.destroy();
        const splitTotal = j.kpis.month_hours + j.kpis.month_paid;
        if (splitTotal > 0) {
            dashChartSplit = new Chart(document.getElementById('chart-split'), {
                type: 'doughnut',
                data: {
                    labels: ['ส่งชั่วโมงทุน', 'ค่าตอบแทน'],
                    datasets: [{
                        data: [j.kpis.month_hours, j.kpis.month_paid],
                        backgroundColor: ['#10b981', '#f59e0b'],
                        borderWidth: 0,
                    }],
                },
                options: {
                    responsive: true, maintainAspectRatio: false, cutout: '65%',
                    plugins: {
                        legend: { position: 'bottom', labels: { font: { weight: 'bold' } } },
                        tooltip: { callbacks: { label: c => `${c.label}: ${c.parsed.toFixed(1)} ชม.` } },
                    },
                },
            });
        } else {
            const ctx = document.getElementById('chart-split');
            ctx.parentElement.innerHTML = '<p class="text-center text-sm text-slate-400 py-20">ยังไม่มีข้อมูลเดือนนี้</p>';
        }

        // Top
        const tw = document.getElementById('dash-top-wrap');
        if (j.top.length === 0) {
            tw.innerHTML = '<p class="text-center text-sm text-slate-400 py-6">ยังไม่มีข้อมูลเดือนนี้</p>';
        } else {
            const medals = ['#fbbf24', '#94a3b8', '#d97706', '#64748b', '#64748b'];
            tw.innerHTML = '<div class="space-y-2">' + j.top.map((r, i) => `
                <div class="flex items-center gap-3 p-2 rounded-xl hover:bg-slate-50">
                    <span class="sch-rank-pill" style="background:${medals[i] || '#64748b'}">${i + 1}</span>
                    <div class="flex-1 min-w-0">
                        <p class="font-black text-sm text-slate-900 truncate">${escHtml(r.full_name)}</p>
                        <p class="text-xs text-slate-500 truncate">${escHtml(r.student_code || '-')} · ${escHtml(r.faculty || '-')}</p>
                    </div>
                    <div class="text-right shrink-0">
                        <p class="font-black text-sm">${parseFloat(r.total).toFixed(1)} <span class="text-xs text-slate-400 font-normal">ชม.</span></p>
                        <p class="text-[10px] text-slate-500">
                            <span class="text-emerald-600">🎓 ${parseFloat(r.hours_scholarship).toFixed(0)}</span> ·
                            <span class="text-amber-600">🪙 ${parseFloat(r.hours_paid).toFixed(0)}</span>
                        </p>
                    </div>
                </div>`).join('') + '</div>';
        }

        // Today's shift status
        const dw = document.getElementById('dash-today-wrap');
        if (j.today_list.length === 0) {
            dw.innerHTML = '<p class="text-center text-sm text-slate-400 py-6">วันนี้ไม่มีกะ</p>';
        } else {
            dw.innerHTML = '<div class="space-y-2">' + j.today_list.map(sh => {
                const stat = ({
                    in:     ['bg-emerald-50 text-emerald-700', 'fa-circle-check', 'อยู่ในงาน'],
                    out:    ['bg-slate-100 text-slate-500',    'fa-circle-stop',  'ออกแล้ว'],
                    absent: ['bg-rose-50 text-rose-600',       'fa-circle-xmark', 'ยังไม่มา'],
                })[sh.arrival_status] || ['bg-slate-50 text-slate-500', 'fa-question', '-'];
                const ct = (sh.comp_type || 'hours') === 'paid'
                    ? '<i class="fa-solid fa-coins text-amber-500 text-[10px]" title="ค่าตอบแทน"></i>'
                    : '<i class="fa-solid fa-graduation-cap text-emerald-500 text-[10px]" title="ทุน"></i>';
                return `<div class="flex items-center gap-3 p-2 rounded-xl bg-slate-50">
                    <div class="text-xs font-mono font-black text-slate-700 shrink-0" style="min-width:75px">
                        ${sh.start_time.substr(0,5)}–${sh.end_time.substr(0,5)}
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-bold text-slate-900 truncate">${escHtml(sh.student_name)} ${ct}</p>
                        <p class="text-[10px] text-slate-500 truncate">${escHtml(sh.student_code || '-')}</p>
                    </div>
                    <span class="sch-status-badge ${stat[0]} shrink-0">
                        <i class="fa-solid ${stat[1]} mr-1"></i>${stat[2]}
                    </span>
                </div>`;
            }).join('') + '</div>';
        }

        // Recent activity
        const rw = document.getElementById('dash-recent-wrap');
        if (j.recent.length === 0) {
            rw.innerHTML = '<p class="text-center text-sm text-slate-400 py-6">ยังไม่มีกิจกรรม</p>';
        } else {
            rw.innerHTML = '<div class="grid grid-cols-1 md:grid-cols-2 gap-2">' + j.recent.map(l => {
                const isIn = l.action === 'clock_in';
                const stat = ({
                    pending:  ['text-rose-600', 'รออนุมัติ'],
                    approved: ['text-emerald-600', 'อนุมัติ'],
                    rejected: ['text-slate-500', 'ปฏิเสธ'],
                })[l.status] || ['text-slate-500', l.status];
                const ct = (l.comp_type || 'hours') === 'paid' ? 'fa-coins text-amber-500' : 'fa-graduation-cap text-emerald-500';
                return `<div class="flex items-center gap-2 p-2 rounded-xl hover:bg-slate-50 text-xs">
                    <i class="fa-solid ${isIn ? 'fa-right-to-bracket text-emerald-500' : 'fa-right-from-bracket text-rose-500'}"></i>
                    <span class="font-bold text-slate-700 truncate flex-1">${escHtml(l.student_name)}</span>
                    <i class="fa-solid ${ct} text-[10px]"></i>
                    <span class="text-slate-400 font-mono">${l.event_at.substr(5, 11)}</span>
                    <span class="font-bold ${stat[0]}">${stat[1]}</span>
                </div>`;
            }).join('') + '</div>';
        }
    }
    window.loadDashboard = loadDashboard;

    // ────── APPROVALS ──────
    async function loadApprovals() {
        const wrap = document.getElementById('appr-table-wrap');
        wrap.innerHTML = '<p class="text-center text-sm text-slate-400 py-10"><i class="fa-solid fa-spinner fa-spin mr-2"></i>กำลังโหลด…</p>';
        const q = document.getElementById('appr-search').value.trim();
        const j = await api('approvals', 'list', { q });
        if (!j.ok) { wrap.innerHTML = `<p class="text-center text-rose-500 py-6">${j.error || 'โหลดไม่สำเร็จ'}</p>`; return; }
        if (j.rows.length === 0) {
            wrap.innerHTML = '<p class="text-center text-slate-400 py-10"><i class="fa-solid fa-inbox text-2xl block mb-2"></i>ไม่มีรายการรออนุมัติ</p>';
            return;
        }
        let html = '<table class="sch-table"><thead><tr>'
            + '<th>นักศึกษา</th><th>ประเภท</th><th>หมวด</th><th>เวลา</th><th>ระยะ GPS</th><th>กะ</th><th class="text-right">การจัดการ</th>'
            + '</tr></thead><tbody>';
        for (const r of j.rows) {
            const isIn = r.action === 'clock_in';
            const distHtml = r.distance_m === null
                ? '<span class="text-slate-400">-</span>'
                : `${Math.round(r.distance_m)} ม.${!r.within_radius ? ' <span class="text-amber-600 font-black">⚠</span>' : ''}`;
            const ct = (r.comp_type || 'hours') === 'paid'
                ? '<span class="sch-status-badge bg-amber-50 text-amber-700"><i class="fa-solid fa-coins mr-1"></i>ค่าตอบแทน</span>'
                : '<span class="sch-status-badge bg-emerald-50 text-emerald-700"><i class="fa-solid fa-graduation-cap mr-1"></i>ทุน</span>';
            html += `<tr>
                <td>
                    <p class="font-black text-slate-900">${escHtml(r.student_name)}</p>
                    <p class="text-xs text-slate-500">${escHtml(r.student_code || '-')} · ${escHtml(r.faculty || '-')}</p>
                </td>
                <td>
                    <span class="sch-status-badge ${isIn ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'}">
                        ${isIn ? 'เข้างาน' : 'ออกงาน'}
                    </span>
                </td>
                <td>${ct}</td>
                <td class="font-mono text-xs">${escHtml(r.event_at)}</td>
                <td class="text-xs">${distHtml}</td>
                <td class="text-xs text-slate-500">${r.shift_label || '-'}</td>
                <td class="text-right">
                    <button class="sch-btn sch-btn--xs" onclick="approveLog(${r.id})"><i class="fa-solid fa-check"></i>อนุมัติ</button>
                    <button class="sch-btn sch-btn--xs sch-btn--danger" onclick="rejectLog(${r.id})"><i class="fa-solid fa-xmark"></i>ปฏิเสธ</button>
                </td>
            </tr>`;
        }
        html += '</tbody></table>';
        wrap.innerHTML = html;
    }
    window.loadApprovals = loadApprovals;

    window.approveLog = async function(id) {
        const j = await api('approvals', 'approve', { id });
        if (j.ok) { Swal.fire({ icon: 'success', title: 'อนุมัติแล้ว', timer: 1200, showConfirmButton: false }); loadApprovals(); }
        else Swal.fire('ไม่สำเร็จ', j.error || '', 'error');
    };
    window.rejectLog = async function(id) {
        const r = await Swal.fire({
            title: 'เหตุผลที่ปฏิเสธ', input: 'text',
            inputPlaceholder: 'เช่น อยู่นอกพื้นที่ / ข้อมูลไม่ถูกต้อง',
            showCancelButton: true, confirmButtonText: 'ปฏิเสธ', cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#f43f5e',
        });
        if (!r.isConfirmed) return;
        const j = await api('approvals', 'reject', { id, reason: r.value || '' });
        if (j.ok) { Swal.fire({ icon: 'success', title: 'ปฏิเสธแล้ว', timer: 1200, showConfirmButton: false }); loadApprovals(); }
        else Swal.fire('ไม่สำเร็จ', j.error || '', 'error');
    };

    document.getElementById('appr-search').addEventListener('input', debounce(loadApprovals, 250));

    // ────── STUDENTS ──────
    async function loadStudents(page = 1) {
        const wrap = document.getElementById('stu-table-wrap');
        wrap.innerHTML = '<p class="text-center text-sm text-slate-400 py-10"><i class="fa-solid fa-spinner fa-spin mr-2"></i>กำลังโหลด…</p>';
        const q = document.getElementById('stu-search').value.trim();
        const status = document.getElementById('stu-filter-status').value;
        const j = await api('students', 'list', { q, status, page });
        if (!j.ok) { wrap.innerHTML = `<p class="text-center text-rose-500 py-6">${j.error || ''}</p>`; return; }

        studentsCache = j.rows;
        // populate shift filter dropdown
        const sf = document.getElementById('shift-filter-student');
        sf.innerHTML = '<option value="">ทุกคน</option>'
            + j.rows.map(s => `<option value="${s.id}">${escHtml(s.full_name)}</option>`).join('');

        if (j.rows.length === 0) {
            wrap.innerHTML = '<p class="text-center text-slate-400 py-10"><i class="fa-solid fa-graduation-cap text-2xl block mb-2"></i>ยังไม่มีนักศึกษาทุน</p>';
            return;
        }
        let html = '<table class="sch-table"><thead><tr>'
            + '<th>ชื่อ</th><th>รหัส</th><th>คณะ</th><th>ภาคเรียน</th><th>ชั่วโมง</th><th>สถานะ</th><th class="text-right">จัดการ</th>'
            + '</tr></thead><tbody>';
        for (const r of j.rows) {
            const stat = r.status === 'active'
                ? '<span class="sch-status-badge bg-emerald-50 text-emerald-700">ใช้งาน</span>'
                : '<span class="sch-status-badge bg-slate-100 text-slate-500">ระงับ</span>';
            const hoursDisp = r.max_hours > 0
                ? `${r.hours_total.toFixed(1)} / ${r.max_hours}`
                : `${r.hours_total.toFixed(1)}`;
            html += `<tr>
                <td><p class="font-black">${escHtml(r.full_name)}</p></td>
                <td class="font-mono text-xs">${escHtml(r.student_code || '-')}</td>
                <td class="text-xs">${escHtml(r.faculty || '-')}</td>
                <td class="text-xs">${escHtml(r.semester || '-')}</td>
                <td class="text-xs font-bold">${hoursDisp}</td>
                <td>${stat}</td>
                <td class="text-right">
                    <button class="sch-btn sch-btn--xs sch-btn--ghost" title="ปรับชั่วโมง" onclick='openAdjustModal(${r.id}, ${JSON.stringify(r.full_name).replaceAll("'","&#39;")}, ${parseFloat(r.hours_total)})'><i class="fa-solid fa-sliders"></i></button>
                    <button class="sch-btn sch-btn--xs sch-btn--ghost" title="แก้ไข" onclick='editStudent(${JSON.stringify(r).replaceAll("'","&#39;")})'><i class="fa-solid fa-pen"></i></button>
                    <button class="sch-btn sch-btn--xs sch-btn--danger" title="ลบ" onclick="deleteStudent(${r.id})"><i class="fa-solid fa-trash"></i></button>
                </td>
            </tr>`;
        }
        html += '</tbody></table>';
        if (j.pagination) html += renderPagination(j.pagination, 'loadStudents');
        wrap.innerHTML = html;
    }
    window.loadStudents = loadStudents;

    window.openStudentModal = function() {
        document.getElementById('student-modal-title').textContent = 'เพิ่มนักศึกษาทุน';
        ['stu-id','stu-user-id','stu-user-search','stu-code','stu-faculty','stu-type','stu-semester','stu-max-hours','stu-notes'].forEach(id => document.getElementById(id).value = '');
        document.getElementById('stu-status').value = 'active';
        document.getElementById('stu-user-selected').classList.add('hidden');
        showModal('student-modal');
    };

    window.editStudent = function(r) {
        document.getElementById('student-modal-title').textContent = 'แก้ไขนักศึกษาทุน';
        document.getElementById('stu-id').value = r.id;
        document.getElementById('stu-user-id').value = r.user_id;
        document.getElementById('stu-user-selected').textContent = '✓ ' + r.full_name;
        document.getElementById('stu-user-selected').classList.remove('hidden');
        document.getElementById('stu-user-search').value = '';
        document.getElementById('stu-code').value = r.student_code || '';
        document.getElementById('stu-faculty').value = r.faculty || '';
        document.getElementById('stu-type').value = r.scholarship_type || '';
        document.getElementById('stu-semester').value = r.semester || '';
        document.getElementById('stu-max-hours').value = r.max_hours || '';
        document.getElementById('stu-notes').value = r.notes || '';
        document.getElementById('stu-status').value = r.status;
        showModal('student-modal');
    };

    window.saveStudent = async function() {
        const id = document.getElementById('stu-id').value;
        const userId = document.getElementById('stu-user-id').value;
        if (!userId) { Swal.fire('กรุณาเลือก User Account', '', 'warning'); return; }
        const data = {
            id,
            user_id: userId,
            student_code: document.getElementById('stu-code').value,
            faculty: document.getElementById('stu-faculty').value,
            scholarship_type: document.getElementById('stu-type').value,
            semester: document.getElementById('stu-semester').value,
            max_hours: document.getElementById('stu-max-hours').value || 0,
            notes: document.getElementById('stu-notes').value,
            status: document.getElementById('stu-status').value,
        };
        const j = await api('students', id ? 'update' : 'create', data);
        if (j.ok) {
            closeModal('student-modal');
            Swal.fire({ icon: 'success', title: 'บันทึกแล้ว', timer: 1200, showConfirmButton: false });
            loadStudents();
        } else Swal.fire('ไม่สำเร็จ', j.error || '', 'error');
    };

    window.deleteStudent = async function(id) {
        const r = await Swal.fire({
            title: 'ลบนักศึกษาทุน?', text: 'log การเข้า-ออกงานจะยังคงอยู่',
            icon: 'warning', showCancelButton: true, confirmButtonText: 'ลบ', cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#f43f5e',
        });
        if (!r.isConfirmed) return;
        const j = await api('students', 'delete', { id });
        if (j.ok) { loadStudents(); Swal.fire({ icon: 'success', title: 'ลบแล้ว', timer: 1000, showConfirmButton: false }); }
        else Swal.fire('ไม่สำเร็จ', j.error || '', 'error');
    };

    document.getElementById('stu-search').addEventListener('input', debounce(() => loadStudents(1), 250));
    document.getElementById('stu-filter-status').addEventListener('change', () => loadStudents(1));

    // User search autocomplete
    document.getElementById('stu-user-search').addEventListener('input', debounce(async (e) => {
        const q = e.target.value.trim();
        const box = document.getElementById('stu-user-suggest');
        if (q.length < 2) { box.classList.add('hidden'); return; }
        const j = await api('students', 'search_users', { q });
        if (!j.ok || j.rows.length === 0) { box.innerHTML = '<p class="p-2 text-xs text-slate-400">ไม่พบ</p>'; box.classList.remove('hidden'); return; }
        // เก็บ row ไว้ใน cache เพื่อให้ pickUser ดึงข้อมูลมาเติมฟอร์มได้ครบ
        window.__userPickCache = {};
        box.innerHTML = j.rows.map(u => {
            window.__userPickCache[u.id] = u;
            return `<button type="button" class="block w-full text-left px-3 py-2 hover:bg-slate-50 border-b border-slate-100 last:border-0" onclick="pickUser(${u.id})">
                <span class="text-sm font-bold">${escHtml(u.full_name)}</span>
                <span class="text-xs text-slate-500 block">${escHtml(u.phone || '')} ${escHtml(u.student_personnel_id || '')}</span>
            </button>`;
        }).join('');
        box.classList.remove('hidden');
    }, 300));
    window.pickUser = function(id) {
        const u = (window.__userPickCache || {})[id];
        if (!u) return;
        document.getElementById('stu-user-id').value = id;
        document.getElementById('stu-user-search').value = '';
        document.getElementById('stu-user-suggest').classList.add('hidden');
        const sel = document.getElementById('stu-user-selected');
        sel.textContent = '✓ ' + u.full_name;
        sel.classList.remove('hidden');

        // Auto-fill ถ้าฟิลด์ในฟอร์มยังว่างอยู่ (ไม่ทับสิ่งที่ admin พิมพ์ไว้)
        const codeEl = document.getElementById('stu-code');
        if (!codeEl.value.trim() && u.student_personnel_id) codeEl.value = u.student_personnel_id;
        const facEl = document.getElementById('stu-faculty');
        if (!facEl.value.trim() && u.department) facEl.value = u.department;
    };

    // ────── SHIFTS ──────
    async function loadShifts(page = 1) {
        const wrap = document.getElementById('shift-table-wrap');
        wrap.innerHTML = '<p class="text-center text-sm text-slate-400 py-10"><i class="fa-solid fa-spinner fa-spin mr-2"></i>กำลังโหลด…</p>';
        const data = {
            student_id: document.getElementById('shift-filter-student').value,
            from: document.getElementById('shift-from').value,
            to: document.getElementById('shift-to').value,
            page,
        };
        const j = await api('shifts', 'list', data);
        if (!j.ok) { wrap.innerHTML = `<p class="text-center text-rose-500 py-6">${j.error || ''}</p>`; return; }
        if (j.rows.length === 0) {
            wrap.innerHTML = '<p class="text-center text-slate-400 py-10"><i class="fa-solid fa-calendar-xmark text-2xl block mb-2"></i>ไม่มีกะในช่วงนี้</p>';
            return;
        }
        let html = '<table class="sch-table"><thead><tr>'
            + '<th>วันที่</th><th>นักศึกษา</th><th>เวลา</th><th>ชั่วโมง</th><th>ประเภท</th><th>สถานะ</th><th class="text-right">จัดการ</th>'
            + '</tr></thead><tbody>';
        for (const r of j.rows) {
            const stat = ({
                scheduled: '<span class="sch-status-badge bg-sky-50 text-sky-700">นัดแล้ว</span>',
                completed: '<span class="sch-status-badge bg-emerald-50 text-emerald-700">เสร็จสิ้น</span>',
                cancelled: '<span class="sch-status-badge bg-slate-100 text-slate-500">ยกเลิก</span>',
            })[r.status] || r.status;
            const ct = (r.comp_type || 'hours') === 'paid'
                ? '<span class="sch-status-badge bg-amber-50 text-amber-700"><i class="fa-solid fa-coins mr-1"></i>ค่าตอบแทน</span>'
                : '<span class="sch-status-badge bg-emerald-50 text-emerald-700"><i class="fa-solid fa-graduation-cap mr-1"></i>ทุน</span>';
            html += `<tr>
                <td class="font-bold">${escHtml(r.shift_date)}</td>
                <td>${escHtml(r.student_name)}</td>
                <td class="font-mono text-xs">${escHtml(r.start_time.substr(0,5))} – ${escHtml(r.end_time.substr(0,5))}</td>
                <td class="text-xs">${parseFloat(r.planned_hours).toFixed(1)}</td>
                <td>${ct}</td>
                <td>${stat}</td>
                <td class="text-right">
                    <button class="sch-btn sch-btn--xs sch-btn--ghost" onclick='editShift(${JSON.stringify(r).replaceAll("'","&#39;")})'><i class="fa-solid fa-pen"></i></button>
                </td>
            </tr>`;
        }
        html += '</tbody></table>';
        if (j.pagination) html += renderPagination(j.pagination, 'loadShifts');
        wrap.innerHTML = html;
    }
    window.loadShifts = loadShifts;

    function syncShiftCtRadio() {
        const v = document.querySelector('input[name="shift-ct"]:checked').value;
        document.getElementById('shift-ct-hours-lbl').style.boxShadow = v === 'hours' ? '0 0 0 4px rgba(16,185,129,.25)' : '';
        document.getElementById('shift-ct-paid-lbl').style.boxShadow = v === 'paid' ? '0 0 0 4px rgba(245,158,11,.25)' : '';
    }
    document.getElementById('shift-ct-hours-lbl').addEventListener('click', () => {
        document.querySelector('input[name="shift-ct"][value="hours"]').checked = true; syncShiftCtRadio();
    });
    document.getElementById('shift-ct-paid-lbl').addEventListener('click', () => {
        document.querySelector('input[name="shift-ct"][value="paid"]').checked = true; syncShiftCtRadio();
    });

    function setShiftCt(val) {
        document.querySelector(`input[name="shift-ct"][value="${val || 'hours'}"]`).checked = true;
        syncShiftCtRadio();
    }

    window.openShiftModal = function() {
        document.getElementById('shift-modal-title').textContent = 'เพิ่มกะ';
        ['shift-id','shift-date','shift-start','shift-end','shift-notes'].forEach(id => document.getElementById(id).value = '');
        setShiftCt('hours');
        const sel = document.getElementById('shift-student');
        sel.innerHTML = studentsCache.filter(s => s.status === 'active')
            .map(s => `<option value="${s.id}">${escHtml(s.full_name)}</option>`).join('');
        document.getElementById('shift-delete-btn').style.display = 'none';
        showModal('shift-modal');
    };

    window.editShift = function(r) {
        document.getElementById('shift-modal-title').textContent = 'แก้ไขกะ';
        document.getElementById('shift-id').value = r.id;
        const sel = document.getElementById('shift-student');
        sel.innerHTML = studentsCache.map(s => `<option value="${s.id}" ${s.id == r.student_id ? 'selected' : ''}>${escHtml(s.full_name)}</option>`).join('');
        document.getElementById('shift-date').value = r.shift_date;
        document.getElementById('shift-start').value = r.start_time.substr(0,5);
        document.getElementById('shift-end').value = r.end_time.substr(0,5);
        document.getElementById('shift-notes').value = r.notes || '';
        setShiftCt(r.comp_type || 'hours');
        document.getElementById('shift-delete-btn').style.display = 'inline-flex';
        showModal('shift-modal');
    };

    window.saveShift = async function() {
        const id = document.getElementById('shift-id').value;
        const data = {
            id,
            student_id: document.getElementById('shift-student').value,
            shift_date: document.getElementById('shift-date').value,
            start_time: document.getElementById('shift-start').value,
            end_time: document.getElementById('shift-end').value,
            notes: document.getElementById('shift-notes').value,
            comp_type: document.querySelector('input[name="shift-ct"]:checked').value,
        };
        if (!data.student_id || !data.shift_date || !data.start_time || !data.end_time) {
            Swal.fire('กรอกข้อมูลให้ครบ', '', 'warning'); return;
        }
        const j = await api('shifts', id ? 'update' : 'create', data);
        if (j.ok) { closeModal('shift-modal'); Swal.fire({ icon:'success', title:'บันทึกแล้ว', timer:1000, showConfirmButton:false }); loadShifts(); }
        else Swal.fire('ไม่สำเร็จ', j.error || '', 'error');
    };

    window.deleteShift = async function() {
        const id = document.getElementById('shift-id').value;
        const r = await Swal.fire({ title:'ลบกะนี้?', icon:'warning', showCancelButton:true, confirmButtonText:'ลบ', confirmButtonColor:'#f43f5e' });
        if (!r.isConfirmed) return;
        const j = await api('shifts', 'delete', { id });
        if (j.ok) { closeModal('shift-modal'); loadShifts(); }
        else Swal.fire('ไม่สำเร็จ', j.error || '', 'error');
    };

    // ────── MANUAL ADJUSTMENT ──────
    function syncAdjCtRadio() {
        const v = document.querySelector('input[name="adj-ct"]:checked').value;
        document.getElementById('adj-ct-hours-lbl').style.boxShadow = v === 'hours' ? '0 0 0 4px rgba(16,185,129,.25)' : '';
        document.getElementById('adj-ct-paid-lbl').style.boxShadow = v === 'paid' ? '0 0 0 4px rgba(245,158,11,.25)' : '';
    }
    document.getElementById('adj-ct-hours-lbl').addEventListener('click', () => {
        document.querySelector('input[name="adj-ct"][value="hours"]').checked = true; syncAdjCtRadio();
    });
    document.getElementById('adj-ct-paid-lbl').addEventListener('click', () => {
        document.querySelector('input[name="adj-ct"][value="paid"]').checked = true; syncAdjCtRadio();
    });

    window.openAdjustModal = async function(studentId, studentName, currentHours) {
        document.getElementById('adj-student-id').value = studentId;
        document.getElementById('adj-student-name').textContent = studentName;
        document.getElementById('adj-current-hours').textContent = parseFloat(currentHours).toFixed(1);
        document.getElementById('adj-delta').value = '';
        document.getElementById('adj-reason').value = '';
        document.getElementById('adj-date').value = new Date().toISOString().slice(0, 10);
        document.querySelector('input[name="adj-ct"][value="hours"]').checked = true;
        syncAdjCtRadio();
        showModal('adjust-modal');
        await loadAdjustments(studentId);
    };

    async function loadAdjustments(studentId) {
        const wrap = document.getElementById('adj-history-wrap');
        wrap.innerHTML = '<p class="text-center text-xs text-slate-400 py-3"><i class="fa-solid fa-spinner fa-spin mr-1"></i>กำลังโหลด…</p>';
        const j = await api('adjustments', 'list', { student_id: studentId });
        if (!j.ok) { wrap.innerHTML = `<p class="text-center text-rose-500 py-3 text-xs">${j.error || ''}</p>`; return; }
        if (j.rows.length === 0) {
            wrap.innerHTML = '<p class="text-center text-xs text-slate-400 py-3">ยังไม่มีประวัติการปรับ</p>';
            return;
        }
        let html = '<div class="space-y-2">';
        for (const r of j.rows) {
            const delta = parseFloat(r.hours_delta);
            const isPos = delta > 0;
            const ctLabel = r.comp_type === 'paid' ? 'ค่าตอบแทน' : 'ทุน';
            const ctColor = r.comp_type === 'paid' ? 'text-amber-600' : 'text-emerald-600';
            html += `<div class="flex items-center gap-2 p-2 rounded-lg bg-slate-50 text-xs">
                <span class="font-black ${isPos ? 'text-emerald-600' : 'text-rose-600'}" style="min-width:60px">
                    ${isPos ? '+' : ''}${delta.toFixed(2)} ชม.
                </span>
                <span class="${ctColor} font-bold" style="min-width:75px">[${ctLabel}]</span>
                <span class="flex-1 text-slate-600 truncate">${escHtml(r.reason)}</span>
                <span class="text-slate-400">${escHtml(r.adjusted_date)}</span>
                <button class="text-rose-500 hover:bg-rose-50 w-7 h-7 rounded-lg" onclick="deleteAdjustment(${r.id}, ${r.student_id})" title="ลบ">
                    <i class="fa-solid fa-trash text-[11px]"></i>
                </button>
            </div>`;
        }
        html += '</div>';
        wrap.innerHTML = html;
    }

    window.saveAdjustment = async function() {
        const studentId = document.getElementById('adj-student-id').value;
        const data = {
            student_id: studentId,
            comp_type: document.querySelector('input[name="adj-ct"]:checked').value,
            hours_delta: document.getElementById('adj-delta').value,
            adjusted_date: document.getElementById('adj-date').value,
            reason: document.getElementById('adj-reason').value,
        };
        if (!data.hours_delta || parseFloat(data.hours_delta) === 0) {
            Swal.fire('กรุณาใส่จำนวนชั่วโมง (ไม่เป็น 0)', '', 'warning'); return;
        }
        if (!data.reason.trim()) {
            Swal.fire('กรุณาระบุเหตุผล', '', 'warning'); return;
        }
        const j = await api('adjustments', 'create', data);
        if (j.ok) {
            Swal.fire({ icon: 'success', title: 'บันทึกแล้ว', timer: 1000, showConfirmButton: false });
            document.getElementById('adj-delta').value = '';
            document.getElementById('adj-reason').value = '';
            await loadAdjustments(studentId);
            loadStudents(); // refresh ตารางหลักให้ชั่วโมงสะสมอัพเดท
        } else {
            Swal.fire('ไม่สำเร็จ', j.error || '', 'error');
        }
    };

    window.deleteAdjustment = async function(id, studentId) {
        const r = await Swal.fire({
            title: 'ลบรายการนี้?',
            text: 'ชั่วโมงสะสมจะถูกคำนวณใหม่',
            icon: 'warning',
            showCancelButton: true, confirmButtonText: 'ลบ', cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#f43f5e',
        });
        if (!r.isConfirmed) return;
        const j = await api('adjustments', 'delete', { id });
        if (j.ok) {
            await loadAdjustments(studentId);
            loadStudents();
        } else {
            Swal.fire('ไม่สำเร็จ', j.error || '', 'error');
        }
    };

    // ────── REPORTS ──────
    async function loadReports() {
        const wrap = document.getElementById('rep-table-wrap');
        wrap.innerHTML = '<p class="text-center text-sm text-slate-400 py-10"><i class="fa-solid fa-spinner fa-spin mr-2"></i>กำลังโหลด…</p>';
        const data = { from: document.getElementById('rep-from').value, to: document.getElementById('rep-to').value };
        const j = await api('reports', 'summary', data);
        if (!j.ok) { wrap.innerHTML = `<p class="text-center text-rose-500 py-6">${j.error || ''}</p>`; return; }
        if (j.rows.length === 0) {
            wrap.innerHTML = '<p class="text-center text-slate-400 py-10">ไม่มีข้อมูลในช่วงที่เลือก</p>';
            return;
        }
        let totalScholar = 0, totalPaid = 0;
        let html = '<table class="sch-table"><thead><tr>'
            + '<th>นักศึกษา</th><th>คณะ</th><th>เข้างาน</th>'
            + '<th><i class="fa-solid fa-graduation-cap text-emerald-500 mr-1"></i>ชม.ทุน</th>'
            + '<th><i class="fa-solid fa-coins text-amber-500 mr-1"></i>ชม.ค่าตอบแทน</th>'
            + '<th>รวม</th><th>เป้า (ทุน)</th><th>%</th>'
            + '</tr></thead><tbody>';
        for (const r of j.rows) {
            totalScholar += parseFloat(r.hours_scholarship);
            totalPaid += parseFloat(r.hours_paid);
            const pct = r.max_hours > 0 ? Math.round((r.hours_scholarship / r.max_hours) * 100) : null;
            html += `<tr>
                <td><p class="font-black">${escHtml(r.full_name)}</p><p class="text-xs text-slate-500">${escHtml(r.student_code || '-')}</p></td>
                <td class="text-xs">${escHtml(r.faculty || '-')}</td>
                <td>${r.checkins}</td>
                <td class="font-bold text-emerald-700">${parseFloat(r.hours_scholarship).toFixed(2)}</td>
                <td class="font-bold text-amber-700">${parseFloat(r.hours_paid).toFixed(2)}</td>
                <td class="font-black">${parseFloat(r.hours).toFixed(2)}</td>
                <td class="text-xs">${r.max_hours > 0 ? r.max_hours : '-'}</td>
                <td>${pct === null ? '-' : `<span class="font-bold ${pct >= 100 ? 'text-emerald-600' : pct >= 50 ? 'text-amber-600' : 'text-slate-500'}">${pct}%</span>`}</td>
            </tr>`;
        }
        const totalAll = totalScholar + totalPaid;
        html += `</tbody><tfoot><tr style="border-top:2px solid #e2e8f0">
            <td colspan="3" class="text-right font-black p-3">รวมทั้งหมด</td>
            <td class="font-black p-3 text-emerald-700">${totalScholar.toFixed(2)}</td>
            <td class="font-black p-3 text-amber-700">${totalPaid.toFixed(2)}</td>
            <td class="font-black p-3">${totalAll.toFixed(2)} ชม.</td>
            <td colspan="2"></td>
        </tr></tfoot></table>`;
        wrap.innerHTML = html;
    }
    window.loadReports = loadReports;

    window.exportReportCSV = function() {
        const params = new URLSearchParams({
            entity: 'reports', action: 'export_csv',
            csrf_token: PORTAL_CSRF,
            from: document.getElementById('rep-from').value,
            to: document.getElementById('rep-to').value,
        });
        window.location.href = AJAX + '?' + params.toString();
    };

    // ────── SETTINGS ──────
    function applyGpsToggleState() {
        const on = document.getElementById('set-gps-required').checked;
        const fs = document.getElementById('gps-fieldset');
        fs.style.opacity = on ? '1' : '.45';
        fs.style.pointerEvents = on ? '' : 'none';
    }
    document.getElementById('set-gps-required').addEventListener('change', applyGpsToggleState);
    applyGpsToggleState();

    window.saveSettings = async function() {
        const data = {
            clinic_lat: document.getElementById('set-lat').value,
            clinic_lng: document.getElementById('set-lng').value,
            radius_m: document.getElementById('set-radius').value,
            grace_before_min: document.getElementById('set-grace').value,
            require_approval: document.getElementById('set-require-approval').checked ? 1 : 0,
            gps_required: document.getElementById('set-gps-required').checked ? 1 : 0,
        };
        const j = await api('settings', 'save', data);
        if (j.ok) Swal.fire({ icon:'success', title:'บันทึกแล้ว', timer:1200, showConfirmButton:false });
        else Swal.fire('ไม่สำเร็จ', j.error || '', 'error');
    };

    window.useCurrentLocation = function() {
        if (!navigator.geolocation) { Swal.fire('อุปกรณ์ไม่รองรับ GPS', '', 'error'); return; }
        Swal.fire({ title:'กำลังระบุตำแหน่ง...', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });
        navigator.geolocation.getCurrentPosition(p => {
            document.getElementById('set-lat').value = p.coords.latitude.toFixed(7);
            document.getElementById('set-lng').value = p.coords.longitude.toFixed(7);
            Swal.close();
        }, err => Swal.fire('ไม่สามารถระบุตำแหน่ง', err.message, 'error'),
        { enableHighAccuracy: true, timeout: 15000 });
    };

    window.openMapPreview = function() {
        const lat = document.getElementById('set-lat').value;
        const lng = document.getElementById('set-lng').value;
        if (!lat || !lng) { Swal.fire('กรุณากรอกพิกัดก่อน', '', 'warning'); return; }
        window.open(`https://www.google.com/maps?q=${lat},${lng}`, '_blank');
    };

    // ────── Helpers ──────
    function escHtml(s) {
        if (s == null) return '';
        return String(s).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#39;');
    }
    function escAttr(s) { return escHtml(s).replaceAll("'", "\\'"); }
    function debounce(fn, ms) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; }
    function showModal(id) { document.getElementById(id).classList.add('show'); }
    window.closeModal = function(id) { document.getElementById(id).classList.remove('show'); };

    function renderPagination(pg, fnName) {
        if (pg.total_pages <= 1) return '';
        const win = 2;
        const start = Math.max(1, pg.page - win);
        const end = Math.min(pg.total_pages, pg.page + win);
        let html = `<div class="flex items-center justify-between mt-4 pt-3 border-t border-slate-100">
            <p class="text-xs text-slate-500">หน้า ${pg.page} / ${pg.total_pages} · รวม ${pg.total} รายการ</p>
            <div class="flex gap-1">`;
        const prevDis = pg.page <= 1 ? 'disabled' : '';
        const nextDis = pg.page >= pg.total_pages ? 'disabled' : '';
        html += `<button class="pg-btn" ${prevDis} onclick="${fnName}(1)">«</button>`;
        html += `<button class="pg-btn" ${prevDis} onclick="${fnName}(${pg.page - 1})">‹</button>`;
        for (let p = start; p <= end; p++) {
            html += `<button class="pg-btn ${p === pg.page ? 'active' : ''}" onclick="${fnName}(${p})">${p}</button>`;
        }
        html += `<button class="pg-btn" ${nextDis} onclick="${fnName}(${pg.page + 1})">›</button>`;
        html += `<button class="pg-btn" ${nextDis} onclick="${fnName}(${pg.total_pages})">»</button>`;
        html += `</div></div>`;
        return html;
    }

    // ── Init: load default tab (Dashboard)
    loadDashboard();
})();
</script>
