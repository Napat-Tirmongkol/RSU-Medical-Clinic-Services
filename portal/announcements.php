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

                <!-- Announcement Form Modal — restored from pre-refactor portal/index.php
                     (JS in portal/_layout_bottom.php references these IDs).
                     z-index 9000 per Portal-Escape Pattern (CLAUDE.md) — sits above
                     sidebar/topbar; backdrop uses explicit rgba for compile safety. -->
                <div id="ann-form-modal"
                    style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(15,23,42,.6);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:20px">
                    <div style="background:#fff;border-radius:28px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;box-shadow:0 30px 60px rgba(0,0,0,.2)">
                        <div style="padding:24px 28px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:#fff;z-index:10">
                            <div>
                                <p style="font-size:11px;font-weight:800;color:#7c3aed;text-transform:uppercase;letter-spacing:.1em;margin-bottom:2px">ระบบประกาศ</p>
                                <h3 id="ann-form-title" style="font-size:18px;font-weight:900;color:#0f172a">สร้างประกาศใหม่</h3>
                            </div>
                            <button onclick="annCloseForm()" type="button" style="width:36px;height:36px;border-radius:10px;border:none;background:#f1f5f9;color:#64748b;cursor:pointer;display:flex;align-items:center;justify-content:center">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>
                        <form method="POST" id="ann-form" enctype="multipart/form-data" style="padding:24px 28px;display:flex;flex-direction:column;gap:16px">
                            <?php csrf_field(); ?>
                            <input type="hidden" id="ann-form-action" name="ann_action" value="create">
                            <input type="hidden" id="ann-form-id" name="ann_id" value="0">

                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                                <div>
                                    <label style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:6px">หัวข้อประกาศ (TH) <span style="color:red">*</span></label>
                                    <input type="text" id="ann-f-title" name="ann_title" required class="premium-input" placeholder="เช่น แจ้งวันหยุดให้บริการ">
                                </div>
                                <div>
                                    <label style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:6px">Announcement Title (EN)</label>
                                    <input type="text" id="ann-f-title-en" name="ann_title_en" class="premium-input" placeholder="e.g. Holiday Announcement">
                                </div>
                            </div>

                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                                <div>
                                    <label style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:6px">เนื้อหา (TH) <span style="color:red">*</span></label>
                                    <textarea id="ann-f-content" name="ann_content" required rows="4" class="premium-input" style="resize:vertical" placeholder="รายละเอียดของประกาศ..."></textarea>
                                </div>
                                <div>
                                    <label style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:6px">Content (EN)</label>
                                    <textarea id="ann-f-content-en" name="ann_content_en" rows="4" class="premium-input" style="resize:vertical" placeholder="Announcement details in English..."></textarea>
                                </div>
                            </div>

                            <div>
                                <label style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:6px">รูปภาพประกอบ (ถ้ามี)</label>
                                <input type="hidden" id="ann-f-image-existing" name="ann_image_existing" value="">
                                <input type="hidden" id="ann-f-image-clear"    name="ann_image_clear"    value="">

                                <label for="ann-f-image-file" id="ann-image-drop"
                                    style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;padding:18px;border:1.5px dashed #cbd5e1;border-radius:14px;background:#f8fafc;cursor:pointer;transition:all .2s;text-align:center">
                                    <i class="fa-solid fa-cloud-arrow-up" style="font-size:22px;color:#7c3aed"></i>
                                    <span style="font-size:13px;font-weight:700;color:#0f172a">คลิกเพื่อแนบไฟล์ภาพ</span>
                                    <span style="font-size:11px;color:#94a3b8">JPG / PNG / WebP / GIF — สูงสุด 5 MB</span>
                                </label>
                                <input type="file" id="ann-f-image-file" name="ann_image_file" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none">

                                <div id="ann-image-preview-wrap" style="display:none;margin-top:10px;position:relative;border-radius:14px;overflow:hidden;border:1px solid #e2e8f0;background:#f8fafc">
                                    <img id="ann-image-preview" src="" alt="preview"
                                        style="display:block;width:100%;max-height:220px;object-fit:contain;background:#fff">
                                    <div id="ann-image-preview-meta" style="padding:8px 12px;font-size:11px;color:#64748b;background:#fff;border-top:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;gap:8px">
                                        <span id="ann-image-preview-name" style="font-weight:700;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></span>
                                        <button type="button" onclick="annClearImage()"
                                            style="flex-shrink:0;padding:5px 10px;border-radius:8px;border:1px solid #fecaca;background:#fee2e2;color:#b91c1c;font-size:11px;font-weight:700;cursor:pointer">
                                            <i class="fa-solid fa-trash mr-1"></i> ลบรูป
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                                <div>
                                    <label style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:6px">ประเภท</label>
                                    <select id="ann-f-type" name="ann_type" class="premium-input">
                                        <option value="info">📘 ข้อมูลทั่วไป</option>
                                        <option value="warning">⚠️ แจ้งเตือน</option>
                                        <option value="success">✅ ข่าวดี</option>
                                        <option value="urgent">🚨 ด่วน!</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:6px">กลุ่มเป้าหมาย</label>
                                    <select id="ann-f-audience" name="ann_audience" class="premium-input">
                                        <option value="all">ทุกคน</option>
                                        <option value="student">นักศึกษา</option>
                                        <option value="staff">บุคลากร</option>
                                        <option value="other">บุคคลทั่วไป</option>
                                    </select>
                                </div>
                            </div>

                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                                <div>
                                    <label style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:6px">วันเริ่ม (ไม่บังคับ)</label>
                                    <input type="date" id="ann-f-start" name="ann_start" class="premium-input">
                                </div>
                                <div>
                                    <label style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:6px">วันหมดอายุ (ไม่บังคับ)</label>
                                    <input type="date" id="ann-f-end" name="ann_end" class="premium-input">
                                </div>
                            </div>

                            <div>
                                <label style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:6px">ลำดับความสำคัญ (0-255)</label>
                                <input type="number" id="ann-f-priority" name="ann_priority" min="0" max="255" value="0" class="premium-input">
                            </div>

                            <div style="display:flex;gap:20px">
                                <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;cursor:pointer">
                                    <input type="checkbox" id="ann-f-active" name="ann_active" value="1" checked style="width:16px;height:16px;accent-color:#7c3aed">
                                    เปิดใช้งานทันที
                                </label>
                                <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;cursor:pointer">
                                    <input type="checkbox" id="ann-f-show-once" name="ann_show_once" value="1" checked style="width:16px;height:16px;accent-color:#7c3aed">
                                    แสดงครั้งเดียวต่อ User
                                </label>
                            </div>

                            <div style="display:flex;gap:10px;padding-top:8px;border-top:1px solid #f1f5f9">
                                <button type="button" onclick="annCloseForm()"
                                    style="flex:none;padding:11px 20px;border-radius:12px;border:1.5px solid #e2e8f0;background:#fff;font-size:13px;font-weight:700;color:#64748b;cursor:pointer">
                                    ยกเลิก
                                </button>
                                <button type="submit"
                                    style="flex:1;padding:11px 20px;border-radius:12px;border:none;background:#7c3aed;color:#fff;font-size:13px;font-weight:800;cursor:pointer;box-shadow:0 4px 14px rgba(124,58,237,.3)">
                                    <i class="fa-solid fa-save mr-1.5"></i> บันทึกประกาศ
                                </button>
                            </div>
                        </form>
                    </div>
                </div><!-- /ann-form-modal -->
            </div><!-- /section-announcements -->
<?php layout_end(); ?>
