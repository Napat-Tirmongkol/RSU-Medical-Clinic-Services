<?php
/**
 * portal/identity.php
 * Section page — auto-generated from monolithic index.php refactor.
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/_portal_data.php';
require_once __DIR__ . '/_layout.php';

layout_start(['section' => 'identity', 'title' => 'Identity & Governance']);
?>
            <div id="section-identity" class="portal-section"
                style="width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <?php if (!($isSuper || !empty($_SESSION['access_identity']))): ?>
                    <div style="padding:100px;text-align:center;font-weight:900;color:#dc2626"><i class="fa-solid fa-shield-slash mb-4" style="font-size:4rem;display:block"></i> ACCESS DENIED<br><span style="font-size:14px;color:#94a3b8;font-weight:600">ต้องมีสิทธิ์ access_identity</span></div>
                <?php else: ?>
                <div class="px-5 md:px-8 py-8">

                    <?php if ($idSaved): ?>
                        <div id="id-toast"
                            style="display:flex;align-items:center;gap:10px;background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:14px;padding:12px 18px;margin-bottom:20px;font-size:13px;font-weight:700;color:#15803d">
                            <i class="fa-solid fa-circle-check"></i> บันทึกข้อมูลสำเร็จ
                        </div>
                        <script>
                        // Swal toast แจ้งเตือนสำเร็จ (เด่นกว่า inline div + auto-dismiss)
                        (function(){
                            if (typeof Swal === 'undefined') return;
                            Swal.fire({
                                toast: true, position: 'top-end',
                                icon: 'success',
                                title: 'บันทึกข้อมูลสำเร็จ',
                                showConfirmButton: false,
                                timer: 3000, timerProgressBar: true,
                            });
                            // ล้าง ?saved=1 จาก URL bar กัน refresh แล้ว toast ขึ้นซ้ำ
                            if (window.history && history.replaceState) {
                                try {
                                    const u = new URL(window.location.href);
                                    u.searchParams.delete('saved');
                                    history.replaceState({}, '', u.toString());
                                } catch (e) {}
                            }
                        })();
                        </script>
                    <?php endif; ?>
                    <?php if ($idError): ?>
                        <div
                            style="display:flex;align-items:center;gap:10px;background:#fff1f2;border:1.5px solid #fecaca;border-radius:14px;padding:12px 18px;margin-bottom:20px;font-size:13px;font-weight:700;color:#dc2626">
                            <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($idError) ?>
                        </div>
                    <?php endif; ?>

                    <!-- Header row -->
                    <div
                        style="display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:24px">
                        <div>
                            <div class="sec-title" style="margin-bottom:2px">Identity &amp; Governance</div>
                            <p style="font-size:13px;color:#64748b">ศูนย์กลางจัดการผู้ใช้งาน สิทธิ์การเข้าถึง
                                และความปลอดภัยของระบบ</p>
                        </div>
                        <div style="display:flex;gap:10px;align-items:center">
                            <?php if ($adminRole === 'superadmin'): ?>
                                <button id="id-btn-add-admin" onclick="openAddAdminModal()"
                                    style="display:none;background:#2e9e63;color:#fff;padding:8px 16px;border-radius:11px;font-size:12px;font-weight:700;border:none;cursor:pointer;box-shadow:0 4px 12px rgba(46,158,99,.25)">
                                    <i class="fa-solid fa-user-plus mr-1"></i> เพิ่ม Admin
                                </button>
                                <button id="id-btn-add-staff" onclick="openAddStaffModal()"
                                    style="display:none;background:#2563eb;color:#fff;padding:8px 16px;border-radius:11px;font-size:12px;font-weight:700;border:none;cursor:pointer;box-shadow:0 4px 12px rgba(37,99,235,.25)">
                                    <i class="fa-solid fa-id-badge mr-1"></i> เพิ่ม Staff
                                </button>
                            <?php endif; ?>
                            <div id="id-search-wrap" style="position:relative">
                                <i class="fa-solid fa-magnifying-glass"
                                    style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:11px;pointer-events:none"></i>
                                <input id="id-search-input" type="text" placeholder="ค้นหาข้อมูล..."
                                    style="padding:8px 12px 8px 30px;border:1.5px solid #d0ead9;border-radius:12px;font-size:12px;font-family:inherit;outline:none;width:200px;transition:border-color .2s"
                                    oninput="idUniversalFilter(this.value)">
                            </div>
                        </div>
                    </div>

                    <!-- Tabs -->
                    <div
                        style="display:flex;gap:6px;margin-bottom:20px;padding-bottom:2px;border-bottom:1px solid #f1f5f9">
                        <button class="id-tab active" data-tab="users" onclick="switchIdTab('users',this)">System Users
                            (<?= number_format($totalIdUsers) ?>)</button>
                        <?php if ($adminRole === 'superadmin'): ?>
                            <button class="id-tab" data-tab="admins" onclick="switchIdTab('admins',this)">System Admins
                                (<?= count($allAdmins) ?>)</button>
                            <button class="id-tab" data-tab="staff" onclick="switchIdTab('staff',this)">Staff
                                (<?= count($allStaff) ?>)</button>
                            <button class="id-tab" data-tab="positions" onclick="switchIdTab('positions',this)">ตำแหน่งงาน
                                (<?= count($allPositions ?? []) ?>)</button>
                            <button class="id-tab" data-tab="departments" onclick="switchIdTab('departments',this)">ฝ่าย/หน่วยงาน
                                (<?= count($allDepartments ?? []) ?>)</button>
                        <?php endif; ?>
                    </div>

                    <!-- PANEL: Master Users -->
                    <div id="id-panel-users" class="id-panel active">
                        <?php
                        // Stats are pre-calculated in identity_queries.php via SQL
                        $totalUsersCalc = $totalIdUsers;
                        $pctStudent = $totalUsersCalc > 0 ? round(($statsUserType['student'] / $totalUsersCalc) * 100) : 0;
                        $pctStaff = $totalUsersCalc > 0 ? round(($statsUserType['staff'] / $totalUsersCalc) * 100) : 0;
                        $pctOther = $totalUsersCalc > 0 ? (100 - $pctStudent - $pctStaff) : 0;
                        $lineMigrationCoverage = max(0, min(100, (float)($lineMigration['coverage'] ?? 0)));
                        ?>
                        
                        <!-- Statistics Bar -->
                        <div style="background:#fff;border-radius:20px;padding:20px;margin-bottom:20px;border:1.5px solid #e2e8f0;box-shadow:0 4px 15px rgba(0,0,0,0.02)">
                            <div style="font-size:12px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:15px;display:flex;align-items:center;gap:6px;">
                                <i class="fa-solid fa-chart-pie" style="color:#2e9e63"></i> สัดส่วนประเภทผู้ใช้งาน
                            </div>
                            
                            <!-- Visual Bar -->
                            <div style="width:100%;height:14px;border-radius:99px;background:#f1f5f9;display:flex;overflow:hidden;margin-bottom:12px;box-shadow:inset 0 2px 4px rgba(0,0,0,0.04)">
                                <?php if($totalUsersCalc > 0): ?>
                                    <div style="width:<?= $pctStudent ?>%;background:linear-gradient(90deg, #3b82f6, #60a5fa);transition:width 1s;border-right:2px solid #fff" title="นักศึกษา: <?= number_format($statsUserType['student']) ?> คน"></div>
                                    <div style="width:<?= $pctStaff ?>%;background:linear-gradient(90deg, #f59e0b, #fbbf24);transition:width 1s;border-right:2px solid #fff" title="บุคลากร: <?= number_format($statsUserType['staff']) ?> คน"></div>
                                    <div style="width:<?= $pctOther ?>%;background:linear-gradient(90deg, #8b5cf6, #a78bfa);transition:width 1s" title="บุคคลทั่วไป/อื่นๆ: <?= number_format($statsUserType['other']) ?> คน"></div>
                                <?php else: ?>
                                    <div style="width:100%;background:#e2e8f0;"></div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Legend -->
                            <div style="display:flex;flex-wrap:wrap;gap:20px;font-size:12px;font-weight:700">
                                <div style="display:flex;align-items:center;gap:8px">
                                    <div style="width:12px;height:12px;border-radius:4px;background:#3b82f6;box-shadow:0 2px 4px rgba(59,130,246,0.3)"></div>
                                    <span style="color:#334155">นักศึกษา <span style="opacity:0.6;font-size:11px">(<?= $pctStudent ?>%)</span></span>
                                </div>
                                <div style="display:flex;align-items:center;gap:8px">
                                    <div style="width:12px;height:12px;border-radius:4px;background:#f59e0b;box-shadow:0 2px 4px rgba(245,158,11,0.3)"></div>
                                    <span style="color:#334155">บุคลากร <span style="opacity:0.6;font-size:11px">(<?= $pctStaff ?>%)</span></span>
                                </div>
                                <div style="display:flex;align-items:center;gap:8px">
                                    <div style="width:12px;height:12px;border-radius:4px;background:#8b5cf6;box-shadow:0 2px 4px rgba(139,92,246,0.3)"></div>
                                    <span style="color:#334155">บุคคลทั่วไป/อื่นๆ <span style="opacity:0.6;font-size:11px">(<?= $pctOther ?>%)</span></span>
                                </div>
                            </div>
                        </div>

                        <!-- LINE Provider Migration -->
                        <div style="background:#fff;border-radius:20px;padding:20px;margin-bottom:20px;border:1.5px solid #dbeafe;box-shadow:0 4px 15px rgba(0,0,0,0.02)">
                            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:16px">
                                <div>
                                    <div style="font-size:12px;font-weight:900;color:#1e40af;text-transform:uppercase;letter-spacing:0.1em;display:flex;align-items:center;gap:7px;margin-bottom:5px">
                                        <i class="fa-brands fa-line" style="color:#06c755"></i> LINE Provider Migration
                                    </div>
                                    <div style="font-size:12px;font-weight:700;color:#64748b">Coverage <?= number_format($lineMigrationCoverage, 1) ?>%</div>
                                </div>
                                <div style="font-size:24px;font-weight:900;color:#1e293b;line-height:1"><?= number_format($lineMigrationCoverage, 1) ?>%</div>
                            </div>

                            <?php if (empty($lineMigration['has_new_column'])): ?>
                                <div style="display:flex;align-items:flex-start;gap:10px;background:#fffbeb;border:1.5px solid #fde68a;border-radius:12px;padding:12px 14px;margin-bottom:16px;color:#92400e;font-size:12px;font-weight:700;line-height:1.5">
                                    <i class="fa-solid fa-triangle-exclamation" style="margin-top:2px"></i>
                                    <span>ไม่พบคอลัมน์ <code style="font-family:ui-monospace,SFMono-Regular,Consolas,monospace;background:#fef3c7;padding:1px 5px;border-radius:5px">line_user_id_new</code> กรุณารัน migration ก่อน เพื่อเริ่มเก็บ UID จาก LINE Provider ใหม่</span>
                                </div>
                            <?php endif; ?>

                            <div style="width:100%;height:14px;border-radius:99px;background:#e2e8f0;overflow:hidden;margin-bottom:16px;box-shadow:inset 0 2px 4px rgba(0,0,0,0.04)">
                                <div style="width:<?= $lineMigrationCoverage ?>%;height:100%;background:linear-gradient(90deg,#06c755,#22c55e);transition:width 1s"></div>
                            </div>

                            <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px">
                                <div style="border:1.5px solid #e2e8f0;border-radius:14px;padding:12px;background:#f8fafc;min-width:0">
                                    <div style="font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.1em;margin-bottom:4px">เดิม</div>
                                    <div style="font-size:20px;font-weight:900;color:#1e293b;line-height:1.1"><?= number_format((int)($lineMigration['old_uid_count'] ?? 0)) ?></div>
                                </div>
                                <div style="border:1.5px solid #bbf7d0;border-radius:14px;padding:12px;background:#f0fdf4;min-width:0">
                                    <div style="font-size:10px;font-weight:900;color:#15803d;text-transform:uppercase;letter-spacing:.1em;margin-bottom:4px">ย้ายแล้ว</div>
                                    <div style="font-size:20px;font-weight:900;color:#166534;line-height:1.1"><?= number_format((int)($lineMigration['migrated_count'] ?? 0)) ?></div>
                                </div>
                                <div style="border:1.5px solid #fed7aa;border-radius:14px;padding:12px;background:#fff7ed;min-width:0">
                                    <div style="font-size:10px;font-weight:900;color:#c2410c;text-transform:uppercase;letter-spacing:.1em;margin-bottom:4px">คงค้าง</div>
                                    <div style="font-size:20px;font-weight:900;color:#9a3412;line-height:1.1"><?= number_format((int)($lineMigration['pending_count'] ?? 0)) ?></div>
                                </div>
                            </div>
                        </div>

                        <div style="background:#fff;border-radius:20px;border:1.5px solid #e2e8f0;overflow:hidden">
                            <div
                                style="padding:18px 24px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:8px">
                                <div
                                    style="width:4px;height:18px;background:linear-gradient(180deg,#6366f1,#a5b4fc);border-radius:99px;flex-shrink:0">
                                </div>
                                <span
                                    style="font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.14em;color:#374151">Master
                                    Records</span>
                                <span
                                    style="margin-left:auto;font-size:11px;font-weight:700;color:#94a3b8"><?= number_format($totalIdUsers) ?>
                                    รายการ</span>
                            </div>
                            <div style="overflow-x:auto" id="idTableWrap">
                                <table style="width:100%;border-collapse:collapse;font-size:13px" id="idUserTable">
                                    <thead>
                                        <tr style="background:#f8fafc;border-bottom:1px solid #f1f5f9">
                                            <th
                                                style="padding:12px 20px;text-align:left;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.14em">
                                                ผู้ใช้งาน</th>
                                            <th
                                                style="padding:12px 20px;text-align:left;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.14em">
                                                ติดต่อ</th>
                                            <th
                                                style="padding:12px 20px;text-align:left;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.14em">
                                                วันที่ลงทะเบียน</th>
                                            <th
                                                style="padding:12px 20px;text-align:right;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.14em">
                                                จัดการ</th>
                                        </tr>
                                    </thead>
                                    <tbody id="idUserTbody">
                                        <!-- Dynamically loaded via AJAX -->
                                        <tr>
                                            <td colspan="4" style="padding:40px;text-align:center;color:#94a3b8">
                                                <i class="fa-solid fa-spinner fa-spin mr-2"></i> กำลังโหลดข้อมูล...
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <!-- Pagination bar -->
                            <div
                                style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:14px 20px;border-top:1px solid #f1f5f9">
                                <div style="display:flex;align-items:center;gap:6px">
                                    <span style="font-size:11px;font-weight:700;color:#94a3b8">แสดง</span>
                                    <?php foreach ([25, 50, 100] as $sz): ?>
                                        <button class="id-ps-btn" data-size="<?= $sz ?>" onclick="idSetPageSize(<?= $sz ?>)"
                                            style="padding:5px 13px;border-radius:8px;border:1.5px solid #e2e8f0;background:<?= $sz === 25 ? '#2e9e63' : '#f8fafc' ?>;color:<?= $sz === 25 ? '#fff' : '#374151' ?>;font-size:12px;font-weight:700;cursor:pointer;transition:all .15s">
                                            <?= $sz ?>
                                        </button>
                                    <?php endforeach; ?>
                                    <span style="font-size:11px;font-weight:700;color:#94a3b8">รายการ</span>
                                </div>
                                <div style="display:flex;align-items:center;gap:8px">
                                    <span id="id-page-info"
                                        style="font-size:12px;font-weight:700;color:#64748b;min-width:120px;text-align:center"></span>
                                    <button id="id-page-prev" onclick="idPrevPage()"
                                        style="width:32px;height:32px;border-radius:9px;border:1.5px solid #e2e8f0;background:#f8fafc;color:#374151;cursor:pointer;font-size:15px;font-weight:700;transition:all .15s;line-height:1"
                                        disabled>‹</button>
                                    <button id="id-page-next" onclick="idNextPage()"
                                        style="width:32px;height:32px;border-radius:9px;border:1.5px solid #e2e8f0;background:#f8fafc;color:#374151;cursor:pointer;font-size:15px;font-weight:700;transition:all .15s;line-height:1">›</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- PANEL: System Admins -->
                    <div id="id-panel-admins" class="id-panel">
                        <div style="background:#fff;border-radius:20px;border:1.5px solid #e2e8f0;overflow:hidden">
                            <div
                                style="padding:18px 24px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:8px">
                                <div style="width:4px;height:18px;background:#2e9e63;border-radius:99px;flex-shrink:0">
                                </div>
                                <span
                                    style="font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.14em;color:#374151">Admin
                                    Accounts</span>
                            </div>
                            <div style="overflow-x:auto">
                                <table style="width:100%;border-collapse:collapse;font-size:13px" id="idAdminTable">
                                    <thead>
                                        <tr style="background:#f8fafc;border-bottom:1.5px solid #e2e8f0">
                                            <th style="padding:16px 20px;text-align:left;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em"><i class="fa-solid fa-user-shield mr-2"></i>Admin Detail</th>
                                            <th style="padding:16px 20px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:150px"><i class="fa-solid fa-key mr-2"></i>Access Level</th>
                                            <th style="padding:16px 20px;text-align:right;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="idAdminTbody">
                                        <?php foreach ($allAdmins as $adm): 
                                            $role = $adm['role'] ?? 'admin';
                                            $roleIcon = '<i class="fa-solid fa-user-shield"></i>';
                                            $roleLabel = 'Standard Admin';
                                            $roleColor = '#3b82f6';
                                            $roleBg = '#eff6ff';
                                            $roleBorder = '#bfdbfe';

                                            if ($role === 'superadmin') {
                                                $roleIcon = '<i class="fa-solid fa-crown"></i>';
                                                $roleLabel = 'Super Administrator';
                                                $roleColor = '#7c3aed';
                                                $roleBg = '#f5f3ff';
                                                $roleBorder = '#ddd6fe';
                                            } elseif ($role === 'editor') {
                                                $roleIcon = '<i class="fa-solid fa-pen-to-square"></i>';
                                                $roleLabel = 'Content Editor';
                                                $roleColor = '#e11d48';
                                                $roleBg = '#fff1f2';
                                                $roleBorder = '#fecdd3';
                                            }
                                        ?>
                                            <tr style="border-bottom:1px solid #f1f5f9" class="id-admin-row hover:bg-slate-50/50 transition-colors">
                                                <td style="padding:16px 20px">
                                                    <div style="display:flex;align-items:center;gap:12px">
                                                        <div style="width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg, <?= $roleColor ?>, <?= $roleColor ?>dd);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:14px;box-shadow:0 4px 10px -2px <?= $roleColor ?>66">
                                                            <?= mb_substr($adm['full_name'], 0, 1) ?>
                                                        </div>
                                                        <div>
                                                            <div style="font-weight:800;color:#1e293b;font-size:13.5px"><?= htmlspecialchars($adm['full_name']) ?></div>
                                                            <div style="font-size:11px;color:#64748b;font-weight:600">@<?= htmlspecialchars($adm['username']) ?> · <?= htmlspecialchars($adm['email']) ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td style="padding:16px 20px;text-align:center">
                                                    <div style="display:inline-flex;align-items:center;gap:8px;padding:4px 12px;border-radius:8px;background:<?= $roleBg ?>;color:<?= $roleColor ?>;border:1.5px solid <?= $roleBorder ?>;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:0.05em">
                                                        <?= $roleIcon ?> <?= $roleLabel ?>
                                                    </div>
                                                </td>
                                                <td style="padding:16px 20px;text-align:right">
                                                    <div style="display:flex;gap:8px;justify-content:flex-end">
                                                        <button onclick='openEditAdminModal(<?= json_encode($adm) ?>)' 
                                                            class="id-action-btn"
                                                            style="width:34px;height:34px;border-radius:10px;border:1.5px solid #e2e8f0;background:#fff;color:#64748b;cursor:pointer;transition:all 0.2s"><i class="fa-solid fa-pen-to-square"></i></button>
                                                        <?php if ($adm['id'] != $_SESSION['admin_id']): ?>
                                                            <form method="POST" style="display:inline" onsubmit="return confirm('ยืนยันการลบ Admin ท่านนี้?')">
                                                                <input type="hidden" name="action" value="delete_admin">
                                                                <input type="hidden" name="admin_id" value="<?= $adm['id'] ?>">
                                                                <?php csrf_field(); ?>
                                                                <button type="submit" 
                                                                    class="id-action-btn-danger"
                                                                    style="width:34px;height:34px;border-radius:10px;border:1.5px solid #fee2e2;background:#fff;color:#ef4444;cursor:pointer;transition:all 0.2s"><i class="fa-solid fa-trash-can"></i></button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- PANEL: Staff Matrix -->
                    <div id="id-panel-staff" class="id-panel">
                        <div style="background:#fff;border-radius:20px;border:1.5px solid #e2e8f0;overflow:hidden">
                            <div style="padding:18px 24px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                                <div style="width:4px;height:18px;background:#2563eb;border-radius:99px;flex-shrink:0"></div>
                                <span style="font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.14em;color:#374151">Staff Permission Matrix</span>
                                <button type="button" onclick="govLineBulkFromOrg()" title="เชื่อม LINE ให้ staff ที่ยังไม่ผูก โดยอ้างอิงผังองค์กร (จับคู่ user ที่ผูก LINE ไว้แล้ว) — ไม่ทับของเดิม"
                                        style="margin-left:auto;display:inline-flex;align-items:center;gap:7px;padding:8px 14px;border-radius:10px;border:1.5px solid #86efac;background:#f0fdf4;color:#15803d;font-weight:800;font-size:12px;cursor:pointer;white-space:nowrap">
                                    <i class="fa-brands fa-line" style="color:#06c755"></i> เชื่อม LINE ทั้งหมดจากผังองค์กร
                                </button>
                            </div>
                            <!-- Matrix Legend -->
                            <div style="padding:12px 24px;background:#f8fafc;border-bottom:1px solid #f1f5f9;display:flex;flex-wrap:wrap;gap:20px;align-items:center">
                                <div style="font-size:10px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em">Matrix Legend:</div>
                                <div style="display:flex;align-items:center;gap:6px;font-size:11px;font-weight:700;color:#475569">
                                    <span style="color:#ea580c"><i class="fa-solid fa-shield-halved"></i></span> Admin
                                </div>
                                <div style="display:flex;align-items:center;gap:6px;font-size:11px;font-weight:700;color:#475569">
                                    <span style="color:#7c3aed"><i class="fa-solid fa-crown"></i></span> Super
                                </div>
                                <div style="display:flex;align-items:center;gap:6px;font-size:11px;font-weight:700;color:#475569">
                                    <span style="color:#2563eb"><i class="fa-solid fa-pen-to-square"></i></span> Editor
                                </div>
                                <div style="display:flex;align-items:center;gap:6px;font-size:11px;font-weight:700;color:#475569">
                                    <span style="color:#16a34a"><i class="fa-solid fa-user"></i></span> Standard
                                </div>
                                <div style="display:flex;align-items:center;gap:6px;font-size:11px;font-weight:700;color:#94a3b8">
                                    <i class="fa-solid fa-circle-xmark"></i> No Access
                                </div>
                                <div style="display:flex;align-items:center;gap:6px;font-size:11px;font-weight:700;color:#475569">
                                    <i class="fa-solid fa-circle-check text-emerald-500"></i> Active Flag
                                </div>
                                <div style="display:flex;align-items:center;gap:6px;font-size:11px;font-weight:700;color:#475569">
                                    <i class="fa-solid fa-circle-minus text-slate-200"></i> Disabled
                                </div>
                                <div style="display:flex;align-items:center;gap:6px;font-size:11px;font-weight:700;color:#475569">
                                    <span style="color:#06c755"><i class="fa-brands fa-line"></i></span> LINE Linked
                                </div>
                            </div>
                            <div style="overflow-x:auto">
                                <table style="width:100%;border-collapse:collapse;font-size:13px" id="idStaffTable">
                                    <thead>
                                        <tr style="background:#f8fafc;border-bottom:1.5px solid #e2e8f0">
                                            <th style="padding:16px 20px;text-align:left;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em"><i class="fa-solid fa-user-gear mr-2"></i>Staff Details</th>
                                            <th style="padding:16px 20px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:120px"><i class="fa-solid fa-box-archive mr-2"></i>e-Borrow</th>
                                            <th style="padding:16px 20px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:120px"><i class="fa-solid fa-bullhorn mr-2"></i>e-Campaign</th>
                                            <th style="padding:16px 20px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:80px" title="Insurance Sync"><i class="fa-solid fa-shield-heart"></i></th>
                                            <th style="padding:16px 20px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:80px" title="System Logs"><i class="fa-solid fa-list-ul"></i></th>
                                            <th style="padding:16px 20px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:80px" title="System Settings"><i class="fa-solid fa-sliders"></i></th>
                                            <th style="padding:16px 20px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:80px" title="AI Suite"><i class="fa-solid fa-wand-magic-sparkles"></i></th>
                                            <th style="padding:16px 20px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:80px" title="Consumables"><i class="fa-solid fa-syringe"></i></th>
                                            <th style="padding:16px 20px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:80px" title="Asset"><i class="fa-solid fa-warehouse"></i></th>
                                            <th style="padding:16px 20px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:80px" title="Scholarship"><i class="fa-solid fa-graduation-cap"></i></th>
                                            <th style="padding:16px 20px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:80px" title="Dashboard Editor"><i class="fa-solid fa-chart-pie"></i></th>
                                            <th style="padding:16px 20px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:80px" title="เชื่อมบัญชี LINE"><i class="fa-brands fa-line" style="color:#06c755"></i></th>
                                            <th style="padding:16px 20px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:100px">Status</th>
                                            <th style="padding:16px 20px;text-align:right;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="idStaffTbody">
                                        <?php foreach ($allStaff as $st):
                                            $isActive = ($st['account_status'] ?? 'active') === 'active';
                                            
                                            // e-Borrow Matrix Mapping
                                            $ebAccess = (int)($st['access_eborrow'] ?? 1);
                                            $ebRole = $st['role'] ?? 'none';
                                            $ebIcon = '<i class="fa-solid fa-circle-xmark" style="color:#cbd5e1;font-size:14px"></i>';
                                            if ($ebAccess) {
                                                if ($ebRole === 'admin') {
                                                    $ebIcon = '<div style="background:#fff7ed;color:#ea580c;border:1px solid #fed7aa;width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;margin:0 auto" title="e-Borrow: Administrator"><i class="fa-solid fa-shield-halved"></i></div>';
                                                } elseif ($ebRole === 'librarian' || $ebRole === 'technician' || $ebRole === 'supervisor') {
                                                    $ebIcon = '<div style="background:#f0f9ff;color:#0369a1;border:1px solid #bae6fd;width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;margin:0 auto" title="e-Borrow: Staff/Librarian"><i class="fa-solid fa-pen-to-square"></i></div>';
                                                } elseif ($ebRole === 'employee') {
                                                    $ebIcon = '<div style="background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;margin:0 auto" title="e-Borrow: Standard User"><i class="fa-solid fa-user"></i></div>';
                                                }
                                            }

                                            // e-Campaign Matrix Mapping
                                            $ecAccess = (int)($st['access_ecampaign'] ?? 0);
                                            $ecRole = $st['ecampaign_role'] ?? 'none';
                                            $ecIcon = '<i class="fa-solid fa-circle-xmark" style="color:#cbd5e1;font-size:14px"></i>';
                                            if ($ecAccess) {
                                                if ($ecRole === 'admin' || $ecRole === 'superadmin') {
                                                    $ecIcon = '<div style="background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe;width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;margin:0 auto" title="e-Campaign: Administrator"><i class="fa-solid fa-crown"></i></div>';
                                                } else {
                                                    $ecIcon = '<div style="background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;margin:0 auto" title="e-Campaign: Editor"><i class="fa-solid fa-file-signature"></i></div>';
                                                }
                                            }

                                            // Portal Extensions
                                            $insAccess = (int)($st['access_insurance'] ?? 0);
                                            $logsAccess = (int)($st['access_system_logs'] ?? 0);
                                            $settAccess = (int)($st['access_site_settings'] ?? 0);
                                            
                                            $insIcon = $insAccess ? '<i class="fa-solid fa-circle-check text-emerald-500"></i>' : '<i class="fa-solid fa-circle-minus text-slate-200"></i>';
                                            $logsIcon = $logsAccess ? '<i class="fa-solid fa-circle-check text-emerald-500"></i>' : '<i class="fa-solid fa-circle-minus text-slate-200"></i>';
                                            $settIcon = $settAccess ? '<i class="fa-solid fa-circle-check text-emerald-500"></i>' : '<i class="fa-solid fa-circle-minus text-slate-200"></i>';

                                            // New extension flags
                                            $aiAccess = (int)($st['access_ai'] ?? 0);
                                            $consAccess = (int)($st['access_consumables'] ?? 0);
                                            $assetAccess = (int)($st['access_asset'] ?? 0);
                                            $financeAccess = (int)($st['access_finance'] ?? 0);
                                            $scholarAccess = (int)($st['access_scholarship'] ?? 0);
                                            $dashAccess = (int)($st['access_dashboard_admin'] ?? 0);
                                            $aiIcon = $aiAccess ? '<i class="fa-solid fa-circle-check text-emerald-500"></i>' : '<i class="fa-solid fa-circle-minus text-slate-200"></i>';
                                            $consIcon = $consAccess ? '<i class="fa-solid fa-circle-check text-emerald-500"></i>' : '<i class="fa-solid fa-circle-minus text-slate-200"></i>';
                                            $assetIcon = $assetAccess ? '<i class="fa-solid fa-circle-check text-emerald-500"></i>' : '<i class="fa-solid fa-circle-minus text-slate-200"></i>';
                                            $scholarIcon = $scholarAccess ? '<i class="fa-solid fa-circle-check text-emerald-500"></i>' : '<i class="fa-solid fa-circle-minus text-slate-200"></i>';
                                            $dashIcon = $dashAccess ? '<i class="fa-solid fa-circle-check text-emerald-500"></i>' : '<i class="fa-solid fa-circle-minus text-slate-200"></i>';

                                            // LINE link status — เชื่อมเองผ่านหน้า Profile (LINE OAuth); identity ไม่ได้ตั้งค่าให้
                                            $lineLinked = trim((string)($st['linked_line_user_id'] ?? '')) !== '';
                                            $lineIcon = $lineLinked
                                                ? '<div style="background:#f0fdf4;color:#06c755;border:1px solid #bbf7d0;width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;margin:0 auto" title="เชื่อมบัญชี LINE แล้ว"><i class="fa-brands fa-line"></i></div>'
                                                : '<i class="fa-brands fa-line" style="color:#cbd5e1;font-size:16px" title="ยังไม่เชื่อมบัญชี LINE"></i>';
                                            ?>
                                            <tr style="border-bottom:1px solid #f1f5f9" class="id-staff-row hover:bg-slate-50/50 transition-colors">
                                                <td style="padding:16px 20px">
                                                    <div style="display:flex;align-items:center;gap:12px">
                                                        <div style="width:36px;height:36px;border-radius:10px;background:<?= $isActive ? 'linear-gradient(135deg,#3b82f6,#1d4ed8)' : '#f1f5f9' ?>;color:<?= $isActive ? '#fff' : '#94a3b8' ?>;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:14px">
                                                            <?= mb_substr($st['full_name'], 0, 1) ?>
                                                        </div>
                                                        <div>
                                                            <div style="font-weight:800;color:#1e293b;font-size:13.5px;display:flex;align-items:center;gap:6px">
                                                                <?= htmlspecialchars($st['full_name']) ?>
                                                                <?php
                                                                $_jt = trim((string)($st['job_title'] ?? ''));
                                                                $_org = trim((string)($st['org_position_title'] ?? ''));
                                                                $_label = $_jt !== '' ? $_jt : $_org;
                                                                if ($_label !== ''):
                                                                ?>
                                                                <span title="<?= $_jt !== '' ? 'ตำแหน่งงาน' : 'จากผังองค์กร' ?>"
                                                                      style="display:inline-block;padding:1px 7px;border-radius:99px;background:<?= $_jt !== '' ? '#ecfeff' : '#f1f5f9' ?>;color:<?= $_jt !== '' ? '#0891b2' : '#64748b' ?>;border:1px solid <?= $_jt !== '' ? '#a5f3fc' : '#e2e8f0' ?>;font-size:10px;font-weight:800"><?= htmlspecialchars($_label) ?></span>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div style="font-size:11px;color:#64748b;font-weight:600">@<?= htmlspecialchars($st['username']) ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td style="padding:16px 20px;text-align:center"><?= $ebIcon ?></td>
                                                <td style="padding:16px 20px;text-align:center"><?= $ecIcon ?></td>
                                                <td style="padding:16px 20px;text-align:center"><?= $insIcon ?></td>
                                                <td style="padding:16px 20px;text-align:center"><?= $logsIcon ?></td>
                                                <td style="padding:16px 20px;text-align:center"><?= $settIcon ?></td>
                                                <td style="padding:16px 20px;text-align:center"><?= $aiIcon ?></td>
                                                <td style="padding:16px 20px;text-align:center"><?= $consIcon ?></td>
                                                <td style="padding:16px 20px;text-align:center"><?= $assetIcon ?></td>
                                                <td style="padding:16px 20px;text-align:center"><?= $scholarIcon ?></td>
                                                <td style="padding:16px 20px;text-align:center"><?= $dashIcon ?></td>
                                                <td style="padding:16px 20px;text-align:center"><?= $lineIcon ?></td>
                                                <td style="padding:16px 20px;text-align:center">
                                                    <span style="font-size:10px;font-weight:900;padding:4px 10px;border-radius:99px;background:<?= $isActive ? '#f0fdf4;color:#16a34a;border:1px solid #bbf7d0' : '#fef2f2;color:#dc2626;border:1px solid #fecaca' ?>"><?= strtoupper($st['account_status']) ?></span>
                                                </td>
                                                <td style="padding:16px 20px;text-align:right">
                                                    <div style="display:flex;gap:8px;justify-content:flex-end">
                                                        <button onclick='openEditStaffModal(<?= json_encode($st) ?>)' class="id-action-btn" style="width:34px;height:34px;border-radius:10px;border:1.5px solid #e2e8f0;background:#fff;color:#64748b;cursor:pointer;transition:all 0.2s"><i class="fa-solid fa-pen-to-square"></i></button>
                                                        <form method="POST" style="display:inline" onsubmit="return confirm('ยืนยันการลบ Staff ท่านนี้?')">
                                                            <input type="hidden" name="action" value="delete_staff">
                                                            <input type="hidden" name="sf_id" value="<?= $st['id'] ?>">
                                                            <?php csrf_field(); ?>
                                                            <button type="submit" class="id-action-btn-danger" style="width:34px;height:34px;border-radius:10px;border:1.5px solid #fee2e2;background:#fff;color:#ef4444;cursor:pointer;transition:all 0.2s"><i class="fa-solid fa-trash-can"></i></button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                        </div>
                    </div>

                    <!-- PANEL: Positions (ตำแหน่งงาน) -->
                    <?php if ($adminRole === 'superadmin'): ?>
                    <div id="id-panel-positions" class="id-panel">
                        <div style="background:#fff;border-radius:18px;border:1.5px solid #e2e8f0;overflow:hidden">
                            <div style="padding:20px 24px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
                                <div>
                                    <div style="font-size:14px;font-weight:900;color:#1e293b;display:flex;align-items:center;gap:8px">
                                        <i class="fa-solid fa-user-tag" style="color:#7c3aed"></i>
                                        ตำแหน่งงาน (Position-based Access)
                                    </div>
                                    <p style="margin:4px 0 0;font-size:11px;color:#64748b;font-weight:600">
                                        สร้างตำแหน่งและกำหนด flag preset — staff ที่ผูกตำแหน่งจะได้รับ flag แบบ live link
                                    </p>
                                </div>
                                <button type="button" onclick="openAddPositionModal()" style="padding:10px 16px;border-radius:10px;border:none;background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;font-weight:900;font-size:12px;cursor:pointer;display:inline-flex;align-items:center;gap:8px;box-shadow:0 6px 14px -3px rgba(124,58,237,.35)">
                                    <i class="fa-solid fa-plus"></i> สร้างตำแหน่งใหม่
                                </button>
                            </div>

                            <?php if (empty($allPositions)): ?>
                                <div style="padding:60px 20px;text-align:center;color:#94a3b8">
                                    <i class="fa-solid fa-user-tag" style="font-size:38px;display:block;margin-bottom:12px;opacity:.4"></i>
                                    <p style="font-size:13px;font-weight:700;margin:0">ยังไม่มีตำแหน่งงานในระบบ</p>
                                    <p style="font-size:11px;color:#cbd5e1;margin:6px 0 0">คลิก "สร้างตำแหน่งใหม่" เพื่อเริ่มต้น</p>
                                </div>
                            <?php else: ?>
                                <div style="overflow-x:auto">
                                    <table style="width:100%;border-collapse:collapse;font-size:13px" id="idPositionTable">
                                        <thead>
                                            <tr style="background:#f8fafc;border-bottom:1.5px solid #e2e8f0">
                                                <th style="padding:14px 18px;text-align:left;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em">ตำแหน่ง</th>
                                                <th style="padding:14px 18px;text-align:left;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em">Flag ที่กำหนด</th>
                                                <th style="padding:14px 18px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:120px">ผูกแล้ว</th>
                                                <th style="padding:14px 18px;text-align:right;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:120px">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $flagLabelMap = [
                                                'access_eborrow'        => ['e-Borrow',         '#f97316'],
                                                'access_ecampaign'      => ['e-Campaign',       '#2563eb'],
                                                'access_insurance'      => ['Insurance',        '#10b981'],
                                                'access_registry'       => ['Registry',         '#06b6d4'],
                                                'access_system_logs'    => ['Logs',             '#64748b'],
                                                'access_site_settings'  => ['Settings',         '#7c3aed'],
                                                'access_edms'           => ['EDMS',             '#0ea5e9'],
                                                'access_edms_sla_admin' => ['EDMS-SLA',         '#a855f7'],
                                                'access_ai'             => ['AI Suite',         '#a855f7'],
                                                'access_consumables'    => ['Consumables',      '#f43f5e'],
                                                'access_asset'          => ['Asset',            '#f59e0b'],
                                                'access_finance'        => ['Finance',          '#059669'],
                                                'access_scholarship'    => ['Scholarship',      '#10b981'],
                                                'access_dashboard_admin'=> ['Dashboard Editor', '#3b82f6'],
                                            ];
                                            foreach ($allPositions as $pos):
                                                $posFlags = json_decode($pos['flags'] ?? '{}', true) ?: [];
                                                $activeFlags = array_keys(array_filter($posFlags, fn($v) => (int)$v === 1));
                                            ?>
                                            <tr style="border-bottom:1px solid #f1f5f9" class="hover:bg-slate-50/50 transition-colors">
                                                <td style="padding:14px 18px;vertical-align:top">
                                                    <div style="font-weight:800;color:#1e293b;font-size:13.5px;display:flex;align-items:center;gap:8px">
                                                        <i class="fa-solid fa-user-tag" style="color:#7c3aed;font-size:11px"></i>
                                                        <?= htmlspecialchars($pos['name']) ?>
                                                    </div>
                                                    <?php if (!empty($pos['description'])): ?>
                                                        <div style="font-size:11px;color:#64748b;font-weight:600;margin-top:3px"><?= htmlspecialchars($pos['description']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="padding:14px 18px">
                                                    <?php if (empty($activeFlags)): ?>
                                                        <span style="font-size:11px;color:#cbd5e1;font-weight:700">— ไม่มี flag —</span>
                                                    <?php else: ?>
                                                        <div style="display:flex;flex-wrap:wrap;gap:5px">
                                                            <?php foreach ($activeFlags as $f):
                                                                [$label, $color] = $flagLabelMap[$f] ?? [$f, '#64748b'];
                                                            ?>
                                                                <span style="font-size:10px;font-weight:800;padding:3px 9px;border-radius:99px;background:<?= $color ?>15;color:<?= $color ?>;border:1px solid <?= $color ?>40">
                                                                    <?= htmlspecialchars($label) ?>
                                                                </span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="padding:14px 18px;text-align:center">
                                                    <span style="display:inline-block;font-size:11px;font-weight:900;padding:4px 10px;border-radius:99px;background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe"><?= (int)($pos['staff_count'] ?? 0) ?> คน</span>
                                                </td>
                                                <td style="padding:14px 18px;text-align:right">
                                                    <div style="display:flex;gap:6px;justify-content:flex-end">
                                                        <button type="button" onclick='openEditPositionModal(<?= json_encode($pos) ?>)' style="width:32px;height:32px;border-radius:9px;border:1.5px solid #e2e8f0;background:#fff;color:#64748b;cursor:pointer" title="แก้ไข"><i class="fa-solid fa-pen-to-square" style="font-size:12px"></i></button>
                                                        <form method="POST" style="display:inline" onsubmit="return confirmDeletePosition(this, '<?= htmlspecialchars(addslashes($pos['name']), ENT_QUOTES) ?>', <?= (int)($pos['staff_count'] ?? 0) ?>)">
                                                            <input type="hidden" name="action" value="delete_position">
                                                            <input type="hidden" name="position_id" value="<?= (int)$pos['id'] ?>">
                                                            <?php csrf_field(); ?>
                                                            <button type="submit" style="width:32px;height:32px;border-radius:9px;border:1.5px solid #fee2e2;background:#fff;color:#ef4444;cursor:pointer" title="ลบ"><i class="fa-solid fa-trash-can" style="font-size:12px"></i></button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- PANEL: Departments (ฝ่าย/หน่วยงาน) -->
                    <?php if ($adminRole === 'superadmin'): ?>
                    <div id="id-panel-departments" class="id-panel">
                        <div style="background:#fff;border-radius:18px;border:1.5px solid #e2e8f0;overflow:hidden">
                            <div style="padding:20px 24px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
                                <div>
                                    <div style="font-size:14px;font-weight:900;color:#1e293b;display:flex;align-items:center;gap:8px">
                                        <i class="fa-solid fa-sitemap" style="color:#6366f1"></i>
                                        ฝ่าย/หน่วยงาน (Department Master)
                                    </div>
                                    <p style="margin:4px 0 0;font-size:11px;color:#64748b;font-weight:600">
                                        จัดการฝ่ายของคลินิก — ใช้ผูกกับ Staff (ผู้กรอกรายงาน) และ Template ของรายงานประจำเดือน
                                    </p>
                                </div>
                                <button type="button" onclick="openAddDeptModal()" style="padding:10px 16px;border-radius:10px;border:none;background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;font-weight:900;font-size:12px;cursor:pointer;display:inline-flex;align-items:center;gap:8px;box-shadow:0 6px 14px -3px rgba(99,102,241,.35)">
                                    <i class="fa-solid fa-plus"></i> เพิ่มฝ่ายใหม่
                                </button>
                            </div>

                            <?php if (empty($allDepartments)): ?>
                                <div style="padding:60px 20px;text-align:center;color:#94a3b8">
                                    <i class="fa-solid fa-sitemap" style="font-size:38px;display:block;margin-bottom:12px;opacity:.4"></i>
                                    <p style="font-size:13px;font-weight:700;margin:0">ยังไม่มีฝ่ายในระบบ</p>
                                    <p style="font-size:11px;color:#cbd5e1;margin:6px 0 0">คลิก "เพิ่มฝ่ายใหม่" เพื่อเริ่มต้น</p>
                                </div>
                            <?php else: ?>
                                <div style="overflow-x:auto">
                                    <table style="width:100%;border-collapse:collapse;font-size:13px" id="idDeptTable">
                                        <thead>
                                            <tr style="background:#f8fafc;border-bottom:1.5px solid #e2e8f0">
                                                <th style="padding:14px 18px;text-align:left;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em">ชื่อฝ่าย</th>
                                                <th style="padding:14px 18px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:90px">ลำดับ</th>
                                                <th style="padding:14px 18px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:120px">Staff ที่ผูก</th>
                                                <th style="padding:14px 18px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:120px">รายงาน</th>
                                                <th style="padding:14px 18px;text-align:center;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:90px">สถานะ</th>
                                                <th style="padding:14px 18px;text-align:right;font-size:10px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.15em;width:120px">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($allDepartments as $dept): ?>
                                            <tr style="border-bottom:1px solid #f1f5f9" class="hover:bg-slate-50/50 transition-colors">
                                                <td style="padding:14px 18px;vertical-align:top">
                                                    <div style="font-weight:800;color:#1e293b;font-size:13.5px;display:flex;align-items:center;gap:8px">
                                                        <i class="fa-solid fa-building" style="color:#6366f1;font-size:11px"></i>
                                                        <?= htmlspecialchars($dept['name']) ?>
                                                    </div>
                                                    <?php if (!empty($dept['description'])): ?>
                                                        <div style="font-size:11px;color:#64748b;font-weight:600;margin-top:3px"><?= htmlspecialchars($dept['description']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="padding:14px 18px;text-align:center">
                                                    <span style="font-size:12px;font-weight:800;color:#475569"><?= (int)($dept['sort_order'] ?? 0) ?></span>
                                                </td>
                                                <td style="padding:14px 18px;text-align:center">
                                                    <span style="display:inline-block;font-size:11px;font-weight:900;padding:4px 10px;border-radius:99px;background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe"><?= (int)($dept['staff_count'] ?? 0) ?> คน</span>
                                                </td>
                                                <td style="padding:14px 18px;text-align:center">
                                                    <span style="display:inline-block;font-size:11px;font-weight:900;padding:4px 10px;border-radius:99px;background:#fef3c7;color:#92400e;border:1px solid #fde68a"><?= (int)($dept['report_count'] ?? 0) ?></span>
                                                </td>
                                                <td style="padding:14px 18px;text-align:center">
                                                    <?php if ((int)$dept['active'] === 1): ?>
                                                        <span style="font-size:10px;font-weight:900;padding:3px 9px;border-radius:99px;background:#d1fae5;color:#065f46">เปิดใช้</span>
                                                    <?php else: ?>
                                                        <span style="font-size:10px;font-weight:900;padding:3px 9px;border-radius:99px;background:#f1f5f9;color:#64748b">ปิด</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="padding:14px 18px;text-align:right">
                                                    <div style="display:flex;gap:6px;justify-content:flex-end">
                                                        <button type="button" onclick='openEditDeptModal(<?= json_encode($dept, JSON_UNESCAPED_UNICODE) ?>)' style="width:32px;height:32px;border-radius:9px;border:1.5px solid #e2e8f0;background:#fff;color:#64748b;cursor:pointer" title="แก้ไข"><i class="fa-solid fa-pen-to-square" style="font-size:12px"></i></button>
                                                        <button type="button" onclick="deleteDept(<?= (int)$dept['id'] ?>, <?= json_encode($dept['name'], JSON_UNESCAPED_UNICODE) ?>, <?= (int)$dept['staff_count'] ?>, <?= (int)$dept['report_count'] ?>)" style="width:32px;height:32px;border-radius:9px;border:1.5px solid #fecaca;background:#fff;color:#dc2626;cursor:pointer" title="ลบ"><i class="fa-solid fa-trash" style="font-size:12px"></i></button>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
                <?php endif; ?>
                <div id="idGovModal" style="display:none;position:fixed;inset:0;z-index:9200;background:rgba(15,23,42,.6);backdrop-filter:blur(8px);align-items:center;justify-content:center;padding:20px">
                    <div style="background:#fff;border-radius:28px;width:100%;max-width:720px;overflow:hidden;box-shadow:0 25px 50px -12px rgba(0,0,0,.3);display:flex;flex-direction:column;max-height:90vh">
                        <!-- Modal Header -->
                        <div style="padding:24px 30px;background:linear-gradient(90deg,#f8fafc,#fff);border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between">
                            <div style="display:flex;align-items:center;gap:15px">
                                <div id="govModalIcon" style="width:45px;height:45px;border-radius:14px;background:#eff6ff;color:#2563eb;display:flex;align-items:center;justify-content:center;font-size:20px;box-shadow:0 4px 10px rgba(37,99,235,0.1)">
                                    <i class="fa-solid fa-user-shield"></i>
                                </div>
                                <div>
                                    <h3 id="govModalTitle" style="margin:0;font-size:18px;font-weight:900;color:#0f172a">จัดการสิทธิ์ผู้ใช้งานระบบ</h3>
                                    <p style="margin:2px 0 0;font-size:12px;color:#64748b;font-weight:600">Identity & Access Governance Interface</p>
                                </div>
                            </div>
                            <button onclick="document.getElementById('idGovModal').style.display='none'" style="width:36px;height:36px;border-radius:10px;border:1.5px solid #e2e8f0;background:#fff;color:#94a3b8;cursor:pointer;transition:all 0.2s" onmouseover="this.style.color='#ef4444';this.style.borderColor='#fecaca'" onmouseout="this.style.color='#94a3b8';this.style.borderColor='#e2e8f0'">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>

                        <!-- Modal Body (Scrollable) -->
                        <form method="POST" id="idGovForm" style="overflow-y:auto;padding:30px">
                            <input type="hidden" name="action" id="govAction" value="save_identity_gov">
                            <input type="hidden" name="target_id" id="govTargetId">
                            <input type="hidden" name="target_type" id="govTargetType"> <!-- 'admin' or 'staff' -->
                            <?php csrf_field(); ?>

                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:30px">
                                <!-- Column 1: Core Identity -->
                                <div style="display:flex;flex-direction:column;gap:20px">
                                    <div style="font-size:11px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em;display:flex;align-items:center;gap:8px">
                                        <i class="fa-solid fa-id-card"></i> ข้อมูลพื้นฐานบัญชี
                                    </div>
                                    
                                    <div>
                                        <label style="display:block;font-size:12px;font-weight:800;color:#475569;margin-bottom:6px">ชื่อ-นามสกุล <span style="color:#ef4444">*</span></label>
                                        <input type="text" name="full_name" id="govFullName" required class="premium-input" style="width:100%">
                                    </div>
                                    
                                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                                        <div>
                                            <label style="display:block;font-size:12px;font-weight:800;color:#475569;margin-bottom:6px">Username</label>
                                            <input type="text" name="username" id="govUsername" required class="premium-input" style="width:100%">
                                        </div>
                                        <div>
                                            <label style="display:block;font-size:12px;font-weight:800;color:#475569;margin-bottom:6px">สถานะบัญชี</label>
                                            <select name="status" id="govStatus" class="premium-input" style="width:100%;background-image:none">
                                                <option value="active">Active</option>
                                                <option value="disabled">Disabled</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div>
                                        <label style="display:block;font-size:12px;font-weight:800;color:#475569;margin-bottom:6px">อีเมล</label>
                                        <input type="email" name="email" id="govEmail" class="premium-input" style="width:100%" placeholder="— ไม่มีข้อมูล —">
                                    </div>

                                    <div>
                                        <label style="display:block;font-size:12px;font-weight:800;color:#475569;margin-bottom:6px">รหัสผ่าน <span style="font-weight:normal;color:#94a3b8;font-size:11px">(เว้นว่างหากไม่เปลี่ยน)</span></label>
                                        <input type="password" name="password" id="govPassword" class="premium-input" style="width:100%" placeholder="••••••••">
                                    </div>
                                </div>

                                <!-- Column 2: System Roles -->
                                <div style="display:flex;flex-direction:column;gap:20px">
                                    <div style="font-size:11px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em;display:flex;align-items:center;gap:8px">
                                        <i class="fa-solid fa-shield-halved"></i> กำหนดสิทธิ์รายระบบ
                                    </div>

                                    <!-- Job Title (free-text descriptor — e.g. พยาบาล/ธุรการ) — ไม่เกี่ยวกับ permission -->
                                    <div id="govJobTitleWrap" style="display:none">
                                        <label style="display:flex;align-items:center;gap:6px;font-size:12px;font-weight:800;color:#475569;margin-bottom:6px">
                                            <i class="fa-solid fa-id-badge" style="color:#0891b2"></i> ตำแหน่งงาน (Job Title)
                                            <span style="color:#94a3b8;font-weight:normal;font-size:11px">เช่น พยาบาล / ธุรการ / แพทย์ — ไม่เกี่ยวกับสิทธิ์</span>
                                        </label>
                                        <input type="text" name="job_title" id="govJobTitle" class="premium-input" style="width:100%" maxlength="120"
                                               list="govJobTitleSuggest" placeholder="เช่น พยาบาล">
                                        <datalist id="govJobTitleSuggest">
                                            <option value="พยาบาลวิชาชีพ">
                                            <option value="พยาบาลเทคนิค">
                                            <option value="ผู้ช่วยพยาบาล">
                                            <option value="แพทย์">
                                            <option value="ผู้ช่วยแพทย์">
                                            <option value="ธุรการ">
                                            <option value="เภสัชกร">
                                            <option value="ผู้ช่วยเภสัชกร">
                                            <option value="หัวหน้าฝ่าย">
                                            <option value="ผู้จัดการ">
                                            <option value="IT Support">
                                        </datalist>
                                        <p id="govOrgPositionInfo" style="display:none;margin:6px 0 0;font-size:11px;color:#0891b2;font-weight:600">
                                            <i class="fa-solid fa-sitemap"></i> ตำแหน่งในผังองค์กร: <span id="govOrgPositionTitle"></span>
                                            <span style="color:#94a3b8">(แก้ที่ Chain of Command)</span>
                                        </p>
                                    </div>

                                    <!-- Permission Template selector — Hybrid: ผูก position = lock flag, Custom = override เอง -->
                                    <div id="govPositionWrap" style="display:none">
                                        <label style="display:flex;align-items:center;gap:6px;font-size:12px;font-weight:800;color:#475569;margin-bottom:6px">
                                            <i class="fa-solid fa-user-tag" style="color:#7c3aed"></i> ชุดสิทธิ์ตำแหน่ง (Permission Template)
                                            <span style="color:#94a3b8;font-weight:normal;font-size:11px">ผูกแล้ว flag จะ lock ตามตำแหน่ง</span>
                                        </label>
                                        <select name="position_id" id="govPositionId" class="premium-input" style="width:100%;background-image:none" onchange="onGovPositionChange()">
                                            <option value="">— Custom (กำหนด flag เอง) —</option>
                                            <?php foreach (($allPositions ?? []) as $pos): ?>
                                                <option value="<?= (int)$pos['id'] ?>" data-flags='<?= htmlspecialchars($pos['flags'] ?? '{}', ENT_QUOTES) ?>'>
                                                    <?= htmlspecialchars($pos['name']) ?><?= !empty($pos['description']) ? ' — ' . htmlspecialchars($pos['description']) : '' ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p id="govPositionLockNote" style="display:none;margin:6px 0 0;font-size:11px;color:#7c3aed;font-weight:700">
                                            <i class="fa-solid fa-lock"></i> Flag ของตำแหน่งจะถูก apply ทันที (live link) — ปลด lock โดยเลือก "Custom"
                                        </p>
                                    </div>


                                    <!-- e-Borrow Card -->
                                    <div id="govEbCard" onclick="toggleGovAccess('govEbAccess', 'govEbRole', this)" class="premium-role-card orange p-4" style="border-radius:18px;border:1.5px solid #fed7aa;background:#fffaf5;cursor:pointer;transition:all 0.2s">
                                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
                                            <div style="display:flex;align-items:center;gap:10px">
                                                <div id="govEbIcon" style="width:32px;height:32px;background:#ffedd5;color:#ea580c;border-radius:8px;display:flex;align-items:center;justify-content:center"><i class="fa-solid fa-box-archive"></i></div>
                                                <span style="font-weight:900;font-size:13px;color:#9a3412">e-Borrow & Inventory</span>
                                            </div>
                                            <input type="checkbox" id="govEbAccess" name="eb_access" value="1" checked style="width:18px;height:18px;cursor:pointer" onclick="event.stopPropagation(); syncGovUI('govEbAccess', 'govEbRole', 'govEbCard')">
                                        </div>
                                        <select name="eb_role" id="govEbRole" class="premium-input" style="width:100%;font-size:12px;border-color:#fed7aa" onclick="event.stopPropagation()">
                                            <option value="employee">Employee (เจ้าหน้าที่ทั่วไป)</option>
                                            <option value="librarian">Librarian (บรรณารักษ์)</option>
                                            <option value="technician">Technician (ช่างเทคนิค)</option>
                                            <option value="supervisor">Supervisor (หัวหน้างาน)</option>
                                            <option value="admin">System Administrator (ผู้ดูแลสูงสุด)</option>
                                        </select>
                                    </div>

                                    <!-- e-Campaign Card -->
                                    <div id="govEcCard" onclick="toggleGovAccess('govEcAccess', 'govEcRole', this)" class="premium-role-card blue p-4" style="border-radius:18px;border:1.5px solid #bfdbfe;background:#f0f7ff;cursor:pointer;transition:all 0.2s">
                                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
                                            <div style="display:flex;align-items:center;gap:10px">
                                                <div id="govEcIcon" style="width:32px;height:32px;background:#dbeafe;color:#2563eb;border-radius:8px;display:flex;align-items:center;justify-content:center"><i class="fa-solid fa-bullhorn"></i></div>
                                                <span style="font-weight:900;font-size:13px;color:#1e40af">e-Campaign System</span>
                                            </div>
                                            <input type="checkbox" name="ec_access" id="govEcAccess" value="1" style="width:18px;height:18px;cursor:pointer" onclick="event.stopPropagation(); syncGovUI('govEcAccess', 'govEcRole', 'govEcCard')">
                                        </div>
                                        <select name="ec_role" id="govEcRole" class="premium-input" style="width:100%;font-size:12px;border-color:#bfdbfe" onclick="event.stopPropagation()">
                                            <option value="editor">Content Editor (จัดการกิจกรรม)</option>
                                            <option value="admin">System Administrator (ผู้ดูแลสูงสุด)</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Portal Role Card (Only for Admins) -->
                                    <div id="govAdminOnlyCard" style="display:none;background:#f5f3ff;border:1.5px solid #ddd6fe;border-radius:18px;padding:15px">
                                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
                                            <div style="width:30px;height:30px;background:#ede9fe;color:#7c3aed;border-radius:8px;display:flex;align-items:center;justify-content:center"><i class="fa-solid fa-crown"></i></div>
                                            <span style="font-weight:900;font-size:13px;color:#5b21b6">Portal Management</span>
                                        </div>
                                        <select name="admin_role" id="govAdminRole" class="premium-input" style="width:100%;font-size:12px;border-color:#ddd6fe">
                                            <option value="admin">Standard Admin</option>
                                            <option value="editor">Standard Editor</option>
                                            <option value="superadmin">Super Administrator (FULL CONTROL)</option>
                                        </select>
                                    </div>

                                    <!-- Portal Extension Rights -->
                                    <div style="display:flex;flex-direction:column;gap:12px">
                                        <div style="font-size:11px;font-weight:900;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em;display:flex;align-items:center;gap:8px">
                                            <i class="fa-solid fa-puzzle-piece"></i> ส่วนขยาย (Extensions)
                                        </div>
                                        <div style="display:grid;grid-template-columns:1fr;gap:10px">
                                            <!-- Insurance -->
                                            <div onclick="document.getElementById('govInsAccess').click()" class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;cursor:pointer;padding:12px;transition:all 0.2s;display:flex;align-items:center;justify-content:space-between">
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <i class="fa-solid fa-shield-heart text-emerald-500"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569">Insurance Sync Hub</span>
                                                </div>
                                                <input type="checkbox" name="ins_access" id="govInsAccess" value="1" style="width:16px;height:16px" onclick="event.stopPropagation()">
                                            </div>
                                            <!-- Registry (ฝ่ายทะเบียน — upload only) -->
                                            <div onclick="document.getElementById('govRegAccess').click()" class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;cursor:pointer;padding:12px;transition:all 0.2s;display:flex;align-items:center;justify-content:space-between">
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <i class="fa-solid fa-id-card-clip text-cyan-500"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569">Registry Upload (ฝ่ายทะเบียน)</span>
                                                </div>
                                                <input type="checkbox" name="reg_access" id="govRegAccess" value="1" style="width:16px;height:16px" onclick="event.stopPropagation()">
                                            </div>
                                            <!-- Logs -->
                                            <div onclick="document.getElementById('govLogsAccess').click()" class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;cursor:pointer;padding:12px;transition:all 0.2s;display:flex;align-items:center;justify-content:space-between">
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <i class="fa-solid fa-list-ul text-slate-500"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569">System Activity Logs</span>
                                                </div>
                                                <input type="checkbox" name="logs_access" id="govLogsAccess" value="1" style="width:16px;height:16px" onclick="event.stopPropagation()">
                                            </div>
                                            <!-- Settings -->
                                            <div onclick="document.getElementById('govSettAccess').click()" class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;cursor:pointer;padding:12px;transition:all 0.2s;display:flex;align-items:center;justify-content:space-between">
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <i class="fa-solid fa-sliders text-slate-500"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569">Global Site Settings</span>
                                                </div>
                                                <input type="checkbox" name="sett_access" id="govSettAccess" value="1" style="width:16px;height:16px" onclick="event.stopPropagation()">
                                            </div>
                                            <!-- EDMS (สารบรรณอิเล็กทรอนิกส์) -->
                                            <div onclick="document.getElementById('govEdmsAccess').click()" class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;cursor:pointer;padding:12px;transition:all 0.2s;display:flex;align-items:center;justify-content:space-between">
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <i class="fa-solid fa-folder-open text-sky-500"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569">สารบรรณอิเล็กทรอนิกส์ (EDMS)</span>
                                                </div>
                                                <input type="checkbox" name="edms_access" id="govEdmsAccess" value="1" style="width:16px;height:16px" onclick="event.stopPropagation()">
                                            </div>
                                            <!-- EDMS SLA Admin (จัดการ SLA policies + dashboard) -->
                                            <div onclick="document.getElementById('govEdmsSlaAdminAccess').click()" class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;cursor:pointer;padding:12px;transition:all 0.2s;display:flex;align-items:center;justify-content:space-between">
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <i class="fa-solid fa-stopwatch text-sky-500"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569">EDMS SLA Admin (จัดการนโยบาย/Dashboard)</span>
                                                </div>
                                                <input type="checkbox" name="edms_sla_admin_access" id="govEdmsSlaAdminAccess" value="1" style="width:16px;height:16px" onclick="event.stopPropagation()">
                                            </div>
                                            <!-- AI Suite (Assistant / QA Lab / Prompts / Knowledge) -->
                                            <div onclick="document.getElementById('govAiAccess').click()" class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;cursor:pointer;padding:12px;transition:all 0.2s;display:flex;align-items:center;justify-content:space-between">
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <i class="fa-solid fa-wand-magic-sparkles text-purple-500"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569">AI Suite (Assistant / QA / Prompts / Knowledge)</span>
                                                </div>
                                                <input type="checkbox" name="ai_access" id="govAiAccess" value="1" style="width:16px;height:16px" onclick="event.stopPropagation()">
                                            </div>
                                            <!-- Consumables (วัสดุสิ้นเปลือง) -->
                                            <div onclick="document.getElementById('govConsumablesAccess').click()" class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;cursor:pointer;padding:12px;transition:all 0.2s;display:flex;align-items:center;justify-content:space-between">
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <i class="fa-solid fa-syringe text-rose-500"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569">วัสดุสิ้นเปลือง (Consumables)</span>
                                                </div>
                                                <input type="checkbox" name="consumables_access" id="govConsumablesAccess" value="1" style="width:16px;height:16px" onclick="event.stopPropagation()">
                                            </div>
                                            <!-- Asset (ครุภัณฑ์) -->
                                            <div onclick="document.getElementById('govAssetAccess').click()" class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;cursor:pointer;padding:12px;transition:all 0.2s;display:flex;align-items:center;justify-content:space-between">
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <i class="fa-solid fa-warehouse text-amber-500"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569">ครุภัณฑ์ (Asset Inventory)</span>
                                                </div>
                                                <input type="checkbox" name="asset_access" id="govAssetAccess" value="1" style="width:16px;height:16px" onclick="event.stopPropagation()">
                                            </div>
                                            <!-- Finance (Cash Book) -->
                                            <div onclick="document.getElementById('govFinanceAccess').click()" class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;cursor:pointer;padding:12px;transition:all 0.2s;display:flex;align-items:center;justify-content:space-between">
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <i class="fa-solid fa-money-bill-trend-up text-emerald-500"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569">การเงิน (Cash Book)</span>
                                                </div>
                                                <input type="checkbox" name="finance_access" id="govFinanceAccess" value="1" style="width:16px;height:16px" onclick="event.stopPropagation()">
                                            </div>
                                            <!-- Scholarship (นักศึกษาทุน) -->
                                            <div onclick="document.getElementById('govScholarshipAccess').click()" class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;cursor:pointer;padding:12px;transition:all 0.2s;display:flex;align-items:center;justify-content:space-between">
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <i class="fa-solid fa-graduation-cap text-emerald-500"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569">นักศึกษาทุน (Scholarship)</span>
                                                </div>
                                                <input type="checkbox" name="scholarship_access" id="govScholarshipAccess" value="1" style="width:16px;height:16px" onclick="event.stopPropagation()">
                                            </div>
                                            <!-- Dashboard Admin (แก้ไข Insurance Dashboard) -->
                                            <div onclick="document.getElementById('govDashboardAccess').click()" class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;cursor:pointer;padding:12px;transition:all 0.2s;display:flex;align-items:center;justify-content:space-between">
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <i class="fa-solid fa-chart-pie text-blue-500"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569">Dashboard Workbook Editor (สิทธิ์แก้ไข widget)</span>
                                                </div>
                                                <input type="checkbox" name="dashboard_admin_access" id="govDashboardAccess" value="1" style="width:16px;height:16px" onclick="event.stopPropagation()">
                                            </div>
                                            <!-- Monthly Report (กรอกรายงานประจำเดือน) -->
                                            <div onclick="document.getElementById('govMonthlyReportAccess').click()" class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;cursor:pointer;padding:12px;transition:all 0.2s;display:flex;align-items:center;justify-content:space-between">
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <i class="fa-solid fa-clipboard-list text-amber-500"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569">รายงานประจำเดือน (กรอก/แก้ของฝ่ายตัวเอง)</span>
                                                </div>
                                                <input type="checkbox" name="monthly_report_access" id="govMonthlyReportAccess" value="1" style="width:16px;height:16px" onclick="event.stopPropagation()">
                                            </div>
                                            <!-- Nurse Productivity -->
                                            <div onclick="document.getElementById('govNurseProductivityAccess').click()" class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;cursor:pointer;padding:12px;transition:all 0.2s;display:flex;align-items:center;justify-content:space-between">
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <i class="fa-solid fa-user-nurse text-amber-500"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569">Productivity พยาบาล OPD (คำนวณภาระงาน)</span>
                                                </div>
                                                <input type="checkbox" name="nurse_productivity_access" id="govNurseProductivityAccess" value="1" style="width:16px;height:16px" onclick="event.stopPropagation()">
                                            </div>
                                            <!-- Daily Summary -->
                                            <div onclick="document.getElementById('govDailySummaryAccess').click()" class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;cursor:pointer;padding:12px;transition:all 0.2s;display:flex;align-items:center;justify-content:space-between">
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <i class="fa-solid fa-clipboard-check text-amber-500"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569">สรุปงานประจำวัน (Daily Summary dashboard)</span>
                                                </div>
                                                <input type="checkbox" name="daily_summary_access" id="govDailySummaryAccess" value="1" style="width:16px;height:16px" onclick="event.stopPropagation()">
                                            </div>
                                            <!-- Director View (ผู้อำนวยการ) -->
                                            <div onclick="document.getElementById('govDirectorViewAccess').click()" class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;cursor:pointer;padding:12px;transition:all 0.2s;display:flex;align-items:center;justify-content:space-between">
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <i class="fa-solid fa-user-tie text-rose-500"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569">ผู้อำนวยการ (ดูทุกฝ่าย + อนุมัติรายงาน)</span>
                                                </div>
                                                <input type="checkbox" name="director_view_access" id="govDirectorViewAccess" value="1" style="width:16px;height:16px" onclick="event.stopPropagation()">
                                            </div>
                                            <!-- Identity & Governance (จัดการสิทธิ์ผู้ใช้) -->
                                            <div onclick="document.getElementById('govIdentityAccess').click()" class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;cursor:pointer;padding:12px;transition:all 0.2s;display:flex;align-items:center;justify-content:space-between">
                                                <div style="display:flex;align-items:center;gap:10px">
                                                    <i class="fa-solid fa-id-card-clip text-blue-600"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569">Identity &amp; Governance (จัดการสิทธิ์/ตำแหน่ง/ฝ่าย)</span>
                                                </div>
                                                <input type="checkbox" name="identity_access" id="govIdentityAccess" value="1" style="width:16px;height:16px" onclick="event.stopPropagation()">
                                            </div>
                                            <!-- Department dropdown -->
                                            <div class="premium-role-card" style="border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;padding:12px;display:flex;align-items:center;justify-content:space-between;gap:10px">
                                                <div style="display:flex;align-items:center;gap:10px;min-width:0">
                                                    <i class="fa-solid fa-sitemap text-indigo-500"></i>
                                                    <span style="font-weight:800;font-size:12px;color:#475569;white-space:nowrap">ฝ่าย/หน่วยงาน</span>
                                                </div>
                                                <select name="department_id" id="govDepartmentId" class="premium-input" style="flex:1;height:32px;padding:0 8px;font-size:12px;font-weight:700">
                                                    <option value="">— ไม่ระบุ —</option>
                                                    <?php
                                                    try {
                                                        $deptRows = $pdo->query("SELECT id, name FROM sys_departments WHERE active=1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
                                                        foreach ($deptRows as $d) {
                                                            echo '<option value="' . (int)$d['id'] . '">' . htmlspecialchars($d['name']) . '</option>';
                                                        }
                                                    } catch (PDOException $e) { /* table not yet created */ }
                                                    ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- ─────── LINE Account Link (staff · edit เท่านั้น) ─────── -->
                            <div id="govLineLinkSection" style="display:none;margin-top:24px;padding:18px;border:1.5px solid #bbf7d0;border-radius:16px;background:#f0fdf4">
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
                                    <i class="fa-brands fa-line" style="color:#06c755;font-size:18px"></i>
                                    <span style="font-size:13px;font-weight:900;color:#166534">เชื่อมบัญชี LINE</span>
                                </div>
                                <p style="margin:0 0 14px;font-size:11px;color:#15803d;font-weight:600;line-height:1.6">
                                    อ้างอิง <b>ผังองค์กร</b> ก่อน (staff ที่จับคู่กับ user ไว้แล้ว) → ดึง LINE UID มาให้อัตโนมัติ ·
                                    ถ้ายังไม่ได้จัดผังองค์กร ระบบจะค้นจากชื่อใน <b>System Users</b> ที่ผูก LINE แทน ·
                                    <b>วิธีที่แนะนำสุด</b> คือให้ staff เชื่อมเองที่หน้าโปรไฟล์ผ่าน LINE Login (ได้ชื่อ/รูป/แจ้งเตือนครบ)
                                </p>

                                <!-- สถานะปัจจุบัน / ตัวที่เลือก (เติมโดย JS) -->
                                <div id="govLineCurrent" style="margin-bottom:12px"></div>

                                <!-- ค้นหาใน System Users -->
                                <div style="display:flex;gap:8px;margin-bottom:10px">
                                    <input type="text" id="govLineSearch" class="premium-input" autocomplete="off"
                                           placeholder="ค้นหาชื่อ / รหัส / เลขบัตร ใน System Users"
                                           onkeydown="if(event.key==='Enter'){event.preventDefault();govLineSearchUsers();}"
                                           style="flex:1;height:38px;padding:0 12px;font-size:13px;font-weight:700">
                                    <button type="button" onclick="govLineSearchUsers()"
                                            style="height:38px;padding:0 16px;border-radius:10px;border:none;background:#06c755;color:#fff;font-weight:800;font-size:13px;cursor:pointer;white-space:nowrap">
                                        <i class="fa-solid fa-magnifying-glass"></i> ค้นหา
                                    </button>
                                </div>
                                <div id="govLineResults" style="max-height:240px;overflow-y:auto"></div>

                                <!-- ค่า UID ที่จะถูกบันทึก (เติมโดย picker; ปล่อยว่าง = ยกเลิกการเชื่อมเมื่อบันทึก) -->
                                <input type="hidden" name="linked_line_user_id" id="govLinkedLineUid" value="">
                            </div>

                            <!-- Audit Justification -->
                            <div style="margin-top:30px;padding-top:20px;border-top:1.5px dashed #e2e8f0">
                                <label style="display:flex;align-items:center;gap:8px;font-size:12px;font-weight:900;color:#dc2626;margin-bottom:8px">
                                    <i class="fa-solid fa-shield-check"></i> เหตุผลความจำเป็นในการปรับสิทธิ์ (Justification) <span style="color:#ef4444">*</span>
                                </label>
                                <textarea name="justification" id="govJustification" required class="premium-input" style="width:100%;height:70px;padding:12px;font-size:13px;border-color:#fecaca" placeholder="ตัวอย่าง: ได้รับมอบหมายให้ดูแลระบบ e-Borrow เพิ่มเติมตามคำสั่งคณะ..."></textarea>
                                <p style="margin:6px 0 0;font-size:10px;color:#94a3b8;font-weight:700"><i class="fa-solid fa-info-circle"></i> ISO 27001 Requirement: ทุกการปรับเปลี่ยนสิทธิ์ต้องมีการระบุเหตุผลความจำเป็นทางธุรกิจ</p>
                            </div>
                        </form>

                        <!-- Modal Footer -->
                        <div style="padding:24px 30px;background:#f8fafc;border-top:1px solid #f1f5f9;display:flex;gap:12px">
                            <button type="button" onclick="document.getElementById('idGovModal').style.display='none'" style="flex:1;padding:13px;border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;color:#475569;font-weight:800;font-size:14px;cursor:pointer">ยกเลิก</button>
                            <button type="button" onclick="confirmGovSubmit()" style="flex:2;padding:13px;border-radius:14px;border:none;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;font-weight:900;font-size:14px;cursor:pointer;box-shadow:0 10px 20px -5px rgba(37,99,235,0.3);display:flex;align-items:center;justify-content:center;gap:8px">
                                <i class="fa-solid fa-check-double"></i> ยืนยันการปรับปรุงสิทธิ์
                            </button>
                        </div>
                    </div>

            <div id="idEditModal"
                style="display:none;position:fixed;inset:0;z-index:9100;background:rgba(15,23,42,.55);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:20px">
                <div
                    style="background:#fff;border-radius:24px;width:100%;max-width:480px;max-height:90vh;overflow:hidden;box-shadow:0 25px 50px -12px rgba(0,0,0,.25);display:flex;flex-direction:column">
                    <div
                        style="padding:20px 24px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between">
                        <div style="display:flex;align-items:center;gap:10px">
                            <div
                                style="width:36px;height:36px;background:#fffbeb;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#d97706">
                                <i class="fa-solid fa-user-pen"></i>
                            </div>
                            <span style="font-size:15px;font-weight:900;color:#d97706">แก้ไขข้อมูลผู้ใช้</span>
                        </div>
                        <button onclick="document.getElementById('idEditModal').style.display='none'"
                            style="width:30px;height:30px;border-radius:8px;border:1px solid #e2e8f0;background:#f8fafc;color:#64748b;cursor:pointer">
                            <i class="fa-solid fa-times" style="font-size:12px"></i>
                        </button>
                    </div>
                    <form method="POST" style="padding:20px 24px;display:flex;flex-direction:column;gap:14px;overflow-y:auto;flex:1;min-height:0">
                        <input type="hidden" name="action" value="portal_edit_user">
                        <input type="hidden" name="user_id" id="id_edit_uid">
                        <?php if (function_exists('csrf_field'))
                            csrf_field(); ?>
                        <div>
                            <label
                                style="display:block;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.1em;margin-bottom:5px">ชื่อ-นามสกุล
                                <span style="color:#ef4444">*</span></label>
                            <input id="id_edit_name" name="full_name" required
                                style="width:100%;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:12px;font-size:13px;font-family:inherit;font-weight:600;outline:none;box-sizing:border-box"
                                onfocus="this.style.borderColor='#6366f1'" onblur="this.style.borderColor='#e2e8f0'">
                        </div>
                        <div>
                            <label
                                style="display:block;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.1em;margin-bottom:5px">เลขบัตรประชาชน</label>
                            <input id="id_edit_citizen" name="citizen_id" maxlength="13"
                                style="width:100%;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:12px;font-size:13px;font-family:inherit;font-weight:600;outline:none;box-sizing:border-box;letter-spacing:.1em"
                                onfocus="this.style.borderColor='#6366f1'" onblur="this.style.borderColor='#e2e8f0'">
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                            <div>
                                <label
                                    style="display:block;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.1em;margin-bottom:5px">รหัสนักศึกษา</label>
                                <input id="id_edit_sid" name="student_personnel_id" maxlength="15"
                                    style="width:100%;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:12px;font-size:13px;font-family:inherit;font-weight:600;outline:none;box-sizing:border-box"
                                    onfocus="this.style.borderColor='#6366f1'"
                                    onblur="this.style.borderColor='#e2e8f0'">
                            </div>
                            <div>
                                <label
                                    style="display:block;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.1em;margin-bottom:5px">เบอร์โทร</label>
                                <input id="id_edit_phone" name="phone_number"
                                    style="width:100%;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:12px;font-size:13px;font-family:inherit;font-weight:600;outline:none;box-sizing:border-box"
                                    onfocus="this.style.borderColor='#6366f1'"
                                    onblur="this.style.borderColor='#e2e8f0'">
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                            <div>
                                <label
                                    style="display:block;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.1em;margin-bottom:5px">อีเมล</label>
                                <input id="id_edit_email" name="email" type="email"
                                    style="width:100%;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:12px;font-size:13px;font-family:inherit;font-weight:600;outline:none;box-sizing:border-box"
                                    onfocus="this.style.borderColor='#6366f1'" onblur="this.style.borderColor='#e2e8f0'"
                                    placeholder="example@rsu.ac.th">
                            </div>
                            <div>
                                <label
                                    style="display:block;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.1em;margin-bottom:5px">เพศ</label>
                                <select id="id_edit_gender" name="gender"
                                    style="width:100%;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:12px;font-size:13px;font-family:inherit;font-weight:600;outline:none;background:#fff">
                                    <option value="">-- ไม่ระบุ --</option>
                                    <option value="male">ชาย</option>
                                    <option value="female">หญิง</option>
                                    <option value="other">อื่นๆ</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label
                                style="display:block;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.1em;margin-bottom:5px">คณะ
                                / หน่วยงาน</label>
                            <input id="id_edit_dept" name="department"
                                style="width:100%;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:12px;font-size:13px;font-family:inherit;font-weight:600;outline:none;box-sizing:border-box"
                                onfocus="this.style.borderColor='#6366f1'" onblur="this.style.borderColor='#e2e8f0'"
                                placeholder="เช่น คณะนิเทศศาสตร์">
                        </div>
                        <div>
                            <label
                                style="display:block;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.1em;margin-bottom:5px">ประเภท
                                <span style="color:#ef4444">*</span></label>
                            <select id="id_edit_status" name="status"
                                style="width:100%;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:12px;font-size:13px;font-family:inherit;font-weight:600;outline:none;background:#fff"
                                onchange="document.getElementById('id_edit_sother_wrap').style.display=this.value==='other'?'block':'none'">
                                <option value="">-- เลือก --</option>
                                <option value="student">นักศึกษา</option>
                                <option value="staff">บุคลากร/อาจารย์</option>
                                <option value="other">บุคคลทั่วไป</option>
                            </select>
                        </div>
                        <div id="id_edit_sother_wrap" style="display:none">
                            <label
                                style="display:block;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.1em;margin-bottom:5px">ระบุสถานภาพ
                                (กรณีเลือก "อื่นๆ")</label>
                            <input id="id_edit_sother" name="status_other"
                                style="width:100%;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:12px;font-size:13px;font-family:inherit;font-weight:600;outline:none;box-sizing:border-box"
                                onfocus="this.style.borderColor='#6366f1'" onblur="this.style.borderColor='#e2e8f0'"
                                placeholder="เช่น ศิษย์เก่า, ผู้ปกครอง">
                        </div>
                        <div style="display:flex;gap:10px;padding-top:6px">
                            <button type="button" onclick="document.getElementById('idEditModal').style.display='none'"
                                style="flex:1;padding:11px;border-radius:12px;border:1.5px solid #e2e8f0;background:#f8fafc;color:#374151;font-size:13px;font-weight:700;cursor:pointer">ยกเลิก</button>
                            <button type="submit"
                                style="flex:2;padding:11px;border-radius:12px;border:none;background:linear-gradient(90deg,#d97706,#f59e0b);color:#fff;font-size:13px;font-weight:800;cursor:pointer">
                                <i class="fa-solid fa-floppy-disk" style="margin-right:6px"></i>บันทึก
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- View Modal (Identity) -->
            <div id="idViewModal"
                style="display:none;position:fixed;inset:0;z-index:9100;background:rgba(15,23,42,.55);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:20px">
                <div
                    style="background:#fff;border-radius:24px;width:100%;max-width:520px;max-height:90vh;overflow:hidden;box-shadow:0 25px 50px -12px rgba(0,0,0,.25);display:flex;flex-direction:column">
                    <div
                        style="padding:20px 24px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between">
                        <div style="display:flex;align-items:center;gap:10px">
                            <div
                                style="width:36px;height:36px;background:#eef2ff;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#4f46e5">
                                <i class="fa-solid fa-user"></i>
                            </div>
                            <span style="font-size:15px;font-weight:900;color:#4f46e5">ข้อมูลผู้ใช้งาน</span>
                        </div>
                        <button onclick="document.getElementById('idViewModal').style.display='none'"
                            style="width:30px;height:30px;border-radius:8px;border:1px solid #e2e8f0;background:#f8fafc;color:#64748b;cursor:pointer"><i
                                class="fa-solid fa-times" style="font-size:12px"></i></button>
                    </div>
                    <div style="padding:20px 24px;display:flex;flex-direction:column;gap:12px;overflow-y:auto;flex:1;min-height:0" id="idViewBody"></div>
                    <div style="padding:14px 24px;border-top:1px solid #f1f5f9;text-align:right;flex-shrink:0">
                        <button onclick="document.getElementById('idViewModal').style.display='none'"
                            style="padding:9px 22px;border-radius:10px;border:1.5px solid #e2e8f0;background:#f8fafc;color:#374151;font-size:13px;font-weight:700;cursor:pointer">ปิด</button>
                    </div>
                </div>
            </div>


                <!-- Position (ตำแหน่งงาน) Modal -->
                <?php if ($adminRole === 'superadmin'): ?>
                <div id="idPosModal" style="display:none;position:fixed;inset:0;z-index:9300;background:rgba(15,23,42,.6);backdrop-filter:blur(6px);align-items:center;justify-content:center;padding:20px">
                    <div style="background:#fff;border-radius:24px;width:100%;max-width:540px;max-height:90vh;overflow-y:auto;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25)">
                        <form method="POST" id="idPosForm">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" id="posAction" value="add_position">
                            <input type="hidden" name="position_id" id="posId" value="">

                            <div style="padding:22px 26px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between">
                                <div style="display:flex;align-items:center;gap:10px">
                                    <div style="width:38px;height:38px;background:#f5f3ff;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#7c3aed">
                                        <i class="fa-solid fa-user-tag"></i>
                                    </div>
                                    <span id="posModalTitle" style="font-size:15px;font-weight:900;color:#1e293b">สร้างตำแหน่งใหม่</span>
                                </div>
                                <button type="button" onclick="document.getElementById('idPosModal').style.display='none'" style="width:32px;height:32px;border-radius:9px;border:1.5px solid #e2e8f0;background:#f8fafc;color:#64748b;cursor:pointer"><i class="fa-solid fa-xmark"></i></button>
                            </div>

                            <div style="padding:22px 26px;display:flex;flex-direction:column;gap:16px">
                                <div>
                                    <label style="display:block;font-size:12px;font-weight:800;color:#475569;margin-bottom:6px">ชื่อตำแหน่ง <span style="color:#ef4444">*</span></label>
                                    <input type="text" name="position_name" id="posName" required class="premium-input" style="width:100%" placeholder="เช่น ธุรการ, ดูแลข้อมูลคลินิก, ดูแลนักศึกษาทุน">
                                </div>
                                <div>
                                    <label style="display:block;font-size:12px;font-weight:800;color:#475569;margin-bottom:6px">คำอธิบาย <span style="font-weight:normal;color:#94a3b8;font-size:11px">(ไม่บังคับ)</span></label>
                                    <textarea name="position_description" id="posDescription" class="premium-input" style="width:100%;min-height:60px;resize:vertical" placeholder="หน้าที่ความรับผิดชอบหรือ scope ของตำแหน่งนี้"></textarea>
                                </div>

                                <div>
                                    <label style="display:flex;align-items:center;gap:6px;font-size:12px;font-weight:800;color:#475569;margin-bottom:8px">
                                        <i class="fa-solid fa-shield-halved" style="color:#7c3aed"></i> เลือก Flag ที่ตำแหน่งนี้จะได้รับ
                                    </label>
                                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                                        <?php
                                        $posFlagInputs = [
                                            'access_eborrow'        => ['e-Borrow',         'fa-toolbox',            '#f97316'],
                                            'access_ecampaign'      => ['e-Campaign',       'fa-bullhorn',           '#2563eb'],
                                            'access_insurance'      => ['Insurance Sync',   'fa-shield-halved',      '#10b981'],
                                            'access_registry'       => ['Registry Upload',  'fa-id-card-clip',       '#06b6d4'],
                                            'access_system_logs'    => ['System Logs',      'fa-list-ul',            '#64748b'],
                                            'access_site_settings'  => ['Site Settings',    'fa-sliders',            '#7c3aed'],
                                            'access_edms'           => ['EDMS',             'fa-folder-open',        '#0ea5e9'],
                                            'access_ai'             => ['AI Suite',         'fa-wand-magic-sparkles','#a855f7'],
                                            'access_consumables'    => ['Consumables',      'fa-syringe',            '#f43f5e'],
                                            'access_asset'          => ['Asset Inventory',  'fa-warehouse',          '#f59e0b'],
                                            'access_finance'        => ['การเงิน (Cash Book)','fa-money-bill-trend-up','#059669'],
                                            'access_scholarship'    => ['Scholarship',      'fa-graduation-cap',     '#10b981'],
                                            'access_dashboard_admin'=> ['Dashboard Editor', 'fa-chart-pie',          '#3b82f6'],
                                            'access_monthly_report' => ['รายงานประจำเดือน',  'fa-clipboard-list',     '#f59e0b'],
                                            'access_nurse_productivity'=>['Productivity พยาบาล','fa-user-nurse',         '#f59e0b'],
                                            'access_daily_summary'  => ['สรุปงานประจำวัน',     'fa-clipboard-check',    '#f59e0b'],
                                            'access_director_view'  => ['ผู้อำนวยการ',       'fa-user-tie',           '#f43f5e'],
                                            'access_identity'       => ['Identity & Gov',     'fa-id-card-clip',       '#2563eb'],
                                        ];
                                        foreach ($posFlagInputs as $key => [$label, $icon, $color]):
                                        ?>
                                            <label style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:10px;cursor:pointer;transition:all .15s;background:#fff" class="pos-flag-card">
                                                <input type="checkbox" name="flag_<?= $key ?>" id="posFlag_<?= $key ?>" value="1" style="width:15px;height:15px;cursor:pointer">
                                                <i class="fa-solid <?= $icon ?>" style="color:<?= $color ?>;font-size:11px"></i>
                                                <span style="font-size:11.5px;font-weight:700;color:#475569"><?= $label ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <div style="padding:18px 26px;background:#f8fafc;border-top:1px solid #f1f5f9;display:flex;gap:10px">
                                <button type="button" onclick="document.getElementById('idPosModal').style.display='none'" style="flex:1;padding:11px;border-radius:11px;border:1.5px solid #e2e8f0;background:#fff;color:#475569;font-weight:800;font-size:13px;cursor:pointer">ยกเลิก</button>
                                <button type="submit" style="flex:2;padding:11px;border-radius:11px;border:none;background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;font-weight:900;font-size:13px;cursor:pointer;box-shadow:0 8px 16px -4px rgba(124,58,237,.3);display:flex;align-items:center;justify-content:center;gap:8px">
                                    <i class="fa-solid fa-floppy-disk"></i> บันทึกตำแหน่ง
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Add Privilege Modal -->
                <div id="privModal" style="display:none;position:fixed;inset:0;z-index:9400;background:rgba(15,23,42,.6);backdrop-filter:blur(6px);align-items:center;justify-content:center;padding:20px">
                    <div style="background:#fff;border-radius:28px;width:100%;max-width:480px;max-height:90vh;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);overflow-y:auto">
                        <div style="padding:24px;background:#fcfdfd;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center">
                            <h3 style="margin:0;font-size:18px;font-weight:900;color:#0f172a">🛡️ บันทึกการถือสิทธิ์ระดับสูง</h3>
                            <button type="button" onclick="document.getElementById('privModal').style.display='none'" style="background:none;border:none;color:#94a3b8;cursor:pointer;font-size:20px"><i class="fa-solid fa-xmark"></i></button>
                        </div>
                        <form id="privForm" style="padding:24px" enctype="multipart/form-data">
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
                                <div>
                                    <label style="display:block;font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;margin-bottom:6px">ผู้รับสิทธิ์ (Admin)</label>
                                    <select name="user_id" class="premium-input" style="width:100%" required>
                                        <option value="">-- เลือกเจ้าหน้าที่ --</option>
                                        <?php foreach ($adminListForSelect as $adm): ?>
                                            <option value="<?= $adm['id'] ?>"><?= htmlspecialchars($adm['full_name']) ?> (@<?= htmlspecialchars($adm['username']) ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label style="display:block;font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;margin-bottom:6px">บทบาท/ระดับสิทธิ์</label>
                                    <input type="text" name="role_assigned" class="premium-input" style="width:100%" required placeholder="เช่น Super Admin">
                                </div>
                            </div>
                            <div style="margin-bottom:16px">
                                <label style="display:block;font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;margin-bottom:6px">เหตุผลความจำเป็น (Justification)</label>
                                <textarea name="justification" class="premium-input" style="width:100%;height:60px" required placeholder="ระบุเหตุผลในการให้สิทธิ์..."></textarea>
                            </div>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
                                <div>
                                    <label style="display:block;font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;margin-bottom:6px">ผู้อนุมัติ (Approved By)</label>
                                    <input type="text" name="approved_by" class="premium-input" style="width:100%" required placeholder="ชื่อผู้อนุมัติ">
                                </div>
                                <div>
                                    <label style="display:block;font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;margin-bottom:6px">วันหมดอายุ (ถ้ามี)</label>
                                    <input type="date" name="expiry_date" class="premium-input" style="width:100%">
                                </div>
                            </div>
                            <div style="margin-bottom:24px">
                                <label style="display:block;font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;margin-bottom:6px">หลักฐานการอนุมัติ (PDF/Image)</label>
                                <input type="file" name="approval_doc" class="premium-input" style="width:100%" accept=".pdf,image/*">
                            </div>
                            <div style="display:flex;gap:12px">
                                <button type="button" onclick="document.getElementById('privModal').style.display='none'" style="flex:1;padding:12px;border-radius:14px;background:#f1f5f9;color:#475569;font-weight:800;border:none;cursor:pointer">ยกเลิก</button>
                                <button type="submit" id="btnSavePriv" style="flex:1;padding:12px;border-radius:14px;background:#2e9e63;color:#fff;font-weight:800;border:none;cursor:pointer;box-shadow:0 4px 12px rgba(46,158,99,.2)">บันทึกรายการ</button>
                            </div>
                        </form>
                    </div>
                </div>
                <script>
                    function openAddPrivilegeModal() {
                        document.getElementById('privModal').style.display = 'flex';
                    }
                    document.getElementById('privForm')?.addEventListener('submit', function(e) {
                        e.preventDefault();
                        const fd = new FormData(this);
                        const btn = document.getElementById('btnSavePriv');
                        btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> กำลังบันทึก...';
                        
                        fetch('ajax_privilege_inventory.php', { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(d => {
                            if(d.status === 'success') {
                                Swal.fire({ icon: 'success', title: 'สำเร็จ', text: d.message }).then(() => location.reload());
                            } else {
                                Swal.fire({ icon: 'error', title: 'ผิดพลาด', text: d.message });
                                btn.disabled = false; btn.textContent = 'บันทึกรายการ';
                            }
                        })
                        .catch(err => {
                            Swal.fire({ icon: 'error', title: 'Error', text: 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้' });
                            btn.disabled = false; btn.textContent = 'บันทึกรายการ';
                        });
                    });
                </script>

            </div><!-- /section-identity -->
<?php layout_end(); ?>
