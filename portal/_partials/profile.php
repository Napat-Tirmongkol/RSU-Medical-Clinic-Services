<?php
/**
 * portal/_partials/profile.php
 * Section: โปรไฟล์ของฉัน — staff (sys_staff) แก้ชื่อ + เปลี่ยนรหัสผ่าน
 *
 * โหลดผ่าน portal/index.php?section=profile
 * POST handler อยู่ใน portal/actions/portal_handlers.php (action=update_profile)
 */

if (!isset($pdo) || !($pdo instanceof PDO)) {
    return;
}

$staffId = (int)($_SESSION['admin_id'] ?? 0);
$me = null;
if ($staffId > 0) {
    $stmt = $pdo->prepare("SELECT username, full_name, role, ecampaign_role,
                                  IFNULL(linked_line_user_id, '') AS linked_line_user_id,
                                  IFNULL(notify_sla_via_line, 1) AS notify_sla_via_line,
                                  IFNULL(access_ecampaign,0) AS access_ecampaign,
                                  IFNULL(access_eborrow,0)   AS access_eborrow,
                                  IFNULL(access_insurance,0) AS access_insurance,
                                  IFNULL(access_registry,0)  AS access_registry,
                                  IFNULL(access_edms,0)      AS access_edms,
                                  IFNULL(access_edms_sla_admin,0) AS access_edms_sla_admin,
                                  IFNULL(access_system_logs,0)   AS access_system_logs,
                                  IFNULL(access_site_settings,0) AS access_site_settings,
                                  IFNULL(access_ai,0)            AS access_ai,
                                  IFNULL(access_consumables,0)   AS access_consumables,
                                  IFNULL(access_asset,0)         AS access_asset,
                                  IFNULL(access_finance,0)       AS access_finance,
                                  IFNULL(access_scholarship,0)   AS access_scholarship,
                                  IFNULL(access_dashboard_admin,0) AS access_dashboard_admin,
                                  IFNULL(access_monthly_report,0) AS access_monthly_report,
                                  IFNULL(access_nurse_productivity,0) AS access_nurse_productivity,
                                  IFNULL(access_daily_summary,0) AS access_daily_summary,
                                  IFNULL(access_director_view,0)  AS access_director_view,
                                  IFNULL(access_identity,0)       AS access_identity
                           FROM sys_staff WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $staffId]);
    $me = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Flash message จาก PRG redirect
$flash = $_SESSION['profile_flash'] ?? null;
unset($_SESSION['profile_flash']);

// แมป access flag → label สำหรับโชว์สิทธิ์ที่ได้รับ
$accessLabels = [
    'access_ecampaign'      => ['e-Campaign',          'fa-bullhorn',           'emerald'],
    'access_eborrow'        => ['e-Borrow',            'fa-toolbox',            'slate'],
    'access_insurance'      => ['Insurance Sync',      'fa-shield-halved',      'indigo'],
    'access_registry'       => ['Registry Upload',     'fa-id-card-clip',       'cyan'],
    'access_edms'           => ['e-DMS',               'fa-folder-open',        'amber'],
    'access_edms_sla_admin' => ['e-DMS SLA Admin',     'fa-stopwatch-20',       'purple'],
    'access_system_logs'    => ['System Logs',         'fa-bug',                'rose'],
    'access_site_settings'  => ['Site Settings',       'fa-gears',              'purple'],
    'access_ai'             => ['AI Suite',            'fa-wand-magic-sparkles','purple'],
    'access_consumables'    => ['Consumables',         'fa-syringe',            'rose'],
    'access_asset'          => ['Asset Inventory',     'fa-warehouse',          'amber'],
    'access_finance'        => ['การเงิน (Cash Book)',  'fa-money-bill-trend-up','emerald'],
    'access_scholarship'    => ['Scholarship',         'fa-graduation-cap',     'emerald'],
    'access_dashboard_admin'=> ['Dashboard Editor',    'fa-chart-pie',          'blue'],
    'access_monthly_report' => ['รายงานประจำเดือน',     'fa-clipboard-list',     'amber'],
    'access_nurse_productivity' => ['Productivity พยาบาล','fa-user-nurse',         'amber'],
    'access_daily_summary'  => ['สรุปงานประจำวัน',      'fa-clipboard-check',    'amber'],
    'access_director_view'  => ['ผู้อำนวยการ',          'fa-user-tie',           'rose'],
    'access_identity'       => ['Identity & Governance', 'fa-id-card-clip',       'blue'],
];
?>

