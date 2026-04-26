<?php
// staff/scan.php — Campaign-specific QR scanner
session_start();

// รับ session ทั้ง 2 แบบ:
// 1. staff_logged_in  — login ผ่าน staff/login.php
// 2. admin_logged_in + is_ecampaign_staff — login ผ่าน portal
$viaStaffLogin  = !empty($_SESSION['staff_logged_in']) && $_SESSION['staff_logged_in'] === true;
$viaPortalLogin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true
               && !empty($_SESSION['is_ecampaign_staff']);

if (!$viaStaffLogin && !$viaPortalLogin) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

require_once __DIR__ . '/../config.php';

// [ISO 27001] ตรวจสอบสิทธิ์การเข้าถึงแบบ Real-time สำหรับ Staff
try {
    $p = db();
    $staffId = $_SESSION['staff_id'] ?? ($_SESSION['admin_id'] ?? 0);
    $uname   = $_SESSION['staff_username'] ?? ($_SESSION['admin_username'] ?? '');
    
    $check = $p->prepare("SELECT IFNULL(access_ecampaign, 0) as access FROM sys_staff WHERE (id = ? OR username = ?) AND account_status = 'active' LIMIT 1");
    $check->execute([$staffId, $uname]);
    $row = $check->fetch();
    
    if (!$row || (int)$row['access'] === 0) {
        // ถูกถอนสิทธิ์ หรือไม่มีสิทธิ์เข้าถึง e-Campaign
        session_destroy();
        header('Location: login.php?error=access_denied');
        exit;
    }
} catch (Exception $e) { /* fallback to current session */ }

$pdo = db();

