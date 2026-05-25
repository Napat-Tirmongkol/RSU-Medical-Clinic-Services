<?php
// staff/index.php
session_start();

// รับ session ทั้ง 2 แบบ:
// 1. staff_logged_in  — login ผ่าน staff/login.php (เดิม)
// 2. admin_logged_in + is_ecampaign_staff — login ผ่าน admin/staff_login.php → portal
$viaStaffLogin  = !empty($_SESSION['staff_logged_in']) && $_SESSION['staff_logged_in'] === true;
$viaPortalLogin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true
               && !empty($_SESSION['is_ecampaign_staff']);

if (!$viaStaffLogin && !$viaPortalLogin) {
    header('Location: login.php');
    exit;
}

// [ISO 27001] ตรวจสอบสิทธิ์การเข้าถึงแบบ Real-time สำหรับ Staff
require_once __DIR__ . '/../config.php';
if ($viaStaffLogin || $viaPortalLogin) {
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
}

// normalize ชื่อให้แสดงใน topbar ได้ไม่ว่าจะ login ทางไหน
if (empty($_SESSION['staff_name']) && !empty($_SESSION['admin_username'])) {
    $_SESSION['staff_name'] = $_SESSION['admin_username'];
}

require_once __DIR__ . '/../config.php';

// Walk-in QR — fetch campaigns that staff can present to walk-in patients.
// Mirrors the gate in admin/ajax/ajax_toggle_walkin.php: 'active' + 'full' both
// allow walk-in (full = overflow lane, slot capacity still gates downstream).
$walkinCampaigns = [];
try {
    $pdo = db();
    $pdo->exec("ALTER TABLE camp_list ADD COLUMN IF NOT EXISTS walkin_enabled TINYINT(1) NOT NULL DEFAULT 0");
    $stmt = $pdo->prepare("
        SELECT id, title, type, status
        FROM camp_list
        WHERE walkin_enabled = 1
          AND status IN ('active', 'full')
          AND (available_until IS NULL OR available_until >= CURDATE())
          AND (available_from  IS NULL OR available_from  <= CURDATE())
        ORDER BY (status = 'active') DESC, id DESC
        LIMIT 30
    ");
    $stmt->execute();
    $walkinCampaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) {}

function staff_type_meta(string $t): array {
    return match ($t) {
        'vaccine'      => ['ฉีดวัคซีน',  'fa-syringe'],
        'training'     => ['อบรม',       'fa-chalkboard-user'],
        'health_check' => ['ตรวจสุขภาพ', 'fa-stethoscope'],
        default        => ['กิจกรรม',     'fa-calendar-check'],
    };
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Scanner</title>
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style> 
        body { font-family: 'Sarabun', sans-serif; background-color: #f4f7fa; }
        #reader__dashboard_section_csr span { display: none !important; }
        #reader button { background-color: #0052CC !important; color: white !important; border: none !important; padding: 8px 16px !important; border-radius: 8px !important; font-family: 'Sarabun', sans-serif !important; margin-top: 10px !important; cursor: pointer; }
        #reader a { display: none !important; }
        #btn-toggle-camera.camera-toggle-btn {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 8px !important;
            min-width: 132px !important;
            height: 46px !important;
            padding: 0 22px !important;
            border: 2px solid rgba(255,255,255,.85) !important;
            border-radius: 18px !important;
            color: #fff !important;
            font-size: 14px !important;
            font-weight: 900 !important;
            line-height: 1 !important;
            box-shadow: 0 14px 34px rgba(15,23,42,.22) !important;
            opacity: 1 !important;
            filter: none !important;
            transform: none;
        }
        #btn-toggle-camera.camera-toggle-btn:hover {
            box-shadow: 0 16px 38px rgba(15,23,42,.28) !important;
        }
        #btn-toggle-camera.camera-toggle-btn:active {
            transform: scale(.96);
        }
        #btn-toggle-camera.camera-toggle-on {
            background: #dc2626 !important;
        }
        #btn-toggle-camera.camera-toggle-on:hover {
            background: #b91c1c !important;
        }
        #btn-toggle-camera.camera-toggle-off {
            background: #0f172a !important;
        }
        #btn-toggle-camera.camera-toggle-off:hover {
            background: #1e293b !important;
        }
    </style>