<style>
    /* Scoped styles for profile section */
    #section-profile .pf-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 24px;
        box-shadow: 0 1px 2px rgba(0,0,0,.02);
    }
    #section-profile .pf-card-head {
        padding: 16px 24px;
        background: #f8fafc;
        border-bottom: 1px solid #f1f5f9;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    #section-profile .pf-card-head h3 {
        font-size: 13px;
        font-weight: 900;
        color: #334155;
        text-transform: uppercase;
        letter-spacing: .08em;
        margin: 0;
    }
    #section-profile .pf-card-body { padding: 24px; }

    #section-profile .pf-input {
        width: 100%;
        padding: 12px 16px;
        border: 1.5px solid #e2e8f0;
        border-radius: 14px;
        font-size: 14px;
        font-weight: 600;
        color: #1e293b;
        background: #f8fafc;
        transition: all .2s;
        font-family: inherit;
    }
    #section-profile .pf-input:focus {
        border-color: #2e9e63;
        outline: none;
        box-shadow: 0 0 0 4px rgba(46,158,99,.1);
        background: #fff;
    }
    #section-profile .pf-input:disabled {
        background: #f1f5f9;
        color: #94a3b8;
        cursor: not-allowed;
    }
    #section-profile .pf-label {
        display: block;
        font-size: 11px;
        font-weight: 900;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: .12em;
        margin-bottom: 8px;
    }
    #section-profile .pf-btn {
        background: linear-gradient(135deg, #2e9e63, #10b981);
        color: #fff;
        padding: 14px 28px;
        border-radius: 14px;
        font-weight: 900;
        font-size: 14px;
        border: 0;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 10px 22px -6px rgba(16,185,129,.4);
        transition: all .25s;
        font-family: inherit;
    }
    #section-profile .pf-btn:hover { transform: translateY(-2px); box-shadow: 0 14px 28px -6px rgba(16,185,129,.5); }
    #section-profile .pf-btn:active { transform: scale(.98); }

    #section-profile .pf-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 12px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: .06em;
    }

    #section-profile .pf-access-row {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 12px 14px;
        border-radius: 14px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
    }
    #section-profile .pf-access-row.is-on  { background: #f0fdf4; border-color: #bbf7d0; }
    #section-profile .pf-access-row.is-off { opacity: .55; }
    #section-profile .pf-access-icon {
        width: 36px; height: 36px; border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        font-size: 14px; flex-shrink: 0;
    }

    /* ── Bold & Colorful + DARK MODE ─────────────────────────────── */
    #section-profile .pf-card { isolation: isolate; transition: transform .25s cubic-bezier(.16,1,.3,1), box-shadow .25s ease, border-color .25s ease; }
    #section-profile .pf-card.fx-tilt:hover { --lift: -3px; box-shadow:0 18px 36px -18px rgba(16,185,129,.30); border-color:rgba(16,185,129,.30); }

    body[data-theme='dark'] #section-profile .pf-card { background:#0f172a; border-color:#1e293b; box-shadow: 0 1px 0 rgba(255,255,255,.04), 0 8px 22px rgba(0,0,0,.35); }
    body[data-theme='dark'] #section-profile .pf-card-head { background:#1e293b; border-color:#334155; }
    body[data-theme='dark'] #section-profile .pf-card-head h3 { color:#f1f5f9; }
    body[data-theme='dark'] #section-profile .pf-input { background:#0b1220; border-color:#1e293b; color:#e2e8f0; }
    body[data-theme='dark'] #section-profile .pf-input:focus { background:#0f172a; }
    body[data-theme='dark'] #section-profile .pf-input:disabled { background:rgba(148,163,184,.08); color:#64748b; }
    body[data-theme='dark'] #section-profile .pf-label { color:#cbd5e1; }
    body[data-theme='dark'] #section-profile .pf-access-row { background:#1e293b; border-color:#334155; }
    body[data-theme='dark'] #section-profile .pf-access-row.is-on { background:rgba(16,185,129,.18); border-color:rgba(16,185,129,.30); }

    body[data-theme='dark'] #section-profile .bg-white { background:#0f172a !important; }
    body[data-theme='dark'] #section-profile .bg-slate-50 { background: rgba(148,163,184,.08) !important; }
    body[data-theme='dark'] #section-profile .bg-slate-100 { background: rgba(148,163,184,.14) !important; }
    body[data-theme='dark'] #section-profile .bg-emerald-50 { background: rgba(16,185,129,.18) !important; }
    body[data-theme='dark'] #section-profile .bg-amber-50 { background: rgba(245,158,11,.18) !important; }
    body[data-theme='dark'] #section-profile .bg-blue-50 { background: rgba(59,130,246,.18) !important; }
    body[data-theme='dark'] #section-profile .text-slate-900 { color:#f1f5f9 !important; }
    body[data-theme='dark'] #section-profile .text-slate-800 { color:#f1f5f9 !important; }
    body[data-theme='dark'] #section-profile .text-slate-700 { color:#e2e8f0 !important; }
    body[data-theme='dark'] #section-profile .text-slate-500 { color:#94a3b8 !important; }
    body[data-theme='dark'] #section-profile .text-slate-400 { color:#64748b !important; }
    body[data-theme='dark'] #section-profile .border-slate-200 { border-color:#1e293b !important; }
    body[data-theme='dark'] #section-profile .border-slate-100 { border-color:#1e293b !important; }

    @media (prefers-reduced-motion: reduce) {
        #section-profile .pf-card { transition: none !important; transform: none !important; }
    }