// ── ดึงรายการแคมเปญที่ active พร้อม stats ──────────────────────────────────
try {
    $campaigns = $pdo->query("
        SELECT
            c.id, c.title, c.type, c.total_capacity,
            COUNT(DISTINCT b.id)                                        AS total_booked,
            SUM(b.attended_at IS NOT NULL)                              AS attended,
            SUM(b.status = 'confirmed' AND b.attended_at IS NULL)      AS waiting,
            MIN(CASE WHEN s.slot_date >= CURDATE() THEN s.slot_date END) AS next_slot_date
        FROM camp_list c
        LEFT JOIN camp_bookings b ON b.campaign_id = c.id
            AND b.status IN ('confirmed','booked')
        LEFT JOIN camp_slots s ON s.campaign_id = c.id
        WHERE c.status = 'active'
        GROUP BY c.id
        ORDER BY next_slot_date ASC, c.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $campaigns = [];
}

// campaign_id จาก URL (กรณีกดจาก admin โดยตรง)
$preselect = (int)($_GET['campaign_id'] ?? 0);
$csrf = get_csrf_token();

$typeLabel = ['vaccine' => 'วัคซีน', 'training' => 'อบรม', 'health_check' => 'ตรวจสุขภาพ', 'other' => 'อื่นๆ'];
$typeColor = ['vaccine' => '#0052CC', 'training' => '#6366f1', 'health_check' => '#059669', 'other' => '#6b7280'];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campaign Scanner — Staff</title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * { font-family: 'Prompt', sans-serif; }
        body { background: #f0f4ff; min-height: 100vh; }

        /* scanner lib overrides */
        #qr-reader__dashboard_section_csr span { display: none !important; }
        #qr-reader button {
            background: #0052CC !important; color: #fff !important;
            border: none !important; padding: 8px 18px !important;
            border-radius: 10px !important; font-family: 'Prompt', sans-serif !important;
            margin-top: 10px !important; cursor: pointer !important;
        }
        #qr-reader a { display: none !important; }

        /* pulse ring on scanner */
        .scanner-ring {
            box-shadow: 0 0 0 0 rgba(0,82,204,.4);
            animation: ringPulse 2s infinite;
        }
        @keyframes ringPulse {
            0%   { box-shadow: 0 0 0 0 rgba(0,82,204,.4); }
            70%  { box-shadow: 0 0 0 14px rgba(0,82,204,0); }
            100% { box-shadow: 0 0 0 0 rgba(0,82,204,0); }
        }

        /* scan result flash */
        @keyframes flashSuccess { 0%,100%{background:#f0fdf4} 50%{background:#bbf7d0} }
        .flash-success { animation: flashSuccess .6s ease; }
        @keyframes flashError { 0%,100%{background:#fff7f7} 50%{background:#fecaca} }
        .flash-error { animation: flashError .6s ease; }

        /* slide up for panel */
        @keyframes slideUp { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} }
        .slide-up { animation: slideUp .3s ease; }

        .log-item { transition: background .3s; }
    </style>
</head>
<body class="pb-20">

<!-- ── Header ────────────────────────────────────────────────────────────── -->
<div class="bg-[#0052CC] text-white px-5 py-4 flex items-center justify-between sticky top-0 z-50 shadow-lg">
    <div class="flex items-center gap-3">
        <div class="w-9 h-9 bg-white/20 rounded-xl flex items-center justify-center">
            <i class="fa-solid fa-qrcode text-white"></i>
        </div>
        <div>
            <p class="font-bold text-sm leading-tight">Campaign Scanner</p>
            <p id="headerCampaignName" class="text-[10px] text-blue-200 leading-tight">เลือกแคมเปญก่อน</p>
        </div>
    </div>
    <div class="flex items-center gap-2">
        <button id="btnChangeCampaign" onclick="showPickerView()"
            class="hidden text-xs bg-white/20 hover:bg-white/30 px-3 py-1.5 rounded-lg font-semibold transition-colors">
            <i class="fa-solid fa-arrows-rotate mr-1"></i>เปลี่ยน
        </button>
        <?php if (!empty($_SESSION['staff_name'])): ?>
        <span class="hidden sm:block text-xs text-blue-200 font-semibold">
            <i class="fa-solid fa-user-tie mr-1"></i><?= htmlspecialchars($_SESSION['staff_name']) ?>
        </span>
        <?php endif; ?>
        <a href="logout.php" class="text-xs bg-white/20 hover:bg-white/30 px-3 py-1.5 rounded-lg font-semibold transition-colors">
            <i class="fa-solid fa-right-from-bracket mr-1"></i>ออก
        </a>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     VIEW 1: Campaign Picker
     ══════════════════════════════════════════════════════════════════════ -->
<div id="pickerView" class="max-w-xl mx-auto p-4">
    <div class="mb-5 mt-2">
        <h2 class="text-xl font-black text-gray-900">เลือกแคมเปญ</h2>
        <p class="text-sm text-gray-500">เลือกแคมเปญที่ต้องการสแกนเช็คอิน</p>
    </div>

    <?php if (empty($campaigns)): ?>
    <div class="bg-white rounded-2xl p-10 text-center text-gray-400 border border-gray-100 shadow-sm">
        <i class="fa-solid fa-circle-exclamation text-3xl mb-3 block"></i>
        <p class="font-semibold">ไม่มีแคมเปญที่เปิดอยู่</p>
    </div>
    <?php else: ?>
    <div class="space-y-3" id="campaignList">
        <?php foreach ($campaigns as $c):
            $color   = $typeColor[$c['type']] ?? '#6b7280';
            $label   = $typeLabel[$c['type']] ?? $c['type'];
            $pct     = $c['total_booked'] > 0 ? round(($c['attended'] / max($c['total_booked'],1)) * 100) : 0;
            $nextDay = $c['next_slot_date'] ? date('d M', strtotime($c['next_slot_date'])) : '—';
        ?>
        <button onclick="selectCampaign(<?= $c['id'] ?>, <?= htmlspecialchars(json_encode($c['title'])) ?>)"
            class="w-full text-left bg-white rounded-2xl p-4 border border-gray-100 shadow-sm
                   hover:border-blue-300 hover:shadow-md active:scale-[.98] transition-all campaign-card">
            <div class="flex items-start gap-3">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 text-white text-sm font-bold"
                    style="background:<?= $color ?>">
                    <?= mb_strtoupper(mb_substr($c['title'],0,1)) ?>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-bold text-gray-900 text-sm leading-tight truncate"><?= htmlspecialchars($c['title']) ?></p>
                    <div class="flex items-center gap-2 mt-1">
                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full text-white" style="background:<?= $color ?>">
                            <?= $label ?>
                        </span>
                        <span class="text-[10px] text-gray-400">
                            <i class="fa-regular fa-calendar mr-0.5"></i><?= $nextDay ?>
                        </span>
                    </div>
                    <!-- Progress bar -->
                    <div class="mt-2.5">
                        <div class="flex justify-between text-[10px] text-gray-500 mb-1">
                            <span>เช็คอินแล้ว <b class="text-gray-700"><?= (int)$c['attended'] ?></b> คน</span>
                            <span>รอ <b class="text-orange-500"><?= (int)$c['waiting'] ?></b> คน</span>
                        </div>
                        <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                            <div class="h-full rounded-full transition-all" style="width:<?= $pct ?>%;background:<?= $color ?>"></div>
                        </div>
                    </div>
                </div>
                <i class="fa-solid fa-chevron-right text-gray-300 mt-1 shrink-0"></i>
            </div>
        </button>
        <?php endforeach; ?>
    </div>

    <!-- Search -->
    <div class="mt-3">
        <input type="text" id="campaignSearch" placeholder="ค้นหาแคมเปญ..."
            oninput="filterCampaigns(this.value)"
            class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100">
    </div>
    <?php endif; ?>

    <div class="mt-5 text-center">
        <a href="index.php" class="text-xs text-gray-400 hover:text-gray-600 underline underline-offset-2">
            สแกนแบบทั่วไป (ไม่ระบุแคมเปญ)
        </a>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     VIEW 2: Scanner (hidden by default)
     ══════════════════════════════════════════════════════════════════════ -->
<div id="scannerView" class="hidden max-w-xl mx-auto p-4 slide-up">

    <!-- Campaign Info Card -->
    <div id="campaignInfoCard" class="bg-white rounded-2xl p-4 border border-gray-100 shadow-sm mb-4">
        <div class="flex items-center gap-3">
            <div id="campaignBadge" class="w-10 h-10 rounded-xl flex items-center justify-center text-white font-bold text-sm bg-[#0052CC]">?</div>
            <div class="flex-1 min-w-0">
                <p id="campaignTitle" class="font-bold text-gray-900 text-sm truncate">—</p>
                <p class="text-[11px] text-gray-400 mt-0.5">รหัสแคมเปญ: <span id="campaignIdDisplay">—</span></p>
            </div>
            <div class="text-right shrink-0">
                <p class="text-2xl font-black text-[#0052CC]" id="attendedCount">0</p>
                <p class="text-[10px] text-gray-400 font-semibold">เช็คอินแล้ว</p>
            </div>
        </div>
        <!-- Today's slot info -->
        <div id="slotInfo" class="mt-3 pt-3 border-t border-gray-100 text-xs text-gray-500 hidden">
            <i class="fa-regular fa-clock mr-1"></i>
            <span id="slotText">—</span>
        </div>
    </div>

    <!-- Scanner Box -->
    <div class="bg-white rounded-3xl shadow-lg border border-gray-100 overflow-hidden mb-4">
        <div class="p-4">
            <div class="flex justify-end mb-3">
                <button id="btnToggleCam" class="text-[10px] font-bold text-white bg-emerald-600 px-3 py-1.5 rounded-lg hover:bg-emerald-700 transition-colors">
                    <i class="fa-solid fa-video mr-1"></i>ปิดกล้อง
                </button>
            </div>
            <div id="qr-reader" class="w-full rounded-2xl overflow-hidden scanner-ring"></div>
            <div class="mt-4 text-center">
                <p id="scan-status" class="text-sm font-bold text-[#0052CC] animate-pulse">กำลังเปิดกล้อง...</p>
            </div>
        </div>

        <!-- Manual input -->
        <div class="border-t border-gray-100 px-4 py-3">
            <p class="text-[10px] text-gray-400 font-bold uppercase tracking-widest mb-3">หรือใช้เครื่องยิงบาร์โค้ด / กรอกรหัส</p>
            <div class="flex gap-2">
                <input type="text" id="manualId" placeholder="เช่น 42 หรือรหัสจากเครื่องยิง"
                    class="flex-1 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100 transition-all">
                <button onclick="submitManual()"
                    class="bg-[#0052CC] hover:bg-blue-700 text-white px-5 py-3 rounded-xl text-sm font-bold transition-colors active:scale-95">
                    <i class="fa-solid fa-paper-plane"></i>
                </button>
            </div>
        </div>

        <!-- QR Image Upload -->
        <div class="border-t border-gray-100 px-4 py-3 bg-gray-50/50">
            <p class="text-[10px] text-gray-400 font-bold uppercase tracking-widest mb-2">หรือสแกนจากรูปภาพ</p>
            <label for="qr-file" class="flex items-center justify-center gap-2 bg-white border border-gray-200 rounded-xl px-4 py-2 text-sm font-bold text-gray-600 cursor-pointer hover:bg-blue-50 hover:border-blue-200 transition-all">
                <i class="fa-solid fa-image text-[#0052CC]"></i>
                <span>เลือกรูปภาพ QR</span>
                <input type="file" id="qr-file" accept="image/*" class="hidden">
            </label>
        </div>
    </div>

    <!-- Recent Scans Log -->
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
            <p class="text-xs font-bold text-gray-600 uppercase tracking-wider">สแกนล่าสุด</p>
            <button onclick="clearLog()" class="text-[10px] text-gray-400 hover:text-gray-600 font-semibold">ล้าง</button>
        </div>
        <div id="scanLog" class="divide-y divide-gray-50 max-h-52 overflow-y-auto">
            <div class="px-4 py-4 text-center text-xs text-gray-400">ยังไม่มีการสแกน</div>
        </div>
    </div>
</div>

<!-- ── Toast notification ──────────────────────────────────────────────── -->
<div id="toast" class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 hidden">
    <div id="toastInner" class="px-5 py-3 rounded-2xl shadow-xl text-sm font-bold flex items-center gap-2 max-w-xs text-center">
        <i id="toastIcon"></i>
        <span id="toastMsg"></span>
    </div>
</div>

<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
<script>
// ── State ──────────────────────────────────────────────────────────────────
let activeCampaignId   = <?= $preselect ?: 'null' ?>;
let activeCampaignName = '';
let html5QrCode        = null;
let isProcessing       = false;
let attendedCount      = 0;
const csrfToken        = <?= json_encode($csrf) ?>;

// Campaign data from PHP
const campaignData = <?= json_encode(array_column($campaigns, null, 'id')) ?>;

// ── Init ───────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    if (activeCampaignId) {
        const c = campaignData[activeCampaignId];
        if (c) selectCampaign(activeCampaignId, c.title);
        else    showPickerView();
    } else {
        showPickerView();
    }
});

// ── View switching ─────────────────────────────────────────────────────────
function showPickerView() {
    stopCamera();
    document.getElementById('pickerView').classList.remove('hidden');
    document.getElementById('scannerView').classList.add('hidden');
    document.getElementById('btnChangeCampaign').classList.add('hidden');
    document.getElementById('headerCampaignName').textContent = 'เลือกแคมเปญก่อน';
}

function showScannerView() {
    document.getElementById('pickerView').classList.add('hidden');
    document.getElementById('scannerView').classList.remove('hidden');
    document.getElementById('btnChangeCampaign').classList.remove('hidden');
    startCamera();
}

// ── Campaign selection ─────────────────────────────────────────────────────
function selectCampaign(id, name) {
    activeCampaignId   = id;
    activeCampaignName = name;
    const c = campaignData[id] || {};

    // update header
    document.getElementById('headerCampaignName').textContent = name;

    // update info card
    document.getElementById('campaignTitle').textContent    = name;
    document.getElementById('campaignIdDisplay').textContent = '#' + id;
    document.getElementById('campaignBadge').textContent    = name.charAt(0).toUpperCase();
    attendedCount = parseInt(c.attended || 0);
    document.getElementById('attendedCount').textContent = attendedCount;

    // update URL without reload
    history.replaceState(null, '', '?campaign_id=' + id);

    showScannerView();
}

// ── Camera ─────────────────────────────────────────────────────────────────
function startCamera() {
    if (html5QrCode && html5QrCode.isScanning) return; // already running
    html5QrCode = new Html5Qrcode('qr-reader');
    setStatus('กำลังเปิดกล้อง...', 'blue');

    html5QrCode.start(
        { facingMode: 'environment' },
        { fps: 10, qrbox: { width: 240, height: 240 }, aspectRatio: 1.0 },
        (decoded) => {
            html5QrCode.pause();
            processCheckin('BOOKING-ID:' === decoded.substring(0,11) ? decoded : decoded, false);
        },
        () => { /* frame errors: ignore */ }
    ).then(() => {
        setStatus('พร้อมสแกน', 'green');
        const btn = document.getElementById('btnToggleCam');
        btn.innerHTML = '<i class="fa-solid fa-video mr-1"></i>ปิดกล้อง';
        btn.className = 'text-[10px] font-bold text-white bg-emerald-600 px-3 py-1.5 rounded-lg hover:bg-emerald-700 transition-colors';
    }).catch(err => {
        console.error(err);
        setStatus('ไม่สามารถเปิดกล้องได้ — ลองกรอก ID แทน', 'red');
    });
}

function stopCamera() {
    if (html5QrCode) {
        html5QrCode.stop().catch(() => {});
        html5QrCode = null;
    }
}

// ── Process check-in ───────────────────────────────────────────────────────
function processCheckin(qrData, isManual, isConfirmed = false) {
    if (isProcessing && !isConfirmed) return;
    isProcessing = true;
    setStatus(isConfirmed ? 'กำลังบันทึกเช็คอิน...' : 'กำลังตรวจสอบ...', 'orange');

    const fd = new FormData();
    fd.append('qr_data', qrData);
    fd.append('campaign_id', activeCampaignId);
    fd.append('csrf_token', csrfToken);
    if (isConfirmed) fd.append('confirm', '1');

    fetch('ajax/ajax_scan_checkin.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'preview') {
                isProcessing = false;
                Swal.fire({
                    title: 'ยืนยันข้อมูลเช็คอิน',
                    html: `<div class="text-left bg-blue-50 p-4 rounded-2xl mt-2 border border-blue-100">
                            <p class="text-[10px] text-blue-400 font-bold uppercase mb-1">ผู้เข้าร่วม</p>
                            <p class="font-bold text-lg text-gray-900 mb-3">${data.data.name}</p>
                            <p class="text-[10px] text-blue-400 font-bold uppercase mb-1">กิจกรรม/แคมเปญ</p>
                            <p class="font-bold text-[#0052CC] text-sm">${data.data.campaign}</p>
                            <p class="text-xs text-gray-500 mt-2"><i class="fa-regular fa-clock mr-1"></i>${data.data.slot_label}</p>
                           </div>`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#0052CC',
                    cancelButtonColor: '#6b7280',
                    confirmButtonText: 'ยืนยันเช็คอิน',
                    cancelButtonText: 'ยกเลิก',
                    reverseButtons: true,
                    customClass: { title: 'font-prompt', popup: 'font-prompt rounded-3xl' }
                }).then((result) => {
                    if (result.isConfirmed) {
                        processCheckin(qrData, isManual, true);
                    } else {
                        setStatus('พร้อมสแกน', 'green');
                        if (!isManual && html5QrCode) {
                            if (html5QrCode.getState() === 3) {
                                setTimeout(() => html5QrCode.resume(), 300);
                            } else if (html5QrCode.getState() === 1) {
                                startCamera();
                            }
                        }
                    }
                });
                return;
            }

            isProcessing = false;

            if (data.status === 'success') {
                attendedCount++;
                document.getElementById('attendedCount').textContent = attendedCount;
                addLog('success', data.data.name, data.data.slot_label || activeCampaignName);
                showToast('success', `เช็คอินสำเร็จ: ${data.data.name}`);
                setStatus('พร้อมสแกน', 'green');
                flashCard('success');
            } else if (data.status === 'warning') {
                addLog('warning', data.message, '');
                showToast('warning', data.message);
                setStatus('พร้อมสแกน', 'green');
            } else {
                addLog('error', data.message, '');
                showToast('error', data.message);
                setStatus('พร้อมสแกน', 'green');
                flashCard('error');
            }

            if (!isManual && html5QrCode) {
                if (html5QrCode.getState() === 3) {
                    setTimeout(() => html5QrCode.resume(), 1500);
                } else if (html5QrCode.getState() === 1) {
                    startCamera();
                }
            }
            if (isManual) document.getElementById('manualId').value = '';
        })
        .catch(() => {
            isProcessing = false;
            showToast('error', 'ไม่สามารถเชื่อมต่อ server ได้');
            setStatus('พร้อมสแกน', 'green');
            if (!isManual && html5QrCode) {
                if (html5QrCode.getState() === 3) html5QrCode.resume();
                else if (html5QrCode.getState() === 1) startCamera();
            }
        });
}

