<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

// โหลดสถานะ maintenance ปัจจุบัน
$mFile = __DIR__ . '/../config/maintenance.json';
$mData = (file_exists($mFile) && ($d = json_decode(file_get_contents($mFile), true))) ? $d : [];

$projects = [
    [
        'key'         => 'e_campaign',
        'title'       => 'e-Campaign',
        'desc'        => 'ระบบจองและลงทะเบียนกิจกรรมสำหรับ User (user/ pages)',
        'icon'        => 'fa-bullhorn',
        'icon_color'  => '#2563eb',
        'icon_bg'     => '#eff6ff',
    ],
    [
        'key'         => 'e_borrow',
        'title'       => 'e-Borrow & Inventory',
        'desc'        => 'ระบบยืม-คืนอุปกรณ์ทางการแพทย์ (archive/e_Borrow/)',
        'icon'        => 'fa-toolbox',
        'icon_color'  => '#475569',
        'icon_bg'     => '#f1f5f9',
    ],
];
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

        /* Toggle Switch */
        .toggle-wrap { display:flex; align-items:center; gap:12px; flex-shrink:0; }
        .toggle { position:relative; width:52px; height:28px; cursor:pointer; }
        .toggle input { opacity:0; width:0; height:0; position:absolute; }
        .toggle-track {
            position:absolute; inset:0;
            background:#e2e8f0;
            border-radius:99px;
            transition:background .25s;
        }
        .toggle input:checked ~ .toggle-track { background:#2e9e63; }
        .toggle-thumb {
            position:absolute; top:3px; left:3px;
            width:22px; height:22px;
            background:#fff;
            border-radius:50%;
            box-shadow:0 1px 4px rgba(0,0,0,.2);
            transition:transform .25s;
        }
        .toggle input:checked ~ .toggle-thumb { transform:translateX(24px); }

        /* Status badge */
        .status-badge {
            display:inline-flex; align-items:center; gap:5px;
            padding:3px 10px; border-radius:99px;
            font-size:11px; font-weight:700;
            transition:all .2s;
        }
        .status-badge.on  { background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; }
        .status-badge.off { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }
        .status-dot { width:7px; height:7px; border-radius:50%; }
        .status-badge.on  .status-dot { background:#22c55e; animation:pulse 1.5s infinite; }
        .status-badge.off .status-dot { background:#ef4444; }
        @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.3} }

        /* Toast */
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

    <!-- Header -->
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

    <!-- Body -->
    <div class="max-w-[1280px] mx-auto px-5 md:px-8 py-10 space-y-8">

        <!-- Page Title -->
        <div class="au">
            <h1 class="text-2xl sm:text-3xl font-black text-gray-900 flex items-center gap-3">
                <span class="w-2 h-8 rounded-full flex-shrink-0" style="background:linear-gradient(180deg,#7c3aed,#a78bfa)"></span>
                Admin Tool
            </h1>
            <p class="text-sm text-gray-400 mt-2 ml-5">เครื่องมือจัดการและตั้งค่าระบบ</p>
        </div>

        <!-- Maintenance Section -->
        <section class="au d1">
            <div class="sec-title mb-5">
                <i class="fa-solid fa-toggle-on text-violet-500"></i>
                Maintenance Mode
            </div>

            <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 flex items-start gap-3 mb-6 text-sm text-amber-700">
                <i class="fa-solid fa-triangle-exclamation mt-0.5 flex-shrink-0"></i>
                <span>เมื่อปิดระบบ <strong>User</strong> ที่เข้ามาจะเห็นหน้า "ระบบอยู่ในขั้นตอนการปรับปรุง" ทันที — Admin ยังเข้า Panel ได้ปกติ</span>
            </div>

            <div class="space-y-4">
                <?php foreach ($projects as $p):
                    $isActive = $mData[$p['key']] ?? true;
                ?>
                <div class="bg-white rounded-2xl border border-gray-100 p-5 flex items-center gap-4 shadow-sm hover:shadow-md transition-shadow"
                     id="card-<?= $p['key'] ?>">

                    <!-- Icon -->
                    <div class="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0"
                         style="background:<?= $p['icon_bg'] ?>; color:<?= $p['icon_color'] ?>;">
                        <i class="fa-solid <?= $p['icon'] ?> text-base"></i>
                    </div>

                    <!-- Info -->
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

                    <!-- Toggle -->
                    <div class="toggle-wrap">
                        <span class="text-xs font-bold text-gray-400 hidden sm:block">ปิด</span>
                        <label class="toggle" title="<?= $p['title'] ?>">
                            <input type="checkbox"
                                   data-project="<?= $p['key'] ?>"
                                   <?= $isActive ? 'checked' : '' ?>
                                   onchange="toggleMaintenance(this)">
                            <div class="toggle-track"></div>
                            <div class="toggle-thumb"></div>
                        </label>
                        <span class="text-xs font-bold text-gray-400 hidden sm:block">เปิด</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

    </div>

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
        const active  = input.checked;   // true = เปิดใช้งาน

        // อัปเดต UI ทันที
        const badge = document.getElementById('badge-' + project);
        badge.className = 'status-badge ' + (active ? 'on' : 'off');
        badge.innerHTML = `<span class="status-dot"></span>${active ? 'เปิดใช้งาน' : 'ปรับปรุง'}`;

        const fd = new FormData();
        fd.append('action',  'set');
        fd.append('project', project);
        fd.append('active',  active ? '1' : '0');
        fd.append('csrf_token', CSRF);

        fetch('ajax_maintenance.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.ok) {
                    showToast(active
                        ? `[สำเร็จ] ${project} เปิดใช้งานแล้ว`
                        : `[แจ้งเตือน] ${project} ปิดปรับปรุงแล้ว — User จะเห็นหน้าปรับปรุง`,
                        active ? 'success' : 'error'
                    );
                } else {
                    showToast('เกิดข้อผิดพลาด: ' + (d.message || ''), 'error');
                    input.checked = !active; // rollback
                }
            })
            .catch(() => {
                showToast('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'error');
                input.checked = !active;
            });
    }
    </script>

</body>
</html>
