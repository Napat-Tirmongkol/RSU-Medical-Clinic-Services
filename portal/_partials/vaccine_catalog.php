<?php
// portal/_partials/vaccine_catalog.php — Catalog CRUD (Phase 2)
// Loaded by portal/index.php — portal_CSRF + SweetAlert2 available
$vcCanWrite = in_array(($_SESSION['admin_role'] ?? ''), ['admin', 'superadmin'], true);
$vcIsSuper  = (($_SESSION['admin_role'] ?? '') === 'superadmin');
?>
<style>
.vc-page { padding: 4px 4px 80px; }
.vc-h1 { font-size: 22px; font-weight: 900; color: #0f172a; margin: 0 0 4px; display: flex; align-items: center; gap: 10px; }
.vc-sub { font-size: 12px; color: #64748b; margin-bottom: 16px; }

.vc-toolbar { display: flex; gap: 8px; margin-bottom: 12px; align-items: center; flex-wrap: wrap; }
.vc-toolbar input[type="search"] { font-size: 13px; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 8px; min-width: 240px; background: #fff; }
.vc-btn { padding: 8px 14px; border-radius: 8px; font-size: 12px; font-weight: 800; cursor: pointer; border: 1px solid transparent; transition: filter 0.15s; display: inline-flex; align-items: center; gap: 6px; }
.vc-btn:hover { filter: brightness(1.05); }
.vc-btn-primary { background: #0d9488; color: #fff; }
.vc-btn-ghost { background: #fff; border-color: #cbd5e1; color: #475569; }

.vc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 12px; }
.vc-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 14px; display: flex; flex-direction: column; gap: 8px; transition: transform 0.18s, box-shadow 0.18s, border-color 0.18s; }
.vc-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px -10px rgba(15,23,42,0.18); border-color: #0d9488; }
.vc-card.is-inactive { opacity: 0.55; border-style: dashed; }
.vc-card-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
.vc-code { font-family: ui-monospace, Menlo, monospace; font-size: 10px; font-weight: 900; padding: 3px 7px; border-radius: 5px; background: #ecfdf5; color: #0f766e; letter-spacing: 0.05em; }
.vc-card.is-inactive .vc-code { background: #f1f5f9; color: #64748b; }
.vc-name { font-size: 14px; font-weight: 900; color: #0f172a; flex: 1; word-break: break-word; line-height: 1.3; }
.vc-name-en { font-size: 11px; color: #64748b; font-weight: 600; margin-top: 1px; }
.vc-meta { display: flex; flex-wrap: wrap; gap: 4px; font-size: 11px; }
.vc-meta .chip { background: #f1f5f9; color: #475569; padding: 2px 8px; border-radius: 999px; font-weight: 700; }
.vc-meta .chip.mfr { background: #eff6ff; color: #1e40af; }
.vc-meta .chip.cat { background: #f3e8ff; color: #6d28d9; text-transform: uppercase; font-size: 9px; letter-spacing: 0.05em; }
.vc-usage { display: flex; gap: 10px; font-size: 11px; color: #475569; font-weight: 700; margin-top: auto; padding-top: 8px; border-top: 1px solid #f1f5f9; }
.vc-usage b { color: #0d9488; }
.vc-actions { display: flex; gap: 6px; margin-top: 6px; }
.vc-actions button { font-size: 11px; padding: 4px 9px; border-radius: 6px; border: 1px solid #cbd5e1; background: #fff; cursor: pointer; font-weight: 700; color: #475569; transition: background 0.12s; }
.vc-actions button:hover { background: #f1f5f9; }
.vc-actions button.danger { color: #b91c1c; border-color: #fecaca; }
.vc-actions button.danger:hover { background: #fee2e2; }

.vc-empty { padding: 40px; text-align: center; color: #94a3b8; grid-column: 1/-1; }

/* Form modal — portal-escape pattern */
#vc-form-modal { display: none; z-index: 9000 !important; background: rgba(15,23,42,0.55) !important; backdrop-filter: blur(6px); position: fixed; inset: 0; align-items: center; justify-content: center; padding: 20px; }
#vc-form-modal.is-open { display: flex; }
#vc-form-box { background: #fff; border-radius: 18px; width: 100%; max-width: 560px; max-height: 92vh; overflow-y: auto; padding: 0; }
#vc-form-box .head { padding: 18px 22px; border-bottom: 1px solid #f1f5f9; }
#vc-form-box .head h3 { font-size: 17px; font-weight: 900; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 10px; }
#vc-form-box .body { padding: 18px 22px; }
#vc-form-box .foot { padding: 14px 22px; border-top: 1px solid #f1f5f9; display: flex; gap: 8px; justify-content: flex-end; }
#vc-form-box .field { margin-bottom: 12px; }
#vc-form-box .field label { display: block; font-size: 11px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px; }
#vc-form-box .field input, #vc-form-box .field select, #vc-form-box .field textarea { width: 100%; font-size: 13px; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; font-family: inherit; }
#vc-form-box .field textarea { min-height: 60px; resize: vertical; }
#vc-form-box .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }

.vc-swal-z { z-index: 9999 !important; }

body[data-theme='dark'] .vc-card,
body[data-theme='dark'] #vc-form-box { background: #1e293b; border-color: #334155; color: #e2e8f0; }
body[data-theme='dark'] .vc-name,
body[data-theme='dark'] .vc-h1,
body[data-theme='dark'] #vc-form-box h3 { color: #f1f5f9; }
body[data-theme='dark'] .vc-toolbar input,
body[data-theme='dark'] #vc-form-box input,
body[data-theme='dark'] #vc-form-box select,
body[data-theme='dark'] #vc-form-box textarea { background: #0f172a; border-color: #334155; color: #e2e8f0; }
body[data-theme='dark'] .vc-meta .chip { background: #0f172a; color: #cbd5e1; }
</style>

<div class="vc-page">
    <h1 class="vc-h1"><i class="fa-solid fa-pills" style="color:#0d9488"></i> ประเภทวัคซีน (Catalog)</h1>
    <p class="vc-sub">จัดการรายการประเภทวัคซีน · เชื่อมโยงกับ campaign · ค่า default (ผู้ผลิต/dose) จะ pre-fill ทุก record ที่ถูกสร้างใหม่</p>

    <div class="vc-toolbar">
        <input type="search" id="vc-q" placeholder="ค้นหา code / ชื่อ…">
        <?php if ($vcCanWrite): ?>
        <button type="button" class="vc-btn vc-btn-primary" onclick="vcOpenForm()"><i class="fa-solid fa-plus"></i> เพิ่มวัคซีนใหม่</button>
        <?php endif; ?>
        <span style="margin-left:auto;font-size:11px;font-weight:700;color:#64748b">รวม <b id="vc-count" style="color:#0f172a">–</b> ชนิด</span>
    </div>

    <div class="vc-grid" id="vc-grid">
        <div class="vc-empty"><i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลด…</div>
    </div>
</div>

<!-- Add/Edit form (teleported to body on first open) -->
<div id="vc-form-modal" onclick="if(event.target===this) vcCloseForm()">
    <div id="vc-form-box">
        <div class="head">
            <h3><i class="fa-solid fa-pills" style="color:#0d9488"></i> <span id="vc-form-title">เพิ่มวัคซีน</span></h3>
        </div>
        <div class="body">
            <input type="hidden" id="vc-f-id">
            <div class="field">
                <label>Code <span style="color:#dc2626">*</span> <span style="font-weight:600;color:#64748b;font-size:10px">(A-Z, 0-9, _, - · ใช้อ้างอิงในระบบ)</span></label>
                <input type="text" id="vc-f-code" required maxlength="50" style="font-family:ui-monospace,monospace;text-transform:uppercase" placeholder="INFLU">
            </div>
            <div class="grid-2">
                <div class="field">
                    <label>ชื่อ (ไทย) <span style="color:#dc2626">*</span></label>
                    <input type="text" id="vc-f-name-th" required maxlength="200" placeholder="ไข้หวัดใหญ่ตามฤดูกาล">
                </div>
                <div class="field">
                    <label>ชื่อ (English)</label>
                    <input type="text" id="vc-f-name-en" maxlength="200" placeholder="Seasonal Influenza">
                </div>
            </div>
            <div class="grid-2">
                <div class="field">
                    <label>จำนวน dose (default)</label>
                    <input type="number" id="vc-f-doses" min="1" max="20" value="1">
                </div>
                <div class="field">
                    <label>Interval (วัน) ระหว่าง dose</label>
                    <input type="number" id="vc-f-interval" min="0" max="36500" placeholder="365">
                </div>
            </div>
            <div class="grid-2">
                <div class="field">
                    <label>Category</label>
                    <select id="vc-f-category">
                        <option value="routine">routine</option>
                        <option value="on-demand">on-demand</option>
                        <option value="outbreak">outbreak</option>
                        <option value="travel">travel</option>
                    </select>
                </div>
                <div class="field">
                    <label>Sort order</label>
                    <input type="number" id="vc-f-sort" min="0" max="9999" value="100">
                </div>
            </div>
            <div class="field">
                <label>ผู้ผลิต (default · pre-fill ทุก record ใหม่)</label>
                <input type="text" id="vc-f-mfr" maxlength="150" placeholder="Sanofi (Vaxigrip Tetra)">
            </div>
            <div class="field">
                <label>หมายเหตุ</label>
                <textarea id="vc-f-notes" maxlength="2000" placeholder="ข้อมูลเพิ่มเติม วิธีเก็บ ฯลฯ"></textarea>
            </div>
        </div>
        <div class="foot">
            <button type="button" class="vc-btn vc-btn-ghost" onclick="vcCloseForm()">ยกเลิก</button>
            <button type="button" class="vc-btn vc-btn-primary" onclick="vcSubmit()" id="vc-form-save"><i class="fa-solid fa-floppy-disk"></i> บันทึก</button>
        </div>
    </div>
</div>

<script>
(function() {
    const AJAX = 'ajax_vaccine_catalog.php';
    const CAN_WRITE = <?= json_encode($vcCanWrite) ?>;
    const IS_SUPER  = <?= json_encode($vcIsSuper) ?>;
    let allTypes = [];

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function vcTeleportModal() {
        const m = document.getElementById('vc-form-modal');
        if (m && m.parentElement !== document.body) document.body.appendChild(m);
        return m;
    }

    async function vcLoad() {
        const grid = document.getElementById('vc-grid');
        grid.innerHTML = '<div class="vc-empty"><i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลด…</div>';
        try {
            const res = await fetch(AJAX + '?action=list', { credentials: 'same-origin' });
            const json = await res.json();
            if (!json.ok) throw new Error(json.message);
            allTypes = json.types || [];
            vcRender();
        } catch (err) {
            grid.innerHTML = `<div class="vc-empty" style="color:#b91c1c">ERROR: ${esc(err.message)}</div>`;
        }
    }

    function vcRender() {
        const q = (document.getElementById('vc-q').value || '').trim().toLowerCase();
        const grid = document.getElementById('vc-grid');
        const filtered = allTypes.filter(t => {
            if (!q) return true;
            return (t.code || '').toLowerCase().includes(q)
                || (t.name_th || '').toLowerCase().includes(q)
                || (t.name_en || '').toLowerCase().includes(q);
        });
        document.getElementById('vc-count').textContent = allTypes.length.toLocaleString('th-TH');

        if (!filtered.length) {
            grid.innerHTML = '<div class="vc-empty">ไม่พบรายการ</div>';
            return;
        }
        grid.innerHTML = filtered.map(t => {
            const inactiveCls = t.is_active == 1 ? '' : ' is-inactive';
            const intervalStr = t.interval_days ? `ทุก ${t.interval_days} วัน` : 'ครั้งเดียว';
            const dosesStr    = `${t.default_doses} dose${t.default_doses > 1 ? 's' : ''}`;
            return `<div class="vc-card${inactiveCls}">
                <div class="vc-card-head">
                    <div style="flex:1;min-width:0">
                        <span class="vc-code">${esc(t.code)}</span>
                        <div class="vc-name">${esc(t.name_th)}</div>
                        ${t.name_en ? `<div class="vc-name-en">${esc(t.name_en)}</div>` : ''}
                    </div>
                </div>
                <div class="vc-meta">
                    <span class="chip cat">${esc(t.category || 'routine')}</span>
                    <span class="chip">${esc(dosesStr)}</span>
                    <span class="chip">${esc(intervalStr)}</span>
                    ${t.default_manufacturer ? `<span class="chip mfr"><i class="fa-solid fa-industry" style="font-size:8px"></i> ${esc(t.default_manufacturer)}</span>` : ''}
                </div>
                ${t.notes ? `<div style="font-size:11px;color:#64748b;line-height:1.5">${esc(t.notes)}</div>` : ''}
                <div class="vc-usage">
                    <span>📋 <b>${(+t.campaign_count).toLocaleString('th-TH')}</b> แคมเปญ</span>
                    <span>💉 <b>${(+t.record_count).toLocaleString('th-TH')}</b> ครั้งที่ฉีด</span>
                </div>
                ${CAN_WRITE ? `<div class="vc-actions">
                    <button type="button" onclick="vcOpenForm(${t.id})"><i class="fa-solid fa-pen"></i> แก้ไข</button>
                    <button type="button" onclick="vcToggle(${t.id})">
                        <i class="fa-solid ${t.is_active == 1 ? 'fa-pause' : 'fa-play'}"></i>
                        ${t.is_active == 1 ? 'หยุดใช้' : 'เปิดใช้'}
                    </button>
                    ${IS_SUPER && (+t.campaign_count === 0 && +t.record_count === 0) ? `<button type="button" class="danger" onclick="vcDelete(${t.id})"><i class="fa-solid fa-trash"></i> ลบ</button>` : ''}
                </div>` : ''}
            </div>`;
        }).join('');
    }

    function vcOpenForm(id) {
        const m = vcTeleportModal();
        document.getElementById('vc-f-id').value = id || '';
        document.getElementById('vc-form-title').textContent = id ? 'แก้ไขวัคซีน' : 'เพิ่มวัคซีน';

        if (id) {
            const t = allTypes.find(x => x.id == id);
            if (!t) return;
            document.getElementById('vc-f-code').value     = t.code || '';
            document.getElementById('vc-f-code').disabled  = false;  // allow code edits
            document.getElementById('vc-f-name-th').value  = t.name_th || '';
            document.getElementById('vc-f-name-en').value  = t.name_en || '';
            document.getElementById('vc-f-doses').value    = t.default_doses || 1;
            document.getElementById('vc-f-interval').value = t.interval_days ?? '';
            document.getElementById('vc-f-category').value = t.category || 'routine';
            document.getElementById('vc-f-sort').value     = t.sort_order ?? 100;
            document.getElementById('vc-f-mfr').value      = t.default_manufacturer || '';
            document.getElementById('vc-f-notes').value    = t.notes || '';
        } else {
            ['vc-f-code','vc-f-name-th','vc-f-name-en','vc-f-mfr','vc-f-notes'].forEach(k => document.getElementById(k).value = '');
            document.getElementById('vc-f-doses').value    = 1;
            document.getElementById('vc-f-interval').value = '';
            document.getElementById('vc-f-category').value = 'routine';
            document.getElementById('vc-f-sort').value     = 100;
        }
        m.classList.add('is-open');
        document.getElementById('vc-f-code').focus();
    }

    function vcCloseForm() {
        document.getElementById('vc-form-modal').classList.remove('is-open');
    }

    async function vcSubmit() {
        const id = document.getElementById('vc-f-id').value;
        const btn = document.getElementById('vc-form-save');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> กำลังบันทึก…';

        try {
            const fd = new FormData();
            fd.append('csrf_token', (typeof portal_CSRF !== 'undefined') ? portal_CSRF : '');
            if (id) fd.append('id', id);
            fd.append('code',     document.getElementById('vc-f-code').value.trim().toUpperCase());
            fd.append('name_th',  document.getElementById('vc-f-name-th').value);
            fd.append('name_en',  document.getElementById('vc-f-name-en').value);
            fd.append('default_doses',        document.getElementById('vc-f-doses').value);
            fd.append('interval_days',        document.getElementById('vc-f-interval').value);
            fd.append('category',             document.getElementById('vc-f-category').value);
            fd.append('sort_order',           document.getElementById('vc-f-sort').value);
            fd.append('default_manufacturer', document.getElementById('vc-f-mfr').value);
            fd.append('notes',                document.getElementById('vc-f-notes').value);

            const action = id ? 'update' : 'create';
            const res = await fetch(AJAX + '?action=' + action, { method: 'POST', body: fd, credentials: 'same-origin' });
            const json = await res.json();
            if (!json.ok) throw new Error(json.message);

            Swal.fire({ icon:'success', title:'บันทึกแล้ว', timer: 1200, showConfirmButton: false, customClass:{ container:'vc-swal-z' } });
            vcCloseForm();
            vcLoad();
        } catch (err) {
            Swal.fire({ icon:'error', title:'บันทึกไม่สำเร็จ', text: err.message, customClass:{ container:'vc-swal-z' } });
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> บันทึก';
        }
    }

    async function vcToggle(id) {
        try {
            const fd = new FormData();
            fd.append('csrf_token', (typeof portal_CSRF !== 'undefined') ? portal_CSRF : '');
            fd.append('id', id);
            const res = await fetch(AJAX + '?action=toggle_active', { method: 'POST', body: fd, credentials: 'same-origin' });
            const json = await res.json();
            if (!json.ok) throw new Error(json.message);
            vcLoad();
        } catch (err) {
            Swal.fire({ icon:'error', title:'เปลี่ยนสถานะไม่สำเร็จ', text: err.message, customClass:{ container:'vc-swal-z' } });
        }
    }

    async function vcDelete(id) {
        const t = allTypes.find(x => x.id == id);
        if (!t) return;
        const { isConfirmed } = await Swal.fire({
            icon: 'warning',
            title: `ลบวัคซีน ${t.code}?`,
            text: 'การลบนี้ลบจริง (hard delete) — กรุณายืนยัน',
            showCancelButton: true,
            confirmButtonText: 'ลบ',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#dc2626',
            reverseButtons: true,
            customClass: { container: 'vc-swal-z' },
        });
        if (!isConfirmed) return;

        try {
            const fd = new FormData();
            fd.append('csrf_token', (typeof portal_CSRF !== 'undefined') ? portal_CSRF : '');
            fd.append('id', id);
            const res = await fetch(AJAX + '?action=delete', { method: 'POST', body: fd, credentials: 'same-origin' });
            const json = await res.json();
            if (!json.ok) throw new Error(json.message);
            vcLoad();
        } catch (err) {
            Swal.fire({ icon:'error', title:'ลบไม่สำเร็จ', text: err.message, customClass:{ container:'vc-swal-z' } });
        }
    }

    document.getElementById('vc-q').addEventListener('input', () => vcRender());

    window.vcOpenForm = vcOpenForm;
    window.vcCloseForm = vcCloseForm;
    window.vcSubmit = vcSubmit;
    window.vcToggle = vcToggle;
    window.vcDelete = vcDelete;

    vcLoad();
})();
</script>
