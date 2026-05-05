<?php
/**
 * portal/_partials/registry_upload.php
 * UI สำหรับฝ่ายทะเบียน — อัพโหลดรายชื่อให้ระบบประกัน
 *
 * โหมดหลัก: "รวม 3 ไฟล์ → ส่งประกัน"
 *   อัพโหลด staff + student + resigned พร้อมกัน → preview → commit เป็น batch เดียว
 *   - dedupe staff ↔ student (citizen_id, fallback member_id) — บุคลากรชนะ
 *   - ตัดคนที่อยู่ในไฟล์ resigned ออก
 *
 * โหมด Advanced: "ทีละไฟล์ (โหมดเดิม)"
 *   - ใช้ ajax_insurance_sync.php?action=upload (full_sync / append)
 */
declare(strict_types=1);
?>
<script src="https://cdn.jsdelivr.net/npm/marked@12.0.0/marked.min.js"></script>
<div style="padding:1.5rem 2rem; max-width:1200px; margin:0 auto;">

    <div style="margin-bottom:1.5rem;">
        <h1 style="margin:0; font-size:1.55rem; font-weight:900; color:#0f172a;">
            <i class="fa-solid fa-id-card-clip mr-1" style="color:#06b6d4;"></i>
            อัพโหลดรายชื่อ (ฝ่ายทะเบียน)
        </h1>
        <p style="margin:.35rem 0 0 0; font-size:.85rem; color:#64748b;">
            อัพโหลดรายชื่อบุคลากร / นักศึกษา / คนออก — ระบบจะ dedupe + ตัดคนออก ก่อนส่งให้บริษัทประกัน
        </p>
    </div>

    <!-- ─────────────── Combined Wizard (PRIMARY) ─────────────── -->
    <div style="background:#fff; border-radius:1rem; box-shadow:0 4px 14px rgba(0,0,0,.05); padding:1.75rem; margin-bottom:1.25rem;">

        <div style="display:flex; align-items:center; gap:.75rem; margin-bottom:1.25rem;">
            <i class="fa-solid fa-layer-group" style="font-size:1.5rem; color:#06b6d4;"></i>
            <div>
                <h3 style="margin:0; font-weight:800; color:#0f172a;">รวม 3 ไฟล์ → ส่งประกัน</h3>
                <p style="margin:.2rem 0 0 0; font-size:.78rem; color:#64748b;">
                    อัพโหลดทั้งหมดพร้อมกัน · ระบบจะแสดงพรีวิวก่อนยืนยัน
                </p>
            </div>
        </div>

        <!-- Stepper -->
        <div style="display:flex; align-items:center; gap:.5rem; margin-bottom:1.25rem; font-size:.78rem; font-weight:700;">
            <div id="cwStep1" class="cw-step cw-step-active">
                <span class="cw-step-num">1</span> เลือกไฟล์
            </div>
            <div class="cw-step-line"></div>
            <div id="cwStep2" class="cw-step">
                <span class="cw-step-num">2</span> พรีวิว
            </div>
            <div class="cw-step-line"></div>
            <div id="cwStep3" class="cw-step">
                <span class="cw-step-num">3</span> ยืนยัน
            </div>
        </div>

        <form id="cwForm" enctype="multipart/form-data" onsubmit="return false;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">

            <!-- 3 drop zones -->
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:.85rem; margin-bottom:1rem;">

                <label class="cw-drop" data-key="staff">
                    <div class="cw-drop-icon" style="background:#fef3c7; color:#d97706;">
                        <i class="fa-solid fa-user-tie"></i>
                    </div>
                    <div class="cw-drop-title">บุคลากร</div>
                    <div class="cw-drop-sub" id="cwName_staff">.csv (ขนาดไม่เกิน 5MB)</div>
                    <input type="file" name="staff_file" id="cwFile_staff" accept=".csv,.txt" hidden onchange="cwOnFile('staff')">
                </label>

                <label class="cw-drop" data-key="student">
                    <div class="cw-drop-icon" style="background:#dbeafe; color:#2563eb;">
                        <i class="fa-solid fa-user-graduate"></i>
                    </div>
                    <div class="cw-drop-title">นักศึกษา</div>
                    <div class="cw-drop-sub" id="cwName_student">.csv (ขนาดไม่เกิน 5MB)</div>
                    <input type="file" name="student_file" id="cwFile_student" accept=".csv,.txt" hidden onchange="cwOnFile('student')">
                </label>

                <label class="cw-drop" data-key="resigned">
                    <div class="cw-drop-icon" style="background:#fee2e2; color:#dc2626;">
                        <i class="fa-solid fa-user-slash"></i>
                    </div>
                    <div class="cw-drop-title">คนออกจากงาน <span style="font-weight:500; color:#94a3b8;">(ถ้ามี)</span></div>
                    <div class="cw-drop-sub" id="cwName_resigned">.csv (ขนาดไม่เกิน 5MB)</div>
                    <input type="file" name="resigned_file" id="cwFile_resigned" accept=".csv,.txt" hidden onchange="cwOnFile('resigned')">
                </label>
            </div>

            <div style="display:flex; align-items:center; gap:.65rem; flex-wrap:wrap; justify-content:center; margin-bottom:1rem; font-size:.78rem; padding:.65rem .9rem; background:#f8fafc; border:1px dashed #cbd5e1; border-radius:.65rem;">
                <span style="color:#475569; font-weight:800;"><i class="fa-solid fa-download mr-1" style="color:#64748b;"></i> ดาวน์โหลดเทมเพลต:</span>
                <a href="#" onclick="cwDownloadTemplate('staff'); return false;" style="color:#d97706; font-weight:800; text-decoration:none;">บุคลากร</a>
                <span style="color:#cbd5e1;">·</span>
                <a href="#" onclick="cwDownloadTemplate('student'); return false;" style="color:#2563eb; font-weight:800; text-decoration:none;">นักศึกษา</a>
                <span style="color:#cbd5e1;">·</span>
                <a href="#" onclick="cwDownloadTemplate('resigned'); return false;" style="color:#dc2626; font-weight:800; text-decoration:none;">คนออก</a>
            </div>

            <div style="background:#fef9c3; border:1px solid #fde68a; border-radius:.65rem; padding:.75rem 1rem; font-size:.78rem; color:#78350f; margin-bottom:1rem; line-height:1.55;">
                <i class="fa-solid fa-info-circle mr-1"></i>
                <strong>กฎการรวม:</strong>
                บุคลากรที่ตรงกับนักศึกษา (citizen_id หรือ member_id เดียวกัน) → <strong>เก็บเป็นบุคลากร</strong> ·
                ใครที่อยู่ในไฟล์คนออก → <strong>ถูกตัดทิ้งจากรายชื่อสุดท้าย</strong>
            </div>

            <div id="cwAlert" style="display:none; margin-bottom:1rem;"></div>

            <div style="display:flex; gap:.5rem; justify-content:flex-end; flex-wrap:wrap;">
                <button type="button" onclick="cwReset()" class="reg-btn-secondary">
                    <i class="fa-solid fa-rotate-left"></i> ล้าง
                </button>
                <button type="button" id="cwBtnPreview" class="reg-btn-primary" disabled onclick="cwPreview()">
                    <i class="fa-solid fa-eye"></i> วิเคราะห์ & พรีวิว
                </button>
            </div>
        </form>

        <!-- Preview panel -->
        <div id="cwPreview" style="display:none; margin-top:1.25rem;"></div>
    </div>

    <!-- ─────────────── Add single member (เข้าใหม่กลางเทอม) ─────────────── -->
    <div style="background:#fff; border-radius:1rem; box-shadow:0 4px 14px rgba(0,0,0,.05); padding:1.5rem 1.75rem; margin-bottom:1.25rem;">
        <div style="display:flex; align-items:center; gap:.75rem; margin-bottom:1.25rem;">
            <i class="fa-solid fa-user-plus" style="font-size:1.4rem; color:#10b981;"></i>
            <div>
                <h3 style="margin:0; font-weight:800; color:#0f172a;">เพิ่มรายชื่อทีละคน</h3>
                <p style="margin:.2rem 0 0 0; font-size:.78rem; color:#64748b;">
                    สำหรับนักศึกษา/บุคลากรเข้าใหม่กลางเทอม — ระบบจะสร้าง batch ส่งให้คลินิกตรวจสอบเหมือนการอัพโหลดไฟล์
                </p>
            </div>
        </div>

        <form id="asForm" onsubmit="return false;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
            <input type="hidden" name="action" value="add_single">

            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:.85rem;">
                <div>
                    <label class="as-label">ประเภท <span class="as-req">*</span></label>
                    <select name="member_status" class="as-input" required>
                        <option value="">-- เลือกประเภท --</option>
                        <option value="นักศึกษา">นักศึกษา</option>
                        <option value="บุคลากร">บุคลากร</option>
                    </select>
                </div>
                <div>
                    <label class="as-label">รหัสนักศึกษา/บุคลากร <span class="as-req">*</span></label>
                    <input type="text" name="member_id" class="as-input" required maxlength="20" placeholder="เช่น 6612345">
                </div>
                <div style="grid-column:1/-1;">
                    <label class="as-label">ชื่อ-นามสกุล (พร้อมคำนำหน้า) <span class="as-req">*</span></label>
                    <input type="text" name="full_name" class="as-input" required maxlength="255" placeholder="เช่น นายสมชาย ใจดี">
                </div>
                <div>
                    <label class="as-label">เลขบัตรประชาชน (13 หลัก)</label>
                    <input type="text" name="citizen_id" class="as-input" maxlength="17" placeholder="1-2345-67890-12-3">
                </div>
                <div>
                    <label class="as-label">วันเกิด</label>
                    <input type="date" name="date_of_birth" class="as-input">
                </div>
                <div>
                    <label class="as-label">ตำแหน่ง / สังกัด / คณะ</label>
                    <input type="text" name="position" class="as-input" maxlength="100" placeholder="เช่น คณะแพทยศาสตร์">
                </div>
                <div>
                    <label class="as-label">วันเริ่มต้นคุ้มครอง</label>
                    <input type="date" name="coverage_start" class="as-input">
                </div>
                <div>
                    <label class="as-label">วันสิ้นสุดคุ้มครอง</label>
                    <input type="date" name="coverage_end" class="as-input">
                </div>
                <div style="grid-column:1/-1;">
                    <label class="as-label">หมายเหตุ</label>
                    <textarea name="remarks" class="as-input" rows="2" placeholder="เช่น เข้าใหม่กลางเทอม 2/2568"></textarea>
                </div>
            </div>

            <div style="display:flex; gap:.5rem; justify-content:flex-end; margin-top:1rem;">
                <button type="button" onclick="asReset()" class="reg-btn-secondary">
                    <i class="fa-solid fa-rotate-left"></i> ล้าง
                </button>
                <button type="button" onclick="asSubmit()" class="reg-btn-primary" style="background:#10b981;">
                    <i class="fa-solid fa-user-plus"></i> เพิ่มรายชื่อ
                </button>
            </div>
        </form>
    </div>

    <!-- ─────────────── Advanced: legacy single-file ─────────────── -->
    <details style="background:#fff; border-radius:1rem; box-shadow:0 4px 14px rgba(0,0,0,.05); padding:1rem 1.5rem;">
        <summary style="cursor:pointer; font-weight:800; color:#475569; font-size:.9rem; list-style:none;">
            <i class="fa-solid fa-screwdriver-wrench mr-1" style="color:#94a3b8;"></i>
            โหมดเดิม — อัพโหลดทีละไฟล์ (Advanced)
            <span style="font-weight:500; font-size:.78rem; color:#94a3b8; margin-left:.5rem;">
                (สำหรับกรณีพิเศษ — แนะนำใช้โหมดรวมด้านบน)
            </span>
        </summary>

        <div style="margin-top:1rem;">
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:.65rem; margin-bottom:1rem;">
                <button type="button" class="reg-mode-card active" data-mode="student" onclick="regSetMode('student')">
                    <div class="reg-icon" style="background:#dbeafe; color:#2563eb;"><i class="fa-solid fa-user-graduate"></i></div>
                    <div><div class="reg-card-title">นักศึกษา</div><div class="reg-card-sub">Full Sync</div></div>
                </button>
                <button type="button" class="reg-mode-card" data-mode="staff" onclick="regSetMode('staff')">
                    <div class="reg-icon" style="background:#fef3c7; color:#d97706;"><i class="fa-solid fa-user-tie"></i></div>
                    <div><div class="reg-card-title">บุคลากร</div><div class="reg-card-sub">Full Sync</div></div>
                </button>
                <button type="button" class="reg-mode-card" data-mode="resigned" onclick="regSetMode('resigned')">
                    <div class="reg-icon" style="background:#fee2e2; color:#dc2626;"><i class="fa-solid fa-user-slash"></i></div>
                    <div><div class="reg-card-title">คนออก</div><div class="reg-card-sub">Append/Inactive</div></div>
                </button>
            </div>

            <form id="regUploadForm" enctype="multipart/form-data" onsubmit="regSubmit(event)">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
                <input type="hidden" name="action" value="upload">
                <input type="hidden" name="upload_mode" id="regUploadMode" value="full_sync">

                <label for="regFileInput" id="regDrop"
                       style="display:flex; flex-direction:column; align-items:center; justify-content:center; gap:.5rem; padding:1.75rem 1rem; border:2.5px dashed #cffafe; border-radius:1rem; background:#f0fdfa; cursor:pointer; transition:all .15s;">
                    <i class="fa-solid fa-file-csv" style="font-size:2rem; color:#0891b2;"></i>
                    <div style="font-weight:700; color:#0f172a;">คลิกเพื่อเลือกไฟล์ — <span id="regModeLabel" style="color:#0891b2;">นักศึกษา</span></div>
                    <div id="regFileName" style="font-size:.82rem; color:#64748b;">รองรับเฉพาะ .csv (ขนาดไม่เกิน 5MB)</div>
                </label>
                <input type="file" name="insurance_file" id="regFileInput" accept=".csv,.txt" required style="display:none;" onchange="regOnFileSelected()">

                <div id="regAlert" style="display:none; margin-top:.85rem;"></div>

                <div style="display:flex; gap:.5rem; justify-content:flex-end; margin-top:1rem;">
                    <button type="reset" onclick="regResetForm()" class="reg-btn-secondary">
                        <i class="fa-solid fa-rotate-left"></i> ล้าง
                    </button>
                    <button type="submit" id="regSubmitBtn" class="reg-btn-primary" disabled>
                        <i class="fa-solid fa-cloud-arrow-up"></i> อัพโหลด
                    </button>
                </div>
            </form>
        </div>
    </details>

    <!-- Format reference -->
    <div style="background:#fff; border-radius:1rem; box-shadow:0 4px 14px rgba(0,0,0,.05); padding:1.25rem 1.75rem; margin-top:1.25rem;">
        <h3 style="margin:0 0 .85rem 0; font-weight:800; color:#0f172a; font-size:.95rem;">
            <i class="fa-solid fa-circle-info mr-1" style="color:#06b6d4;"></i> รูปแบบไฟล์ CSV — รับชื่อคอลัมน์ภาษาไทยตรงๆ
        </h3>
        <p style="font-size:.82rem; color:#475569; margin:0 0 .85rem 0; line-height:1.6;">
            ระบบรู้จักคอลัมน์ตามไฟล์ทะเบียนปัจจุบัน — ไม่ต้องเปลี่ยนชื่อ header
            <strong style="color:#0f172a;">ตัด section title (เช่น "มหาวิทยาลัยรังสิต รายชื่อบุคลากร...") ออกก่อน</strong>
            ให้แถวแรกเป็น header จริงๆ
        </p>
        <table style="width:100%; border-collapse:collapse; font-size:.8rem;">
            <thead>
                <tr style="background:#f0f9ff;">
                    <th style="padding:.5rem .75rem; text-align:left; font-weight:700; color:#075985;">ฟิลด์ในระบบ</th>
                    <th style="padding:.5rem .75rem; text-align:left; font-weight:700; color:#075985;">ชื่อคอลัมน์ที่รับ</th>
                    <th style="padding:.5rem .75rem; text-align:left; font-weight:700; color:#075985;">หมายเหตุ</th>
                </tr>
            </thead>
            <tbody>
                <tr><td style="padding:.5rem .75rem;"><code>member_id</code></td><td><code>รหัสนักศึกษา</code> / <code>รหัสบุคลากร</code> / <code>รหัสพนักงาน</code> / <code>รหัส</code> / <code>STUDENT_CODE</code></td><td><strong>บังคับ</strong> สำหรับ staff/student</td></tr>
                <tr><td style="padding:.5rem .75rem;"><code>citizen_id</code></td><td><code>เลขบัตรประชาชน</code> / <code>หมายเลขประจำตัวประชาชน</code> / <code>เลขบัตร</code> / <code>ID_CARD_NO</code></td><td>13 หลัก รับรูปมี <code>-</code> คั่น เช่น <code>3-8206-00056-99-3</code></td></tr>
                <tr><td style="padding:.5rem .75rem;"><code>full_name</code> หรือ<br>แยก 3 คอลัมน์</td><td><code>ชื่อพนักงาน</code> (รวม) <strong>หรือ</strong> <code>คำนำหน้า</code> + <code>ชื่อ</code> + <code>นามสกุล</code> / <code>สกุล</code></td><td>แยกหรือรวมก็ได้ — ระบบประกอบให้</td></tr>
                <tr><td style="padding:.5rem .75rem;"><code>position</code></td><td><code>ตำแหน่ง</code> / <code>สังกัด</code> / <code>สาขา</code> / <code>คณะ</code> / <code>หน่วยงาน</code></td><td>ฟิลด์เดียวกันในระบบ</td></tr>
                <tr><td style="padding:.5rem .75rem;"><code>date_of_birth</code></td><td><code>วันเดือนปีเกิด</code> / <code>วันเดือนปี เกิด</code> / <code>วันเกิด</code> / <code>BIRTHDAY</code></td><td>รองรับ ค.ศ. M/D/Y, พ.ศ. d/m/y, ชื่อเดือนไทย เช่น <code>8 มีนาคม 1977</code>, <code>4/8/2505</code></td></tr>
                <tr><td style="padding:.5rem .75rem;"><code>resign_date</code></td><td><code>วันที่ออก</code> / <code>วันลาออก</code> (เฉพาะไฟล์คนออก)</td><td>จะเขียนเป็น <code>coverage_end</code> + แปะหมายเหตุ "ออกเมื่อ ..."</td></tr>
            </tbody>
        </table>

        <div style="margin-top:1rem; background:#ecfdf5; border:1px solid #86efac; border-radius:.65rem; padding:.75rem 1rem; font-size:.8rem; color:#065f46; line-height:1.6;">
            <strong><i class="fa-solid fa-circle-check mr-1"></i> ระบบรองรับอัตโนมัติ:</strong>
            ปี พ.ศ. (≥2400 หาร 543) · ชื่อเดือนภาษาไทย (มกราคม...ธันวาคม + ม.ค...ธ.ค.) ·
            เลขบัตรมี <code>-</code> หรือเว้นวรรค · ชื่อรวมในคอลัมน์เดียว
        </div>
        <div style="margin-top:.5rem; background:#fef2f2; border:1px solid #fca5a5; border-radius:.65rem; padding:.75rem 1rem; font-size:.8rem; color:#991b1b; line-height:1.6;">
            <strong><i class="fa-solid fa-triangle-exclamation mr-1"></i> ระวัง — ข้อมูลที่ระบบกู้ไม่ได้:</strong>
            เลขบัตรเป็น scientific notation (<code>3.1301E+12</code>) จาก Excel — แปลว่าหลักท้ายหายแล้ว
            ต้อง re-export โดย format คอลัมน์เป็น <strong>Text</strong> ก่อน
        </div>
    </div>
