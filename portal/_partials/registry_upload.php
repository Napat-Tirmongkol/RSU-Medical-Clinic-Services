<?php
/**
 * portal/_partials/registry_upload.php
 * Minimal upload UI สำหรับฝ่ายทะเบียน (เจ้าหน้าที่ที่มีสิทธิ์ access_registry)
 *
 * รองรับการอัพโหลด CSV 3 ประเภท:
 *   - student   : รายชื่อนักศึกษา
 *   - staff     : รายชื่อบุคลากร
 *   - resigned  : รายชื่อคนออกจากงาน (mark Inactive)
 *
 * ส่งต่อไปที่ ajax_insurance_sync.php (action=upload) ซึ่ง gating ให้ registry user ทำได้เฉพาะ upload
 */
declare(strict_types=1);
?>
<div style="padding:1.5rem 2rem; max-width:1100px; margin:0 auto;">

    <div style="margin-bottom:1.5rem;">
        <h1 style="margin:0; font-size:1.55rem; font-weight:900; color:#0f172a;">
            <i class="fa-solid fa-id-card-clip mr-1" style="color:#06b6d4;"></i>
            อัพโหลดรายชื่อ (ฝ่ายทะเบียน)
        </h1>
        <p style="margin:.35rem 0 0 0; font-size:.85rem; color:#64748b;">
            อัพโหลดรายชื่อนักศึกษา/บุคลากร/คนออกจากงาน เพื่อให้ระบบประกันใช้งาน
        </p>
    </div>

    <!-- ─── Mode selector ─── -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:1rem; margin-bottom:1.5rem;">
        <button type="button" class="reg-mode-card active" data-mode="student" onclick="regSetMode('student')">
            <div class="reg-icon" style="background:#dbeafe; color:#2563eb;">
                <i class="fa-solid fa-user-graduate"></i>
            </div>
            <div>
                <div class="reg-card-title">รายชื่อนักศึกษา</div>
                <div class="reg-card-sub">CSV รายชื่อนักศึกษาปัจจุบัน</div>
            </div>
        </button>
        <button type="button" class="reg-mode-card" data-mode="staff" onclick="regSetMode('staff')">
            <div class="reg-icon" style="background:#fef3c7; color:#d97706;">
                <i class="fa-solid fa-user-tie"></i>
            </div>
            <div>
                <div class="reg-card-title">รายชื่อบุคลากร</div>
                <div class="reg-card-sub">CSV รายชื่อพนักงานปัจจุบัน</div>
            </div>
        </button>
        <button type="button" class="reg-mode-card" data-mode="resigned" onclick="regSetMode('resigned')">
            <div class="reg-icon" style="background:#fee2e2; color:#dc2626;">
                <i class="fa-solid fa-user-slash"></i>
            </div>
            <div>
                <div class="reg-card-title">รายชื่อคนออกจากงาน</div>
                <div class="reg-card-sub">รายการที่จะถูก mark Inactive</div>
            </div>
        </button>
    </div>

    <!-- ─── Upload form ─── -->
    <div style="background:#fff; border-radius:1rem; box-shadow:0 4px 14px rgba(0,0,0,.05); padding:1.75rem;">
        <div style="display:flex; align-items:center; gap:.75rem; margin-bottom:1.25rem;">
            <i class="fa-solid fa-cloud-arrow-up" style="font-size:1.5rem; color:#06b6d4;"></i>
            <h3 style="margin:0; font-weight:800; color:#0f172a;">อัพโหลด CSV — <span id="regModeLabel" style="color:#0891b2;">รายชื่อนักศึกษา</span></h3>
        </div>

        <div id="regAlert" style="display:none; margin-bottom:1rem;"></div>

        <form id="regUploadForm" enctype="multipart/form-data" onsubmit="regSubmit(event)">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
            <input type="hidden" name="action" value="upload">
            <input type="hidden" name="upload_mode" id="regUploadMode" value="full_sync">

            <!-- Drop zone -->
            <label for="regFileInput" id="regDrop"
                   style="display:flex; flex-direction:column; align-items:center; justify-content:center; gap:.5rem; padding:2.5rem 1rem; border:2.5px dashed #cffafe; border-radius:1rem; background:#f0fdfa; cursor:pointer; transition:all .15s;">
                <i class="fa-solid fa-file-csv" style="font-size:2.5rem; color:#0891b2;"></i>
                <div style="font-weight:700; color:#0f172a;">คลิกเพื่อเลือกไฟล์ หรือลากไฟล์มาวาง</div>
                <div id="regFileName" style="font-size:.85rem; color:#64748b;">รองรับเฉพาะ .csv (ขนาดไม่เกิน 5MB)</div>
            </label>
            <input type="file" name="insurance_file" id="regFileInput" accept=".csv,.txt" required
                   style="display:none;" onchange="regOnFileSelected()">

            <div style="display:flex; gap:.5rem; justify-content:flex-end; margin-top:1.25rem;">
                <button type="reset" onclick="regResetForm()" class="reg-btn-secondary">
                    <i class="fa-solid fa-rotate-left"></i> ล้าง
                </button>
                <button type="submit" id="regSubmitBtn" class="reg-btn-primary" disabled>
                    <i class="fa-solid fa-cloud-arrow-up"></i> อัพโหลด
                </button>
            </div>
        </form>
    </div>

    <!-- ─── Format info ─── -->
    <div style="background:#fff; border-radius:1rem; box-shadow:0 4px 14px rgba(0,0,0,.05); padding:1.75rem; margin-top:1.25rem;">
        <h3 style="margin:0 0 .85rem 0; font-weight:800; color:#0f172a;">
            <i class="fa-solid fa-circle-info mr-1" style="color:#06b6d4;"></i> รูปแบบไฟล์ CSV
        </h3>
        <p style="font-size:.85rem; color:#475569;">
            Header บรรทัดแรกต้องประกอบด้วย column ต่อไปนี้:
        </p>
        <table style="width:100%; border-collapse:collapse; font-size:.85rem;">
            <thead>
                <tr style="background:#f0f9ff;">
                    <th style="padding:.55rem .75rem; text-align:left; font-weight:700; color:#075985; border-bottom:1px solid #e0f2fe;">Column</th>
                    <th style="padding:.55rem .75rem; text-align:left; font-weight:700; color:#075985; border-bottom:1px solid #e0f2fe;">จำเป็น</th>
                    <th style="padding:.55rem .75rem; text-align:left; font-weight:700; color:#075985; border-bottom:1px solid #e0f2fe;">คำอธิบาย</th>
                </tr>
            </thead>
            <tbody>
                <tr><td style="padding:.55rem .75rem;"><code>member_id</code></td><td>✅</td><td>รหัสนักศึกษา / รหัสพนักงาน</td></tr>
                <tr><td style="padding:.55rem .75rem;"><code>full_name</code></td><td>✅</td><td>ชื่อ-สกุล</td></tr>
                <tr><td style="padding:.55rem .75rem;"><code>citizen_id</code></td><td>—</td><td>เลขบัตรประชาชน 13 หลัก</td></tr>
                <tr><td style="padding:.55rem .75rem;"><code>date_of_birth</code></td><td>—</td><td>YYYY-MM-DD หรือ DD/MM/YYYY</td></tr>
                <tr><td style="padding:.55rem .75rem;"><code>member_status</code></td><td>—</td><td>นักศึกษา / บุคลากร / etc.</td></tr>
                <tr><td style="padding:.55rem .75rem;"><code>position</code></td><td>—</td><td>ตำแหน่ง / คณะ</td></tr>
            </tbody>
        </table>

        <div style="background:#fef3c7; border:1px solid #fde68a; border-radius:.65rem; padding:.85rem 1rem; margin-top:1rem; font-size:.82rem; color:#78350f;">
            <strong><i class="fa-solid fa-triangle-exclamation mr-1"></i> หมายเหตุการทำงาน:</strong>
            <ul style="margin:.4rem 0 0 1rem; padding:0;">
                <li><strong>รายชื่อนักศึกษา/บุคลากร</strong> — จะ insert/update เป็น Active และ mark รายชื่อที่ไม่อยู่ในไฟล์เป็น Inactive (Full Sync)</li>
                <li><strong>รายชื่อคนออกจากงาน</strong> — จะ mark เฉพาะรายชื่อในไฟล์เป็น Inactive (Append/Deactivate mode)</li>
            </ul>
        </div>
    </div>