function submitManual() {
    const val = document.getElementById('manualId').value.trim();
    if (!val) {
        showToast('error', 'กรุณากรอกรหัส หรือใช้เครื่องยิงบาร์โค้ด');
        return;
    }
    processCheckin(val, true);
    document.getElementById('manualId').value = ''; // ล้างค่าหลังส่ง
}

// ── UI helpers ─────────────────────────────────────────────────────────────
function setStatus(text, color) {
    const el = document.getElementById('scan-status');
    el.textContent = text;
    el.className = 'text-sm font-bold animate-pulse ' +
        (color === 'green'  ? 'text-green-500' :
         color === 'orange' ? 'text-orange-500' :
         color === 'red'    ? 'text-red-500' : 'text-[#0052CC]');
}

let toastTimer = null;
function showToast(type, msg) {
    const wrap  = document.getElementById('toast');
    const inner = document.getElementById('toastInner');
    const icon  = document.getElementById('toastIcon');
    const span  = document.getElementById('toastMsg');

    span.textContent = msg;
    if (type === 'success') {
        inner.className = 'px-5 py-3 rounded-2xl shadow-xl text-sm font-bold flex items-center gap-2 max-w-xs bg-green-600 text-white';
        icon.className  = 'fa-solid fa-circle-check';
    } else if (type === 'warning') {
        inner.className = 'px-5 py-3 rounded-2xl shadow-xl text-sm font-bold flex items-center gap-2 max-w-xs bg-amber-500 text-white';
        icon.className  = 'fa-solid fa-triangle-exclamation';
    } else {
        inner.className = 'px-5 py-3 rounded-2xl shadow-xl text-sm font-bold flex items-center gap-2 max-w-xs bg-red-600 text-white';
        icon.className  = 'fa-solid fa-circle-xmark';
    }

    wrap.classList.remove('hidden');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => wrap.classList.add('hidden'), 3000);
}

