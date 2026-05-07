<!-- ════════════ SECTION: ACCOUNT (บัญชีของฉัน) ════════════ -->
<?php
// portal/_partials/account.php — included by portal/index.php
// $pdo, $adminRole, $portal_CSRF (via JS), and SweetAlert2 are available from parent.

$_acc_id = (int)($_SESSION['admin_id'] ?? 0);
$_acc_admin = null;

if ($_acc_id > 0) {
    try {
        // Self-heal: ensure prefs columns exist (idempotent SHOW COLUMNS check)
        foreach (['phone','avatar_path','theme_pref','notif_email','notif_inapp'] as $_acc_col) {
            $_acc_chk = $pdo->query("SHOW COLUMNS FROM sys_admins LIKE " . $pdo->quote($_acc_col));
            if (!$_acc_chk->fetch()) {
                $clauses = [
                    'phone'        => "ADD COLUMN phone VARCHAR(30) DEFAULT NULL",
                    'avatar_path'  => "ADD COLUMN avatar_path VARCHAR(255) DEFAULT NULL",
                    'theme_pref'   => "ADD COLUMN theme_pref ENUM('light','dark','auto') NOT NULL DEFAULT 'light'",
                    'notif_email'  => "ADD COLUMN notif_email TINYINT(1) NOT NULL DEFAULT 1",
                    'notif_inapp'  => "ADD COLUMN notif_inapp TINYINT(1) NOT NULL DEFAULT 1",
                ];
                $pdo->exec("ALTER TABLE sys_admins " . $clauses[$_acc_col]);
            }
        }

        $_acc_st = $pdo->prepare("SELECT id, full_name, username, email, phone, avatar_path,
                                         theme_pref, notif_email, notif_inapp, role, status, created_at
                                  FROM sys_admins WHERE id = :id LIMIT 1");
        $_acc_st->execute([':id' => $_acc_id]);
        $_acc_admin = $_acc_st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        $_acc_admin = null;
    }
}

if (!$_acc_admin) {
    echo '<div style="padding:80px;text-align:center;color:#dc2626;font-weight:900"><i class="fa-solid fa-circle-exclamation" style="font-size:3rem;display:block;margin-bottom:12px"></i> ไม่พบข้อมูลบัญชี กรุณาเข้าสู่ระบบใหม่</div>';
    return;
}

// Activity log (current admin only)
$_acc_log_page  = max(1, (int)($_GET['ap'] ?? 1));
$_acc_log_lim   = 20;
$_acc_log_off   = ($_acc_log_page - 1) * $_acc_log_lim;
$_acc_log_total = 0;
$_acc_log_rows  = [];
try {
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM sys_activity_logs WHERE user_id = :id");
    $cnt->execute([':id' => $_acc_id]);
    $_acc_log_total = (int)$cnt->fetchColumn();
    $_acc_log_pages = max(1, (int)ceil($_acc_log_total / $_acc_log_lim));
    if ($_acc_log_page > $_acc_log_pages) {
        $_acc_log_page = $_acc_log_pages;
        $_acc_log_off  = ($_acc_log_page - 1) * $_acc_log_lim;
    }
    $rows = $pdo->prepare("SELECT action, description, timestamp
                           FROM sys_activity_logs
                           WHERE user_id = :id
                           ORDER BY timestamp DESC
                           LIMIT $_acc_log_lim OFFSET $_acc_log_off");
    $rows->execute([':id' => $_acc_id]);
    $_acc_log_rows = $rows->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_acc_log_pages = 1;
}

$_acc_avatar = $_acc_admin['avatar_path'] ?: '';
$_acc_initial = mb_substr($_acc_admin['full_name'] ?: $_acc_admin['username'] ?? '?', 0, 1);

// Quick links — registered destinations the staff likely uses daily.
// Each item: [section_id, label, icon, accent]. switchSection() handles routing.
$_acc_quick_links = [
    ['dashboard',     'แดชบอร์ด',        'fa-house',          '#0ea5e9'],
    ['identity',      'รายชื่อผู้ใช้',     'fa-users',           '#6366f1'],
    ['announcements', 'ประกาศ',          'fa-bullhorn',        '#f59e0b'],
    ['edms',          'เอกสารอิเล็กฯ',    'fa-folder-tree',     '#10b981'],
    ['activity_logs', 'บันทึกกิจกรรม',    'fa-clipboard-list',  '#64748b'],
    ['clinic_data',   'ข้อมูลคลินิก',     'fa-hospital',        '#0d9488'],
];
?>

<style>
    /* Account section ── scoped under #section-account so we don't bleed into siblings */
    #section-account .acc-tab {
        flex: 1; padding: 10px 14px; border-radius: 12px;
        font-size: 12px; font-weight: 800; letter-spacing: .02em;
        color: #64748b; background: transparent; border: 0; cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center; gap: 8px;
        transition: all .18s; font-family: inherit;
    }
    #section-account .acc-tab i { font-size: 11px; }
    #section-account .acc-tab:hover  { color: #334155; background: #f1f5f9; }
    #section-account .acc-tab.active { color: #fff; background: #2e9e63; box-shadow: 0 6px 18px rgba(46,158,99,.22); }
    #section-account .acc-tab.active:hover { background: #1f7a4d; }
    #section-account .acc-pane[hidden] { display: none !important; }
    #section-account .acc-card { background:#fff; border:1px solid #e5e7eb; border-radius:24px; box-shadow:0 1px 2px rgba(0,0,0,.02); }
    #section-account .acc-card-head { padding:16px 24px; background:#f8fafc; border-bottom:1px solid #f1f5f9; }
    #section-account .acc-card-head h3 { font-size:13px; font-weight:900; color:#334155; text-transform:uppercase; letter-spacing:.08em; margin:0; }
    #section-account .acc-card-body { padding:24px; }
    #section-account .acc-input {
        width: 100%; padding: 11px 14px; background:#f9fafb;
        border: 1.5px solid #e5e7eb; border-radius: 12px;
        font-size: 14px; font-weight: 600; color: #0f172a;
        transition: all .18s; outline: none; font-family: inherit;
    }
    #section-account .acc-input:focus { background:#fff; border-color:#2e9e63; box-shadow: 0 0 0 4px rgba(46,158,99,.1); }
    #section-account .acc-label { display:block; font-size:11px; font-weight:900; color:#64748b; text-transform:uppercase; letter-spacing:.1em; margin-bottom:6px; }
    #section-account .acc-btn-primary {
        background:#2e9e63; color:#fff; border:0; cursor:pointer;
        padding:11px 22px; border-radius:12px; font-size:13px; font-weight:900;
        display:inline-flex; align-items:center; gap:8px;
        box-shadow: 0 8px 20px rgba(46,158,99,.22);
        transition: all .18s; font-family: inherit;
    }
    #section-account .acc-btn-primary:hover { background:#1f7a4d; }
    #section-account .acc-btn-primary:active { transform: scale(.97); }
    #section-account .acc-btn-primary:disabled { opacity:.55; cursor:not-allowed; transform:none; }
    #section-account .acc-btn-ghost {
        background:#f1f5f9; color:#475569; border:0; cursor:pointer;
        padding:10px 18px; border-radius:12px; font-size:12px; font-weight:900;
        display:inline-flex; align-items:center; gap:6px;
        transition: all .18s; font-family: inherit;
    }
    #section-account .acc-btn-ghost:hover { background:#e2e8f0; color:#0f172a; }

    /* Toggle switch */
    #section-account .acc-toggle { position:relative; display:inline-block; width:46px; height:26px; }
    #section-account .acc-toggle input { opacity:0; width:0; height:0; }
    #section-account .acc-toggle .acc-slider {
        position:absolute; cursor:pointer; inset:0; background:#cbd5e1; border-radius:30px; transition: .2s;
    }
    #section-account .acc-toggle .acc-slider::before {
        content:""; position:absolute; height:20px; width:20px; left:3px; bottom:3px;
        background:#fff; border-radius:50%; transition:.2s; box-shadow:0 2px 6px rgba(0,0,0,.15);
    }
    #section-account .acc-toggle input:checked + .acc-slider { background:#2e9e63; }
    #section-account .acc-toggle input:checked + .acc-slider::before { transform: translateX(20px); }

    /* Theme radio cards */
    #section-account .acc-theme-grid { display:grid; grid-template-columns: repeat(auto-fit,minmax(140px,1fr)); gap:12px; }
    #section-account .acc-theme-card {
        position:relative; border:2px solid #e5e7eb; border-radius:16px; padding:14px;
        cursor:pointer; transition:all .18s; background:#fff;
    }
    #section-account .acc-theme-card:hover { border-color:#94a3b8; }
    #section-account .acc-theme-card input { position:absolute; opacity:0; }
    #section-account .acc-theme-card.is-active { border-color:#2e9e63; background:#f0fdf4; box-shadow:0 0 0 4px rgba(46,158,99,.1); }
    #section-account .acc-theme-preview {
        height: 60px; border-radius:10px; margin-bottom:10px;
        display:flex; align-items:center; justify-content:center; font-size:22px;
    }
    #section-account .acc-theme-light  { background: linear-gradient(135deg,#fff,#f1f5f9); color:#0f172a; border:1px solid #e2e8f0; }
    #section-account .acc-theme-dark   { background: linear-gradient(135deg,#0f172a,#334155); color:#fff; }
    #section-account .acc-theme-auto   { background: linear-gradient(90deg,#fff 50%,#0f172a 50%); color:#475569; }

    /* Activity row */
    #section-account .acc-act-row { padding: 14px 18px; border-bottom: 1px solid #f1f5f9; display:flex; align-items:center; gap:14px; }
    #section-account .acc-act-row:last-child { border-bottom: 0; }
    #section-account .acc-act-icon { width:36px; height:36px; border-radius:10px; background:#f1f5f9; color:#64748b; display:flex; align-items:center; justify-content:center; flex-shrink:0; }

    /* Quick link card */
    #section-account .acc-ql-grid { display:grid; grid-template-columns: repeat(auto-fit,minmax(160px,1fr)); gap:12px; }
    #section-account .acc-ql {
        background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:16px;
        text-align:center; cursor:pointer; transition:all .18s; text-decoration:none;
        display:flex; flex-direction:column; align-items:center; gap:8px;
    }
    #section-account .acc-ql:hover { transform:translateY(-2px); box-shadow:0 12px 28px rgba(15,23,42,.08); }
    #section-account .acc-ql-icon { width:48px; height:48px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:18px; color:#fff; }
    #section-account .acc-ql-label { font-size:12px; font-weight:800; color:#334155; }