</style>

<div class="max-w-[900px] mx-auto px-4 md:px-8 py-6 space-y-6">

    <!-- Header -->
    <div class="flex items-center gap-4 mb-2">
        <div class="w-12 h-12 bg-white rounded-xl shadow-sm border border-gray-200 flex items-center justify-center text-emerald-600 text-xl">
            <i class="fa-solid fa-user-pen"></i>
        </div>
        <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-black text-slate-800">โปรไฟล์ของฉัน</h2>
            <p class="text-slate-500 text-sm font-medium">แก้ไขข้อมูลส่วนตัวและรหัสผ่านของคุณ</p>
        </div>
    </div>

    <?php if ($flash): ?>
        <?php if ($flash['type'] === 'success'): ?>
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 px-5 py-4 rounded-2xl flex items-center gap-3 font-bold text-sm">
                <i class="fa-solid fa-circle-check text-emerald-500"></i>
                <?= htmlspecialchars($flash['msg']) ?>
            </div>
        <?php else: ?>
            <div class="bg-rose-50 border border-rose-200 text-rose-700 px-5 py-4 rounded-2xl flex items-center gap-3 font-bold text-sm">
                <i class="fa-solid fa-circle-exclamation text-rose-500"></i>
                <?= htmlspecialchars($flash['msg']) ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!$me): ?>
        <div class="pf-card fx-tilt fx-tilt-light" data-tilt="3">
            <div class="pf-card-body text-center text-rose-600 font-bold py-12">
                <i class="fa-solid fa-user-slash text-3xl mb-3 block"></i>
                ไม่พบข้อมูลผู้ใช้ในระบบ
            </div>
        </div>
    <?php else: ?>

        <!-- ข้อมูลบัญชี + แก้ไขชื่อ -->
        <form method="POST" action="index.php?section=profile" class="pf-card" id="profile-form">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="update_profile">

            <div class="pf-card-head">
                <i class="fa-solid fa-id-card text-emerald-600"></i>
                <h3>ข้อมูลบัญชี</h3>
            </div>
            <div class="pf-card-body space-y-5">
                <div>
                    <label class="pf-label">ชื่อผู้ใช้ (Username)</label>
                    <div class="flex items-center gap-3 flex-wrap">
                        <input type="text" class="pf-input" style="max-width:340px" value="<?= htmlspecialchars($me['username']) ?>" disabled>
                        <span class="pf-badge" style="background:#f0fdf4;color:#166534;border:1px solid #bbf7d0">
                            <i class="fa-solid fa-shield-halved"></i>
                            <?= htmlspecialchars($me['ecampaign_role'] ?: 'editor') ?>
                        </span>
                        <?php if (!empty($me['role'])): ?>
                            <span class="pf-badge" style="background:#eef2ff;color:#3730a3;border:1px solid #c7d2fe">
                                <i class="fa-solid fa-user-tag"></i>
                                <?= htmlspecialchars($me['role']) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <p class="text-[11px] text-slate-400 font-medium mt-2">ชื่อผู้ใช้ไม่สามารถแก้ไขได้เอง — ติดต่อผู้ดูแลระบบหากต้องเปลี่ยน</p>
                </div>

                <div>
                    <label class="pf-label" for="pf-fullname">ชื่อ-นามสกุล <span class="text-rose-500">*</span></label>
                    <input type="text" id="pf-fullname" name="full_name" class="pf-input"
                           value="<?= htmlspecialchars($me['full_name']) ?>" required
                           placeholder="ระบุชื่อ-นามสกุลของคุณ">
                </div>

                <!-- เปลี่ยนรหัสผ่าน -->
                <div class="pt-5 border-t border-slate-100">
                    <div class="flex items-center gap-2 mb-3">
                        <i class="fa-solid fa-lock text-emerald-500"></i>
                        <h4 class="text-sm font-black text-slate-900 uppercase tracking-widest">เปลี่ยนรหัสผ่าน</h4>
                    </div>
                    <p class="text-[11px] text-slate-400 font-bold mb-4">เว้นว่างไว้หากไม่ต้องการเปลี่ยน · ขั้นต่ำ 6 ตัวอักษร</p>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="pf-label" for="pf-newpwd">รหัสผ่านใหม่</label>
                            <input type="password" id="pf-newpwd" name="new_password" class="pf-input" placeholder="••••••••" minlength="6" autocomplete="new-password">
                        </div>
                        <div>
                            <label class="pf-label" for="pf-confirmpwd">ยืนยันรหัสผ่านใหม่</label>
                            <input type="password" id="pf-confirmpwd" name="confirm_password" class="pf-input" placeholder="••••••••" minlength="6" autocomplete="new-password">
                        </div>
                    </div>
                </div>

                <div class="pt-3 flex justify-end">
                    <button type="submit" class="pf-btn">
                        <i class="fa-solid fa-floppy-disk"></i>
                        บันทึกข้อมูลโปรไฟล์
                    </button>
                </div>
            </div>
        </form>

        <!-- เชื่อมต่อบัญชี LINE -->
        <?php
            $lineLinked = !empty($me['linked_line_user_id']);
            $lineUid = (string)($me['linked_line_user_id'] ?? '');
            $notifyViaLine = (int)($me['notify_sla_via_line'] ?? 1) === 1;
            $linkFlash = $_SESSION['line_link_flash'] ?? null;
            unset($_SESSION['line_link_flash']);
        ?>
        <div class="pf-card fx-tilt fx-tilt-light" data-tilt="3">
            <div class="pf-card-head">
                <i class="fa-brands fa-line" style="color:#06c755"></i>
                <h3>เชื่อมต่อบัญชี LINE</h3>
            </div>
            <div class="pf-card-body">
                <?php if ($linkFlash): ?>
                    <div class="rounded-2xl border <?= $linkFlash['ok'] ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-rose-50 border-rose-200 text-rose-700' ?> px-4 py-2.5 text-xs font-bold mb-4">
                        <i class="fa-solid <?= $linkFlash['ok'] ? 'fa-check-circle' : 'fa-circle-exclamation' ?> mr-1"></i>
                        <?= htmlspecialchars($linkFlash['msg']) ?>
                    </div>
                <?php endif; ?>

                <p class="text-[12px] text-slate-500 font-medium mb-4">
                    ผูกบัญชี LINE ของคุณกับบัญชี Staff เพื่อรับการแจ้งเตือน — เช่น SLA warning / breach, เอกสารใหม่ที่ถูกมอบหมาย
                </p>

                <?php if ($lineLinked): ?>
                    <div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-4 mb-3">
                        <div class="flex items-center justify-between gap-3 flex-wrap">
                            <div class="flex items-center gap-3">
                                <div class="w-11 h-11 rounded-full bg-white border border-emerald-200 flex items-center justify-center text-2xl" style="color:#06c755">
                                    <i class="fa-brands fa-line"></i>
                                </div>
                                <div>
                                    <p class="text-xs font-black text-emerald-700">เชื่อมต่อแล้ว <i class="fa-solid fa-circle-check ml-0.5"></i></p>
                                    <p class="text-[10px] font-mono text-slate-500 mt-0.5"><?= htmlspecialchars(substr($lineUid, 0, 8) . '...' . substr($lineUid, -6)) ?></p>
                                </div>
                            </div>
                            <button type="button" onclick="profileUnlinkLine()" class="bg-white hover:bg-rose-50 text-rose-600 border border-rose-200 px-3 py-1.5 rounded-xl text-xs font-black inline-flex items-center gap-1.5">
                                <i class="fa-solid fa-link-slash"></i> ยกเลิกการเชื่อม
                            </button>
                        </div>
                    </div>

                    <label class="flex items-center gap-2 cursor-pointer p-3 bg-slate-50 rounded-xl border border-slate-100">
                        <input type="checkbox" id="profile-notify-line" <?= $notifyViaLine ? 'checked' : '' ?> onchange="profileToggleNotifyLine(this.checked)" class="w-4 h-4 accent-emerald-500">
                        <span class="text-xs font-bold text-slate-700">รับแจ้งเตือน SLA ผ่าน LINE (warning / breach / escalation)</span>
                    </label>
                <?php else: ?>
                    <a href="../line_api/staff_link_line.php"
                       class="inline-flex items-center gap-2 text-white px-5 py-2.5 rounded-2xl text-sm font-black shadow-sm transition-all hover:opacity-90"
                       style="background:#06c755">
                        <i class="fa-brands fa-line text-lg"></i>
                        เชื่อมต่อบัญชี LINE
                    </a>
                    <p class="text-[11px] font-bold text-slate-400 mt-3">
                        <i class="fa-solid fa-info-circle text-slate-300"></i>
                        จะเปิดหน้า LINE Login — เข้าด้วยบัญชีที่อยากผูก แล้วระบบจะกลับมาที่หน้านี้
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- สิทธิ์การเข้าถึงระบบ -->
        <div class="pf-card fx-tilt fx-tilt-light" data-tilt="3">
            <div class="pf-card-head">
                <i class="fa-solid fa-key text-emerald-600"></i>
                <h3>สิทธิ์การเข้าถึงระบบ</h3>
            </div>
            <div class="pf-card-body">
                <p class="text-[12px] text-slate-500 font-medium mb-4">
                    สิทธิ์ของคุณกำหนดโดยผู้ดูแลระบบ — ติดต่อ admin หากต้องการเปลี่ยนแปลง
                </p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <?php foreach ($accessLabels as $key => [$label, $icon, $tone]): ?>
                        <?php $on = (int)($me[$key] ?? 0) === 1; ?>
                        <div class="pf-access-row <?= $on ? 'is-on' : 'is-off' ?>">
                            <div class="pf-access-icon" style="background:<?= $on ? '#dcfce7' : '#f1f5f9' ?>;color:<?= $on ? '#166534' : '#94a3b8' ?>">
                                <i class="fa-solid <?= htmlspecialchars($icon) ?>"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="text-[13px] font-black text-slate-800"><?= htmlspecialchars($label) ?></div>
                                <div class="text-[10px] font-bold uppercase tracking-widest <?= $on ? 'text-emerald-600' : 'text-slate-400' ?>">
                                    <?= $on ? 'มีสิทธิ์' : 'ไม่มีสิทธิ์' ?>
                                </div>
                            </div>
                            <i class="fa-solid <?= $on ? 'fa-circle-check text-emerald-500' : 'fa-circle-minus text-slate-300' ?>"></i>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

    <?php endif; ?>