function addLog(type, main, sub) {
    const log = document.getElementById('scanLog');
    // Remove placeholder
    if (log.querySelector('.text-gray-400')) log.innerHTML = '';

    const now = new Date().toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    const icons = { success: 'fa-circle-check text-green-500', warning: 'fa-triangle-exclamation text-amber-500', error: 'fa-circle-xmark text-red-500' };
    const div = document.createElement('div');
    div.className = 'log-item px-4 py-3 flex items-start gap-3';
    div.innerHTML = `
        <i class="fa-solid ${icons[type] || icons.error} mt-0.5 text-sm shrink-0"></i>
        <div class="flex-1 min-w-0">
            <p class="text-sm font-semibold text-gray-800 truncate">${escHtml(main)}</p>
            ${sub ? `<p class="text-[10px] text-gray-400 truncate">${escHtml(sub)}</p>` : ''}
        </div>
        <span class="text-[10px] text-gray-400 shrink-0">${now}</span>`;
    log.prepend(div);

    // Keep max 20 entries
    while (log.children.length > 20) log.removeChild(log.lastChild);
}

function clearLog() {
    document.getElementById('scanLog').innerHTML =
        '<div class="px-4 py-4 text-center text-xs text-gray-400">ยังไม่มีการสแกน</div>';
}

