<?php
/**
 * portal/_partials/manage_insurance_partners.php
 * จัดการบริษัทประกัน + บัญชี Insurance Partner — เฉพาะ superadmin
 */
declare(strict_types=1);
?>
<div style="padding:1.5rem 2rem; max-width:1400px; margin:0 auto;">

    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1.5rem; flex-wrap:wrap; gap:1rem;">
        <div>
            <h1 style="margin:0; font-size:1.55rem; font-weight:900; color:#064e3b;">
                <i class="fa-solid fa-handshake mr-1" style="color:#10b981;"></i>
                จัดการ Insurance Partner Portal
            </h1>
            <p style="margin:.35rem 0 0 0; font-size:.85rem; color:#64748b;">
                จัดการบริษัทประกันและบัญชี login ของเจ้าหน้าที่บริษัทประกัน (เข้าใช้งานผ่าน <code>/insurance_partner/login.php</code>)
            </p>
        </div>
        <a href="../insurance_partner/login.php" target="_blank" rel="noopener"
           style="background:#059669; color:#fff; padding:.6rem 1.1rem; border-radius:.6rem; font-weight:700; font-size:.85rem; text-decoration:none;">
            <i class="fa-solid fa-arrow-up-right-from-square mr-1"></i> เปิด Partner Portal
        </a>
    </div>

    <!-- Tabs -->
    <div style="display:flex; gap:.5rem; border-bottom:2px solid #e2e8f0; margin-bottom:1.25rem;">
        <button class="ipt-tab active" data-tab="users">
            <i class="fa-solid fa-users"></i> บัญชี Partner
        </button>
        <button class="ipt-tab" data-tab="companies">
            <i class="fa-solid fa-building"></i> บริษัทประกัน
        </button>
        <button class="ipt-tab" data-tab="activity">
            <i class="fa-solid fa-clock-rotate-left"></i> Activity Log
        </button>
    </div>

    <!-- ════ Tab: Users ════ -->
    <div id="ipt-tab-users" class="ipt-pane">
        <div style="background:#fff; border-radius:.85rem; box-shadow:0 4px 14px rgba(0,0,0,.05); padding:1.25rem; margin-bottom:1rem;">
            <div style="display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; margin-bottom:1rem;">
                <input type="text" id="iptUserSearch" placeholder="ค้นหา username, ชื่อ, อีเมล..."
                       style="flex:1; min-width:200px; padding:.55rem .8rem; border:1.5px solid #e2e8f0; border-radius:.5rem; font-family:Sarabun,sans-serif; font-size:.85rem;">
                <select id="iptUserCompanyFilter"
                        style="padding:.55rem .8rem; border:1.5px solid #e2e8f0; border-radius:.5rem; font-family:Sarabun,sans-serif; font-size:.85rem;">
                    <option value="">-- ทุกบริษัท --</option>
                </select>
                <button onclick="iptLoadUsers(1)" class="ipt-btn-primary">
                    <i class="fa-solid fa-magnifying-glass"></i> ค้นหา
                </button>
                <button onclick="iptShowUserModal()" class="ipt-btn-success">
                    <i class="fa-solid fa-plus"></i> เพิ่มบัญชี
                </button>
            </div>

            <div id="iptUserTotalInfo" style="font-size:.8rem; color:#475569; margin-bottom:.5rem;"></div>

            <div style="overflow-x:auto;">
                <table class="ipt-table">
                    <thead>
                        <tr>
                            <th>Username</th><th>ชื่อ-สกุล</th><th>บริษัท</th>
                            <th>สถานะ</th><th>Login ล่าสุด</th><th style="width:160px;">การจัดการ</th>
                        </tr>
                    </thead>
                    <tbody id="iptUserTbody">
                        <tr><td colspan="6" style="text-align:center; padding:1.5rem; color:#94a3b8;">
                            <i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลด...
                        </td></tr>
                    </tbody>
                </table>
            </div>

            <div id="iptUserPagination" class="ipt-pagination"></div>
        </div>
    </div>

    <!-- ════ Tab: Companies ════ -->
    <div id="ipt-tab-companies" class="ipt-pane" style="display:none;">
        <div style="background:#fff; border-radius:.85rem; box-shadow:0 4px 14px rgba(0,0,0,.05); padding:1.25rem;">
            <div style="display:flex; justify-content:space-between; margin-bottom:1rem;">
                <h3 style="margin:0; font-size:1rem; font-weight:700; color:#0f172a;">บริษัทประกันทั้งหมด</h3>
                <button onclick="iptShowCompanyModal()" class="ipt-btn-success">
                    <i class="fa-solid fa-plus"></i> เพิ่มบริษัท
                </button>
            </div>
            <div style="overflow-x:auto;">
                <table class="ipt-table">
                    <thead>
                        <tr>
                            <th>รหัส</th><th>ชื่อบริษัท</th><th>ผู้ติดต่อ</th>
                            <th>สมาชิก</th><th>บัญชี</th><th>สถานะ</th><th style="width:120px;">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody id="iptCompanyTbody">
                        <tr><td colspan="7" style="text-align:center; padding:1.5rem; color:#94a3b8;">
                            <i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลด...
                        </td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ════ Tab: Activity ════ -->
    <div id="ipt-tab-activity" class="ipt-pane" style="display:none;">
        <div style="background:#fff; border-radius:.85rem; box-shadow:0 4px 14px rgba(0,0,0,.05); padding:1.25rem;">
            <div style="display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:1rem;">
                <select id="iptActCompanyFilter"
                        style="padding:.55rem .8rem; border:1.5px solid #e2e8f0; border-radius:.5rem; font-family:Sarabun,sans-serif; font-size:.85rem;">
                    <option value="">-- ทุกบริษัท --</option>
                </select>
                <button onclick="iptLoadActivity(1)" class="ipt-btn-primary">
                    <i class="fa-solid fa-magnifying-glass"></i> กรอง
                </button>
            </div>
            <div id="iptActTotalInfo" style="font-size:.8rem; color:#475569; margin-bottom:.5rem;"></div>
            <div style="overflow-x:auto;">
                <table class="ipt-table">
                    <thead>
                        <tr><th>เวลา</th><th>Username</th><th>บริษัท</th><th>การกระทำ</th><th>รายละเอียด</th><th>IP</th></tr>
                    </thead>
                    <tbody id="iptActTbody">
                        <tr><td colspan="6" style="text-align:center; padding:1.5rem; color:#94a3b8;">
                            <i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลด...
                        </td></tr>
                    </tbody>
                </table>
            </div>
            <div id="iptActPagination" class="ipt-pagination"></div>
        </div>
    </div>
