<?php
/**
 * portal/index.php (v3.0 Dynamic & Scalable Edition)
 * Central Hub Dashboard สำหรับการจัดการระบบที่รองรับการขยายโปรเจกต์ในอนาคต
 */
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php'; // ตรวจสอบความปลอดภัย

$pdo = db();
$adminRole = $_SESSION['admin_role'] ?? 'admin'; // ตัวแปรบทบาทสำหรับเช็คสิทธิ์ (Mock role)

/**
 * 📊 (1) LIVE DATA & ROBUST STATS
 * ดึงสถิจริง พร้อมระบบป้องกันถ้าตารางในอนาคตยังไม่พร้อม
 */
$kpis = [
    'users'   => 0,
    'camps'   => 0,
    'borrows' => 0,
    'logs'    => 0
];

try {
    $kpis['users'] = (int)$pdo->query("SELECT COUNT(*) FROM sys_users")->fetchColumn();
    $kpis['camps'] = (int)$pdo->query("SELECT COUNT(*) FROM camp_list WHERE status = 'active'")->fetchColumn();
    
    // เช็คตารางยืมอุปกรณ์ (โมดูลเสริม)
    if ($pdo->query("SHOW TABLES LIKE 'borrow_records'")->rowCount() > 0) {
        $kpis['borrows'] = (int)$pdo->query("SELECT COUNT(*) FROM borrow_records WHERE approval_status = 'pending'")->fetchColumn();
    }
    
    // เช็คตารางกิจกรรม (โมดูลสถิติ)
    if ($pdo->query("SHOW TABLES LIKE 'activity_logs'")->rowCount() > 0) {
        $kpis['logs'] = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();
    }
} catch (PDOException $e) {
    error_log("Portal Stats Fetch Error: " . $e->getMessage());
}

/**
 * 🧩 (2) PROJECT CATALOG (SCALABLE STRUCTURE)
 * โครงสร้างอาเรย์สำหรับวนลูปโปรเจกต์ รองรับการเพิ่มโมดูลในอนาคตได้ทันที
 */
$projects = [
    [
        'id'            => 'identity_governance',
        'title'         => 'User & Access Management',
        'description'   => 'ศูนย์กลางจัดการข้อมูลผู้ใช้งานและควบคุมสิทธิ์การเข้าถึงระบบสำหรับเจ้าหน้าที่และแอดมินระดับสูง',
        'icon'          => 'fa-id-card-clip',
        'bg_color'      => 'bg-amber-50',
        'icon_color'    => 'text-amber-500',
        'border_color'  => 'border-amber-100',
        'allowed_roles' => ['admin', 'superadmin'],
        'badges'        => [ 'Central DB', 'Security Hub' ],
        'actions'       => array_filter([
            ['label' => 'Search Users',  'url' => 'users.php?layout=none',        'primary' => false],
            $adminRole === 'superadmin'
                ? ['label' => 'Manage Admins', 'url' => 'manage_admins.php?layout=none', 'primary' => true]
                : null,
        ])
    ],
    [
        'id'            => 'e_campaign',
        'title'         => 'e-Campaign',
        'description'   => 'ระบบจัดการแคมเปญ งานอบรม งานสแกนและการลงทะเบียนเข้าร่วมกิจกรรมแบบ Real-time',
        'icon'          => 'fa-bullhorn',
        'bg_color'      => 'bg-blue-50',
        'icon_color'    => 'text-blue-600',
        'border_color'  => 'border-blue-100',
        'allowed_roles' => ['admin', 'superadmin'],
        'badges'        => [ 'Campaigns', 'Activity' ],
        'actions'       => [
            ['label' => 'Open e-Campaign', 'url' => '../admin/index.php', 'primary' => true],
        ]
    ],
    [
        'id'            => 'e_borrow',
        'title'         => 'e-Borrow & Inventory',
        'description'   => 'ระบบยืม-คืนอุปกรณ์ทางการแพทย์และเวชภัณฑ์ (Archive Support) จัดการสต็อกและพัสดุกลาง',
        'icon'          => 'fa-toolbox',
        'bg_color'      => 'bg-slate-100',
        'icon_color'    => 'text-slate-700',
        'border_color'  => 'border-slate-200',
        'allowed_roles' => ['admin', 'superadmin'],
        'badges'        => [ 'Inventory', 'Asset Tracking' ],
        'actions'       => [
            ['label' => 'Manage Inventory', 'url' => '../archive/e_Borrow/admin/index.php', 'primary' => true],
        ]
    ],
    [
        'id'            => 'system_logs',
        'title'         => 'System Logs',
        'description'   => 'ติดตาม Error Log และ Activity Log ของระบบแบบ Real-time เพื่อตรวจสอบและแก้ไขปัญหาได้ทันที',
        'icon'          => 'fa-bug',
        'bg_color'      => 'bg-red-50',
        'icon_color'    => 'text-red-500',
        'border_color'  => 'border-red-100',
        'allowed_roles' => ['admin', 'superadmin'],
        'badges'        => [ 'Monitoring', 'Debug' ],
        'actions'       => [
            ['label' => 'Error Logs',    'url' => '../admin/error_logs.php',    'primary' => true],
            ['label' => 'Activity Logs', 'url' => '../admin/activity_logs.php', 'primary' => false],
        ]
    ],
    /**
     * ตัวอย่างการเพิ่มโปรเจกต์ในอนาคต:
     * เพียงแค่ก๊อปปี้บล็อกนี้แล้วเปลี่ยน URL/Icon ระบบจะวาดหน้า Layout ให้เองทันที
     */
    [
        'id'            => 'future_app',
        'title'         => 'Future Modules',
        'description'   => 'ระบบใหม่ที่กำลังอยู่ในระหว่างการพัฒนา เพื่อเสริมสร้างศักยภาพการจัดการข้อมูลในอนาคต',
        'icon'          => 'fa-plus-circle',
        'bg_color'      => 'bg-gray-50',
        'icon_color'    => 'text-gray-300',
        'border_color'  => 'border-gray-100',
        'allowed_roles' => ['superadmin'],
        'badges'        => [ 'Dev Stage' ],
        'actions'       => [
            ['label' => 'No actions yet', 'url' => '#', 'primary' => false],
        ]
    ]
];