</head>
<body class="pb-24">

<div class="bg-white p-4 shadow-sm flex justify-between items-center sticky top-0 z-50">
    <div class="font-bold text-[#0052CC] text-lg"><i class="fa-solid fa-qrcode mr-2"></i>Staff Scanner</div>
    <div class="flex items-center gap-3">
        <?php if (!empty($_SESSION['staff_name'])): ?>
        <span class="text-xs text-gray-500 font-semibold hidden sm:block">
            <i class="fa-solid fa-user-tie mr-1 text-gray-400"></i><?= htmlspecialchars($_SESSION['staff_name']) ?>
        </span>
        <?php endif; ?>
        <a href="../portal/index.php" class="bg-blue-50 text-blue-600 px-4 py-2 rounded-xl text-xs font-bold hover:bg-blue-100 transition-colors">
            <i class="fa-solid fa-grip mr-1"></i>Portal
        </a>
        <a href="logout.php" class="bg-red-50 text-red-500 px-4 py-2 rounded-xl text-xs font-bold hover:bg-red-100 transition-colors">
            ออกจากระบบ
        </a>
    </div>
</div>

<div class="max-w-md mx-auto p-5 mt-4">
    <!-- ปุ่มเปิด/ปิดกล้อง -->
    <div class="flex justify-center mb-6">
        <button id="btn-toggle-camera" class="camera-toggle-btn camera-toggle-on">
            <i class="fa-solid fa-video-slash"></i>
            <span id="toggle-text">ปิดกล้อง</span>
        </button>
    </div>

    <div class="bg-white p-4 rounded-3xl shadow-lg border border-gray-100 relative overflow-hidden" id="scanner-container">
        <div id="reader" class="w-full rounded-2xl overflow-hidden bg-black relative"></div>
        <div class="mt-6 text-center pb-2">
            <p id="scan-status" class="text-sm font-bold text-[#0052CC] animate-pulse">กำลังรอกล้อง...</p>
        </div>
    </div>

    <!-- ส่วนอัปโหลดรูปภาพ (Fallback) -->
    <div class="mt-4 bg-white p-6 rounded-3xl shadow-lg border border-gray-100 text-center">
        <p class="text-[10px] text-gray-400 font-bold uppercase tracking-widest mb-3">หรืออัปโหลดรูปภาพ QR Code</p>
        <label for="qr-input-file" class="flex flex-col items-center justify-center border-2 border-dashed border-blue-50 rounded-2xl p-6 cursor-pointer hover:bg-blue-50 hover:border-blue-200 transition-all group">
            <div class="w-12 h-12 bg-blue-50 rounded-full flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                <i class="fa-solid fa-image text-[#0052CC] text-xl"></i>
            </div>
            <span class="text-sm font-semibold text-gray-600">เลือกรูปภาพเพื่อสแกน</span>
            <input type="file" id="qr-input-file" accept="image/*" class="hidden">
        </label>
    </div>

    <!-- ส่วนกรอกรหัส/ใช้เครื่องยิงบาร์โค้ด -->
    <div class="mt-4 bg-white p-6 rounded-3xl shadow-lg border border-gray-100">
        <p class="text-[10px] text-gray-400 font-bold uppercase tracking-widest mb-3">กรอกรหัส หรือใช้เครื่องยิงบาร์โค้ด</p>
        <div class="flex gap-2">
            <input type="text" id="manual-input-id" placeholder="เช่น 42 หรือรหัสจากเครื่องยิง" class="flex-1 bg-gray-50 border border-gray-200 rounded-2xl px-4 py-3 text-sm focus:outline-none focus:border-blue-400 transition-all">
            <button id="btn-submit-manual" class="bg-blue-600 text-white px-5 py-3 rounded-2xl font-bold text-sm shadow-md hover:bg-blue-700 active:scale-95 transition-all">
                <i class="fa-solid fa-paper-plane"></i>
            </button>
        </div>
        <p class="text-[9px] text-gray-400 mt-2"><i class="fa-solid fa-circle-info mr-1"></i>เครื่องยิงบาร์โค้ดจะทำงานอัตโนมัติเมื่อวางเคอร์เซอร์ในช่องนี้</p>
    </div>

    <!-- ── Walk-in QR — present a QR for patients to scan at the counter ── -->
    <div class="mt-4 bg-white p-6 rounded-3xl shadow-lg border border-amber-100">
        <div class="flex items-center justify-between mb-3">
            <p class="text-[10px] text-amber-600 font-bold uppercase tracking-widest">
                <i class="fa-solid fa-person-walking mr-1"></i> Walk-in QR (ผู้ป่วยลงทะเบียนเอง)
            </p>
            <span class="text-[10px] font-bold text-amber-700 bg-amber-50 px-2 py-0.5 rounded-full">
                <?= count($walkinCampaigns) ?> แคมเปญ
            </span>
        </div>
        <p class="text-xs text-gray-500 mb-3">
            แสดง QR หน้าจอให้ผู้ป่วย → ผู้ป่วยสแกนด้วยมือถือ → ลงทะเบียน LINE + เช็คอินอัตโนมัติ
        </p>

        <?php if (empty($walkinCampaigns)): ?>
        <div class="text-center text-gray-400 py-6 bg-gray-50 rounded-2xl border border-dashed border-gray-200">
            <i class="fa-solid fa-circle-info text-2xl mb-2 block opacity-50"></i>
            <div class="text-sm font-semibold">ยังไม่มีแคมเปญที่เปิด Walk-in</div>
            <div class="text-[11px] mt-1">ให้ admin เปิดสวิตช์ Walk-in QR ในหน้า "สร้างแคมเปญ" ก่อน</div>
        </div>
        <?php else: ?>
        <div class="space-y-2">
            <?php foreach ($walkinCampaigns as $c): ?>
                <?php [$_tLabel, $_tIcon] = staff_type_meta((string)$c['type']); ?>
            <button type="button"
                    onclick="showWalkinQr(<?= (int)$c['id'] ?>, <?= htmlspecialchars(json_encode($c['title'], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($_tLabel, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)"
                    class="w-full flex items-center justify-between gap-3 p-3 rounded-2xl border-2 border-amber-100 bg-amber-50 hover:bg-amber-100 hover:border-amber-200 active:scale-[.99] transition-all text-left">
                <div class="flex items-center gap-2.5 min-w-0">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-500 to-amber-600 text-white flex items-center justify-center flex-shrink-0 shadow-sm shadow-amber-200">
                        <i class="fa-solid <?= $_tIcon ?>"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-bold text-amber-900 truncate"><?= htmlspecialchars($c['title']) ?></div>
                        <div class="text-[11px] text-amber-700 font-semibold">
                            <?= $_tLabel ?>
                            <?php if ($c['status'] === 'full'): ?>
                                · <span class="text-rose-600">เต็มโควต้า (รับ overflow)</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <i class="fa-solid fa-qrcode text-amber-600 text-lg"></i>
            </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Walk-in QR display modal -->
<div id="walkinQrModal" style="display:none;position:fixed;inset:0;z-index:9500;background:rgba(15,23,42,.65);backdrop-filter:blur(8px);align-items:center;justify-content:center;padding:16px"
     onclick="if(event.target===this)closeWalkinQr()">
    <div style="background:#fff;border-radius:24px;width:100%;max-width:380px;overflow:hidden;box-shadow:0 25px 50px -12px rgba(0,0,0,.4);max-height:95vh;display:flex;flex-direction:column">
        <div style="padding:14px 18px;background:linear-gradient(135deg,#d97706,#f59e0b);color:#fff;display:flex;align-items:center;justify-content:space-between;flex-shrink:0">
            <div style="min-width:0">
                <div style="font-size:10px;font-weight:800;letter-spacing:.1em;opacity:.85">
                    <i class="fa-solid fa-person-walking mr-1"></i>WALK-IN QR
                </div>
                <div id="walkinModalTitle" style="font-size:14px;font-weight:900;margin-top:1px;line-height:1.3"></div>
                <div id="walkinModalType" style="font-size:11px;opacity:.9;margin-top:1px"></div>
            </div>
            <button onclick="closeWalkinQr()" style="width:30px;height:30px;border-radius:8px;border:none;background:rgba(255,255,255,.2);color:#fff;cursor:pointer;flex-shrink:0">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div style="padding:20px;text-align:center;overflow-y:auto">
            <div style="display:inline-block;padding:14px;background:#fff;border:3px solid #d97706;border-radius:18px">
                <img id="walkinQrImg" src="" alt="QR" style="width:260px;height:260px;display:block">
            </div>
            <p style="margin:14px 0 4px;font-size:13px;color:#475569;font-weight:700">
                <i class="fa-solid fa-mobile-screen text-amber-500 mr-1"></i>
                ให้ผู้ป่วยเปิดกล้องมือถือสแกน
            </p>
            <p style="font-size:11px;color:#94a3b8">Login LINE → กรอกข้อมูล → เช็คอินอัตโนมัติ</p>
            <button onclick="closeWalkinQr()" class="mt-3 bg-amber-500 hover:bg-amber-600 text-white px-5 py-2 rounded-xl text-sm font-bold transition-all">
                <i class="fa-solid fa-check mr-1"></i> เสร็จแล้ว
            </button>
        </div>
    </div>
</div>

<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ใช้ Html5Qrcode เพื่อบังคับเลนส์กล้องหลังหลัก
    const html5QrCode = new Html5Qrcode("reader");
    let isProcessing = false;
    let manualSubmitLocked = false;
    let lastManualValue = '';
    let lastManualAt = 0;

    // ฟังก์ชันเช็คอิน
    function resumeCameraIfNeeded(source) {
        if (source === 'manual') return;
        if (html5QrCode.getState() === 3) html5QrCode.resume();
        else if (html5QrCode.getState() === 1) startCamera();
    }

    function processQRCode(decodedText, isConfirmed = false, source = 'camera') {
        if (isProcessing && !isConfirmed) return; 
        isProcessing = true;
        
        document.getElementById('scan-status').innerText = isConfirmed ? 'กำลังบันทึกเช็คอิน...' : 'กำลังตรวจสอบข้อมูล...';
        document.getElementById('scan-status').className = 'text-sm font-bold text-orange-500 animate-pulse';

        const formData = new FormData();
        formData.append('qr_data', decodedText);
        formData.append('csrf_token', '<?= get_csrf_token() ?>');
        if (isConfirmed) formData.append('confirm', '1');

        fetch('ajax_scan_checkin.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                let swalConfig = { allowOutsideClick: false, customClass: { title: 'font-prompt', popup: 'font-prompt rounded-3xl' }};
                
                if (data.status === 'preview') {
                    isProcessing = false;
                    const isEarly = Boolean(data.data.is_early);
                    const earlyNotice = isEarly
                        ? `<div class="mb-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm font-semibold text-amber-700"><i class="fa-solid fa-triangle-exclamation mr-1"></i>${data.data.warning || 'ยังไม่ถึงวันรับบริการ'}</div>`
                        : '';
                    Swal.fire({
                        title: isEarly ? 'ยืนยันให้เข้ารับการบริการ' : 'ยืนยันข้อมูลเช็คอิน',
                        html: `${earlyNotice}<div class="text-left bg-blue-50 p-4 rounded-2xl mt-2 border border-blue-100">
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
                        confirmButtonText: isEarly ? 'ยืนยันให้เข้ารับการบริการ' : 'ยืนยันเช็คอิน',
                        cancelButtonText: 'ยกเลิก',
                        reverseButtons: true
                    }).then((result) => {
                        if (result.isConfirmed) {
                            processQRCode(decodedText, true, source);
                        } else {
                            document.getElementById('scan-status').innerText = 'พร้อมสแกน...';
                            document.getElementById('scan-status').className = 'text-sm font-bold text-green-500 animate-pulse';
                            resumeCameraIfNeeded(source);
                        }
                    });
                } else if (data.status === 'success') {
                    isProcessing = false;
                    swalConfig.title = 'เช็คอินสำเร็จ!';
                    swalConfig.html = `<div class="text-left bg-green-50 p-4 rounded-xl mt-2 border border-green-100"><p class="text-sm text-green-600 mb-1">ผู้เข้าร่วม:</p><p class="font-bold text-lg text-gray-900 mb-3">${data.data.name}</p><p class="text-sm text-green-600 mb-1">กิจกรรม:</p><p class="font-bold text-[#0052CC]">${data.data.campaign}</p></div>`;
                    swalConfig.icon = 'success'; swalConfig.confirmButtonColor = '#0052CC'; swalConfig.confirmButtonText = 'สแกนคิวถัดไป';
                    
                    Swal.fire(swalConfig).then(() => { 
                        document.getElementById('scan-status').innerText = 'พร้อมสแกน...';
                        document.getElementById('scan-status').className = 'text-sm font-bold text-green-500 animate-pulse';
                        resumeCameraIfNeeded(source);
                    });
                } else if (data.status === 'warning') {
                    isProcessing = false;
                    swalConfig.title = 'แจ้งเตือน!'; swalConfig.text = data.message; swalConfig.icon = 'warning'; swalConfig.confirmButtonColor = '#f59e0b'; swalConfig.confirmButtonText = 'ตกลง';
                    Swal.fire(swalConfig).then(() => { 
                        document.getElementById('scan-status').innerText = 'พร้อมสแกน...';
                        document.getElementById('scan-status').className = 'text-sm font-bold text-green-500 animate-pulse';
                        resumeCameraIfNeeded(source);
                    });
                } else {
                    isProcessing = false;
                    swalConfig.title = 'ข้อผิดพลาด!'; swalConfig.text = data.message; swalConfig.icon = 'error'; swalConfig.confirmButtonColor = '#ef4444'; swalConfig.confirmButtonText = 'ลองใหม่';
                    Swal.fire(swalConfig).then(() => { 
                        document.getElementById('scan-status').innerText = 'พร้อมสแกน...';
                        document.getElementById('scan-status').className = 'text-sm font-bold text-green-500 animate-pulse';
                        resumeCameraIfNeeded(source);
                    });
                }
            })
            .catch(err => {
                isProcessing = false;
                Swal.fire('Error', 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'error').then(() => { 
                    resumeCameraIfNeeded(source);
                });
            })
            .finally(() => {
                if (source === 'manual') {
                    manualSubmitLocked = false;
                    const btn = document.getElementById('btn-submit-manual');
                    if (btn) {
                        btn.disabled = false;
                        btn.classList.remove('opacity-60', 'cursor-not-allowed');
                    }
                }
            });
    }

    // ฟังก์ชันเปิด/ปิดกล้อง
    const toggleBtn = document.getElementById('btn-toggle-camera');
    const toggleText = document.getElementById('toggle-text');

    async function startCamera() {
        try {
            await html5QrCode.start(
                { facingMode: "environment" }, 
                { fps: 10, qrbox: { width: 250, height: 250 }, aspectRatio: 1.0 },
                (decodedText) => {
                    html5QrCode.pause();
                    processQRCode(decodedText);
                },
                () => {}
            );
            document.getElementById('scan-status').innerText = 'พร้อมสแกน...';
            document.getElementById('scan-status').className = 'text-sm font-bold text-green-500 animate-pulse';
            toggleBtn.className = 'camera-toggle-btn camera-toggle-on';
            toggleBtn.querySelector('i').className = 'fa-solid fa-video-slash';
            toggleText.innerText = 'ปิดกล้อง';
        } catch (err) {
            console.error("Camera error", err);
            document.getElementById('scan-status').innerText = 'ไม่สามารถเปิดกล้องได้';
            document.getElementById('scan-status').className = 'text-sm font-bold text-red-500';
        }
    }

    async function stopCamera() {
        if (html5QrCode.isScanning) {
            await html5QrCode.stop();
            document.getElementById('scan-status').innerText = 'ปิดกล้องแล้ว';
            document.getElementById('scan-status').className = 'text-sm font-bold text-gray-400';
            toggleBtn.className = 'camera-toggle-btn camera-toggle-off';
            toggleBtn.querySelector('i').className = 'fa-solid fa-video';
            toggleText.innerText = 'เปิดกล้อง';
        }
    }

    toggleBtn.addEventListener('click', async () => {
        if (html5QrCode.isScanning) {
            await stopCamera();
        } else {
            await startCamera();
        }
    });

    // เริ่มต้นเปิดกล้อง
    startCamera();

    // เพิ่มตัวดักจับการอัปโหลดไฟล์
    const fileInput = document.getElementById('qr-input-file');
    fileInput.addEventListener('change', async e => {
        if (e.target.files.length === 0) return;
        const imageFile = e.target.files[0];
        
        const wasScanning = html5QrCode.isScanning;
        document.getElementById('scan-status').innerText = 'กำลังประมวลผลรูปภาพ...';
        document.getElementById('scan-status').className = 'text-sm font-bold text-orange-500 animate-pulse';

        try {
            // ต้องหยุดกล้องก่อนสแกนไฟล์ (Cannot start file scan - ongoing camera scan)
            if (wasScanning) {
                await stopCamera();
            }

            const decodedText = await html5QrCode.scanFile(imageFile, true);
            fileInput.value = '';
            processQRCode(decodedText);
            
            // ถ้าก่อนหน้านี้เปิดกล้องอยู่ ให้เปิดกลับมาใหม่หลังสแกนไฟล์เสร็จ (ผ่าน processQRCode หรือ Error)
            // แต่ในกรณีสำเร็จ processQRCode จะมีปุ่มให้กดต่อ ซึ่งเราจะจัดการที่นั่น
        } catch (err) {
            console.error(err);
            Swal.fire({
                title: 'ไม่พบ QR Code',
                text: 'ไม่สามารถอ่าน QR Code จากรูปภาพนี้ได้ กรุณาลองด้วยรูปภาพอื่น',
                icon: 'error',
                confirmButtonColor: '#0052CC',
                customClass: { title: 'font-prompt', popup: 'font-prompt rounded-3xl' }
            });
            document.getElementById('scan-status').innerText = 'พร้อมสแกน...';
            document.getElementById('scan-status').className = 'text-sm font-bold text-green-500 animate-pulse';
            fileInput.value = '';
            
            // ถ้าก่อนหน้าเปิดกล้องอยู่ ให้เปิดกลับมา
            if (wasScanning) {
                startCamera();
            }
        }
    });

    // จัดการการกรอกรหัส/เครื่องยิงบาร์โค้ด
    const manualInput = document.getElementById('manual-input-id');
    const btnSubmitManual = document.getElementById('btn-submit-manual');

    async function handleManualSubmit(e) {
        if (e) e.preventDefault();
        if (manualSubmitLocked || isProcessing) return;

        const val = manualInput.value.trim();
        if (!val) return;

        const now = Date.now();
        if (val === lastManualValue && now - lastManualAt < 1500) {
            return;
        }

        manualSubmitLocked = true;
        lastManualValue = val;
        lastManualAt = now;
        btnSubmitManual.disabled = true;
        btnSubmitManual.classList.add('opacity-60', 'cursor-not-allowed');

        if (html5QrCode.isScanning) {
            await stopCamera();
        }

        processQRCode(val, false, 'manual');
        manualInput.value = '';
    }

    manualInput.addEventListener('keydown', e => {
        if (e.key === 'Enter') {
            if (e.repeat || e.isComposing) return;
            handleManualSubmit(e);
        }
    });
    btnSubmitManual.addEventListener('click', handleManualSubmit);
});

// ── Walk-in QR modal ────────────────────────────────────────────────
function showWalkinQr(cid, title, typeLabel) {
    document.getElementById('walkinModalTitle').textContent = title || '';
    document.getElementById('walkinModalType').textContent  = typeLabel || '';
    // size=12 = 12px per module ~ 280-300px image, sharp on phones
    document.getElementById('walkinQrImg').src =
        '../user/api_walkin_qr.php?campaign=' + encodeURIComponent(cid) + '&size=12&t=' + Date.now();
    document.getElementById('walkinQrModal').style.display = 'flex';
}
function closeWalkinQr() {
    document.getElementById('walkinQrModal').style.display = 'none';
    document.getElementById('walkinQrImg').src = '';
}
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && document.getElementById('walkinQrModal').style.display === 'flex') {
        closeWalkinQr();
    }
});
</script>
</body>
</html>