</div>

<!-- ════ Modal: User ════ -->
<div id="iptUserModal" class="ipt-modal" style="display:none;">
    <div class="ipt-modal-card">
        <h3 id="iptUserModalTitle" style="margin:0 0 1rem 0; font-size:1.1rem; font-weight:800; color:#064e3b;">เพิ่มบัญชี Partner</h3>
        <form id="iptUserForm" onsubmit="iptSaveUser(event)">
            <input type="hidden" name="id" id="iptUserId">
            <div class="ipt-form-row">
                <label>Username <span style="color:#dc2626;">*</span></label>
                <input type="text" name="username" id="iptUserUsername" required pattern="[a-zA-Z0-9_.\-]{3,50}">
            </div>
            <div class="ipt-form-row">
                <label>ชื่อ-สกุล <span style="color:#dc2626;">*</span></label>
                <input type="text" name="full_name" id="iptUserFullName" required>
            </div>
            <div class="ipt-form-row">
                <label>อีเมล</label>
                <input type="email" name="email" id="iptUserEmail">
            </div>
            <div class="ipt-form-row" id="iptUserCompanyRow">
                <label>บริษัทประกัน <span style="color:#dc2626;">*</span></label>
                <select name="company_code" id="iptUserCompany" required></select>
            </div>
            <div class="ipt-form-row" id="iptUserStatusRow" style="display:none;">
                <label>สถานะบัญชี</label>
                <select name="account_status" id="iptUserStatus">
                    <option value="Active">Active</option>
                    <option value="Suspended">Suspended</option>
                </select>
            </div>
            <div class="ipt-form-row">
                <label id="iptUserPwLabel">รหัสผ่าน <span style="color:#dc2626;">*</span></label>
                <input type="password" name="password" id="iptUserPassword" minlength="8">
                <small style="color:#64748b; font-size:.75rem;">อย่างน้อย 8 ตัวอักษร (เว้นว่างเพื่อไม่เปลี่ยน เมื่อแก้ไข)</small>
            </div>
            <div style="display:flex; gap:.5rem; justify-content:flex-end; margin-top:1rem;">
                <button type="button" onclick="iptCloseUserModal()" class="ipt-btn-secondary">ยกเลิก</button>
                <button type="submit" class="ipt-btn-primary"><i class="fa-solid fa-floppy-disk"></i> บันทึก</button>
            </div>
        </form>
    </div>
