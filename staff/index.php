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
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Scanner</title>
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style> 
        body { font-family: 'Prompt', sans-serif; background-color: #f4f7fa; }
        #reader__dashboard_section_csr span { display: none !important; }
        #reader button { background-color: #0052CC !important; color: white !important; border: none !important; padding: 8px 16px !important; border-radius: 8px !important; font-family: 'Prompt', sans-serif !important; margin-top: 10px !important; cursor: pointer; }
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
</div>

<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ใช้ Html5Qrcode เพื่อบังคับเลนส์กล้องหลังหลัก
    const html5QrCode = new Html5Qrcode("reader");
    let isProcessing = false;

    // ฟังก์ชันเช็คอิน
    function processQRCode(decodedText, isConfirmed = false) {
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
                        reverseButtons: true
                    }).then((result) => {
                        if (result.isConfirmed) {
                            processQRCode(decodedText, true);
                        } else {
                            document.getElementById('scan-status').innerText = 'พร้อมสแกน...';
                            document.getElementById('scan-status').className = 'text-sm font-bold text-green-500 animate-pulse';
                            if (html5QrCode.getState() === 3) html5QrCode.resume();
                            else if (html5QrCode.getState() === 1) startCamera();
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
                        if (html5QrCode.getState() === 3) html5QrCode.resume();
                        else if (html5QrCode.getState() === 1) startCamera();
                    });
                } else if (data.status === 'warning') {
                    isProcessing = false;
                    swalConfig.title = 'แจ้งเตือน!'; swalConfig.text = data.message; swalConfig.icon = 'warning'; swalConfig.confirmButtonColor = '#f59e0b'; swalConfig.confirmButtonText = 'ตกลง';
                    Swal.fire(swalConfig).then(() => { 
                        document.getElementById('scan-status').innerText = 'พร้อมสแกน...';
                        document.getElementById('scan-status').className = 'text-sm font-bold text-green-500 animate-pulse';
                        if (html5QrCode.getState() === 3) html5QrCode.resume();
                        else if (html5QrCode.getState() === 1) startCamera();
                    });
                } else {
                    isProcessing = false;
                    swalConfig.title = 'ข้อผิดพลาด!'; swalConfig.text = data.message; swalConfig.icon = 'error'; swalConfig.confirmButtonColor = '#ef4444'; swalConfig.confirmButtonText = 'ลองใหม่';
                    Swal.fire(swalConfig).then(() => { 
                        document.getElementById('scan-status').innerText = 'พร้อมสแกน...';
                        document.getElementById('scan-status').className = 'text-sm font-bold text-green-500 animate-pulse';
                        if (html5QrCode.getState() === 3) html5QrCode.resume();
                        else if (html5QrCode.getState() === 1) startCamera();
                    });
                }
            })
            .catch(err => {
                isProcessing = false;
                Swal.fire('Error', 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'error').then(() => { 
                    if (html5QrCode.getState() === 3) html5QrCode.resume();
                    else if (html5QrCode.getState() === 1) startCamera();
                });
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

    function handleManualSubmit() {
        const val = manualInput.value.trim();
        if (val) {
            processQRCode(val);
            manualInput.value = '';
        }
    }

    manualInput.addEventListener('keydown', e => {
        if (e.key === 'Enter') handleManualSubmit();
    });
    btnSubmitManual.addEventListener('click', handleManualSubmit);
});
</script>
</body>
</html>
