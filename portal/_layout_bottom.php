        </main><!-- /portal-main -->
    </div><!-- /app-shell -->

    <!-- Theme Handling Script -->
    <script>
        function toggleDarkMode() {
            const isDark = document.body.getAttribute('data-theme') === 'dark';
            applyTheme(isDark ? 'light' : 'dark');
        }

        function applyTheme(theme) {
            const btn = document.getElementById('darkModeToggle');
            if (theme === 'dark') {
                document.body.setAttribute('data-theme', 'dark');
                if (btn) btn.innerHTML = '<i class="fa-solid fa-sun text-amber-500"></i>';
                localStorage.setItem('ecampaign_theme', 'dark');
            } else {
                document.body.removeAttribute('data-theme');
                if (btn) btn.innerHTML = '<i class="fa-solid fa-moon"></i>';
                localStorage.setItem('ecampaign_theme', 'light');
            }
            document.querySelectorAll('iframe').forEach(iframe => {
                try { iframe.contentWindow.postMessage({ type: 'THEME_CHANGE', theme }, '*'); } catch(e) {}
            });
        }

        // Sync toggle icon with the theme already applied by the early inline script
        document.addEventListener('DOMContentLoaded', () => {
            if (localStorage.getItem('ecampaign_theme') === 'dark') {
                const btn = document.getElementById('darkModeToggle');
                if (btn) btn.innerHTML = '<i class="fa-solid fa-sun text-amber-500"></i>';
            }
        });
    </script>

    <!-- ── KPI counter is now handled by assets/js/rsu-fx.js (IntersectionObserver-based) ── -->
    <script>
        /* ── Ripple on buttons ──────────────────────────────────── */
        document.querySelectorAll('.proj-action').forEach(btn => {
            btn.addEventListener('click', function (e) {
                const r = this.getBoundingClientRect();
                const size = Math.max(r.width, r.height);
                const el = document.createElement('span');
                el.className = 'ripple-wave';
                el.style.cssText = `width:${size}px;height:${size}px;left:${e.clientX - r.left - size / 2}px;top:${e.clientY - r.top - size / 2}px`;
                this.appendChild(el);
                el.addEventListener('animationend', () => el.remove());
            });
        });

        /* ── 3. 3D Tilt on project cards ───────────────────────── */
        document.querySelectorAll('.proj-card').forEach(card => {
            card.addEventListener('mousemove', function (e) {
                const r = this.getBoundingClientRect();
                const x = (e.clientX - r.left) / r.width - .5;
                const y = (e.clientY - r.top) / r.height - .5;
                this.style.transform = `translateY(-5px) rotateX(${-y * 8}deg) rotateY(${x * 8}deg)`;
                this.style.transition = 'transform .1s ease';
            });
            card.addEventListener('mouseleave', function () {
                this.style.transform = '';
                this.style.transition = 'transform .4s ease, box-shadow .25s, border-color .25s';
            });
        });

        /* ── 4. Global Search Filtering (Moved to Local) ──────── */
        const globalSearch = document.getElementById('search-project');
        const projCards = document.querySelectorAll('.proj-card');
        const projEmpty = document.getElementById('proj-empty');

        if (globalSearch) {
            globalSearch.addEventListener('input', function() {
                const val = this.value.toLowerCase().trim();
                let matchCount = 0;

                projCards.forEach(card => {
                    const name = card.dataset.name || '';
                    const keywords = card.dataset.keywords || '';
                    const isMatch = name.includes(val) || keywords.includes(val);
                    
                    card.style.display = isMatch ? '' : 'none';
                    if (isMatch) matchCount++;
                });

                if (projEmpty) {
                    projEmpty.style.display = (matchCount === 0 && val !== '') ? 'block' : 'none';
                }
            });
        }

        /* ── 5. Project Pinning (Database Driven) ───────────── */
        window.togglePin = function(projId, btn) {
            btn.disabled = true;
            const isPinned = btn.classList.contains('active');
            
            const fd = new FormData();
            fd.append('project_id', projId);

            fetch('ajax_pins.php?action=toggle', {
                method: 'POST',
                body: fd
            })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    if (data.action === 'added') {
                        btn.classList.add('active');
                        document.getElementById('proj-' + projId).dataset.pinned = '1';
                    } else {
                        btn.classList.remove('active');
                        document.getElementById('proj-' + projId).dataset.pinned = '0';
                    }
                    applyProjectOrder();
                }
            })
            .finally(() => {
                btn.disabled = false;
            });
        };

        function applyProjectOrder() {
            const container = document.getElementById('project-container');
            if (!container) return;
            const cards = Array.from(container.querySelectorAll('.proj-card'));

            cards.sort((a, b) => {
                const aPinned = a.dataset.pinned === '1';
                const bPinned = b.dataset.pinned === '1';
                if (aPinned && !bPinned) return -1;
                if (!aPinned && bPinned) return 1;
                return 0;
            });

            cards.forEach(card => container.appendChild(card));
        }

        applyProjectOrder();
    </script>

    <?php if ($adminRole === 'superadmin'): ?>
        <script>
            function triggerGitPull() {
                Swal.fire({
                    title: 'กำลังดำเนินการ Git Pull...',
                    text: 'กรุณารอสักครู่ ระบบกำลังอัปเดตโค้ดล่าสุดจาก Server',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    showConfirmButton: false,
                    didOpen: () => {
                        Swal.showLoading();
                        const btn = document.getElementById('btnGitPull');
                        const btnHistory = document.getElementById('btnGitPullHistory');
                        if (btn) { btn.disabled = true; btn.style.opacity = '0.6'; }
                        if (btnHistory) { btnHistory.disabled = true; btnHistory.style.opacity = '0.6'; }

                        fetch('../admin/ajax/ajax_git_pull.php', { method: 'POST' })
                            .then(r => r.json())
                            .then(data => {
                                if (data.status === 'success') {
                                    if (data.detail && !data.detail.includes('Already up to date')) {
                                        Swal.fire({
                                            icon: 'success',
                                            title: 'Git Pull สำเร็จ!',
                                            html: `<div style="text-align:left; font-size:13px; background:#f8fafc; padding:10px; border-radius:8px; border:1px solid #e2e8f0; font-family:monospace; margin-top:10px; max-height:200px; overflow-y:auto;">${data.detail.replace(/\n/g, '<br>')}</div><p style="margin-top:15px; font-weight:700;">รีโหลดหน้าเพื่อใช้งานโค้ดใหม่?</p>`,
                                            showCancelButton: true,
                                            confirmButtonText: 'ตกลง (Reload)',
                                            cancelButtonText: 'ยังไม่รีโหลด',
                                            confirmButtonColor: '#2e9e63'
                                        }).then((result) => {
                                            if (result.isConfirmed) {
                                                location.reload();
                                            }
                                        });
                                    } else {
                                        Swal.fire({
                                            icon: 'info',
                                            title: 'Git Pull สำเร็จ',
                                            text: 'ระบบเป็นเวอร์ชันล่าสุดอยู่แล้ว (Already up to date)',
                                            confirmButtonColor: '#2e9e63'
                                        });
                                    }
                                } else {
                                    Swal.fire({
                                        icon: 'error',
                                        title: 'Git Pull ล้มเหลว',
                                        text: data.message,
                                        footer: data.detail ? `<pre style="text-align:left; font-size:10px;">${data.detail}</pre>` : ''
                                    });
                                }
                            })
                            .catch((err) => {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'เกิดข้อผิดพลาด',
                                    text: 'ไม่สามารถเชื่อมต่อกับ AJAX Git Pull ได้'
                                });
                            })
                            .finally(() => {
                                if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
                                if (btnHistory) { btnHistory.disabled = false; btnHistory.style.opacity = '1'; }
                            });
                    }
                });
            }
        </script>
    <?php endif; ?>

    <script>
        document.getElementById('siteSettingsForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const form = this;
            const formData = new FormData(form);
            const btn = form.querySelector('button[type="submit"]');
            
            btn.disabled = true;
            btn.style.opacity = '0.6';
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> กำลังบันทึก...';

            fetch(form.action, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    Swal.fire({
                        icon: 'success',
                        title: 'สำเร็จ!',
                        text: data.message,
                        confirmButtonColor: '#2563eb'
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'ผิดพลาด',
                        text: data.message,
                        confirmButtonColor: '#ef4444'
                    });
                }
            })
            .catch(err => {
                Swal.fire({
                    icon: 'error',
                    title: 'ข้อผิดพลาดระบบ',
                    text: 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้',
                    confirmButtonColor: '#ef4444'
                });
            })
            .finally(() => {
                btn.disabled = false;
                btn.style.opacity = '1';
                btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> บันทึกการตั้งค่า';
            });
        });
    </script>

    <script>
        /* ══════════════════════════════════════════════════════════════
           POLLING — live dashboard updates every 20s (no persistent connection)
           ══════════════════════════════════════════════════════════════ */

        const _liveStyle = document.createElement('style');
        _liveStyle.textContent = `
  @keyframes livePulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.4;transform:scale(.8)} }
  @keyframes kpiFade   { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:translateY(0)} }
  @keyframes feedSlide { from{opacity:0;transform:translateX(10px)} to{opacity:1;transform:translateX(0)} }
  .kpi-updated { animation: kpiFade .4s ease both; }
  .feed-new    { animation: feedSlide .3s ease both; }
`;
        document.head.appendChild(_liveStyle);

        const badge = document.getElementById('ws-badge');
        const dot = document.getElementById('ws-dot');
        const label = document.getElementById('ws-label');

        function setBadge(state) {
            if (!badge || !dot || !label) return;
            const styles = {
                live: { bg: '#f0fdf4', color: '#16a34a', border: '#c7e8d5', dot: '#22c55e', anim: 'livePulse 1.6s infinite', text: 'Live' },
                loading: { bg: '#fffbeb', color: '#d97706', border: '#fde68a', dot: '#f59e0b', anim: 'livePulse .8s infinite', text: 'Updating…' },
                offline: { bg: '#fef2f2', color: '#dc2626', border: '#fecaca', dot: '#ef4444', anim: 'none', text: 'Offline' },
            };
            const s = styles[state] || styles.offline;
            badge.style.cssText = `display:flex;align-items:center;gap:5px;padding:5px 10px;border-radius:8px;font-size:10px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;transition:all .3s;background:${s.bg};color:${s.color};border:1px solid ${s.border}`;
            dot.style.background = s.dot;
            dot.style.animation = s.anim;
            label.textContent = s.text;
        }

        function animateKpi(el, toVal) {
            if (!el) return;
            const from = parseInt(el.textContent.replace(/,/g, ''), 10) || 0;
            if (from === toVal) return;
            const dur = 600, start = performance.now();
            const ease = t => 1 - Math.pow(1 - t, 3);
            el.classList.remove('kpi-updated'); void el.offsetWidth; el.classList.add('kpi-updated');
            (function tick(now) {
                const p = Math.min((now - start) / dur, 1);
                el.textContent = Math.floor(ease(p) * (toVal - from) + from).toLocaleString();
                if (p < 1) requestAnimationFrame(tick);
                else el.textContent = toVal.toLocaleString();
            })(start);
        }

        function escHtml(s) {
            return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function renderActivity(logs) {
            const feed = document.getElementById('activity-feed');
            const link = feed?.querySelector('a[href]');
            if (!feed) return;
            feed.querySelectorAll('.feed-item').forEach(el => el.remove());
            if (!logs?.length) return;
            const frag = document.createDocumentFragment();
            logs.forEach((log, i) => {
                const ts = new Date(log.timestamp.replace(' ', 'T'));
                const timeStr = ts.toLocaleString('th-TH', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
                const row = document.createElement('div');
                row.className = 'feed-item feed-new';
                row.style.animationDelay = (i * 0.04) + 's';
                row.innerHTML = `<div class="feed-dot"><i class="fa-solid fa-bolt text-[11px]"></i></div>
            <div class="min-w-0 flex-1">
                <div class="flex items-center justify-between gap-2 mb-0.5">
                    <span class="text-[10px] font-black uppercase tracking-wider truncate" style="color:#2e9e63">${escHtml(log.action)}</span>
                    <span class="text-[9px] text-gray-400 whitespace-nowrap">${timeStr}</span>
                </div>
                <p class="text-[12px] font-bold text-gray-800 leading-snug truncate">${escHtml(log.admin_name || 'System')}</p>
                <p class="text-[11px] text-gray-400 leading-snug mt-0.5 line-clamp-1">${escHtml(log.description || '')}</p>
            </div>`;
                frag.appendChild(row);
            });
            feed.insertBefore(frag, link);
        }

        // ── Polling ───────────────────────────────────────────────────────────────────
        const POLL_INTERVAL = 20000; // 20 seconds
        let pollTimer = null;

        function poll() {
            setBadge('loading');
            fetch('ajax_stats.php', { credentials: 'same-origin' })
                .then(r => r.ok ? r.json() : Promise.reject(r.status))
                .then(d => {
                    if (!d.ok) { setBadge('offline'); return; }
                    animateKpi(document.getElementById('kpi-users'), d.users);
                    animateKpi(document.getElementById('kpi-camps'), d.camps);
                    animateKpi(document.getElementById('kpi-borrows'), d.borrows);

                    // Borrows urgency badge + sub text
                    const ub = document.getElementById('borrows-urgent');
                    if (ub) ub.style.display = d.borrows > 0 ? 'inline' : 'none';
                    const borrowsSub = document.getElementById('borrows-sub');
                    if (borrowsSub) {
                        if (d.borrows > 0) {
                            borrowsSub.style.color = '#ef4444';
                            borrowsSub.innerHTML = '<i class="fa-solid fa-circle-exclamation" style="margin-right:3px"></i>รอการตรวจสอบ';
                        } else {
                            borrowsSub.style.color = '#94a3b8';
                            borrowsSub.textContent = 'ไม่มีรายการค้างในระบบ';
                        }
                    }

                    // Quota & booking rate
                    if (d.total_quota !== undefined) {
                        const rate = d.booking_rate ?? 0;
                        const rateBar = document.getElementById('kpi-rate-bar');
                        const rateNum = document.getElementById('kpi-rate');
                        const kpiUsed = document.getElementById('kpi-used');
                        const kpiTQ = document.getElementById('kpi-total-quota');
                        const kpiQuota = document.getElementById('kpi-quota');
                        if (rateBar) rateBar.style.width = rate + '%';
                        if (rateNum) rateNum.textContent = rate;
                        if (kpiUsed) kpiUsed.textContent = (d.used_quota ?? 0).toLocaleString();
                        if (kpiTQ) kpiTQ.textContent = d.total_quota.toLocaleString();
                        if (kpiQuota) kpiQuota.textContent = d.total_quota.toLocaleString();
                    }

                    if (Array.isArray(d.activity)) renderActivity(d.activity);
                    setBadge('live');
                })
                .catch(() => setBadge('offline'));
        }

        /* ── Project Grid Controls ────────────────────────────────────────────────── */
        (function () {
            var currentFilter = 'all';
            var searchQuery = '';

            function applyFilters() {
                var cards = document.querySelectorAll('#project-container .proj-card');
                var visible = 0;
                cards.forEach(function (card) {
                    var name = (card.dataset.name || '').toLowerCase();
                    var keywords = (card.dataset.keywords || '').toLowerCase();
                    var category = card.dataset.category || '';
                    var matchSearch = !searchQuery || name.includes(searchQuery) || keywords.includes(searchQuery);
                    var matchFilter = currentFilter === 'all' || category === currentFilter;
                    if (matchSearch && matchFilter) {
                        card.style.display = ''; visible++;
                    } else {
                        card.style.display = 'none';
                    }
                });
                var empty = document.getElementById('proj-empty');
                if (empty) empty.style.display = visible === 0 ? 'block' : 'none';
            }

            window.projSetFilter = function (btn) {
                document.querySelectorAll('.proj-tab').forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');
                currentFilter = btn.dataset.filter;
                applyFilters();
            };

            window.projSetView = function (view) {
                var container = document.getElementById('project-container');
                var btnGrid = document.getElementById('btn-grid');
                var btnList = document.getElementById('btn-list');
                var activeStyle = 'padding:5px 10px;border-radius:8px;border:none;cursor:pointer;background:#fff;color:#2e9e63;box-shadow:0 1px 4px rgba(0,0,0,.08);transition:all .2s';
                var inactiveStyle = 'padding:5px 10px;border-radius:8px;border:none;cursor:pointer;background:transparent;color:#94a3b8;transition:all .2s';
                if (view === 'list') {
                    container.classList.add('list-mode');
                    btnGrid.style.cssText = inactiveStyle;
                    btnList.style.cssText = activeStyle;
                } else {
                    container.classList.remove('list-mode');
                    btnGrid.style.cssText = activeStyle;
                    btnList.style.cssText = inactiveStyle;
                }
            };

            var searchInput = document.getElementById('search-project');
            if (searchInput) {
                searchInput.addEventListener('input', function () {
                    searchQuery = this.value.toLowerCase().trim();
                    applyFilters();
                });
            }
        })();

        /* ── Identity & Governance ─────────────────────────────────────────────── */
        function switchIdTab(tab, btn) {
            document.querySelectorAll('.id-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.id-panel').forEach(p => p.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById('id-panel-' + tab).classList.add('active');

            // Header visibility
            const isUsers = tab === 'users';
            const isAdmins = tab === 'admins';
            const isStaff = tab === 'staff';

            const btnAdmin = document.getElementById('id-btn-add-admin');
            const btnStaff = document.getElementById('id-btn-add-staff');
            if (btnAdmin) btnAdmin.style.display = isAdmins ? 'block' : 'none';
            if (btnStaff) btnStaff.style.display = isStaff ? 'block' : 'none';

            // Search behavior
            const search = document.getElementById('id-search-input');
            if (search) {
                search.value = '';
                idUniversalFilter('');
                search.placeholder = isUsers ? 'ค้นหา Users...' : (isAdmins ? 'ค้นหา Admins...' : 'ค้นหา Staff...');
            }
        }

        function idUniversalFilter(val) {
            val = val.toLowerCase().trim();
            const activePanel = document.querySelector('.id-panel.active');
            if (!activePanel) return;

            const rows = activePanel.querySelectorAll('tbody tr');
            rows.forEach(row => {
                if (row.cells.length < 2) return;
                row.style.display = row.innerText.toLowerCase().includes(val) ? '' : 'none';
            });
        }

        function openAddAdminModal() {
            openGovModal('admin', 'add');
        }

        function openAddStaffModal() {
            openGovModal('staff', 'add');
        }

        function openEditAdminModal(adm) {
            openGovModal('admin', 'edit', adm);
        }

        function openEditStaffModal(st) {
            openGovModal('staff', 'edit', st);
        }

        // Teleport modal to <body> on first open. Modals live inside
        // section-identity after the multi-page refactor; any ancestor
        // creating a stacking context (transform, filter, backdrop-filter,
        // contain, perspective) would trap their position:fixed and pin
        // them inside the content area instead of the viewport.
        // See CLAUDE.md → "Portal-Escape Pattern" for the full pitfall list.
        function idPortalEscape(id) {
            const el = document.getElementById(id);
            if (el && el.parentElement !== document.body) {
                document.body.appendChild(el);
            }
            return el;
        }

        /**
         * Unified Governance Modal Handler
         */
        function openGovModal(type, mode, data = null) {
            idPortalEscape('idGovModal');
            const m = document.getElementById('idGovModal');
            const f = document.getElementById('idGovForm');
            const title = document.getElementById('govModalTitle');
            const icon = document.getElementById('govModalIcon');
            
            f.reset();
            document.getElementById('govJustification').value = '';
            document.getElementById('govTargetType').value = type;
            document.getElementById('govTargetId').value = data ? data.id : '';
            document.getElementById('govAction').value = (mode === 'add' ? 'add_identity_gov' : 'save_identity_gov');
            
            // Set visuals based on type
            const govPosWrap = document.getElementById('govPositionWrap');
            const govJobWrap = document.getElementById('govJobTitleWrap');
            const govLineSection = document.getElementById('govLineLinkSection');
            if (type === 'admin') {
                title.textContent = (mode === 'add' ? 'เพิ่ม System Admin' : 'จัดการสิทธิ์ System Admin');
                icon.style.background = '#f5f3ff';
                icon.style.color = '#7c3aed';
                icon.innerHTML = '<i class="fa-solid fa-crown"></i>';
                document.getElementById('govAdminOnlyCard').style.display = 'block';
                document.getElementById('govEbCard').style.opacity = '0.5'; // Adms might not need borrow roles
                document.getElementById('govEcCard').style.opacity = '1';
                if (govPosWrap) govPosWrap.style.display = 'none';
                if (govJobWrap) govJobWrap.style.display = 'none';
                if (govLineSection) govLineSection.style.display = 'none';
            } else {
                title.textContent = (mode === 'add' ? 'เพิ่ม Staff Record' : 'จัดการสิทธิ์ Staff & Roles');
                icon.style.background = '#eff6ff';
                icon.style.color = '#2563eb';
                if (govPosWrap) govPosWrap.style.display = 'block';
                if (govJobWrap) govJobWrap.style.display = 'block';
                icon.innerHTML = '<i class="fa-solid fa-id-card-clip"></i>';
                document.getElementById('govAdminOnlyCard').style.display = 'none';
                document.getElementById('govEbCard').style.opacity = '1';
                document.getElementById('govEcCard').style.opacity = '1';
                if (govLineSection) govLineSection.style.display = (mode === 'edit') ? 'block' : 'none';
            }

            // Fill data if editing
            if (data) {
                document.getElementById('govFullName').value = data.full_name || '';
                document.getElementById('govUsername').value = data.username || '';
                document.getElementById('govEmail').value = data.email || '';
                document.getElementById('govStatus').value = data.account_status || data.status || 'active';
                
                    if (type === 'admin') {
                        document.getElementById('govAdminRole').value = data.role || 'admin';
                    } else {
                        document.getElementById('govEbAccess').checked = (data.access_eborrow === undefined) ? true : (parseInt(data.access_eborrow) === 1);
                        document.getElementById('govEbRole').value = data.role || 'employee';
                        document.getElementById('govEcAccess').checked = parseInt(data.access_ecampaign) === 1;
                        document.getElementById('govEcRole').value = data.ecampaign_role || 'editor';

                        document.getElementById('govInsAccess').checked = parseInt(data.access_insurance) === 1;
                        document.getElementById('govLogsAccess').checked = parseInt(data.access_system_logs) === 1;
                        document.getElementById('govSettAccess').checked = parseInt(data.access_site_settings) === 1;
                        document.getElementById('govRegAccess').checked = parseInt(data.access_registry) === 1;
                        document.getElementById('govEdmsAccess').checked = parseInt(data.access_edms) === 1;
                        document.getElementById('govEdmsSlaAdminAccess').checked = parseInt(data.access_edms_sla_admin) === 1;
                        document.getElementById('govAiAccess').checked = parseInt(data.access_ai) === 1;
                        document.getElementById('govConsumablesAccess').checked = parseInt(data.access_consumables) === 1;
                        document.getElementById('govAssetAccess').checked = parseInt(data.access_asset) === 1;
                        document.getElementById('govFinanceAccess').checked = parseInt(data.access_finance) === 1;
                        document.getElementById('govScholarshipAccess').checked = parseInt(data.access_scholarship) === 1;
                        document.getElementById('govDashboardAccess').checked = parseInt(data.access_dashboard_admin) === 1;
                        const mrEl = document.getElementById('govMonthlyReportAccess');
                        if (mrEl) mrEl.checked = parseInt(data.access_monthly_report) === 1;
                        const npEl = document.getElementById('govNurseProductivityAccess');
                        if (npEl) npEl.checked = parseInt(data.access_nurse_productivity) === 1;
                        const dsEl = document.getElementById('govDailySummaryAccess');
                        if (dsEl) dsEl.checked = parseInt(data.access_daily_summary) === 1;
                        const dvEl = document.getElementById('govDirectorViewAccess');
                        if (dvEl) dvEl.checked = parseInt(data.access_director_view) === 1;
                        const idEl = document.getElementById('govIdentityAccess');
                        if (idEl) idEl.checked = parseInt(data.access_identity) === 1;
                        const lineUidEl = document.getElementById('govLinkedLineUid');
                        if (lineUidEl) lineUidEl.value = data.linked_line_user_id || '';
                        // LINE picker — seed ค้นหาด้วยชื่อ staff + render สถานะปัจจุบัน (จับคู่ผ่าน System Users)
                        govLineInit(data.full_name || '', data.id || 0);
                        const deptSel = document.getElementById('govDepartmentId');
                        if (deptSel) deptSel.value = data.department_id ? String(data.department_id) : '';

                        // Position (Hybrid live link)
                        const posSel = document.getElementById('govPositionId');
                        if (posSel) {
                            posSel.value = data.position_id ? String(data.position_id) : '';
                            onGovPositionChange();
                        }

                        // Job title (free text) + Org chart position (read-only info)
                        const jt = document.getElementById('govJobTitle');
                        if (jt) jt.value = data.job_title || '';
                        const orgInfo = document.getElementById('govOrgPositionInfo');
                        const orgT = document.getElementById('govOrgPositionTitle');
                        if (orgInfo && orgT) {
                            if (data.org_position_title) {
                                orgT.textContent = data.org_position_title;
                                orgInfo.style.display = '';
                            } else {
                                orgInfo.style.display = 'none';
                            }
                        }
                    }
                } else {
                    // Reset Extension Checkboxes for new records
                    document.getElementById('govInsAccess').checked = false;
                    document.getElementById('govLogsAccess').checked = false;
                    document.getElementById('govSettAccess').checked = false;
                    document.getElementById('govRegAccess').checked = false;
                    document.getElementById('govEdmsAccess').checked = false;
                    document.getElementById('govEdmsSlaAdminAccess').checked = false;
                    document.getElementById('govAiAccess').checked = false;
                    document.getElementById('govConsumablesAccess').checked = false;
                    document.getElementById('govAssetAccess').checked = false;
                    document.getElementById('govFinanceAccess').checked = false;
                    document.getElementById('govScholarshipAccess').checked = false;
                    document.getElementById('govDashboardAccess').checked = false;
                    const mrElR = document.getElementById('govMonthlyReportAccess');
                    if (mrElR) mrElR.checked = false;
                    const npElR = document.getElementById('govNurseProductivityAccess');
                    if (npElR) npElR.checked = false;
                    const dsElR = document.getElementById('govDailySummaryAccess');
                    if (dsElR) dsElR.checked = false;
                    const dvElR = document.getElementById('govDirectorViewAccess');
                    if (dvElR) dvElR.checked = false;
                    const idElR = document.getElementById('govIdentityAccess');
                    if (idElR) idElR.checked = false;
                    const lineUidElR = document.getElementById('govLinkedLineUid');
                    if (lineUidElR) lineUidElR.value = '';
                    const deptSelR = document.getElementById('govDepartmentId');
                    if (deptSelR) deptSelR.value = '';
                    const posSel = document.getElementById('govPositionId');
                    if (posSel) { posSel.value = ''; onGovPositionChange(); }
                    const jtR = document.getElementById('govJobTitle');
                    if (jtR) jtR.value = '';
                    const orgInfoR = document.getElementById('govOrgPositionInfo');
                    if (orgInfoR) orgInfoR.style.display = 'none';
                }
            // Update UI States
            syncGovUI('govEbAccess', 'govEbRole', 'govEbCard');
            syncGovUI('govEcAccess', 'govEcRole', 'govEcCard');

            m.style.display = 'flex';
        }

        // ════════════════════════════════════════════════════════════════════
        // LINE Link picker — จับคู่ Staff กับ System Users (sys_users) ที่ผูก LINE แล้ว
        // เติม #govLinkedLineUid → บันทึกผ่าน save_identity_gov (validate U+32hex +
        // dedupe + audit ฝั่ง server). อ่านอย่างเดียว ไม่เขียน DB จาก JS
        // ════════════════════════════════════════════════════════════════════
        let _govLineStaffId = 0;

        function govLineEsc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
        }
        function govLineMaskUid(uid) {
            uid = String(uid || '');
            return uid.length > 8 ? uid.slice(0, 3) + '…' + uid.slice(-4) : uid;
        }

        function govLineInit(name, staffId) {
            _govLineStaffId = parseInt(staffId) || 0;
            const results = document.getElementById('govLineResults');
            if (results) results.innerHTML = '';
            govLineRenderCurrent();
            const uid = (document.getElementById('govLinkedLineUid')?.value || '').trim();
            const search = document.getElementById('govLineSearch');
            if (search) {
                search.value = name || '';
                // ยังไม่ผูก → auto-search ด้วยชื่อ staff เพื่อช่วย admin หาเร็ว
                if (!uid && (name || '').trim().length >= 2) govLineSearchUsers();
            }
        }

        function govLineRenderCurrent() {
            const box = document.getElementById('govLineCurrent');
            if (!box) return;
            const uid = (document.getElementById('govLinkedLineUid')?.value || '').trim();
            if (!uid) {
                box.innerHTML = '<div style="display:flex;align-items:center;gap:8px;font-size:12px;font-weight:700;color:#94a3b8">'
                    + '<i class="fa-regular fa-circle"></i> ยังไม่เชื่อมบัญชี LINE</div>';
                return;
            }
            box.innerHTML =
                '<div style="display:flex;align-items:center;gap:10px;background:#fff;border:1.5px solid #bbf7d0;border-radius:12px;padding:10px 12px">'
                + '<i class="fa-brands fa-line" style="color:#06c755;font-size:20px"></i>'
                + '<div style="flex:1;min-width:0">'
                + '<div style="font-size:12px;font-weight:900;color:#166534">เชื่อมแล้ว — กด "บันทึก" เพื่อยืนยัน</div>'
                + '<div style="font-size:11px;color:#64748b;font-weight:700;font-family:ui-monospace,monospace">' + govLineEsc(govLineMaskUid(uid)) + '</div>'
                + '</div>'
                + '<button type="button" onclick="govLineClear()" style="padding:6px 12px;border-radius:9px;border:1.5px solid #fecaca;background:#fff;color:#dc2626;font-weight:800;font-size:11px;cursor:pointer;white-space:nowrap">'
                + '<i class="fa-solid fa-link-slash"></i> ยกเลิกการเชื่อม</button>'
                + '</div>';
        }

        function govLineClear() {
            const el = document.getElementById('govLinkedLineUid');
            if (el) el.value = '';
            govLineRenderCurrent();
        }

        async function govLineSearchUsers() {
            const q = (document.getElementById('govLineSearch')?.value || '').trim();
            const results = document.getElementById('govLineResults');
            if (!results) return;
            if (q.length < 2) {
                results.innerHTML = '<div style="padding:10px;font-size:12px;color:#94a3b8;font-weight:700">พิมพ์อย่างน้อย 2 ตัวอักษร</div>';
                return;
            }
            results.innerHTML = '<div style="padding:14px;text-align:center;color:#94a3b8"><i class="fa-solid fa-spinner fa-spin"></i> กำลังค้นหา...</div>';
            try {
                const url = 'ajax_identity_line_match.php?q=' + encodeURIComponent(q) + '&staff_id=' + _govLineStaffId;
                const res = await fetch(url, { credentials: 'same-origin' });
                const data = await res.json();
                if (data.status !== 'success') throw new Error(data.message || 'ค้นหาไม่สำเร็จ');
                const rows = data.data || [];
                if (!rows.length) {
                    results.innerHTML = '<div style="padding:14px;text-align:center;font-size:12px;color:#94a3b8;font-weight:700">'
                        + '<i class="fa-solid fa-user-slash"></i> ไม่พบผู้ใช้ที่ผูก LINE ตรงกับคำค้นนี้</div>';
                    return;
                }
                results.innerHTML = rows.map(govLineRow).join('');
            } catch (e) {
                results.innerHTML = '<div style="padding:12px;font-size:12px;color:#dc2626;font-weight:700">ผิดพลาด: ' + govLineEsc(e.message) + '</div>';
            }
        }

        function govLineRow(r) {
            const pic = r.picture_url
                ? '<img src="' + govLineEsc(r.picture_url) + '" style="width:38px;height:38px;border-radius:50%;object-fit:cover;flex-shrink:0" onerror="this.style.display=\'none\'">'
                : '<div style="width:38px;height:38px;border-radius:50%;background:#dcfce7;color:#06c755;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="fa-brands fa-line"></i></div>';
            const sid = r.personnel_id ? '<span style="font-family:ui-monospace,monospace">' + govLineEsc(r.personnel_id) + '</span>' : '';
            const statusBadge = r.status ? '<span style="padding:1px 7px;border-radius:99px;background:#f1f5f9;color:#64748b;font-size:10px;font-weight:800">' + govLineEsc(r.status) + '</span>' : '';

            let action;
            if (!r.valid_format) {
                action = '<span title="UID ไม่ตรงรูปแบบ U+32hex — เชื่อมไม่ได้" style="font-size:10px;color:#b45309;font-weight:800;padding:6px 10px;white-space:nowrap">UID ไม่ถูกรูปแบบ</span>';
            } else if (r.linked_to) {
                action = '<span title="ผูกกับ ' + govLineEsc(r.linked_to) + ' แล้ว" style="font-size:10px;color:#b91c1c;font-weight:800;padding:6px 10px;white-space:nowrap"><i class="fa-solid fa-lock"></i> ผูกแล้ว: ' + govLineEsc(r.linked_to) + '</span>';
            } else {
                action = '<button type="button" onclick="govLinePick(\'' + govLineEsc(r.line_user_id) + '\')" style="padding:7px 14px;border-radius:9px;border:none;background:#06c755;color:#fff;font-weight:800;font-size:12px;cursor:pointer;white-space:nowrap">เลือก</button>';
            }

            return '<div style="display:flex;align-items:center;gap:10px;padding:8px;border:1px solid #e2e8f0;border-radius:12px;margin-bottom:8px;background:#fff">'
                + pic
                + '<div style="flex:1;min-width:0">'
                + '<div style="font-size:13px;font-weight:800;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + govLineEsc(r.full_name) + '</div>'
                + '<div style="display:flex;align-items:center;gap:8px;margin-top:2px;font-size:11px;color:#64748b;font-weight:700">' + sid + statusBadge + '</div>'
                + '</div>'
                + action
                + '</div>';
        }

        function govLinePick(uid) {
            const el = document.getElementById('govLinkedLineUid');
            if (el) el.value = uid;
            govLineRenderCurrent();
            const results = document.getElementById('govLineResults');
            if (results) results.innerHTML = '<div style="padding:8px 4px;font-size:11px;color:#16a34a;font-weight:800"><i class="fa-solid fa-circle-check"></i> เลือกแล้ว — กด "บันทึก" ด้านล่างเพื่อยืนยันการเชื่อม</div>';
        }

        /**
         * Position Modal — สร้าง / แก้ไข / ลบ ตำแหน่งงาน
         */
        const POS_FLAG_KEYS = [
            'access_eborrow','access_ecampaign','access_insurance','access_registry',
            'access_system_logs','access_site_settings','access_edms',
            'access_ai','access_consumables','access_asset','access_finance','access_scholarship',
            'access_dashboard_admin','access_monthly_report','access_nurse_productivity','access_daily_summary','access_director_view',
            'access_identity'
        ];

        function openAddPositionModal() {
            const modal = document.getElementById('idPosModal');
            if (!modal) return;
            document.getElementById('posModalTitle').textContent = 'สร้างตำแหน่งใหม่';
            document.getElementById('posAction').value = 'add_position';
            document.getElementById('posId').value = '';
            document.getElementById('posName').value = '';
            document.getElementById('posDescription').value = '';
            POS_FLAG_KEYS.forEach(k => {
                const cb = document.getElementById('posFlag_' + k);
                if (cb) cb.checked = false;
            });
            modal.style.display = 'flex';
        }

        function openEditPositionModal(pos) {
            const modal = document.getElementById('idPosModal');
            if (!modal) return;
            document.getElementById('posModalTitle').textContent = 'แก้ไขตำแหน่ง: ' + (pos.name || '');
            document.getElementById('posAction').value = 'edit_position';
            document.getElementById('posId').value = pos.id || '';
            document.getElementById('posName').value = pos.name || '';
            document.getElementById('posDescription').value = pos.description || '';
            let flags = {};
            try { flags = JSON.parse(pos.flags || '{}') || {}; } catch (e) { flags = {}; }
            POS_FLAG_KEYS.forEach(k => {
                const cb = document.getElementById('posFlag_' + k);
                if (cb) cb.checked = parseInt(flags[k]) === 1;
            });
            modal.style.display = 'flex';
        }

        function confirmDeletePosition(formEl, name, staffCount) {
            const msg = staffCount > 0
                ? `ต้องการลบตำแหน่ง "${name}"?\nstaff ${staffCount} คนที่ผูกอยู่จะถูกเปลี่ยนเป็น Custom (ติ๊ก flag เอง) อัตโนมัติ`
                : `ต้องการลบตำแหน่ง "${name}"?`;
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'ยืนยันการลบตำแหน่ง',
                    text: msg,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'ลบเลย',
                    cancelButtonText: 'ยกเลิก',
                    confirmButtonColor: '#ef4444',
                    cancelButtonColor: '#94a3b8',
                    reverseButtons: true
                }).then((r) => { if (r.isConfirmed) formEl.submit(); });
                return false;
            }
            return confirm(msg);
        }

        /**
         * Department CRUD — ผ่าน ajax_monthly_report.php (entity=department)
         * ใช้ SweetAlert2 form แทน modal แยก (form สั้นพอ)
         */
        async function deptAjax(action, payload) {
            const fd = new FormData();
            fd.append('entity', 'department');
            fd.append('action', action);
            fd.append('csrf_token', portal_CSRF);
            for (const [k, v] of Object.entries(payload)) fd.append(k, v);
            const r = await fetch('ajax_monthly_report.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            return r.json();
        }

        function deptFormHtml(dept) {
            const d = dept || {};
            const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
            return `
                <div style="text-align:left;display:flex;flex-direction:column;gap:12px">
                    <div>
                        <label style="display:block;font-size:12px;font-weight:900;color:#475569;margin-bottom:4px">ชื่อฝ่าย <span style="color:#ef4444">*</span></label>
                        <input id="swDeptName" type="text" value="${esc(d.name || '')}" class="swal2-input" style="margin:0;width:100%" placeholder="เช่น หน่วยบริการสุขภาพ">
                    </div>
                    <div>
                        <label style="display:block;font-size:12px;font-weight:900;color:#475569;margin-bottom:4px">คำอธิบาย (optional)</label>
                        <textarea id="swDeptDesc" class="swal2-textarea" style="margin:0;width:100%;min-height:60px" placeholder="หน้าที่หลักของฝ่ายนี้">${esc(d.description || '')}</textarea>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                        <div>
                            <label style="display:block;font-size:12px;font-weight:900;color:#475569;margin-bottom:4px">ลำดับการแสดง</label>
                            <input id="swDeptSort" type="number" value="${parseInt(d.sort_order ?? 0) || 0}" class="swal2-input" style="margin:0;width:100%">
                        </div>
                        <div>
                            <label style="display:block;font-size:12px;font-weight:900;color:#475569;margin-bottom:4px">สถานะ</label>
                            <select id="swDeptActive" class="swal2-select" style="margin:0;width:100%">
                                <option value="1" ${(d.active ?? 1) == 1 ? 'selected' : ''}>เปิดใช้งาน</option>
                                <option value="0" ${(d.active ?? 1) == 0 ? 'selected' : ''}>ปิด</option>
                            </select>
                        </div>
                    </div>
                </div>`;
        }

        async function openAddDeptModal() {
            const result = await Swal.fire({
                title: '<i class="fa-solid fa-plus" style="color:#6366f1"></i> เพิ่มฝ่ายใหม่',
                html: deptFormHtml(null),
                showCancelButton: true,
                confirmButtonText: 'บันทึก',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#6366f1',
                focusConfirm: false,
                preConfirm: () => {
                    const name = document.getElementById('swDeptName').value.trim();
                    if (!name) { Swal.showValidationMessage('กรุณาระบุชื่อฝ่าย'); return false; }
                    return {
                        name,
                        description: document.getElementById('swDeptDesc').value.trim(),
                        sort_order:  document.getElementById('swDeptSort').value || 0,
                        active:      document.getElementById('swDeptActive').value,
                    };
                }
            });
            if (!result.isConfirmed) return;
            const res = await deptAjax('save', result.value);
            if (res.status === 'ok') {
                await Swal.fire({ icon:'success', title:'เพิ่มเรียบร้อย', timer:1100, showConfirmButton:false });
                location.reload();
            } else {
                Swal.fire({ icon:'error', title:'บันทึกไม่สำเร็จ', text: res.message || '' });
            }
        }

        async function openEditDeptModal(dept) {
            const result = await Swal.fire({
                title: '<i class="fa-solid fa-pen-to-square" style="color:#6366f1"></i> แก้ไขฝ่าย',
                html: deptFormHtml(dept),
                showCancelButton: true,
                confirmButtonText: 'บันทึก',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#6366f1',
                focusConfirm: false,
                preConfirm: () => {
                    const name = document.getElementById('swDeptName').value.trim();
                    if (!name) { Swal.showValidationMessage('กรุณาระบุชื่อฝ่าย'); return false; }
                    return {
                        id: dept.id,
                        name,
                        description: document.getElementById('swDeptDesc').value.trim(),
                        sort_order:  document.getElementById('swDeptSort').value || 0,
                        active:      document.getElementById('swDeptActive').value,
                    };
                }
            });
            if (!result.isConfirmed) return;
            const res = await deptAjax('save', result.value);
            if (res.status === 'ok') {
                await Swal.fire({ icon:'success', title:'บันทึกเรียบร้อย', timer:1100, showConfirmButton:false });
                location.reload();
            } else {
                Swal.fire({ icon:'error', title:'บันทึกไม่สำเร็จ', text: res.message || '' });
            }
        }

        async function deleteDept(id, name, staffCount, reportCount) {
            if (reportCount > 0) {
                return Swal.fire({
                    icon:'error', title:'ลบไม่ได้',
                    html: `ฝ่าย "<b>${name}</b>" มีรายงาน ${reportCount} ฉบับในระบบ<br><span style="font-size:12px;color:#64748b">ต้องลบรายงานก่อน หรือเปลี่ยนสถานะเป็น "ปิด" แทน</span>`,
                });
            }
            const warn = staffCount > 0
                ? `ฝ่าย "${name}" มี staff ${staffCount} คนผูกอยู่<br>หลังลบ — ค่า department_id ของ staff ทั้งหมดจะกลายเป็น NULL`
                : `ลบฝ่าย "${name}"?`;
            const { isConfirmed } = await Swal.fire({
                icon:'warning', title:'ยืนยันการลบฝ่าย', html: warn,
                showCancelButton:true, confirmButtonText:'ลบเลย', cancelButtonText:'ยกเลิก',
                confirmButtonColor:'#ef4444', reverseButtons:true,
            });
            if (!isConfirmed) return;
            const res = await deptAjax('delete', { id });
            if (res.status === 'ok') {
                await Swal.fire({ icon:'success', title:'ลบเรียบร้อย', timer:1100, showConfirmButton:false });
                location.reload();
            } else {
                Swal.fire({ icon:'error', title:'ลบไม่สำเร็จ', text: res.message || '' });
            }
        }

        /**
         * Position change handler — Hybrid (Live Link)
         *   - มี position → load flag จาก position แล้ว disable checkboxes
         *   - Custom (NULL) → enable checkboxes ให้ติ๊กเอง
         */
        const GOV_FLAG_MAP = [
            ['access_eborrow',       'govEbAccess'],
            ['access_ecampaign',     'govEcAccess'],
            ['access_insurance',     'govInsAccess'],
            ['access_registry',      'govRegAccess'],
            ['access_system_logs',   'govLogsAccess'],
            ['access_site_settings', 'govSettAccess'],
            ['access_edms',          'govEdmsAccess'],
            ['access_edms_sla_admin','govEdmsSlaAdminAccess'],
            ['access_ai',            'govAiAccess'],
            ['access_consumables',   'govConsumablesAccess'],
            ['access_asset',         'govAssetAccess'],
            ['access_finance',       'govFinanceAccess'],
            ['access_scholarship',   'govScholarshipAccess'],
            ['access_dashboard_admin','govDashboardAccess'],
            ['access_monthly_report','govMonthlyReportAccess'],
            ['access_nurse_productivity','govNurseProductivityAccess'],
            ['access_daily_summary',     'govDailySummaryAccess'],
            ['access_director_view', 'govDirectorViewAccess'],
            ['access_identity',      'govIdentityAccess'],
        ];

        function onGovPositionChange() {
            const sel = document.getElementById('govPositionId');
            const note = document.getElementById('govPositionLockNote');
            if (!sel) return;

            const opt = sel.options[sel.selectedIndex];
            const flagsRaw = opt ? opt.getAttribute('data-flags') : null;
            const isCustom = !sel.value || !flagsRaw;

            if (isCustom) {
                if (note) note.style.display = 'none';
                GOV_FLAG_MAP.forEach(([key, id]) => {
                    const cb = document.getElementById(id);
                    if (!cb) return;
                    cb.disabled = false;
                    const card = cb.closest('.premium-role-card');
                    if (card) card.style.filter = 'none';
                });
            } else {
                let posFlags = {};
                try { posFlags = JSON.parse(flagsRaw) || {}; } catch (e) { posFlags = {}; }
                if (note) note.style.display = 'block';
                GOV_FLAG_MAP.forEach(([key, id]) => {
                    const cb = document.getElementById(id);
                    if (!cb) return;
                    cb.checked = parseInt(posFlags[key]) === 1;
                    cb.disabled = true;
                    const card = cb.closest('.premium-role-card');
                    if (card) card.style.filter = 'grayscale(0.4) opacity(0.85)';
                });
            }
        }

        /**
         * Toggle helper for the whole card
         */
        function toggleGovAccess(checkId, selectId, cardEl) {
            const cb = document.getElementById(checkId);
            cb.checked = !cb.checked;
            syncGovUI(checkId, selectId, cardEl.id);
        }

        /**
         * Visual Sync for Roles
         */
        function syncGovUI(checkId, selectId, cardId) {
            const cb = document.getElementById(checkId);
            const sel = document.getElementById(selectId);
            const card = document.getElementById(cardId);
            
            if (cb.checked) {
                sel.disabled = false;
                sel.style.opacity = '1';
                card.style.filter = 'none';
                card.style.background = (cardId === 'govEcCard' ? '#f0f7ff' : '#fffaf5');
            } else {
                sel.disabled = true;
                sel.style.opacity = '0.5';
                card.style.filter = 'grayscale(0.6)';
                card.style.background = '#f8fafc';
            }
        }


        function confirmGovSubmit() {
            const reason = document.getElementById('govJustification').value.trim();
            if (!reason) {
                Swal.fire({
                    title: 'ระบุเหตุผล',
                    text: 'กรุณากรอกเหตุผลความจำเป็นในการปรับสิทธิ์ก่อนบันทึกครับ (ISO 27001 Requirement)',
                    icon: 'warning',
                    confirmButtonColor: '#ef4444'
                });
                return;
            }

            Swal.fire({
                title: 'ยืนยันการบันทึกสิทธิ์?',
                text: "การเปลี่ยนแปลงสิทธิ์จะถูกบันทึกเข้าสู่ Audit Log พร้อมเหตุผลที่คุณระบุ และจะมีผลต่อการเข้าถึงระบบทันที",
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#2563eb',
                cancelButtonColor: '#94a3b8',
                confirmButtonText: 'ใช่, ยืนยันการบันทึก',
                cancelButtonText: 'ยกเลิก',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'กำลังบันทึกข้อมูล...',
                        text: 'กรุณารอสักครู่ ระบบกำลังดำเนินการปรับปรุงสิทธิ์และบันทึก Audit Log',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    document.getElementById('idGovForm').submit();
                }
            });
        }

        function idOpenEdit(u) {
            idPortalEscape('idEditModal');
            document.getElementById('id_edit_uid').value = u.id;
            document.getElementById('id_edit_name').value = u.full_name || '';
            document.getElementById('id_edit_citizen').value = u.citizen_id || '';
            document.getElementById('id_edit_sid').value = u.student_personnel_id || '';
            document.getElementById('id_edit_phone').value = u.phone_number || '';
            document.getElementById('id_edit_email').value = u.email || '';
            document.getElementById('id_edit_gender').value = u.gender || '';
            document.getElementById('id_edit_dept').value = u.department || '';
            document.getElementById('id_edit_status').value = u.status || '';
            document.getElementById('id_edit_sother').value = u.status_other || '';
            document.getElementById('id_edit_sother_wrap').style.display = u.status === 'other' ? 'block' : 'none';
            var m = document.getElementById('idEditModal');
            m.style.display = 'flex';
        }
        function idOpenView(u) {
            idPortalEscape('idViewModal');
            var statusMap = { student: 'นักศึกษา', staff: 'บุคลากร/อาจารย์', teacher: 'อาจารย์', other: 'บุคคลทั่วไป' };
            var genderMap = { male: 'ชาย', female: 'หญิง', other: 'อื่นๆ' };
            // Format helpers — kept inline so this stays a single self-contained
            // function that any partial can call without extra dependencies
            function fmtDate(s) {
                if (!s) return '—';
                var d = new Date(String(s).replace(' ', 'T'));
                if (isNaN(d.getTime())) return s;
                return d.toLocaleDateString('th-TH', { year:'numeric', month:'long', day:'numeric' });
            }
            function fmtDateTime(s) {
                if (!s) return '—';
                var d = new Date(String(s).replace(' ', 'T'));
                if (isNaN(d.getTime())) return s;
                return d.toLocaleString('th-TH', { year:'numeric', month:'long', day:'numeric', hour:'2-digit', minute:'2-digit' });
            }
            function consentPill(ts, ver) {
                if (!ts) return '<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:9999px;background:#fee2e2;color:#b91c1c;font-size:11px;font-weight:800"><i class="fa-solid fa-xmark"></i> ยังไม่ยินยอม</span>';
                return '<div><span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:9999px;background:#dcfce7;color:#15803d;font-size:11px;font-weight:800"><i class="fa-solid fa-check"></i> ยินยอม</span> <span style="font-size:11px;color:#475569;margin-left:4px">' + fmtDateTime(ts) + '</span>'
                    + (ver ? '<div style="font-family:ui-monospace,Menlo,monospace;font-size:10px;color:#7c3aed;margin-top:3px">' + ver + '</div>' : '')
                    + '</div>';
            }
            // Sections use a single-string entry as the header marker; tuples
            // are [label, value]; tuples with a 3rd "html" item bypass the
            // text-escape pipeline (used for the PDPA pill markup)
            var map = [
                ['__section', 'ข้อมูลพื้นฐาน'],
                ['ชื่อ-นามสกุล', (u.prefix ? u.prefix + ' ' : '') + (u.full_name || '')],
                ['เลขบัตรประชาชน', u.citizen_id],
                ['LINE User ID', u.line_user_id],
                ['Member ID (QR/เช็คอิน)', u.member_id],

                ['__section', 'ติดต่อ'],
                ['เบอร์โทรศัพท์', u.phone_number],
                ['อีเมล', u.email],

                ['__section', 'ข้อมูลส่วนตัว'],
                ['เพศ', genderMap[u.gender] || u.gender],
                ['วันเดือนปีเกิด', fmtDate(u.date_of_birth)],

                ['__section', 'สังกัด'],
                ['ประเภท', statusMap[u.status] || u.status],
                ['คณะ / หน่วยงาน', u.department],
                ['รหัสนักศึกษา / บุคลากร', u.student_personnel_id],
            ];
            if (u.status === 'other' && u.status_other) {
                map.push(['ระบุสถานภาพ', u.status_other]);
            }
            // Health data (Sec. 26 sensitive) — only render the section if at
            // least one value is set; backend masks values for non-superadmin
            var hasHealth = u.blood_type || u.height_cm || u.weight_kg || u.allergies || u.chronic_conditions;
            if (hasHealth) {
                map.push(['__section', 'ข้อมูลสุขภาพ (อ่อนไหว — มาตรา 26)']);
                if (u.blood_type)         map.push(['หมู่เลือด', u.blood_type]);
                if (u.height_cm)          map.push(['ส่วนสูง (ซม.)', u.height_cm]);
                if (u.weight_kg)          map.push(['น้ำหนัก (กก.)', u.weight_kg]);
                if (u.allergies)          map.push(['ประวัติแพ้ยา/อาหาร', u.allergies]);
                if (u.chronic_conditions) map.push(['โรคประจำตัว', u.chronic_conditions]);
            }
            // Emergency contact — same gating
            var hasEm = u.emergency_contact_name || u.emergency_contact_phone || u.emergency_contact_relation;
            if (hasEm) {
                map.push(['__section', 'ผู้ติดต่อกรณีฉุกเฉิน']);
                map.push(['ชื่อ-สกุล', u.emergency_contact_name]);
                map.push(['เบอร์โทร', u.emergency_contact_phone]);
                map.push(['ความสัมพันธ์', u.emergency_contact_relation]);
            }

            // PDPA consent — always shown; pills render even on NULL
            map.push(['__section', 'สถานะ PDPA Consent']);
            map.push(['ทั่วไป (มาตรา 24)',   consentPill(u.consent_general_accepted_at,   u.consent_general_version),   'html']);
            map.push(['อ่อนไหว (มาตรา 26)', consentPill(u.consent_sensitive_accepted_at, u.consent_sensitive_version), 'html']);
            if (u.consent_ip || u.consent_user_agent) {
                if (u.consent_ip)         map.push(['IP ตอนยินยอม', u.consent_ip]);
                if (u.consent_user_agent) map.push(['User-Agent', u.consent_user_agent]);
            }

            map.push(['__section', 'เวลา']);
            map.push(['วันที่ลงทะเบียน', fmtDateTime(u.created_at)]);

            // Render — XSS-safe except for explicitly opted-in html rows
            function esc(s) {
                return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                    return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
                });
            }
            document.getElementById('idViewBody').innerHTML = map.map(function (r) {
                if (r[0] === '__section') {
                    return '<div style="font-size:11px;font-weight:900;color:#4f46e5;text-transform:uppercase;letter-spacing:.12em;margin:8px 0 2px;padding-bottom:4px;border-bottom:1.5px solid #e0e7ff">' + esc(r[1]) + '</div>';
                }
                var isHtml = r[2] === 'html';
                var val = isHtml ? r[1] : esc(r[1] || '—');
                if (!isHtml && (r[1] === null || r[1] === undefined || r[1] === '')) val = '—';
                return '<div><div style="font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em;margin-bottom:3px">' + esc(r[0]) + '</div>'
                    + '<div style="padding:10px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;font-size:13px;font-weight:700;color:#0f172a;word-break:break-word">' + val + '</div></div>';
            }).join('');
            document.getElementById('idViewModal').style.display = 'flex';
        }
        /* ── Identity & Governance AJAX Pagination ── */
        (function () {
            var currentPage = 1;
            var pageSize = 25;
            var searchQuery = '';
            var isInitialLoad = true;

            function loadUsers() {
                var tbody = document.getElementById('idUserTbody');
                if (!tbody) return;

                // Show loading state
                tbody.style.opacity = '0.5';
                
                var url = 'ajax_identity_users.php?page=' + currentPage + '&pageSize=' + pageSize + '&search=' + encodeURIComponent(searchQuery);

                fetch(url)
                    .then(res => res.json())
                    .then(res => {
                        tbody.style.opacity = '1';
                        if (res.status === 'success') {
                            renderRows(res.data);
                            renderPagination(res.pagination);
                        } else {
                            tbody.innerHTML = '<tr><td colspan="4" style="padding:40px;text-align:center;color:#ef4444">เกิดข้อผิดพลาด: ' + res.message + '</td></tr>';
                        }
                    })
                    .catch(err => {
                        tbody.style.opacity = '1';
                        tbody.innerHTML = '<tr><td colspan="4" style="padding:40px;text-align:center;color:#ef4444">ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้</td></tr>';
                    });
            }

            function renderRows(users) {
                var tbody = document.getElementById('idUserTbody');
                if (!tbody) return;

                if (users.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="4" style="padding:60px;text-align:center;color:#94a3b8"><i class="fa-solid fa-ghost text-3xl mb-3 block"></i>ไม่พบข้อมูลผู้ใช้งาน</td></tr>';
                    return;
                }

                var statusMap = { student: 'นักศึกษา', staff: 'บุคลากร', other: 'บุคคลทั่วไป' };
                
                var html = users.map(function(u) {
                    var statusTH = statusMap[u.status] || u.status_other || 'ไม่ระบุ';
                    var initial = (u.full_name || '?').charAt(0);
                    var dateObj = new Date(u.created_at.replace(' ', 'T'));
                    var dateStr = dateObj.toLocaleDateString('th-TH', { day: '2-digit', month: 'short', year: 'numeric' });
                    var timeStr = dateObj.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });

                    return `
                        <tr style="border-bottom:1px solid #f1f5f9" class="id-user-row animate-fade-in">
                            <td style="padding:14px 20px">
                                <div style="display:flex;align-items:center;gap:12px">
                                    <div style="width:38px;height:38px;border-radius:11px;background:#f1f5f9;color:#64748b;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;flex-shrink:0">
                                        ${initial}
                                    </div>
                                    <div>
                                        <div style="font-weight:750;color:#0f172a">${u.full_name}</div>
                                        <div style="font-size:10px;color:#94a3b8;font-weight:700;margin-top:2px">
                                            #${u.student_personnel_id || '—'} · ${statusTH}
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td style="padding:14px 20px">
                                <div style="font-size:12px;color:#374151;font-weight:600">${u.phone_number || '—'}</div>
                                <div style="font-size:11px;color:#94a3b8;margin-top:2px">${u.email || '—'}</div>
                                ${(u.line_user_id_new || u.line_user_id) ? `
                                    <div style="font-size:10px;color:#06c755;margin-top:3px;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;display:flex;align-items:center;gap:4px"
                                         title="LINE User ID${u.line_user_id_new ? ' (Provider ใหม่)' : ' (Provider เดิม)'}">
                                        <i class="fa-brands fa-line"></i>
                                        <span>${u.line_user_id_new || u.line_user_id}</span>
                                    </div>` : ''}
                            </td>
                            <td style="padding:14px 20px">
                                <div style="font-size:12px;font-weight:700;color:#374151">${dateStr}</div>
                                <div style="font-size:10px;color:#94a3b8;margin-top:1px">${timeStr}</div>
                            </td>
                            <td style="padding:14px 20px;text-align:right">
                                <div style="display:flex;gap:6px;justify-content:flex-end">
                                    ${u.has_line ? `
                                    <button onclick="idTestLine(${u.id}, '${(u.full_name || '').replace(/'/g, '&apos;')}')"
                                        style="width:32px;height:32px;border-radius:8px;border:1px solid #d1fae5;background:#f0fdf4;color:#06c755;cursor:pointer;transition:all .15s"
                                        onmouseover="this.style.background='#dcfce7'"
                                        onmouseout="this.style.background='#f0fdf4'"
                                        title="ทดสอบส่งข้อความ LINE">
                                        <i class="fa-brands fa-line" style="font-size:13px"></i>
                                    </button>` : ''}
                                    <button onclick='idOpenView(${JSON.stringify(u).replace(/'/g, "&apos;")})'
                                        style="width:32px;height:32px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;color:#64748b;cursor:pointer;transition:all .15s"
                                        title="ดูข้อมูล">
                                        <i class="fa-solid fa-eye" style="font-size:11px"></i>
                                    </button>
                                    <button onclick='idOpenEdit(${JSON.stringify(u).replace(/'/g, "&apos;")})'
                                        style="width:32px;height:32px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;color:#64748b;cursor:pointer;transition:all .15s"
                                        title="แก้ไข">
                                        <i class="fa-solid fa-pen" style="font-size:11px"></i>
                                    </button>
                                    <a href="../admin/user_history.php?id=${u.id}&redirect_back=${encodeURIComponent('../portal/identity.php')}"
                                        style="width:32px;height:32px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;color:#64748b;display:flex;align-items:center;justify-content:center;text-decoration:none;transition:all .15s"
                                        onmouseover="this.style.background='#fffbeb';this.style.color='#d97706'"
                                        onmouseout="this.style.background='#fff';this.style.color='#64748b'"
                                        title="ประวัติการใช้งาน">
                                        <i class="fa-solid fa-clock-rotate-left" style="font-size:11px"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>`;
                }).join('');
                tbody.innerHTML = html;
            }

            // ── Test LINE push (ปุ่มสีเขียว LINE ในตาราง) ──
            window.idTestLine = async function(userId, fullName) {
                const conf = await Swal.fire({
                    icon: 'question',
                    title: 'ทดสอบส่งข้อความ LINE',
                    html: `ส่งข้อความทดสอบไปยัง <b>${fullName || 'user นี้'}</b>?<br><span style="font-size:12px;color:#64748b">ข้อความจะมี [ทดสอบ] นำหน้า · ผู้รับจะรู้ว่าเป็น test</span>`,
                    showCancelButton: true,
                    confirmButtonText: 'ส่งเลย',
                    cancelButtonText: 'ยกเลิก',
                    confirmButtonColor: '#06c755',
                });
                if (!conf.isConfirmed) return;

                Swal.fire({ title: 'กำลังส่ง...', didOpen: () => Swal.showLoading(), allowOutsideClick: false });

                try {
                    const fd = new FormData();
                    fd.append('csrf_token', portal_CSRF);
                    fd.append('user_id', String(userId));
                    const r = await fetch('ajax_identity_test_line.php', { method: 'POST', body: fd });
                    const j = await r.json();
                    if (j.ok) {
                        Swal.fire({
                            icon: 'success',
                            title: 'ส่งสำเร็จ',
                            html: `<div style="text-align:left;font-size:14px"><div>✓ ส่งไปยัง <code style="font-family:ui-monospace;background:#f1f5f9;padding:1px 5px;border-radius:4px">${j.target_masked || '—'}</code></div><div style="margin-top:6px;font-size:12px;color:#64748b">UID source: ${j.source === 'new' ? 'Provider ใหม่' : 'Provider เดิม'}</div></div>`,
                            confirmButtonColor: '#059669',
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'ส่งไม่สำเร็จ',
                            html: `<div style="text-align:left;font-size:14px">${(j.error || 'unknown').replace(/</g, '&lt;')}${j.target_masked ? `<div style="margin-top:6px;font-size:12px;color:#64748b">UID: <code>${j.target_masked}</code> (${j.source === 'new' ? 'ใหม่' : 'เดิม'})</div>` : ''}</div>`,
                            confirmButtonColor: '#dc2626',
                        });
                    }
                } catch(e) {
                    Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: String(e) });
                }
            };

            function renderPagination(p) {
                var info = document.getElementById('id-page-info');
                if (info) {
                    var from = p.total === 0 ? 0 : (p.page - 1) * p.pageSize + 1;
                    var to = Math.min(p.page * p.pageSize, p.total);
                    info.textContent = p.total === 0 ? 'ไม่พบรายการ' : from + '–' + to + ' จาก ' + p.total.toLocaleString();
                }

                var prev = document.getElementById('id-page-prev');
                var next = document.getElementById('id-page-next');
                if (prev) {
                    prev.disabled = p.page <= 1;
                    prev.style.opacity = p.page <= 1 ? '.35' : '1';
                }
                if (next) {
                    next.disabled = p.page >= p.totalPages;
                    next.style.opacity = p.page >= p.totalPages ? '.35' : '1';
                }
            }

            window.idUniversalFilter = function (val) {
                // If on users tab, use AJAX. Otherwise use client-side filter
                const activeTab = document.querySelector('.id-tab.active');
                if (activeTab && activeTab.dataset.tab === 'users') {
                    searchQuery = val;
                    currentPage = 1;
                    clearTimeout(window._idSearchTimer);
                    window._idSearchTimer = setTimeout(loadUsers, 400);
                } else {
                    // Original client-side filter for admins/staff
                    val = val.toLowerCase().trim();
                    const activePanel = document.querySelector('.id-panel.active');
                    if (!activePanel) return;
                    const rows = activePanel.querySelectorAll('tbody tr');
                    rows.forEach(row => {
                        if (row.cells.length < 2) return;
                        row.style.display = row.innerText.toLowerCase().includes(val) ? '' : 'none';
                    });
                }
            };

            window.idSetPageSize = function (size) {
                pageSize = size;
                currentPage = 1;
                loadUsers();
                document.querySelectorAll('.id-ps-btn').forEach(function (b) {
                    var active = parseInt(b.dataset.size) === size;
                    b.style.background = active ? '#2e9e63' : '#f8fafc';
                    b.style.color = active ? '#fff' : '#374151';
                    b.style.borderColor = active ? '#2e9e63' : '#e2e8f0';
                });
            };

            window.idPrevPage = function () { if (currentPage > 1) { currentPage--; loadUsers(); } };
            window.idNextPage = function () { currentPage++; loadUsers(); };

            if (isInitialLoad) {
                isInitialLoad = false;
                loadUsers();
            }
        })();

        /**
         * switchIdTab - Handles switching between Identity sub-panels
         */
        function switchIdTab(tabName, btn) {
            // Update tabs
            document.querySelectorAll('.id-tab').forEach(b => b.classList.remove('active'));
            if (btn) btn.classList.add('active');

            // Update panels
            document.querySelectorAll('.id-panel').forEach(p => p.classList.remove('active'));
            const targetPanel = document.getElementById('id-panel-' + tabName);
            if (targetPanel) targetPanel.classList.add('active');

            // Show/Hide relevant Add buttons (Superadmin only)
            const addAdmin = document.getElementById('id-btn-add-admin');
            const addStaff = document.getElementById('id-btn-add-staff');
            if (addAdmin) addAdmin.style.display = (tabName === 'admins') ? 'block' : 'none';
            if (addStaff) addStaff.style.display = (tabName === 'staff') ? 'block' : 'none';
        }

        // Close modals on backdrop click
        ['idEditModal', 'idViewModal', 'idGovModal', 'privModal'].forEach(function (id) {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('click', function (e) {
                    if (e.target === this) this.style.display = 'none';
                });
            }
        });

        // Auto-switch section from URL ?section=...
        // PHP already rendered the correct section server-side, so on initial
        // load we just need to highlight the sidebar button — NOT call
        // switchSection (which strips cd_view/s/p and would break sub-view
        // pagination on refresh).
        (function () {
            var params = new URLSearchParams(window.location.search);
            var sec = params.get('section');
            // หลัง multi-page refactor URL ไม่มี ?section= แล้ว — derive จาก pathname
            // เช่น /portal/identity.php → sec = 'identity'
            if (!sec) {
                var m = window.location.pathname.match(/\/([a-z_]+)\.php$/i);
                if (m) sec = m[1];
            }
            var tab = params.get('tab');
            if (sec) {
                var btn = document.querySelector('.psb-item[data-section="' + sec + '"]');
                if (btn) {
                    document.querySelectorAll('.psb-item').forEach(function (b) {
                        b.classList.remove('psb-active');
                        b.removeAttribute('aria-current');
                    });
                    btn.classList.add('psb-active');
                    btn.setAttribute('aria-current', 'page');
                }
            }
            if (sec === 'identity' && tab) {
                var tabBtn = document.querySelector('.id-tab[data-tab="' + tab + '"]');
                if (tabBtn) switchIdTab(tab, tabBtn);
            }
            // Auto-dismiss toast
            var toast = document.getElementById('id-toast');
            if (toast) setTimeout(function () { toast.style.transition = 'opacity .5s'; toast.style.opacity = '0'; setTimeout(function () { toast.remove(); }, 500); }, 3000);
        })();

        // Pause when tab hidden, resume when visible
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                clearInterval(pollTimer);
                pollTimer = null;
            } else {
                poll();
                pollTimer = setInterval(poll, POLL_INTERVAL);
            }
        });

        /* ── Maintenance Mode Logic (Merged from Admin Tool) ─────────────────────── */
        const portal_CSRF = <?= json_encode(get_csrf_token()) ?>;
        const HAS_ACCESS_FINANCE = <?= json_encode($isSuper || $adminRole === 'admin' || !empty($_SESSION['access_finance'])) ?>;
        const SHOW_LINE_LINK_PROMPT = <?= json_encode($_showLineLinkPrompt ?? false) ?>;

        /* ── LINE Link Prompt — แจ้งให้ staff ผูก LINE ตอน login ครั้งแรกของ session ── */
        (function(){
            if (!SHOW_LINE_LINK_PROMPT) return;
            // skip ถ้า dismiss ใน session นี้แล้ว
            if (sessionStorage.getItem('line_link_dismissed') === '1') return;
            // skip ถ้าอยู่ในหน้า profile แล้ว (user เห็น link button อยู่)
            const activeSection = <?= json_encode($activeSection) ?>;
            if (activeSection === 'profile') return;

            // ดีเลย์เล็กน้อยกัน flash UI ตอนเข้าเว็บ
            const showPrompt = () => {
                if (!window.Swal) {
                    // SweetAlert2 ยังไม่โหลด → retry
                    setTimeout(showPrompt, 400);
                    return;
                }
                Swal.fire({
                    title: '<i class="fa-brands fa-line" style="color:#06c755"></i> เชื่อมต่อบัญชี LINE',
                    html: `
                        <div style="text-align:left;font-size:14px;color:#475569;line-height:1.7">
                            <p style="margin-bottom:12px">
                                <strong style="color:#0f172a">ยังไม่ได้ผูก LINE กับบัญชี Staff</strong>
                            </p>
                            <p style="margin-bottom:8px">เมื่อผูกแล้วจะได้รับการแจ้งเตือนผ่าน LINE สำหรับ:</p>
                            <ul style="list-style:none;padding-left:0;margin:0 0 14px;font-size:13px">
                                <li style="padding:3px 0">
                                    <i class="fa-solid fa-bell" style="color:#f59e0b;width:18px"></i>
                                    SLA warning / breach / escalation
                                </li>
                                <li style="padding:3px 0">
                                    <i class="fa-solid fa-envelope-open-text" style="color:#0ea5e9;width:18px"></i>
                                    เอกสารใหม่ที่ถูกมอบหมาย
                                </li>
                                <li style="padding:3px 0">
                                    <i class="fa-solid fa-circle-info" style="color:#a855f7;width:18px"></i>
                                    การแจ้งเตือนสำคัญอื่นๆ จากระบบ
                                </li>
                            </ul>
                            <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#64748b;cursor:pointer;padding:8px;background:#f8fafc;border-radius:8px">
                                <input type="checkbox" id="line-prompt-dontshow" style="width:14px;height:14px;accent-color:#dc2626">
                                <span>ไม่ต้องเตือนอีก</span>
                            </label>
                        </div>
                    `,
                    showCancelButton: true,
                    confirmButtonText: '<i class="fa-brands fa-line"></i> เชื่อมต่อเลย',
                    cancelButtonText: 'ไว้ทีหลัง',
                    confirmButtonColor: '#06c755',
                    cancelButtonColor: '#94a3b8',
                    reverseButtons: true,
                    focusConfirm: false,
                    allowOutsideClick: false,
                    customClass: { popup: 'rounded-3xl' },
                }).then(result => {
                    const dontShowAgain = document.getElementById('line-prompt-dontshow')?.checked;

                    if (result.isConfirmed) {
                        // เชื่อมต่อ — redirect ทันที (ไม่ต้องสน checkbox)
                        window.location.href = '../line_api/staff_link_line.php';
                        return;
                    }

                    // ไว้ทีหลัง
                    sessionStorage.setItem('line_link_dismissed', '1');

                    if (dontShowAgain) {
                        // ปิดถาวร via AJAX
                        const fd = new FormData();
                        fd.append('csrf_token', portal_CSRF);
                        fd.append('action', 'dismiss_link_prompt');
                        fetch('ajax_profile_line.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                            .then(r => r.json())
                            .then(r => {
                                if (r.ok && window.Swal) {
                                    Swal.fire({
                                        icon: 'info', title: 'ปิดการเตือนแล้ว',
                                        text: 'คุณยังเชื่อม LINE ได้ที่หน้าโปรไฟล์',
                                        timer: 1800, showConfirmButton: false, toast: true, position: 'top-end',
                                    });
                                }
                            })
                            .catch(() => {});
                    }
                });
            };
            // ดีเลย์ 600ms กัน flash + ให้ portal render เสร็จ
            setTimeout(showPrompt, 600);
        })();

        function showPortalToast(msg, type = 'success') {
            const id = 'portal-runtime-toast';
            let t = document.getElementById(id);
            if (!t) {
                t = document.createElement('div');
                t.id = id;
                t.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;padding:12px 20px;border-radius:14px;font-size:13px;font-weight:700;box-shadow:0 4px 20px rgba(0,0,0,.12);transform:translateY(80px);opacity:0;transition:all .3s cubic-bezier(.16,1,.3,1);pointer-events:none;';
                document.body.appendChild(t);
            }
            t.textContent = msg;
            t.style.background = type === 'success' ? '#f0fdf4' : '#fef2f2';
            t.style.color = type === 'success' ? '#16a34a' : '#dc2626';
            t.style.border = type === 'success' ? '1.5px solid #bbf7d0' : '1.5px solid #fecaca';

            t.style.transform = 'translateY(0)';
            t.style.opacity = '1';
            clearTimeout(t._tid);
            t._tid = setTimeout(() => {
                t.style.transform = 'translateY(80px)';
                t.style.opacity = '0';
            }, 3000);
        }

        function updateMaintenanceUI(project, active) {
            const badge = document.getElementById('badge-' + project);
            if (badge) {
                badge.className = 'status-badge ' + (active ? 'on' : 'off');
                badge.innerHTML = `<span class="status-dot"></span>${active ? 'เปิดใช้งาน' : 'ปรับปรุง'}`;
                badge.classList.remove('badge-pop');
                void badge.offsetWidth;
                badge.classList.add('badge-pop');
            }

            // Update main status banner
            const toggles = document.querySelectorAll('[data-project]');
            const allOn = Array.from(toggles).every(t => t.checked);
            const banner = document.getElementById('status-banner');
            if (banner) {
                banner.dataset.state = allOn ? 'ok' : 'warn';
                const icon = document.getElementById('banner-icon');
                const title = document.getElementById('banner-title');
                const desc = document.getElementById('banner-desc');

                if (icon) icon.className = `fa-solid ${allOn ? 'fa-circle-check' : 'fa-triangle-exclamation'} text-base`;
                if (title) title.textContent = allOn ? 'ระบบทุกโปรเจกต์พร้อมใช้งาน' : 'มีบางโปรเจกต์ปิดปรับปรุงอยู่';
                if (desc) desc.textContent = allOn ? 'User ทุกคนสามารถเข้าใช้งานได้ตามปกติ' : 'คุณสามารถคลิกเปิดระบบได้จากรายการด้านล่าง';

                const iconWrap = icon?.parentElement;
                if (iconWrap) iconWrap.style.cssText = allOn ? 'background:#dcfce7;color:#16a34a' : 'background:#fef3c7;color:#d97706';
            }
        }

        function toggleMaintenance(input) {
            const project = input.dataset.project;
            const active = input.checked;
            const actionText = active ? 'เปิดใช้งาน' : 'ปิดปรับปรุง';
            const confirmText = active ? 'ใช่, เปิดระบบ' : 'ใช่, ปิดปรับปรุงระบบ';
            const confirmColor = active ? '#10b981' : '#f43f5e';

            // Reset input state immediately (we will set it after confirmation)
            input.checked = !active;

            Swal.fire({
                title: `ยืนยันการ${actionText}ระบบ?`,
                text: `คุณกำลังจะทำการ${actionText}โปรเจกต์ ${project} ยืนยันการดำเนินการหรือไม่?`,
                icon: active ? 'info' : 'warning',
                showCancelButton: true,
                confirmButtonColor: confirmColor,
                cancelButtonColor: '#94a3b8',
                confirmButtonText: confirmText,
                cancelButtonText: 'ยกเลิก',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    // Proceed with update
                    input.checked = active;
                    updateMaintenanceUI(project, active);

                    const fd = new FormData();
                    fd.append('action', 'set');
                    fd.append('project', project);
                    fd.append('active', active ? '1' : '0');
                    fd.append('csrf_token', portal_CSRF);

                    fetch('ajax_maintenance.php', { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(d => {
                            if (d.ok) {
                                showPortalToast(active ? `${project} เปิดใช้งานแล้ว` : `${project} ปิดปรับปรุงแล้ว`, active ? 'success' : 'error');
                            } else {
                                input.checked = !active;
                                updateMaintenanceUI(project, !active);
                                Swal.fire('ผิดพลาด', d.message || 'Unknown error', 'error');
                            }
                        })
                        .catch(() => {
                            input.checked = !active;
                            updateMaintenanceUI(project, !active);
                            showPortalToast('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'error');
                        });
                }
            });
        }

        // ── ฟังก์ชัน Announcement Form ─────────────────────────────────────────
        window.annOpenForm = function(mode, data) {
            const modal = document.getElementById('ann-form-modal');
            document.getElementById('ann-form-title').textContent = mode === 'create' ? 'สร้างประกาศใหม่' : 'แก้ไขประกาศ';
            document.getElementById('ann-form-action').value      = mode;
            document.getElementById('ann-form-id').value          = data ? data.id : 0;
            document.getElementById('ann-f-title').value          = data ? (data.title    || '') : '';
            document.getElementById('ann-f-title-en').value       = data ? (data.title_en || '') : '';
            document.getElementById('ann-f-content').value        = data ? (data.content  || '') : '';
            document.getElementById('ann-f-content-en').value      = data ? (data.content_en|| '') : '';
            document.getElementById('ann-f-type').value           = data ? (data.type || 'info') : 'info';
            document.getElementById('ann-f-audience').value       = data ? (data.target_audience || 'all') : 'all';
            document.getElementById('ann-f-start').value          = data ? (data.start_date || '') : '';
            document.getElementById('ann-f-end').value            = data ? (data.end_date   || '') : '';
            document.getElementById('ann-f-priority').value       = data ? (data.priority || 0) : 0;
            document.getElementById('ann-f-active').checked       = data ? (parseInt(data.is_active) === 1) : true;
            document.getElementById('ann-f-show-once').checked    = data ? (parseInt(data.show_once) === 1) : true;

            // ── Image preview / state ───────────────────────────────────
            const existingUrl = data ? (data.image_url || '') : '';
            document.getElementById('ann-f-image-existing').value = existingUrl;
            document.getElementById('ann-f-image-clear').value    = '';
            document.getElementById('ann-f-image-file').value     = '';
            const wrap = document.getElementById('ann-image-preview-wrap');
            const img  = document.getElementById('ann-image-preview');
            const name = document.getElementById('ann-image-preview-name');
            if (existingUrl) {
                img.src = existingUrl;
                name.textContent = existingUrl.split('/').pop();
                wrap.style.display = 'block';
            } else {
                img.src = '';
                name.textContent = '';
                wrap.style.display = 'none';
            }
            modal.style.display = 'flex';
        };

        // เคลียร์รูป (ทั้งของเดิมและที่เพิ่งเลือก) — ติด flag ให้ฝั่ง server รู้ว่าต้อง NULL
        window.annClearImage = function() {
            document.getElementById('ann-f-image-file').value     = '';
            document.getElementById('ann-f-image-existing').value = '';
            document.getElementById('ann-f-image-clear').value    = '1';
            const wrap = document.getElementById('ann-image-preview-wrap');
            document.getElementById('ann-image-preview').src = '';
            document.getElementById('ann-image-preview-name').textContent = '';
            wrap.style.display = 'none';
        };

        // เมื่อเลือกไฟล์ใหม่ → แสดง preview + ตรวจขนาด
        document.getElementById('ann-f-image-file')?.addEventListener('change', function(e) {
            const file = e.target.files && e.target.files[0];
            if (!file) return;
            const maxBytes = 5 * 1024 * 1024;
            if (file.size > maxBytes) {
                Swal.fire({ icon: 'warning', title: 'ไฟล์ใหญ่เกินไป', text: 'รองรับสูงสุด 5 MB' });
                e.target.value = '';
                return;
            }
            const allowed = ['image/jpeg','image/png','image/webp','image/gif'];
            if (!allowed.includes(file.type)) {
                Swal.fire({ icon: 'warning', title: 'ชนิดไฟล์ไม่รองรับ', text: 'รองรับเฉพาะ JPG / PNG / WebP / GIF' });
                e.target.value = '';
                return;
            }
            const reader = new FileReader();
            reader.onload = function(ev) {
                document.getElementById('ann-image-preview').src = ev.target.result;
                document.getElementById('ann-image-preview-name').textContent = file.name;
                document.getElementById('ann-image-preview-wrap').style.display = 'block';
                // เลือกไฟล์ใหม่ = ไม่ต้อง clear (server จะใช้ไฟล์ใหม่แทน existing เอง)
                document.getElementById('ann-f-image-clear').value = '';
            };
            reader.readAsDataURL(file);
        });

        // Drag & drop
        (function() {
            const dz = document.getElementById('ann-image-drop');
            if (!dz) return;
            ['dragenter','dragover'].forEach(ev => dz.addEventListener(ev, e => {
                e.preventDefault(); e.stopPropagation();
                dz.style.borderColor = '#7c3aed';
                dz.style.background = '#f5f3ff';
            }));
            ['dragleave','drop'].forEach(ev => dz.addEventListener(ev, e => {
                e.preventDefault(); e.stopPropagation();
                dz.style.borderColor = '#cbd5e1';
                dz.style.background = '#f8fafc';
            }));
            dz.addEventListener('drop', e => {
                const file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
                if (!file) return;
                const input = document.getElementById('ann-f-image-file');
                const dt = new DataTransfer();
                dt.items.add(file);
                input.files = dt.files;
                input.dispatchEvent(new Event('change'));
            });
        })();

        window.annCloseForm = function() {
            document.getElementById('ann-form-modal').style.display = 'none';
        };

        window.annConfirmDelete = function(id, title) {
            Swal.fire({
                title: 'ลบประกาศ?',
                html: `ต้องการลบประกาศ <b>"${title}"</b> ออกจากระบบ?<br><small style="color:#94a3b8">การลบจะไม่สามารถกู้คืนได้</small>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#94a3b8',
                confirmButtonText: 'ลบเลย',
                cancelButtonText: 'ยกเลิก',
            }).then(result => {
                if (result.isConfirmed) {
                    document.getElementById('ann-delete-id').value = id;
                    document.getElementById('ann-delete-form').submit();
                }
            });
        };

        document.getElementById('ann-form-modal')?.addEventListener('click', function(e) {
            if (e.target === this) window.annCloseForm();
        });

        <?php if ($ann_saved): ?>
        switchSection('announcements', document.querySelector('[data-section="announcements"]'));
        <?php endif; ?>

    </script>

    <!-- ════════════════════════════════════════════════════════════
         COMMAND PALETTE (⌘K) — added by /overdrive
         ════════════════════════════════════════════════════════════ -->
    <div id="cmdk-overlay" class="cmdk-overlay" role="dialog" aria-modal="true" aria-labelledby="cmdk-title" hidden>
        <div class="cmdk-panel" role="document">
            <div class="cmdk-search-wrap">
                <i class="fa-solid fa-magnifying-glass cmdk-search-icon" aria-hidden="true"></i>
                <input type="text" id="cmdk-input" class="cmdk-input"
                       placeholder="พิมพ์เพื่อค้นหาคำสั่ง / ระบบ / หน้า…"
                       aria-label="ค้นหาคำสั่ง"
                       autocomplete="off" spellcheck="false">
                <kbd class="cmdk-esc" aria-hidden="true">ESC</kbd>
            </div>
            <ul id="cmdk-list" class="cmdk-list" role="listbox" aria-label="ผลการค้นหา"></ul>
            <div class="cmdk-foot">
                <span><kbd>↑</kbd><kbd>↓</kbd> เลื่อน</span>
                <span><kbd>↵</kbd> เลือก</span>
                <span><kbd>ESC</kbd> ปิด</span>
                <span class="ml-auto cmdk-help-hint">กด <kbd>?</kbd> ดูคีย์ลัด</span>
            </div>
        </div>
    </div>

    <!-- Keyboard shortcuts help modal -->
    <div id="kbd-help-overlay" class="cmdk-overlay" role="dialog" aria-modal="true" aria-labelledby="kbd-help-title" hidden>
        <div class="cmdk-panel cmdk-panel--small">
            <div class="cmdk-help-head">
                <h2 id="kbd-help-title" class="font-bold text-slate-800 text-base">คีย์ลัด</h2>
                <button class="cmdk-close" onclick="kbdHelpClose()" aria-label="ปิด">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <dl class="kbd-help-list">
                <div><kbd>⌘</kbd>+<kbd>K</kbd> <span>เปิด Command Palette</span></div>
                <div><kbd>g</kbd> <kbd>d</kbd> <span>ไปหน้า Dashboard</span></div>
                <div><kbd>g</kbd> <kbd>i</kbd> <span>ไป Identity & Governance</span></div>
                <div><kbd>g</kbd> <kbd>a</kbd> <span>ไปประกาศ</span></div>
                <div><kbd>g</kbd> <kbd>e</kbd> <span>ไป Error Logs</span></div>
                <div><kbd>g</kbd> <kbd>s</kbd> <span>ไป Settings</span></div>
                <div><kbd>g</kbd> <kbd>r</kbd> <span>ไปครุภัณฑ์สำนักงาน</span></div>
                <div><kbd>/</kbd> <span>โฟกัสช่องค้นหา</span></div>
                <div><kbd>?</kbd> <span>เปิดคีย์ลัด (หน้านี้)</span></div>
                <div><kbd>ESC</kbd> <span>ปิด modal / palette</span></div>
            </dl>
        </div>
    </div>

    <script>
    (function () {
        // ── User permission map (from PHP session) ──────────────────────────
        const USER_ACCESS = {
            isSuper:         <?= json_encode($isSuper) ?>,
            isAdminRole:     <?= json_encode($adminRole === 'admin') ?>,
            access_eborrow:    <?= json_encode((bool)($_SESSION['access_eborrow']    ?? 0)) ?>,
            access_ecampaign:  <?= json_encode((bool)($_SESSION['access_ecampaign']  ?? 0)) ?>,
            access_insurance:  <?= json_encode((bool)($_SESSION['access_insurance']  ?? 0)) ?>,
            access_registry:   <?= json_encode((bool)($_SESSION['access_registry']   ?? 0)) ?>,
            access_edms:       <?= json_encode((bool)($_SESSION['access_edms']       ?? 0)) ?>,
            access_ai:         <?= json_encode((bool)($_SESSION['access_ai']         ?? 0)) ?>,
            access_asset:      <?= json_encode((bool)($_SESSION['access_asset']      ?? 0)) ?>,
            access_consumables:<?= json_encode((bool)($_SESSION['access_consumables']?? 0)) ?>,
            access_finance:    <?= json_encode((bool)($_SESSION['access_finance']    ?? 0)) ?>,
            access_system_logs:<?= json_encode((bool)($_SESSION['access_system_logs']?? 0)) ?>,
            access_site_settings:<?= json_encode((bool)($_SESSION['access_site_settings']?? 0)) ?>,
            access_identity:   <?= json_encode((bool)($_SESSION['access_identity']   ?? 0)) ?>,
        };
        // Helper: check ว่า user มีสิทธิ์ตาม access string
        // Accepts: 'superadmin', 'admin', 'access_xxx', หรือ array รวมกัน (OR)
        function hasAccess(req) {
            if (!req) return true;  // no restriction
            const arr = Array.isArray(req) ? req : [req];
            return arr.some(k => {
                if (k === 'superadmin') return USER_ACCESS.isSuper;
                if (k === 'admin')      return USER_ACCESS.isAdminRole || USER_ACCESS.isSuper;
                return USER_ACCESS[k] === true;
            });
        }

        // ── Command catalog ──────────────────────────────────────────────
        // type: 'section' = call switchSection, 'url' = navigate
        // access: optional string/array — ถ้าไม่ระบุ = allow ทุกคน
        const ALL_COMMANDS = [
            { id: 'dashboard',     label: 'Dashboard',           desc: 'ภาพรวม + งานวันนี้', shortcut: 'g d', icon: 'fa-chart-pie',          tone: 'success', type: 'section', target: 'dashboard' },
            { id: 'ai_assistant',  label: 'AI Assistant',        desc: 'ผู้ช่วย AI',         icon: 'fa-wand-magic-sparkles', tone: 'accent', type: 'section', target: 'ai_assistant', access: ['access_ai','superadmin'] },
            { id: 'ai_qa_lab',     label: 'AI QA Lab',           desc: 'Sandbox คำถามจาก user', icon: 'fa-flask-vial',      tone: 'accent', type: 'section', target: 'ai_qa_lab', access: ['access_ai','superadmin'] },
            { id: 'identity',      label: 'Identity & Governance', desc: 'จัดการสิทธิ์ผู้ใช้', shortcut: 'g i', icon: 'fa-id-card-clip',  tone: 'info',    type: 'section', target: 'identity', access: ['access_identity','superadmin'] },
            { id: 'insurance_sync', label: 'Insurance Hub',      desc: 'ระบบสิทธิ์ประกัน',   icon: 'fa-shield-halved',      tone: 'info',    type: 'section', target: 'insurance_sync', access: ['access_insurance','superadmin'] },
            { id: 'insurance_dashboard', label: 'Dashboard Workbook', desc: 'ภาพรวม + แก้ widgets · Multi-workbook', icon: 'fa-chart-pie',     tone: 'info',    type: 'section', target: 'insurance_dashboard', access: ['access_insurance','superadmin'] },
            { id: 'gold_card_pending', label: 'ย้ายสิทธิ์บัตรทอง', desc: 'คิวคำขอย้ายสิทธิ์บัตรทองจาก user', icon: 'fa-hourglass-half', tone: 'info',    type: 'section', target: 'gold_card_pending', access: ['access_insurance','superadmin'] },
            { id: 'gold_card',     label: 'บัตรทอง',             desc: 'จัดการบัตรทอง + เอกสาร', icon: 'fa-id-card',         tone: 'warning', type: 'section', target: 'gold_card', access: ['access_insurance','superadmin'] },
            { id: 'registry_upload', label: 'อัพโหลดรายชื่อ',    desc: 'ทะเบียน',            icon: 'fa-id-card-clip',      tone: 'info',    type: 'section', target: 'registry_upload', access: ['access_registry','access_insurance','superadmin'] },
            { id: 'batch_status',  label: 'สถานะเอกสาร',         desc: 'Insurance Batch',    icon: 'fa-list-check',         tone: 'info',    type: 'section', target: 'batch_status', access: ['access_insurance','superadmin'] },
<?php if ($isSuper): ?>
            { id: 'manage_insurance_partners', label: 'Insurance Partners', desc: 'จัดการพาร์ทเนอร์', icon: 'fa-handshake', tone: 'success', type: 'section', target: 'manage_insurance_partners', access: 'superadmin' },
<?php endif; ?>
            { id: 'announcements', label: 'ประกาศ',              desc: 'จัดการประกาศ Hub',  shortcut: 'g a', icon: 'fa-bullhorn',           tone: 'accent',  type: 'section', target: 'announcements' },
            { id: 'edms',          label: 'สารบรรณอิเล็กทรอนิกส์', desc: 'EDMS + งาน/Tasks', icon: 'fa-folder-open',         tone: 'info',   type: 'section', target: 'edms', access: ['access_edms','superadmin'] },
            { id: 'activity_logs', label: 'Activity Logs',       desc: 'บันทึกกิจกรรมระบบ',  icon: 'fa-file-lines',         tone: 'neutral', type: 'section', target: 'activity_logs', access: ['access_system_logs','superadmin'] },
            { id: 'error_logs',    label: 'Error Logs',          desc: 'บันทึกข้อผิดพลาด',  shortcut: 'g e', icon: 'fa-bug',                tone: 'danger',  type: 'section', target: 'error_logs', access: ['access_system_logs','superadmin'] },
            { id: 'privilege_inventory', label: 'ISO Governance', desc: 'Privileged Access', icon: 'fa-shield-halved',      tone: 'success', type: 'section', target: 'privilege_inventory', access: 'superadmin' },
            { id: 'pdpa_audit',    label: 'PDPA Audit',          desc: 'ตรวจสอบความยินยอม PDPA', icon: 'fa-user-shield',    tone: 'info',    type: 'section', target: 'pdpa_audit', access: 'superadmin' },
            { id: 'db_schema',     label: 'Database Schema',     desc: 'กราฟความสัมพันธ์ของฐานข้อมูล', icon: 'fa-diagram-project', tone: 'info', type: 'section', target: 'db_schema', access: 'superadmin' },
            { id: 'sql_console',   label: 'SQL Console (RO)',    desc: 'รัน SELECT diagnostic · superadmin only', icon: 'fa-terminal',  tone: 'warning', type: 'section', target: 'sql_console', access: 'superadmin' },
            { id: 'vaccinations',  label: 'บันทึกการฉีดวัคซีน',     desc: 'จัดการประวัติวัคซีน · KPI · audit log', icon: 'fa-syringe',     tone: 'success', type: 'section', target: 'vaccinations', access: ['access_ecampaign','superadmin'] },
            { id: 'vaccine_catalog', label: 'ประเภทวัคซีน (Catalog)', desc: 'CRUD vaccine types · ผูกกับ campaign', icon: 'fa-pills', tone: 'success', type: 'section', target: 'vaccine_catalog', access: ['access_ecampaign','superadmin'] },
            { id: 'settings',      label: 'Settings',            desc: 'ตั้งค่าระบบ',        shortcut: 'g s', icon: 'fa-gear',               tone: 'warning', type: 'section', target: 'settings', access: ['access_site_settings','superadmin'] },

            { id: 'open_asset',    label: 'ครุภัณฑ์สำนักงาน',   desc: 'ทะเบียนทรัพย์สิน',  shortcut: 'g r', icon: 'fa-boxes-stacked',     tone: 'success', type: 'url',     target: '../asset/index.php', access: ['access_asset','admin','superadmin'] },
            { id: 'open_campaign', label: 'Campaign Manager',    desc: 'จัดการแคมเปญ',      icon: 'fa-bullhorn',           tone: 'info',    type: 'url',     target: '../admin/campaigns.php', access: ['access_ecampaign','admin','superadmin'] },
            { id: 'open_eborrow',  label: 'e-Borrow & Inventory', desc: 'ระบบยืม-คืนอุปกรณ์', icon: 'fa-toolbox',         tone: 'neutral', type: 'url',     target: '../e_Borrow/admin/index.php', access: ['access_eborrow','admin','superadmin'] },
            { id: 'open_users',    label: 'Users Center',        desc: 'รายชื่อผู้ใช้',     icon: 'fa-users',              tone: 'info',    type: 'url',     target: 'users.php', access: 'superadmin' },
            { id: 'open_support',  label: 'Live Support Chat',   desc: 'แชทตอบกลับผู้ใช้',  icon: 'fa-comments',           tone: 'info',    type: 'url',     target: 'support_chat.php', access: ['access_ai','admin','superadmin'] },
        ];

        // Filter to commands that exist for this user
        // 1. type='section' → check both sidebar render + explicit access
        // 2. type='url'     → check explicit access (sidebar ไม่มี data-section)
        const accessibleSections = new Set(
            Array.from(document.querySelectorAll('[data-section]')).map(el => el.dataset.section)
        );
        const COMMANDS = ALL_COMMANDS.filter(c => {
            if (!hasAccess(c.access)) return false;  // permission gate
            if (c.type === 'section') return accessibleSections.has(c.target);
            return true;  // url type — passed access gate already
        });

        // ── State ────────────────────────────────────────────────────────
        const overlay = document.getElementById('cmdk-overlay');
        const input   = document.getElementById('cmdk-input');
        const list    = document.getElementById('cmdk-list');
        const helpOverlay = document.getElementById('kbd-help-overlay');
        let activeIdx = 0;
        let filtered  = COMMANDS;
        let leaderKey = null;       // pending 'g'
        let leaderTimer = null;

        // ── Filtering ────────────────────────────────────────────────────
        function fuzzyMatch(query, text) {
            query = query.toLowerCase().trim();
            text  = text.toLowerCase();
            if (!query) return true;
            // Substring or all-chars-in-order
            if (text.includes(query)) return true;
            let qi = 0;
            for (let i = 0; i < text.length && qi < query.length; i++) {
                if (text[i] === query[qi]) qi++;
            }
            return qi === query.length;
        }

        function filter(query) {
            filtered = COMMANDS.filter(c =>
                fuzzyMatch(query, c.label + ' ' + (c.desc || '') + ' ' + (c.shortcut || ''))
            );
            activeIdx = 0;
            render();
        }

        // ── Render ───────────────────────────────────────────────────────
        function render() {
            if (!filtered.length) {
                list.innerHTML = '<li class="cmdk-empty">ไม่พบคำสั่งที่ตรง</li>';
                return;
            }
            list.innerHTML = filtered.map((c, i) => `
                <li class="cmdk-item cmdk-item--${c.tone || 'neutral'} ${i === activeIdx ? 'is-active' : ''}"
                    role="option" aria-selected="${i === activeIdx}" data-idx="${i}">
                    <div class="cmdk-item-icon"><i class="fa-solid ${c.icon}"></i></div>
                    <div class="cmdk-item-body">
                        <div class="cmdk-item-label">${c.label}</div>
                        ${c.desc ? `<div class="cmdk-item-desc">${c.desc}</div>` : ''}
                    </div>
                    ${c.shortcut ? `<kbd class="cmdk-item-kbd">${c.shortcut}</kbd>` : ''}
                </li>
            `).join('');
        }

        // ── Open / Close ────────────────────────────────────────────────
        function open() {
            overlay.hidden = false;
            requestAnimationFrame(() => overlay.classList.add('is-open'));
            input.value = '';
            filter('');
            input.focus();
        }
        function close() {
            overlay.classList.remove('is-open');
            setTimeout(() => { overlay.hidden = true; }, 180);
        }
        window.cmdkOpen = open;

        // Help modal
        function helpOpen() {
            helpOverlay.hidden = false;
            requestAnimationFrame(() => helpOverlay.classList.add('is-open'));
        }
        window.kbdHelpClose = function () {
            helpOverlay.classList.remove('is-open');
            setTimeout(() => { helpOverlay.hidden = true; }, 180);
        };

        // ── Execute ──────────────────────────────────────────────────────
        function execute(cmd) {
            close();
            if (!cmd) return;
            if (cmd.type === 'section') {
                if (typeof switchSection === 'function') {
                    const btn = document.querySelector(`[data-section="${cmd.target}"]`);
                    switchSection(cmd.target, btn);
                }
            } else if (cmd.type === 'url') {
                window.location.href = cmd.target;
            }
        }

        // ── Events ───────────────────────────────────────────────────────
        input.addEventListener('input', e => filter(e.target.value));
        input.addEventListener('keydown', e => {
            if (e.key === 'ArrowDown') { e.preventDefault(); activeIdx = (activeIdx + 1) % filtered.length; render(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); activeIdx = (activeIdx - 1 + filtered.length) % filtered.length; render(); }
            else if (e.key === 'Enter')  { e.preventDefault(); execute(filtered[activeIdx]); }
        });
        list.addEventListener('click', e => {
            const li = e.target.closest('.cmdk-item');
            if (li) execute(filtered[parseInt(li.dataset.idx, 10)]);
        });
        overlay.addEventListener('click', e => { if (e.target === overlay) close(); });
        helpOverlay.addEventListener('click', e => { if (e.target === helpOverlay) window.kbdHelpClose(); });

        // ── Global keyboard ─────────────────────────────────────────────
        function isTypingTarget(el) {
            if (!el) return false;
            const tag = el.tagName;
            return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable;
        }

        document.addEventListener('keydown', e => {
            // ⌘K / Ctrl+K — open palette
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                if (overlay.hidden) open(); else close();
                return;
            }
            // ESC — close any open modal
            if (e.key === 'Escape') {
                if (!overlay.hidden) { e.preventDefault(); close(); }
                else if (!helpOverlay.hidden) { e.preventDefault(); window.kbdHelpClose(); }
                return;
            }
            // Don't trigger leader / help while typing
            if (isTypingTarget(e.target)) return;

            // ? — open shortcut help (use shift+/ which produces "?")
            if (e.key === '?') { e.preventDefault(); helpOpen(); return; }

            // / — focus project search
            if (e.key === '/') {
                e.preventDefault();
                const proj = document.getElementById('search-project');
                if (proj) proj.focus();
                return;
            }

            // Sequence shortcut (g + letter)
            if (e.key === 'g' && !e.metaKey && !e.ctrlKey && !e.altKey) {
                leaderKey = 'g';
                clearTimeout(leaderTimer);
                leaderTimer = setTimeout(() => { leaderKey = null; }, 900);
                return;
            }
            if (leaderKey === 'g') {
                const map = {
                    d: 'dashboard',
                    i: 'identity',
                    a: 'announcements',
                    e: 'error_logs',
                    s: 'settings',
                };
                const sec = map[e.key];
                if (sec) {
                    e.preventDefault();
                    leaderKey = null;
                    if (typeof switchSection === 'function') {
                        const btn = document.querySelector(`[data-section="${sec}"]`);
                        if (btn) switchSection(sec, btn);
                    }
                    return;
                }
                if (e.key === 'r') {
                    e.preventDefault(); leaderKey = null;
                    window.location.href = '../asset/index.php'; return;
                }
                leaderKey = null;
            }
        });
    })();
    </script>

<!-- ════════════════════ App Switcher (Phase 1) ════════════════════ -->
<style>
    #app-switcher-backdrop {
        position: fixed; inset: 0; z-index: 9000;
        background: rgba(15,23,42,.55); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
        opacity: 0; pointer-events: none; transition: opacity .25s;
        display: flex; align-items: center; justify-content: center;
        padding: 20px;
    }
    #app-switcher-backdrop.show { opacity: 1; pointer-events: auto; }
    #app-switcher-modal {
        background: #fff; border-radius: 24px;
        width: 100%; max-width: 900px;
        max-height: 90vh; overflow-y: auto;
        box-shadow: 0 25px 60px -10px rgba(0,0,0,.35);
        transform: scale(.95); transition: transform .25s cubic-bezier(.34,1.56,.64,1);
        padding: 24px;
    }
    #app-switcher-backdrop.show #app-switcher-modal { transform: scale(1); }
    .aps-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; padding-bottom: 16px; border-bottom: 1.5px solid #f1f5f9; }
    .aps-head h2 { margin: 0; font-size: 18px; font-weight: 900; color: #0f172a; display: flex; align-items: center; gap: 10px; }
    .aps-head .aps-close { width: 36px; height: 36px; border-radius: 10px; border: none; background: #f1f5f9; color: #475569; cursor: pointer; font-size: 14px; display: flex; align-items: center; justify-content: center; transition: background .15s; }
    .aps-head .aps-close:hover { background: #e2e8f0; color: #0f172a; }
    .aps-section-label { font-size: 10px; font-weight: 900; text-transform: uppercase; letter-spacing: .12em; color: #94a3b8; margin: 18px 0 10px; }
    .aps-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; }
    .aps-card {
        display: flex; flex-direction: column; gap: 8px;
        padding: 16px; border-radius: 16px; cursor: pointer;
        background: #f8fafc; border: 1.5px solid #e2e8f0;
        text-decoration: none; color: #0f172a;
        transition: transform .15s, box-shadow .15s, border-color .15s, background .15s;
    }
    .aps-card:hover { transform: translateY(-2px); box-shadow: 0 8px 18px rgba(0,0,0,.08); }
    .aps-card.current { border-color: #10b981; background: #ecfdf5; box-shadow: inset 0 0 0 1px #10b981; }
    .aps-card.current::after { content: 'อยู่ที่นี่'; position: absolute; }
    .aps-card-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 18px; }
    .aps-card-title { font-size: 14px; font-weight: 900; line-height: 1.2; }
    .aps-card-desc { font-size: 11px; color: #64748b; font-weight: 500; line-height: 1.4; }
    .aps-footer { margin-top: 20px; padding-top: 14px; border-top: 1.5px dashed #e2e8f0; font-size: 11px; color: #94a3b8; text-align: center; }
    .aps-footer kbd { background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 4px; padding: 1px 6px; font-family: monospace; font-size: 10px; }

    @media (prefers-reduced-motion: reduce) {
        #app-switcher-backdrop, #app-switcher-modal { transition: none !important; }
    }
</style>

<div id="app-switcher-backdrop" onclick="if(event.target===this)closeAppSwitcher()">
    <div id="app-switcher-modal" role="dialog" aria-modal="true">
        <div class="aps-head">
            <h2><i class="fa-solid fa-grip" style="color:#2e9e63"></i>เลือกระบบที่ต้องการใช้งาน</h2>
            <button class="aps-close" onclick="closeAppSwitcher()" aria-label="ปิด"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <div class="aps-section-label">โมดูลใน Portal</div>
        <div class="aps-grid">
            <a class="aps-card" data-app="overview"  href="index.php?section=dashboard">
                <div class="aps-card-icon" style="background:#ecfdf5;color:#059669"><i class="fa-solid fa-chart-line"></i></div>
                <div class="aps-card-title">ภาพรวม</div>
                <div class="aps-card-desc">Dashboard · โปรไฟล์ของฉัน</div>
            </a>
            <a class="aps-card" data-app="ai"        href="index.php?section=ai_assistant">
                <div class="aps-card-icon" style="background:#f5f3ff;color:#7c3aed"><i class="fa-solid fa-wand-magic-sparkles"></i></div>
                <div class="aps-card-title">AI Suite</div>
                <div class="aps-card-desc">AI Assistant · QA Lab · Prompts</div>
            </a>
            <a class="aps-card" data-app="security"  href="index.php?section=identity">
                <div class="aps-card-icon" style="background:#eef2ff;color:#4f46e5"><i class="fa-solid fa-shield-halved"></i></div>
                <div class="aps-card-title">สิทธิ์ &amp; ความปลอดภัย</div>
                <div class="aps-card-desc">Identity Governance · ISO</div>
            </a>
            <a class="aps-card" data-app="insurance" href="index.php?section=insurance_hub">
                <div class="aps-card-icon" style="background:#fff1f2;color:#e11d48"><i class="fa-solid fa-hospital-user"></i></div>
                <div class="aps-card-title">ประกันสุขภาพ</div>
                <div class="aps-card-desc">Insurance Hub · บัตรทอง · Partners</div>
            </a>
            <a class="aps-card" data-app="comm"      href="index.php?section=announcements">
                <div class="aps-card-icon" style="background:#eff6ff;color:#2563eb"><i class="fa-solid fa-bullhorn"></i></div>
                <div class="aps-card-title">สื่อสาร</div>
                <div class="aps-card-desc">ประกาศ · EDMS</div>
            </a>
            <a class="aps-card" data-app="monitor"   href="index.php?section=activity_logs">
                <div class="aps-card-icon" style="background:#f1f5f9;color:#475569"><i class="fa-solid fa-binoculars"></i></div>
                <div class="aps-card-title">ติดตามระบบ</div>
                <div class="aps-card-desc">Activity Logs · Error Logs</div>
            </a>
            <a class="aps-card" data-app="masterdata" href="index.php?section=clinic_data">
                <div class="aps-card-icon" style="background:#ecfeff;color:#0891b2"><i class="fa-solid fa-database"></i></div>
                <div class="aps-card-title">ข้อมูลหลัก</div>
                <div class="aps-card-desc">คลินิก · นักศึกษาทุน · Master</div>
            </a>
            <a class="aps-card" data-app="masterdata" href="index.php?section=nurse_schedule">
                <div class="aps-card-icon" style="background:#e0f2fe;color:#0284c7"><i class="fa-solid fa-user-nurse"></i></div>
                <div class="aps-card-title">ตารางเวรพยาบาล</div>
                <div class="aps-card-desc">จัดเวร · ใบลา · OT · สรุป</div>
            </a>
            <a class="aps-card" data-app="settings"  href="index.php?section=settings">
                <div class="aps-card-icon" style="background:#f9fafb;color:#374151"><i class="fa-solid fa-gear"></i></div>
                <div class="aps-card-title">ตั้งค่า</div>
                <div class="aps-card-desc">Settings</div>
            </a>
        </div>

        <div class="aps-section-label">โมดูลภายนอก (เปิดในแท็บใหม่)</div>
        <div class="aps-grid">
            <a class="aps-card" href="../admin/index.php" target="_blank" rel="noopener">
                <div class="aps-card-icon" style="background:#dcfce7;color:#15803d"><i class="fa-solid fa-bullhorn"></i></div>
                <div class="aps-card-title">e-Campaign</div>
                <div class="aps-card-desc">จองรอบบริการ · รายงานประจำวัน</div>
            </a>
            <a class="aps-card" href="../e_Borrow/admin/index.php" target="_blank" rel="noopener">
                <div class="aps-card-icon" style="background:#ffedd5;color:#c2410c"><i class="fa-solid fa-toolbox"></i></div>
                <div class="aps-card-title">e-Borrow</div>
                <div class="aps-card-desc">ยืม-คืนอุปกรณ์</div>
            </a>
            <a class="aps-card" href="../consumables/admin/index.php" target="_blank" rel="noopener">
                <div class="aps-card-icon" style="background:#fce7f3;color:#be185d"><i class="fa-solid fa-syringe"></i></div>
                <div class="aps-card-title">Consumables</div>
                <div class="aps-card-desc">เวชภัณฑ์สิ้นเปลือง</div>
            </a>
            <a class="aps-card" href="../asset/admin/index.php" target="_blank" rel="noopener">
                <div class="aps-card-icon" style="background:#fef3c7;color:#b45309"><i class="fa-solid fa-warehouse"></i></div>
                <div class="aps-card-title">Asset Inventory</div>
                <div class="aps-card-desc">ครุภัณฑ์ · ทะเบียนทรัพย์สิน</div>
            </a>
        </div>

        <div class="aps-footer">
            กด <kbd>ESC</kbd> เพื่อปิด · กด <kbd>⌘K</kbd> เพื่อค้นหาเร็ว
        </div>
    </div>
</div>

<script>
(function() {
    const APP_LABELS = {
        overview: 'ภาพรวม', ai: 'AI Suite', security: 'สิทธิ์ & ความปลอดภัย',
        insurance: 'ประกันสุขภาพ', comm: 'สื่อสาร', monitor: 'ติดตามระบบ',
        masterdata: 'ข้อมูลหลัก', settings: 'ตั้งค่า',
    };

    function currentAppKey() {
        // หา group ที่มี active item
        const active = document.querySelector('.psb-item.psb-active');
        if (!active) return null;
        const grp = active.closest('.psb-group');
        return grp ? grp.getAttribute('data-group') : null;
    }

    function markCurrentApp() {
        const key = currentAppKey();
        // เคลียร์ current ของ card ทุกใบก่อน (กรณีเปลี่ยน section)
        document.querySelectorAll('.aps-card.current').forEach(c => c.classList.remove('current'));
        if (key) {
            document.querySelectorAll('.aps-card[data-app="' + key + '"]').forEach(c => c.classList.add('current'));
        }
        updateBreadcrumb();
    }

    function updateBreadcrumb() {
        const active = document.querySelector('.psb-item.psb-active');
        const bcApp = document.getElementById('bc-app');
        const bcSection = document.getElementById('bc-section');
        const bcSep = document.getElementById('bc-sep');
        if (!bcApp || !bcSection) return;
        if (!active) {
            bcApp.textContent = '';
            bcSection.textContent = '';
            if (bcSep) bcSep.style.display = 'none';
            return;
        }
        const sectionLabel = (active.querySelector('.psb-label')?.textContent || active.textContent || '').trim();
        const grp = active.closest('.psb-group');
        const key = grp?.getAttribute('data-group');
        const appLabel = (key && APP_LABELS[key]) || '';
        bcApp.textContent = appLabel;
        bcSection.textContent = sectionLabel;
        if (bcSep) bcSep.style.display = appLabel ? '' : 'none';
        // อัปเดต document.title ด้วยให้สวยใน browser tab
        if (sectionLabel) document.title = sectionLabel + ' · Portal';
    }

    window.openAppSwitcher = function() {
        document.getElementById('app-switcher-backdrop').classList.add('show');
        document.body.style.overflow = 'hidden';
    };
    window.closeAppSwitcher = function() {
        document.getElementById('app-switcher-backdrop').classList.remove('show');
        document.body.style.overflow = '';
    };

    // ESC ปิด
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && document.getElementById('app-switcher-backdrop').classList.contains('show')) {
            closeAppSwitcher();
        }
    });

    // Phase 2: Sidebar contextualization — show current app only
    function applyCurrentAppOnly() {
        const key = currentAppKey();
        if (!key) return;
        document.querySelectorAll('.psb-group').forEach(grp => {
            const k = grp.getAttribute('data-group');
            if (!k) return;
            const btn = document.querySelector('.psb-section-toggle[data-group="' + k + '"]');
            if (k === key) {
                grp.classList.remove('collapsed');
                if (btn) btn.classList.remove('collapsed');
            } else {
                grp.classList.add('collapsed');
                if (btn) btn.classList.add('collapsed');
            }
        });
    }
    window.applyCurrentAppOnly = applyCurrentAppOnly;

    function applyAndMark() {
        markCurrentApp();
        if (localStorage.getItem('portal_current_app_only') !== '0') {
            applyCurrentAppOnly();
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        applyAndMark();

        // เมื่อ user คลิก sidebar item (อาจข้าม group) — re-apply
        document.querySelectorAll('.psb-item').forEach(item => {
            item.addEventListener('click', () => setTimeout(applyAndMark, 0));
        });

        // Wrap switchSection ให้ breadcrumb อัปเดตเมื่อ nav จาก dashboard cards หรือที่อื่น
        if (typeof window.switchSection === 'function' && !window._switchWrapped) {
            const _orig = window.switchSection;
            window.switchSection = function(sectionId, btn) {
                const r = _orig.apply(this, arguments);
                setTimeout(applyAndMark, 0);
                return r;
            };
            window._switchWrapped = true;
        }
    });
})();
</script>

<!-- ════════════ Guided Tour (Driver.js) ════════════ -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/driver.js@1.3.1/dist/driver.css">
<script src="https://cdn.jsdelivr.net/npm/driver.js@1.3.1/dist/driver.js.iife.js"></script>
<script src="../assets/js/rsu-tour.js"></script>
<script>
(function () {
    const portalSteps = [
        { popover: { title: 'ยินดีต้อนรับสู่ Portal', description: 'ระบบจัดการคลินิก RSU Medical Clinic Services — ทัวร์สั้นๆ ดูเมนูหลักกัน' } },
        { element: '#portal-sidebar', popover: { title: 'Sidebar เมนู', description: 'เมนูจัดเป็น 8 กลุ่ม: ภาพรวม / งานคลินิก / รายงาน & ตรวจสอบ / ทรัพยากร / สื่อสาร & AI / ระบบ & สิทธิ์ / ข้อมูลหลัก / ตั้งค่า — คลิกหัวกลุ่มเพื่อเปิด/ปิด', side: 'right' } },
        { element: '#psb-apps-launcher', popover: { title: 'App Launcher (ใหม่!)', description: 'เมนูเปิดทุกระบบ (e-Borrow, ครุภัณฑ์, วัสดุ, Insurance Sync, ISO, LINE ฯลฯ) ย้ายมาอยู่ที่นี่แล้ว — Dashboard เลยโล่งขึ้น', side: 'right' } },
        { element: '.psb-section-toggle[data-group="resources"]', popover: { title: 'ทรัพยากร', description: 'รวม "การเงิน + บิลลิ่ง + เงินเดือน" และทางเข้า "คลังพัสดุ" (ครุภัณฑ์ + วัสดุสิ้นเปลือง)', side: 'right' } },
        { element: '[data-section="settings"]', popover: { title: 'ตั้งค่าระบบ', description: 'ที่อยู่ของ Site Settings, Maintenance, LINE, AI ฯลฯ', side: 'right' } },
        { popover: { title: 'เริ่มใช้งานได้เลย', description: 'กดปุ่ม <i class="fa-solid fa-question"></i> มุมขวาล่างเมื่อต้องการดูทัวร์ซ้ำได้ตลอด' } },
    ];
    window.RsuTour && RsuTour.maybeAutoStart('portal_v2', portalSteps);
    window._portalTourSteps = portalSteps;

    // ── App Launcher migration banner: dismiss + mini-tour ─────────
    const APPS_MIGR_KEY  = 'apps_migration_dismissed_v1';
    const APPS_NEW_KEY   = 'apps_launcher_new_seen_v1';

    document.addEventListener('DOMContentLoaded', function () {
        const banner   = document.getElementById('apps-migration-banner');
        const newBadge = document.getElementById('psb-apps-new-badge');
        const dismissBtn = document.getElementById('apps-migration-dismiss');
        const tourBtn  = document.getElementById('apps-migration-tour-btn');
        const ctaBtn   = document.getElementById('apps-migration-cta');

        // Hide banner if user previously dismissed it
        try {
            if (banner && localStorage.getItem(APPS_MIGR_KEY) === '1') {
                banner.classList.add('is-dismissed');
            }
            if (newBadge && localStorage.getItem(APPS_NEW_KEY) === '1') {
                newBadge.classList.add('is-dismissed');
            }
        } catch (e) { /* silent */ }

        // Dismiss banner (does NOT hide the sidebar item or NEW badge)
        if (dismissBtn && banner) {
            dismissBtn.addEventListener('click', function () {
                banner.classList.add('is-dismissed');
                try { localStorage.setItem(APPS_MIGR_KEY, '1'); } catch (e) {}
            });
        }

        // Mark NEW badge as seen once user clicks the sidebar item or CTA
        function markSeen() {
            try { localStorage.setItem(APPS_NEW_KEY, '1'); } catch (e) {}
            if (newBadge) newBadge.classList.add('is-dismissed');
        }
        const sidebarApps = document.getElementById('psb-apps-launcher');
        if (sidebarApps) sidebarApps.addEventListener('click', markSeen);
        if (ctaBtn)      ctaBtn.addEventListener('click', markSeen);

        // "ดูตำแหน่งใหม่" — mini-tour that highlights the new sidebar location
        if (tourBtn && window.RsuTour) {
            const miniSteps = [
                { element: '#psb-apps-launcher', popover: {
                    title: 'นี่คือทางเข้าใหม่ของ App Launcher',
                    description: 'อยู่ใน sidebar กลุ่ม OVERVIEW · คลิกปุ่มนี้เมื่อใดก็ได้เพื่อเปิดหน้ารวมระบบทั้งหมด',
                    side: 'right'
                }},
                { element: '#apps-migration-cta', popover: {
                    title: 'หรือกดที่นี่ตอนนี้เลย',
                    description: 'ไปยังหน้า App Launcher ทันที — ที่นั่นสามารถปักหมุดระบบที่ใช้บ่อย แล้วจะมาโผล่ที่ Dashboard ใต้แบนเนอร์นี้',
                    side: 'top'
                }},
            ];
            tourBtn.addEventListener('click', function () {
                window.RsuTour.start(miniSteps, 'apps_migration');
            });
        }

        // First-visit nudge: if user has never seen the new badge AND never dismissed,
        // gently pulse the sidebar item so it draws the eye (animation already wired via CSS).
        // (No popover here — popover only shows on portal tour or user-triggered mini-tour.)
    });
})();
</script>
<button id="rsu-tour-fab" type="button" aria-label="ดู Tour อีกครั้ง" title="ดู Tour อีกครั้ง"
    onclick="window.RsuTour && RsuTour.start(window._portalTourSteps, 'portal_v2')"
    style="position:fixed;bottom:20px;right:20px;width:44px;height:44px;border-radius:50%;border:none;background:#2e9e63;color:#fff;font-size:16px;cursor:pointer;box-shadow:0 4px 12px rgba(46,158,99,.35);z-index:90;transition:transform .15s">
    <i class="fa-solid fa-question"></i>
</button>

</body>


</html>
