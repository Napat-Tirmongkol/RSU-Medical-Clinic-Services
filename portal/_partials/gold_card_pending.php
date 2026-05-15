<?php
/**
 * portal/_partials/gold_card_pending.php
 * "ใบสมัครรออนุมัติ" — review queue เฉพาะ status=submitted/pending
 *
 * แยกจาก gold_card.php (ที่จัดการสมาชิกที่ approve แล้ว)
 * UX optimized for review: card-based list + photo/signature thumbnails inline
 *
 * Auth: superadmin หรือ access_insurance
 * Loaded by portal/index.php (มี portal_CSRF + SweetAlert2 พร้อม)
 */
declare(strict_types=1);

if (!isset($pdo)) $pdo = db();

// Pending count for header — เฉพาะ user submit ผ่าน LIFF (status=submitted)
// ไม่นับ status=pending จาก bulk import เพราะอยู่อีก section
$pendingCount = 0;
try {
    $pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM gold_card_members WHERE status = 'submitted'")->fetchColumn();
} catch (PDOException $e) { /* table may not exist yet */ }

$gcpEndpoint = 'ajax_gold_card.php';
$gcpCsrfToken = function_exists('get_csrf_token') ? get_csrf_token() : ($_SESSION['csrf_token'] ?? '');
?>
<style>
    #section-gold_card_pending .gcp-card { background:#fff; border:1px solid #e2e8f0; border-radius:18px; padding:18px; transition:all 0.2s; }
    #section-gold_card_pending .gcp-card:hover { border-color:#fbbf24; box-shadow:0 8px 20px rgba(245,158,11,0.08); }
    #section-gold_card_pending .gcp-thumb { width:88px; height:88px; border-radius:14px; border:2px solid #e2e8f0; overflow:hidden; cursor:pointer; position:relative; background:#fff; flex-shrink:0; }
    #section-gold_card_pending .gcp-thumb:hover { border-color:#f59e0b; }
    #section-gold_card_pending .gcp-thumb img { width:100%; height:100%; object-fit:cover; }
    #section-gold_card_pending .gcp-thumb-sig img { object-fit:contain; }
    #section-gold_card_pending .gcp-thumb-tag { position:absolute; top:4px; left:4px; padding:2px 6px; border-radius:4px; background:rgba(0,0,0,0.6); color:white; font-size:9px; font-weight:900; }
    #section-gold_card_pending .gcp-thumb-empty { width:88px; height:88px; border-radius:14px; border:2px dashed #cbd5e1; background:#f8fafc; display:flex; flex-direction:column; align-items:center; justify-content:center; color:#cbd5e1; font-size:11px; font-weight:700; flex-shrink:0; }
    #section-gold_card_pending .gcp-meta-row { display:grid; grid-template-columns:90px 1fr; gap:6px; font-size:13px; padding:3px 0; }
    #section-gold_card_pending .gcp-meta-row dt { color:#94a3b8; font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:0.05em; }
    #section-gold_card_pending .gcp-meta-row dd { color:#1e293b; font-weight:700; }
    #section-gold_card_pending .gcp-action-btn { padding:10px 16px; border-radius:12px; font-weight:900; font-size:13px; transition:all 0.15s; display:inline-flex; align-items:center; gap:6px; }
    #section-gold_card_pending .gcp-action-btn:active { transform:scale(0.95); }
    #section-gold_card_pending .gcp-pager-btn { width:36px; height:36px; border-radius:10px; border:1px solid #e2e8f0; background:white; font-weight:700; color:#64748b; font-size:13px; transition:all 0.15s; }
    #section-gold_card_pending .gcp-pager-btn:hover:not(:disabled) { background:#fef3c7; border-color:#f59e0b; color:#b45309; }
    #section-gold_card_pending .gcp-pager-btn:disabled { opacity:0.4; cursor:not-allowed; }
    #section-gold_card_pending .gcp-pager-btn.gcp-active { background:#f59e0b; border-color:#f59e0b; color:white; }
    #section-gold_card_pending .gcp-empty { padding:60px 20px; text-align:center; }
    .gcp-lightbox-dl:hover { background:#0284c7 !important; transform:translateY(-1px); box-shadow:0 10px 22px rgba(14,165,233,0.4) !important; }
    .gcp-lightbox-dl:active { transform:translateY(0); }
    @media (max-width: 640px) {
        #section-gold_card_pending .gcp-card-inner { flex-direction:column; gap:14px; }
        #section-gold_card_pending .gcp-thumb-stack { flex-direction:row; }
    }

    /* ── Bold & Colorful — tilt-aware lift on pending request cards ── */
    #section-gold_card_pending .gcp-card { isolation: isolate; }
    #section-gold_card_pending .gcp-card:hover:not(.fx-tilt) { transform: translateY(-3px); box-shadow:0 18px 36px -18px rgba(245,158,11,.25); }
    #section-gold_card_pending .gcp-card.fx-tilt:hover { --lift: -3px; box-shadow:0 18px 36px -18px rgba(245,158,11,.30); }

    /* ── DARK MODE ──────────────────────────────────────────────── */
    body[data-theme='dark'] #section-gold_card_pending .gcp-card { background:#0f172a; border-color:#1e293b; box-shadow: 0 1px 0 rgba(255,255,255,.04), 0 8px 22px rgba(0,0,0,.35); }
    body[data-theme='dark'] #section-gold_card_pending .gcp-card:hover { border-color:#f59e0b; }
    body[data-theme='dark'] #section-gold_card_pending .gcp-thumb { background:#1e293b; border-color:#334155; }
    body[data-theme='dark'] #section-gold_card_pending .gcp-thumb:hover { border-color:#f59e0b; }
    body[data-theme='dark'] #section-gold_card_pending .gcp-thumb-empty { background: rgba(148,163,184,.08); border-color:#334155; color:#475569; }
    body[data-theme='dark'] #section-gold_card_pending .gcp-meta-row dt { color:#94a3b8; }
    body[data-theme='dark'] #section-gold_card_pending .gcp-meta-row dd { color:#f1f5f9; }
    body[data-theme='dark'] #section-gold_card_pending .gcp-pager-btn { background:#0f172a; border-color:#1e293b; color:#cbd5e1; }
    body[data-theme='dark'] #section-gold_card_pending .gcp-pager-btn:hover:not(:disabled) { background: rgba(245,158,11,.16); border-color:#f59e0b; color:#fbbf24; }
    body[data-theme='dark'] #section-gold_card_pending .gcp-pager-btn.gcp-active { background:#f59e0b; border-color:#f59e0b; color:#0f172a; }
    body[data-theme='dark'] #section-gold_card_pending .gcp-empty { color:#64748b; }

    body[data-theme='dark'] #section-gold_card_pending .bg-white { background:#0f172a !important; }
    body[data-theme='dark'] #section-gold_card_pending .bg-slate-50 { background: rgba(148,163,184,.08) !important; }
    body[data-theme='dark'] #section-gold_card_pending .bg-gradient-to-br.from-blue-50 {
        background: linear-gradient(135deg, rgba(59,130,246,.12), #0f172a 55%, rgba(99,102,241,.12)) !important;
    }
    body[data-theme='dark'] #section-gold_card_pending .text-slate-800 { color:#f1f5f9 !important; }
    body[data-theme='dark'] #section-gold_card_pending .text-slate-700 { color:#e2e8f0 !important; }
    body[data-theme='dark'] #section-gold_card_pending .text-slate-600 { color:#cbd5e1 !important; }
    body[data-theme='dark'] #section-gold_card_pending .text-slate-500 { color:#94a3b8 !important; }
    body[data-theme='dark'] #section-gold_card_pending .text-slate-400 { color:#64748b !important; }
    body[data-theme='dark'] #section-gold_card_pending .text-slate-300 { color:#475569 !important; }
    body[data-theme='dark'] #section-gold_card_pending .text-blue-600 { color:#93c5fd !important; }
    body[data-theme='dark'] #section-gold_card_pending .text-blue-500 { color:#60a5fa !important; }
    body[data-theme='dark'] #section-gold_card_pending .border-slate-200 { border-color:#1e293b !important; }
    body[data-theme='dark'] #section-gold_card_pending .border-slate-100 { border-color:#1e293b !important; }
    body[data-theme='dark'] #section-gold_card_pending .border-blue-200 { border-color: rgba(59,130,246,.30) !important; }

    @media (prefers-reduced-motion: reduce) {
        #section-gold_card_pending .gcp-card { transition: none !important; transform: none !important; }
    }
</style>

<div class="space-y-6 px-4 sm:px-6 py-6 max-w-6xl mx-auto">

    <!-- ── Header ────────────────────────────────────────────────────── -->
    <div class="bg-gradient-to-br from-blue-50 via-white to-indigo-50 border border-blue-200 rounded-[2rem] p-6 shadow-sm">
        <div class="flex flex-wrap items-start gap-5">
            <div class="w-14 h-14 rounded-2xl bg-white shadow-sm flex items-center justify-center text-blue-500 text-2xl shrink-0">
                <i class="fa-solid fa-hourglass-half"></i>
            </div>
            <div class="flex-1 min-w-[250px]">
                <h2 class="text-base font-black text-slate-800">📥 ย้ายสิทธิ์บัตรทอง</h2>
                <p class="text-xs font-bold text-slate-500 mt-1 leading-relaxed">
                    คิวคำขอย้ายสิทธิ์บัตรทองจาก user ผ่าน LINE — ตรวจสอบรูปและลายเซ็นแล้วกดอนุมัติ/ไม่อนุมัติ
                    <br><span class="text-blue-600">→ หลังอนุมัติ ระบบจะตั้งวันคุ้มครอง 1 ปีอัตโนมัติ</span>
                </p>
            </div>
            <div class="text-right">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">รอตรวจ</p>
                <p class="text-3xl font-black text-blue-600" id="gcpHeaderCount"><?= number_format($pendingCount) ?></p>
                <p class="text-[10px] font-bold text-slate-400 mt-1">รายการ</p>
            </div>
        </div>
    </div>

    <!-- ── Toolbar ────────────────────────────────────────────────────── -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm px-5 py-4 flex flex-wrap items-center gap-3">
        <div class="relative flex-1 min-w-[220px]">
            <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 text-xs"></i>
            <input type="text" id="gcpSearch" placeholder="ค้นหาชื่อ / เลขบัตรประชาชน / เบอร์โทร..."
                class="h-10 pl-9 pr-4 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-blue-500/10 outline-none w-full">
        </div>
        <select id="gcpType" onchange="gcpLoadList(1)"
            class="h-10 px-4 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-blue-500/10 outline-none">
            <option value="">ทุกประเภท</option>
            <option value="บุคลากร">บุคลากร</option>
            <option value="นักศึกษา">นักศึกษา</option>
            <option value="บุคคลทั่วไป">บุคคลทั่วไป</option>
        </select>
        <button onclick="gcpLoadList(1)" class="h-10 px-4 bg-blue-500 hover:bg-blue-600 text-white font-black rounded-xl text-sm active:scale-95 transition-all flex items-center gap-2">
            <i class="fa-solid fa-rotate"></i> รีเฟรช
        </button>
    </div>

    <!-- ── List wrapper ────────────────────────────────────────────────── -->
    <div id="gcpListWrap" class="space-y-3">
        <div class="gcp-empty bg-white rounded-2xl border border-slate-100">
            <i class="fa-solid fa-spinner fa-spin text-3xl text-slate-300 mb-2"></i>
            <p class="text-sm font-bold text-slate-400">กำลังโหลด...</p>
        </div>
    </div>

    <!-- ── Pagination ──────────────────────────────────────────────────── -->
    <div id="gcpPagerWrap" class="hidden flex-wrap items-center justify-between gap-3 pt-2">
        <p id="gcpPagerInfo" class="text-xs font-bold text-slate-500"></p>
        <div id="gcpPager" class="flex items-center gap-1"></div>
    </div>
</div>

<script>
(function(){
    const ENDPOINT = '<?= $gcpEndpoint ?>';
    const CSRF = '<?= htmlspecialchars($gcpCsrfToken, ENT_QUOTES) ?>';
    const PAGE_SIZE = 20;
    let currentPage = 1;

    function gcpPost(entity, action, extra = {}) {
        const body = new FormData();
        body.append('entity', entity);
        body.append('action', action);
        body.append('csrf_token', CSRF);
        for (const [k, v] of Object.entries(extra)) body.append(k, v);
        return fetch(ENDPOINT, { method: 'POST', body, credentials: 'same-origin' }).then(r => r.json());
    }

    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function maskCid(cid) {
        if (!cid || cid.length !== 13) return cid || '—';
        return `${cid.substr(0,1)}-${cid.substr(1,4)}-${cid.substr(5,5)}-${cid.substr(10,2)}-${cid.substr(12,1)}`;
    }

    function thaiDate(d) {
        if (!d) return '—';
        const dt = new Date(d);
        if (isNaN(dt)) return d;
        return dt.toLocaleDateString('th-TH', { year:'numeric', month:'short', day:'numeric' });
    }

    function genderLabel(g) {
        return ({male:'ชาย', female:'หญิง', other:'อื่นๆ'})[g] || (g || '—');
    }

    window.gcpLoadList = function(page) {
        currentPage = page || 1;
        window._gcpCurrentPage = currentPage; // expose สำหรับ modal close handler
        const search = document.getElementById('gcpSearch').value.trim();
        const type   = document.getElementById('gcpType').value;

        gcpPost('member', 'list', {
            page: currentPage,
            page_size: PAGE_SIZE,
            search,
            type,
            status: 'submitted',
            include_docs: 1,
        }).then(async r => {
            if (r.status !== 'ok') {
                document.getElementById('gcpListWrap').innerHTML = `<div class="gcp-empty bg-white rounded-2xl border border-rose-200 text-rose-500 font-bold">${r.message || 'โหลดไม่สำเร็จ'}</div>`;
                return;
            }
            // Also fetch pending in same call would need API change — for now just submitted
            // (Most user submits = 'submitted'. 'pending' is for incomplete/legacy)
            renderList(r.rows || [], r.total || 0, r.page || 1, r.pages || 1);
            updateHeaderCount();
        });
    };

    async function updateHeaderCount() {
        try {
            const r = await gcpPost('member', 'list', { page: 1, page_size: 1, status: 'submitted' });
            if (r.status === 'ok') {
                document.getElementById('gcpHeaderCount').textContent = (r.total || 0).toLocaleString();
            }
        } catch (e) { /* silent */ }
    }

    function renderList(rows, total, page, pages) {
        const wrap = document.getElementById('gcpListWrap');
        if (!rows.length) {
            wrap.innerHTML = `
                <div class="gcp-empty bg-white rounded-2xl border border-slate-100">
                    <i class="fa-solid fa-circle-check text-4xl text-emerald-300 mb-3"></i>
                    <p class="text-base font-black text-slate-700">ไม่มีใบสมัครรออนุมัติ</p>
                    <p class="text-xs font-bold text-slate-400 mt-1">เมื่อมี user ส่งใบสมัครจาก LINE จะแสดงที่นี่</p>
                </div>`;
            renderPager(0, 0, 0);
            return;
        }
        wrap.innerHTML = rows.map(r => {
            const photoDoc = (r.documents || []).find(d => d.doc_type === 'photo');
            const sigDoc   = (r.documents || []).find(d => d.doc_type === 'signature');
            const photoUrl = photoDoc ? `${ENDPOINT}?entity=document&action=download&id=${photoDoc.id}` : '';
            const sigUrl   = sigDoc   ? `${ENDPOINT}?entity=document&action=download&id=${sigDoc.id}` : '';
            const safeName = (r.full_name || 'member').replace(/[^฀-๿a-zA-Z0-9_-]+/g, '_').slice(0, 60) || 'member';
            const photoFile = (photoDoc && photoDoc.file_name) || `${safeName}_photo.jpg`;
            const sigFile   = (sigDoc && sigDoc.file_name) || `${safeName}_signature.png`;
            const photoFileJs = escapeHtml(photoFile).replace(/'/g, "\\'");
            const sigFileJs   = escapeHtml(sigFile).replace(/'/g, "\\'");

            return `<div class="gcp-card fx-tilt fx-tilt-light" data-tilt="3">
                <div class="gcp-card-inner flex gap-4 items-start">
                    <div class="gcp-thumb-stack flex flex-col gap-2 shrink-0">
                        ${photoUrl
                            ? `<div class="gcp-thumb" onclick="gcpLightbox('${photoUrl}','photo','${photoFileJs}')">
                                <span class="gcp-thumb-tag">รูป</span>
                                <img src="${photoUrl}" alt="photo">
                               </div>`
                            : `<div class="gcp-thumb-empty"><i class="fa-solid fa-image text-2xl mb-1"></i>ไม่มีรูป</div>`}
                        ${sigUrl
                            ? `<div class="gcp-thumb gcp-thumb-sig" onclick="gcpLightbox('${sigUrl}','signature','${sigFileJs}')">
                                <span class="gcp-thumb-tag">เซ็น</span>
                                <img src="${sigUrl}" alt="signature">
                               </div>`
                            : `<div class="gcp-thumb-empty"><i class="fa-solid fa-signature text-2xl mb-1"></i>ไม่มีเซ็น</div>`}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-start justify-between gap-3 flex-wrap mb-2">
                            <div class="flex-1 min-w-0">
                                <h3 class="text-base font-black text-slate-900 truncate">${escapeHtml(r.full_name || '—')}</h3>
                                <span class="inline-flex px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 text-[10px] font-black uppercase tracking-widest mt-1">
                                    <i class="fa-solid fa-paper-plane mr-1"></i> ส่งใบสมัครเมื่อ ${thaiDate(r.created_at)}
                                </span>
                            </div>
                        </div>
                        <dl class="space-y-0.5 mb-3">
                            <div class="gcp-meta-row"><dt>เลขบัตรประชาชน</dt><dd class="font-mono">${maskCid(r.citizen_id || '')}</dd></div>
                            <div class="gcp-meta-row"><dt>เพศ / วันเกิด</dt><dd>${genderLabel(r.gender)} ${r.date_of_birth ? '· เกิด ' + thaiDate(r.date_of_birth) : ''}</dd></div>
                            <div class="gcp-meta-row"><dt>เบอร์โทร</dt><dd>${escapeHtml(r.phone || '—')}</dd></div>
                            <div class="gcp-meta-row"><dt>ประเภท</dt><dd>${escapeHtml(r.member_type || 'บุคคลทั่วไป')}</dd></div>
                            ${r.remarks ? `<div class="gcp-meta-row"><dt>หมายเหตุ</dt><dd class="text-slate-600 text-xs whitespace-pre-line">${escapeHtml(r.remarks)}</dd></div>` : ''}
                        </dl>
                        <div class="flex flex-wrap gap-2">
                            <button onclick="gcpApprove(${r.id}, '${escapeHtml(r.full_name || '').replace(/'/g, "\\'")}')" class="gcp-action-btn bg-emerald-500 hover:bg-emerald-600 text-white shadow-md shadow-emerald-200">
                                <i class="fa-solid fa-circle-check"></i> อนุมัติ
                            </button>
                            <button onclick="gcpReject(${r.id}, '${escapeHtml(r.full_name || '').replace(/'/g, "\\'")}')" class="gcp-action-btn bg-rose-500 hover:bg-rose-600 text-white shadow-md shadow-rose-200">
                                <i class="fa-solid fa-circle-xmark"></i> ไม่อนุมัติ
                            </button>
                            <button onclick="gcpSendMessage(${r.id}, '${escapeHtml(r.full_name || '').replace(/'/g, "\\'")}', ${r.linked_user_id ? 'true' : 'false'})" class="gcp-action-btn bg-purple-50 hover:bg-purple-100 text-purple-700 border border-purple-200" ${!r.linked_user_id ? 'title="ผู้สมัครยังไม่ได้ผูก LINE"' : ''}>
                                <i class="fa-solid fa-comment-dots"></i> ส่งข้อความ
                            </button>
                            <button onclick="gcpOpenDetail(${r.id})" class="gcp-action-btn bg-amber-50 hover:bg-amber-100 text-amber-700 border border-amber-200">
                                <i class="fa-solid fa-pen-to-square"></i> ดู / แก้ไข
                            </button>
                        </div>
                    </div>
                </div>
            </div>`;
        }).join('');
        if (window.RsuFx && typeof RsuFx.refresh === 'function') RsuFx.refresh(wrap);
        renderPager(page, pages, total);
    }

    function renderPager(page, pages, total) {
        const wrap = document.getElementById('gcpPagerWrap');
        const info = document.getElementById('gcpPagerInfo');
        const pager = document.getElementById('gcpPager');
        if (pages <= 1) { wrap.classList.add('hidden'); return; }
        wrap.classList.remove('hidden');
        wrap.classList.add('flex');
        info.textContent = `หน้า ${page} / ${pages} · รวม ${total.toLocaleString()} รายการ`;

        let html = `<button class="gcp-pager-btn" onclick="gcpLoadList(1)" ${page<=1?'disabled':''}>«</button>
                    <button class="gcp-pager-btn" onclick="gcpLoadList(${page-1})" ${page<=1?'disabled':''}>‹</button>`;
        const start = Math.max(1, page - 2);
        const end   = Math.min(pages, page + 2);
        if (start > 1) html += `<span class="text-slate-300 px-1">…</span>`;
        for (let i = start; i <= end; i++) {
            html += `<button class="gcp-pager-btn ${i===page?'gcp-active':''}" onclick="gcpLoadList(${i})">${i}</button>`;
        }
        if (end < pages) html += `<span class="text-slate-300 px-1">…</span>`;
        html += `<button class="gcp-pager-btn" onclick="gcpLoadList(${page+1})" ${page>=pages?'disabled':''}>›</button>
                 <button class="gcp-pager-btn" onclick="gcpLoadList(${pages})" ${page>=pages?'disabled':''}>»</button>`;
        pager.innerHTML = html;
    }

    window.gcpLightbox = function(url, type, fileName) {
        const isSignature = type === 'signature';
        const safeName = fileName || (isSignature ? 'signature.png' : 'photo.jpg');
        const dlUrl = url + '&disposition=attachment';
        const dlUrlAttr  = escapeHtml(dlUrl);
        const dlNameAttr = escapeHtml(safeName);
        Swal.fire({
            html: `<div style="display:flex; align-items:center; justify-content:center; min-height:60vh;">
                       <img src="${url}"
                            style="max-width:100%; max-height:80vh; width:auto; height:auto; object-fit:contain;
                                   border-radius:12px; ${isSignature ? 'background:#fff; padding:24px;' : ''}
                                   box-shadow:0 20px 50px rgba(0,0,0,0.3);"
                            alt="${type}">
                   </div>
                   <div style="display:flex; align-items:center; justify-content:center; gap:14px; margin-top:14px; flex-wrap:wrap;">
                       <p style="color:#64748b; font-weight:700; font-size:13px; margin:0;">
                           ${isSignature ? '✍️ ลายมือชื่อ' : '📷 รูปถ่ายคู่บัตรประชาชน'}
                       </p>
                       <a href="${dlUrlAttr}" download="${dlNameAttr}" class="gcp-lightbox-dl"
                          style="display:inline-flex; align-items:center; gap:8px; padding:9px 16px; border-radius:10px; background:#0ea5e9; color:#fff; font-weight:900; font-size:13px; text-decoration:none; box-shadow:0 6px 16px rgba(14,165,233,0.3); transition:all 0.15s;">
                           <i class="fa-solid fa-download"></i> ดาวน์โหลดรูป
                       </a>
                   </div>`,
            width: 'auto',
            padding: '20px',
            showConfirmButton: false,
            showCloseButton: true,
            background: '#fff',
            customClass: { popup: 'gcp-lightbox-popup' },
        });
    };

    async function gcpStatusChange(id, name, newStatus) {
        const labels = { approved:'อนุมัติ', rejected:'ไม่อนุมัติ' };
        const colors = { approved:'#10b981', rejected:'#ef4444' };
        const icons  = { approved:'success', rejected:'warning' };

        // Step 1: load member + documents
        const cur = await gcpPost('member', 'get', { id });
        if (cur.status !== 'ok') return Swal.fire({icon:'error', title:'โหลดข้อมูลไม่สำเร็จ', text: cur.message || ''});
        const m    = cur.member    || {};
        const docs = cur.documents || [];
        const hasApprovalDoc = docs.some(d => d.doc_type === 'approval');

        // Step 2: For approved → ensure PDF attached (inline upload if missing)
        if (newStatus === 'approved' && !hasApprovalDoc) {
            const { value: file } = await Swal.fire({
                icon: 'info',
                title: 'แนบเอกสารอนุมัติก่อน',
                html: `<b>${escapeHtml(name)}</b><br><span class="text-slate-500 text-sm">ต้องแนบ "เอกสารอนุมัติจากหน่วยงาน (PDF)" ก่อนกดอนุมัติ</span>`,
                input: 'file',
                inputAttributes: { accept: 'application/pdf' },
                showCancelButton: true,
                confirmButtonText: '📎 อัพโหลด + อนุมัติ',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: colors.approved,
                inputValidator: (value) => {
                    if (!value) return 'กรุณาเลือกไฟล์ PDF';
                    if (value.type !== 'application/pdf') return 'ต้องเป็นไฟล์ PDF เท่านั้น';
                    if (value.size > 20 * 1024 * 1024) return 'ขนาดไฟล์เกิน 20MB';
                }
            });
            if (!file) return;

            Swal.fire({title:'กำลังอัพโหลด...', allowOutsideClick:false, didOpen:()=>Swal.showLoading()});
            const fd = new FormData();
            fd.append('entity', 'document');
            fd.append('action', 'upload');
            fd.append('csrf_token', CSRF);
            fd.append('member_id', id);
            fd.append('doc_type', 'approval');
            fd.append('file', file);
            const upRes  = await fetch(ENDPOINT, { method:'POST', body:fd, credentials:'same-origin' });
            const upJson = await upRes.json();
            if (upJson.status !== 'ok') {
                return Swal.fire({icon:'error', title:'อัพโหลดไม่สำเร็จ', text: upJson.message || ''});
            }
        }

        // Step 3: Confirm (rejected ต้องกรอกเหตุผล / approved ที่มี PDF อยู่แล้ว — ขอยืนยันธรรมดา / approved ที่เพิ่ง upload — ข้าม confirm)
        let confirmedValue = null;
        if (newStatus === 'rejected' || (newStatus === 'approved' && hasApprovalDoc)) {
            const inputOpts = newStatus === 'rejected' ? {
                input: 'textarea',
                inputLabel: 'เหตุผลที่ไม่อนุมัติ (จะบันทึกใน remarks)',
                inputPlaceholder: 'เช่น เอกสารไม่ครบ / ข้อมูลไม่ตรง',
                inputValidator: (v) => !v || v.trim() === '' ? 'กรุณาระบุเหตุผล' : undefined,
            } : {};
            const result = await Swal.fire({
                icon: icons[newStatus],
                title: `ยืนยัน${labels[newStatus]}?`,
                html: `<b>${escapeHtml(name)}</b><br><span class="text-slate-500 text-sm">การกระทำนี้จะแจ้งสถานะให้ผู้สมัครเห็นที่ profile ทันที</span>`,
                showCancelButton: true,
                confirmButtonText: labels[newStatus],
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: colors[newStatus],
                ...inputOpts,
            });
            if (!result.isConfirmed) return;
            confirmedValue = result.value;
        }

        // Step 4: Save status
        const newRemarks = (newStatus === 'rejected' && confirmedValue)
            ? ((m.remarks ? m.remarks + '\n' : '') + `[${new Date().toLocaleDateString('th-TH')}] ไม่อนุมัติ: ${confirmedValue}`)
            : (m.remarks || '');

        let extra = {};
        if (newStatus === 'approved') {
            const today = new Date();
            const oneYear = new Date(today); oneYear.setFullYear(today.getFullYear() + 1);
            extra = {
                coverage_start: m.coverage_start || today.toISOString().slice(0,10),
                coverage_end:   m.coverage_end   || oneYear.toISOString().slice(0,10),
            };
        }

        const saveRes = await gcpPost('member', 'save', {
            id,
            citizen_id:    m.citizen_id    || '',
            full_name:     m.full_name     || '',
            member_type:   m.member_type   || 'บุคคลทั่วไป',
            position:      m.position      || '',
            phone:         m.phone         || '',
            hospital_main: m.hospital_main || '',
            hospital_sub:  m.hospital_sub  || '',
            application_date: m.application_date || '',
            coverage_start:   m.coverage_start   || '',
            coverage_end:     m.coverage_end     || '',
            status:        newStatus,
            remarks:       newRemarks,
            ...extra,
        });

        if (saveRes.status !== 'ok') {
            return Swal.fire({icon:'error', title:'บันทึกไม่สำเร็จ', text: saveRes.message || ''});
        }
        Swal.fire({icon:'success', title:`${labels[newStatus]}สำเร็จ`, timer:1200, showConfirmButton:false});
        gcpLoadList(currentPage);
    }

    window.gcpApprove = (id, name) => gcpStatusChange(id, name, 'approved');
    window.gcpReject  = (id, name) => gcpStatusChange(id, name, 'rejected');

    // Quick message templates (admin click → fill textarea)
    window.GCP_MSG_TEMPLATES = [
        { label: '📷 ขอรูปใหม่',     text: 'รูปถ่ายไม่ชัด/ไม่ตรง กรุณาอัปโหลดรูปคู่บัตรประชาชนใหม่ที่หน้าตรง ไม่ใส่หมวก/แว่นกันแดด' },
        { label: '✍️ ขอเซ็นใหม่',   text: 'ลายมือชื่อไม่ชัด กรุณาเซ็นใหม่อีกครั้งให้ชัดเจน' },
        { label: '📄 เอกสารไม่ครบ', text: 'เอกสารยังไม่ครบ กรุณาเตรียมและติดต่อกลับที่ห้องพยาบาล' },
        { label: '📞 ติดต่อกลับ',   text: 'กรุณาติดต่อกลับที่ห้องพยาบาล โทร 02-791-6000 ต่อ 4499 ในเวลาทำการ' },
    ];
    window.gcpFillTpl = function(idx) {
        const ta = document.getElementById('swal2-textarea');
        if (ta && window.GCP_MSG_TEMPLATES[idx]) ta.value = window.GCP_MSG_TEMPLATES[idx].text;
    };

    window.gcpSendMessage = async function(id, name, hasLineUser) {
        if (!hasLineUser) {
            return Swal.fire({
                icon:'warning',
                title:'ส่งข้อความไม่ได้',
                html:`<b>${escapeHtml(name)}</b><br><span class="text-slate-500 text-sm">ผู้สมัครยังไม่ได้ผูกบัญชี LINE — โปรดติดต่อทางเบอร์โทรแทน</span>`,
            });
        }

        // Build templates HTML (use global array + index — กัน HTML attribute escaping)
        const tplHtml = window.GCP_MSG_TEMPLATES.map((t, i) =>
            `<button type="button" onclick="window.gcpFillTpl(${i})"
                class="m-1 px-3 py-1.5 rounded-lg bg-slate-50 hover:bg-purple-100 border border-slate-200 hover:border-purple-300 text-xs font-bold text-slate-700 hover:text-purple-700 transition-all">${escapeHtml(t.label)}</button>`
        ).join('');

        const result = await Swal.fire({
            title: 'ส่งข้อความผ่าน LINE',
            html: `<div class="text-left mb-2"><b class="text-base">${escapeHtml(name)}</b><br><span class="text-xs text-slate-500">ข้อความจะถูกส่งจาก LINE Official ของคลินิก</span></div>
                   <div class="flex flex-wrap justify-center mb-2 mt-3">${tplHtml}</div>`,
            input: 'textarea',
            inputPlaceholder: 'พิมพ์ข้อความที่ต้องการส่ง... หรือคลิก template ด้านบน',
            inputAttributes: { maxlength: 4000, rows: 5 },
            inputValidator: (v) => !v || v.trim() === '' ? 'กรุณาพิมพ์ข้อความ' : undefined,
            showCancelButton: true,
            confirmButtonText: '📤 ส่งผ่าน LINE',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#a855f7',
            width: '500px',
        });
        if (!result.isConfirmed) return;

        const send = await gcpPost('member', 'send_message', { id, message: result.value });
        if (send.status === 'ok') {
            Swal.fire({icon:'success', title:'ส่งข้อความสำเร็จ', text:`ส่งถึง ${send.recipient || name}`, timer:1800, showConfirmButton:false});
        } else {
            Swal.fire({icon:'error', title:'ส่งไม่สำเร็จ', text: send.message || ''});
        }
    };

    // เปิด edit modal โดยตรง (modal ถูก relocate ไป body แล้ว — เปิดได้ทุก section)
    window.gcpOpenDetail = function(id) {
        if (typeof window.gcOpenMemberModal === 'function') {
            window.gcOpenMemberModal(id);
        } else {
            Swal.fire({icon:'error', title:'ไม่สามารถเปิดหน้าต่างแก้ไข', text:'กรุณา refresh หน้าและลองใหม่'});
        }
    };

    // Search debounce
    let searchTimer = null;
    document.getElementById('gcpSearch').addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => gcpLoadList(1), 350);
    });

    // Initial load
    gcpLoadList(1);
})();
</script>
