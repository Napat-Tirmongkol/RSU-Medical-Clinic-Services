<?php
/**
 * portal/privilege_inventory.php
 * Section page — auto-generated from monolithic index.php refactor.
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

layout_start(['section' => 'privilege_inventory', 'title' => 'ISO Governance']);
?>
            <div class="portal-section" 
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
<?php layout_end(); ?>