/**
 * 🕒 (3) RECENT ACTIVITY FETCH
 * ดึงความเคลื่อนไหวล่าสุดจาก sys_activity_logs มาแสดงที่ Dashboard
 */
$recentActivity = [];
try {
    $sql = "SELECT l.action, l.description, l.timestamp as created_at, a.full_name as admin_name 
            FROM sys_activity_logs l
            LEFT JOIN sys_admins a ON l.user_id = a.id
            ORDER BY l.timestamp DESC 
            LIMIT 5";
    $recentActivity = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* silent */ }

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Management Smart Portal - Central Intelligence HUB</title>
    
    <!-- UI Framework & Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Prompt:wght@100;300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link rel="stylesheet" href="../assets/css/portal.css">
</head>
<body class="font-sans text-gray-800" style="min-height:100vh">

    <!-- Ambient background dots -->
    <div class="amb-dot" style="width:320px;height:320px;background:rgba(46,158,99,.1);top:5%;left:10%;--dur:16s;--delay:0s;--dx:40px;--dy:-30px"></div>
    <div class="amb-dot" style="width:240px;height:240px;background:rgba(77,201,138,.07);top:60%;right:8%;--dur:20s;--delay:-5s;--dx:-35px;--dy:25px"></div>
    <div class="amb-dot" style="width:180px;height:180px;background:rgba(59,186,122,.07);bottom:15%;left:30%;--dur:13s;--delay:-8s;--dx:25px;--dy:30px"></div>
    <div class="amb-dot" style="width:200px;height:200px;background:rgba(13,61,34,.05);top:35%;right:25%;--dur:17s;--delay:-3s;--dx:-20px;--dy:-25px"></div>
    <div class="amb-dot" style="width:150px;height:150px;background:rgba(110,231,183,.06);top:80%;left:55%;--dur:22s;--delay:-11s;--dx:30px;--dy:-20px"></div>

    <!-- ══════════════════ HEADER ══════════════════ -->
    <header class="portal-header au">
        <div class="max-w-[1280px] mx-auto px-6 py-3 flex items-center justify-between gap-4">
            <!-- Brand -->
            <div class="flex items-center gap-3">
                <div class="brand-icon"><i class="fa-solid fa-heart"></i></div>
                <div>
                    <div class="font-black text-gray-900 text-[17px] leading-none tracking-tight">Central HUB</div>
                    <div class="text-[10px] font-bold tracking-[.15em] uppercase opacity-70 mt-0.5" style="color:#2e9e63">RSU Medical Clinic Services</div>
                </div>
            </div>

            <!-- Right: user + logout -->
            <div class="flex items-center gap-3">

                <?php if ($adminRole === 'superadmin'): ?>
                <!-- Git Pull Button (Superadmin only) -->
                <button id="btnGitPull"
                        onclick="triggerGitPull()"
                        title="Pull โค้ดล่าสุดจาก Git"
                        style="display:flex;align-items:center;gap:6px;padding:6px 14px;border-radius:10px;border:1px solid #d1fae5;background:#f0fdf4;color:#16a34a;font-size:12px;font-weight:700;cursor:pointer;transition:all .2s;">
                    <i class="fa-solid fa-code-branch"></i>
                    <span>Git Pull</span>
                </button>
                <?php endif; ?>

                <div class="user-pill">
                    <div class="user-avatar"><i class="fa-solid fa-user-shield text-[11px]"></i></div>
                    <div class="hidden sm:block">
                        <div class="text-[9px] font-bold text-gray-400 uppercase tracking-wider leading-none mb-0.5">Admin</div>
                        <div class="text-xs font-black text-gray-900 leading-none"><?= htmlspecialchars($_SESSION['admin_username'] ?? 'Administrator') ?></div>
                    </div>
                </div>
                <a href="../admin/logout.php"
                   class="w-9 h-9 rounded-xl bg-red-50 text-red-400 hover:bg-red-500 hover:text-white flex items-center justify-center transition-all border border-red-100"
                   title="ออกจากระบบ">
                    <i class="fa-solid fa-power-off text-sm"></i>
                </a>
            </div>
        </div>
    </header>

    <!-- ══════════════════ PAGE BODY ══════════════════ -->
    <div class="max-w-[1280px] mx-auto px-5 md:px-8 py-8 space-y-8">

        <!-- KPI STRIP -->
        <section class="grid grid-cols-2 lg:grid-cols-4 gap-4 au d1">
            <!-- Total Members -->
            <div class="kpi-card">
                <div class="kpi-accent" style="background:linear-gradient(90deg,#f59e0b,#fbbf24)"></div>
                <div class="kpi-icon" style="background:#fffbeb; color:#d97706">
                    <i class="fa-solid fa-users"></i>
                </div>
                <div class="kpi-num text-gray-900" data-counter="<?= $kpis['users'] ?>">0</div>
                <div class="kpi-label">Total Members</div>
            </div>

            <!-- Running Campaigns -->
            <div class="kpi-card">
                <div class="kpi-accent" style="background:linear-gradient(90deg,#2e9e63,#6ee7b7)"></div>
                <div class="kpi-icon" style="background:#e8f8f0; color:#2e9e63">
                    <i class="fa-solid fa-bullhorn"></i>
                </div>
                <div class="kpi-num text-gray-900" data-counter="<?= $kpis['camps'] ?>">0</div>
                <div class="kpi-label">Active Campaigns</div>
            </div>

            <!-- Pending Borrows -->
            <div class="kpi-card">
                <div class="kpi-accent" style="background:linear-gradient(90deg,#ef4444,#fca5a5)"></div>
                <div class="kpi-icon" style="background:#fff1f2; color:#ef4444">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
                <div class="flex items-end gap-2">
                    <div class="kpi-num text-gray-900" data-counter="<?= $kpis['borrows'] ?>">0</div>
                    <?php if($kpis['borrows'] > 0): ?>
                        <span class="mb-1 px-1.5 py-0.5 bg-red-500 text-white text-[8px] font-black rounded-md leading-none animate-pulse">URGENT</span>
                    <?php endif; ?>
                </div>
                <div class="kpi-label">Borrow Requests</div>
            </div>

            <!-- System Health -->
            <div class="kpi-card">
                <div class="kpi-accent" style="background:linear-gradient(90deg,#10b981,#6ee7b7)"></div>
                <div class="kpi-icon" style="background:#ecfdf5; color:#059669">
                    <i class="fa-solid fa-heart-pulse"></i>
                </div>
                <div class="kpi-num" style="color:#059669; font-size:1.5rem">Healthy</div>
                <div class="kpi-label">System Status</div>
            </div>
        </section>

        <!-- MAIN GRID -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

            <!-- PROJECT CARDS (8/12) -->
            <section class="lg:col-span-8 au d2">
                <div class="sec-title mb-5">Applications</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <?php $cardIdx = 0; foreach($projects as $proj):
                        if (!in_array($adminRole, $proj['allowed_roles'])) continue;
                        $cardDelay = round(0.1 + $cardIdx * 0.12, 2);
                        $cardIdx++;
                    ?>
                    <div class="proj-card" style="animation-delay:<?= $cardDelay ?>s">
                        <!-- Card top row -->
                        <div class="flex items-start justify-between mb-4">
                            <div class="proj-card-icon <?= $proj['bg_color'] ?> <?= $proj['icon_color'] ?> <?= $proj['border_color'] ?>">
                                <i class="fa-solid <?= $proj['icon'] ?>"></i>
                            </div>
                            <div class="flex flex-wrap justify-end gap-1">
                                <?php foreach($proj['badges'] as $b): ?>
                                    <span class="proj-badge"><?= $b ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Title & description -->
                        <h3 class="text-[15px] font-black text-gray-900 mb-1.5 leading-tight"><?= $proj['title'] ?></h3>
                        <p class="text-[12px] text-gray-500 leading-relaxed mb-5 flex-1"><?= $proj['description'] ?></p>

                        <!-- Actions -->
                        <div class="flex gap-2 mt-auto">
                            <?php foreach($proj['actions'] as $act): ?>
                                <a href="<?= $act['url'] ?>" class="proj-action <?= $act['primary'] ? 'primary' : 'secondary' ?>">
                                    <?php if($act['primary']): ?><i class="fa-solid fa-arrow-up-right-from-square mr-1.5 text-[10px]"></i><?php endif; ?>
                                    <?= $act['label'] ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- SIDEBAR (4/12) -->
            <aside class="lg:col-span-4 flex flex-col gap-5 au d3">

                <!-- Activity Feed -->
                <div>
                    <div class="sec-title mb-4">
                        Recent Activity
                        <span class="ml-auto live-badge">LIVE</span>
                    </div>
                    <div class="feed-card">
                        <?php if($recentActivity): ?>
                            <?php foreach($recentActivity as $log): ?>
                                <div class="feed-item">
                                    <div class="feed-dot">
                                        <i class="fa-solid fa-bolt text-[11px]"></i>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center justify-between gap-2 mb-0.5">
                                            <span class="text-[10px] font-black uppercase tracking-wider truncate" style="color:#2e9e63"><?= htmlspecialchars($log['action']) ?></span>
                                            <span class="text-[9px] text-gray-400 whitespace-nowrap"><?= date('d M H:i', strtotime($log['created_at'])) ?></span>
                                        </div>
                                        <p class="text-[12px] font-bold text-gray-800 leading-snug truncate"><?= htmlspecialchars($log['admin_name'] ?? 'System') ?></p>
                                        <p class="text-[11px] text-gray-400 leading-snug mt-0.5 line-clamp-1"><?= htmlspecialchars($log['description']) ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="py-12 text-center text-gray-300">
                                <i class="fa-solid fa-ghost text-3xl mb-2 block"></i>
                                <p class="text-[11px] font-bold uppercase tracking-widest">No activity yet</p>
                            </div>
                        <?php endif; ?>
                        <a href="../admin/activity_logs.php"
                           class="flex items-center justify-center gap-1.5 py-3 text-[10px] font-black uppercase tracking-wider transition-colors border-t border-gray-50 hover:bg-green-50" style="color:#2e9e63">
                            View all logs <i class="fa-solid fa-chevron-right text-[9px]"></i>
                        </a>
                    </div>
                </div>

                <!-- Quick Shortcuts -->
                <div class="shortcut-card au d4">
                    <div class="text-xs font-black uppercase tracking-widest opacity-70 mb-1">Quick Access</div>
                    <div class="font-black text-lg mb-4">System Shortcuts</div>
                    <div class="space-y-2">
                        <a href="users.php" class="shortcut-link">
                            <i class="fa-solid fa-users"></i> Users Center
                        </a>
                        <a href="../admin/campaigns.php" class="shortcut-link">
                            <i class="fa-solid fa-bullhorn"></i> Campaign Manager
                        </a>
                        <a href="../admin/error_logs.php" class="shortcut-link">
                            <i class="fa-solid fa-bug"></i> Error Logs
                        </a>
                    </div>
                    <i class="fa-solid fa-screwdriver-wrench absolute -bottom-6 -right-6 text-[6rem] opacity-5 rotate-12 pointer-events-none"></i>
                </div>

            </aside>
        </div>

        <!-- FOOTER -->
        <footer class="pt-6 pb-4 text-center">
            <div class="flex items-center justify-center gap-2 opacity-25">
                <i class="fa-solid fa-shield-halved" style="color:#2e9e63"></i>
                <span class="text-[10px] font-black uppercase tracking-[.4em]">Central Command v3.0 · RSU Healthcare</span>
            </div>
        </footer>

    </div>