</div>

<style>
.cw-step {
    display:inline-flex; align-items:center; gap:.4rem;
    padding:.45rem .85rem; border-radius:99px;
    background:#f1f5f9; color:#64748b; border:1px solid #e2e8f0;
}
.cw-step-active { background:#0891b2; color:#fff; border-color:#0891b2; }
.cw-step-num { width:1.25rem; height:1.25rem; border-radius:99px; background:rgba(255,255,255,.25); display:inline-flex; align-items:center; justify-content:center; font-size:.7rem; }
.cw-step:not(.cw-step-active) .cw-step-num { background:#e2e8f0; color:#64748b; }
.cw-step-line { flex:1; height:2px; background:#e2e8f0; max-width:2.5rem; }

.cw-drop {
    display:flex; flex-direction:column; align-items:center; gap:.45rem;
    padding:1.4rem 1rem;
    background:#fff;
    border:2.5px dashed #cbd5e1;
    border-radius:1rem;
    cursor:pointer;
    text-align:center;
    transition:all .15s;
}
.cw-drop:hover { border-color:#06b6d4; background:#ecfeff; }
.cw-drop.has-file { border-color:#10b981; border-style:solid; background:#ecfdf5; }
.cw-drop-icon { width:2.75rem; height:2.75rem; border-radius:.75rem; display:flex; align-items:center; justify-content:center; font-size:1.15rem; }
.cw-drop-title { font-weight:800; color:#0f172a; font-size:.92rem; }
.cw-drop-sub { font-size:.75rem; color:#64748b; }
.cw-drop.has-file .cw-drop-sub { color:#059669; font-weight:700; }

.cw-stat-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:.65rem; margin-bottom:1rem; }
.cw-stat { background:#f8fafc; border:1px solid #e2e8f0; border-radius:.75rem; padding:.85rem; }
.cw-stat-label { font-size:.7rem; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.05em; }
.cw-stat-value { font-size:1.5rem; font-weight:900; color:#0f172a; margin-top:.15rem; line-height:1; }
.cw-stat.cw-warn .cw-stat-value { color:#d97706; }
.cw-stat.cw-danger .cw-stat-value { color:#dc2626; }
.cw-stat.cw-success .cw-stat-value { color:#059669; }

.cw-table-wrap { background:#fff; border:1px solid #e2e8f0; border-radius:.75rem; overflow:hidden; margin-top:.85rem; }
.cw-table-head { padding:.7rem 1rem; background:#f1f5f9; font-weight:800; font-size:.82rem; color:#0f172a; border-bottom:1px solid #e2e8f0; }
.cw-table-body { max-height:280px; overflow:auto; }
.cw-table { width:100%; border-collapse:collapse; font-size:.78rem; }
.cw-table th, .cw-table td { padding:.45rem .75rem; text-align:left; border-bottom:1px solid #f1f5f9; }
.cw-table th { background:#fafbfc; font-weight:700; color:#475569; position:sticky; top:0; }

.reg-mode-card { display:flex; align-items:center; gap:.85rem; padding:1.1rem 1.25rem; background:#fff; border:2px solid #e2e8f0; border-radius:1rem; cursor:pointer; text-align:left; transition:all .15s; font-family:'Sarabun',sans-serif; }
.reg-mode-card:hover { border-color:#06b6d4; box-shadow:0 6px 18px rgba(6,182,212,.1); }
.reg-mode-card.active { border-color:#06b6d4; background:#ecfeff; box-shadow:0 6px 18px rgba(6,182,212,.18); }
.reg-icon { width:3rem; height:3rem; border-radius:.8rem; display:flex; align-items:center; justify-content:center; font-size:1.25rem; flex-shrink:0; }
.reg-card-title { font-weight:800; color:#0f172a; font-size:.95rem; }
.reg-card-sub { font-size:.78rem; color:#64748b; margin-top:.15rem; }
#regDrop.dragover { border-color:#06b6d4; background:#cffafe; }
.reg-btn-primary, .reg-btn-secondary { display:inline-flex; align-items:center; gap:.5rem; padding:.7rem 1.4rem; border-radius:.65rem; font-weight:700; font-size:.9rem; cursor:pointer; border:none; font-family:'Sarabun',sans-serif; transition:opacity .15s, background .15s; }
.reg-btn-primary { background:#0891b2; color:#fff; }
.reg-btn-primary:hover:not(:disabled) { background:#0e7490; }
.reg-btn-primary:disabled { opacity:.5; cursor:not-allowed; }
.reg-btn-secondary { background:#e2e8f0; color:#1e293b; }
.reg-btn-secondary:hover { background:#cbd5e1; }
.reg-alert-success { background:#ecfdf5; border:1px solid #86efac; color:#065f46; padding:.85rem 1rem; border-radius:.65rem; }
.reg-alert-error { background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; padding:.85rem 1rem; border-radius:.65rem; }
.reg-alert-info { background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; padding:.85rem 1rem; border-radius:.65rem; }

.as-label { display:block; font-size:.78rem; font-weight:700; color:#475569; margin-bottom:.3rem; }
.as-req   { color:#ef4444; }
.as-input {
    width:100%; padding:.55rem .75rem;
    border:1.5px solid #e2e8f0; border-radius:.5rem;
    font-family:'Sarabun',sans-serif; font-size:.88rem;
    background:#fff; color:#0f172a;
    transition: border-color .15s, box-shadow .15s;
}
.as-input:focus {
    outline:none; border-color:#10b981;
    box-shadow:0 0 0 3px rgba(16,185,129,.15);
}
</style>

<script>
(function() {
    // ═══════════════════════════════════════════════════════════════════════════
    // Combined Wizard
    // ═══════════════════════════════════════════════════════════════════════════
    const cwFiles = { staff: null, student: null, resigned: null };

    function escHTML(s) {
        return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    }
    function fmt(n) { return (n ?? 0).toLocaleString(); }

    // Download CSV template (UTF-8 BOM for Excel compat)
    window.cwDownloadTemplate = function(kind) {
        const today = new Date().toISOString().slice(0, 10);
        const tpl = {
            staff: {
                name: 'staff_template.csv',
                rows: [
                    ['ลำดับ', 'รหัสพนักงาน', 'คำนำหน้า', 'ชื่อ', 'สกุล', 'ตำแหน่ง', 'สังกัด', 'หมายเลขประจำตัวประชาชน', 'วันเดือนปีเกิด'],
                    ['1', 'RHZ001', 'นางสาว', 'จันจิรา', 'ตรีชัย', 'พนักงานศูนย์ความงาม', 'RSU Horizon', '3-8206-00056-99-3', '8 มีนาคม 1977'],
                    ['2', '2901035', 'น.ส.', 'สุกัลยา', 'วงศ์ชมบุญ', 'ผู้ช่วยคณบดี', 'วิทยาลัยศิลปศาสตร์', '3130100300638', '4/8/2505'],
                ],
            },
            student: {
                name: 'student_template.csv',
                rows: [
                    ['ลำดับ', 'คำนำหน้า', 'ชื่อ', 'นามสกุล', 'เลขบัตรประชาชน', 'รหัสนักศึกษา', 'วันเดือนปี เกิด'],
                    ['1', 'นาย', 'ภฤศธร', 'ภูวภิรมย์ขวัญ', '1100801395116', '6300327', '6/11/2001'],
                    ['2', 'นางสาว', 'นภสร', 'รัตนพร', '1309902900548', '6302774', '9/17/2001'],
                ],
            },
            resigned: {
                name: 'resigned_template.csv',
                rows: [
                    ['member_id', 'citizen_id', 'full_name', 'resign_date'],
                    ['1000099', '1234567890127', 'นายเก่า ลาออก', today],
                    ['1000100', '', 'นางสาวออก จากงาน', today],
                ],
            },
        };
        const t = tpl[kind];
        if (!t) return;
        const csv = t.rows.map(r => r.map(c => {
            const s = String(c ?? '');
            return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
        }).join(',')).join('\r\n') + '\r\n';
        const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url; a.download = t.name;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    };

    function cwSetStep(n) {
        for (let i = 1; i <= 3; i++) {
            const el = document.getElementById('cwStep' + i);
            if (el) el.classList.toggle('cw-step-active', i <= n);
        }
    }

    function cwAlertBox(html, kind) {
        const a = document.getElementById('cwAlert');
        a.className = 'reg-alert-' + (kind || 'info');
        a.innerHTML = html;
        a.style.display = 'block';
    }
    function cwAlertHide() { document.getElementById('cwAlert').style.display = 'none'; }

    window.cwOnFile = function(key) {
        const input = document.getElementById('cwFile_' + key);
        const f = input.files[0];
        cwFiles[key] = f || null;
        const drop = document.querySelector('.cw-drop[data-key="' + key + '"]');
        const sub  = document.getElementById('cwName_' + key);
        if (f) {
            drop.classList.add('has-file');
            sub.textContent = f.name + ' · ' + (f.size / 1024).toFixed(1) + ' KB';
        } else {
            drop.classList.remove('has-file');
            sub.textContent = '.csv (ขนาดไม่เกิน 5MB)';
        }
        // Enable preview button when at least staff or student is present
        document.getElementById('cwBtnPreview').disabled = !(cwFiles.staff || cwFiles.student);
    };

    window.cwReset = function() {
        ['staff', 'student', 'resigned'].forEach(k => {
            document.getElementById('cwFile_' + k).value = '';
            cwOnFile(k);
        });
        document.getElementById('cwPreview').style.display = 'none';
        document.getElementById('cwPreview').innerHTML = '';
        cwAlertHide();
        cwSetStep(1);
    };

    window.cwPreview = async function() {
        const btn = document.getElementById('cwBtnPreview');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> กำลังวิเคราะห์...';
        cwAlertHide();

        const fd = new FormData(document.getElementById('cwForm'));
        fd.append('action', 'upload_combined');
        fd.append('mode', 'preview');

        try {
            const res = await fetch('ajax_insurance_sync.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.status !== 'ok') {
                throw new Error(data.message || 'วิเคราะห์ไม่สำเร็จ');
            }
            cwRenderPreview(data);
            cwSetStep(2);
        } catch (e) {
            cwAlertBox('<strong><i class="fa-solid fa-circle-exclamation mr-1"></i> ผิดพลาด:</strong> ' + escHTML(e.message), 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-eye"></i> วิเคราะห์ & พรีวิว';
        }
    };

    function cwRenderPreview(p) {
        window._cwLastPreview = p;
        const s = p.summary || {};
        const dups = p.duplicates_sample || [];
        const drops = p.dropped_sample || [];
        const finals = p.final_sample || [];

        const stats = `
            <div class="cw-stat-grid">
                <div class="cw-stat"><div class="cw-stat-label">บุคลากร (ไฟล์)</div><div class="cw-stat-value">${fmt(s.staff_in_file)}</div></div>
                <div class="cw-stat"><div class="cw-stat-label">นักศึกษา (ไฟล์)</div><div class="cw-stat-value">${fmt(s.student_in_file)}</div></div>
                <div class="cw-stat"><div class="cw-stat-label">คนออก (ไฟล์)</div><div class="cw-stat-value">${fmt(s.resigned_in_file)}</div></div>
                <div class="cw-stat cw-warn"><div class="cw-stat-label">ซ้ำ (รวมเป็น 1)</div><div class="cw-stat-value">${fmt(s.duplicates)}</div></div>
                <div class="cw-stat cw-danger"><div class="cw-stat-label">ตัดออก (คนออก)</div><div class="cw-stat-value">${fmt(s.dropped_leavers)}</div></div>
                <div class="cw-stat cw-success"><div class="cw-stat-label">คงเหลือสุดท้าย</div><div class="cw-stat-value">${fmt(s.final_count)}</div></div>
            </div>`;

        const dupTable = dups.length ? `
            <div class="cw-table-wrap">
                <div class="cw-table-head"><i class="fa-solid fa-clone mr-1" style="color:#d97706;"></i> รายการซ้ำ บุคลากร ↔ นักศึกษา (${dups.length}${dups.length === 50 ? '+' : ''} รายการ)</div>
                <div class="cw-table-body">
                    <table class="cw-table">
                        <thead><tr><th>รหัสบุคลากร</th><th>รหัส นศ.</th><th>เลขบัตร</th><th>ชื่อ</th></tr></thead>
                        <tbody>${dups.map(d => `
                            <tr>
                                <td><code>${escHTML(d.staff_member_id)}</code></td>
                                <td><code style="color:#94a3b8;">${escHTML(d.student_member_id)}</code></td>
                                <td>${escHTML(d.citizen_id)}</td>
                                <td>${escHTML(d.full_name)}</td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>
            </div>` : '';

        const matchLabel = (m) => {
            if (m === 'citizen_id') return '<span style="color:#0891b2; font-weight:700;">ตรงเลขบัตร</span>';
            if (m === 'member_id')  return '<span style="color:#7c3aed; font-weight:700;">ตรงรหัส</span>';
            return '—';
        };
        const dropTable = drops.length ? `
            <div class="cw-table-wrap">
                <div class="cw-table-head"><i class="fa-solid fa-user-slash mr-1" style="color:#dc2626;"></i> ถูกตัดทิ้งเพราะอยู่ในไฟล์คนออก (${drops.length}${drops.length === 50 ? '+' : ''} รายการแรก)</div>
                <div class="cw-table-body">
                    <table class="cw-table">
                        <thead><tr><th>มาจาก</th><th>รหัส</th><th>เลขบัตร</th><th>ชื่อ</th><th>เหตุผล</th><th>วันที่ออก</th></tr></thead>
                        <tbody>${drops.map(d => `
                            <tr>
                                <td>${d._dropped_from === 'staff' ? '<span style="color:#d97706;">บุคลากร</span>' : '<span style="color:#2563eb;">นักศึกษา</span>'}</td>
                                <td><code>${escHTML(d.member_id)}</code></td>
                                <td>${escHTML(d.citizen_id)}</td>
                                <td>${escHTML(d.full_name)}</td>
                                <td>${matchLabel(d._match)}<div style="font-size:.7rem; color:#94a3b8; font-weight:500;">${escHTML(d._match_value || '')}</div></td>
                                <td>${escHTML(d._resign_date || '—')}</td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>
            </div>` : '';

        const finalTable = finals.length ? `
            <div class="cw-table-wrap">
                <div class="cw-table-head"><i class="fa-solid fa-list-check mr-1" style="color:#059669;"></i> ตัวอย่างรายชื่อสุดท้าย (50 รายการแรก)</div>
                <div class="cw-table-body">
                    <table class="cw-table">
                        <thead><tr><th>ประเภท</th><th>รหัส</th><th>เลขบัตร</th><th>ชื่อ</th><th>หมายเหตุ</th></tr></thead>
                        <tbody>${finals.map(f => `
                            <tr>
                                <td>${escHTML(f.member_status || '')}</td>
                                <td><code>${escHTML(f.member_id)}</code></td>
                                <td>${escHTML(f.citizen_id)}</td>
                                <td>${escHTML(f.full_name || '')}</td>
                                <td style="color:#64748b;">${escHTML(f.remarks || '')}</td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>
            </div>` : '';

        const aiResultBox = `<div id="cwAiResult" style="margin-top:.85rem;"></div>`;

        const actionBar = `
            <div style="display:flex; gap:.5rem; justify-content:space-between; align-items:center; flex-wrap:wrap; margin-top:1.25rem;">
                <button type="button" id="cwBtnAiReview" onclick="cwAiReview()"
                    style="display:inline-flex; align-items:center; gap:.5rem; padding:.7rem 1.2rem; border-radius:.65rem; font-weight:700; font-size:.85rem; cursor:pointer; border:1px solid #c4b5fd; background:#faf5ff; color:#6b21a8;">
                    <i class="fa-solid fa-robot"></i> ขอ AI ช่วยตรวจ
                    <span style="font-size:.7rem; color:#a78bfa; font-weight:500;">(Gemini · PDPA-safe)</span>
                </button>
                <div style="display:flex; gap:.5rem;">
                    <button type="button" onclick="cwReset()" class="reg-btn-secondary">
                        <i class="fa-solid fa-rotate-left"></i> เริ่มใหม่
                    </button>
                    <button type="button" id="cwBtnCommit" class="reg-btn-primary" onclick="cwCommit()">
                        <i class="fa-solid fa-check"></i> ยืนยันและบันทึก (${fmt(s.final_count)} รายการ)
                    </button>
                </div>
            </div>`;

        document.getElementById('cwPreview').innerHTML = stats + dupTable + dropTable + finalTable + aiResultBox + actionBar;
        document.getElementById('cwPreview').style.display = 'block';
    }

    // ── PDPA-safe masking helpers ───────────────────────────────────────────────
    function maskCid(cid) {
        const s = String(cid || '').replace(/\D/g, '');
        if (s.length !== 13) return '—';
        return s[0] + 'X'.repeat(11) + s[12];
    }
    function nameInitials(name) {
        const s = String(name || '').trim();
        if (!s) return '—';
        // Keep prefix as-is, abbreviate first/last names
        const prefixes = ['น.ส.', 'นางสาว', 'นาย', 'นาง', 'ดร.', 'ผศ.', 'รศ.', 'ศ.', 'อ.', 'Mr.', 'Mrs.', 'Ms.', 'Miss'];
        let work = s;
        let prefix = '';
        for (const p of prefixes) {
            if (work.startsWith(p)) {
                prefix = p;
                work = work.slice(p.length).trim();
                break;
            }
        }
        const parts = work.split(/\s+/).filter(Boolean);
        const abbr = parts.map(p => (p[0] || '') + '.').join(' ');
        return (prefix + abbr).trim() || '—';
    }

    window.cwAiReview = async function() {
        const btn = document.getElementById('cwBtnAiReview');
        const box = document.getElementById('cwAiResult');
        if (!window._cwLastPreview) { return; }

        btn.disabled = true;
        const origText = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> AI กำลังวิเคราะห์...';
        box.innerHTML = '';

        const p = window._cwLastPreview;
        const sample = (p.final_sample || []).slice(0, 15).map(r => ({
            member_id:    r.member_id,
            citizen_id:   maskCid(r.citizen_id),
            name:         nameInitials(r.full_name),
            member_status: r.member_status || '',
            position:     r.position || '',
            date_of_birth: r.date_of_birth || '',
            remarks:      (r.remarks || '').slice(0, 80),
        }));

        const csrfToken = document.querySelector('#cwForm input[name="csrf_token"]')?.value || '';
        const fd = new FormData();
        fd.append('action', 'ai_review');
        fd.append('csrf_token', csrfToken);
        fd.append('summary', JSON.stringify(p.summary || {}));
        fd.append('sample',  JSON.stringify(sample));

        try {
            const res = await fetch('ajax_insurance_sync.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.status !== 'ok') throw new Error(data.message || 'AI ตอบไม่ได้');
            const tokens = data.tokens ? ` · ${data.tokens.toLocaleString()} tokens` : '';
            box.innerHTML = `
                <div style="background:#faf5ff; border:1px solid #c4b5fd; border-radius:.85rem; overflow:hidden;">
                    <div style="padding:.7rem 1rem; background:#ede9fe; color:#6b21a8; font-weight:800; font-size:.82rem; display:flex; align-items:center; justify-content:space-between;">
                        <span><i class="fa-solid fa-robot mr-1"></i> AI Review (Gemini 2.5 Flash)</span>
                        <span style="font-size:.7rem; color:#a78bfa; font-weight:500;">PDPA-safe · masked sample${tokens}</span>
                    </div>
                    <div class="ai-review-body">${marked.parse(data.review)}</div>
                </div>`;
        } catch (e) {
            box.innerHTML = `<div class="reg-alert-error"><strong><i class="fa-solid fa-circle-exclamation mr-1"></i> ผิดพลาด:</strong> ${escHTML(e.message)}</div>`;
        } finally {
            btn.disabled = false;
            btn.innerHTML = origText;
        }
    };

    window.cwCommit = async function() {
        const { isConfirmed } = await Swal.fire({
            title: 'ยืนยันบันทึก?',
            text: 'บันทึกรายชื่อสุดท้ายเป็น batch ใหม่',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: '<i class="fa-solid fa-check mr-1"></i> ยืนยัน',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#0f766e',
            reverseButtons: true,
        });
        if (!isConfirmed) return;

        const btn = document.getElementById('cwBtnCommit');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> กำลังบันทึก...';
        cwAlertHide();

        const fd = new FormData(document.getElementById('cwForm'));
        fd.append('action', 'upload_combined');
        fd.append('mode', 'commit');

        try {
            const res = await fetch('ajax_insurance_sync.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.status !== 'ok') {
                throw new Error(data.message || 'บันทึกไม่สำเร็จ');
            }
            const s = data.summary || {};
            cwSetStep(3);
            cwAlertBox(`
                <strong><i class="fa-solid fa-circle-check mr-1"></i> บันทึกสำเร็จ</strong>
                <ul style="margin-top:.5rem; font-size:.85rem; line-height:1.6;">
                    <li>Batch: <code>${escHTML(data.batch_code || '—')}</code> · sync_id #${data.sync_id}</li>
                    <li>รายชื่อสุดท้าย: <strong>${fmt(s.final_count)}</strong> รายการ</li>
                    <li>เพิ่มใหม่ ${fmt(s.new)} · อัพเดท ${fmt(s.updated)} · Inactive ${fmt(s.inactivated)}${s.protected ? ' · Protected ' + fmt(s.protected) : ''}</li>
                    <li>ซ้ำที่ถูกรวม: ${fmt(s.duplicates)} · ตัดเพราะคนออก: ${fmt(s.dropped_leavers)}</li>
                </ul>`, 'success');
            btn.style.display = 'none';
        } catch (e) {
            cwAlertBox('<strong><i class="fa-solid fa-circle-exclamation mr-1"></i> ผิดพลาด:</strong> ' + escHTML(e.message), 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-check"></i> ลองใหม่';
        }
    };

    // ═══════════════════════════════════════════════════════════════════════════
    // Legacy single-file mode (advanced)
    // ═══════════════════════════════════════════════════════════════════════════
    let currentMode = 'student';

    window.regSetMode = function(mode) {
        currentMode = mode;
        document.querySelectorAll('.reg-mode-card').forEach(c => c.classList.toggle('active', c.dataset.mode === mode));
        const labels = { student: 'นักศึกษา', staff: 'บุคลากร', resigned: 'คนออก' };
        document.getElementById('regModeLabel').textContent = labels[mode] || mode;
        document.getElementById('regUploadMode').value = (mode === 'resigned') ? 'append' : 'full_sync';
    };

    window.regOnFileSelected = function() {
        const f = document.getElementById('regFileInput').files[0];
        if (!f) return;
        document.getElementById('regFileName').textContent = `${f.name} (${(f.size / 1024).toFixed(1)} KB)`;
        document.getElementById('regSubmitBtn').disabled = false;
    };

    window.regResetForm = function() {
        document.getElementById('regUploadForm').reset();
        document.getElementById('regFileName').textContent = 'รองรับเฉพาะ .csv (ขนาดไม่เกิน 5MB)';
        document.getElementById('regSubmitBtn').disabled = true;
        document.getElementById('regAlert').style.display = 'none';
        regSetMode('student');
    };

    window.regSubmit = async function(e) {
        e.preventDefault();
        const form = document.getElementById('regUploadForm');
        const fd = new FormData(form);
        const btn = document.getElementById('regSubmitBtn');
        const alertBox = document.getElementById('regAlert');

        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> กำลังอัพโหลด...';
        alertBox.style.display = 'none';

        try {
            const res = await fetch('ajax_insurance_sync.php', { method: 'POST', body: fd });
            const data = await res.json();

            if (data.status === 'ok') {
                const summary = data;
                alertBox.className = 'reg-alert-success';
                alertBox.innerHTML = `
                    <strong><i class="fa-solid fa-circle-check mr-1"></i> อัพโหลดสำเร็จ</strong>
                    <ul style="margin-top:.5rem; font-size:.85rem;">
                        <li>รายการในไฟล์: ${(summary.total_csv ?? summary.total ?? 0).toLocaleString()}</li>
                        <li>เพิ่มใหม่: ${(summary.new ?? summary.cnt_new ?? 0).toLocaleString()}</li>
                        <li>อัพเดท: ${(summary.updated ?? summary.cnt_updated ?? 0).toLocaleString()}</li>
                        <li>เปลี่ยนเป็น Inactive: ${(summary.inactivated ?? summary.cnt_inactivated ?? 0).toLocaleString()}</li>
                    </ul>`;
            } else {
                alertBox.className = 'reg-alert-error';
                alertBox.innerHTML = `<strong><i class="fa-solid fa-circle-exclamation mr-1"></i> ผิดพลาด:</strong> ${data.message || 'ไม่ทราบสาเหตุ'}`;
            }
        } catch (err) {
            alertBox.className = 'reg-alert-error';
            alertBox.innerHTML = `<strong>เกิดข้อผิดพลาด:</strong> ${err.message}`;
        }
        alertBox.style.display = 'block';

        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-cloud-arrow-up"></i> อัพโหลด';
    };

    const drop = document.getElementById('regDrop');
    if (drop) {
        ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('dragover'); }));
        ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('dragover'); }));
        drop.addEventListener('drop', e => {
            if (e.dataTransfer.files.length) {
                document.getElementById('regFileInput').files = e.dataTransfer.files;
                regOnFileSelected();
            }
        });
    }

    // ── Drag-drop for Combined Wizard zones ────────────────────────────────────
    document.querySelectorAll('.cw-drop').forEach(zone => {
        const key = zone.dataset.key;
        const input = document.getElementById('cwFile_' + key);
        ['dragenter', 'dragover'].forEach(ev => zone.addEventListener(ev, e => { e.preventDefault(); zone.style.borderColor = '#06b6d4'; }));
        ['dragleave', 'drop'].forEach(ev => zone.addEventListener(ev, e => {
            e.preventDefault();
            if (!zone.classList.contains('has-file')) zone.style.borderColor = '';
        }));
        zone.addEventListener('drop', e => {
            if (e.dataTransfer.files.length) {
                input.files = e.dataTransfer.files;
                cwOnFile(key);
            }
        });
    });

    // ═══════════════════════════════════════════════════════════════════════════
    // Add Single Member (เพิ่มรายชื่อทีละคน)
    // ═══════════════════════════════════════════════════════════════════════════
    window.asReset = function() {
        const f = document.getElementById('asForm');
        if (!f) return;
        f.querySelectorAll('input[type=text], input[type=date], textarea').forEach(el => el.value = '');
        f.querySelector('select[name=member_status]').value = '';
    };

    window.asSubmit = async function() {
        const f = document.getElementById('asForm');
        const memberId   = f.member_id.value.trim();
        const fullName   = f.full_name.value.trim();
        const memberType = f.member_status.value;
        const citizenId  = (f.citizen_id.value || '').replace(/[\s\-]/g, '');

        if (!memberType) { Swal.fire({ icon: 'warning', title: 'กรุณาเลือกประเภท' }); return; }
        if (!memberId)   { Swal.fire({ icon: 'warning', title: 'กรุณากรอกรหัสนักศึกษา/บุคลากร' }); return; }
        if (!fullName)   { Swal.fire({ icon: 'warning', title: 'กรุณากรอกชื่อ-นามสกุล' }); return; }
        if (citizenId !== '' && !/^\d{13}$/.test(citizenId)) {
            Swal.fire({ icon: 'warning', title: 'เลขบัตรประชาชนไม่ถูกต้อง', text: 'ต้องเป็นตัวเลข 13 หลัก' });
            return;
        }

        const { isConfirmed } = await Swal.fire({
            title: 'ยืนยันเพิ่มรายชื่อ?',
            html: `<div style="text-align:left; font-size:.9rem;">
                <div><b>ประเภท:</b> ${escHTML(memberType)}</div>
                <div><b>รหัส:</b> ${escHTML(memberId)}</div>
                <div><b>ชื่อ:</b> ${escHTML(fullName)}</div>
                <div style="margin-top:.5rem; color:#64748b; font-size:.8rem;">
                    ระบบจะสร้าง batch รายเดียว ส่งให้คลินิกตรวจสอบ
                </div>
            </div>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: '<i class="fa-solid fa-check mr-1"></i> ยืนยัน',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#10b981',
            reverseButtons: true,
        });
        if (!isConfirmed) return;

        Swal.fire({ title: 'กำลังบันทึก...', didOpen: () => Swal.showLoading(), allowOutsideClick: false });

        const fd = new FormData(f);
        try {
            const r = await (typeof safeFetch === 'function'
                ? safeFetch('ajax_insurance_sync.php', { method: 'POST', body: fd })
                : fetch('ajax_insurance_sync.php', { method: 'POST', body: fd }).then(r => r.json()));
            Swal.close();
            if (!r) return; // safeFetch already alerted
            if (r.status !== 'ok') {
                Swal.fire({ icon: 'error', title: 'เพิ่มไม่สำเร็จ', text: r.message || 'เกิดข้อผิดพลาด' });
                return;
            }
            await Swal.fire({
                icon: 'success',
                title: 'เพิ่มรายชื่อแล้ว',
                html: `สร้าง batch <code>${escHTML(r.batch_code || '')}</code> ส่งให้คลินิกตรวจสอบ`,
                timer: 2200,
                showConfirmButton: false,
            });
            asReset();
        } catch (e) {
            Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: e.message || 'network error' });
        }
    };
})();
</script>