</div>

<style>
.reg-mode-card {
    display: flex; align-items: center; gap: .85rem;
    padding: 1.1rem 1.25rem;
    background: #fff;
    border: 2px solid #e2e8f0;
    border-radius: 1rem;
    cursor: pointer;
    text-align: left;
    transition: all .15s;
    font-family: 'Prompt', sans-serif;
}
.reg-mode-card:hover { border-color: #06b6d4; box-shadow: 0 6px 18px rgba(6,182,212,.1); }
.reg-mode-card.active { border-color: #06b6d4; background: #ecfeff; box-shadow: 0 6px 18px rgba(6,182,212,.18); }
.reg-icon {
    width: 3rem; height: 3rem; border-radius: .8rem;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.25rem; flex-shrink: 0;
}
.reg-card-title { font-weight: 800; color: #0f172a; font-size: .95rem; }
.reg-card-sub { font-size: .78rem; color: #64748b; margin-top: .15rem; }

#regDrop.dragover { border-color: #06b6d4; background: #cffafe; }

.reg-btn-primary, .reg-btn-secondary {
    display: inline-flex; align-items: center; gap: .5rem;
    padding: .7rem 1.4rem; border-radius: .65rem;
    font-weight: 700; font-size: .9rem;
    cursor: pointer; border: none;
    font-family: 'Prompt', sans-serif;
    transition: opacity .15s, background .15s;
}
.reg-btn-primary { background: #0891b2; color: #fff; }
.reg-btn-primary:hover:not(:disabled) { background: #0e7490; }
.reg-btn-primary:disabled { opacity: .5; cursor: not-allowed; }
.reg-btn-secondary { background: #e2e8f0; color: #1e293b; }
.reg-btn-secondary:hover { background: #cbd5e1; }

.reg-alert-success { background: #ecfdf5; border: 1px solid #86efac; color: #065f46; padding: .85rem 1rem; border-radius: .65rem; }
.reg-alert-error { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; padding: .85rem 1rem; border-radius: .65rem; }
</style>

<script>
(function() {
    let currentMode = 'student';

    window.regSetMode = function(mode) {
        currentMode = mode;
        document.querySelectorAll('.reg-mode-card').forEach(c => c.classList.toggle('active', c.dataset.mode === mode));
        const labels = { student: 'รายชื่อนักศึกษา', staff: 'รายชื่อบุคลากร', resigned: 'รายชื่อคนออกจากงาน' };
        document.getElementById('regModeLabel').textContent = labels[mode] || mode;
        // ใช้ append mode เมื่อเป็น resigned (mark inactive เฉพาะที่อยู่ในไฟล์ ไม่กระทบคนอื่น)
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

            if (data.ok || data.success) {
                const summary = data.summary || data.data || data;
                const lines = [
                    `<strong><i class="fa-solid fa-circle-check mr-1"></i> อัพโหลดสำเร็จ</strong>`,
                    `<ul style="margin-top:.5rem; font-size:.85rem;">`,
                    `<li>รายการในไฟล์: ${(summary.total_csv ?? summary.total ?? 0).toLocaleString()}</li>`,
                    `<li>เพิ่มใหม่: ${(summary.new ?? summary.cnt_new ?? 0).toLocaleString()}</li>`,
                    `<li>อัพเดท: ${(summary.updated ?? summary.cnt_updated ?? 0).toLocaleString()}</li>`,
                    `<li>เปลี่ยนเป็น Inactive: ${(summary.inactivated ?? summary.cnt_inactivated ?? 0).toLocaleString()}</li>`,
                    `</ul>`,
                ];
                alertBox.className = 'reg-alert-success';
                alertBox.innerHTML = lines.join('');
            } else {
                alertBox.className = 'reg-alert-error';
                alertBox.innerHTML = `<strong><i class="fa-solid fa-circle-exclamation mr-1"></i> ผิดพลาด:</strong> ${data.error || data.message || 'ไม่ทราบสาเหตุ'}`;
            }
        } catch (err) {
            alertBox.className = 'reg-alert-error';
            alertBox.innerHTML = `<strong>เกิดข้อผิดพลาด:</strong> ${err.message}`;
        }
        alertBox.style.display = 'block';

        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-cloud-arrow-up"></i> อัพโหลด';
    };

    // Drag & drop
    const drop = document.getElementById('regDrop');
    ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('dragover'); }));
    ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('dragover'); }));
    drop.addEventListener('drop', e => {
        if (e.dataTransfer.files.length) {
            document.getElementById('regFileInput').files = e.dataTransfer.files;
            regOnFileSelected();
        }
    });
})();
</script>