</div>

<!-- ════ Modal: Company ════ -->
<div id="iptCompanyModal" class="ipt-modal" style="display:none;">
    <div class="ipt-modal-card">
        <h3 id="iptCompanyModalTitle" style="margin:0 0 1rem 0; font-size:1.1rem; font-weight:800; color:#064e3b;">เพิ่มบริษัทประกัน</h3>
        <form id="iptCompanyForm" onsubmit="iptSaveCompany(event)">
            <input type="hidden" name="is_edit" id="iptCompanyIsEdit" value="0">
            <div class="ipt-form-row">
                <label>รหัสบริษัท (Code) <span style="color:#dc2626;">*</span></label>
                <input type="text" name="company_code" id="iptCompanyCode" required pattern="[A-Z0-9_]{2,20}" maxlength="20">
                <small style="color:#64748b; font-size:.75rem;">A-Z, 0-9, _ ความยาว 2-20 ตัว (เช่น MTI, AIA)</small>
            </div>
            <div class="ipt-form-row">
                <label>ชื่อบริษัท <span style="color:#dc2626;">*</span></label>
                <input type="text" name="company_name" id="iptCompanyName" required>
            </div>
            <div class="ipt-form-row">
                <label>ผู้ติดต่อ</label>
                <input type="text" name="contact_name" id="iptCompanyContactName">
            </div>
            <div class="ipt-form-row">
                <label>อีเมล</label>
                <input type="email" name="contact_email" id="iptCompanyEmail">
            </div>
            <div class="ipt-form-row">
                <label>เบอร์โทร</label>
                <input type="text" name="contact_phone" id="iptCompanyPhone">
            </div>
            <div class="ipt-form-row" id="iptCompanyStatusRow" style="display:none;">
                <label>สถานะ</label>
                <select name="status" id="iptCompanyStatus">
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                </select>
            </div>
            <div style="display:flex; gap:.5rem; justify-content:flex-end; margin-top:1rem;">
                <button type="button" onclick="iptCloseCompanyModal()" class="ipt-btn-secondary">ยกเลิก</button>
                <button type="submit" class="ipt-btn-primary"><i class="fa-solid fa-floppy-disk"></i> บันทึก</button>
            </div>
        </form>
    </div>
</div>