<script>
/* ── 1. KPI Number Counter ──────────────────────────────── */
document.querySelectorAll('[data-counter]').forEach(el => {
    const target = parseInt(el.dataset.counter, 10) || 0;
    if (target === 0) { el.textContent = '0'; return; }
    const duration = 1200;
    const start = performance.now();
    const easeOut = t => 1 - Math.pow(1 - t, 3);
    function tick(now) {
        const p = Math.min((now - start) / duration, 1);
        el.textContent = Math.floor(easeOut(p) * target).toLocaleString();
        if (p < 1) requestAnimationFrame(tick);
        else el.textContent = target.toLocaleString();
    }
    requestAnimationFrame(tick);
});

/* ── 2. Ripple on buttons ──────────────────────────────── */
document.querySelectorAll('.proj-action').forEach(btn => {
    btn.addEventListener('click', function(e) {
        const r = this.getBoundingClientRect();
        const size = Math.max(r.width, r.height);
        const el = document.createElement('span');
        el.className = 'ripple-wave';
        el.style.cssText = `width:${size}px;height:${size}px;left:${e.clientX-r.left-size/2}px;top:${e.clientY-r.top-size/2}px`;
        this.appendChild(el);
        el.addEventListener('animationend', () => el.remove());
    });
});

/* ── 3. 3D Tilt on project cards ───────────────────────── */
document.querySelectorAll('.proj-card').forEach(card => {
    card.addEventListener('mousemove', function(e) {
        const r = this.getBoundingClientRect();
        const x = (e.clientX - r.left) / r.width  - .5;
        const y = (e.clientY - r.top)  / r.height - .5;
        this.style.transform = `translateY(-5px) rotateX(${-y*8}deg) rotateY(${x*8}deg)`;
        this.style.transition = 'transform .1s ease';
    });
    card.addEventListener('mouseleave', function() {
        this.style.transform = '';
        this.style.transition = 'transform .4s ease, box-shadow .25s, border-color .25s';
    });
});
</script>

