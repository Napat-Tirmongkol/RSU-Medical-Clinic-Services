<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(SITE_NAME) ?> - Central Intelligence HUB</title>
    <link rel="icon" href="<?= !empty(SITE_LOGO) ? '../' . SITE_LOGO : '../favicon.ico' ?>">

    <!-- UI Framework & Fonts -->
    <link
        href="https://fonts.googleapis.com/css2?family=Sarabun:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link rel="stylesheet" href="../assets/css/portal.css?v=<?= @filemtime(__DIR__ . '/../assets/css/portal.css') ?: (defined('APP_BUILD') ? APP_BUILD : time()) ?>">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Chart.js — loaded globally so all section pages (nurse_productivity,
         gold_card, activity_dashboard, edms/sla_dashboard, etc.) have access
         without each having to script-tag it. Some partials still load their
         own copy — harmless re-load from CDN cache. -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="../assets/js/safe-fetch.js?v=<?= @filemtime(__DIR__ . '/../assets/js/safe-fetch.js') ?: (defined('APP_BUILD') ? APP_BUILD : time()) ?>"></script>
    <script defer src="../assets/js/rsu-fx.js?v=<?= @filemtime(__DIR__ . '/../assets/js/rsu-fx.js') ?: (defined('APP_BUILD') ? APP_BUILD : time()) ?>"></script>
    <!-- Suppress harmless AbortError from skipped View Transitions
         (เกิดเมื่อนำทางมาจากหน้า admin/e_Borrow ที่เปิด @view-transition แล้วถูกข้าม) -->
    <script>
        window.addEventListener('unhandledrejection', function(e) {
            var r = e.reason;
            if (r && r.name === 'AbortError' && /transition/i.test(r.message || '')) {
                e.preventDefault();
            }
        });
    </script>
    <style>
        /* ── Toggle Switch (Maintenance Mode) ──────────────────────────────── */
        .toggle-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }

        .toggle {
            position: relative;
            width: 46px;
            height: 24px;
            cursor: pointer;
        }

        .toggle input {
            opacity: 0;
            width: 0;
            height: 0;
            position: absolute;
        }

        .toggle-track {
            position: absolute;
            inset: 0;
            background: #e2e8f0;
            border-radius: 99px;
            transition: background .25s cubic-bezier(.25, 1, .5, 1);
        }

        .toggle input:checked~.toggle-track {
            background: #2e9e63;
        }

        .toggle-thumb {
            position: absolute;
            top: 3px;
            left: 3px;
            width: 18px;
            height: 18px;
            background: #fff;
            border-radius: 50%;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .15);
            transition: transform .3s cubic-bezier(.25, 1, .5, 1);
        }

        .toggle input:checked~.toggle-thumb {
            transform: translateX(22px);
        }

        @keyframes toggleRingOn {
            0% {
                box-shadow: 0 0 0 0 rgba(46, 158, 99, .4);
            }

            50% {
                box-shadow: 0 0 0 6px rgba(46, 158, 99, .15);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(46, 158, 99, .0);
            }
        }

        .toggle-ring-on {
            animation: toggleRingOn .45s cubic-bezier(.25, 1, .5, 1) both;
        }

        /* ── Status badge ──────────────────────────────────────────────────── */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 2px 9px;
            border-radius: 99px;
            font-size: 10px;
            font-weight: 700;
        }

        .status-badge.on {
            background: #f0fdf4;
            color: #16a34a;
            border: 1px solid #bbf7d0;
        }

        .status-badge.off {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .status-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .status-badge.on .status-dot {
            background: #22c55e;
            animation: livePulse 1.5s infinite;
        }

        .status-badge.off .status-dot {
            background: #ef4444;
        }

        @keyframes badgePop {
            0% {
                opacity: .35;
                transform: scale(.82);
            }

            60% {
                transform: scale(1.07);
            }

            100% {
                opacity: 1;
                transform: scale(1);
            }
        }

        .badge-pop {
            animation: badgePop .3s cubic-bezier(.25, 1, .5, 1) both;
        }

        #status-banner[data-state="ok"] {
            background: #f0fdf4;
            border-color: #bbf7d0;
        }

        /* ── Identity Tabs ──────────────────────────────────────────────────── */
        .id-tab {
            padding: 10px 18px;
            font-size: 13px;
            font-weight: 800;
            color: #64748b;
            background: transparent;
            border: none;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all .2s;
        }

        .id-tab.active {
            color: #2e9e63;
            border-bottom-color: #2e9e63;
        }

        .id-panel {
            display: none;
            animation: idFadeIn .3s ease;
        }

        .id-panel.active {
            display: block;
        }

        @keyframes idFadeIn {
            from {
                opacity: 0;
                transform: translateY(5px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ── Premium Form Inputs ────────────────────────────────────────────── */
        .premium-input {
            width: 100%;
            padding: 12px 16px;
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            color: #0f172a;
            outline: none;
            transition: all .2s;
        }

        .premium-input:focus {
            background: #fff;
            border-color: #2563eb;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
        }

        .premium-role-card {
            background: #fff;
            border: 1.5px solid #f1f5f9;
            border-radius: 20px;
            overflow: hidden;
            transition: all .2s;
        }

        .premium-role-card.blue {
            border-color: #dbeafe;
            background: #f0f7ff;
        }

        .premium-role-card.orange {
            border-color: #ffedd5;
            background: #fffaf5;
        }

        .role-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #fff;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
    </style>
    <script>
        /* ── Critical Navigation Functions (Defined in Head for early availability) ── */
        window.toggleSidebar = function () {
            var sidebar = document.getElementById('portal-sidebar');
            var icon = document.getElementById('sidebar-toggle-icon');
            var expanded = document.getElementById('psb-user-expanded');
            var collapsed = document.getElementById('psb-user-collapsed');
            if (!sidebar) return;
            sidebar.classList.toggle('collapsed');
            var isCollapsed = sidebar.classList.contains('collapsed');
            if (icon) icon.style.transform = isCollapsed ? 'rotate(180deg)' : '';
            if (expanded) expanded.style.display = isCollapsed ? 'none' : 'flex';
            if (collapsed) collapsed.style.display = isCollapsed ? 'flex' : 'none';
            localStorage.setItem('portal_sidebar_collapsed', isCollapsed ? '1' : '0');
        };

        // Auto-apply sidebar state on load
        window.addEventListener('DOMContentLoaded', function() {
            // Hide NEW badge on items the user has already opened. We can't
            // server-side gate this because the flag is per-browser; do it
            // client-side at boot so the markup ships intact and the JS
            // decides what's still actually NEW for *this* viewer.
            try {
                document.querySelectorAll('.psb-item[data-new-key]').forEach(function (b) {
                    if (localStorage.getItem('psb_seen_' + b.dataset.newKey) === '1') {
                        var badge = b.querySelector('.psb-new-badge');
                        if (badge) badge.classList.add('is-dismissed');
                    }
                });
            } catch (e) { /* localStorage disabled, fine */ }

            if (localStorage.getItem('portal_sidebar_collapsed') === '1') {
                var sidebar = document.getElementById('portal-sidebar');
                if (sidebar) {
                    sidebar.classList.add('collapsed');
                    var icon = document.getElementById('sidebar-toggle-icon');
                    if (icon) icon.style.transform = 'rotate(180deg)';
                    var expanded = document.getElementById('psb-user-expanded');
                    var collapsed = document.getElementById('psb-user-collapsed');
                    if (expanded) expanded.style.display = 'none';
                    if (collapsed) collapsed.style.display = 'flex';
                }
            }

            // Apply saved per-group collapse state.
            // First-time visitor (no saved state): default-collapse everything except OVERVIEW
            // — sidebar with 12 sections expanded is overwhelming for Staff ทั่วไป.
            try {
                var savedRaw = localStorage.getItem('psb_groups_collapsed');
                var saved;
                if (savedRaw === null) {
                    saved = ['ai','security','insurance','comm','inventory','finance','monitor','reports','docs','masterdata','settings'];
                    localStorage.setItem('psb_groups_collapsed', JSON.stringify(saved));
                } else {
                    saved = JSON.parse(savedRaw);
                }
                saved.forEach(function (key) {
                    var btn = document.querySelector('.psb-section-toggle[data-group="' + key + '"]');
                    var grp = document.querySelector('.psb-group[data-group="' + key + '"]');
                    if (btn && grp) {
                        btn.classList.add('collapsed');
                        grp.classList.add('collapsed');
                    }
                });
            } catch (e) { /* silent */ }

            // Auto-expand the group containing the active item (override saved collapse)
            var activeItem = document.querySelector('.psb-item.psb-active');
            if (activeItem) {
                var grp = activeItem.closest('.psb-group');
                if (grp) {
                    var key = grp.getAttribute('data-group');
                    grp.classList.remove('collapsed');
                    var btn = document.querySelector('.psb-section-toggle[data-group="' + key + '"]');
                    if (btn) btn.classList.remove('collapsed');
                }
            }

            // ── Sidebar search ─────────────────────────────────────
            // กรอง psb-item ตาม text + ซ่อน group ที่ไม่เหลือ item ที่ตรง
            var searchInput = document.getElementById('psb-search');
            var clearBtn = document.getElementById('psb-search-clear');
            if (searchInput) {
                var doFilter = function () {
                    var q = (searchInput.value || '').trim().toLowerCase();
                    clearBtn.style.display = q ? 'block' : 'none';
                    document.querySelectorAll('.psb-group').forEach(function (grp) {
                        var key = grp.getAttribute('data-group');
                        var anyMatch = false;
                        grp.querySelectorAll('.psb-item').forEach(function (item) {
                            var label = (item.textContent || '').toLowerCase();
                            var match = !q || label.indexOf(q) >= 0;
                            item.style.display = match ? '' : 'none';
                            if (match && q) anyMatch = true;
                        });
                        // While searching, auto-expand groups with matches; restore on clear
                        if (q) {
                            var btn = document.querySelector('.psb-section-toggle[data-group="' + key + '"]');
                            if (anyMatch) {
                                grp.classList.remove('collapsed');
                                if (btn) btn.classList.remove('collapsed');
                                grp.style.display = '';
                                if (btn) btn.style.display = '';
                            } else {
                                grp.style.display = 'none';
                                if (btn) btn.style.display = 'none';
                            }
                        } else {
                            grp.style.display = '';
                            var btn2 = document.querySelector('.psb-section-toggle[data-group="' + key + '"]');
                            if (btn2) btn2.style.display = '';
                        }
                    });
                };
                searchInput.addEventListener('input', doFilter);
                window.psbClearSearch = function () {
                    searchInput.value = '';
                    doFilter();
                    searchInput.focus();
                };
                // Cmd/Ctrl+K shortcut
                document.addEventListener('keydown', function (e) {
                    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
                        e.preventDefault();
                        searchInput.focus();
                        searchInput.select();
                    }
                });
            }
        });

        // Toggle a sidebar group open/closed; persist to localStorage
        window.togglePsbGroup = function (key, btnEl) {
            var btn = btnEl || document.querySelector('.psb-section-toggle[data-group="' + key + '"]');
            var grp = document.querySelector('.psb-group[data-group="' + key + '"]');
            if (!btn || !grp) return;
            var nowCollapsed = btn.classList.toggle('collapsed');
            grp.classList.toggle('collapsed', nowCollapsed);

            try {
                var saved = JSON.parse(localStorage.getItem('psb_groups_collapsed') || '[]');
                var idx = saved.indexOf(key);
                if (nowCollapsed && idx < 0) saved.push(key);
                if (!nowCollapsed && idx >= 0) saved.splice(idx, 1);
                localStorage.setItem('psb_groups_collapsed', JSON.stringify(saved));
            } catch (e) { /* silent */ }
        };

        window.switchSection = function (sectionId, btn) {
            var target = document.getElementById('section-' + sectionId);
            if (!target) {
                // After multi-page refactor (2026-05-23): no in-page <div id="section-X">
                // exists on standalone section pages. Navigate to the section file directly.
                // Old `<a href="javascript:switchSection('foo')">` links keep working.
                window.location.href = sectionId + '.php';
                return;
            }
            document.querySelectorAll('.portal-section').forEach(function (s) { s.style.display = 'none'; });
            target.style.display = '';
            // Persist "user has seen this NEW feature" so the pulse pill
            // doesn't keep advertising it forever. Uses the button's
            // data-new-key (versioned: e.g. pdpa_audit_v1) so we can re-
            // surface a NEW badge later if we ship v2 of the same section.
            // Dismisses via the existing .is-dismissed class on .psb-new-badge
            // (defined in portal.css for the apps-launcher badge).
            if (btn && btn.dataset && btn.dataset.newKey) {
                try {
                    localStorage.setItem('psb_seen_' + btn.dataset.newKey, '1');
                    var badge = btn.querySelector('.psb-new-badge');
                    if (badge) badge.classList.add('is-dismissed');
                } catch (e) { /* localStorage disabled, fine */ }
            }
            document.querySelectorAll('.psb-item').forEach(function (b) {
                b.classList.remove('psb-active');
                b.removeAttribute('aria-current');
            });

            // If btn not provided, try to find it in sidebar
            if (!btn) {
                btn = document.querySelector('.psb-item[data-section="' + sectionId + '"]');
            }
            if (btn) {
                btn.classList.add('psb-active');
                btn.setAttribute('aria-current', 'page');
            }

            // Refresh batch_status data whenever the section becomes active
            if (sectionId === 'batch_status' && typeof window.bsLoad === 'function') {
                window.bsLoad(1);
            }
            // Activity Dashboard: start polling + Pusher subscription
            if (sectionId === 'activity_dashboard' && typeof window.adActivate === 'function') {
                window.adActivate();
            }

            var url = new URL(window.location.href);
            url.searchParams.set('section', sectionId);
            ['page','el_search','el_level','el_date','el_source','al_q','eml_q','eml_type','eml_status','cd_search','cd_view','s','p'].forEach(function(k){ url.searchParams.delete(k); });
            history.pushState({section: sectionId}, '', url.toString());
        };
    </script>
</head>

<body class="font-sans text-gray-800 bg-[#f4f7f5]" style="height:100vh;overflow:hidden;display:flex;flex-direction:row">
<script>if(localStorage.getItem('ecampaign_theme')==='dark')document.body.setAttribute('data-theme','dark');</script>

    <a href="#portal-main" class="skip-to-content">ข้ามไปยังเนื้อหาหลัก</a>

    <!-- ── Collapsible Sidebar ── -->
    <nav id="portal-sidebar">
        <!-- Brand / Toggle -->
        <div
            style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px 12px;border-bottom:1px solid #f0faf4;min-height:60px">
            <div class="flex items-center gap-2" id="psb-brand-text">
                <div class="brand-icon" style="width:30px;height:30px;font-size:12px;border-radius:10px;overflow:hidden;display:flex;align-items:center;justify-content:center;<?= defined('SITE_LOGO') && SITE_LOGO !== '' ? 'background:transparent;' : '' ?>">
                    <?php if (defined('SITE_LOGO') && SITE_LOGO !== ''): ?>
                        <img src="../<?= htmlspecialchars(SITE_LOGO) ?>" style="width:100%;height:100%;object-fit:contain;" alt="Logo">
                    <?php else: ?>
                        <i class="fa-solid fa-heart"></i>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="font-black text-slate-800 text-[15px] leading-tight tracking-tight"><?= htmlspecialchars(SITE_NAME ?: 'Central HUB') ?></div>
                </div>
            </div>
            <button onclick="toggleSidebar()" id="sidebar-toggle" title="Toggle sidebar"
                style="width:28px;height:28px;border-radius:8px;border:none;cursor:pointer;background:#f0faf4;color:#2e9e63;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .18s">
                <i id="sidebar-toggle-icon" class="fa-solid fa-chevron-left"
                    style="font-size:11px;transition:transform .3s"></i>
            </button>
        </div>

        <!-- Sidebar search (filters menu items by Thai label) -->
        <div id="psb-search-wrap" style="padding:10px 12px 6px;">
            <div style="position:relative;">
                <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:11px;pointer-events:none;"></i>
                <input type="search" id="psb-search" placeholder="ค้นหาเมนู… (Ctrl+K)" autocomplete="off"
                    style="width:100%;padding:7px 28px 7px 28px;border:1px solid #e2e8f0;border-radius:9px;font-size:12px;background:#fafbfc;color:#0f172a;outline:none;transition:border-color .15s, background .15s;">
                <button type="button" id="psb-search-clear" onclick="psbClearSearch()" aria-label="ล้างคำค้นหา"
                    style="display:none;position:absolute;right:6px;top:50%;transform:translateY(-50%);width:18px;height:18px;border-radius:50%;border:none;background:#e2e8f0;color:#475569;cursor:pointer;font-size:9px;line-height:18px;padding:0;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        </div>

        <!-- Nav items (grouped) -->
        <div style="padding:10px;flex:1;overflow-y:auto;display:flex;flex-direction:column;">
            <?php
            // Pre-compute role flags for cleaner conditionals
            $isSuper        = ($adminRole === 'superadmin');
            $hasRegistry    = $isSuper || !empty($_SESSION['access_registry']);
            $hasInsurance   = $isSuper || !empty($_SESSION['access_insurance']) || !empty($_SESSION['access_registry']);
            $hasSysLogs     = $isSuper || !empty($_SESSION['access_system_logs']);
            $hasSiteSet     = $isSuper || !empty($_SESSION['access_site_settings']);
            $hasEdms        = $isSuper || !empty($_SESSION['access_edms']);
            $hasScholarship = $isSuper || !empty($_SESSION['access_scholarship']);
            $hasDashboardAdmin = $isSuper || !empty($_SESSION['access_dashboard_admin']);
            $hasMonthlyReport  = $isSuper || !empty($_SESSION['access_monthly_report']) || !empty($_SESSION['access_director_view']);
            $hasNurseProductivity = $isSuper || ($adminRole === 'admin') || !empty($_SESSION['access_nurse_productivity']);
            $hasDailySummary      = $isSuper || ($adminRole === 'admin') || !empty($_SESSION['access_daily_summary']);
            $hasAsset          = $isSuper || in_array($_SESSION['role'] ?? '', ['admin','editor'], true) || !empty($_SESSION['access_asset']);
            $hasConsumables    = $isSuper || in_array($_SESSION['role'] ?? '', ['admin','editor'], true) || !empty($_SESSION['access_consumables']);
            $hasInventory      = $hasAsset || $hasConsumables;
            // Group-toggle visibility flag — must match the OR of all inner-item gates
            // (Avoids empty group headers that confuse users when they expand and find nothing.)
            $hasInsuranceGroup = $isSuper || $hasInsurance || $hasRegistry;

            // EDMS pending count badge — count routings where current user is recipient and status is open
            $edmsInboxBadge   = 0;
            $edmsBreachedMine = 0;
            $edmsWarningMine  = 0;
            $edmsTaskMine     = 0;
            if ($hasEdms) {
                $_uid = (int)($_SESSION['admin_id'] ?? 0);
                if ($_uid > 0) {
                    try {
                        // Total open inbox count (เดิม — ใช้ใน sidebar badge)
                        $_st = $pdo->prepare("SELECT COUNT(*) FROM sys_doc_routings WHERE to_user_id = ? AND status IN ('pending','acknowledged')");
                        $_st->execute([$_uid]);
                        $edmsInboxBadge = (int)$_st->fetchColumn();
                    } catch (PDOException) { /* table not yet migrated */ }

                    try {
                        // Breached + warning count แยก (SLA module)
                        $_st = $pdo->prepare("SELECT
                            SUM(CASE WHEN sla_state = 'breached' THEN 1 ELSE 0 END) AS breached,
                            SUM(CASE WHEN sla_state = 'warning' THEN 1 ELSE 0 END) AS warning
                            FROM sys_doc_routings
                            WHERE to_user_id = ? AND status IN ('pending','acknowledged')");
                        $_st->execute([$_uid]);
                        $_sr = $_st->fetch(PDO::FETCH_ASSOC) ?: [];
                        $edmsBreachedMine = (int)($_sr['breached'] ?? 0);
                        $edmsWarningMine  = (int)($_sr['warning'] ?? 0);
                    } catch (PDOException) { /* sla columns ยังไม่มี */ }

                    try {
                        // Task count (มอบหมายงาน) — แยกจากเอกสารทางการ
                        $_st = $pdo->prepare("SELECT COUNT(*) FROM sys_doc_routings r
                            JOIN sys_doc_documents d ON d.id = r.doc_id
                            WHERE r.to_user_id = ? AND r.status IN ('pending','acknowledged') AND d.doc_type = 'task'");
                        $_st->execute([$_uid]);
                        $edmsTaskMine = (int)$_st->fetchColumn();
                    } catch (PDOException) {}
                }
            }
            ?>

            <?php /* ── OVERVIEW ───────────────────────────────────────────── */ ?>
            <?php if (!$registryOnly): ?>
                <button type="button" class="psb-section-toggle" data-group="overview" onclick="togglePsbGroup('overview',this)">
                    <i class="fa-solid fa-chart-line" style="color:#94a3b8"></i>
                    <span>OVERVIEW</span>
                    <i class="fa-solid fa-chevron-down psb-chevron"></i>
                </button>
                <div class="psb-group" data-group="overview">
                    <a class="psb-item <?= $activeSection==='dashboard'?'psb-active':'' ?>" data-section="dashboard" href="dashboard.php">
                        <div class="psb-icon"><i class="fa-solid fa-chart-pie" style="color:#059669"></i></div>
                        <span class="psb-label">Dashboard</span>
                    </a>
                    <a class="psb-item <?= $activeSection==='apps'?'psb-active':'' ?>" data-section="apps" href="apps.php" id="psb-apps-launcher">
                        <div class="psb-icon"><i class="fa-solid fa-grip" style="color:#2e9e63"></i></div>
                        <span class="psb-label">App Launcher</span>
                        <span class="psb-new-badge" id="psb-apps-new-badge">NEW</span>
                    </a>
                    <?php if ($isStaff): ?>
                        <a class="psb-item <?= $activeSection==='profile'?'psb-active':'' ?>" data-section="profile" href="profile.php">
                            <div class="psb-icon"><i class="fa-solid fa-user-pen" style="color:#0891b2"></i></div>
                            <span class="psb-label">โปรไฟล์ของฉัน</span>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php /* ── AI SUITE ────────────────────────────────────────────── */ ?>
            <?php if (!$registryOnly && ($isSuper || !empty($_SESSION['access_ai']))): ?>
                <button type="button" class="psb-section-toggle" data-group="ai" onclick="togglePsbGroup('ai',this)">
                    <i class="fa-solid fa-wand-magic-sparkles" style="color:#a855f7"></i>
                    <span>AI Suite</span>
                    <i class="fa-solid fa-chevron-down psb-chevron"></i>
                </button>
                <div class="psb-group" data-group="ai">
                    <a class="psb-item" data-section="ai_assistant" href="ai_assistant.php">
                        <div class="psb-icon"><i class="fa-solid fa-wand-magic-sparkles" style="color:#8b5cf6"></i></div>
                        <span class="psb-label">AI Assistant</span>
                    </a>
                    <a class="psb-item <?= $activeSection==='admin_chat'?'psb-active':'' ?>" data-section="admin_chat" href="admin_chat.php">
                        <div class="psb-icon"><i class="fa-solid fa-comments" style="color:#a855f7"></i></div>
                        <span class="psb-label">ผู้ช่วยข้อมูล</span>
                    </a>
                    <a class="psb-item <?= $activeSection==='line_chat'?'psb-active':'' ?>" data-section="line_chat" href="line_chat.php">
                        <div class="psb-icon"><i class="fa-brands fa-line" style="color:#06c755"></i></div>
                        <span class="psb-label">LINE Chat</span>
                    </a>
                    <a class="psb-item <?= $activeSection==='ai_qa_lab'?'psb-active':'' ?>" data-section="ai_qa_lab" href="ai_qa_lab.php">
                        <div class="psb-icon"><i class="fa-solid fa-flask-vial" style="color:#a855f7"></i></div>
                        <span class="psb-label">AI QA Lab</span>
                    </a>
                    <a class="psb-item <?= $activeSection==='ai_prompts'?'psb-active':'' ?>" data-section="ai_prompts" href="ai_prompts.php">
                        <div class="psb-icon"><i class="fa-solid fa-code" style="color:#a855f7"></i></div>
                        <span class="psb-label">AI Prompts</span>
                    </a>
                    <a class="psb-item <?= $activeSection==='ai_knowledge'?'psb-active':'' ?>" data-section="ai_knowledge" href="ai_knowledge.php">
                        <div class="psb-icon"><i class="fa-solid fa-database" style="color:#10b981"></i></div>
                        <span class="psb-label">AI Knowledge</span>
                    </a>
                </div>
            <?php endif; ?>

            <?php /* ── สิทธิ์ & ความปลอดภัย ──────────────────────────────── */ ?>
            <?php
            // Gate the group header itself: render only if user can see at least one item inside.
            // (Empty group toggles confuse users — they expand and find nothing.)
            $hasSecurityGroup = $isSuper || !empty($_SESSION['access_identity']);
            if (!$registryOnly && $hasSecurityGroup): ?>
                <button type="button" class="psb-section-toggle" data-group="security" onclick="togglePsbGroup('security',this)">
                    <i class="fa-solid fa-shield-halved" style="color:#2563eb"></i>
                    <span>สิทธิ์ &amp; ความปลอดภัย</span>
                    <i class="fa-solid fa-chevron-down psb-chevron"></i>
                </button>
                <div class="psb-group" data-group="security">
                    <?php if ($isSuper || !empty($_SESSION['access_identity'])): ?>
                    <a class="psb-item" data-section="identity" href="identity.php">
                        <div class="psb-icon"><i class="fa-solid fa-id-card-clip" style="color:#2563eb"></i></div>
                        <span class="psb-label">Identity &amp; Governance</span>
                    </a>
                    <?php endif; ?>
                    <?php if ($isSuper): ?>
                        <a class="psb-item" data-section="privilege_inventory" href="privilege_inventory.php">
                            <div class="psb-icon"><i class="fa-solid fa-shield-halved" style="color:#10b981"></i></div>
                            <span class="psb-label">ISO Governance</span>
                        </a>
                    <?php endif; ?>
                    <?php if ($isSuper || !empty($_SESSION['access_identity'])): ?>
                    <a class="psb-item <?= $activeSection==='pdpa_audit'?'psb-active':'' ?>" data-section="pdpa_audit" href="pdpa_audit.php" data-new-key="pdpa_audit_v1">
                        <div class="psb-icon"><i class="fa-solid fa-user-shield" style="color:#7c3aed"></i></div>
                        <span class="psb-label">PDPA Audit</span>
                        <span class="psb-new-badge">NEW</span>
                    </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php /* ── ประกันสุขภาพ ─────────────────────────────────────── */ ?>
            <?php if ($hasInsuranceGroup): ?>
                <button type="button" class="psb-section-toggle" data-group="insurance" onclick="togglePsbGroup('insurance',this)">
                    <i class="fa-solid fa-hospital-user" style="color:#0ea5e9"></i>
                    <span>ประกันสุขภาพ</span>
                    <i class="fa-solid fa-chevron-down psb-chevron"></i>
                </button>
                <div class="psb-group" data-group="insurance">
                    <?php if (!$registryOnly && $hasInsurance): ?>
                        <a class="psb-item <?= $activeSection==='insurance_dashboard'?'psb-active':'' ?>" data-section="insurance_dashboard" href="insurance_dashboard.php">
                            <div class="psb-icon"><i class="fa-solid fa-chart-pie" style="color:#3b82f6"></i></div>
                            <span class="psb-label">Dashboard Workbook</span>
                        </a>
                        <a class="psb-item" data-section="insurance_sync" href="insurance_sync.php">
                            <div class="psb-icon"><i class="fa-solid fa-shield-halved" style="color:#0ea5e9"></i></div>
                            <span class="psb-label">Insurance Hub</span>
                        </a>
                        <a class="psb-item <?= $activeSection==='gold_card_pending'?'psb-active':'' ?>" data-section="gold_card_pending" href="gold_card_pending.php">
                            <div class="psb-icon"><i class="fa-solid fa-hourglass-half" style="color:#3b82f6"></i></div>
                            <span class="psb-label">ย้ายสิทธิ์บัตรทอง</span>
                            <?php
                            $pendingBadgeCount = 0;
                            try { $pendingBadgeCount = (int)db()->query("SELECT COUNT(*) FROM gold_card_members WHERE status = 'submitted'")->fetchColumn(); }
                            catch (PDOException) {}
                            if ($pendingBadgeCount > 0): ?>
                                <span class="ml-auto px-2 py-0.5 rounded-full bg-rose-500 text-white text-[10px] font-black"><?= $pendingBadgeCount > 99 ? '99+' : $pendingBadgeCount ?></span>
                            <?php endif; ?>
                        </a>
                        <a class="psb-item <?= $activeSection==='gold_card'?'psb-active':'' ?>" data-section="gold_card" href="gold_card.php">
                            <div class="psb-icon"><i class="fa-solid fa-id-card" style="color:#f59e0b"></i></div>
                            <span class="psb-label">บัตรทอง</span>
                        </a>
                    <?php endif; ?>
                    <?php if ($hasRegistry): ?>
                        <a class="psb-item <?= $activeSection==='registry_upload'?'psb-active':'' ?>" data-section="registry_upload" href="registry_upload.php">
                            <div class="psb-icon"><i class="fa-solid fa-id-card-clip" style="color:#06b6d4"></i></div>
                            <span class="psb-label">อัพโหลดรายชื่อ (ทะเบียน)</span>
                        </a>
                    <?php endif; ?>
                    <?php if ($hasInsurance): ?>
                        <a class="psb-item <?= $activeSection==='batch_status'?'psb-active':'' ?>" data-section="batch_status" href="batch_status.php">
                            <div class="psb-icon"><i class="fa-solid fa-list-check" style="color:#0891b2"></i></div>
                            <span class="psb-label">สถานะเอกสาร</span>
                        </a>
                    <?php endif; ?>
                    <?php if (!$registryOnly && $isSuper): ?>
                        <a class="psb-item" data-section="manage_insurance_partners" href="manage_insurance_partners.php">
                            <div class="psb-icon"><i class="fa-solid fa-handshake" style="color:#10b981"></i></div>
                            <span class="psb-label">Insurance Partners</span>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php /* ── สื่อสาร ──────────────────────────────────────────── */ ?>
            <?php if (!$registryOnly): ?>
                <button type="button" class="psb-section-toggle" data-group="comm" onclick="togglePsbGroup('comm',this)">
                    <i class="fa-solid fa-bullhorn" style="color:#7c3aed"></i>
                    <span>สื่อสาร</span>
                    <i class="fa-solid fa-chevron-down psb-chevron"></i>
                </button>
                <div class="psb-group" data-group="comm">
                    <a class="psb-item" data-section="announcements" href="announcements.php">
                        <div class="psb-icon"><i class="fa-solid fa-bullhorn" style="color:#7c3aed"></i></div>
                        <span class="psb-label">ประกาศ</span>
                    </a>
                    <?php if ($hasEdms):
                        // detect sub-view สำหรับ highlight sidebar item
                        $_edmsView = $_GET['edms_view'] ?? '';
                        $_edmsType = $_GET['type'] ?? '';
                        $_isSlaDash = ($activeSection === 'edms' && $_edmsView === 'sla_dashboard');
                        $_isSlaPol  = ($activeSection === 'edms' && $_edmsView === 'sla_policies');
                        $_isTasks   = ($activeSection === 'edms' && $_edmsView === 'list' && $_edmsType === 'task');
                        // parent active เฉพาะตอนอยู่ section=edms และไม่ใช่ sub-view ที่มีปุ่มแยก
                        $_isEdmsParent = ($activeSection === 'edms' && !$_isSlaDash && !$_isSlaPol && !$_isTasks);
                    ?>
                        <a class="psb-item <?= $_isEdmsParent ? 'psb-active' : '' ?>" data-section="edms" href="edms.php" style="position:relative">
                            <div class="psb-icon"><i class="fa-solid fa-folder-open" style="color:#0ea5e9"></i></div>
                            <span class="psb-label">สารบรรณอิเล็กทรอนิกส์</span>
                            <?php if ($edmsInboxBadge > 0): ?>
                                <span style="margin-left:auto;display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:20px;padding:0 6px;border-radius:99px;background:#f59e0b;color:#fff;font-size:10px;font-weight:900;box-shadow:0 1px 2px rgba(0,0,0,.1)" title="<?= $edmsInboxBadge ?> รายการรอดำเนินการ">
                                    <?= $edmsInboxBadge > 99 ? '99+' : $edmsInboxBadge ?>
                                </span>
                            <?php endif; ?>
                        </a>
                        <?php /* EDMS sub-menu: Tasks — งานที่มอบหมาย (ไม่ใช่เอกสารทางการ) */ ?>
                        <a class="psb-item <?= $_isTasks ? 'psb-active' : '' ?>"
                           href="edms.php?view=list&type=task">
                            <div class="psb-icon"><i class="fa-solid fa-list-check" style="color:#06b6d4"></i></div>
                            <span class="psb-label">— งาน/Tasks</span>
                        </a>
                        <?php /* EDMS sub-menu: SLA — เป็น sub-section ของ section=edms แต่ highlight แยก */ ?>
                        <a class="psb-item <?= $_isSlaDash ? 'psb-active' : '' ?>"
                           href="edms.php?view=sla_dashboard">
                            <div class="psb-icon"><i class="fa-solid fa-gauge-high" style="color:#10b981"></i></div>
                            <span class="psb-label">— SLA Dashboard</span>
                        </a>
                        <?php $_canSlaAdmin = ($adminRole === 'superadmin') || !empty($_SESSION['access_edms_sla_admin']); ?>
                        <?php if ($_canSlaAdmin): ?>
                            <a class="psb-item <?= $_isSlaPol ? 'psb-active' : '' ?>"
                               href="edms.php?view=sla_policies">
                                <div class="psb-icon"><i class="fa-solid fa-stopwatch-20" style="color:#a855f7"></i></div>
                                <span class="psb-label">— นโยบาย SLA</span>
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php /* ── คลังพัสดุ (Inventory) ────────────────────────────── */ ?>
            <?php if (!$registryOnly && $hasInventory): ?>
                <button type="button" class="psb-section-toggle" data-group="inventory" onclick="togglePsbGroup('inventory',this)">
                    <i class="fa-solid fa-warehouse" style="color:#2e9e63"></i>
                    <span>คลังพัสดุ</span>
                    <i class="fa-solid fa-chevron-down psb-chevron"></i>
                </button>
                <div class="psb-group" data-group="inventory">
                    <?php if ($hasAsset): ?>
                        <a href="../asset/index.php" class="psb-item" style="text-decoration:none">
                            <div class="psb-icon"><i class="fa-solid fa-boxes-stacked" style="color:#0d9488"></i></div>
                            <span class="psb-label">ครุภัณฑ์สำนักงาน</span>
                        </a>
                    <?php endif; ?>
                    <?php if ($hasConsumables): ?>
                        <a href="../consumables/index.php" class="psb-item" style="text-decoration:none">
                            <div class="psb-icon"><i class="fa-solid fa-box-open" style="color:#2e9e63"></i></div>
                            <span class="psb-label">วัสดุสิ้นเปลือง</span>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php /* ── การเงิน ──────────────────────────────────────────── */ ?>
            <?php
            $hasFinance = $isSuper || $adminRole === 'admin' || !empty($_SESSION['access_finance']);
            if (!$registryOnly && $hasFinance): ?>
                <button type="button" class="psb-section-toggle" data-group="finance" onclick="togglePsbGroup('finance',this)">
                    <i class="fa-solid fa-money-bill-trend-up" style="color:#059669"></i>
                    <span>การเงิน</span>
                    <i class="fa-solid fa-chevron-down psb-chevron"></i>
                </button>
                <div class="psb-group" data-group="finance">
                    <a class="psb-item <?= $activeSection==='finance'?'psb-active':'' ?>" data-section="finance" href="finance.php">
                        <div class="psb-icon"><i class="fa-solid fa-book" style="color:#059669"></i></div>
                        <span class="psb-label">Cash Book</span>
                    </a>
                </div>
            <?php endif; ?>

            <?php /* ── ยา (Pharmacy / Vaccination — Phase 1: vaccines only) ───── */ ?>
            <?php if (!$registryOnly && ($isSuper || $adminRole === 'admin' || !empty($_SESSION['access_identity']))): ?>
                <button type="button" class="psb-section-toggle" data-group="pharmacy" onclick="togglePsbGroup('pharmacy',this)">
                    <i class="fa-solid fa-prescription-bottle-medical" style="color:#0d9488"></i>
                    <span>ยา</span>
                    <i class="fa-solid fa-chevron-down psb-chevron"></i>
                </button>
                <div class="psb-group" data-group="pharmacy">
                    <a class="psb-item <?= $activeSection==='vaccinations'?'psb-active':'' ?>" data-section="vaccinations" href="vaccinations.php" data-new-key="vaccinations_v1">
                        <div class="psb-icon"><i class="fa-solid fa-syringe" style="color:#0d9488"></i></div>
                        <span class="psb-label">บันทึกการฉีดวัคซีน</span>
                        <span class="psb-new-badge">NEW</span>
                    </a>
                    <a class="psb-item <?= $activeSection==='vaccine_catalog'?'psb-active':'' ?>" data-section="vaccine_catalog" href="vaccine_catalog.php" data-new-key="vaccine_catalog_v1">
                        <div class="psb-icon"><i class="fa-solid fa-pills" style="color:#0d9488"></i></div>
                        <span class="psb-label">ประเภทวัคซีน</span>
                        <span class="psb-new-badge">NEW</span>
                    </a>
                </div>
            <?php endif; ?>

            <?php /* ── ติดตามระบบ ──────────────────────────────────────── */ ?>
            <?php if (!$registryOnly && $hasSysLogs): ?>
                <button type="button" class="psb-section-toggle" data-group="monitor" onclick="togglePsbGroup('monitor',this)">
                    <i class="fa-solid fa-binoculars" style="color:#64748b"></i>
                    <span>ติดตามระบบ</span>
                    <i class="fa-solid fa-chevron-down psb-chevron"></i>
                </button>
                <div class="psb-group" data-group="monitor">
                    <?php if (($_SESSION['admin_role'] ?? '') === 'superadmin'): ?>
                    <a class="psb-item" data-section="activity_dashboard" href="activity_dashboard.php">
                        <div class="psb-icon"><i class="fa-solid fa-chart-line" style="color:#8b5cf6"></i></div>
                        <span class="psb-label">Activity Dashboard</span>
                    </a>
                    <?php endif; ?>
                    <a class="psb-item" data-section="activity_logs" href="activity_logs.php">
                        <div class="psb-icon"><i class="fa-solid fa-file-lines" style="color:#64748b"></i></div>
                        <span class="psb-label">Activity Logs</span>
                    </a>
                    <a class="psb-item" data-section="error_logs" href="error_logs.php">
                        <div class="psb-icon"><i class="fa-solid fa-bug" style="color:#ef4444"></i></div>
                        <span class="psb-label">Error Logs</span>
                    </a>
                    <?php if ($adminRole === 'superadmin'): ?>
                    <a class="psb-item <?= $activeSection==='sentry_events'?'psb-active':'' ?>" data-section="sentry_events" href="sentry_events.php">
                        <div class="psb-icon"><i class="fa-solid fa-radiation" style="color:#8b5cf6"></i></div>
                        <span class="psb-label">Sentry Events</span>
                    </a>
                    <?php endif; ?>
                    <?php if ($isSuper || $adminRole === 'admin' || !empty($_SESSION['access_identity'])): ?>
                    <a class="psb-item <?= $activeSection==='db_schema'?'psb-active':'' ?>" data-section="db_schema" href="db_schema.php" data-new-key="db_schema_v1">
                        <div class="psb-icon"><i class="fa-solid fa-diagram-project" style="color:#0891b2"></i></div>
                        <span class="psb-label">Database Schema</span>
                        <span class="psb-new-badge">NEW</span>
                    </a>
                    <?php endif; ?>
                    <?php if ($isSuper): ?>
                    <a class="psb-item <?= $activeSection==='sql_console'?'psb-active':'' ?>" data-section="sql_console" href="sql_console.php" data-new-key="sql_console_v1">
                        <div class="psb-icon"><i class="fa-solid fa-terminal" style="color:#ea580c"></i></div>
                        <span class="psb-label">SQL Console <span style="font-size:8px;color:#9a3412;background:#fed7aa;padding:1px 4px;border-radius:3px;margin-left:2px">RO</span></span>
                        <span class="psb-new-badge">NEW</span>
                    </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php /* ── รายงาน ─────────────────────────────────────────────── */ ?>
            <?php if (!$registryOnly && ($hasMonthlyReport || $hasNurseProductivity || $hasDailySummary)): ?>
                <button type="button" class="psb-section-toggle" data-group="reports" onclick="togglePsbGroup('reports',this)">
                    <i class="fa-solid fa-clipboard-list" style="color:#f59e0b"></i>
                    <span>รายงาน</span>
                    <i class="fa-solid fa-chevron-down psb-chevron"></i>
                </button>
                <div class="psb-group" data-group="reports">
                    <?php if ($hasDailySummary): ?>
                    <a class="psb-item <?= $activeSection==='daily_summary'?'psb-active':'' ?>" data-section="daily_summary" href="daily_summary.php">
                        <div class="psb-icon"><i class="fa-solid fa-clipboard-check" style="color:#f59e0b"></i></div>
                        <span class="psb-label">สรุปงานประจำวัน</span>
                    </a>
                    <?php endif; ?>
                    <?php if ($hasMonthlyReport): ?>
                    <a class="psb-item <?= $activeSection==='monthly_report'?'psb-active':'' ?>" data-section="monthly_report" href="monthly_report.php">
                        <div class="psb-icon"><i class="fa-solid fa-calendar-days" style="color:#f59e0b"></i></div>
                        <span class="psb-label">รายงานประจำเดือน</span>
                    </a>
                    <?php endif; ?>
                    <?php if ($hasNurseProductivity): ?>
                    <a class="psb-item <?= $activeSection==='nurse_productivity'?'psb-active':'' ?>" data-section="nurse_productivity" href="nurse_productivity.php">
                        <div class="psb-icon"><i class="fa-solid fa-user-nurse" style="color:#f59e0b"></i></div>
                        <span class="psb-label">Productivity พยาบาล</span>
                    </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php /* ── เอกสาร / รายงาน (Document Library) ─────────────────── */ ?>
            <?php if (!$registryOnly && ($adminRole === 'superadmin' || $adminRole === 'admin')): ?>
                <button type="button" class="psb-section-toggle" data-group="docs" onclick="togglePsbGroup('docs',this)">
                    <i class="fa-solid fa-folder-tree" style="color:#0f7349"></i>
                    <span>เอกสาร</span>
                    <i class="fa-solid fa-chevron-down psb-chevron"></i>
                </button>
                <div class="psb-group" data-group="docs">
                    <a class="psb-item <?= $activeSection==='documents'?'psb-active':'' ?>" data-section="documents" href="documents.php">
                        <div class="psb-icon"><i class="fa-solid fa-file-lines" style="color:#0f7349"></i></div>
                        <span class="psb-label">คลังเอกสาร</span>
                    </a>
                </div>
            <?php endif; ?>

            <div style="flex:1"></div> <!-- Spacer to push settings to bottom -->

            <?php /* ── ข้อมูลหลัก (Master Data) ─────────────────────────── */ ?>
            <?php if (!$registryOnly && $hasSiteSet): ?>
                <button type="button" class="psb-section-toggle" data-group="masterdata" onclick="togglePsbGroup('masterdata',this)">
                    <i class="fa-solid fa-database" style="color:#0d9488"></i>
                    <span>ข้อมูลหลัก</span>
                    <i class="fa-solid fa-chevron-down psb-chevron"></i>
                </button>
                <div class="psb-group" data-group="masterdata">
                    <a class="psb-item <?= $activeSection==='clinic_data'?'psb-active':'' ?>" data-section="clinic_data" href="clinic_data.php">
                        <div class="psb-icon"><i class="fa-solid fa-hospital" style="color:#0d9488"></i></div>
                        <span class="psb-label">ข้อมูลคลินิก</span>
                    </a>
                    <?php if ($hasScholarship): ?>
                        <a class="psb-item <?= $activeSection==='scholarship'?'psb-active':'' ?>" data-section="scholarship" href="scholarship.php">
                            <div class="psb-icon"><i class="fa-solid fa-graduation-cap" style="color:#10b981"></i></div>
                            <span class="psb-label">นักศึกษาทุน</span>
                        </a>
                    <?php endif; ?>
                    <a class="psb-item <?= $activeSection==='nurse_schedule'?'psb-active':'' ?>" data-section="nurse_schedule" href="nurse_schedule.php">
                        <div class="psb-icon"><i class="fa-solid fa-user-nurse" style="color:#0ea5e9"></i></div>
                        <span class="psb-label">ตารางเวรพยาบาล</span>
                    </a>
                </div>
            <?php endif; ?>

            <?php /* ── ตั้งค่า (ล่างสุด) ─────────────────────────────────── */ ?>
            <?php if (!$registryOnly && $hasSiteSet): ?>
                <button type="button" class="psb-section-toggle" data-group="settings" onclick="togglePsbGroup('settings',this)">
                    <i class="fa-solid fa-gear" style="color:#d97706"></i>
                    <span>ตั้งค่า</span>
                    <i class="fa-solid fa-chevron-down psb-chevron"></i>
                </button>
                <div class="psb-group" data-group="settings">
                    <a class="psb-item" data-section="settings" href="settings.php">
                        <div class="psb-icon"><i class="fa-solid fa-gear" style="color:#d97706"></i></div>
                        <span class="psb-label">Settings</span>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </nav>

    <div id="app-shell" style="flex:1;min-width:0;background:#f4f7f5;height:100vh;overflow:hidden;display:flex;flex-direction:column;">

        <!-- ══════════════════ HEADER ══════════════════ -->
        <?php include __DIR__ . '/_partials/header.php'; ?>

        <!-- ── Main Content ── -->
        <main id="portal-main" style="flex:1;overflow-y:auto;min-width:0;">
<?php // end of top - section content goes here ?>