function flashCard(type) {
    const card = document.getElementById('campaignInfoCard');
    card.classList.add(type === 'success' ? 'flash-success' : 'flash-error');
    setTimeout(() => card.classList.remove('flash-success','flash-error'), 700);
}

function filterCampaigns(q) {
    q = q.toLowerCase();
    document.querySelectorAll('.campaign-card').forEach(btn => {
        btn.style.display = btn.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Manual input: Enter key
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('manualId')?.addEventListener('keydown', e => {
        if (e.key === 'Enter') submitManual();
    });

    // Camera Toggle handler
    document.getElementById('btnToggleCam')?.addEventListener('click', async () => {
        const btn = document.getElementById('btnToggleCam');
        if (html5QrCode && html5QrCode.isScanning) {
            await html5QrCode.stop();
            btn.innerHTML = '<i class="fa-solid fa-video-slash mr-1"></i>เปิดกล้อง';
            btn.className = 'text-[10px] font-bold text-white bg-slate-700 px-3 py-1.5 rounded-lg hover:bg-slate-800 transition-colors';
            setStatus('ปิดกล้องแล้ว', 'gray');
        } else {
            startCamera();
        }
    });

    // QR File Upload handler
    document.getElementById('qr-file')?.addEventListener('change', async e => {
        if (!activeCampaignId) {
            showToast('warning', 'กรุณาเลือกแคมเปญก่อน');
            e.target.value = '';
            return;
        }
        if (e.target.files.length === 0) return;
        const file = e.target.files[0];
        
        const wasScanning = html5QrCode && html5QrCode.isScanning;
        
        // Ensure html5QrCode is initialized
        if (!html5QrCode) {
            html5QrCode = new Html5Qrcode('qr-reader');
        }
        
        setStatus('กำลังอ่านรูปภาพ...', 'orange');
        
        try {
            // ต้องหยุดกล้องก่อนสแกนไฟล์
            if (wasScanning) {
                await stopCamera();
            }

            const decoded = await html5QrCode.scanFile(file, true);
            e.target.value = '';
            processCheckin(decoded, false);
        } catch (err) {
            console.error(err);
            showToast('error', 'ไม่พบ QR Code ในรูปภาพนี้');
            setStatus('พร้อมสแกน', 'green');
            e.target.value = '';
            
            // กลับมาเปิดกล้องต่อถ้าก่อนหน้าเปิดอยู่
            if (wasScanning) {
                startCamera();
            }
        }
    });
});
</script>
</body>
</html>
