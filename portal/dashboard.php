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
