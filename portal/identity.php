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
            <div class="portal-section"
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
                            <div style="padding:18px 24px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:8px">
                                <div style="width:4px;height:18px;background:#2563eb;border-radius:99px;flex-shrink:0"></div>
                                <span style="font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.14em;color:#374151">Staff Permission Matrix</span>
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
            </div><!-- /section-identity -->
<?php layout_end(); ?>
