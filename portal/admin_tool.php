<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

// ── Maintenance status ────────────────────────────────────────────────────────
$mFile = __DIR__ . '/../config/maintenance.json';
$mData = (file_exists($mFile) && ($d = json_decode(file_get_contents($mFile), true))) ? $d : [];

$projects = [
    [
        'key'        => 'e_campaign',
        'title'      => 'e-Campaign',
        'desc'       => 'ระบบจองและลงทะเบียนกิจกรรมสำหรับ User',
        'icon'       => 'fa-bullhorn',
        'icon_color' => '#2563eb',
        'icon_bg'    => '#eff6ff',
    ],
    [
        'key'        => 'e_borrow',
        'title'      => 'e-Borrow & Inventory',
        'desc'       => 'ระบบยืม-คืนอุปกรณ์ทางการแพทย์',
        'icon'       => 'fa-toolbox',
        'icon_color' => '#475569',
        'icon_bg'    => '#f1f5f9',
    ],
];

// ── Quick log stats ───────────────────────────────────────────────────────────
$todayErrors   = 0;
$totalErrors   = 0;
$recentActivity = 0;
$latestError   = null;
$latestLog     = null;

try {
    $pdo = db();

    try {
        $todayErrors = (int)$pdo->query(
            "SELECT COUNT(*) FROM sys_error_logs WHERE level='error' AND DATE(created_at)=CURDATE()"
        )->fetchColumn();
        $totalErrors = (int)$pdo->query(
            "SELECT COUNT(*) FROM sys_error_logs"
        )->fetchColumn();
        $latestError = $pdo->query(
            "SELECT level, message, created_at FROM sys_error_logs ORDER BY created_at DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { /* table may not exist yet */ }

    try {
        $recentActivity = (int)$pdo->query(
            "SELECT COUNT(*) FROM sys_activity_logs WHERE timestamp >= NOW() - INTERVAL 24 HOUR"
        )->fetchColumn();
        $latestLog = $pdo->query(
            "SELECT l.action, l.description, l.timestamp,
                    COALESCE(a.full_name, s.full_name, 'System') as actor_name
             FROM sys_activity_logs l
             LEFT JOIN sys_admins a ON l.user_id = a.id
             LEFT JOIN sys_staff  s ON l.user_id = s.id
             ORDER BY l.timestamp DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { /* table may not exist yet */ }

} catch (Exception $e) { /* db unavailable */ }

$allOnline = true;
foreach ($projects as $p) {
    if (($mData[$p['key']] ?? true) === false) { $allOnline = false; break; }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Tool — RSU Medical Clinic Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <style>
        * { font-family: 'Prompt', sans-serif; }

        /* ── Toggle Switch ─────────────────────────────────────────────────── */
        .toggle-wrap { display:flex; align-items:center; gap:10px; flex-shrink:0; }
        .toggle { position:relative; width:50px; height:26px; cursor:pointer; }
        .toggle input { opacity:0; width:0; height:0; position:absolute; }
        .toggle-track {
            position:absolute; inset:0;
            background:#e2e8f0; border-radius:99px;
            transition:background .25s;
        }
        .toggle input:checked ~ .toggle-track { background:#2e9e63; }
        .toggle-thumb {
            position:absolute; top:3px; left:3px;
            width:20px; height:20px;
            background:#fff; border-radius:50%;
            box-shadow:0 1px 4px rgba(0,0,0,.2);
            transition:transform .25s;
        }
        .toggle input:checked ~ .toggle-thumb { transform:translateX(24px); }

        /* ── Status badge ──────────────────────────────────────────────────── */
        .status-badge {
            display:inline-flex; align-items:center; gap:5px;
            padding:2px 9px; border-radius:99px;
            font-size:10px; font-weight:700;
            transition:all .2s;
        }
        .status-badge.on  { background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; }
        .status-badge.off { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }
        .status-dot { width:6px; height:6px; border-radius:50%; flex-shrink:0; }
        .status-badge.on  .status-dot { background:#22c55e; animation:pulse 1.5s infinite; }
        .status-badge.off .status-dot { background:#ef4444; }
        @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.3} }

        /* ── Log card ──────────────────────────────────────────────────────── */
        .log-card {
            background:#fff; border-radius:20px;
            border:1.5px solid #e5e7eb;
            padding:24px;
            transition:border-color .2s, box-shadow .2s;
            text-decoration:none; display:block; color:inherit;
        }
        .log-card:hover {
            border-color:#c7e8d5;
            box-shadow:0 6px 24px rgba(46,158,99,.10);
        }

        /* ── Toast ─────────────────────────────────────────────────────────── */
        #toast {
            position:fixed; bottom:24px; right:24px; z-index:9999;
            padding:12px 20px; border-radius:14px;
            font-size:13px; font-weight:700;
            box-shadow:0 4px 20px rgba(0,0,0,.12);
            transform:translateY(80px); opacity:0;
            transition:all .3s cubic-bezier(.16,1,.3,1);
            pointer-events:none;
        }
        #toast.show { transform:translateY(0); opacity:1; }
        #toast.success { background:#f0fdf4; color:#16a34a; border:1.5px solid #bbf7d0; }
        #toast.error   { background:#fef2f2; color:#dc2626; border:1.5px solid #fecaca; }
    </style>
</head>
<body>

<!-- ── Header ─────────────────────────────────────────────────────────────── -->
<header class="portal-header">
    <div class="max-w-[1280px] mx-auto px-4 sm:px-6 py-3 flex items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <a href="index.php"
               class="w-9 h-9 flex items-center justify-center rounded-xl border text-sm transition-all hover:bg-gray-50"
               style="border-color:#c7e8d5; color:#2e9e63;">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <div class="w-9 h-9 rounded-xl flex items-center justify-center text-violet-600" style="background:#ede9fe;">
                <i class="fa-solid fa-screwdriver-wrench text-sm"></i>
            </div>
            <div>
                <div class="font-black text-gray-900 text-[15px] leading-none">Admin Tool</div>
                <div class="text-[10px] font-bold tracking-[.15em] uppercase opacity-60 mt-0.5" style="color:#2e9e63">RSU Medical Clinic Portal</div>
            </div>
        </div>
        <div class="user-pill">
            <div class="user-avatar"><i class="fa-solid fa-user-shield text-[10px]"></i></div>
            <div class="hidden sm:block">
                <div class="text-[9px] font-bold text-gray-400 uppercase tracking-wider leading-none mb-0.5">
                    <?= htmlspecialchars(ucfirst($_SESSION['admin_role'] ?? 'admin')) ?>
                </div>
                <div class="text-xs font-black text-gray-900 leading-none">
                    <?= htmlspecialchars($_SESSION['admin_username'] ?? 'Admin') ?>
                </div>
            </div>
        </div>
    </div>
</header>

<!-- ── Body ───────────────────────────────────────────────────────────────── -->
<div class="max-w-[1280px] mx-auto px-5 md:px-8 py-10 space-y-10">

    <!-- Page title -->
    <div class="au">
        <h1 class="text-2xl sm:text-3xl font-black text-gray-900 flex items-center gap-3">
            <span class="w-2 h-8 rounded-full flex-shrink-0" style="background:linear-gradient(180deg,#7c3aed,#a78bfa)"></span>
            Admin Tool
        </h1>
        <p class="text-sm text-gray-400 mt-2 ml-5">จัดการระบบ, ควบคุมสถานะโปรเจค, และตรวจสอบบันทึกระบบ</p>
    </div>

    <!-- ── System Status Banner ─────────────────────────────────────────────── -->
    <div class="au d1 rounded-2xl border px-5 py-4 flex items-center gap-4
        <?= $allOnline
            ? 'bg-green-50 border-green-200'
            : 'bg-amber-50 border-amber-200' ?>">
        <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0
            <?= $allOnline ? 'bg-green-100 text-green-600' : 'bg-amber-100 text-amber-600' ?>">
            <i class="fa-solid <?= $allOnline ? 'fa-circle-check' : 'fa-triangle-exclamation' ?> text-base"></i>
        </div>
        <div class="flex-1 min-w-0">
            <div class="font-bold text-sm <?= $allOnline ? 'text-green-800' : 'text-amber-800' ?>">
                <?= $allOnline ? 'ระบบทุกโปรเจคพร้อมใช้งาน' : 'มีโปรเจคที่ปิดปรับปรุงอยู่' ?>
            </div>
            <div class="text-xs <?= $allOnline ? 'text-green-600' : 'text-amber-600' ?> mt-0.5">
                <?= $allOnline
                    ? 'User ทุกคนสามารถเข้าใช้งานได้ตามปกติ'
                    : 'User จะเห็นหน้า "ระบบอยู่ในขั้นตอนการปรับปรุง" สำหรับโปรเจคที่ปิดไว้' ?>
            </div>
        </div>
        <?php if ($todayErrors > 0): ?>
        <a href="../admin/error_logs.php"
           class="flex items-center gap-1.5 px-3 py-1.5 bg-red-100 text-red-700 border border-red-200 rounded-xl text-xs font-bold hover:bg-red-200 transition-colors flex-shrink-0">
            <i class="fa-solid fa-bug text-[10px]"></i>
            <?= $todayErrors ?> error วันนี้
        </a>
        <?php endif; ?>
    </div>

    <!-- ── Two-column layout ─────────────────────────────────────────────────── -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">

        <!-- ── Left: Maintenance Mode ────────────────────────────────────────── -->
        <section class="au d1 space-y-5">
            <div class="sec-title">
                <i class="fa-solid fa-toggle-on text-violet-500"></i>
                Maintenance Mode
            </div>

            <div class="bg-amber-50 border border-amber-200 rounded-xl p-3.5 flex items-start gap-2.5 text-xs text-amber-700">
                <i class="fa-solid fa-triangle-exclamation mt-0.5 flex-shrink-0"></i>
                <span>เมื่อปิดระบบ <strong>User</strong> จะเห็นหน้า "ระบบอยู่ในขั้นตอนการปรับปรุง" ทันที — Admin ยังเข้าได้ปกติ</span>
            </div>

            <div class="space-y-3">
                <?php foreach ($projects as $p):
                    $isActive = $mData[$p['key']] ?? true;
                ?>
                <div class="bg-white rounded-2xl border border-gray-100 p-4 flex items-center gap-4 shadow-sm hover:shadow-md transition-shadow"
                     id="card-<?= $p['key'] ?>">

                    <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0"
                         style="background:<?= $p['icon_bg'] ?>; color:<?= $p['icon_color'] ?>;">
                        <i class="fa-solid <?= $p['icon'] ?> text-sm"></i>
                    </div>

                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 mb-0.5">
                            <span class="font-black text-gray-900 text-sm"><?= htmlspecialchars($p['title']) ?></span>
                            <span class="status-badge <?= $isActive ? 'on' : 'off' ?>" id="badge-<?= $p['key'] ?>">
                                <span class="status-dot"></span>
                                <?= $isActive ? 'เปิดใช้งาน' : 'ปรับปรุง' ?>
                            </span>
                        </div>
                        <p class="text-xs text-gray-400"><?= htmlspecialchars($p['desc']) ?></p>
                    </div>

                    <div class="toggle-wrap">
                        <span class="text-[10px] font-bold text-gray-400 hidden sm:block">ปิด</span>
                        <label class="toggle" title="<?= $p['title'] ?>">
                            <input type="checkbox"
                                   data-project="<?= $p['key'] ?>"
                                   <?= $isActive ? 'checked' : '' ?>
                                   onchange="toggleMaintenance(this)">
                            <div class="toggle-track"></div>
                            <div class="toggle-thumb"></div>
                        </label>
                        <span class="text-[10px] font-bold text-gray-400 hidden sm:block">เปิด</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- ── Right: System Logs ─────────────────────────────────────────────── -->
        <section class="au d1 space-y-5">
            <div class="sec-title">
                <i class="fa-solid fa-file-lines text-slate-500"></i>
                System Logs
            </div>

            <!-- Error Logs card -->
            <a href="../admin/error_logs.php" class="log-card group">
                <div class="flex items-start gap-4">
                    <div class="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0
                                <?= $todayErrors > 0 ? 'bg-red-100 text-red-600' : 'bg-gray-100 text-gray-500' ?>
                                group-hover:scale-105 transition-transform">
                        <i class="fa-solid fa-bug text-base"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between gap-2 mb-1">
                            <span class="font-black text-gray-900 text-sm">Error Logs</span>
                            <div class="flex items-center gap-2 flex-shrink-0">
                                <?php if ($todayErrors > 0): ?>
                                <span class="text-[10px] font-bold px-2 py-0.5 bg-red-100 text-red-700 rounded-full border border-red-200">
                                    <?= $todayErrors ?> วันนี้
                                </span>
                                <?php endif; ?>
                                <span class="text-[10px] font-bold px-2 py-0.5 bg-gray-100 text-gray-600 rounded-full">
                                    <?= number_format($totalErrors) ?> รวม
                                </span>
                            </div>
                        </div>
                        <p class="text-xs text-gray-400 mb-3">บันทึกข้อผิดพลาดและ PHP errors ทั้งหมดในระบบ</p>
                        <?php if ($latestError): ?>
                        <div class="bg-gray-50 rounded-xl p-3 border border-gray-100">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="text-[9px] font-bold uppercase tracking-wider
                                    <?= $latestError['level']==='error' ? 'text-red-500' : ($latestError['level']==='warning' ? 'text-amber-500' : 'text-blue-500') ?>">
                                    <?= $latestError['level'] ?>
                                </span>
                                <span class="text-[9px] text-gray-400"><?= date('d/m/y H:i', strtotime($latestError['created_at'])) ?></span>
                            </div>
                            <p class="text-[11px] text-gray-600 line-clamp-1"><?= htmlspecialchars($latestError['message']) ?></p>
                        </div>
                        <?php else: ?>
                        <div class="flex items-center gap-2 text-xs text-green-600">
                            <i class="fa-solid fa-circle-check text-green-500"></i>
                            ไม่พบ error log — ระบบทำงานปกติ
                        </div>
                        <?php endif; ?>
                    </div>
                    <i class="fa-solid fa-arrow-right text-gray-300 group-hover:text-gray-500 group-hover:translate-x-0.5 transition-all text-sm flex-shrink-0 mt-1"></i>
                </div>
            </a>

            <!-- Activity Logs card -->
            <a href="../admin/activity_logs.php" class="log-card group">
                <div class="flex items-start gap-4">
                    <div class="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0
                                bg-blue-50 text-blue-600
                                group-hover:scale-105 transition-transform">
                        <i class="fa-solid fa-clock-rotate-left text-base"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between gap-2 mb-1">
                            <span class="font-black text-gray-900 text-sm">Activity Logs</span>
                            <?php if ($recentActivity > 0): ?>
                            <span class="text-[10px] font-bold px-2 py-0.5 bg-blue-100 text-blue-700 rounded-full border border-blue-200 flex-shrink-0">
                                <?= $recentActivity ?> กิจกรรม (24h)
                            </span>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs text-gray-400 mb-3">ติดตามทุกการเคลื่อนไหวและการเข้าถึงระบบ</p>
                        <?php if ($latestLog): ?>
                        <div class="bg-gray-50 rounded-xl p-3 border border-gray-100">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="text-[9px] font-bold uppercase tracking-wider text-blue-500">
                                    <?= htmlspecialchars($latestLog['action']) ?>
                                </span>
                                <span class="text-[9px] text-gray-400"><?= date('d/m/y H:i', strtotime($latestLog['timestamp'])) ?></span>
                            </div>
                            <p class="text-[11px] text-gray-600 line-clamp-1"><?= htmlspecialchars($latestLog['description']) ?></p>
                        </div>
                        <?php else: ?>
                        <div class="flex items-center gap-2 text-xs text-gray-400">
                            <i class="fa-solid fa-inbox text-gray-300"></i>
                            ไม่พบบันทึกกิจกรรม
                        </div>
                        <?php endif; ?>
                    </div>
                    <i class="fa-solid fa-arrow-right text-gray-300 group-hover:text-gray-500 group-hover:translate-x-0.5 transition-all text-sm flex-shrink-0 mt-1"></i>
                </div>
            </a>
        </section>

    </div><!-- /grid -->

</div><!-- /body -->

<!-- Toast -->
<div id="toast"></div>

<script>
const CSRF = <?= json_encode(get_csrf_token()) ?>;

function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'show ' + type;
    clearTimeout(t._tid);
    t._tid = setTimeout(() => { t.className = t.className.replace('show', '').trim(); }, 2800);
}

function toggleMaintenance(input) {
    const project = input.dataset.project;
    const active  = input.checked;

    const badge = document.getElementById('badge-' + project);
    badge.className = 'status-badge ' + (active ? 'on' : 'off');
    badge.innerHTML = `<span class="status-dot"></span>${active ? 'เปิดใช้งาน' : 'ปรับปรุง'}`;

    const fd = new FormData();
    fd.append('action',     'set');
    fd.append('project',    project);
    fd.append('active',     active ? '1' : '0');
    fd.append('csrf_token', CSRF);

    fetch('ajax_maintenance.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                updateStatusBanner();
                showToast(
                    active
                        ? `${project} เปิดใช้งานแล้ว`
                        : `${project} ปิดปรับปรุงแล้ว — User จะเห็นหน้าปรับปรุง`,
                    active ? 'success' : 'error'
                );
            } else {
                showToast('เกิดข้อผิดพลาด: ' + (d.message || ''), 'error');
                input.checked = !active;
                badge.className = 'status-badge ' + (!active ? 'on' : 'off');
                badge.innerHTML = `<span class="status-dot"></span>${!active ? 'เปิดใช้งาน' : 'ปรับปรุง'}`;
            }
        })
        .catch(() => {
            showToast('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'error');
            input.checked = !active;
            badge.className = 'status-badge ' + (!active ? 'on' : 'off');
            badge.innerHTML = `<span class="status-dot"></span>${!active ? 'เปิดใช้งาน' : 'ปรับปรุง'}`;
        });
}

function updateStatusBanner() {
    // Re-check all toggles to update the banner
    const checks = document.querySelectorAll('[data-project]');
    const allOn  = Array.from(checks).every(c => c.checked);
    const banner = document.querySelector('.au.d1.rounded-2xl');
    if (!banner) return;

    if (allOn) {
        banner.className = banner.className.replace(/bg-amber-\d+ border-amber-\d+/g, '').trim()
            + ' bg-green-50 border-green-200';
        banner.querySelector('.w-10').className = banner.querySelector('.w-10').className
            .replace(/bg-amber-\d+ text-amber-\d+/g, 'bg-green-100 text-green-600');
        banner.querySelector('.w-10 i').className = 'fa-solid fa-circle-check text-base';
        banner.querySelector('.font-bold').textContent = 'ระบบทุกโปรเจคพร้อมใช้งาน';
        banner.querySelector('.text-xs').textContent   = 'User ทุกคนสามารถเข้าใช้งานได้ตามปกติ';
    } else {
        banner.className = banner.className.replace(/bg-green-\d+ border-green-\d+/g, '').trim()
            + ' bg-amber-50 border-amber-200';
        banner.querySelector('.w-10').className = banner.querySelector('.w-10').className
            .replace(/bg-green-\d+ text-green-\d+/g, 'bg-amber-100 text-amber-600');
        banner.querySelector('.w-10 i').className = 'fa-solid fa-triangle-exclamation text-base';
        banner.querySelector('.font-bold').textContent = 'มีโปรเจคที่ปิดปรับปรุงอยู่';
        banner.querySelector('.text-xs').textContent   = 'User จะเห็นหน้า "ระบบอยู่ในขั้นตอนการปรับปรุง" สำหรับโปรเจคที่ปิดไว้';
    }
}
</script>

</body>
</html>
