<?php
/**
 * portal/_partials/batch_status.php
 *
 * Shared UI for batch workflow status (registry / clinic / superadmin)
 * - Registry users see only their own batches
 * - Clinic / superadmin see all + can approve/reject
 *
 * Sections:
 *  - Stats cards (by status)
 *  - Filter / search
 *  - Batch table (paginated 20/page) with stepper preview
 *  - Detail drawer with full stepper, member list, event timeline
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/insurance_batch.php';

$canApprove = !empty($_SESSION['access_insurance']) || ($_SESSION['admin_role'] ?? '') === 'superadmin';
$labels = ins_batch_status_labels();
$stages = ins_batch_stepper_stages();
?>
<div style="padding:1.5rem 2rem; max-width:1500px; margin:0 auto;">

    <div style="margin-bottom:1.25rem;">
        <h1 style="margin:0; font-size:1.55rem; font-weight:900; color:#0f172a;">
            <i class="fa-solid fa-list-check mr-1" style="color:#06b6d4;"></i>
            สถานะเอกสาร (Batch Workflow)
        </h1>
        <p style="margin:.35rem 0 0 0; font-size:.85rem; color:#64748b;">
            ติดตามสถานะแต่ละชุดรายชื่อ ตั้งแต่อัพโหลดจนถึงออกกรมธรรม์
            <?php if (!$canApprove): ?>
            <span style="color:#06b6d4; font-weight:700;">(เห็นเฉพาะ batch ที่คุณอัพโหลด)</span>
            <?php endif; ?>
        </p>
    </div>

    <!-- ─── Stats cards by status ─── -->
    <div id="bsStatsGrid" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:.85rem; margin-bottom:1.25rem;">
        <?php foreach ($labels as $st => [$label, $color, $icon]): ?>
        <div class="bs-stat" data-status="<?= htmlspecialchars($st) ?>" onclick="bsFilterByStatus('<?= htmlspecialchars($st) ?>')">
            <div style="font-size:1.5rem; color:<?= $color ?>;"><i class="fa-solid fa-<?= $icon ?>"></i></div>
            <div style="font-size:1.6rem; font-weight:900; color:#0f172a; line-height:1;" data-count="0">0</div>
            <div style="font-size:.72rem; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:.05em;">
                <?= htmlspecialchars($label) ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ─── Filter bar + table ─── -->
    <div style="background:#fff; border-radius:1rem; box-shadow:0 4px 14px rgba(0,0,0,.05); padding:1.25rem;">
        <div style="display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; margin-bottom:1rem;">
            <input type="text" id="bsSearch" placeholder="ค้นหา batch_code, ผู้อัพโหลด..."
                   style="flex:1; min-width:200px; padding:.55rem .8rem; border:1.5px solid #e2e8f0; border-radius:.5rem; font-family:Sarabun,sans-serif; font-size:.85rem;">
            <select id="bsStatusFilter"
                    style="padding:.55rem .8rem; border:1.5px solid #e2e8f0; border-radius:.5rem; font-family:Sarabun,sans-serif; font-size:.85rem;">
                <option value="">-- ทุกสถานะ --</option>
                <?php foreach ($labels as $st => [$label]): ?>
                <option value="<?= htmlspecialchars($st) ?>"><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button onclick="bsLoad(1)" class="bs-btn-primary"><i class="fa-solid fa-magnifying-glass"></i> ค้นหา</button>
            <button onclick="bsClearFilters()" class="bs-btn-secondary"><i class="fa-solid fa-xmark"></i> ล้าง</button>
        </div>

        <div id="bsTotalInfo" style="font-size:.8rem; color:#475569; margin-bottom:.5rem;"></div>

        <div style="overflow-x:auto;">
            <table class="bs-table">
                <thead>
                    <tr>
                        <th style="width:160px;">รหัสเอกสาร</th>
                        <th>ความคืบหน้า</th>
                        <th style="width:130px;">รายชื่อ</th>
                        <th style="width:120px;">ผู้อัพโหลด</th>
                        <th style="width:140px;">วันที่อัพโหลด</th>
                        <th style="width:120px;">การจัดการ</th>
                    </tr>
                </thead>
                <tbody id="bsTbody">
                    <tr><td colspan="6" style="text-align:center; padding:1.5rem; color:#94a3b8;">
                        <i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลด...
                    </td></tr>
                </tbody>
            </table>
        </div>

        <div id="bsPagination" class="bs-pagination"></div>
    </div>
</div>

<!-- ════════════ Detail Drawer ════════════ -->
<div id="bsDrawer" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,.55); z-index:9999; backdrop-filter:blur(4px);" onclick="bsCloseDrawer(event)">
    <div onclick="event.stopPropagation()" style="position:absolute; top:0; right:0; width:100%; max-width:720px; height:100%; background:#fff; box-shadow:-10px 0 40px rgba(0,0,0,.2); overflow-y:auto;">
        <div style="position:sticky; top:0; background:#fff; padding:1rem 1.5rem; border-bottom:1.5px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between; z-index:10;">
            <h3 id="bsDrawerTitle" style="margin:0; font-size:1.1rem; font-weight:800; color:#0f172a;"></h3>
            <button onclick="bsCloseDrawer()" class="bs-btn-secondary"><i class="fa-solid fa-xmark"></i> ปิด</button>
        </div>
        <div id="bsDrawerBody" style="padding:1.5rem;"></div>
    </div>
</div>

<style>
.bs-stat {
    background:#fff; padding:1rem .85rem; border-radius:.85rem;
    box-shadow:0 2px 8px rgba(0,0,0,.04);
    text-align:center; cursor:pointer;
    border:2px solid transparent;
    transition: border-color .15s, transform .1s;
}
.bs-stat:hover { border-color:#06b6d4; transform: translateY(-2px); }
.bs-stat.active { border-color:#06b6d4; background:#ecfeff; }

.bs-table { width:100%; border-collapse:collapse; font-size:.85rem; }
.bs-table th, .bs-table td { padding:.7rem .75rem; text-align:left; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
.bs-table th { background:#f0f9ff; font-weight:700; color:#075985; font-size:.78rem; text-transform:uppercase; letter-spacing:.05em; }
.bs-table tbody tr:hover { background:#f8fafc; }

.bs-badge { display:inline-flex; align-items:center; gap:.3rem; padding:.25rem .6rem; border-radius:999px; font-size:.7rem; font-weight:700; }

.bs-stepper { display:flex; align-items:center; gap:.25rem; }
.bs-step { display:flex; align-items:center; gap:.25rem; }
.bs-step-dot {
    width:1.5rem; height:1.5rem; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    font-size:.65rem; font-weight:800;
    background:#e2e8f0; color:#94a3b8;
    flex-shrink:0;
}
.bs-step-dot.done { background:#10b981; color:#fff; }
.bs-step-dot.current { background:#06b6d4; color:#fff; box-shadow:0 0 0 3px rgba(6,182,212,.3); animation: bsPulse 2s infinite; }
.bs-step-dot.rejected { background:#ef4444; color:#fff; }
.bs-step-line { flex:1; height:2px; background:#e2e8f0; min-width:8px; }
.bs-step-line.done { background:#10b981; }
@keyframes bsPulse { 0%,100% { box-shadow:0 0 0 3px rgba(6,182,212,.3); } 50% { box-shadow:0 0 0 6px rgba(6,182,212,.15); } }

.bs-btn-primary, .bs-btn-secondary, .bs-btn-success, .bs-btn-danger {
    display:inline-flex; align-items:center; gap:.4rem;
    padding:.5rem .95rem; border-radius:.5rem; border:none;
    font-weight:700; font-size:.8rem; cursor:pointer;
    font-family: 'Sarabun', sans-serif;
    transition: opacity .15s;
}
.bs-btn-primary { background:#06b6d4; color:#fff; }
.bs-btn-primary:hover { background:#0891b2; }
.bs-btn-secondary { background:#e2e8f0; color:#1e293b; }
.bs-btn-secondary:hover { background:#cbd5e1; }
.bs-btn-success { background:#10b981; color:#fff; }
.bs-btn-success:hover { background:#059669; }
.bs-btn-danger { background:#ef4444; color:#fff; }
.bs-btn-danger:hover { background:#dc2626; }

.bs-pagination { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem; margin-top:1rem; padding-top:.75rem; border-top:1px solid #e2e8f0; }
.bs-page-btn {
    min-width:2rem; height:2rem; padding:0 .5rem;
    border-radius:.4rem; border:1.5px solid #e2e8f0;
    background:#fff; color:#475569;
    cursor:pointer; font-weight:700; font-size:.78rem;
    display:inline-flex; align-items:center; justify-content:center;
    font-family: 'Sarabun', sans-serif;
}
.bs-page-btn:hover:not(.disabled):not(.active) { background:#f0f9ff; border-color:#06b6d4; color:#0891b2; }
.bs-page-btn.active { background:#06b6d4; color:#fff; border-color:#06b6d4; }
.bs-page-btn.disabled { opacity:.4; cursor:not-allowed; }

.bs-stage-full { display:flex; align-items:center; gap:.5rem; }
.bs-stage-full .bs-step-dot { width:2rem; height:2rem; font-size:.8rem; }
.bs-stage-full .bs-step-line { min-width:24px; height:3px; }
.bs-stage-label { font-size:.7rem; font-weight:700; color:#475569; text-align:center; max-width:80px; }

.bs-event-item {
    display:flex; gap:.85rem; padding:.85rem 0;
    border-bottom:1px dashed #e2e8f0;
}
.bs-event-icon {
    width:2rem; height:2rem; border-radius:50%;
    background:#ecfeff; color:#0891b2;
    display:flex; align-items:center; justify-content:center;
    font-size:.85rem; flex-shrink:0;
}
</style>

<script>
(function() {
    const CSRF = '<?= htmlspecialchars(get_csrf_token()) ?>';
    const STATUS_LABELS = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>;
    const STAGES = <?= json_encode(array_values(array_map(fn($s, $k) => ['key' => $k, 'label' => $s[0], 'icon' => $s[1]], $stages, array_keys($stages))), JSON_UNESCAPED_UNICODE) ?>;
    const STAGE_INDEX = {
        uploaded: 0, pending_review: 1, approved: 2, rejected: 2,
        downloaded: 3, in_progress: 4, partial: 4, completed: 5, cancelled: -1,
    };
    const CAN_APPROVE = <?= $canApprove ? 'true' : 'false' ?>;

    let currentPage = 1;

    window.bsClearFilters = function() {
        document.getElementById('bsSearch').value = '';
        document.getElementById('bsStatusFilter').value = '';
        document.querySelectorAll('.bs-stat').forEach(s => s.classList.remove('active'));
        bsLoad(1);
    };

    window.bsFilterByStatus = function(st) {
        document.getElementById('bsStatusFilter').value = st;
        document.querySelectorAll('.bs-stat').forEach(s => s.classList.toggle('active', s.dataset.status === st));
        bsLoad(1);
    };

    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function statusBadge(st) {
        const l = STATUS_LABELS[st];
        if (!l) return `<span class="bs-badge" style="background:#e2e8f0;color:#475569;">${esc(st)}</span>`;
        return `<span class="bs-badge" style="background:${l[1]}22;color:${l[1]};"><i class="fa-solid fa-${l[2]}"></i> ${esc(l[0])}</span>`;
    }

    function stepperHtml(currentStatus, compact = true) {
        const cur = STAGE_INDEX[currentStatus] ?? 0;
        const isReject = currentStatus === 'rejected';
        const isCancel = currentStatus === 'cancelled';
        let html = compact ? '<div class="bs-stepper">' : '<div class="bs-stepper" style="gap:.4rem;">';
        STAGES.forEach((s, i) => {
            let cls = 'bs-step-dot';
            let content = `<i class="fa-solid fa-${s.icon}"></i>`;
            if (i < cur) { cls += ' done'; content = '<i class="fa-solid fa-check"></i>'; }
            else if (i === cur) {
                if (isReject) { cls += ' rejected'; content = '<i class="fa-solid fa-xmark"></i>'; }
                else if (isCancel) { cls += ''; content = '<i class="fa-solid fa-ban"></i>'; }
                else { cls += ' current'; }
            }
            if (compact) {
                html += `<div class="${cls}" title="${esc(s.label)}">${content}</div>`;
                if (i < STAGES.length - 1) html += `<div class="bs-step-line ${i < cur ? 'done' : ''}"></div>`;
            } else {
                html += `<div class="bs-stage-full" style="flex-direction:column;">
                    <div class="${cls}">${content}</div>
                    <div class="bs-stage-label">${esc(s.label)}</div>
                </div>`;
                if (i < STAGES.length - 1) html += `<div class="bs-step-line ${i < cur ? 'done' : ''}" style="align-self:center; margin-top:-1.4rem;"></div>`;
            }
        });
        html += '</div>';
        return html;
    }

    window.bsLoadStats = async function() {
        const r = await fetch('ajax_insurance_batches.php?action=stats').then(r => r.json());
        if (r.status !== 'ok') return;
        const counts = (r.data && r.data.by_status) || r.by_status || {};
        document.querySelectorAll('.bs-stat').forEach(el => {
            const st = el.dataset.status;
            const cntEl = el.querySelector('[data-count]');
            cntEl.textContent = (counts[st] || 0).toLocaleString();
        });
    };

    window.bsLoad = async function(page = 1) {
        currentPage = page;
        const q = document.getElementById('bsSearch').value;
        const st = document.getElementById('bsStatusFilter').value;
        const url = `ajax_insurance_batches.php?action=list&page=${page}&q=${encodeURIComponent(q)}&status=${encodeURIComponent(st)}`;
        const r = await fetch(url).then(r => r.json());
        if (r.status !== 'ok') { alert(r.message || 'load error'); return; }
        const payload = r.data || r;

        const tb = document.getElementById('bsTbody');
        const rows = payload.data || [];
        if (!rows.length) {
            tb.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:1.5rem; color:#94a3b8;">ไม่พบ batch</td></tr>';
        } else {
            tb.innerHTML = rows.map(b => `
                <tr>
                    <td>
                        <div style="font-weight:700; color:#0f172a;"><code>${esc(b.batch_code)}</code></div>
                        <div style="margin-top:.25rem;">${statusBadge(b.status)}</div>
                    </td>
                    <td>
                        ${stepperHtml(b.status, true)}
                        <div style="margin-top:.4rem; font-size:.75rem; color:#64748b;">
                            ${b.members_with_policy}/${b.total_members} ออกกรมธรรม์แล้ว
                        </div>
                    </td>
                    <td>
                        <div style="font-size:.85rem; color:#0f172a; font-weight:700;">${(b.total_members || 0).toLocaleString()}</div>
                        <div style="font-size:.7rem; color:#64748b;">
                            +${b.members_inserted || 0} / ↻${b.members_updated || 0} / ✕${b.members_inactivated || 0}
                        </div>
                    </td>
                    <td style="font-size:.78rem; color:#475569;">${esc(b.uploaded_by_name || '-')}</td>
                    <td style="font-size:.78rem; color:#475569;">${esc(b.uploaded_at)}</td>
                    <td>
                        <button class="bs-btn-primary" onclick="bsOpenDrawer(${b.id})">
                            <i class="fa-solid fa-eye"></i> ดู
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        const p = payload.pagination;
        if (!p) {
            // Backend returned no pagination block — skip pagination render to avoid TypeError
            bsLoadStats();
            return;
        }
        document.getElementById('bsTotalInfo').textContent =
            `หน้า ${p.page} / ${p.total_pages} · รวม ${p.total.toLocaleString()} เอกสาร`;
        renderPagination('bsPagination', p, bsLoad);
        bsLoadStats();
    };

    window.bsOpenDrawer = async function(id) {
        document.getElementById('bsDrawer').style.display = 'block';
        document.body.style.overflow = 'hidden';
        document.getElementById('bsDrawerTitle').textContent = 'กำลังโหลด...';
        document.getElementById('bsDrawerBody').innerHTML = '<div style="text-align:center; padding:3rem; color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin fa-2x"></i></div>';

        const r = await fetch(`ajax_insurance_batches.php?action=detail&id=${id}`).then(r => r.json());
        if (r.status !== 'ok') {
            document.getElementById('bsDrawerBody').innerHTML = `<div style="color:#dc2626;">${esc(r.message || 'load error')}</div>`;
            return;
        }
        const d = r.data || r;
        const b = d.batch;
        document.getElementById('bsDrawerTitle').innerHTML = `<i class="fa-solid fa-file-lines mr-1"></i> ${esc(b.batch_code)} ${statusBadge(b.status)}`;

        // Action buttons
        let actions = '';
        if (d.can_approve && ['pending_review', 'uploaded'].includes(b.status)) {
            actions = `
                <div style="display:flex; gap:.5rem; margin-top:1rem;">
                    <button class="bs-btn-success" onclick="bsApprove(${b.id})"><i class="fa-solid fa-check"></i> อนุมัติ</button>
                    <button class="bs-btn-danger" onclick="bsReject(${b.id})"><i class="fa-solid fa-xmark"></i> ตีกลับ</button>
                </div>
            `;
        } else if (d.can_approve && b.status === 'approved') {
            actions = `
                <div style="display:flex; gap:.5rem; margin-top:1rem;">
                    <button class="bs-btn-danger" onclick="bsReject(${b.id})"><i class="fa-solid fa-xmark"></i> ตีกลับ (ยกเลิกการอนุมัติ)</button>
                </div>
            `;
        }

        // Stepper full
        const stepperFull = stepperHtml(b.status, false);

        // Events
        const events = (d.events || []).map(ev => `
            <div class="bs-event-item">
                <div class="bs-event-icon"><i class="fa-solid fa-${eventIcon(ev.event_type)}"></i></div>
                <div style="flex:1; min-width:0;">
                    <div style="font-weight:700; color:#0f172a; font-size:.85rem;">
                        ${esc(eventLabel(ev.event_type))}
                        ${ev.from_status && ev.to_status ? ` <span style="color:#94a3b8; font-weight:500;">${esc(ev.from_status)} → ${esc(ev.to_status)}</span>` : ''}
                    </div>
                    <div style="font-size:.75rem; color:#64748b; margin-top:.15rem;">
                        <i class="fa-regular fa-user mr-1"></i>${esc(ev.actor_name || ev.actor_type)}
                        · <i class="fa-regular fa-clock mr-1"></i>${esc(ev.created_at)}
                    </div>
                    ${ev.details ? `<div style="font-size:.78rem; color:#475569; margin-top:.35rem; padding:.5rem .65rem; background:#f8fafc; border-radius:.4rem;">${esc(ev.details)}</div>` : ''}
                </div>
            </div>
        `).join('');

        document.getElementById('bsDrawerBody').innerHTML = `
            <div style="background:#f8fafc; border-radius:.85rem; padding:1.25rem; margin-bottom:1.25rem;">
                <div style="font-size:.78rem; color:#64748b; font-weight:700; text-transform:uppercase; margin-bottom:.65rem;">ความคืบหน้า</div>
                ${stepperFull}
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:.85rem; margin-bottom:1.25rem;">
                <div class="bs-stat" style="cursor:default;">
                    <div style="font-size:.7rem; color:#64748b; font-weight:700; text-transform:uppercase;">รายชื่อทั้งหมด</div>
                    <div style="font-size:1.4rem; font-weight:900; color:#0f172a;">${(b.total_members||0).toLocaleString()}</div>
                </div>
                <div class="bs-stat" style="cursor:default;">
                    <div style="font-size:.7rem; color:#64748b; font-weight:700; text-transform:uppercase;">ออกกรมธรรม์แล้ว</div>
                    <div style="font-size:1.4rem; font-weight:900; color:#059669;">${(b.members_with_policy||0).toLocaleString()}/${(b.total_members||0).toLocaleString()}</div>
                </div>
            </div>

            <div style="background:#fff; border:1.5px solid #e2e8f0; border-radius:.85rem; padding:1rem 1.25rem; margin-bottom:1.25rem;">
                <div style="display:grid; grid-template-columns:auto 1fr; gap:.4rem .85rem; font-size:.85rem;">
                    <div style="color:#64748b;">รหัสเอกสาร:</div><div style="font-weight:700;"><code>${esc(b.batch_code)}</code></div>
                    <div style="color:#64748b;">โหมด:</div><div>${esc(b.upload_mode)} (${esc(b.source_type)})</div>
                    <div style="color:#64748b;">บริษัทประกัน:</div><div>${esc(b.company_name || b.insurance_company)}</div>
                    <div style="color:#64748b;">อัพโหลดโดย:</div><div>${esc(b.uploaded_by_name || '-')}</div>
                    <div style="color:#64748b;">เมื่อ:</div><div>${esc(b.uploaded_at)}</div>
                    ${b.reviewed_at ? `
                    <div style="color:#64748b;">ตรวจสอบโดย:</div><div>${esc(b.reviewed_by_name || '-')}</div>
                    <div style="color:#64748b;">เมื่อ:</div><div>${esc(b.reviewed_at)}</div>
                    ${b.review_note ? `<div style="color:#64748b;">หมายเหตุ:</div><div>${esc(b.review_note)}</div>` : ''}
                    ` : ''}
                    ${b.first_downloaded_at ? `<div style="color:#64748b;">ดาวน์โหลด:</div><div>${esc(b.first_downloaded_at)} (${b.download_count} ครั้ง)</div>` : ''}
                    ${b.first_policy_returned_at ? `<div style="color:#64748b;">ออกกรมธรรม์แรก:</div><div>${esc(b.first_policy_returned_at)}</div>` : ''}
                    ${b.completed_at ? `<div style="color:#64748b;">เสร็จสิ้น:</div><div style="color:#059669; font-weight:700;">${esc(b.completed_at)}</div>` : ''}
                </div>
                ${actions}
            </div>

            <div style="background:#fff; border:1.5px solid #e2e8f0; border-radius:.85rem; padding:1rem 1.25rem; margin-bottom:1.25rem;">
                <div style="font-weight:800; color:#0f172a; margin-bottom:.85rem;">
                    <i class="fa-solid fa-clock-rotate-left mr-1"></i> Timeline
                </div>
                ${events || '<div style="color:#94a3b8; font-size:.85rem;">ยังไม่มีกิจกรรม</div>'}
            </div>

            <div style="background:#fff; border:1.5px solid #e2e8f0; border-radius:.85rem; padding:1rem 1.25rem;">
                <div style="font-weight:800; color:#0f172a; margin-bottom:.85rem; display:flex; align-items:center; justify-content:space-between;">
                    <span><i class="fa-solid fa-users mr-1"></i> รายชื่อใน batch (${(d.member_count||0).toLocaleString()} ราย)</span>
                </div>
                <div id="bsMemberList"><div style="color:#94a3b8; font-size:.85rem;">กำลังโหลด...</div></div>
                <div id="bsMemberPagination" class="bs-pagination"></div>
            </div>
        `;

        bsLoadMembers(b.id, 1);
    };

    function eventIcon(type) {
        const m = {
            uploaded: 'cloud-arrow-up', approved: 'check', rejected: 'xmark',
            downloaded: 'paper-plane', policy_imported: 'shield-heart',
            completed: 'circle-check', note_added: 'comment', status_auto_change: 'rotate',
        };
        return m[type] || 'circle-info';
    }
    function eventLabel(type) {
        const m = {
            uploaded: 'อัพโหลดเอกสาร', approved: 'อนุมัติเอกสาร', rejected: 'ตีกลับเอกสาร',
            downloaded: 'ดาวน์โหลดโดย Partner', policy_imported: 'นำเข้าเลขกรมธรรม์',
            completed: 'เสร็จสิ้นสมบูรณ์', note_added: 'เพิ่มหมายเหตุ',
            status_auto_change: 'เปลี่ยนสถานะอัตโนมัติ',
        };
        return m[type] || type;
    }

    window.bsLoadMembers = async function(batchId, page) {
        const r = await fetch(`ajax_insurance_batches.php?action=members&id=${batchId}&page=${page}`).then(r => r.json());
        if (r.status !== 'ok') { document.getElementById('bsMemberList').innerHTML = `<div style="color:#dc2626;">${esc(r.message)}</div>`; return; }
        const p = (r.data || r).pagination;
        const rows = (r.data || r).data || [];
        if (!rows.length) {
            document.getElementById('bsMemberList').innerHTML = '<div style="color:#94a3b8;">ไม่มีรายชื่อ</div>';
            return;
        }
        document.getElementById('bsMemberList').innerHTML = `
            <table class="bs-table" style="font-size:.78rem;">
                <thead><tr><th>รหัส</th><th>ชื่อ-สกุล</th><th>การเปลี่ยนแปลง</th><th>เลขกรมธรรม์</th></tr></thead>
                <tbody>${rows.map(m => `
                    <tr>
                        <td><code>${esc(m.member_id)}</code></td>
                        <td>${esc(m.full_name || '-')}</td>
                        <td>
                            ${m.change_type === 'inserted' ? '<span class="bs-badge" style="background:#dcfce7;color:#15803d;">+ เพิ่มใหม่</span>' :
                              m.change_type === 'inactivated' ? '<span class="bs-badge" style="background:#fee2e2;color:#991b1b;">✕ Inactive</span>' :
                              '<span class="bs-badge" style="background:#fef3c7;color:#92400e;">↻ Update</span>'}
                        </td>
                        <td>${m.policy_number ? `<code style="color:#059669;">${esc(m.policy_number)}</code>` : '<span style="color:#94a3b8;">รอออก</span>'}</td>
                    </tr>`).join('')}
                </tbody>
            </table>
            <div style="font-size:.75rem; color:#64748b; margin-top:.5rem;">หน้า ${p.page} / ${p.total_pages} · รวม ${p.total.toLocaleString()} ราย</div>
        `;
        renderPagination('bsMemberPagination', p, (newPage) => bsLoadMembers(batchId, newPage));
    };

    window.bsApprove = async function(id) {
        const note = prompt('หมายเหตุการอนุมัติ (ไม่บังคับ):', '');
        if (note === null) return;
        const fd = new FormData();
        fd.append('action', 'approve');
        fd.append('id', id);
        fd.append('note', note);
        fd.append('csrf_token', CSRF);
        const r = await fetch('ajax_insurance_batches.php', { method: 'POST', body: fd }).then(r => r.json());
        if (r.status !== 'ok') { alert(r.message); return; }
        bsCloseDrawer(); bsLoad(currentPage);
    };
    window.bsReject = async function(id) {
        const note = prompt('เหตุผลการตีกลับ (จำเป็น):', '');
        if (!note || !note.trim()) return;
        const fd = new FormData();
        fd.append('action', 'reject');
        fd.append('id', id);
        fd.append('note', note.trim());
        fd.append('csrf_token', CSRF);
        const r = await fetch('ajax_insurance_batches.php', { method: 'POST', body: fd }).then(r => r.json());
        if (r.status !== 'ok') { alert(r.message); return; }
        bsCloseDrawer(); bsLoad(currentPage);
    };
    window.bsCloseDrawer = function(e) {
        if (e && e.target.id !== 'bsDrawer') return;
        document.getElementById('bsDrawer').style.display = 'none';
        document.body.style.overflow = '';
    };

    function renderPagination(elId, p, cb) {
        const el = document.getElementById(elId);
        if (!p || p.total_pages <= 1) { el.innerHTML = ''; return; }
        const first = Math.max(1, p.page - 2);
        const last  = Math.min(p.total_pages, p.page + 2);
        let html = `<div style="font-size:.78rem; color:#475569;">หน้า ${p.page} / ${p.total_pages} · รวม ${p.total.toLocaleString()} รายการ</div><div style="display:flex; gap:.2rem;">`;
        const btn = (lbl, page, opts = {}) => {
            const dis = opts.disabled ? 'disabled' : '';
            const act = opts.active ? 'active' : '';
            const handler = dis ? '' : `onclick="(${cb.toString()})(${typeof page === 'number' ? page : `'${page}'`})"`;
            return `<button class="bs-page-btn ${dis} ${act}" ${dis ? 'disabled' : handler}>${lbl}</button>`;
        };
        html += btn('«', 1, { disabled: p.page <= 1 });
        html += btn('‹', p.page - 1, { disabled: p.page <= 1 });
        for (let i = first; i <= last; i++) html += btn(i, i, { active: i === p.page });
        html += btn('›', p.page + 1, { disabled: p.page >= p.total_pages });
        html += btn('»', p.total_pages, { disabled: p.page >= p.total_pages });
        html += '</div>';
        el.innerHTML = html;
    }

    document.getElementById('bsSearch').addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); bsLoad(1); }
    });
    document.getElementById('bsStatusFilter').addEventListener('change', () => bsLoad(1));

    // Init
    bsLoad(1);
})();
</script>