<?php if ($adminRole === 'superadmin'): ?>
<script>
function triggerGitPull() {
    const btn = document.getElementById('btnGitPull');
    btn.disabled = true;
    btn.style.opacity = '0.6';
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> <span>Pulling...</span>';

    fetch('../admin/ajax_git_pull.php', { method: 'POST' })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                btn.style.background = '#dcfce7';
                btn.style.color = '#15803d';
                btn.innerHTML = '<i class="fa-solid fa-check"></i> <span>สำเร็จ!</span>';
                if (data.detail && !data.detail.includes('Already up to date')) {
                    // มีโค้ดใหม่ — แจ้งให้ refresh
                    setTimeout(() => {
                        if (confirm('Git Pull สำเร็จ!\n\n' + data.detail + '\n\nรีโหลดหน้าเพื่อใช้งานโค้ดใหม่?')) {
                            location.reload();
                        }
                    }, 500);
                }
            } else {
                btn.style.background = '#fef2f2';
                btn.style.color = '#dc2626';
                btn.style.borderColor = '#fecaca';
                btn.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> <span>ล้มเหลว</span>';
                alert('Git Pull ล้มเหลว:\n' + data.message + (data.detail ? '\n\n' + data.detail : ''));
            }
        })
        .catch(() => {
            btn.style.background = '#fef2f2';
            btn.style.color = '#dc2626';
            btn.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> <span>Error</span>';
        })
        .finally(() => {
            // Reset button หลัง 3 วินาที
            setTimeout(() => {
                btn.disabled = false;
                btn.style.opacity = '1';
                btn.style.background = '#f0fdf4';
                btn.style.color = '#16a34a';
                btn.style.borderColor = '#d1fae5';
                btn.innerHTML = '<i class="fa-solid fa-code-branch"></i> <span>Git Pull</span>';
            }, 3000);
        });
}
</script>
<?php endif; ?>

</body>
</html>
