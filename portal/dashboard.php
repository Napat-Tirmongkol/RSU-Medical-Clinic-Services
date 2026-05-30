<?php
/**
 * portal/dashboard.php
 * Section page — dashboard with KPIs, priorities, activity feed.
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/_portal_data.php';   // $kpis, $recentActivity, $projects, $pinnedProjects, etc.
require_once __DIR__ . '/_layout.php';

layout_start(['section' => 'dashboard', 'title' => 'Dashboard']);
?>
            <div id="section-dashboard" class="portal-section" style="">
                <div class="max-w-[1280px] mx-auto px-5 md:px-8 py-8 space-y-8">

                    <!-- ── PRIORITY PANEL: งานวันนี้ ──────────────────────────────── -->
                    <?php
                    // Role-aware capability flags
                    // - Portal admin (ไม่ใช่ staff) → เห็นทุกอย่างตามเดิม
                    // - Staff (is_ecampaign_staff) → จำกัดตาม access_* flag ที่ตั้งไว้ตอน login
                    $canEcampaign  = !$isStaff || !empty($_SESSION['access_ecampaign']);
                    $canEborrow    = !$isStaff || !empty($_SESSION['access_eborrow']);
                    $canSystemLogs = !$isStaff || !empty($_SESSION['access_system_logs']);

                    $today_items = [];

                    // e-Campaign signals — เน้น check-in workload วันนี้ (สำหรับ staff)
                    if ($canEcampaign) {
                        if ($kpis['pending_today'] > 0) {
                            $today_items[] = [
                                'label' => 'รอเช็คอินวันนี้',
                                'value' => $kpis['pending_today'],
                                'icon'  => 'fa-clock',
                                'tone'  => 'warning',
                                'href'  => '../admin/daily_report.php',
                            ];
                        }
                        if ($kpis['checkins_today'] > 0) {
                            $today_items[] = [
                                'label' => 'เช็คอินสำเร็จวันนี้',
                                'value' => $kpis['checkins_today'],
                                'icon'  => 'fa-circle-check',
                                'tone'  => 'success',
                                'href'  => '../admin/daily_report.php',
                            ];
                        }
                        if ($kpis['slots_today'] > 0) {
                            $today_items[] = [
                                'label' => 'Slot นัดหมายวันนี้',
                                'value' => $kpis['slots_today'],
                                'icon'  => 'fa-calendar-day',
                                'tone'  => 'info',
                                'href'  => '../admin/time_slots.php',
                            ];
                        }
                        if ($kpis['bookings_today'] > 0) {
                            $today_items[] = [
                                'label' => 'การจองใหม่ใน 24 ชม.',
                                'value' => $kpis['bookings_today'],
                                'icon'  => 'fa-bullhorn',
                                'tone'  => 'accent',
                                'href'  => '../admin/bookings.php',
                            ];
                        }
                    }

                    // e-Borrow signals — เฉพาะคนที่มีสิทธิ์ดูแล e-Borrow
                    if ($canEborrow) {
                        if ($kpis['borrows'] > 0) {
                            $today_items[] = [
                                'label' => 'อุปกรณ์รออนุมัติ',
                                'value' => $kpis['borrows'],
                                'icon'  => 'fa-box-open',
                                'tone'  => 'warning',
                                'href'  => '../e_Borrow/admin/index.php',
                            ];
                        }
                        if ($kpis['borrows_overdue'] > 0) {
                            $today_items[] = [
                                'label' => 'เลยกำหนดคืน',
                                'value' => $kpis['borrows_overdue'],
                                'icon'  => 'fa-clock-rotate-left',
                                'tone'  => 'danger',
                                'href'  => '../e_Borrow/admin/return_dashboard.php',
                            ];
                        }
                    }

                    // System logs — เฉพาะคนดูแลระบบ
                    if ($canSystemLogs && $kpis['errors_today'] > 0) {
                        $today_items[] = [
                            'label' => 'Error ใหม่ใน 24 ชม.',
                            'value' => $kpis['errors_today'],
                            'icon'  => 'fa-bug',
                            'tone'  => 'danger',
                            'href'  => 'javascript:switchSection(\'error_logs\')',
                        ];
                    }

                    // EDMS / สารบรรณอิเล็กทรอนิกส์ — เฉพาะคนที่ access_edms
                    if ($hasEdms) {
                        // 1) เลยกำหนด — เร่งด่วนสุด แสดงก่อน
                        if ($edmsBreachedMine > 0) {
                            $today_items[] = [
                                'label' => 'เลยกำหนดแล้ว — ต้องเร่งด่วน',
                                'value' => $edmsBreachedMine,
                                'icon'  => 'fa-circle-exclamation',
                                'tone'  => 'danger',
                                'href'  => '?section=edms&edms_view=myinbox&filter=breached',
                            ];
                        }
                        // 2) Warning — ใกล้หมดเวลา
                        if ($edmsWarningMine > 0) {
                            $today_items[] = [
                                'label' => 'ใกล้หมดเวลา — รีบทำให้เสร็จ',
                                'value' => $edmsWarningMine,
                                'icon'  => 'fa-triangle-exclamation',
                                'tone'  => 'warning',
                                'href'  => '?section=edms&edms_view=myinbox&filter=warning',
                            ];
                        }
                        // 3) Tasks ของฉัน (ถ้ามี — เน้นว่าเป็นงานมอบหมาย)
                        if ($edmsTaskMine > 0) {
                            $today_items[] = [
                                'label' => 'งานที่ต้องทำ',
                                'value' => $edmsTaskMine,
                                'icon'  => 'fa-list-check',
                                'tone'  => 'info',
                                'href'  => '?section=edms&edms_view=myinbox&filter=open',
                            ];
                        }
                        // 4) เอกสารใน inbox (เฉพาะที่ไม่ใช่ task — กัน double-count)
                        $_docOnlyInbox = max(0, $edmsInboxBadge - $edmsTaskMine);
                        if ($_docOnlyInbox > 0) {
                            $today_items[] = [
                                'label' => 'เอกสารที่ต้องดำเนินการ',
                                'value' => $_docOnlyInbox,
                                'icon'  => 'fa-folder-open',
                                'tone'  => 'accent',
                                'href'  => '?section=edms&edms_view=myinbox&filter=open',
                            ];
                        }
                    }
                    ?>
                    <section class="au d1">
                        <?php
                        // Build 4 hero KPI tiles role-aware
                        $heroKpis = [];
                        if ($isStaff && $canEcampaign) {
                            $heroKpis[] = ['tone'=>'brand', 'icon'=>'fa-circle-check', 'num'=>$kpis['checkins_today'], 'sub'=>'/ '.number_format($kpis['appts_today']), 'label'=>'เช็คอินวันนี้ · จากนัดหมาย'];
                            $heroKpis[] = ['tone'=>'info',  'icon'=>'fa-calendar-day', 'num'=>$kpis['slots_today'],    'label'=>'Slot วันนี้'];
                            $heroKpis[] = ['tone'=>'amber', 'icon'=>'fa-clock',        'num'=>$kpis['pending_today'],  'label'=>'รอเช็คอิน'];
                            $heroKpis[] = ['tone'=>'accent','icon'=>'fa-bullhorn',     'num'=>$kpis['bookings_today'], 'label'=>'จองใหม่ใน 24 ชม.'];
                        } else {
                            $heroKpis[] = ['tone'=>'brand', 'icon'=>'fa-users',     'num'=>$kpis['users'],      'label'=>'บุคลากรและนักศึกษา', 'counter'=>true];
                            $heroKpis[] = ['tone'=>'info',  'icon'=>'fa-bullhorn',  'num'=>$kpis['camps'],      'label'=>'แคมเปญ active'];
                            $heroKpis[] = ['tone'=>'amber', 'icon'=>'fa-gauge-high','num'=>$kpis['used_quota'], 'sub'=>'/ '.number_format($kpis['total_quota']), 'label'=>'ใช้ไปแล้ว · จาก quota'];
                            $heroKpis[] = ['tone'=>'rose',  'icon'=>'fa-bug',       'num'=>$kpis['errors_today'],'label'=>'Error ใน 24 ชม.'];
                        }
                        $firstName = !empty($_SESSION['admin_username']) ? explode(' ', $_SESSION['admin_username'])[0] : '';
                        $hour = (int)date('G');
                        $greet = $hour < 12 ? 'อรุณสวัสดิ์' : ($hour < 17 ? 'สวัสดี' : ($hour < 21 ? 'สวัสดีตอนเย็น' : 'สวัสดีค่ำคืนนี้'));
                        $thaiMonths = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
                        $thaiDays   = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
                        $todayStr   = $thaiDays[(int)date('w')] . ' ' . (int)date('j') . ' ' . $thaiMonths[(int)date('n')] . ' ' . (date('Y')+543);
                        ?>
                        <div class="dash-hero">
                            <div class="dash-hero-glow"></div>
                            <div class="dash-hero-greet">
                                <div class="dash-hero-eyebrow">
                                    <i class="fa-solid fa-calendar-day"></i> <?= $todayStr ?>
                                    <?php if ($isStaff): ?><span class="dash-hero-role-pill"><i class="fa-solid fa-id-badge"></i> เจ้าหน้าที่</span><?php endif; ?>
                                </div>
                                <h1 class="dash-hero-title">
                                    <?= $greet ?><?= $firstName ? ' <span class="dash-hero-name">' . htmlspecialchars($firstName) . '</span>' : '' ?>
                                </h1>
                                <p class="dash-hero-sub">
                                    ภาพรวมระบบและงานวันนี้ของคุณ — เปิด App Launcher ที่ sidebar เพื่อเข้าระบบอื่นๆ
                                </p>
                            </div>
                            <div class="dash-hero-kpis">
                                <?php foreach ($heroKpis as $i => $k): ?>
                                <div class="dash-kpi" data-tone="<?= $k['tone'] ?>" style="animation-delay:<?= 0.1 + $i * 0.08 ?>s">
                                    <div class="dash-kpi-ic"><i class="fa-solid <?= $k['icon'] ?>"></i></div>
                                    <div class="dash-kpi-body">
                                        <div class="dash-kpi-num">
                                            <span<?= !empty($k['counter']) ? ' id="kpi-users"' : '' ?> data-counter="<?= (int)$k['num'] ?>">0</span><?php if (!empty($k['sub'])): ?><span class="dash-kpi-sub"><?= $k['sub'] ?></span><?php endif; ?>
                                        </div>
                                        <div class="dash-kpi-label"><?= htmlspecialchars($k['label']) ?></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </section>
                    <!-- ── MORNING BRIEF WIDGET ──────────────────────────────────── -->
                    <div id="mb-widget" class="rounded-2xl border border-slate-200 bg-white p-5 hidden">
                        <div class="flex items-start justify-between gap-3 mb-3 flex-wrap">
                            <div class="flex items-center gap-2.5">
                                <span class="inline-flex w-9 h-9 rounded-xl items-center justify-center" id="mb-icon-wrap" style="background:#fef3c7;color:#d97706">
                                    <i class="fa-solid fa-sun"></i>
                                </span>
                                <div>
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <h3 class="text-base font-bold text-slate-900">Morning Brief</h3>
                                        <span id="mb-urgency-badge" class="hidden text-[11px] font-bold px-2 py-0.5 rounded-full"></span>
                                        <span id="mb-unread-dot" class="hidden w-2 h-2 rounded-full bg-rose-500" title="ยังไม่ได้อ่าน"></span>
                                    </div>
                                    <p class="text-xs text-slate-500 mt-0.5" id="mb-date-line">กำลังโหลด…</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <button onclick="mbGenerate()" id="mb-refresh-btn" class="inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-emerald-700 px-2.5 py-1.5 rounded-lg hover:bg-slate-50" title="สร้าง brief ใหม่">
                                    <i class="fa-solid fa-rotate"></i><span class="hidden md:inline">รีเฟรช</span>
                                </button>
                                <a href="?section=morning_brief_settings" class="inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-emerald-700 px-2.5 py-1.5 rounded-lg hover:bg-slate-50" title="ตั้งค่า">
                                    <i class="fa-solid fa-gear"></i>
                                </a>
                                <button onclick="mbDismiss()" class="inline-flex items-center text-xs text-slate-400 hover:text-slate-700 w-7 h-7 rounded-lg hover:bg-slate-50" title="ปิดวันนี้">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </div>
                        </div>

                        <p id="mb-narrative" class="text-sm text-slate-700 leading-relaxed mb-4"></p>

                        <div id="mb-priorities" class="space-y-2 mb-4"></div>

                        <div id="mb-doctors" class="hidden mb-4 p-3 rounded-lg border border-indigo-100 bg-indigo-50/40">
                            <div class="flex items-center gap-2 mb-2">
                                <i class="fa-solid fa-user-doctor text-indigo-500"></i>
                                <p class="text-sm font-semibold text-slate-900">แพทย์ออกตรวจวันนี้</p>
                                <span id="mb-doctors-count" class="text-xs text-slate-500"></span>
                            </div>
                            <div id="mb-doctors-list" class="space-y-1"></div>
                        </div>

                        <div id="mb-stats" class="grid grid-cols-2 md:grid-cols-4 gap-2 pt-4 border-t border-slate-100"></div>

                        <div class="flex items-center justify-between mt-3 text-[11px] text-slate-400">
                            <span id="mb-meta"></span>
                            <span id="mb-error" class="text-amber-600 hidden"></span>
                        </div>
                    </div>

                    <style>
                        #mb-widget [data-urgency="critical"] { background:#fee2e2; color:#991b1b; }
                        #mb-widget [data-urgency="high"]     { background:#ffedd5; color:#9a3412; }
                        #mb-widget [data-urgency="normal"]   { background:#e0e7ff; color:#3730a3; }
                        #mb-widget [data-urgency="low"]      { background:#dcfce7; color:#166534; }
                        .mb-priority { display:flex; gap:.75rem; padding:.65rem .85rem; border-radius:.5rem; background:#f8fafc; border:1px solid #e2e8f0; }
                        .mb-priority-icon { flex-shrink:0; width:1.75rem; height:1.75rem; border-radius:.5rem; display:flex; align-items:center; justify-content:center; font-size:.75rem; }
                        .mb-priority[data-mod="campaign"] .mb-priority-icon { background:#fed7aa; color:#9a3412; }
                        .mb-priority[data-mod="scholarship"] .mb-priority-icon { background:#dcfce7; color:#166534; }
                        .mb-priority[data-mod="finance"] .mb-priority-icon { background:#fef3c7; color:#92400e; }
                        .mb-priority[data-mod="edms"] .mb-priority-icon { background:#e0e7ff; color:#3730a3; }
                        .mb-priority[data-mod="inventory"] .mb-priority-icon { background:#ccfbf1; color:#115e59; }
                        .mb-priority[data-mod="clinic"] .mb-priority-icon { background:#fce7f3; color:#9d174d; }
                        .mb-priority[data-mod="other"] .mb-priority-icon { background:#f1f5f9; color:#475569; }
                        .mb-stat { padding:.6rem .8rem; border-radius:.5rem; background:#f8fafc; }
                        .mb-stat-label { font-size:.7rem; color:#64748b; }
                        .mb-stat-value { font-size:1.1rem; font-weight:600; color:#0f172a; margin-top:.15rem; }
                        .mb-doctor-row { display:flex; gap:.5rem; align-items:baseline; font-size:.8rem; line-height:1.5; }
                        .mb-doctor-time { color:#3730a3; font-weight:600; font-variant-numeric:tabular-nums; min-width:90px; }
                        .mb-doctor-name { color:#0f172a; flex:1; }
                        .mb-doctor-room { color:#64748b; font-size:.72rem; }
                        .mb-doctor-override { display:inline-block; padding:0 .35rem; border-radius:.25rem; background:#fed7aa; color:#9a3412; font-size:.65rem; font-weight:600; margin-left:.35rem; }
                        body[data-theme='dark'] #mb-widget { background:#0f172a; border-color:#1e293b; }
                        body[data-theme='dark'] #mb-widget .mb-priority { background:#1e293b; border-color:#334155; }
                        body[data-theme='dark'] #mb-widget .mb-stat { background:#1e293b; }
                        body[data-theme='dark'] #mb-widget .mb-stat-value { color:#f1f5f9; }
                        body[data-theme='dark'] #mb-widget #mb-narrative { color:#e2e8f0; }
                        body[data-theme='dark'] #mb-widget h3 { color:#f1f5f9; }
                        body[data-theme='dark'] #mb-widget #mb-doctors { background:rgba(99,102,241,.08); border-color:rgba(99,102,241,.25); }
                        body[data-theme='dark'] #mb-widget .mb-doctor-name { color:#f1f5f9; }
                        body[data-theme='dark'] #mb-widget .mb-doctor-time { color:#a5b4fc; }
                    </style>

                    <script>
                    (function(){
                        const TODAY = new Date().toISOString().slice(0,10);
                        const DISMISS_KEY = 'mb_dismissed_' + TODAY;
                        if (sessionStorage.getItem(DISMISS_KEY) === '1') return; // user closed it earlier

                        const URGENCY_LABEL = { low: 'ปกติ', normal: 'มีงานเข้า', high: 'ต้องดูด่วน', critical: 'วิกฤต' };
                        const MOD_ICON = {
                            campaign: 'fa-calendar-check',
                            scholarship: 'fa-graduation-cap',
                            finance: 'fa-money-check-dollar',
                            edms: 'fa-file-lines',
                            inventory: 'fa-boxes-stacked',
                            clinic: 'fa-hospital',
                            other: 'fa-circle-info',
                        };
                        const MOD_LINK = {
                            campaign: '../admin/daily_report.php',
                            scholarship: '?section=scholarship',
                            finance: '?section=finance',
                            edms: '?section=edms',
                            inventory: '/consumables/',
                            clinic: '?section=clinic_data',
                        };

                        function esc(s) { const d = document.createElement('div'); d.textContent = String(s||''); return d.innerHTML; }
                        function fmt(n) { return Number(n||0).toLocaleString('th-TH'); }

                        async function mbLoad(autoGen) {
                            try {
                                const r = await fetch('ajax_morning_brief.php?action=get&date=' + TODAY + (autoGen ? '&auto=1' : ''));
                                const j = await r.json();
                                if (!j.ok || !j.brief) return; // silently bail if no brief and auto disabled
                                mbRender(j.brief, j.unread);
                                document.getElementById('mb-widget').classList.remove('hidden');
                                if (j.unread) {
                                    // mark read after rendering — fire & forget
                                    const fd = new FormData();
                                    fd.append('csrf_token', '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>');
                                    fd.append('date', TODAY);
                                    fetch('ajax_morning_brief.php?action=mark_read', { method:'POST', body: fd });
                                }
                            } catch(e) { /* silent fail */ }
                        }

                        function mbRender(brief, unread) {
                            const data = brief.data || {};
                            const clinic = data.clinic || {};
                            const camp = data.campaign || {};
                            const sch = data.scholarship || {};
                            const fin = data.finance || {};
                            const edms = data.edms || {};
                            const inv = data.inventory || {};

                            // Date line + clinic status
                            const dt = (clinic.date_thai ? clinic.date_thai + ' · วัน' + clinic.weekday_thai : TODAY);
                            let opens = '';
                            if (clinic.clinic_open === true) {
                                opens = 'คลินิกเปิด' + (clinic.clinic_hours ? ' ' + clinic.clinic_hours : '');
                            } else if (clinic.clinic_open === false) {
                                const src = clinic.clinic_source === 'holiday' ? ' (วันหยุด)' :
                                            clinic.clinic_source === 'special' ? ' (พิเศษ)' : '';
                                opens = 'คลินิกหยุด' + src + (clinic.clinic_note ? ' · ' + clinic.clinic_note : '');
                            }
                            document.getElementById('mb-date-line').textContent = dt + (opens ? ' · ' + opens : '');

                            // Urgency badge
                            const urgency = brief.urgency_level || 'normal';
                            const ub = document.getElementById('mb-urgency-badge');
                            ub.textContent = URGENCY_LABEL[urgency] || urgency;
                            ub.setAttribute('data-urgency', urgency);
                            ub.classList.remove('hidden');

                            // Unread dot
                            document.getElementById('mb-unread-dot').classList.toggle('hidden', !unread);

                            // Narrative
                            document.getElementById('mb-narrative').textContent = brief.ai_narrative || 'ยังไม่มีข้อมูลสรุป';

                            // Priorities
                            const pri = brief.ai_priorities || [];
                            const pwrap = document.getElementById('mb-priorities');
                            if (pri.length) {
                                pwrap.innerHTML = pri.map(p => {
                                    const mod = p.module || 'other';
                                    const icon = MOD_ICON[mod] || 'fa-circle-info';
                                    const link = MOD_LINK[mod];
                                    return `<div class="mb-priority" data-mod="${esc(mod)}">
                                        <span class="mb-priority-icon"><i class="fa-solid ${icon}"></i></span>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-semibold text-slate-900">${esc(p.title || '')}</p>
                                            <p class="text-xs text-slate-600 mt-0.5">${esc(p.detail || '')}</p>
                                        </div>
                                        ${link ? `<a href="${link}" class="text-xs font-semibold text-emerald-700 hover:text-emerald-800 self-center whitespace-nowrap">เปิด <i class="fa-solid fa-arrow-right"></i></a>` : ''}
                                    </div>`;
                                }).join('');
                            } else {
                                pwrap.innerHTML = '';
                            }

                            // Doctor schedule block
                            const docList = clinic.doctors_today_list || [];
                            const docWrap = document.getElementById('mb-doctors');
                            if (docList.length > 0) {
                                document.getElementById('mb-doctors-count').textContent = '· ' + docList.length + ' ท่าน';
                                document.getElementById('mb-doctors-list').innerHTML = docList.map(d => {
                                    const time = d.time || '–';
                                    const name = d.name || 'ไม่ระบุชื่อ';
                                    const room = d.room ? `<span class="mb-doctor-room">@ ${esc(d.room)}</span>` : '';
                                    const ovr  = d.is_override ? '<span class="mb-doctor-override">พิเศษ</span>' : '';
                                    return `<div class="mb-doctor-row">
                                        <span class="mb-doctor-time">${esc(time)}</span>
                                        <span class="mb-doctor-name">${esc(name)}${ovr}</span>
                                        ${room}
                                    </div>`;
                                }).join('');
                                docWrap.classList.remove('hidden');
                            } else {
                                docWrap.classList.add('hidden');
                            }

                            // Stats grid (key numbers) — prefer e-Campaign data, fallback to generic bookings
                            const apptToday = (Number(camp.today_scheduled || 0) > 0)
                                ? Number(camp.today_scheduled || 0)
                                : Number(clinic.appointments_today || 0);
                            const stats = [
                                { label: 'รออนุมัติ',         value: fmt(sch.pending_approvals) },
                                { label: 'กะวันนี้',          value: fmt(sch.today_shifts) },
                                { label: 'นัดแคมเปญวันนี้',   value: fmt(apptToday) },
                                { label: 'งาน EDMS ครบกำหนด', value: fmt(edms.tasks_due_today) },
                            ];
                            document.getElementById('mb-stats').innerHTML = stats.map(s =>
                                `<div class="mb-stat">
                                    <div class="mb-stat-label">${esc(s.label)}</div>
                                    <div class="mb-stat-value">${esc(s.value)}</div>
                                </div>`).join('');

                            // Meta
                            const gen = brief.generated_at ? brief.generated_at.slice(11,16) : '';
                            const model = brief.ai_model || '';
                            document.getElementById('mb-meta').textContent =
                                (gen ? 'สร้างเมื่อ ' + gen : '') + (model && model !== 'fallback' ? ' · ' + model : '');
                            const err = document.getElementById('mb-error');
                            if (brief.ai_error) { err.textContent = '⚠ ' + brief.ai_error; err.classList.remove('hidden'); }
                            else { err.classList.add('hidden'); }
                        }

                        window.mbGenerate = async function() {
                            const btn = document.getElementById('mb-refresh-btn');
                            btn.disabled = true;
                            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i><span class="hidden md:inline">กำลังสร้าง…</span>';
                            try {
                                const fd = new FormData();
                                fd.append('csrf_token', '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>');
                                fd.append('date', TODAY);
                                const r = await fetch('ajax_morning_brief.php?action=generate', { method:'POST', body: fd });
                                const j = await r.json();
                                if (j.ok && j.brief) {
                                    mbRender(j.brief, false);
                                    document.getElementById('mb-widget').classList.remove('hidden');
                                } else if (window.Swal) {
                                    Swal.fire({ icon:'error', title:'สร้าง brief ไม่สำเร็จ', text: j.error || j.message || 'unknown' });
                                }
                            } catch(e) {
                                window.Swal && Swal.fire({ icon:'error', title:'เกิดข้อผิดพลาด', text: String(e) });
                            } finally {
                                btn.disabled = false;
                                btn.innerHTML = '<i class="fa-solid fa-rotate"></i><span class="hidden md:inline">รีเฟรช</span>';
                            }
                        };

                        window.mbDismiss = function() {
                            sessionStorage.setItem(DISMISS_KEY, '1');
                            document.getElementById('mb-widget').style.display = 'none';
                        };

                        // Initial load — auto-generate on first visit of the day
                        mbLoad(true);
                    })();
                    </script>

                    <!-- ── DAILY STATS QUICK-ENTRY ──────────────────────────────────── -->
                    <div id="ds-widget" class="rounded-2xl border border-slate-200 bg-white p-5">
                        <div class="flex items-center justify-between gap-3 mb-4 flex-wrap">
                            <div class="flex items-center gap-2.5">
                                <span class="inline-flex w-9 h-9 rounded-xl items-center justify-center" style="background:#dbeafe;color:#2563eb">
                                    <i class="fa-solid fa-clipboard-list"></i>
                                </span>
                                <div>
                                    <h3 class="text-base font-bold text-slate-900">บันทึกประจำวัน</h3>
                                    <p class="text-xs text-slate-500 mt-0.5" id="ds-date-line"></p>
                                </div>
                            </div>
                            <span id="ds-last-update" class="text-[11px] text-slate-400"></span>
                        </div>

                        <form id="ds-form" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                            <div class="md:col-span-3">
                                <label class="block text-xs font-semibold text-slate-600 mb-1">
                                    <i class="fa-solid fa-user-injured text-blue-500 mr-1"></i>ผู้ป่วยวันนี้
                                </label>
                                <div class="relative">
                                    <input type="number" id="ds-patient" min="0" max="99999" inputmode="numeric"
                                           class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm font-semibold focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
                                           placeholder="0" autocomplete="off">
                                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-slate-400 pointer-events-none">คน</span>
                                </div>
                            </div>
                            <div class="md:col-span-3">
                                <label class="block text-xs font-semibold text-slate-600 mb-1">
                                    <i class="fa-solid fa-kit-medical text-rose-500 mr-1"></i>อุบัติเหตุ
                                </label>
                                <div class="relative">
                                    <input type="number" id="ds-accident" min="0" max="9999" inputmode="numeric"
                                           class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm font-semibold focus:border-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-100"
                                           placeholder="0" autocomplete="off">
                                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-slate-400 pointer-events-none">ราย</span>
                                </div>
                            </div>
                            <div class="md:col-span-4">
                                <label class="block text-xs font-semibold text-slate-600 mb-1">หมายเหตุ <span class="font-normal text-slate-400">(ไม่บังคับ)</span></label>
                                <input type="text" id="ds-note" maxlength="500"
                                       class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                                       placeholder="เช่น มีคนไข้หนัก 2 ราย" autocomplete="off">
                            </div>
                            <div class="md:col-span-2">
                                <button type="submit" id="ds-save-btn"
                                        class="w-full px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold flex items-center justify-center gap-1.5">
                                    <i class="fa-solid fa-check"></i> บันทึก
                                </button>
                            </div>
                        </form>
                    </div>

                    <style>
                        body[data-theme='dark'] #ds-widget { background:#0f172a; border-color:#1e293b; }
                        body[data-theme='dark'] #ds-widget h3 { color:#f1f5f9; }
                        body[data-theme='dark'] #ds-widget input { background:#1e293b; border-color:#334155; color:#f1f5f9; }
                        body[data-theme='dark'] #ds-widget label { color:#94a3b8; }
                    </style>

                    <script>
                    (function(){
                        const DS_TODAY = new Date().toISOString().slice(0,10);
                        const DS_CSRF  = '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>';

                        // Date line (Thai date)
                        const dThai = new Date(DS_TODAY + 'T00:00:00').toLocaleDateString('th-TH', {
                            weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
                        });
                        document.getElementById('ds-date-line').textContent = dThai;

                        async function dsLoad() {
                            try {
                                const r = await fetch('ajax_daily_stats.php?action=get&date=' + DS_TODAY);
                                const j = await r.json();
                                if (!j.ok) return;
                                if (j.row) {
                                    document.getElementById('ds-patient').value  = j.row.patient_count;
                                    document.getElementById('ds-accident').value = j.row.accident_count;
                                    document.getElementById('ds-note').value     = j.row.note || '';
                                    const t = j.row.updated_at ? j.row.updated_at.slice(11,16) : '';
                                    const by = j.row.updated_by ? ' โดย ' + j.row.updated_by : '';
                                    document.getElementById('ds-last-update').textContent =
                                        'อัปเดตล่าสุด ' + t + by;
                                } else {
                                    document.getElementById('ds-last-update').textContent = 'ยังไม่ได้บันทึกวันนี้';
                                }
                            } catch(e) { /* silent */ }
                        }

                        document.getElementById('ds-form').addEventListener('submit', async (e) => {
                            e.preventDefault();
                            const btn = document.getElementById('ds-save-btn');
                            const origHtml = btn.innerHTML;
                            btn.disabled = true;
                            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> บันทึก...';
                            try {
                                const fd = new FormData();
                                fd.append('csrf_token', DS_CSRF);
                                fd.append('date', DS_TODAY);
                                fd.append('patient_count',  document.getElementById('ds-patient').value || '0');
                                fd.append('accident_count', document.getElementById('ds-accident').value || '0');
                                fd.append('note', document.getElementById('ds-note').value);
                                const r = await fetch('ajax_daily_stats.php?action=save', { method:'POST', body: fd });
                                const j = await r.json();
                                if (j.ok) {
                                    btn.innerHTML = '<i class="fa-solid fa-check"></i> บันทึกแล้ว';
                                    btn.classList.remove('bg-emerald-600','hover:bg-emerald-700');
                                    btn.classList.add('bg-emerald-700');
                                    if (j.row) {
                                        const t = j.row.updated_at ? j.row.updated_at.slice(11,16) : '';
                                        const by = j.row.updated_by ? ' โดย ' + j.row.updated_by : '';
                                        document.getElementById('ds-last-update').textContent = 'อัปเดตล่าสุด ' + t + by;
                                    }
                                    setTimeout(() => {
                                        btn.disabled = false;
                                        btn.innerHTML = origHtml;
                                        btn.classList.add('bg-emerald-600','hover:bg-emerald-700');
                                        btn.classList.remove('bg-emerald-700');
                                    }, 1600);
                                } else {
                                    window.Swal
                                        ? Swal.fire({ icon:'error', title:'บันทึกไม่สำเร็จ', text: j.error || 'unknown' })
                                        : alert('บันทึกไม่สำเร็จ: ' + (j.error || 'unknown'));
                                    btn.disabled = false; btn.innerHTML = origHtml;
                                }
                            } catch(e) {
                                btn.disabled = false; btn.innerHTML = origHtml;
                                window.Swal && Swal.fire({ icon:'error', title:'เกิดข้อผิดพลาด', text: String(e) });
                            }
                        });

                        // Enter key in any input → submit (เร่งความเร็วการกรอก)
                        ['ds-patient','ds-accident','ds-note'].forEach(id => {
                            document.getElementById(id).addEventListener('keydown', (e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    document.getElementById('ds-form').requestSubmit();
                                }
                            });
                        });

                        dsLoad();
                    })();
                    </script>

                    <!-- ── PRIORITY: งานต้องทำวันนี้ (clean, no greeting now) ───── -->
                    <section class="au d2">
                        <div class="priority-panel priority-panel--slim">
                            <div class="priority-panel-head">
                                <div>
                                    <div class="eyebrow">งานวันนี้ · ที่ต้องดำเนินการ</div>
                                    <div class="sec-title" style="margin-top:4px;font-size:1.05rem">Priorities</div>
                                </div>
                                <?php if (!empty($today_items)): ?>
                                <span class="priority-count-pill"><?= count($today_items) ?> รายการ</span>
                                <?php endif; ?>
                            </div>
                            <?php if (empty($today_items)): ?>
                                <div class="priority-empty">
                                    <i class="fa-solid fa-circle-check"></i>
                                    <div>
                                        <strong>ไม่มีงานค้าง</strong>
                                        <p>ทุกอย่างเรียบร้อยใน 24 ชั่วโมงที่ผ่านมา</p>
                                    </div>
                                </div>
                            <?php else:
                                // เรียงตามความเร่งด่วน: danger → warning → accent → info → success
                                $_toneRank = ['danger' => 0, 'warning' => 1, 'accent' => 2, 'info' => 3, 'success' => 4];
                                usort($today_items, function($a, $b) use ($_toneRank) {
                                    return ($_toneRank[$a['tone']] ?? 99) <=> ($_toneRank[$b['tone']] ?? 99);
                                });
                                // แยก hero (อันที่เร่งสุด) ออกจาก list ปกติ
                                $_hero = ($today_items[0]['tone'] === 'danger' || $today_items[0]['tone'] === 'warning')
                                    ? array_shift($today_items)
                                    : null;
                            ?>
                                <?php if ($_hero): ?>
                                    <a href="<?= htmlspecialchars($_hero['href']) ?>" class="priority-hero priority-hero--<?= $_hero['tone'] ?>">
                                        <div class="priority-hero-pill"><i class="fa-solid fa-fire"></i> ทำอันนี้ก่อน</div>
                                        <div class="priority-hero-body">
                                            <div class="priority-hero-icon"><i class="fa-solid <?= $_hero['icon'] ?>"></i></div>
                                            <div class="priority-hero-text">
                                                <div class="priority-hero-num"><span data-counter="<?= (int)$_hero['value'] ?>">0</span></div>
                                                <div class="priority-hero-label"><?= htmlspecialchars($_hero['label']) ?></div>
                                            </div>
                                            <i class="fa-solid fa-arrow-right priority-hero-arrow"></i>
                                        </div>
                                    </a>
                                <?php endif; ?>
                                <?php if (!empty($today_items)): ?>
                                <div class="priority-grid">
                                    <?php foreach ($today_items as $it): ?>
                                        <a href="<?= htmlspecialchars($it['href']) ?>" class="priority-item priority-item--<?= $it['tone'] ?>">
                                            <div class="priority-item-icon"><i class="fa-solid <?= $it['icon'] ?>"></i></div>
                                            <div class="priority-item-body">
                                                <div class="priority-item-num"><span data-counter="<?= (int)$it['value'] ?>">0</span></div>
                                                <div class="priority-item-label"><?= htmlspecialchars($it['label']) ?></div>
                                            </div>
                                            <i class="fa-solid fa-arrow-right priority-item-arrow"></i>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </section>

                    <!-- ── MAIN GRID: 3-column dashboard body (4 / 5 / 3) ──────── -->
                    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

                        <!-- COL 1: Clinic calendar widget (4/12) -->
                        <section class="lg:col-span-4 au d3">
                            <?php include __DIR__ . '/_partials/dashboard_clinic_calendar.php'; ?>
                        </section>

                        <!-- COL 2: Activity feed (5/12) -->
                        <section class="lg:col-span-5 au d3">
                            <div class="dash-panel">
                                <div class="dash-panel-head">
                                    <div class="sec-title">
                                        กิจกรรมของฉันล่าสุด
                                    </div>
                                    <?php if (!empty($recentActivity)): ?>
                                        <span class="dash-panel-count"><?= count($recentActivity) ?></span>
                                    <?php endif; ?>
                                </div>
                                <ul class="activity-list" id="activity-feed" role="log" aria-live="polite" aria-label="ความเคลื่อนไหวล่าสุด">
                                    <?php
                                    if ($recentActivity):
                                        // map action keyword → tone (color)
                                        $eventTone = function (string $action): array {
                                            $a = strtolower($action);
                                            if (str_contains($a, 'error') || str_contains($a, 'fail')) return ['tone' => 'danger',  'icon' => 'fa-circle-exclamation'];
                                            if (str_contains($a, 'login'))                              return ['tone' => 'info',    'icon' => 'fa-right-to-bracket'];
                                            if (str_contains($a, 'logout'))                             return ['tone' => 'neutral', 'icon' => 'fa-right-from-bracket'];
                                            if (str_contains($a, 'register') || str_contains($a, 'create')) return ['tone' => 'success', 'icon' => 'fa-user-plus'];
                                            if (str_contains($a, 'migrate'))                            return ['tone' => 'accent',  'icon' => 'fa-arrows-rotate'];
                                            if (str_contains($a, 'delete') || str_contains($a, 'remove')) return ['tone' => 'danger', 'icon' => 'fa-trash-can'];
                                            if (str_contains($a, 'update') || str_contains($a, 'edit'))   return ['tone' => 'info',   'icon' => 'fa-pen'];
                                            return ['tone' => 'neutral', 'icon' => 'fa-circle-dot'];
                                        };
                                        foreach ($recentActivity as $log):
                                            $et = $eventTone($log['action']);
                                            $userName = trim((string)($log['admin_name'] ?? ''));
                                            if ($userName === '') $userName = 'ระบบ';
                                    ?>
                                        <li class="activity-row activity-row--<?= $et['tone'] ?>">
                                            <div class="activity-dot"><i class="fa-solid <?= $et['icon'] ?>"></i></div>
                                            <div class="activity-body">
                                                <div class="activity-line">
                                                    <strong class="activity-user"><?= htmlspecialchars($userName) ?></strong>
                                                    <span class="activity-tag"><?= htmlspecialchars(strtolower($log['action'])) ?></span>
                                                </div>
                                                <?php if (!empty($log['description'])): ?>
                                                    <p class="activity-desc"><?= htmlspecialchars($log['description']) ?></p>
                                                <?php endif; ?>
                                            </div>
                                            <time class="activity-time" datetime="<?= htmlspecialchars($log['created_at']) ?>"
                                                  title="<?= date('d/m/Y H:i', strtotime($log['created_at'])) ?>">
                                                <?= date('H:i', strtotime($log['created_at'])) ?>
                                            </time>
                                        </li>
                                    <?php endforeach; else: ?>
                                        <li class="activity-empty">
                                            <i class="fa-solid fa-circle-check"></i>
                                            ยังไม่มีกิจกรรมของคุณในระบบ
                                        </li>
                                    <?php endif; ?>
                                </ul>
                                <?php if ($isSuper || !empty($_SESSION['access_system_logs'])): ?>
                                <a href="javascript:switchSection('activity_logs', document.querySelector('[data-section=activity_logs]'))"
                                    class="activity-view-all">
                                    ดูของระบบทั้งหมด <i class="fa-solid fa-arrow-right text-[10px]"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </section>

                        <!-- COL 3: Pinned apps + Quick shortcuts + Slim migration banner (3/12) -->
                        <aside class="lg:col-span-3 flex flex-col gap-5 au d4">

                            <!-- Pinned apps mini-list -->
                            <?php
                            $pinnedProjects = [];
                            if (!empty($userPins)) {
                                foreach ($projects as $p) {
                                    if (in_array($p['id'], $userPins, true)) $pinnedProjects[] = $p;
                                }
                            }
                            ?>
                            <div class="dash-panel">
                                <div class="dash-panel-head">
                                    <div class="sec-title" style="font-size:.95rem">
                                        <i class="fa-solid fa-thumbtack" style="color:#f59e0b;font-size:.78rem;margin-right:2px"></i>
                                        ปักหมุด
                                    </div>
                                    <a href="javascript:switchSection('apps', document.querySelector('[data-section=apps]'))"
                                        class="dash-panel-link">
                                        ทั้งหมด <i class="fa-solid fa-arrow-right"></i>
                                    </a>
                                </div>
                                <?php if (!empty($pinnedProjects)): ?>
                                <ul class="pinned-list">
                                    <?php foreach ($pinnedProjects as $pp):
                                        $primaryAction = $pp['actions'][0] ?? null;
                                        if (!$primaryAction) continue;
                                    ?>
                                    <li>
                                        <a href="<?= htmlspecialchars($primaryAction['url']) ?>" class="pinned-row">
                                            <span class="pinned-row-ic <?= $pp['bg_color'] ?> <?= $pp['icon_color'] ?>">
                                                <i class="fa-solid <?= $pp['icon'] ?>"></i>
                                            </span>
                                            <span class="pinned-row-label"><?= htmlspecialchars($pp['title']) ?></span>
                                            <i class="fa-solid fa-arrow-right pinned-row-arrow"></i>
                                        </a>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php else: ?>
                                <div class="pinned-mini-empty">
                                    <i class="fa-solid fa-thumbtack"></i>
                                    <span>ปักหมุดระบบที่ใช้บ่อยใน <a href="javascript:switchSection('apps', document.querySelector('[data-section=apps]'))">App Launcher</a></span>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Quick Shortcuts (flat, role-aware) -->
                            <?php
                            $quickShortcuts = [];
                            if ($isStaff && $canEcampaign) {
                                $quickShortcuts[] = ['url' => '../staff/index.php',        'icon' => 'fa-qrcode',         'label' => 'เปิดสแกน QR เช็คอิน'];
                                $quickShortcuts[] = ['url' => '../admin/daily_report.php', 'icon' => 'fa-clipboard-list', 'label' => 'รายงานเช็คอินวันนี้'];
                            }
                            if ($canEcampaign) {
                                $quickShortcuts[] = ['url' => '../admin/campaigns.php', 'icon' => 'fa-bullhorn',     'label' => 'Campaign Manager'];
                                $quickShortcuts[] = ['url' => '../admin/bookings.php',  'icon' => 'fa-calendar-check', 'label' => 'รายการนัดหมาย'];
                            }
                            if (!$isStaff || in_array($adminRole, ['admin', 'superadmin'], true)) {
                                $quickShortcuts[] = ['url' => 'users.php', 'icon' => 'fa-users', 'label' => 'Users Center'];
                            }
                            $quickShortcuts[] = ['url' => '../asset/index.php',       'icon' => 'fa-boxes-stacked', 'label' => 'ครุภัณฑ์สำนักงาน'];
                            $quickShortcuts[] = ['url' => '../consumables/index.php', 'icon' => 'fa-box-open',      'label' => 'วัสดุสิ้นเปลือง'];
                            if ($canSystemLogs) {
                                $quickShortcuts[] = [
                                    'url'   => "javascript:switchSection('error_logs', document.querySelector('[data-section=error_logs]'))",
                                    'icon'  => 'fa-bug',
                                    'label' => 'Error Logs',
                                ];
                            }
                            ?>
                            <div class="dash-panel quick-list">
                                <div class="dash-panel-head">
                                    <div class="sec-title" style="font-size:.95rem">
                                        <i class="fa-solid fa-bolt" style="color:#0ea5e9;font-size:.78rem;margin-right:2px"></i>
                                        ทางลัด
                                    </div>
                                </div>
                                <ul class="quick-items">
                                    <?php foreach ($quickShortcuts as $sc): ?>
                                        <li>
                                            <a href="<?= htmlspecialchars($sc['url']) ?>">
                                                <i class="fa-solid <?= htmlspecialchars($sc['icon']) ?>"></i>
                                                <?= htmlspecialchars($sc['label']) ?>
                                                <i class="fa-solid fa-arrow-right ml-auto"></i>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>

                            <!-- 🚀 Slim migration banner (dismissable) -->
                            <div id="apps-migration-banner" class="apps-migration apps-migration--slim">
                                <div class="apps-migration-glow"></div>
                                <div class="apps-migration-body">
                                    <div class="apps-migration-eyebrow">
                                        <i class="fa-solid fa-sparkles"></i> ใหม่
                                    </div>
                                    <h2 class="apps-migration-title">
                                        เปิดระบบทั้งหมดที่ <span>App Launcher</span>
                                    </h2>
                                    <p class="apps-migration-desc">
                                        เมนูเปิดทุกระบบย้ายไปอยู่หน้าใหม่แล้ว — เปิดได้จาก sidebar กลุ่ม OVERVIEW
                                    </p>
                                    <div class="apps-migration-actions">
                                        <a href="javascript:switchSection('apps', document.querySelector('[data-section=apps]'))"
                                            class="apps-migration-cta" id="apps-migration-cta">
                                            <i class="fa-solid fa-grip"></i> เปิด
                                        </a>
                                        <button type="button" id="apps-migration-tour-btn" class="apps-migration-ghost">
                                            <i class="fa-solid fa-route"></i>
                                        </button>
                                        <button type="button" id="apps-migration-dismiss" class="apps-migration-dismiss" title="ซ่อน">
                                            <i class="fa-solid fa-xmark"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                        </aside>
                    </div>

                    <!-- FOOTER -->
                    <footer class="pt-6 pb-4 text-center">
                        <div class="flex items-center justify-center gap-2 opacity-25">
                            <i class="fa-solid fa-shield-halved" style="color:#2e9e63"></i>
                            <span class="text-[10px] font-black uppercase tracking-[.4em]">Central Command v3.0 · RSU
                                Medical Clinic</span>
                        </div>
                    </footer>

                </div><!-- /section-dashboard inner -->

            </div><!-- /section-dashboard -->
<?php layout_end(); ?>
