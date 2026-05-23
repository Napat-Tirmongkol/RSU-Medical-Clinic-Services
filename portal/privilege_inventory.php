<?php
/**
 * portal/privilege_inventory.php
 * ISO 27001:2022 A.5.18 — Privileged Access Inventory.
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

// Fetch privilege inventory (superadmin-only — table joins sys_admins for display names).
// Was previously loaded inside monolithic index.php; restored here so the
// standalone page renders the rows instead of an empty list.
$privilegeInventory = [];
$adminListForSelect = [];
if ($adminRole === 'superadmin') {
    try {
        $privilegeInventory = $pdo->query(
            "SELECT p.*, a.full_name as admin_full_name, a.username as admin_username
             FROM sys_admin_privilege_inventory p
             LEFT JOIN sys_admins a ON p.user_id = a.id
             ORDER BY p.assigned_at DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { /* table not yet migrated — show empty state */ }

    // For the "Add Privilege" modal dropdown
    try {
        $adminListForSelect = $pdo->query(
            "SELECT id, full_name, username FROM sys_admins ORDER BY full_name ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
}

layout_start(['section' => 'privilege_inventory', 'title' => 'ISO Governance']);
?>
            <div id="section-privilege_inventory" class="portal-section" 
                style="width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <div class="px-5 md:px-8 py-8">
                    <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:24px">
                        <div>
                            <div class="sec-title" style="margin-bottom:2px">🛡️ Privileged Access Inventory</div>
                            <p style="font-size:13px;color:#64748b">ISO 27001:2022 Control A.5.18 - การจัดการสิทธิ์การเข้าถึงที่ได้รับสิทธิพิเศษ</p>
                        </div>
                        <div style="display:flex;gap:10px;align-items:center">
                            <button onclick="openAddPrivilegeModal()"
                                style="background:#2e9e63;color:#fff;padding:8px 16px;border-radius:11px;font-size:12px;font-weight:700;border:none;cursor:pointer;box-shadow:0 4px 12px rgba(46,158,99,.25)">
                                <i class="fa-solid fa-plus mr-1"></i> บันทึกการให้สิทธิ์ใหม่
                            </button>
                        </div>
                    </div>

                    <div style="background:#fff;border-radius:20px;border:1.5px solid #e2e8f0;overflow:hidden">
                        <div style="padding:18px 24px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:8px;background:#fcfdfc">
                            <i class="fa-solid fa-list-check text-emerald-600"></i>
                            <span style="font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.14em;color:#374151">บันทึกประวัติการถือสิทธิ์ระดับสูง</span>
                        </div>
                        <div style="overflow-x:auto">
                            <table style="width:100%;border-collapse:collapse;font-size:13px">
                                <thead>
                                    <tr style="background:#f8fafc;border-bottom:1px solid #f1f5f9">
                                        <th style="padding:12px 20px;text-align:left;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.14em">ผู้ได้รับสิทธิ์</th>
                                        <th style="padding:12px 20px;text-align:left;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.14em">ระดับสิทธิ์ / บทบาท</th>
                                        <th style="padding:12px 20px;text-align:left;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.14em">วันที่ได้รับ / หมดอายุ</th>
                                        <th style="padding:12px 20px;text-align:left;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.14em">ผู้อนุมัติ (Approved By)</th>
                                        <th style="padding:12px 20px;text-align:center;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.14em">สถานะ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($privilegeInventory)): ?>
                                        <tr>
                                            <td colspan="5" style="padding:40px;text-align:center;color:#94a3b8">
                                                <i class="fa-solid fa-folder-open text-4xl mb-3 block opacity-20"></i>
                                                <p class="font-bold">ยังไม่มีการบันทึกข้อมูลในระบบ Inventory</p>
                                                <p class="text-[11px]">กรุณาคลิก "บันทึกการให้สิทธิ์ใหม่" เพื่อเริ่มจัดเก็บประภูมิตามมาตรฐาน ISO</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($privilegeInventory as $row): 
                                            $isExpired = $row['expiry_date'] && strtotime($row['expiry_date']) < time();
                                            $statusColor = $row['status'] == 1 && !$isExpired ? '#16a34a' : '#dc2626';
                                            $statusBg = $row['status'] == 1 && !$isExpired ? '#f0fdf4' : '#fef2f2';
                                            $statusText = $row['status'] == 1 && !$isExpired ? 'Active' : ($isExpired ? 'Expired' : 'Revoked');
                                        ?>
                                        <tr style="border-bottom:1px solid #f1f5f9">
                                            <td style="padding:14px 20px">
                                                <div style="font-weight:750;color:#0f172a"><?= htmlspecialchars($row['admin_full_name'] ?? '—') ?></div>
                                                <div style="font-size:11px;color:#64748b">@<?= htmlspecialchars($row['admin_username'] ?? 'unknown') ?></div>
                                            </td>
                                            <td style="padding:14px 20px">
                                                <div style="font-size:12px;font-weight:800;color:#1e293b"><?= htmlspecialchars($row['role_assigned'] ?? '—') ?></div>
                                                <div style="font-size:10px;color:#94a3b8;max-width:200px" class="truncate" title="<?= htmlspecialchars($row['justification'] ?? '') ?>">
                                                    Reason: <?= htmlspecialchars($row['justification'] ?? '—') ?>
                                                </div>
                                            </td>
                                            <td style="padding:14px 20px">
                                                <div style="font-size:12px;font-weight:700;color:#334155"><?= date('d M Y', strtotime($row['assigned_at'])) ?></div>
                                                <div style="font-size:10px;color:<?= $isExpired ? '#ef4444' : '#94a3b8' ?>">
                                                    Exp: <?= $row['expiry_date'] ? date('d M Y', strtotime($row['expiry_date'])) : 'Permanent' ?>
                                                </div>
                                            </td>
                                            <td style="padding:14px 20px">
                                                <div style="font-size:12px;font-weight:700;color:#475569"><?= htmlspecialchars($row['approved_by'] ?? '—') ?></div>
                                                <?php if ($row['document_path']):
                                                    // document_path stored as 'storage/access_requests/...' (project-root relative).
                                                    // Page rendered from /portal/index.php — needs '../' prefix.
                                                    $_docRaw  = (string)$row['document_path'];
                                                    $_docHref = (str_starts_with($_docRaw, '/') || str_starts_with($_docRaw, '../'))
                                                        ? $_docRaw
                                                        : '../' . ltrim($_docRaw, './');
                                                ?>
                                                    <a href="<?= htmlspecialchars($_docHref) ?>" target="_blank" style="font-size:10px;color:#2563eb;text-decoration:none">
                                                        <i class="fa-solid fa-file-pdf mr-1"></i> ดูเอกสารประกอบ
                                                    </a>
                                                <?php else: ?>
                                                    <span style="font-size:10px;color:#cbd5e1;font-style:italic">No document</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding:14px 20px;text-align:center">
                                                <span style="padding:3px 10px;border-radius:99px;font-size:10px;font-weight:800;background:<?= $statusBg ?>;color:<?= $statusColor ?>;border:1px solid <?= $statusColor ?>40">
                                                    <?= $statusText ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div style="padding:15px 24px;background:#f8fafc;border-top:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center">
                            <div style="font-size:11px;color:#94a3b8;font-weight:700">
                                <i class="fa-solid fa-circle-info mr-1"></i> ข้อมูลนี้ถูกใช้เพื่อการ Audit มาตรฐานความปลอดภัยสารสนเทศ
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Add Privilege Modal (extracted from old monolithic identity section) -->
            <div id="privModal" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(15,23,42,.6);backdrop-filter:blur(6px);align-items:center;justify-content:center;padding:20px">
                <div style="background:#fff;border-radius:28px;width:100%;max-width:480px;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);overflow:hidden">
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
                    // Teleport to body so fixed-position anchors to viewport (escape any
                    // ancestor containing-block created by transform/backdrop-filter on
                    // sidebar/header — see CLAUDE.md "Portal-Escape Pattern")
                    var m = document.getElementById('privModal');
                    if (m && m.parentElement !== document.body) document.body.appendChild(m);
                    if (m) m.style.display = 'flex';
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
<?php layout_end(); ?>
