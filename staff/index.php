<?php
// staff/index.php
session_start();
if (!isset($_SESSION['staff_logged_in']) || $_SESSION['staff_logged_in'] !== true) {
    header('Location: login.php');
    exit;
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
        <a href="logout.php" class="bg-red-50 text-red-500 px-4 py-2 rounded-xl text-xs font-bold hover:bg-red-100 transition-colors">
            ออกจากระบบ
        </a>
    </div>
</div>

<div class="max-w-md mx-auto p-5 mt-4">
    <div class="bg-white p-4 rounded-3xl shadow-lg border border-gray-100 relative overflow-hidden" id="scanner-container">
        <div id="reader" class="w-full rounded-2xl overflow-hidden bg-black relative"></div>
        <div class="mt-6 text-center pb-2">
            <p id="scan-status" class="text-sm font-bold text-[#0052CC] animate-pulse">กำลังรอกล้อง...</p>
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

    // ฟังก์ชันเช็คอิน
    function processQRCode(decodedText) {
        if (isProcessing) return; 
        isProcessing = true;
        
        document.getElementById('scan-status').innerText = 'กำลังตรวจสอบข้อมูล...';
        document.getElementById('scan-status').className = 'text-sm font-bold text-orange-500 animate-pulse';

        const formData = new FormData();
        formData.append('qr_data', decodedText);
        formData.append('csrf_token', '<?= get_csrf_token() ?>');

        fetch('ajax_scan_checkin.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                let swalConfig = { allowOutsideClick: false, customClass: { title: 'font-prompt', popup: 'font-prompt rounded-3xl' }};
                
                if (data.status === 'success') {
                    swalConfig.title = 'เช็คอินสำเร็จ!';
                    swalConfig.html = `<div class="text-left bg-gray-50 p-4 rounded-xl mt-2 border border-gray-100"><p class="text-sm text-gray-500 mb-1">ผู้เข้าร่วม:</p><p class="font-bold text-lg text-gray-900 mb-3">${data.data.name}</p><p class="text-sm text-gray-500 mb-1">กิจกรรม:</p><p class="font-bold text-[#0052CC]">${data.data.campaign}</p></div>`;
                    swalConfig.icon = 'success'; swalConfig.confirmButtonColor = '#0052CC'; swalConfig.confirmButtonText = 'สแกนคิวถัดไป';
                } else if (data.status === 'warning') {
                    swalConfig.title = 'แจ้งเตือน!'; swalConfig.text = data.message; swalConfig.icon = 'warning'; swalConfig.confirmButtonColor = '#f59e0b'; swalConfig.confirmButtonText = 'ตกลง';
                } else {
                    swalConfig.title = 'ข้อผิดพลาด!'; swalConfig.text = data.message; swalConfig.icon = 'error'; swalConfig.confirmButtonColor = '#ef4444'; swalConfig.confirmButtonText = 'ลองใหม่';
                }
                
                Swal.fire(swalConfig).then(() => { 
                    isProcessing = false;
                    document.getElementById('scan-status').innerText = 'พร้อมสแกน...';
                    document.getElementById('scan-status').className = 'text-sm font-bold text-green-500 animate-pulse';
                    html5QrCode.resume(); // เริ่มสแกนต่อ
                });
            })
            .catch(err => Swal.fire('Error', 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'error').then(() => { 
                isProcessing = false;
                html5QrCode.resume();
            }));
    }

    // เริ่มเปิดกล้อง โดยบังคับใช้กล้องหลัง (environment)
    html5QrCode.start(
        { facingMode: "environment" }, 
        { fps: 10, qrbox: { width: 250, height: 250 }, aspectRatio: 1.0 },
        (decodedText, decodedResult) => {
            html5QrCode.pause(); // หยุดกล้องตอนสแกนติด
            processQRCode(decodedText);
        },
        (errorMessage) => {
            // ไม่ต้องทำอะไร ปล่อยให้มันหา QR ต่อไป
        }
    ).catch((err) => {
        console.error("Camera error", err);
        document.getElementById('scan-status').innerText = 'ไม่สามารถเปิดกล้องได้';
        document.getElementById('scan-status').className = 'text-sm font-bold text-red-500';
    });
});
</script>
</body>
</html>