</style>

<div class="max-w-[1100px] mx-auto px-4 py-6">

    <!-- Header -->
    <div class="mb-5 flex items-center gap-4">
        <div class="w-12 h-12 bg-white rounded-xl shadow-sm border border-gray-200 flex items-center justify-center text-emerald-600 text-xl">
            <i class="fa-solid fa-circle-user"></i>
        </div>
        <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-black text-slate-800">บัญชีของฉัน</h2>
            <p class="text-slate-500 text-sm font-medium">จัดการโปรไฟล์ ความปลอดภัย และค่าตั้งของคุณเอง</p>
        </div>
    </div>

    <!-- Quick links (always visible) -->
    <div class="acc-card mb-6">
        <div class="acc-card-head"><h3><i class="fa-solid fa-bolt mr-2"></i>Quick Links</h3></div>
        <div class="acc-card-body">
            <div class="acc-ql-grid">
                <?php foreach ($_acc_quick_links as $ql): [$qid, $qlabel, $qicon, $qcolor] = $ql; ?>
                    <a href="javascript:void(0)" class="acc-ql"
                       onclick="switchSection('<?= htmlspecialchars($qid, ENT_QUOTES) ?>', document.querySelector('[data-section=<?= htmlspecialchars($qid, ENT_QUOTES) ?>]'))">
                        <div class="acc-ql-icon" style="background:<?= htmlspecialchars($qcolor, ENT_QUOTES) ?>"><i class="fa-solid <?= htmlspecialchars($qicon, ENT_QUOTES) ?>"></i></div>
                        <span class="acc-ql-label"><?= htmlspecialchars($qlabel) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="bg-white border border-gray-200 rounded-2xl p-1.5 flex gap-1 mb-6 shadow-sm">
        <button type="button" class="acc-tab active" data-tab="profile">
            <i class="fa-solid fa-id-card"></i>โปรไฟล์
        </button>
        <button type="button" class="acc-tab" data-tab="security">
            <i class="fa-solid fa-shield-halved"></i>ความปลอดภัย
        </button>
        <button type="button" class="acc-tab" data-tab="prefs">
            <i class="fa-solid fa-sliders"></i>ค่าตั้ง
        </button>
        <button type="button" class="acc-tab" data-tab="activity">
            <i class="fa-solid fa-clock-rotate-left"></i>กิจกรรมล่าสุด
        </button>
    </div>

    <!-- ── Pane: Profile ── -->
    <div class="acc-pane space-y-6" data-pane="profile">
        <div class="acc-card">
            <div class="acc-card-head"><h3>โปรไฟล์ &amp; รูปภาพ</h3></div>
            <div class="acc-card-body">

                <div class="flex flex-col sm:flex-row gap-6 items-start">
                    <!-- Avatar -->
                    <div class="flex-shrink-0 flex flex-col items-center gap-3">
                        <div id="acc-avatar-wrap" class="w-28 h-28 rounded-2xl overflow-hidden bg-gradient-to-br from-emerald-100 to-teal-100 border-2 border-white shadow-md flex items-center justify-center text-3xl font-black text-emerald-700">
                            <?php if ($_acc_avatar): ?>
                                <img id="acc-avatar-img" src="../<?= htmlspecialchars($_acc_avatar, ENT_QUOTES) ?>?v=<?= time() ?>" alt="avatar" style="width:100%;height:100%;object-fit:cover">
                            <?php else: ?>
                                <span id="acc-avatar-initial"><?= htmlspecialchars($_acc_initial) ?></span>
                            <?php endif; ?>
                        </div>
                        <label class="acc-btn-ghost" style="cursor:pointer">
                            <i class="fa-solid fa-camera"></i>เปลี่ยนรูป
                            <input type="file" id="acc-avatar-file" accept="image/jpeg,image/png,image/webp" style="display:none">
                        </label>
                        <p class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">JPG/PNG/WEBP · ≤ 2MB</p>
                    </div>

                    <!-- Form -->
                    <form id="acc-profile-form" class="flex-1 grid grid-cols-1 md:grid-cols-2 gap-4 w-full">
                        <div class="md:col-span-2">
                            <label class="acc-label">ชื่อ-นามสกุล <span class="text-rose-500">*</span></label>
                            <input class="acc-input" name="full_name" required maxlength="150"
                                   value="<?= htmlspecialchars($_acc_admin['full_name'] ?? '', ENT_QUOTES) ?>">
                        </div>
                        <div>
                            <label class="acc-label">ชื่อผู้ใช้</label>
                            <input class="acc-input" value="<?= htmlspecialchars($_acc_admin['username'] ?? '', ENT_QUOTES) ?>" disabled style="background:#f1f5f9;color:#94a3b8">
                            <p class="text-[10px] text-slate-400 mt-1.5 font-medium">แก้ไขโดย Superadmin เท่านั้น</p>
                        </div>
                        <div>
                            <label class="acc-label">บทบาท</label>
                            <input class="acc-input" value="<?= htmlspecialchars($_acc_admin['role'] ?? '', ENT_QUOTES) ?>" disabled style="background:#f1f5f9;color:#94a3b8">
                        </div>
                        <div>
                            <label class="acc-label">อีเมล <span class="text-rose-500">*</span></label>
                            <input class="acc-input" name="email" type="email" required maxlength="150"
                                   value="<?= htmlspecialchars($_acc_admin['email'] ?? '', ENT_QUOTES) ?>">
                        </div>
                        <div>
                            <label class="acc-label">เบอร์โทร</label>
                            <input class="acc-input" name="phone" maxlength="30" placeholder="08X-XXX-XXXX"
                                   value="<?= htmlspecialchars($_acc_admin['phone'] ?? '', ENT_QUOTES) ?>">
                        </div>
                        <div class="md:col-span-2 pt-2 flex justify-end">
                            <button type="submit" class="acc-btn-primary"><i class="fa-solid fa-save"></i>บันทึกโปรไฟล์</button>
                        </div>
                    </form>
                </div>

            </div>
        </div>
    </div>

    <!-- ── Pane: Security ── -->
    <div class="acc-pane space-y-6" data-pane="security" hidden>
        <div class="acc-card">
            <div class="acc-card-head"><h3>เปลี่ยนรหัสผ่าน</h3></div>
            <div class="acc-card-body max-w-xl">
                <form id="acc-pw-form" class="space-y-4">
                    <div>
                        <label class="acc-label">รหัสผ่านปัจจุบัน <span class="text-rose-500">*</span></label>
                        <input class="acc-input" type="password" name="current_password" required autocomplete="current-password">
                    </div>
                    <div>
                        <label class="acc-label">รหัสผ่านใหม่ <span class="text-rose-500">*</span></label>
                        <input class="acc-input" type="password" name="new_password" required minlength="8" autocomplete="new-password">
                        <p class="text-[11px] text-slate-400 mt-1.5 font-medium">อย่างน้อย 8 ตัวอักษร</p>
                    </div>
                    <div>
                        <label class="acc-label">ยืนยันรหัสผ่านใหม่ <span class="text-rose-500">*</span></label>
                        <input class="acc-input" type="password" name="confirm_password" required minlength="8" autocomplete="new-password">
                    </div>
                    <div class="pt-2">
                        <button type="submit" class="acc-btn-primary"><i class="fa-solid fa-key"></i>เปลี่ยนรหัสผ่าน</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ── Pane: Preferences (Theme + Notifications) ── -->
    <div class="acc-pane space-y-6" data-pane="prefs" hidden>
        <form id="acc-prefs-form">
            <div class="acc-card mb-6">
                <div class="acc-card-head"><h3>ธีม</h3></div>
                <div class="acc-card-body">
                    <p class="text-sm text-slate-500 mb-4 font-medium">เลือกธีมที่ใช้แสดงผลในระบบ</p>
                    <div class="acc-theme-grid">
                        <?php
                        $_acc_themes = [
                            ['light', 'สว่าง', 'fa-sun',     'acc-theme-light'],
                            ['dark',  'มืด',   'fa-moon',    'acc-theme-dark'],
                            ['auto',  'อัตโนมัติ', 'fa-circle-half-stroke', 'acc-theme-auto'],
                        ];
                        foreach ($_acc_themes as $t):
                            [$tval, $tlabel, $ticon, $tclass] = $t;
                            $isOn = ($_acc_admin['theme_pref'] ?? 'light') === $tval;
                        ?>
                            <label class="acc-theme-card <?= $isOn ? 'is-active' : '' ?>" data-theme="<?= htmlspecialchars($tval, ENT_QUOTES) ?>">
                                <input type="radio" name="theme_pref" value="<?= htmlspecialchars($tval, ENT_QUOTES) ?>" <?= $isOn ? 'checked' : '' ?>>
                                <div class="acc-theme-preview <?= htmlspecialchars($tclass, ENT_QUOTES) ?>"><i class="fa-solid <?= htmlspecialchars($ticon, ENT_QUOTES) ?>"></i></div>
                                <div class="text-sm font-bold text-slate-700 text-center"><?= htmlspecialchars($tlabel) ?></div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="acc-card mb-6">
                <div class="acc-card-head"><h3>การแจ้งเตือน</h3></div>
                <div class="acc-card-body space-y-4">
                    <div class="flex items-start justify-between gap-4 py-2">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-bold text-slate-800">แจ้งเตือนทางอีเมล</p>
                            <p class="text-[11px] text-slate-500 font-medium mt-0.5">รับแจ้งเตือนเมื่อมีงานใหม่ มอบหมาย หรือสถานะเปลี่ยน</p>
                        </div>
                        <label class="acc-toggle">
                            <input type="checkbox" name="notif_email" <?= ((int)($_acc_admin['notif_email'] ?? 1) === 1) ? 'checked' : '' ?>>
                            <span class="acc-slider"></span>
                        </label>
                    </div>
                    <div class="flex items-start justify-between gap-4 py-2 border-t border-slate-100">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-bold text-slate-800">แจ้งเตือนภายในระบบ</p>
                            <p class="text-[11px] text-slate-500 font-medium mt-0.5">แสดงสัญลักษณ์เตือนบนแถบเครื่องมือเมื่อมีกิจกรรมใหม่</p>
                        </div>
                        <label class="acc-toggle">
                            <input type="checkbox" name="notif_inapp" <?= ((int)($_acc_admin['notif_inapp'] ?? 1) === 1) ? 'checked' : '' ?>>
                            <span class="acc-slider"></span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="acc-btn-primary"><i class="fa-solid fa-save"></i>บันทึกค่าตั้ง</button>
            </div>
        </form>
    </div>

    <!-- ── Pane: Activity ── -->
    <div class="acc-pane space-y-6" data-pane="activity" hidden>
        <div class="acc-card">
            <div class="acc-card-head" style="display:flex;align-items:center;justify-content:space-between">
                <h3>กิจกรรมล่าสุดของฉัน</h3>
                <span class="text-[11px] font-black text-slate-400 uppercase tracking-widest">รวม <?= number_format($_acc_log_total) ?> รายการ</span>
            </div>
            <div class="acc-card-body" style="padding:0">
                <?php if (empty($_acc_log_rows)): ?>
                    <div class="py-16 text-center">
                        <div class="w-14 h-14 mx-auto mb-3 rounded-2xl bg-slate-50 text-slate-300 flex items-center justify-center text-xl"><i class="fa-regular fa-folder-open"></i></div>
                        <p class="text-sm font-bold text-slate-500">ยังไม่มีบันทึกกิจกรรม</p>
                    </div>
                <?php else: foreach ($_acc_log_rows as $r): ?>
                    <div class="acc-act-row">
                        <div class="acc-act-icon"><i class="fa-solid fa-bolt-lightning"></i></div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-bold text-slate-800 truncate"><?= htmlspecialchars($r['action'] ?: '-') ?></p>
                            <p class="text-[11px] text-slate-500 font-medium mt-0.5 truncate"><?= htmlspecialchars($r['description'] ?? '') ?></p>
                        </div>
                        <span class="text-[10px] text-slate-400 font-bold uppercase tracking-wider whitespace-nowrap"><?= htmlspecialchars(date('d M Y H:i', strtotime($r['timestamp']))) ?></span>
                    </div>
                <?php endforeach; endif; ?>
            </div>
            <?php if (($_acc_log_pages ?? 1) > 1):
                $win    = 2;
                $start  = max(1, $_acc_log_page - $win);
                $end    = min($_acc_log_pages, $_acc_log_page + $win);
                $url    = function (int $p) { return '?section=account&ap=' . $p; };
            ?>
                <div class="px-6 py-4 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-3">
                    <p class="text-[11px] font-black text-slate-400 uppercase tracking-widest">หน้า <?= $_acc_log_page ?> / <?= $_acc_log_pages ?> · รวม <?= number_format($_acc_log_total) ?> รายการ</p>
                    <div class="flex flex-wrap items-center gap-1">
                        <a href="<?= $url(1) ?>" class="acc-btn-ghost" style="padding:6px 10px;<?= $_acc_log_page === 1 ? 'opacity:.4;pointer-events:none' : '' ?>">«</a>
                        <a href="<?= $url(max(1, $_acc_log_page-1)) ?>" class="acc-btn-ghost" style="padding:6px 10px;<?= $_acc_log_page === 1 ? 'opacity:.4;pointer-events:none' : '' ?>">‹</a>
                        <?php for ($p = $start; $p <= $end; $p++): ?>
                            <a href="<?= $url($p) ?>" class="acc-btn-ghost" style="padding:6px 12px;<?= $p === $_acc_log_page ? 'background:#2e9e63;color:#fff' : '' ?>"><?= $p ?></a>
                        <?php endfor; ?>
                        <a href="<?= $url(min($_acc_log_pages, $_acc_log_page+1)) ?>" class="acc-btn-ghost" style="padding:6px 10px;<?= $_acc_log_page === $_acc_log_pages ? 'opacity:.4;pointer-events:none' : '' ?>">›</a>
                        <a href="<?= $url($_acc_log_pages) ?>" class="acc-btn-ghost" style="padding:6px 10px;<?= $_acc_log_page === $_acc_log_pages ? 'opacity:.4;pointer-events:none' : '' ?>">»</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<script>