<style>
.ipt-tab {
    background: transparent; border: none; padding: .75rem 1.25rem;
    font-weight: 700; font-size: .9rem; color: #64748b;
    cursor: pointer; border-bottom: 3px solid transparent;
    margin-bottom: -2px; font-family: 'Sarabun', sans-serif;
    transition: color .15s, border-color .15s;
}
.ipt-tab:hover { color: #059669; }
.ipt-tab.active { color: #059669; border-bottom-color: #10b981; }
.ipt-tab i { margin-right: .35rem; }

.ipt-table { width:100%; border-collapse:collapse; font-size:.85rem; }
.ipt-table th, .ipt-table td { padding:.65rem .75rem; text-align:left; border-bottom:1px solid #f1f5f9; }
.ipt-table th { background:#f0fdf4; font-weight:700; color:#064e3b; font-size:.78rem; text-transform:uppercase; letter-spacing:.05em; }
.ipt-table tbody tr:hover { background:#f9fafb; }

.ipt-btn-primary, .ipt-btn-secondary, .ipt-btn-success, .ipt-btn-danger {
    display:inline-flex; align-items:center; gap:.4rem;
    padding:.55rem 1rem; border-radius:.5rem; border:none;
    font-weight:700; font-size:.82rem; cursor:pointer;
    font-family: 'Sarabun', sans-serif;
    transition: opacity .15s;
}
.ipt-btn-primary { background:#059669; color:#fff; }
.ipt-btn-primary:hover { background:#047857; }
.ipt-btn-secondary { background:#e2e8f0; color:#1e293b; }
.ipt-btn-secondary:hover { background:#cbd5e1; }
.ipt-btn-success { background:#10b981; color:#fff; }
.ipt-btn-success:hover { background:#059669; }
.ipt-btn-danger { background:#dc2626; color:#fff; }
.ipt-btn-danger:hover { background:#b91c1c; }

.ipt-badge { display:inline-block; padding:.2rem .55rem; border-radius:999px; font-size:.7rem; font-weight:700; }
.ipt-badge-active { background:#d1fae5; color:#065f46; }
.ipt-badge-suspended, .ipt-badge-inactive { background:#fee2e2; color:#991b1b; }
.ipt-badge-locked { background:#fef3c7; color:#92400e; }

.ipt-pagination { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem; margin-top:1rem; padding-top:.75rem; border-top:1px solid #e2e8f0; }
.ipt-page-btn {
    min-width:2rem; height:2rem; padding:0 .5rem;
    border-radius:.4rem; border:1.5px solid #e2e8f0;
    background:#fff; color:#475569;
    cursor:pointer; font-weight:700; font-size:.78rem;
    display:inline-flex; align-items:center; justify-content:center;
    font-family: 'Sarabun', sans-serif;
}
.ipt-page-btn:hover:not(.disabled):not(.active) { background:#f0fdf4; border-color:#10b981; color:#059669; }
.ipt-page-btn.active { background:#059669; color:#fff; border-color:#059669; }
.ipt-page-btn.disabled { opacity:.4; cursor:not-allowed; }

.ipt-form-row { display:flex; flex-direction:column; gap:.3rem; margin-bottom:.85rem; }
.ipt-form-row label { font-size:.8rem; font-weight:700; color:#0f172a; }
.ipt-form-row input, .ipt-form-row select {
    padding:.55rem .75rem; border:1.5px solid #e2e8f0;
    border-radius:.45rem; font-size:.85rem;
    font-family: 'Sarabun', sans-serif;
}
.ipt-form-row input:focus, .ipt-form-row select:focus {
    outline:none; border-color:#10b981;
    box-shadow: 0 0 0 3px rgba(16,185,129,.12);
}

.ipt-modal {
    position:fixed; inset:0; background:rgba(15,23,42,.55);
    z-index:9999; display:flex; align-items:center; justify-content:center;
    padding:1rem; backdrop-filter: blur(4px);
}
.ipt-modal-card {
    background:#fff; border-radius:1rem; padding:1.5rem;
    width:100%; max-width:480px; max-height:90vh; overflow-y:auto;
    box-shadow: 0 25px 80px rgba(0,0,0,.25);
}

/* ── DARK MODE ──────────────────────────────────────────────── */
body[data-theme='dark'] #section-manage_insurance_partners .ipt-tab { color:#94a3b8; }
body[data-theme='dark'] #section-manage_insurance_partners .ipt-tab:hover { color:#6ee7b7; }
body[data-theme='dark'] #section-manage_insurance_partners .ipt-tab.active { color:#6ee7b7; }
body[data-theme='dark'] #section-manage_insurance_partners .ipt-table th { background: rgba(16,185,129,.16); color:#6ee7b7; }
body[data-theme='dark'] #section-manage_insurance_partners .ipt-table td { border-color:#1e293b; color:#e2e8f0; }
body[data-theme='dark'] #section-manage_insurance_partners .ipt-table tbody tr:hover { background:#0b1220; }
body[data-theme='dark'] #section-manage_insurance_partners .ipt-btn-secondary { background:#334155; color:#e2e8f0; }
body[data-theme='dark'] #section-manage_insurance_partners .ipt-btn-secondary:hover { background:#475569; }
body[data-theme='dark'] #section-manage_insurance_partners .ipt-badge-active { background: rgba(16,185,129,.18); color:#6ee7b7; }
body[data-theme='dark'] #section-manage_insurance_partners .ipt-badge-suspended,
body[data-theme='dark'] #section-manage_insurance_partners .ipt-badge-inactive { background: rgba(244,63,94,.18); color:#fb7185; }
body[data-theme='dark'] #section-manage_insurance_partners .ipt-badge-locked { background: rgba(245,158,11,.18); color:#fbbf24; }
body[data-theme='dark'] #section-manage_insurance_partners .ipt-pagination { border-color:#1e293b; }
body[data-theme='dark'] #section-manage_insurance_partners .ipt-page-btn { background:#0f172a; border-color:#1e293b; color:#cbd5e1; }
body[data-theme='dark'] #section-manage_insurance_partners .ipt-page-btn:hover:not(.disabled):not(.active) { background: rgba(16,185,129,.16); border-color:#10b981; color:#6ee7b7; }
body[data-theme='dark'] #section-manage_insurance_partners .ipt-page-btn.active { background:#10b981; color:#0f172a; border-color:#10b981; }
body[data-theme='dark'] #section-manage_insurance_partners .ipt-form-row label { color:#e2e8f0; }
body[data-theme='dark'] #section-manage_insurance_partners .ipt-form-row input,
body[data-theme='dark'] #section-manage_insurance_partners .ipt-form-row select {
    background:#0b1220; border-color:#1e293b; color:#e2e8f0;
}
body[data-theme='dark'] #section-manage_insurance_partners .ipt-form-row input:focus,
body[data-theme='dark'] #section-manage_insurance_partners .ipt-form-row select:focus { background:#0f172a; }
body[data-theme='dark'] #section-manage_insurance_partners .ipt-modal-card { background:#0f172a; color:#e2e8f0; }
body[data-theme='dark'] #section-manage_insurance_partners .bg-white { background:#0f172a !important; }
body[data-theme='dark'] #section-manage_insurance_partners .bg-slate-50 { background: rgba(148,163,184,.08) !important; }
body[data-theme='dark'] #section-manage_insurance_partners .bg-slate-100 { background: rgba(148,163,184,.14) !important; }
body[data-theme='dark'] #section-manage_insurance_partners .bg-emerald-50 { background: rgba(16,185,129,.18) !important; }
body[data-theme='dark'] #section-manage_insurance_partners .bg-amber-50 { background: rgba(245,158,11,.18) !important; }
body[data-theme='dark'] #section-manage_insurance_partners .bg-rose-50 { background: rgba(244,63,94,.18) !important; }
body[data-theme='dark'] #section-manage_insurance_partners .text-slate-900 { color:#f1f5f9 !important; }
body[data-theme='dark'] #section-manage_insurance_partners .text-slate-800 { color:#f1f5f9 !important; }
body[data-theme='dark'] #section-manage_insurance_partners .text-slate-700 { color:#e2e8f0 !important; }
body[data-theme='dark'] #section-manage_insurance_partners .text-slate-600 { color:#cbd5e1 !important; }
body[data-theme='dark'] #section-manage_insurance_partners .text-slate-500 { color:#94a3b8 !important; }
body[data-theme='dark'] #section-manage_insurance_partners .text-slate-400 { color:#64748b !important; }
body[data-theme='dark'] #section-manage_insurance_partners .border-slate-200 { border-color:#1e293b !important; }
body[data-theme='dark'] #section-manage_insurance_partners .border-slate-100 { border-color:#1e293b !important; }
</style>

<script>
(function() {
    const CSRF = '<?= htmlspecialchars(get_csrf_token()) ?>';
    let companiesCache = [];
    let userPage = 1;
    let actPage = 1;

    // ── Tabs ──────────────────────────────────────────────
    document.querySelectorAll('.ipt-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.ipt-tab').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            document.querySelectorAll('.ipt-pane').forEach(p => p.style.display = 'none');
            document.getElementById('ipt-tab-' + btn.dataset.tab).style.display = '';
            if (btn.dataset.tab === 'companies') iptLoadCompanies();
            if (btn.dataset.tab === 'activity') iptLoadActivity(1);
        });
    });

    // ── Companies ─────────────────────────────────────────
    window.iptLoadCompanies = async function() {
        const r = await fetch('ajax_insurance_partners.php?action=list_companies').then(r => r.json());
        if (!r.ok) return alert(r.error || 'error');
        companiesCache = r.data;
        const tb = document.getElementById('iptCompanyTbody');
        if (!r.data.length) {
            tb.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:1.5rem; color:#94a3b8;">ยังไม่มีบริษัท</td></tr>';
            return;
        }
        tb.innerHTML = r.data.map(c => `
            <tr>
                <td><code>${esc(c.company_code)}</code></td>
                <td><strong>${esc(c.company_name)}</strong></td>
                <td>${esc(c.contact_name||'-')}<br><small style="color:#64748b;">${esc(c.contact_email||'')}</small></td>
                <td>${Number(c.member_count).toLocaleString()}</td>
                <td>${Number(c.user_count).toLocaleString()}</td>
                <td><span class="ipt-badge ipt-badge-${c.status==='Active'?'active':'inactive'}">${esc(c.status)}</span></td>
                <td>
                    <button class="ipt-btn-secondary" onclick='iptShowCompanyModal(${JSON.stringify(c)})' title="แก้ไข">
                        <i class="fa-solid fa-pen"></i>
                    </button>
                </td>
            </tr>
        `).join('');
        // sync filter dropdowns
        const opts = ['<option value="">-- ทุกบริษัท --</option>']
            .concat(r.data.map(c => `<option value="${esc(c.company_code)}">${esc(c.company_name)}</option>`));
        document.getElementById('iptUserCompanyFilter').innerHTML = opts.join('');
        document.getElementById('iptActCompanyFilter').innerHTML = opts.join('');
        document.getElementById('iptUserCompany').innerHTML =
            r.data.filter(c => c.status==='Active').map(c => `<option value="${esc(c.company_code)}">${esc(c.company_name)}</option>`).join('');
    };

    window.iptShowCompanyModal = function(company) {
        const isEdit = !!company;
        document.getElementById('iptCompanyModalTitle').textContent = isEdit ? 'แก้ไขบริษัทประกัน' : 'เพิ่มบริษัทประกัน';
        document.getElementById('iptCompanyIsEdit').value = isEdit ? '1' : '0';
        document.getElementById('iptCompanyCode').value = company?.company_code || '';
        document.getElementById('iptCompanyCode').readOnly = isEdit;
        document.getElementById('iptCompanyName').value = company?.company_name || '';
        document.getElementById('iptCompanyContactName').value = company?.contact_name || '';
        document.getElementById('iptCompanyEmail').value = company?.contact_email || '';
        document.getElementById('iptCompanyPhone').value = company?.contact_phone || '';
        document.getElementById('iptCompanyStatus').value = company?.status || 'Active';
        document.getElementById('iptCompanyStatusRow').style.display = isEdit ? '' : 'none';
        document.getElementById('iptCompanyModal').style.display = 'flex';
    };
    window.iptCloseCompanyModal = function() {
        document.getElementById('iptCompanyModal').style.display = 'none';
    };
    window.iptSaveCompany = async function(e) {
        e.preventDefault();
        const isEdit = document.getElementById('iptCompanyIsEdit').value === '1';
        const fd = new FormData(e.target);
        fd.append('csrf_token', CSRF);
        fd.append('action', isEdit ? 'update_company' : 'add_company');
        const r = await fetch('ajax_insurance_partners.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!r.ok) return alert(r.error || 'error');
        iptCloseCompanyModal();
        iptLoadCompanies();
    };

    // ── Users ─────────────────────────────────────────────
    window.iptLoadUsers = async function(page = 1) {
        userPage = page;
        const q = document.getElementById('iptUserSearch').value;
        const cc = document.getElementById('iptUserCompanyFilter').value;
        const url = `ajax_insurance_partners.php?action=list_users&page=${page}&q=${encodeURIComponent(q)}&company=${encodeURIComponent(cc)}`;
        const r = await fetch(url).then(r => r.json());
        if (!r.ok) return alert(r.error || 'error');

        const tb = document.getElementById('iptUserTbody');
        if (!r.data.length) {
            tb.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:1.5rem; color:#94a3b8;">ไม่พบบัญชี</td></tr>';
        } else {
            tb.innerHTML = r.data.map(u => {
                const isLocked = u.locked_until && new Date(u.locked_until) > new Date();
                return `
                <tr>
                    <td><code>${esc(u.username)}</code></td>
                    <td>${esc(u.full_name)}<br><small style="color:#64748b;">${esc(u.email||'')}</small></td>
                    <td>${esc(u.company_name)} <small style="color:#64748b;">(${esc(u.company_code)})</small></td>
                    <td>
                        <span class="ipt-badge ipt-badge-${u.account_status==='Active'?'active':'suspended'}">${esc(u.account_status)}</span>
                        ${isLocked ? '<br><span class="ipt-badge ipt-badge-locked" style="margin-top:.2rem;">Locked</span>' : ''}
                    </td>
                    <td style="font-size:.8rem; color:#475569;">
                        ${u.last_login_at ? esc(u.last_login_at) : '<em style="color:#94a3b8;">ยังไม่เคย</em>'}
                        ${u.last_login_ip ? `<br><small>${esc(u.last_login_ip)}</small>` : ''}
                    </td>
                    <td>
                        <button class="ipt-btn-secondary" onclick='iptShowUserModal(${JSON.stringify(u)})' title="แก้ไข">
                            <i class="fa-solid fa-pen"></i>
                        </button>
                        ${isLocked ? `<button class="ipt-btn-success" onclick="iptUnlockUser(${u.id})" title="Unlock"><i class="fa-solid fa-lock-open"></i></button>` : ''}
                        <button class="ipt-btn-danger" onclick="iptDeleteUser(${u.id}, '${esc(u.username)}')" title="ลบ">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </td>
                </tr>`;
            }).join('');
        }
        document.getElementById('iptUserTotalInfo').textContent =
            `หน้า ${r.pagination.page} / ${r.pagination.total_pages} · รวม ${r.pagination.total.toLocaleString()} รายการ`;
        renderPagination('iptUserPagination', r.pagination, iptLoadUsers);
    };

    window.iptShowUserModal = function(user) {
        if (!companiesCache.length) {
            alert('กรุณาโหลด tab "บริษัทประกัน" ก่อน');
            return;
        }
        const isEdit = !!user;
        document.getElementById('iptUserModalTitle').textContent = isEdit ? 'แก้ไขบัญชี Partner' : 'เพิ่มบัญชี Partner';
        document.getElementById('iptUserId').value = user?.id || '';
        document.getElementById('iptUserUsername').value = user?.username || '';
        document.getElementById('iptUserUsername').readOnly = isEdit;
        document.getElementById('iptUserFullName').value = user?.full_name || '';
        document.getElementById('iptUserEmail').value = user?.email || '';
        document.getElementById('iptUserCompanyRow').style.display = isEdit ? 'none' : '';
        if (!isEdit) {
            document.getElementById('iptUserCompany').innerHTML =
                companiesCache.filter(c => c.status==='Active').map(c => `<option value="${esc(c.company_code)}">${esc(c.company_name)}</option>`).join('');
        }
        document.getElementById('iptUserStatusRow').style.display = isEdit ? '' : 'none';
        document.getElementById('iptUserStatus').value = user?.account_status || 'Active';
        document.getElementById('iptUserPassword').value = '';
        document.getElementById('iptUserPassword').required = !isEdit;
        document.getElementById('iptUserPwLabel').innerHTML = isEdit
            ? 'รีเซ็ตรหัสผ่านใหม่ (เว้นว่างเพื่อไม่เปลี่ยน)'
            : 'รหัสผ่าน <span style="color:#dc2626;">*</span>';
        document.getElementById('iptUserModal').style.display = 'flex';
    };
    window.iptCloseUserModal = function() {
        document.getElementById('iptUserModal').style.display = 'none';
    };
    window.iptSaveUser = async function(e) {
        e.preventDefault();
        const isEdit = !!document.getElementById('iptUserId').value;
        const fd = new FormData(e.target);
        fd.append('csrf_token', CSRF);
        fd.append('action', isEdit ? 'update_user' : 'add_user');
        const r = await fetch('ajax_insurance_partners.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!r.ok) return alert(r.error || 'error');
        iptCloseUserModal();
        iptLoadUsers(userPage);
    };
    window.iptUnlockUser = async function(id) {
        if (!confirm('ปลดล็อคบัญชีนี้?')) return;
        const fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('action', 'unlock_user');
        fd.append('id', id);
        const r = await fetch('ajax_insurance_partners.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!r.ok) return alert(r.error || 'error');
        iptLoadUsers(userPage);
    };
    window.iptDeleteUser = async function(id, username) {
        if (!confirm(`ลบบัญชี "${username}" ถาวร? (ไม่สามารถกู้คืนได้)`)) return;
        const fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('action', 'delete_user');
        fd.append('id', id);
        const r = await fetch('ajax_insurance_partners.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!r.ok) return alert(r.error || 'error');
        iptLoadUsers(userPage);
    };

    // ── Activity ──────────────────────────────────────────
    window.iptLoadActivity = async function(page = 1) {
        actPage = page;
        const cc = document.getElementById('iptActCompanyFilter').value;
        const url = `ajax_insurance_partners.php?action=list_activity&page=${page}&company=${encodeURIComponent(cc)}`;
        const r = await fetch(url).then(r => r.json());
        if (!r.ok) return alert(r.error || 'error');
        const tb = document.getElementById('iptActTbody');
        if (!r.data.length) {
            tb.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:1.5rem; color:#94a3b8;">ไม่มีกิจกรรม</td></tr>';
        } else {
            tb.innerHTML = r.data.map(a => `
                <tr>
                    <td style="font-size:.78rem;">${esc(a.created_at)}</td>
                    <td><code>${esc(a.username||'-')}</code></td>
                    <td>${esc(a.company_code||'-')}</td>
                    <td><strong>${esc(a.action)}</strong></td>
                    <td style="font-size:.8rem; color:#475569;">${esc(a.details||'')}</td>
                    <td style="font-size:.78rem; color:#64748b;">${esc(a.ip_address||'')}</td>
                </tr>`).join('');
        }
        document.getElementById('iptActTotalInfo').textContent =
            `หน้า ${r.pagination.page} / ${r.pagination.total_pages} · รวม ${r.pagination.total.toLocaleString()} รายการ`;
        renderPagination('iptActPagination', r.pagination, iptLoadActivity);
    };

    // ── Pagination ────────────────────────────────────────
    function renderPagination(elId, p, cb) {
        const el = document.getElementById(elId);
        if (p.total_pages <= 1) { el.innerHTML = ''; return; }
        const first = Math.max(1, p.page - 2);
        const last  = Math.min(p.total_pages, p.page + 2);
        let html = `<div style="font-size:.78rem; color:#475569;">หน้า ${p.page} / ${p.total_pages} · รวม ${p.total.toLocaleString()} รายการ</div><div style="display:flex; gap:.2rem;">`;
        const btn = (lbl, page, opts = {}) => {
            const dis = opts.disabled ? 'disabled' : '';
            const act = opts.active ? 'active' : '';
            return `<button class="ipt-page-btn ${dis} ${act}" ${dis ? 'disabled' : `onclick="${cb.name}(${page})"`}>${lbl}</button>`;
        };
        html += btn('«', 1, { disabled: p.page <= 1 });
        html += btn('‹', p.page - 1, { disabled: p.page <= 1 });
        for (let i = first; i <= last; i++) html += btn(i, i, { active: i === p.page });
        html += btn('›', p.page + 1, { disabled: p.page >= p.total_pages });
        html += btn('»', p.total_pages, { disabled: p.page >= p.total_pages });
        html += '</div>';
        el.innerHTML = html;
    }

    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    // Init
    iptLoadCompanies();
    iptLoadUsers(1);

    // Enter key in search
    document.getElementById('iptUserSearch').addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); iptLoadUsers(1); }
    });
})();
</script>
