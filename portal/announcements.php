<?php
/**
 * portal/announcements.php
 * Section page — auto-generated from monolithic index.php refactor.
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/_portal_data.php';
require_once __DIR__ . '/_layout.php';

layout_start(['section' => 'announcements', 'title' => 'ประกาศ']);
?>
            <div id="section-announcements" class="portal-section" 
                style="width:100%; height:calc(100vh - 60px); background:#f8fafc; overflow-y:auto;">
                <div class="px-5 md:px-8 py-8">

                    <?php if ($ann_saved): ?>
                    <div style="display:flex;align-items:center;gap:10px;background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:14px;padding:12px 18px;margin-bottom:20px;font-size:13px;font-weight:700;color:#15803d">
                        <i class="fa-solid fa-circle-check"></i> บันทึกข้อมูลสำเร็จ
                    </div>
                    <?php endif; ?>
                    <?php if ($ann_error): ?>
                    <div style="display:flex;align-items:center;gap:10px;background:#fff1f2;border:1.5px solid #fecaca;border-radius:14px;padding:12px 18px;margin-bottom:20px;font-size:13px;font-weight:700;color:#dc2626">
                        <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($ann_error) ?>
                    </div>
                    <?php endif; ?>

                    <!-- Header -->
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:24px">
                        <div>
                            <div class="sec-title" style="margin-bottom:4px">
                                <div style="width:28px;height:28px;border-radius:8px;background:#fdf2f8;color:#db2777;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0;">
                                    <i class="fa-solid fa-bullhorn"></i>
                                </div>
                                จัดการประกาศ
                            </div>
                            <p style="font-size:13px;color:#64748b">สร้างและแก้ไขประกาศที่จะปรากฏเป็น Popup ให้ผู้ใช้เห็นเมื่อเข้าหน้า Hub</p>
                        </div>
                        <button onclick="annOpenForm('create')"
                            style="background:#7c3aed;color:#fff;padding:10px 20px;border-radius:12px;font-size:13px;font-weight:700;border:none;cursor:pointer;display:flex;align-items:center;gap:8px;box-shadow:0 4px 14px rgba(124,58,237,.3)">
                            <i class="fa-solid fa-plus"></i> สร้างประกาศใหม่
                        </button>
                    </div>

                    <!-- Announcement List -->
                    <div style="display:flex;flex-direction:column;gap:14px;">
                        <?php if (empty($announcements_list)): ?>
                        <div style="text-align:center;padding:60px 20px;background:#fff;border-radius:24px;border:1.5px dashed #e2e8f0;color:#94a3b8">
                            <i class="fa-solid fa-bullhorn" style="font-size:2.5rem;margin-bottom:12px;display:block;opacity:.3"></i>
                            <p style="font-weight:700;font-size:14px">ยังไม่มีประกาศ</p>
                            <p style="font-size:12px;margin-top:4px">กดปุ่ม "สร้างประกาศใหม่" เพื่อเพิ่มประกาศแรก</p>
                        </div>
                        <?php else: ?>
                        <?php
                            $typeStyles = [
                                'info'    => ['bg'=>'#eff6ff','color'=>'#1d4ed8','icon'=>'fa-bullhorn',        'label'=>'ข้อมูลทั่วไป'],
                                'warning' => ['bg'=>'#fffbeb','color'=>'#b45309','icon'=>'fa-triangle-exclamation','label'=>'แจ้งเตือน'],
                                'success' => ['bg'=>'#f0fdf4','color'=>'#15803d','icon'=>'fa-circle-check',   'label'=>'ข่าวดี'],
                                'urgent'  => ['bg'=>'#fff1f2','color'=>'#dc2626','icon'=>'fa-siren-on',       'label'=>'ด่วน!'],
                            ];
                        ?>
                        <?php foreach ($announcements_list as $ann): ?>
                        <?php $ts = $typeStyles[$ann['type']] ?? $typeStyles['info']; ?>
                        <div style="background:#fff;border-radius:20px;border:1.5px solid #f1f5f9;padding:18px 22px;display:flex;align-items:center;gap:16px;box-shadow:0 2px 8px rgba(0,0,0,.04)">
                            <!-- Icon -->
                            <div style="width:44px;height:44px;border-radius:14px;background:<?= $ts['bg'] ?>;color:<?= $ts['color'] ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                                <i class="fa-solid <?= $ts['icon'] ?> text-lg"></i>
                            </div>
                            <!-- Info -->
                            <div style="flex:1;min-width:0">
                                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:2px">
                                    <span style="font-weight:800;font-size:14px;color:#0f172a"><?= htmlspecialchars($ann['title']) ?></span>
                                    <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px;background:<?= $ts['bg'] ?>;color:<?= $ts['color'] ?>"><?= $ts['label'] ?></span>
                                    <?php if ($ann['target_audience'] !== 'all'): ?>
                                    <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px;background:#f1f5f9;color:#64748b"><?= htmlspecialchars($ann['target_audience']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($ann['title_en'])): ?>
                                    <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px;background:#eff6ff;color:#1d4ed8;border:1px solid #dbeafe">EN</span>
                                    <?php endif; ?>
                                    <?php if (!$ann['is_active']): ?>
                                    <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px;background:#fef2f2;color:#dc2626">ปิดอยู่</span>
                                    <?php endif; ?>
                                </div>
                                <p style="font-size:12px;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:500px"><?= htmlspecialchars(mb_substr($ann['content'], 0, 100)) ?>...</p>
                                <div style="display:flex;align-items:center;gap:12px;margin-top:4px;font-size:11px;color:#94a3b8;font-weight:600">
                                    <span><i class="fa-solid fa-eye mr-1"></i><?= (int)$ann['read_count'] ?> คนอ่านแล้ว</span>
                                    <?php if ($ann['end_date']): ?><span><i class="fa-regular fa-calendar-xmark mr-1"></i>หมดอายุ <?= date('d/m/Y', strtotime($ann['end_date'])) ?></span><?php endif; ?>
                                    <?php if ($ann['image_url']): ?><span><i class="fa-solid fa-image mr-1"></i>มีรูปภาพ</span><?php endif; ?>
                                </div>
                            </div>
                            <!-- Actions -->
                            <div style="display:flex;gap:6px;flex-shrink:0">
                                <!-- Toggle -->
                                <form method="POST" style="display:inline">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="ann_action" value="toggle">
                                    <input type="hidden" name="ann_id" value="<?= $ann['id'] ?>">
                                    <input type="hidden" name="ann_active_val" value="<?= $ann['is_active'] ? '0' : '1' ?>">
                                    <button type="submit" title="<?= $ann['is_active'] ? 'ปิด' : 'เปิด' ?>ประกาศ"
                                        style="width:34px;height:34px;border-radius:10px;border:1px solid #e2e8f0;background:#fff;cursor:pointer;color:<?= $ann['is_active'] ? '#22c55e' : '#94a3b8' ?>;display:flex;align-items:center;justify-content:center">
                                        <i class="fa-solid <?= $ann['is_active'] ? 'fa-toggle-on' : 'fa-toggle-off' ?> text-lg"></i>
                                    </button>
                                </form>
                                <!-- Edit -->
                                <button onclick="annOpenForm('edit', <?= htmlspecialchars(json_encode($ann, JSON_UNESCAPED_UNICODE)) ?>)"
                                    style="width:34px;height:34px;border-radius:10px;border:1px solid #e2e8f0;background:#fff;cursor:pointer;color:#6366f1;display:flex;align-items:center;justify-content:center">
                                    <i class="fa-solid fa-pen-to-square text-sm"></i>
                                </button>
                                <!-- Delete -->
                                <button onclick="annConfirmDelete(<?= $ann['id'] ?>, '<?= htmlspecialchars(addslashes($ann['title'])) ?>')"
                                    style="width:34px;height:34px;border-radius:10px;border:1px solid #fee2e2;background:#fff1f2;cursor:pointer;color:#ef4444;display:flex;align-items:center;justify-content:center">
                                    <i class="fa-solid fa-trash text-sm"></i>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                </div>
            </div><!-- /section-announcements -->
<?php layout_end(); ?>
