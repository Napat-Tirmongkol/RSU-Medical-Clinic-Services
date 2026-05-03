<?php
// asset/admin/scan.php — Mobile QR scanner สำหรับเดินตรวจนับ + เปิดดูครุภัณฑ์
require_once __DIR__ . '/../includes/check_session.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = db();

// ค้นหารอบตรวจนับที่ active (สำหรับ scan-to-mark mode)
$activeTake = null;
try {
    $activeTake = $pdo->query("
        SELECT id, name, scope_location_id, scope_category_id
        FROM asset_stock_takes
        WHERE status = 'in_progress'
        ORDER BY id DESC LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* ignore */ }

// อนุญาต force takeId via ?take=
$takeId = (int)($_GET['take'] ?? 0);
if ($takeId > 0) {
    $stmt = $pdo->prepare("SELECT id, name FROM asset_stock_takes WHERE id = ? AND status='in_progress'");
    $stmt->execute([$takeId]);
    $activeTake = $stmt->fetch(PDO::FETCH_ASSOC);
}

$page_title   = 'สแกนครุภัณฑ์';
$current_page = 'scan';
include __DIR__ . '/../includes/header.php';
?>

<div class="flex items-center justify-between mb-4 flex-wrap gap-2">
    <div>
        <h2 class="asset-sec-title">สแกน QR / Barcode</h2>
        <p class="text-sm text-slate-500 mt-1 ml-[14px]">
            <?php if ($activeTake): ?>
                สแกนแล้วบันทึก "เจอ" รอบ <strong>"<?= htmlspecialchars($activeTake['name']) ?>"</strong> อัตโนมัติ
            <?php else: ?>
                สแกนแล้วเปิดดูรายละเอียดครุภัณฑ์
            <?php endif; ?>
        </p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <!-- Scanner card -->
    <div class="asset-card overflow-hidden">
        <div id="scan-viewport" class="bg-slate-900" style="aspect-ratio: 4/3; position: relative;">
            <div id="reader" style="width: 100%; height: 100%;"></div>
            <div id="scan-overlay" class="absolute inset-0 flex items-center justify-center pointer-events-none">
                <div class="border-4 border-[#2e9e63] rounded-2xl"
                     style="width: 70%; aspect-ratio: 1; box-shadow: 0 0 0 200vmax rgba(0,0,0,0.4);"></div>
            </div>
            <div id="scan-status"
                 class="absolute top-3 left-3 right-3 text-center text-white text-sm font-semibold"
                 style="text-shadow: 0 1px 4px rgba(0,0,0,0.6);">
                กำลังเตรียมกล้อง...
            </div>
        </div>
        <div class="p-4 flex items-center gap-2 flex-wrap">
            <button id="btn-start" class="btn-asset btn-asset-primary">
                <i class="fas fa-camera"></i> เริ่มสแกน
            </button>
            <button id="btn-stop" class="btn-asset btn-asset-ghost" hidden>
                <i class="fas fa-stop"></i> หยุด
            </button>
            <button id="btn-flip" class="btn-asset btn-asset-secondary" hidden title="สลับกล้องหน้า/หลัง">
                <i class="fas fa-camera-rotate"></i>
            </button>
            <span id="scan-hint" class="text-xs text-slate-500 ml-auto">เปิดในเบราว์เซอร์มือถือเพื่อใช้กล้อง</span>
        </div>
    </div>

    <!-- Manual entry + last result -->
    <div class="asset-card p-5">
        <h3 class="font-bold text-slate-700 mb-3"><i class="fas fa-keyboard text-[#2e9e63]"></i> หรือพิมพ์รหัสครุภัณฑ์</h3>
        <form id="manual-form" class="flex items-center gap-2 mb-4">
            <input type="text" id="manual-code" class="asset-input" placeholder="AST-2026-0001"
                   inputmode="text" autocapitalize="characters" autocomplete="off">
            <button type="submit" class="btn-asset btn-asset-primary">
                <i class="fas fa-arrow-right"></i> ค้นหา
            </button>
        </form>

        <h3 class="font-bold text-slate-700 mb-3"><i class="fas fa-clock-rotate-left text-[#2e9e63]"></i> ผลล่าสุด</h3>
        <div id="recent-scans" class="space-y-2 text-sm">
            <p class="text-slate-400 text-center py-6">ยังไม่มีการสแกน</p>
        </div>
    </div>
</div>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
(function () {
    const ACTIVE_TAKE = <?= $activeTake ? (int)$activeTake['id'] : 'null' ?>;
    const BASE        = window.location.origin + window.location.pathname.replace(/\/asset\/.*/, '/asset/');

    const reader   = document.getElementById('reader');
    const status   = document.getElementById('scan-status');
    const btnStart = document.getElementById('btn-start');
    const btnStop  = document.getElementById('btn-stop');
    const btnFlip  = document.getElementById('btn-flip');
    const recentEl = document.getElementById('recent-scans');
    const manualForm = document.getElementById('manual-form');
    const manualCode = document.getElementById('manual-code');

    let qr = null;
    let cameras = [];
    let curCamIdx = 0;
    let lastScanned = '';
    let lastTime = 0;

    async function startScan() {
        try {
            cameras = await Html5Qrcode.getCameras();
            if (!cameras.length) {
                status.textContent = 'ไม่พบกล้องบนอุปกรณ์';
                return;
            }
            // Prefer back camera
            const backCam = cameras.find(c => /back|rear|environment/i.test(c.label));
            if (backCam) curCamIdx = cameras.indexOf(backCam);

            qr = new Html5Qrcode('reader');
            await qr.start(
                cameras[curCamIdx].id,
                { fps: 10, qrbox: { width: 250, height: 250 } },
                onScanSuccess,
                () => {} // ignore decode errors (fires every frame)
            );
            status.textContent = 'หันกล้องไปที่ป้าย QR';
            btnStart.hidden = true;
            btnStop.hidden  = false;
            if (cameras.length > 1) btnFlip.hidden = false;
        } catch (e) {
            status.textContent = 'เปิดกล้องไม่ได้ — ' + (e.message || e);
        }
    }

    async function stopScan() {
        if (qr) {
            try { await qr.stop(); await qr.clear(); } catch (e) {}
            qr = null;
        }
        btnStart.hidden = false;
        btnStop.hidden  = true;
        btnFlip.hidden  = true;
        status.textContent = 'หยุดแล้ว — กดเริ่มสแกน';
    }

    async function flipCamera() {
        curCamIdx = (curCamIdx + 1) % cameras.length;
        await stopScan();
        await startScan();
    }

    function onScanSuccess(decodedText) {
        // De-duplicate within 2 seconds
        const now = Date.now();
        if (decodedText === lastScanned && (now - lastTime) < 2000) return;
        lastScanned = decodedText;
        lastTime = now;

        // Vibration (mobile only)
        if (navigator.vibrate) navigator.vibrate(60);

        handleCode(decodedText);
    }

    async function handleCode(code) {
        code = (code || '').trim().toUpperCase();
        if (!code) return;
        status.textContent = 'พบรหัส: ' + code;

        if (ACTIVE_TAKE) {
            // Mark found in active stock-take
            try {
                const fd = new FormData();
                fd.append('csrf_token', window.ASSET_CSRF);
                fd.append('asset_code', code);
                fd.append('stock_take_id', ACTIVE_TAKE);
                fd.append('status', 'found');

                const r = await fetch('ajax/scan_mark.php', { method: 'POST', body: fd });
                const data = await r.json();
                appendRecent(code, data);
                if (data.ok && navigator.vibrate) navigator.vibrate([50, 50, 50]);
            } catch (e) {
                appendRecent(code, { ok: false, message: 'เน็ตขัดข้อง' });
            }
        } else {
            // No active take → open asset_view
            try {
                const r = await fetch('ajax/scan_lookup.php?code=' + encodeURIComponent(code));
                const data = await r.json();
                if (data.ok) {
                    appendRecent(code, { ok: true, name: data.name, redirect: 'admin/asset_view.php?id=' + data.id });
                    setTimeout(() => { window.location.href = 'admin/asset_view.php?id=' + data.id; }, 700);
                } else {
                    appendRecent(code, { ok: false, message: 'ไม่พบครุภัณฑ์รหัสนี้' });
                }
            } catch (e) {
                appendRecent(code, { ok: false, message: 'เน็ตขัดข้อง' });
            }
        }
    }

    function appendRecent(code, data) {
        const wrap = document.createElement('div');
        const ok = !!data.ok;
        wrap.className = 'flex items-center gap-3 p-3 rounded-xl border ' +
                        (ok ? 'bg-[#f0faf4] border-[#c7e8d5]' : 'bg-rose-50 border-rose-200');
        wrap.innerHTML = `
            <i class="fas ${ok ? 'fa-circle-check text-[#2e9e63]' : 'fa-circle-xmark text-rose-600'} text-xl"></i>
            <div class="flex-1 min-w-0">
                <div class="font-mono text-xs text-slate-700">${code}</div>
                <div class="text-sm font-bold ${ok ? 'text-slate-800' : 'text-rose-700'} truncate">
                    ${data.name || data.message || (ok ? 'บันทึก "เจอ" สำเร็จ' : 'ผิดพลาด')}
                </div>
            </div>
            <span class="text-[10px] text-slate-400">${new Date().toLocaleTimeString('th-TH', {hour:'2-digit',minute:'2-digit'})}</span>
        `;
        // Replace placeholder if first
        if (recentEl.querySelector('p.text-slate-400')) recentEl.innerHTML = '';
        recentEl.prepend(wrap);
        // Keep only 10
        while (recentEl.children.length > 10) recentEl.removeChild(recentEl.lastChild);
    }

    btnStart.addEventListener('click', startScan);
    btnStop.addEventListener('click', stopScan);
    btnFlip.addEventListener('click', flipCamera);
    manualForm.addEventListener('submit', (e) => {
        e.preventDefault();
        if (manualCode.value.trim()) handleCode(manualCode.value.trim());
        manualCode.value = '';
    });

    // Auto-start if mobile + camera permission likely
    if (/Mobi|Android/i.test(navigator.userAgent)) {
        // Don't auto-start — wait for user gesture (browser policy)
    }

    window.addEventListener('beforeunload', () => { if (qr) stopScan(); });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
