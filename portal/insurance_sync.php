<?php
// portal/insurance_sync.php — Insurance Sync Hub
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$adminRole = $_SESSION['admin_role'] ?? '';
$isStaff   = !empty($_SESSION['is_ecampaign_staff']);
if ($isStaff && $adminRole === '') {
    header('Location: index.php'); exit;
}
$allowedRoles = ['admin', 'superadmin', 'editor'];
if (!in_array($adminRole, $allowedRoles, true)) {
    header('Location: index.php'); exit;
}

$pdo = db();

// Ensure tables exist (non-fatal)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS insurance_sync_logs (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT, synced_by INT NOT NULL DEFAULT 0,
        filename VARCHAR(255) NOT NULL DEFAULT '', total_matched INT NOT NULL DEFAULT 0,
        total_inactivated INT NOT NULL DEFAULT 0, total_newcomers INT NOT NULL DEFAULT 0,
        total_active INT NOT NULL DEFAULT 0, synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        notes TEXT NULL, PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS insurance_members (
        member_id VARCHAR(20) NOT NULL, full_name VARCHAR(255) NOT NULL DEFAULT '',
        member_status VARCHAR(50) NOT NULL DEFAULT '', position VARCHAR(100) NOT NULL DEFAULT '',
        citizen_id VARCHAR(13) NOT NULL DEFAULT '', date_of_birth DATE NULL,
        insurance_status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        coverage_start DATE NULL, coverage_end DATE NULL, policy_number VARCHAR(100) NOT NULL DEFAULT '',
        remarks TEXT NULL, last_sync_id INT NULL, manually_overridden TINYINT(1) NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (member_id), INDEX idx_citizen_id (citizen_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) { /* tables may already exist */ }

// KPI stats
$kpi = ['total' => 0, 'active' => 0, 'inactive' => 0, 'manual' => 0, 'last_sync' => null];
try {
    $row = $pdo->query("SELECT COUNT(*) as total,
        SUM(insurance_status='Active') as active,
        SUM(insurance_status='Inactive') as inactive,
        SUM(manually_overridden=1) as manual
        FROM insurance_members")->fetch(PDO::FETCH_ASSOC);
    $kpi = array_merge($kpi, $row ?? []);
    $kpi['last_sync'] = $pdo->query("SELECT synced_at, filename FROM insurance_sync_logs ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* silent */ }

// Sync history
$syncHistory = [];
try {
    $syncHistory = $pdo->query("
        SELECT l.id, l.filename, l.total_matched, l.total_inactivated, l.total_newcomers,
               l.total_active, l.synced_at, a.full_name AS synced_by_name
        FROM insurance_sync_logs l
        LEFT JOIN sys_admins a ON l.synced_by = a.id
        ORDER BY l.id DESC LIMIT 30
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* silent */ }

$csrfToken = get_csrf_token();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Insurance Sync Hub — RSU Medical Clinic</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * { font-family: 'Prompt', sans-serif; }
        body { background: #f0f4ff; }
        .tab-btn { transition: all .2s; }
        .tab-btn.active { background: #0052CC; color: #fff; }
        .tab-btn:not(.active) { background: #fff; color: #64748b; border: 1.5px solid #e2e8f0; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .stat-card { background: #fff; border-radius: 16px; padding: 20px 24px; border: 1.5px solid #e2e8f0; }
        .badge-active { background: #dcfce7; color: #16a34a; padding: 2px 10px; border-radius: 20px; font-size: 12px; font-weight: 700; }
        .badge-inactive { background: #fee2e2; color: #dc2626; padding: 2px 10px; border-radius: 20px; font-size: 12px; font-weight: 700; }
        .badge-manual { background: #fef3c7; color: #d97706; padding: 2px 10px; border-radius: 20px; font-size: 12px; font-weight: 700; }
        .upload-area { border: 2px dashed #bfdbfe; background: #eff6ff; border-radius: 16px; cursor: pointer; transition: all .2s; }
        .upload-area:hover, .upload-area.drag-over { border-color: #0052CC; background: #dbeafe; }
        .progress-bar { height: 8px; background: #e2e8f0; border-radius: 99px; overflow: hidden; }
        .progress-fill { height: 100%; background: #0052CC; border-radius: 99px; transition: width .4s; }
        table { border-collapse: collapse; width: 100%; }
        th { background: #f8faff; font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .06em; padding: 10px 12px; border-bottom: 1.5px solid #e2e8f0; }
        td { padding: 10px 12px; font-size: 13px; border-bottom: 1px solid #f1f5f9; color: #374151; }
        tr:hover td { background: #f8faff; }
    </style>
</head>
<body class="pb-20">

<!-- Top bar -->
<div class="bg-white border-b border-gray-100 px-6 py-4 flex items-center justify-between sticky top-0 z-40 shadow-sm">
    <div class="flex items-center gap-3">
        <a href="index.php" class="text-gray-400 hover:text-gray-600 transition-colors">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div class="w-9 h-9 bg-[#0052CC] rounded-xl flex items-center justify-center text-white text-sm">
            <i class="fa-solid fa-shield-halved"></i>
        </div>
        <div>
            <h1 class="text-lg font-black text-gray-900 leading-none">Insurance Sync Hub</h1>
            <p class="text-xs text-gray-400 mt-0.5">ศูนย์กลางอัปเดตสิทธิ์ประกัน</p>
        </div>
    </div>
    <div class="flex items-center gap-2">
        <?php if ($kpi['last_sync']): ?>
        <span class="text-xs text-gray-400 hidden sm:block">
            ซิงค์ล่าสุด: <?= date('d/m/Y H:i', strtotime($kpi['last_sync']['synced_at'])) ?>
        </span>
        <?php endif; ?>
        <a href="ajax_insurance_export.php?type=active" class="bg-green-50 text-green-700 px-4 py-2 rounded-xl text-xs font-bold hover:bg-green-100 transition-colors">
            <i class="fa-solid fa-file-arrow-down mr-1"></i>Export Active
        </a>
    </div>
</div>

<div class="max-w-5xl mx-auto px-4 py-6">

    <!-- KPI Row -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
        <div class="stat-card">
            <div class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">สมาชิกทั้งหมด</div>
            <div class="text-3xl font-black text-gray-900"><?= number_format((int)$kpi['total']) ?></div>
        </div>
        <div class="stat-card">
            <div class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">มีสิทธิ์ (Active)</div>
            <div class="text-3xl font-black text-green-600"><?= number_format((int)$kpi['active']) ?></div>
        </div>
        <div class="stat-card">
            <div class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">หมดสิทธิ์ (Inactive)</div>
            <div class="text-3xl font-black text-red-500"><?= number_format((int)$kpi['inactive']) ?></div>
        </div>
        <div class="stat-card">
            <div class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">Override ด้วยมือ</div>
            <div class="text-3xl font-black text-amber-500"><?= number_format((int)$kpi['manual']) ?></div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="flex gap-2 mb-5">
        <button class="tab-btn active px-5 py-2.5 rounded-xl text-sm font-bold" onclick="switchTab('sync')">
            <i class="fa-solid fa-rotate mr-1.5"></i>ซิงค์ข้อมูล
        </button>
        <button class="tab-btn px-5 py-2.5 rounded-xl text-sm font-bold" onclick="switchTab('history')">
            <i class="fa-solid fa-clock-rotate-left mr-1.5"></i>ประวัติ
        </button>
        <button class="tab-btn px-5 py-2.5 rounded-xl text-sm font-bold" onclick="switchTab('members')">
            <i class="fa-solid fa-users mr-1.5"></i>สมาชิก
        </button>
    </div>

    <!-- ─────────────────────── TAB: SYNC ─────────────────────────────── -->
    <div id="tab-sync" class="tab-content active">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <h2 class="text-base font-bold text-gray-800 mb-1">อัปโหลด CSV จากสำนักทะเบียน</h2>
            <p class="text-sm text-gray-400 mb-5">รองรับ UTF-8 และ TIS-620 / Windows-874 (ไทย) — ระบบจะแปลงให้อัตโนมัติ</p>

            <!-- CSV Format hint -->
            <div class="bg-blue-50 border border-blue-100 rounded-xl p-4 mb-5 text-xs text-blue-700">
                <div class="font-bold mb-1"><i class="fa-solid fa-circle-info mr-1"></i>รูปแบบ CSV ที่รองรับ</div>
                <code class="text-blue-800">member_id, full_name, member_status, position, citizen_id, date_of_birth, coverage_start, coverage_end, policy_number, remarks</code>
                <div class="mt-1 text-blue-500">* คอลัมน์ที่จำเป็น: <strong>member_id</strong> และ <strong>full_name</strong></div>
            </div>

            <!-- Upload area -->
            <div class="upload-area text-center py-10 px-6 mb-5" id="uploadArea" onclick="document.getElementById('csvFile').click()">
                <i class="fa-solid fa-cloud-arrow-up text-3xl text-blue-300 mb-3 block"></i>
                <p class="text-sm font-bold text-gray-600">คลิกหรือลากไฟล์ CSV มาวางที่นี่</p>
                <p class="text-xs text-gray-400 mt-1" id="fileNameLabel">ยังไม่ได้เลือกไฟล์</p>
            </div>
            <input type="file" id="csvFile" accept=".csv" class="hidden">

            <button id="btnDryRun" onclick="doDryRun()" disabled
                class="w-full bg-[#0052CC] hover:bg-blue-700 disabled:opacity-40 disabled:cursor-not-allowed text-white font-bold py-3 rounded-xl transition-all flex items-center justify-center gap-2">
                <i class="fa-solid fa-magnifying-glass"></i> ตรวจสอบ (Dry Run)
            </button>
        </div>

        <!-- Dry Run Result -->
        <div id="dryRunResult" class="hidden mt-5">
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <h3 class="text-base font-bold text-gray-800 mb-4"><i class="fa-solid fa-list-check mr-2 text-blue-500"></i>ผลการตรวจสอบ (Preview)</h3>

                <!-- Guard warning -->
                <div id="guardWarning" class="hidden bg-red-50 border border-red-200 rounded-xl p-4 mb-4">
                    <div class="font-bold text-red-700 mb-1"><i class="fa-solid fa-triangle-exclamation mr-1"></i>คำเตือน: จะมีการ Inactivate จำนวนมาก</div>
                    <div id="guardMsg" class="text-sm text-red-600"></div>
                </div>

                <!-- Summary counts -->
                <div class="grid grid-cols-3 gap-3 mb-5" id="drySummary"></div>

                <!-- Preview tables -->
                <div id="dryTables"></div>

                <div class="flex gap-3 mt-5">
                    <button id="btnExecute" onclick="doExecute(false)"
                        class="flex-1 bg-green-600 hover:bg-green-700 text-white font-bold py-3 rounded-xl transition-all flex items-center justify-center gap-2">
                        <i class="fa-solid fa-check"></i> ยืนยันซิงค์
                    </button>
                    <button onclick="resetSync()"
                        class="px-6 bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold py-3 rounded-xl transition-all">
                        ยกเลิก
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ─────────────────────── TAB: HISTORY ─────────────────────────────── -->
    <div id="tab-history" class="tab-content">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="p-5 border-b border-gray-100 flex items-center justify-between">
                <h2 class="text-base font-bold text-gray-800">ประวัติการซิงค์</h2>
                <span class="text-xs text-gray-400"><?= count($syncHistory) ?> รายการล่าสุด</span>
            </div>
            <?php if (empty($syncHistory)): ?>
            <div class="text-center py-16 text-gray-300">
                <i class="fa-solid fa-clock-rotate-left text-4xl mb-3 block"></i>
                <p class="text-sm">ยังไม่มีประวัติการซิงค์</p>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table>
                    <thead>
                        <tr>
                            <th>ซิงค์ #</th>
                            <th>ไฟล์</th>
                            <th>Matched</th>
                            <th>Newcomers</th>
                            <th>Inactivated</th>
                            <th>Total Active</th>
                            <th>ผู้ซิงค์</th>
                            <th>วันเวลา</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($syncHistory as $log): ?>
                        <tr>
                            <td class="font-bold text-[#0052CC]">#<?= $log['id'] ?></td>
                            <td class="text-gray-500 text-xs"><?= htmlspecialchars($log['filename']) ?></td>
                            <td><span class="text-green-600 font-bold"><?= number_format($log['total_matched']) ?></span></td>
                            <td><span class="text-blue-600 font-bold"><?= number_format($log['total_newcomers']) ?></span></td>
                            <td><span class="text-red-500 font-bold"><?= number_format($log['total_inactivated']) ?></span></td>
                            <td><span class="font-bold"><?= number_format($log['total_active']) ?></span></td>
                            <td class="text-gray-500 text-xs"><?= htmlspecialchars($log['synced_by_name'] ?? '-') ?></td>
                            <td class="text-gray-500 text-xs"><?= date('d/m/Y H:i', strtotime($log['synced_at'])) ?></td>
                            <td>
                                <button onclick="viewSyncDetail(<?= $log['id'] ?>)"
                                    class="text-xs text-[#0052CC] font-bold hover:underline">รายละเอียด</button>
                                <?php if ($log['total_newcomers'] > 0): ?>
                                <a href="ajax_insurance_export.php?type=newcomers&sync_id=<?= $log['id'] ?>"
                                    class="text-xs text-green-600 font-bold hover:underline ml-2">Export</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ─────────────────────── TAB: MEMBERS ─────────────────────────────── -->
    <div id="tab-members" class="tab-content">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm">
            <div class="p-5 border-b border-gray-100 flex flex-wrap gap-3 items-center">
                <h2 class="text-base font-bold text-gray-800 mr-auto">รายชื่อสมาชิก</h2>
                <input type="text" id="memberSearch" placeholder="ค้นหารหัส/ชื่อ/เลขบัตร..."
                    class="border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-blue-400 w-52">
                <select id="memberFilter" class="border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none">
                    <option value="all">ทั้งหมด</option>
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                </select>
                <button onclick="loadMembers(1)" class="bg-[#0052CC] text-white text-sm font-bold px-4 py-2 rounded-xl">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </button>
            </div>
            <div id="membersTable" class="overflow-x-auto">
                <div class="text-center py-16 text-gray-300">
                    <i class="fa-solid fa-users text-4xl mb-3 block"></i>
                    <p class="text-sm">กดค้นหาเพื่อดูรายชื่อสมาชิก</p>
                </div>
            </div>
            <div id="membersPagination" class="p-4 flex justify-center gap-2"></div>
        </div>
    </div>

</div>

<!-- ─── Modal: Sync Detail ──────────────────────────────────────────────────── -->
<div id="detailModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 backdrop-blur-sm">
    <div class="bg-white rounded-3xl w-full max-w-3xl mx-4 max-h-[85vh] flex flex-col shadow-2xl">
        <div class="p-5 border-b border-gray-100 flex items-center justify-between">
            <h3 class="text-base font-bold text-gray-900" id="detailTitle">รายละเอียด Sync</h3>
            <button onclick="closeDetailModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fa-solid fa-xmark text-xl"></i>
            </button>
        </div>
        <div id="detailBody" class="overflow-y-auto flex-1 p-5 text-sm text-gray-600">
            <div class="text-center py-8"><i class="fa-solid fa-spinner fa-spin text-2xl text-blue-400"></i></div>
        </div>
    </div>
</div>

<!-- ─── Modal: Override ──────────────────────────────────────────────────────── -->
<div id="overrideModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 backdrop-blur-sm">
    <div class="bg-white rounded-3xl w-full max-w-md mx-4 shadow-2xl p-6">
        <h3 class="text-base font-bold text-gray-900 mb-4">แก้ไขสิทธิ์ด้วยมือ (Manual Override)</h3>
        <input type="hidden" id="overrideMemberId">
        <div class="mb-3">
            <label class="text-xs font-bold text-gray-500 mb-1 block">สมาชิก</label>
            <div id="overrideName" class="text-sm font-bold text-gray-800"></div>
        </div>
        <div class="mb-3">
            <label class="text-xs font-bold text-gray-500 mb-1 block">สถานะใหม่</label>
            <select id="overrideStatus" class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm">
                <option value="Active">Active — มีสิทธิ์</option>
                <option value="Inactive">Inactive — หมดสิทธิ์</option>
            </select>
        </div>
        <div class="mb-5">
            <label class="text-xs font-bold text-gray-500 mb-1 block">หมายเหตุ (ไม่บังคับ)</label>
            <input type="text" id="overrideNote" placeholder="เหตุผล..." class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm">
        </div>
        <div class="flex gap-3">
            <button onclick="submitOverride()" class="flex-1 bg-[#0052CC] text-white font-bold py-2.5 rounded-xl text-sm">บันทึก</button>
            <button onclick="closeOverrideModal()" class="px-5 bg-gray-100 text-gray-700 font-bold py-2.5 rounded-xl text-sm">ยกเลิก</button>
        </div>
    </div>
</div>

<script>
const CSRF = '<?= $csrfToken ?>';

// ── Tab switching ──────────────────────────────────────────────────────────
function switchTab(name) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    event.currentTarget.classList.add('active');

    if (name === 'members' && document.getElementById('membersTable').querySelector('.text-sm')) {
        loadMembers(1);
    }
}

// ── File upload ────────────────────────────────────────────────────────────
const csvFileInput = document.getElementById('csvFile');
const uploadArea   = document.getElementById('uploadArea');
const fileNameLbl  = document.getElementById('fileNameLabel');
const btnDryRun    = document.getElementById('btnDryRun');

csvFileInput.addEventListener('change', () => {
    if (csvFileInput.files[0]) {
        fileNameLbl.textContent = csvFileInput.files[0].name;
        btnDryRun.disabled = false;
    }
});

uploadArea.addEventListener('dragover', e => { e.preventDefault(); uploadArea.classList.add('drag-over'); });
uploadArea.addEventListener('dragleave', () => uploadArea.classList.remove('drag-over'));
uploadArea.addEventListener('drop', e => {
    e.preventDefault();
    uploadArea.classList.remove('drag-over');
    const file = e.dataTransfer.files[0];
    if (file) {
        const dt = new DataTransfer();
        dt.items.add(file);
        csvFileInput.files = dt.files;
        fileNameLbl.textContent = file.name;
        btnDryRun.disabled = false;
    }
});

// ── Dry Run ────────────────────────────────────────────────────────────────
let dryRunData = null;

async function doDryRun() {
    const file = csvFileInput.files[0];
    if (!file) return;

    btnDryRun.disabled = true;
    btnDryRun.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i>กำลังตรวจสอบ...';

    const fd = new FormData();
    fd.append('action', 'dryrun');
    fd.append('csrf_token', CSRF);
    fd.append('csv_file', file);

    try {
        const res  = await fetch('ajax_insurance_sync.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.status !== 'ok') {
            Swal.fire('ข้อผิดพลาด', data.message, 'error');
            btnDryRun.disabled = false;
            btnDryRun.innerHTML = '<i class="fa-solid fa-magnifying-glass mr-2"></i>ตรวจสอบ (Dry Run)';
            return;
        }

        dryRunData = data;
        renderDryRunResult(data);
        document.getElementById('dryRunResult').classList.remove('hidden');

    } catch (err) {
        Swal.fire('Error', 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์', 'error');
    }

    btnDryRun.innerHTML = '<i class="fa-solid fa-magnifying-glass mr-2"></i>ตรวจสอบ (Dry Run)';
    btnDryRun.disabled = !csvFileInput.files[0];
}

function renderDryRunResult(data) {
    // Guard warning
    const guardDiv = document.getElementById('guardWarning');
    if (data.guard_triggered) {
        document.getElementById('guardMsg').textContent =
            `จะมีการ Inactivate ${data.total_inactivated} คน (${data.guard_percent}% ของ Active ทั้งหมด) กรุณากด "บังคับยืนยัน" เพื่อดำเนินการต่อ`;
        guardDiv.classList.remove('hidden');
        document.getElementById('btnExecute').textContent = '';
        document.getElementById('btnExecute').innerHTML = '<i class="fa-solid fa-triangle-exclamation mr-2"></i>บังคับยืนยัน (Override Guard)';
        document.getElementById('btnExecute').onclick = () => doExecute(true);
        document.getElementById('btnExecute').classList.replace('bg-green-600','bg-red-600');
        document.getElementById('btnExecute').classList.replace('hover:bg-green-700','hover:bg-red-700');
    } else {
        guardDiv.classList.add('hidden');
    }

    // Summary counts
    document.getElementById('drySummary').innerHTML = `
        <div class="stat-card text-center">
            <div class="text-2xl font-black text-green-600">${data.total_matched}</div>
            <div class="text-xs text-gray-400 mt-1 font-bold">Matched (Active)</div>
        </div>
        <div class="stat-card text-center">
            <div class="text-2xl font-black text-blue-600">${data.total_newcomers}</div>
            <div class="text-xs text-gray-400 mt-1 font-bold">Newcomers</div>
        </div>
        <div class="stat-card text-center">
            <div class="text-2xl font-black text-red-500">${data.total_inactivated}</div>
            <div class="text-xs text-gray-400 mt-1 font-bold">Will Inactivate</div>
        </div>
    `;

    // Preview tables
    let html = '';

    if (data.newcomers.length > 0) {
        html += `<h4 class="text-xs font-bold text-blue-700 uppercase tracking-widest mt-4 mb-2">รายชื่อใหม่ (Newcomers) — แสดง ${data.newcomers.length} คน</h4>
        <div class="overflow-x-auto rounded-xl border border-blue-100"><table>
            <thead><tr><th>รหัส</th><th>ชื่อ-สกุล</th><th>ตำแหน่ง</th></tr></thead>
            <tbody>${data.newcomers.map(r => `<tr><td class="font-mono text-xs">${esc(r.member_id)}</td><td>${esc(r.full_name)}</td><td class="text-gray-400">${esc(r.position||'')}</td></tr>`).join('')}</tbody>
        </table></div>`;
    }

    if (data.inactivated.length > 0) {
        html += `<h4 class="text-xs font-bold text-red-600 uppercase tracking-widest mt-5 mb-2">จะถูก Inactivate — แสดง ${data.inactivated.length} คน</h4>
        <div class="overflow-x-auto rounded-xl border border-red-100"><table>
            <thead><tr><th>รหัส</th><th>ชื่อ-สกุล</th><th>หมายเหตุ</th></tr></thead>
            <tbody>${data.inactivated.map(r => `<tr><td class="font-mono text-xs">${esc(r.member_id)}</td><td>${esc(r.full_name)}</td><td class="text-xs text-gray-400">${r.manually_overridden ? '🔒 Manual Override — จะไม่เปลี่ยน' : ''}</td></tr>`).join('')}</tbody>
        </table></div>`;
    }

    document.getElementById('dryTables').innerHTML = html;
}

// ── Execute ────────────────────────────────────────────────────────────────
async function doExecute(forceOverride) {
    if (!dryRunData) return;

    const confirm = await Swal.fire({
        title: forceOverride ? 'บังคับยืนยันการซิงค์?' : 'ยืนยันการซิงค์?',
        html: `จะอัปเดตฐานข้อมูลสมาชิกทันที<br>Matched: <b>${dryRunData.total_matched}</b> | Newcomers: <b>${dryRunData.total_newcomers}</b> | Inactivated: <b>${dryRunData.total_inactivated}</b>`,
        icon: forceOverride ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonColor: forceOverride ? '#dc2626' : '#0052CC',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'ยืนยัน',
        cancelButtonText: 'ยกเลิก',
    });

    if (!confirm.isConfirmed) return;

    const fd = new FormData();
    fd.append('action', 'execute');
    fd.append('csrf_token', CSRF);
    fd.append('csv_base64', dryRunData.csv_base64);
    fd.append('filename', dryRunData.filename);
    fd.append('force_override', forceOverride ? '1' : '0');

    Swal.fire({ title: 'กำลังซิงค์...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    try {
        const res  = await fetch('ajax_insurance_sync.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.status === 'success') {
            Swal.fire({
                icon: 'success',
                title: 'ซิงค์สำเร็จ!',
                html: `Sync #${data.sync_id}<br>✅ Matched: <b>${data.total_matched}</b> | 🆕 Newcomers: <b>${data.total_newcomers}</b> | ❌ Inactivated: <b>${data.total_inactivated}</b><br>Active ทั้งหมด: <b>${data.total_active}</b> คน`,
                confirmButtonColor: '#0052CC',
            }).then(() => location.reload());
        } else if (data.status === 'guard') {
            Swal.fire('คำเตือน!', data.message, 'warning');
        } else {
            Swal.fire('ข้อผิดพลาด', data.message, 'error');
        }
    } catch (err) {
        Swal.fire('Error', 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์', 'error');
    }
}

function resetSync() {
    dryRunData = null;
    document.getElementById('dryRunResult').classList.add('hidden');
    csvFileInput.value = '';
    fileNameLbl.textContent = 'ยังไม่ได้เลือกไฟล์';
    btnDryRun.disabled = true;
}

// ── View Sync Detail ───────────────────────────────────────────────────────
async function viewSyncDetail(syncId) {
    document.getElementById('detailModal').classList.replace('hidden', 'flex');
    document.getElementById('detailTitle').textContent = `รายละเอียด Sync #${syncId}`;
    document.getElementById('detailBody').innerHTML = '<div class="text-center py-8"><i class="fa-solid fa-spinner fa-spin text-2xl text-blue-400"></i></div>';

    const fd = new FormData();
    fd.append('action', 'get_sync_detail');
    fd.append('csrf_token', CSRF);
    fd.append('sync_id', syncId);

    try {
        const res  = await fetch('ajax_insurance_sync.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.status !== 'ok') {
            document.getElementById('detailBody').innerHTML = `<p class="text-red-500">${data.message}</p>`;
            return;
        }

        const log  = data.log;
        const rows = data.rows;

        const groups = { matched: [], inactivated: [], inserted: [], manual: [] };
        rows.forEach(r => { if (groups[r.change_type]) groups[r.change_type].push(r); });

        let html = `<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
            <div class="stat-card text-center"><div class="text-xl font-black text-green-600">${log.total_matched}</div><div class="text-xs text-gray-400">Matched</div></div>
            <div class="stat-card text-center"><div class="text-xl font-black text-blue-600">${log.total_newcomers}</div><div class="text-xs text-gray-400">Newcomers</div></div>
            <div class="stat-card text-center"><div class="text-xl font-black text-red-500">${log.total_inactivated}</div><div class="text-xs text-gray-400">Inactivated</div></div>
            <div class="stat-card text-center"><div class="text-xl font-black text-gray-700">${log.total_active}</div><div class="text-xs text-gray-400">Total Active</div></div>
        </div>
        <div class="text-xs text-gray-400 mb-4">ไฟล์: ${esc(log.filename)} | ซิงค์โดย: ${esc(log.synced_by_name || '-')} | ${log.synced_at}</div>`;

        const renderGroup = (title, color, arr) => {
            if (!arr.length) return '';
            return `<h4 class="text-xs font-bold uppercase tracking-widest mt-4 mb-2" style="color:${color}">${title} (${arr.length})</h4>
            <div class="overflow-x-auto rounded-xl border border-gray-100 mb-3"><table>
                <thead><tr><th>รหัส</th><th>ชื่อ-สกุล</th><th>เปลี่ยนจาก</th><th>เป็น</th></tr></thead>
                <tbody>${arr.map(r => `<tr>
                    <td class="font-mono text-xs">${esc(r.member_id)}</td>
                    <td>${esc(r.full_name || '-')}</td>
                    <td><span class="badge-${r.old_status === 'Active' ? 'active' : 'inactive'}">${r.old_status}</span></td>
                    <td><span class="badge-${r.new_status === 'Active' ? 'active' : 'inactive'}">${r.new_status}</span></td>
                </tr>`).join('')}</tbody>
            </table></div>`;
        };

        html += renderGroup('Matched (Active)', '#16a34a', groups.matched);
        html += renderGroup('Newcomers (inserted)', '#2563eb', groups.inserted);
        html += renderGroup('Inactivated', '#dc2626', groups.inactivated);
        html += renderGroup('Manual Override', '#d97706', groups.manual);

        document.getElementById('detailBody').innerHTML = html;
    } catch (err) {
        document.getElementById('detailBody').innerHTML = '<p class="text-red-500">โหลดข้อมูลไม่ได้</p>';
    }
}

function closeDetailModal() {
    document.getElementById('detailModal').classList.replace('flex', 'hidden');
}

// ── Members Tab ────────────────────────────────────────────────────────────
let currentMembersPage = 1;

async function loadMembers(page) {
    currentMembersPage = page;
    const search = document.getElementById('memberSearch').value;
    const filter = document.getElementById('memberFilter').value;
    const tbody  = document.getElementById('membersTable');

    tbody.innerHTML = '<div class="text-center py-10"><i class="fa-solid fa-spinner fa-spin text-2xl text-blue-400"></i></div>';

    const fd = new FormData();
    fd.append('action', 'list_members');
    fd.append('csrf_token', CSRF);
    fd.append('page', page);
    fd.append('search', search);
    fd.append('filter', filter);

    try {
        const res  = await fetch('ajax_insurance_sync.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.status !== 'ok') {
            tbody.innerHTML = `<p class="text-center py-8 text-red-500">${data.message}</p>`;
            return;
        }

        if (!data.members.length) {
            tbody.innerHTML = '<div class="text-center py-16 text-gray-300"><i class="fa-solid fa-users text-4xl mb-3 block"></i><p class="text-sm">ไม่พบข้อมูล</p></div>';
            document.getElementById('membersPagination').innerHTML = '';
            return;
        }

        tbody.innerHTML = `<table>
            <thead><tr><th>รหัส</th><th>ชื่อ-สกุล</th><th>ตำแหน่ง</th><th>สถานะ</th><th>Override</th><th>อัปเดต</th><th></th></tr></thead>
            <tbody>${data.members.map(m => `<tr>
                <td class="font-mono text-xs">${esc(m.member_id)}</td>
                <td class="font-semibold">${esc(m.full_name)}</td>
                <td class="text-gray-400 text-xs">${esc(m.position||'')}</td>
                <td><span class="badge-${m.insurance_status === 'Active' ? 'active' : 'inactive'}">${m.insurance_status}</span></td>
                <td>${m.manually_overridden == 1 ? '<span class="badge-manual">Manual</span>' : '<span class="text-gray-300 text-xs">—</span>'}</td>
                <td class="text-gray-400 text-xs">${m.updated_at ? m.updated_at.substring(0,16) : ''}</td>
                <td><button onclick="openOverrideModal('${esc(m.member_id)}','${esc(m.full_name)}','${m.insurance_status}')"
                    class="text-xs text-[#0052CC] font-bold hover:underline">แก้ไข</button></td>
            </tr>`).join('')}</tbody>
        </table>`;

        // Pagination
        const totalPages = Math.ceil(data.total / data.per_page);
        let pages = '';
        for (let i = 1; i <= totalPages; i++) {
            pages += `<button onclick="loadMembers(${i})" class="px-3 py-1.5 rounded-lg text-sm font-bold ${i === page ? 'bg-[#0052CC] text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'}">${i}</button>`;
        }
        document.getElementById('membersPagination').innerHTML = pages;

    } catch (err) {
        tbody.innerHTML = '<p class="text-center py-8 text-red-500">โหลดข้อมูลไม่ได้</p>';
    }
}

// Enter key search
document.getElementById('memberSearch').addEventListener('keydown', e => { if (e.key === 'Enter') loadMembers(1); });

// ── Manual Override modal ──────────────────────────────────────────────────
function openOverrideModal(memberId, fullName, currentStatus) {
    document.getElementById('overrideMemberId').value  = memberId;
    document.getElementById('overrideName').textContent = fullName;
    document.getElementById('overrideStatus').value    = currentStatus === 'Active' ? 'Inactive' : 'Active';
    document.getElementById('overrideNote').value      = '';
    document.getElementById('overrideModal').classList.replace('hidden', 'flex');
}

function closeOverrideModal() {
    document.getElementById('overrideModal').classList.replace('flex', 'hidden');
}

async function submitOverride() {
    const mid    = document.getElementById('overrideMemberId').value;
    const status = document.getElementById('overrideStatus').value;
    const note   = document.getElementById('overrideNote').value;

    const fd = new FormData();
    fd.append('action', 'manual_override');
    fd.append('csrf_token', CSRF);
    fd.append('member_id', mid);
    fd.append('new_status', status);
    fd.append('note', note);

    try {
        const res  = await fetch('ajax_insurance_sync.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.status === 'success') {
            closeOverrideModal();
            Swal.fire({ icon: 'success', title: 'บันทึกแล้ว', text: `${mid}: ${data.old_status} → ${data.new_status}`, timer: 2000, showConfirmButton: false });
            loadMembers(currentMembersPage);
        } else {
            Swal.fire('ข้อผิดพลาด', data.message, 'error');
        }
    } catch (err) {
        Swal.fire('Error', 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์', 'error');
    }
}

// ── Helper ─────────────────────────────────────────────────────────────────
function esc(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Close modals on backdrop click
document.getElementById('detailModal').addEventListener('click', e => { if (e.target === e.currentTarget) closeDetailModal(); });
document.getElementById('overrideModal').addEventListener('click', e => { if (e.target === e.currentTarget) closeOverrideModal(); });
</script>
</body>
</html>