</div>

<script>
(function () {
    // Client-side validation: ยืนยันรหัสผ่านตรงกัน
    var form = document.getElementById('profile-form');
    if (!form) return;
    form.addEventListener('submit', function (e) {
        var pwd  = form.querySelector('#pf-newpwd');
        var cpwd = form.querySelector('#pf-confirmpwd');
        if (!pwd || !cpwd) return;
        if (pwd.value !== '' && pwd.value !== cpwd.value) {
            e.preventDefault();
            if (window.Swal) {
                Swal.fire({
                    icon: 'warning',
                    title: 'รหัสผ่านไม่ตรงกัน',
                    text: 'กรุณายืนยันรหัสผ่านใหม่ให้ตรงกัน',
                    confirmButtonColor: '#2e9e63',
                });
            } else {
                cpwd.focus();
            }
        }
    });
})();

// ════════ LINE Link self-service ════════
(function(){
    const csrf = window.portal_CSRF || '';

    async function profileLineAjax(action, params = {}) {
        const fd = new FormData();
        fd.append('csrf_token', csrf);
        fd.append('action', action);
        Object.entries(params).forEach(([k, v]) => fd.append(k, v));
        const res = await fetch('ajax_profile_line.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        return res.json();
    }

    window.profileUnlinkLine = async function() {
        const { isConfirmed } = await Swal.fire({
            icon: 'warning',
            title: 'ยกเลิกการเชื่อมบัญชี LINE?',
            text: 'คุณจะไม่ได้รับการแจ้งเตือนผ่าน LINE อีก — เชื่อมใหม่ได้ตลอดเวลา',
            showCancelButton: true,
            confirmButtonText: 'ยกเลิกการเชื่อม',
            cancelButtonText: 'ไม่ใช่ตอนนี้',
            confirmButtonColor: '#dc2626',
        });
        if (!isConfirmed) return;
        const r = await profileLineAjax('unlink');
        if (r.ok) {
            await Swal.fire({ icon: 'success', title: r.message, timer: 1300, showConfirmButton: false });
            location.reload();
        } else {
            Swal.fire({ icon: 'error', title: 'ไม่สำเร็จ', text: r.message });
        }
    };

    window.profileToggleNotifyLine = async function(checked) {
        const r = await profileLineAjax('toggle_notify_sla', { enabled: checked ? 1 : 0 });
        if (r.ok) {
            if (window.Swal) Swal.fire({ icon: 'success', title: r.message, timer: 900, showConfirmButton: false, position: 'top-end', toast: true });
        } else {
            Swal.fire({ icon: 'error', title: r.message });
            // Revert checkbox
            const box = document.getElementById('profile-notify-line');
            if (box) box.checked = !checked;
        }
    };
})();
</script>
