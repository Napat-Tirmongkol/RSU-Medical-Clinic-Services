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
                                  IFNULL(access_ecampaign,0) AS access_ecampaign,
                                  IFNULL(access_eborrow,0)   AS access_eborrow,
                                  IFNULL(access_insurance,0) AS access_insurance,
                                  IFNULL(access_registry,0)  AS access_registry,
                                  IFNULL(access_edms,0)      AS access_edms,
                                  IFNULL(access_system_logs,0)   AS access_system_logs,
                                  IFNULL(access_site_settings,0) AS access_site_settings,
                                  IFNULL(access_ai,0)            AS access_ai,
                                  IFNULL(access_consumables,0)   AS access_consumables,
                                  IFNULL(access_asset,0)         AS access_asset
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
    'access_system_logs'    => ['System Logs',         'fa-bug',                'rose'],
    'access_site_settings'  => ['Site Settings',       'fa-gears',              'purple'],
    'access_ai'             => ['AI Suite',            'fa-wand-magic-sparkles','purple'],
    'access_consumables'    => ['Consumables',         'fa-syringe',            'rose'],
    'access_asset'          => ['Asset Inventory',     'fa-warehouse',          'amber'],
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
        <div class="pf-card">
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

        <!-- สิทธิ์การเข้าถึงระบบ -->
        <div class="pf-card">
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
</script>
