<!-- portal/_partials/header.php -->
<header class="portal-header au">
    <div class="w-full px-5 sm:px-8 py-3 flex items-center justify-between gap-4" style="min-height:60px">

        <!-- Left: App Switcher + Breadcrumb -->
        <div style="flex: 1; display: flex; justify-content: flex-start; gap: 10px; align-items: center; min-width: 0;">
            <button id="app-switcher-btn" onclick="openAppSwitcher()" title="สลับระบบ (App Switcher)"
                class="w-9 h-9 flex items-center justify-center rounded-xl bg-slate-100 text-slate-600 hover:bg-slate-200 hover:text-slate-900 transition-colors shadow-sm shrink-0">
                <i class="fa-solid fa-grip"></i>
            </button>
            <nav id="portal-breadcrumb" class="text-xs font-bold text-slate-500 hidden md:flex items-center gap-1.5 min-w-0 flex-1" aria-label="breadcrumb">
                <a href="index.php?section=dashboard" class="hover:text-slate-900 shrink-0">Portal</a>
                <i class="fa-solid fa-chevron-right text-[8px] text-slate-300 shrink-0"></i>
                <span id="bc-app" class="text-slate-600 shrink-0"></span>
                <i class="fa-solid fa-chevron-right text-[8px] text-slate-300 shrink-0" id="bc-sep"></i>
                <span id="bc-section" class="text-slate-900 font-black truncate"></span>
            </nav>
        </div>

        <!-- Right Action Icons -->
        <div class="flex items-center gap-3 sm:gap-4">

            <!-- Command palette ⌘K (visible trigger) -->
            <button id="cmdk-topbar-btn" onclick="window.cmdkOpen && window.cmdkOpen()" title="ค้นหา (⌘K)"
                class="hidden md:inline-flex items-center gap-2 px-3 h-9 rounded-xl bg-slate-100 text-slate-600 hover:bg-slate-200 hover:text-slate-900 transition-colors shadow-sm text-xs font-bold">
                <i class="fa-solid fa-magnifying-glass text-[11px]"></i>
                <span>ค้นหา</span>
                <kbd class="px-1.5 py-0.5 rounded bg-white border border-slate-200 text-[10px] font-black text-slate-500 leading-none">⌘K</kbd>
            </button>
            <button id="cmdk-topbar-btn-mobile" onclick="window.cmdkOpen && window.cmdkOpen()" title="ค้นหา (⌘K)"
                class="md:hidden w-9 h-9 flex items-center justify-center rounded-xl bg-slate-100 text-slate-600 hover:bg-slate-200 transition-colors shadow-sm">
                <i class="fa-solid fa-magnifying-glass text-xs"></i>
            </button>

            <!-- Dark Mode Toggle Button -->
            <button id="darkModeToggle" onclick="toggleDarkMode()" title="สลับโหมดมืด/สว่าง"
                class="w-9 h-9 flex items-center justify-center rounded-xl bg-slate-100 text-slate-600 hover:bg-slate-200 hover:text-slate-900 transition-colors shadow-sm dark-mode-btn">
                <i class="fa-solid fa-moon"></i>
            </button>

            <!-- Notification Bell (Integrated from Admin) -->
            <?php
            $notifAjaxUrl    = '../admin/ajax/ajax_notifications.php';
            $notifErrorUrl   = 'index.php?section=error_logs';
            $notifBookingUrl = '../admin/bookings.php';
            ?>
            <div class="relative" id="notif-wrapper">
                <button id="notif-btn"
                    class="relative w-9 h-9 flex items-center justify-center rounded-xl border transition-all hover:shadow-sm focus:outline-none"
                    style="background:#f0faf4;color:#2e9e63;border-color:#c7e8d5;"
                    aria-label="การแจ้งเตือน">
                    <i class="fa-solid fa-bell text-sm"></i>
                    <span id="notif-badge"
                        class="hidden absolute -top-1 -right-1 w-4 h-4 items-center justify-center text-[9px] font-black text-white bg-rose-500 rounded-full leading-none shadow-sm z-10 border border-white">
                        0
                    </span>
                    <span id="notif-ping" class="hidden absolute -top-1 -right-1 w-4 h-4 bg-rose-500 rounded-full animate-ping opacity-75"></span>
                </button>
                
                <div id="notif-panel"
                    class="hidden absolute right-0 top-full mt-3 bg-white border border-gray-100 rounded-3xl overflow-hidden"
                    style="z-index:200; width: 320px; min-width: 320px; box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);">
                    <div class="flex items-center justify-between px-5 py-4 border-b border-gray-50 bg-gray-50/50" style="display: flex; align-items: center; justify-content: space-between;">
                        <span class="text-[15px] font-black text-gray-900" style="white-space: nowrap;">การแจ้งเตือน</span>
                        <span id="notif-total-label" class="hidden text-[10px] font-black px-2.5 py-1 rounded-full shrink-0" style="background:#fee2e2;color:#b91c1c; white-space: nowrap;"></span>
                    </div>
                    <div id="notif-items" class="divide-y divide-gray-50 max-h-[400px] overflow-y-auto">
                        <div class="px-4 py-8 text-sm text-gray-400 text-center">กำลังโหลด...</div>
                    </div>
                </div>
            </div>

            <!-- Divider -->
            <div class="w-px h-6 bg-gray-200 hidden sm:block"></div>

            <!-- User Identity & Logout -->
            <div class="flex items-center gap-2 sm:gap-3">
                <?php
                // profile.php อ้าง sys_staff (admin_id ของ staff) — ทำลิงก์เฉพาะ staff session
                $_canEditProfile = !empty($_SESSION['is_ecampaign_staff']);

                // ── คำนวณ role label + tone ตาม session ────────────────────────
                $_isStaff       = !empty($_SESSION['is_ecampaign_staff']);
                $_isSuperRole   = ($_SESSION['admin_role'] ?? '') === 'superadmin'
                                  || ($_SESSION['role'] ?? '') === 'superadmin';
                $_rawRole       = $_SESSION['admin_role'] ?? $_SESSION['role'] ?? 'guest';
                $_roleConfig = [
                    'superadmin' => ['label' => 'Super Admin', 'icon' => 'fa-crown',       'grad' => 'linear-gradient(135deg, #a855f7, #ec4899)', 'shadow' => 'shadow-purple-500/30'],
                    'admin'      => ['label' => 'Admin',       'icon' => 'fa-user-shield', 'grad' => 'linear-gradient(135deg, #2e9e63, #10b981)', 'shadow' => 'shadow-emerald-500/20'],
                    'editor'     => ['label' => 'Editor',      'icon' => 'fa-user-pen',    'grad' => 'linear-gradient(135deg, #0ea5e9, #06b6d4)', 'shadow' => 'shadow-sky-500/20'],
                    'employee'   => ['label' => 'Staff',       'icon' => 'fa-user',        'grad' => 'linear-gradient(135deg, #64748b, #94a3b8)', 'shadow' => 'shadow-slate-500/20'],
                    'librarian'  => ['label' => 'Librarian',   'icon' => 'fa-book',        'grad' => 'linear-gradient(135deg, #f59e0b, #d97706)', 'shadow' => 'shadow-amber-500/20'],
                    'guest'      => ['label' => 'Guest',       'icon' => 'fa-user-circle', 'grad' => 'linear-gradient(135deg, #94a3b8, #cbd5e1)', 'shadow' => 'shadow-slate-300/20'],
                ];
                $_rc = $_roleConfig[$_rawRole] ?? $_roleConfig['guest'];

                // ── ดึง LINE profile (cache ใน session) ─────────────────────────
                if ($_isStaff && !isset($_SESSION['_line_profile_cache'])) {
                    // Auto-migrate columns (idempotent) — กัน prod ที่ยังไม่ได้รัน migration
                    try { $pdo->exec("ALTER TABLE sys_staff ADD COLUMN IF NOT EXISTS line_display_name VARCHAR(120) NULL DEFAULT NULL"); } catch (PDOException) {}
                    try { $pdo->exec("ALTER TABLE sys_staff ADD COLUMN IF NOT EXISTS line_picture_url VARCHAR(500) NULL DEFAULT NULL"); } catch (PDOException) {}

                    try {
                        $_pst = $pdo->prepare("SELECT IFNULL(line_picture_url,'') AS pic, IFNULL(line_display_name,'') AS name FROM sys_staff WHERE id = ? LIMIT 1");
                        $_pst->execute([(int)($_SESSION['admin_id'] ?? 0)]);
                        $_lp = $_pst->fetch(PDO::FETCH_ASSOC) ?: ['pic' => '', 'name' => ''];
                        $_SESSION['_line_profile_cache'] = $_lp;
                    } catch (PDOException) {
                        $_SESSION['_line_profile_cache'] = ['pic' => '', 'name' => ''];
                    }
                }
                $_linePic  = $_SESSION['_line_profile_cache']['pic']  ?? '';
                $_lineName = $_SESSION['_line_profile_cache']['name'] ?? '';

                // ── Build text block (role label + name) ────────────────────────
                $_displayName = $_SESSION['admin_username'] ?? 'Administrator';
                $_idTextHtml = '<div class="text-right hidden sm:block">'
                             . '<div class="text-[9px] font-extrabold uppercase tracking-widest leading-none mb-1" style="color:#64748b">' . htmlspecialchars($_rc['label']) . '</div>'
                             . '<div class="text-[13px] font-black text-slate-900 leading-none">'
                             .   htmlspecialchars($_displayName)
                             . '</div>'
                             . '</div>';

                // ── Build avatar (priority: LINE pic > role icon) ───────────────
                if ($_linePic !== '') {
                    $_idAvatarHtml = '<div class="relative w-9 h-9 flex-shrink-0">'
                                   . '<img src="' . htmlspecialchars($_linePic) . '" alt="' . htmlspecialchars($_lineName ?: $_displayName) . '"'
                                   .   ' class="w-9 h-9 rounded-xl object-cover shadow-md ' . $_rc['shadow'] . '"'
                                   .   ' style="border:2px solid #06c755"'
                                   .   ' onerror="this.outerHTML=\'<div class=&quot;w-9 h-9 rounded-xl flex items-center justify-center shadow-md ' . $_rc['shadow'] . ' text-sm&quot; style=&quot;background:' . $_rc['grad'] . ';color:#fff&quot;><i class=&quot;fa-solid ' . $_rc['icon'] . '&quot;></i></div>\'">'
                                   . '<span class="absolute -bottom-0.5 -right-0.5 w-3.5 h-3.5 rounded-full flex items-center justify-center bg-white" title="เชื่อม LINE">'
                                   .   '<i class="fa-brands fa-line text-[10px]" style="color:#06c755"></i>'
                                   . '</span>'
                                   . '</div>';
                } else {
                    $_idAvatarHtml = '<div class="w-9 h-9 rounded-xl flex flex-shrink-0 items-center justify-center shadow-md ' . $_rc['shadow'] . ' text-sm" style="background:' . $_rc['grad'] . ';color:#fff;">'
                                   . '<i class="fa-solid ' . $_rc['icon'] . '"></i>'
                                   . '</div>';
                }
                ?>
                <?php if ($_canEditProfile): ?>
                    <a href="index.php?section=profile" title="แก้ไขโปรไฟล์เจ้าหน้าที่"
                        class="flex items-center gap-2 sm:gap-3 group hover:opacity-90 transition-opacity"
                        style="text-decoration:none"
                        onclick="if (typeof switchSection === 'function') { event.preventDefault(); switchSection('profile', document.querySelector('[data-section=profile]')); }">
                        <?= $_idTextHtml ?>
                        <?= $_idAvatarHtml ?>
                    </a>
                <?php else: ?>
                    <?= $_idTextHtml ?>
                    <?= $_idAvatarHtml ?>
                <?php endif; ?>
                <a href="../admin/auth/logout.php" title="ออกจากระบบ"
                    class="w-9 h-9 rounded-xl bg-rose-50 text-rose-600 flex flex-shrink-0 items-center justify-center hover:bg-rose-500 hover:text-white transition-colors border border-rose-100 ml-1">
                    <i class="fa-solid fa-power-off text-xs"></i>
                </a>
            </div>
        </div>
    </div>