(function () {
    const root = document.getElementById('section-account');
    if (!root) return;

    // ── Tab switcher ─────────────────────────────────────────────────────
    root.querySelectorAll('.acc-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            const target = btn.dataset.tab;
            root.querySelectorAll('.acc-tab').forEach(b => b.classList.toggle('active', b === btn));
            root.querySelectorAll('.acc-pane').forEach(p => p.hidden = (p.dataset.pane !== target));
        });
    });

    // ── Theme card click highlights radio ────────────────────────────────
    root.querySelectorAll('.acc-theme-card').forEach(card => {
        card.addEventListener('click', () => {
            root.querySelectorAll('.acc-theme-card').forEach(c => c.classList.toggle('is-active', c === card));
            const r = card.querySelector('input[type=radio]');
            if (r) r.checked = true;
        });
    });

    // ── Save profile ─────────────────────────────────────────────────────
    const profileForm = document.getElementById('acc-profile-form');
    profileForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(profileForm);
        fd.append('action', 'save_profile');
        fd.append('csrf_token', portal_CSRF);
        try {
            const res = await fetch('ajax_account.php', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.ok) {
                Swal.fire({ icon: 'success', title: 'สำเร็จ', text: json.message || 'บันทึกแล้ว', timer: 1800, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'ไม่สำเร็จ', text: json.message || 'เกิดข้อผิดพลาด' });
            }
        } catch (err) {
            Swal.fire({ icon: 'error', title: 'เครือข่ายผิดพลาด', text: 'กรุณาลองใหม่' });
        }
    });

    // ── Avatar upload ────────────────────────────────────────────────────
    const avatarInput = document.getElementById('acc-avatar-file');
    avatarInput?.addEventListener('change', async () => {
        if (!avatarInput.files || !avatarInput.files[0]) return;
        const file = avatarInput.files[0];
        if (file.size > 2 * 1024 * 1024) {
            Swal.fire({ icon: 'error', title: 'ไฟล์ใหญ่เกินไป', text: 'ขนาดไฟล์ต้องไม่เกิน 2MB' });
            avatarInput.value = '';
            return;
        }
        const fd = new FormData();
        fd.append('action', 'save_avatar');
        fd.append('csrf_token', portal_CSRF);
        fd.append('avatar', file);
        try {
            const res = await fetch('ajax_account.php', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.ok && json.avatar_path) {
                const wrap = document.getElementById('acc-avatar-wrap');
                wrap.innerHTML = '<img id="acc-avatar-img" src="../' + json.avatar_path + '?v=' + Date.now() + '" alt="avatar" style="width:100%;height:100%;object-fit:cover">';
                Swal.fire({ icon: 'success', title: 'อัปเดตรูปแล้ว', timer: 1500, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'อัปโหลดไม่สำเร็จ', text: json.message || 'เกิดข้อผิดพลาด' });
            }
        } catch (err) {
            Swal.fire({ icon: 'error', title: 'เครือข่ายผิดพลาด', text: 'กรุณาลองใหม่' });
        } finally {
            avatarInput.value = '';
        }
    });

    // ── Change password ──────────────────────────────────────────────────
    const pwForm = document.getElementById('acc-pw-form');
    pwForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(pwForm);
        fd.append('action', 'change_password');
        fd.append('csrf_token', portal_CSRF);
        try {
            const res = await fetch('ajax_account.php', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.ok) {
                pwForm.reset();
                Swal.fire({ icon: 'success', title: 'สำเร็จ', text: json.message, timer: 1800, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'ไม่สำเร็จ', text: json.message });
            }
        } catch (err) {
            Swal.fire({ icon: 'error', title: 'เครือข่ายผิดพลาด', text: 'กรุณาลองใหม่' });
        }
    });

    // ── Save prefs (theme + notif) ───────────────────────────────────────
    const prefsForm = document.getElementById('acc-prefs-form');
    prefsForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(prefsForm);
        fd.append('action', 'save_prefs');
        fd.append('csrf_token', portal_CSRF);
        try {
            const res = await fetch('ajax_account.php', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.ok) {
                Swal.fire({ icon: 'success', title: 'บันทึกแล้ว', timer: 1500, showConfirmButton: false });
                if (json.admin && json.admin.theme_pref) {
                    document.documentElement.setAttribute('data-theme', json.admin.theme_pref);
                }
            } else {
                Swal.fire({ icon: 'error', title: 'ไม่สำเร็จ', text: json.message });
            }
        } catch (err) {
            Swal.fire({ icon: 'error', title: 'เครือข่ายผิดพลาด', text: 'กรุณาลองใหม่' });
        }
    });
})();
</script>