</header>
<script>
(function () {
    var notifOpen  = false;
    var btn        = document.getElementById('notif-btn');
    var panel      = document.getElementById('notif-panel');
    var badge      = document.getElementById('notif-badge');
    var ping       = document.getElementById('notif-ping');
    var totalLabel = document.getElementById('notif-total-label');
    var items      = document.getElementById('notif-items');
    var ajaxUrl    = <?= json_encode($notifAjaxUrl) ?>;
    var errorUrl   = <?= json_encode($notifErrorUrl) ?>;
    var bookingUrl = <?= json_encode($notifBookingUrl) ?>;

    function renderItems(d) {
        var html = '';
        if (d.errors_today > 0) {
            html += '<a href="' + errorUrl + '" class="flex items-center gap-4 px-5 py-4 hover:bg-rose-50/50 transition-all group" style="display: flex; align-items: center; text-decoration: none;">'
                  + '<div class="w-10 h-10 rounded-2xl flex items-center justify-center shrink-0 shadow-sm group-hover:scale-110 transition-transform" style="background:#fff1f2;color:#e11d48; flex-shrink: 0; display: flex; align-items: center; justify-content: center;">'
                  + '<i class="fa-solid fa-bug text-sm"></i></div>'
                  + '<div class="flex-1 min-w-0" style="flex: 1; min-width: 0; margin-left: 1rem;">'
                  + '<div class="text-[13px] font-bold text-gray-900 mb-0.5" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">Error Logs วันนี้</div>'
                  + '<div class="text-[11px] font-medium text-gray-500" style="white-space: nowrap;">' + d.errors_today + ' รายการใหม่</div></div>'
                  + '<i class="fa-solid fa-chevron-right text-[10px] text-gray-300 group-hover:text-rose-400 transition-colors shrink-0" style="flex-shrink: 0; margin-left: auto;"></i></a>';
        }
        if (d.pending_bookings > 0) {
            html += '<a href="' + bookingUrl + '" class="flex items-center gap-4 px-5 py-4 hover:bg-amber-50/50 transition-all group" style="display: flex; align-items: center; text-decoration: none;">'
                  + '<div class="w-10 h-10 rounded-2xl flex items-center justify-center shrink-0 shadow-sm group-hover:scale-110 transition-transform" style="background:#fffbeb;color:#d97706; flex-shrink: 0; display: flex; align-items: center; justify-content: center;">'
                  + '<i class="fa-solid fa-clock-rotate-left text-sm"></i></div>'
                  + '<div class="flex-1 min-w-0" style="flex: 1; min-width: 0; margin-left: 1rem;">'
                  + '<div class="text-[13px] font-bold text-gray-900 mb-0.5" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">รอการอนุมัติ</div>'
                  + '<div class="text-[11px] font-medium text-gray-500" style="white-space: nowrap;">' + d.pending_bookings + ' คิวรอพิจารณา</div></div>'
                  + '<i class="fa-solid fa-chevron-right text-[10px] text-gray-300 group-hover:text-amber-400 transition-colors shrink-0" style="flex-shrink: 0; margin-left: auto;"></i></a>';
        }
        if (html === '') {
            html = '<div class="px-4 py-10 text-center">'
                 + '<i class="fa-solid fa-circle-check text-3xl text-green-400 mb-2 block"></i>'
                 + '<div class="text-[13px] font-bold text-gray-400">ไม่มีการแจ้งเตือน</div></div>';
        }
        items.innerHTML = html;
    }

    function fetchNotifications() {
        fetch(ajaxUrl)
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.status !== 'success') return;
                var total = d.total;
                if (total > 0) {
                    badge.textContent = total > 99 ? '99+' : total;
                    badge.classList.remove('hidden');
                    badge.classList.add('flex');
                    if (ping) ping.classList.remove('hidden');
                    totalLabel.textContent = total + ' รายการ';
                    totalLabel.classList.remove('hidden');
                } else {
                    badge.classList.add('hidden');
                    badge.classList.remove('flex');
                    if (ping) ping.classList.add('hidden');
                    totalLabel.classList.add('hidden');
                }
                if (notifOpen) renderItems(d);
            })
            .catch(function () {});
    }

    if (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            notifOpen = !notifOpen;
            if (notifOpen) {
                panel.classList.remove('hidden');
                items.innerHTML = '<div class="px-4 py-8 text-sm text-gray-400 text-center">กำลังโหลด...</div>';
                fetch(ajaxUrl)
                    .then(function (r) { return r.json(); })
                    .then(renderItems)
                    .catch(function () {
                        items.innerHTML = '<div class="px-4 py-8 text-sm text-red-400 text-center">โหลดข้อมูลไม่สำเร็จ</div>';
                    });
            } else {
                panel.classList.add('hidden');
            }
        });
        document.addEventListener('click', function() {
            if (notifOpen) {
                notifOpen = false;
                panel.classList.add('hidden');
            }
        });
        panel.addEventListener('click', function(e) { e.stopPropagation(); });
    }

    fetchNotifications();
    setInterval(fetchNotifications, 10000); // 10s
})();
</script>
