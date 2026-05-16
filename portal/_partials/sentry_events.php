<?php
// portal/_partials/sentry_events.php
// Sentry webhook events viewer — read-only + manual "Retry GitHub" action
?>
<style>
.se-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:16px; }
.se-chip { display:inline-flex; align-items:center; gap:5px; padding:4px 12px; border-radius:999px; font-size:12px; font-weight:800; background:#f1f5f9; color:#475569; border:1.5px solid transparent; cursor:pointer; transition:all .2s; }
.se-chip:hover { background:#e2e8f0; }
.se-chip.is-active { background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff; border-color:#7c3aed; box-shadow:0 4px 12px rgba(139,92,246,.30); }
.se-chip[data-pos="closed"] { background:#fee2e2; color:#b91c1c; }
.se-input { width:100%; padding:8px 12px; border:1.5px solid #e2e8f0; border-radius:8px; font-size:13px; font-weight:600; background:#fff; }
.se-input:focus { outline:none; border-color:#7c3aed; box-shadow:0 0 0 3px rgba(124,58,237,.15); }
.se-kpi { display:flex; align-items:center; gap:10px; padding:12px 14px; border-radius:11px; background:#f8fafc; border:1px solid #e2e8f0; }
.se-kpi .ic { width:36px; height:36px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:14px; }
.se-kpi .num { font-size:20px; font-weight:900; color:#0f172a; line-height:1.1; }
.se-kpi .lbl { font-size:10.5px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.4px; margin-top:2px; }
.se-kpi[data-tone="total"]   .ic { background:#ede9fe; color:#6d28d9; }
.se-kpi[data-tone="errors"]  .ic { background:#fee2e2; color:#b91c1c; }
.se-kpi[data-tone="github"]  .ic { background:#dcfce7; color:#15803d; }
.se-kpi[data-tone="failed"]  .ic { background:#fef3c7; color:#b45309; }

.se-table { width:100%; border-collapse:collapse; font-size:12.5px; }
.se-table th { background:#f8fafc; padding:9px 11px; text-align:left; font-weight:800; color:#475569; font-size:11px; text-transform:uppercase; letter-spacing:.4px; border-bottom:2px solid #e2e8f0; }
.se-table td { padding:10px 11px; border-bottom:1px solid #f1f5f9; }
.se-table tr:hover td { background:#fafbfc; }
.se-table .title { font-weight:700; color:#0f172a; }
.se-table .culprit { font-size:11px; color:#94a3b8; font-family:'JetBrains Mono','SF Mono',Consolas,monospace; }
.se-table .id-cell { font-family:'JetBrains Mono',Consolas,monospace; font-size:11px; color:#64748b; }

.se-pill { display:inline-block; padding:1.5px 8px; border-radius:999px; font-size:10.5px; font-weight:800; text-transform:uppercase; letter-spacing:.3px; }
.se-pill-error    { background:#fee2e2; color:#b91c1c; }
.se-pill-warning  { background:#fef3c7; color:#b45309; }
.se-pill-info     { background:#dbeafe; color:#1e40af; }
.se-pill-fatal    { background:#7f1d1d; color:#fff; }
.se-pill-debug    { background:#e0e7ff; color:#3730a3; }
.se-pill-default  { background:#f1f5f9; color:#475569; }
.se-pill-gh-ok    { background:#dcfce7; color:#15803d; }
.se-pill-gh-fail  { background:#fee2e2; color:#b91c1c; }
.se-pill-gh-none  { background:#f1f5f9; color:#94a3b8; }

.se-btn { display:inline-flex; align-items:center; gap:5px; padding:5px 11px; border-radius:7px; font-size:11px; font-weight:800; border:0; cursor:pointer; transition:all .15s; }
.se-btn:hover { transform:translateY(-1px); }
.se-btn-view  { background:#ede9fe; color:#6d28d9; }
.se-btn-view:hover { background:#7c3aed; color:#fff; }
.se-btn-retry { background:#fef3c7; color:#b45309; }
.se-btn-retry:hover { background:#f59e0b; color:#fff; }
.se-btn-gh    { background:#1f2937; color:#fff; text-decoration:none; }
.se-btn-gh:hover { background:#0f172a; }

.se-pager-btn { padding:6px 11px; border-radius:8px; background:#f1f5f9; color:#475569; font-weight:800; font-size:12px; border:0; cursor:pointer; transition:all .15s; }
.se-pager-btn:hover:not(:disabled) { background:#7c3aed; color:#fff; }
.se-pager-btn.is-active { background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff; }
.se-pager-btn:disabled { opacity:.4; cursor:not-allowed; }

/* Modal */
/* Bulk selection bar */
.se-row-checkbox { width:16px; height:16px; cursor:pointer; }
.se-bulk-bar { position:fixed; bottom:20px; left:50%; transform:translateX(-50%) translateY(150%); background:linear-gradient(135deg,#1e293b,#0f172a); color:#fff; border-radius:14px; padding:14px 22px; box-shadow:0 16px 40px rgba(0,0,0,.35), 0 0 0 1px rgba(255,255,255,.08); display:flex; align-items:center; gap:14px; z-index:80; transition:transform .35s cubic-bezier(.16,1,.3,1); }
.se-bulk-bar.is-visible { transform:translateX(-50%) translateY(0); }
.se-bulk-bar .count { font-size:14px; font-weight:900; color:#fff; }
.se-bulk-bar .count b { color:#a78bfa; }
.se-bulk-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border-radius:8px; font-size:12px; font-weight:800; border:0; cursor:pointer; transition:all .15s; }
.se-bulk-btn-danger { background:#ef4444; color:#fff; }
.se-bulk-btn-danger:hover { background:#dc2626; transform:translateY(-1px); }
.se-bulk-btn-ghost  { background:rgba(255,255,255,.10); color:#cbd5e1; }
.se-bulk-btn-ghost:hover { background:rgba(255,255,255,.18); color:#fff; }

#se-modal { position:fixed; inset:0; background:rgba(15,23,42,.6); z-index:9000; display:none; align-items:flex-start; justify-content:center; padding:40px 20px; overflow-y:auto; backdrop-filter:blur(6px); }
#se-modal.is-open { display:flex; }
.se-modal-box { background:#fff; border-radius:14px; max-width:960px; width:100%; padding:24px; box-shadow:0 24px 60px rgba(0,0,0,.4); }
.se-modal-box h3 { margin:0 0 6px; color:#0f172a; font-size:17px; font-weight:900; }
.se-meta { display:grid; grid-template-columns:repeat(2,1fr); gap:8px 18px; font-size:12.5px; margin:14px 0 16px; }
.se-meta dt { color:#94a3b8; font-weight:700; font-size:11px; }
.se-meta dd { color:#0f172a; font-weight:700; margin:0 0 4px; font-family:'JetBrains Mono',Consolas,monospace; word-break:break-all; }
.se-raw { background:#0f172a; color:#86efac; padding:14px; border-radius:9px; font-family:'JetBrains Mono',Consolas,monospace; font-size:11.5px; line-height:1.55; max-height:420px; overflow:auto; white-space:pre-wrap; word-break:break-all; }

/* DARK MODE */
body[data-theme='dark'] .se-card { background:#1e293b !important; border-color:#334155 !important; }
body[data-theme='dark'] .se-chip { background:#334155; color:#cbd5e1; }
body[data-theme='dark'] .se-chip:hover { background:#475569; }
body[data-theme='dark'] .se-input { background:#0f172a; color:#e2e8f0; border-color:#334155; }
body[data-theme='dark'] .se-kpi { background:#0f172a; border-color:#334155; }
body[data-theme='dark'] .se-kpi .num { color:#f1f5f9; }
body[data-theme='dark'] .se-kpi .lbl { color:#94a3b8; }
body[data-theme='dark'] .se-table th { background:#0f172a; color:#cbd5e1; border-bottom-color:#334155; }
body[data-theme='dark'] .se-table td { color:#e2e8f0; border-bottom-color:#1e293b; }
body[data-theme='dark'] .se-table tr:hover td { background:#0f172a; }
body[data-theme='dark'] .se-table .title { color:#f1f5f9; }
body[data-theme='dark'] .se-table .culprit { color:#64748b; }
body[data-theme='dark'] .se-pager-btn { background:#334155; color:#cbd5e1; }
body[data-theme='dark'] .se-modal-box { background:#1e293b; }
body[data-theme='dark'] .se-modal-box h3 { color:#f1f5f9; }
body[data-theme='dark'] .se-meta dd { color:#e2e8f0; }
</style>

<div class="space-y-4">
    <!-- Header -->
    <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
            <h2 class="text-xl font-black text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-radiation text-purple-600"></i>
                Sentry Events
            </h2>
            <p class="text-xs text-slate-500 mt-1">
                Events ที่ส่งมาจาก <code class="bg-slate-100 px-1.5 py-0.5 rounded text-[10px]">api/sentry_webhook.php</code> — มี action retry สร้าง GitHub Issue ด้วย <code>@claude</code> mention ได้จากตรงนี้
            </p>
        </div>
        <div class="flex items-center gap-2">
            <button class="btn-solid bg-amber-500 text-white hover:bg-amber-600 text-sm" onclick="sePurgeNoise()" title="ลบ events รบกวน (info-level, [TEST], ฯลฯ)">
                <i class="fa-solid fa-broom"></i> ลบ noise
            </button>
            <button class="btn-solid bg-slate-700 text-white hover:bg-slate-800 text-sm" onclick="seRefresh()">
                <i class="fa-solid fa-rotate"></i> รีเฟรช
            </button>
        </div>
    </div>

    <!-- KPI Tiles -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="se-kpi" data-tone="total"><div class="ic"><i class="fa-solid fa-database"></i></div>
            <div><div class="num" id="se-kpi-total">—</div><div class="lbl">ทั้งหมด</div></div></div>
        <div class="se-kpi" data-tone="errors"><div class="ic"><i class="fa-solid fa-bug"></i></div>
            <div><div class="num" id="se-kpi-errors">—</div><div class="lbl">Error / Fatal</div></div></div>
        <div class="se-kpi" data-tone="github"><div class="ic"><i class="fa-brands fa-github"></i></div>
            <div><div class="num" id="se-kpi-github">—</div><div class="lbl">มี GitHub Issue</div></div></div>
        <div class="se-kpi" data-tone="failed"><div class="ic"><i class="fa-solid fa-circle-exclamation"></i></div>
            <div><div class="num" id="se-kpi-failed">—</div><div class="lbl">สร้าง Issue ล้มเหลว</div></div></div>
    </div>

    <!-- Filters -->
    <div class="se-card">
        <div class="flex items-center gap-2 flex-wrap mb-3">
            <span class="text-[11px] font-bold text-slate-500 uppercase tracking-wider mr-1">ช่วงเวลา</span>
            <button class="se-chip is-active" data-period="all"   onclick="seSetPeriod('all',this)">ทั้งหมด</button>
            <button class="se-chip" data-period="today"            onclick="seSetPeriod('today',this)">วันนี้</button>
            <button class="se-chip" data-period="7d"               onclick="seSetPeriod('7d',this)">7 วัน</button>
            <button class="se-chip" data-period="30d"              onclick="seSetPeriod('30d',this)">30 วัน</button>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-5 gap-2">
            <input type="text" id="se-q" class="se-input md:col-span-2" placeholder="🔍 ค้นหา title / culprit / sentry id">
            <select id="se-resource" class="se-input"><option value="">ทุก resource</option><option>issue</option><option>event_alert</option><option>installation</option><option>metric_alert</option></select>
            <select id="se-level" class="se-input"><option value="">ทุก level</option><option>fatal</option><option>error</option><option>warning</option><option>info</option><option>debug</option></select>
            <select id="se-github" class="se-input"><option value="">GitHub ทุกสถานะ</option><option value="created">สร้าง Issue แล้ว</option><option value="pending">ยังไม่สร้าง</option><option value="failed">ล้มเหลว</option></select>
        </div>
    </div>

    <!-- Table -->
    <div class="se-card" style="padding:0;overflow:hidden">
        <div style="overflow-x:auto">
            <table class="se-table">
                <thead>
                    <tr>
                        <th style="width:36px;text-align:center"><input type="checkbox" id="se-check-all" class="se-row-checkbox" onclick="seToggleAll(this)" title="เลือกทั้งหน้า"></th>
                        <th style="width:50px">#</th>
                        <th>Title / Culprit</th>
                        <th style="width:90px">Level</th>
                        <th style="width:90px">Resource</th>
                        <th style="width:90px">Action</th>
                        <th style="width:130px">GitHub</th>
                        <th style="width:140px">Received</th>
                        <th style="width:170px"></th>
                    </tr>
                </thead>
                <tbody id="se-tbody">
                    <tr><td colspan="9" style="padding:30px;text-align:center;color:#94a3b8;font-style:italic">กำลังโหลด…</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div id="se-pager" style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-top:1px solid #e2e8f0;background:#f8fafc">
            <div class="text-[11px] font-bold text-slate-500" id="se-pager-info">หน้า — / — · รวม — รายการ</div>
            <div class="flex items-center gap-1" id="se-pager-btns"></div>
        </div>
    </div>
</div>

<!-- Bulk action bar (appears when selectedIds.size > 0) -->
<div id="se-bulk-bar" class="se-bulk-bar">
    <span class="count">เลือก <b id="se-bulk-count">0</b> รายการ</span>
    <button class="se-bulk-btn se-bulk-btn-danger" onclick="seBulkDelete()">
        <i class="fa-solid fa-trash-can"></i> ลบที่เลือก
    </button>
    <button class="se-bulk-btn se-bulk-btn-ghost" onclick="seClearSelection()">
        <i class="fa-solid fa-xmark"></i> ยกเลิก
    </button>
</div>

<!-- Modal -->
<div id="se-modal" onclick="if(event.target===this)seCloseModal()">
    <div class="se-modal-box">
        <div class="flex items-start justify-between gap-3">
            <div style="flex:1;min-width:0">
                <h3 id="se-modal-title">—</h3>
                <div class="culprit" id="se-modal-culprit" style="font-family:Consolas,monospace;font-size:11.5px;color:#94a3b8;margin-top:3px"></div>
            </div>
            <button onclick="seCloseModal()" style="background:none;border:0;cursor:pointer;color:#94a3b8;font-size:24px;line-height:1">×</button>
        </div>
        <dl class="se-meta">
            <div><dt>Sentry ID</dt><dd id="se-modal-sid">—</dd></div>
            <div><dt>Level</dt><dd><span class="se-pill" id="se-modal-level">—</span></dd></div>
            <div><dt>Resource / Action</dt><dd id="se-modal-ra">—</dd></div>
            <div><dt>Environment</dt><dd id="se-modal-env">—</dd></div>
            <div><dt>Received</dt><dd id="se-modal-recv">—</dd></div>
            <div><dt>Sentry Link</dt><dd><a id="se-modal-link" href="#" target="_blank" style="color:#7c3aed">เปิดใน Sentry →</a></dd></div>
            <div style="grid-column:1/-1"><dt>GitHub Issue</dt><dd id="se-modal-gh">—</dd></div>
        </dl>
        <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-2">Raw payload</div>
        <pre class="se-raw" id="se-modal-raw">—</pre>
    </div>
</div>

<script>
(function () {
  const AJAX = 'ajax_sentry_events.php';
  const CSRF = '<?= htmlspecialchars(get_csrf_token(), ENT_QUOTES) ?>';

  const state = { page: 1, period: 'all', resource: '', level: '', github: '', q: '' };
  const selectedIds = new Set();
  let searchTimer = null;

  const $ = id => document.getElementById(id);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const num = n => (n == null) ? '—' : Number(n).toLocaleString('th-TH');

  function pill(level) {
    const lv = (level || '').toLowerCase();
    const known = ['fatal','error','warning','info','debug'];
    const cls = known.includes(lv) ? 'se-pill-' + lv : 'se-pill-default';
    return `<span class="se-pill ${cls}">${esc(lv || '—')}</span>`;
  }

  function ghCell(row) {
    if (row.github_issue_url) {
      const num = row.github_issue_number ? '#' + row.github_issue_number : 'open';
      return `<a class="se-btn se-btn-gh" href="${esc(row.github_issue_url)}" target="_blank"><i class="fa-brands fa-github"></i>${esc(num)}</a>`;
    }
    if (row.github_error) return `<span class="se-pill se-pill-gh-fail" title="${esc(row.github_error)}">❌ failed</span>`;
    return `<span class="se-pill se-pill-gh-none">—</span>`;
  }

  function actionsCell(row) {
    let html = `<button class="se-btn se-btn-view" onclick="seView(${row.id})"><i class="fa-solid fa-eye"></i>ดู</button>`;
    if (!row.github_issue_url) {
      html += ` <button class="se-btn se-btn-retry" onclick="seRetry(${row.id},this)"><i class="fa-brands fa-github"></i>สร้าง Issue</button>`;
    }
    return html;
  }

  async function fetchList() {
    const qs = new URLSearchParams({
      action: 'list', page: state.page,
      period: state.period, resource: state.resource, level: state.level,
      github: state.github, q: state.q,
    });
    const res = await fetch(`${AJAX}?${qs}`);
    const json = await res.json();
    if (!json.ok) { Swal.fire({icon:'error', title:'โหลดล้มเหลว', text:json.message}); return; }

    // KPI
    const st = json.stats || {};
    $('se-kpi-total').textContent  = num(st.total);
    $('se-kpi-errors').textContent = num(st.errors);
    $('se-kpi-github').textContent = num(st.gh_created);
    $('se-kpi-failed').textContent = num(st.gh_failed);

    // Rows
    if (!json.rows.length) {
      $('se-tbody').innerHTML = `<tr><td colspan="9" style="padding:36px;text-align:center;color:#94a3b8;font-style:italic">ไม่พบ event ที่ตรงเงื่อนไข</td></tr>`;
    } else {
      $('se-tbody').innerHTML = json.rows.map(r => {
        const isSel = selectedIds.has(r.id);
        return `
        <tr>
          <td style="text-align:center"><input type="checkbox" class="se-row-checkbox" data-id="${r.id}" ${isSel ? 'checked' : ''} onclick="seToggleRow(${r.id}, this)"></td>
          <td class="id-cell">${r.id}</td>
          <td>
            <div class="title">${esc((r.title || '').slice(0, 100))}</div>
            <div class="culprit">${esc(r.culprit || '—')}</div>
          </td>
          <td>${pill(r.level)}</td>
          <td><code class="text-[11px]">${esc(r.resource || '—')}</code></td>
          <td><code class="text-[11px]">${esc(r.action || '—')}</code></td>
          <td>${ghCell(r)}</td>
          <td class="text-[11px]">${esc(r.received_at)}</td>
          <td>${actionsCell(r)}</td>
        </tr>
      `;
      }).join('');
    }
    syncCheckAll();

    // Pager
    $('se-pager-info').textContent = `หน้า ${json.page} / ${json.pages} · รวม ${num(json.total)} รายการ`;
    renderPager(json.page, json.pages);
  }

  function renderPager(page, pages) {
    const c = $('se-pager-btns');
    if (pages <= 1) { c.innerHTML = ''; return; }

    const btn = (label, target, dis, active=false) =>
      `<button class="se-pager-btn ${active?'is-active':''}" ${dis?'disabled':''} onclick="seGoto(${target})">${label}</button>`;

    let html = btn('«', 1, page === 1) + btn('‹', page - 1, page === 1);
    const win = 2;
    const start = Math.max(1, page - win);
    const end   = Math.min(pages, page + win);
    if (start > 1)      html += `<span style="padding:0 4px;color:#94a3b8">…</span>`;
    for (let i = start; i <= end; i++) html += btn(i, i, false, i === page);
    if (end < pages)    html += `<span style="padding:0 4px;color:#94a3b8">…</span>`;
    html += btn('›', page + 1, page === pages) + btn('»', pages, page === pages);
    c.innerHTML = html;
  }

  // ── Selection ───────────────────────────────────────────────────────────
  function updateBulkBar() {
    const bar = $('se-bulk-bar');
    $('se-bulk-count').textContent = selectedIds.size;
    if (selectedIds.size > 0) bar.classList.add('is-visible');
    else bar.classList.remove('is-visible');
  }

  function syncCheckAll() {
    const boxes = document.querySelectorAll('.se-row-checkbox[data-id]');
    const all = $('se-check-all');
    if (!boxes.length) { all.checked = false; all.indeterminate = false; return; }
    let checkedCount = 0;
    boxes.forEach(b => { if (b.checked) checkedCount++; });
    all.checked = (checkedCount === boxes.length);
    all.indeterminate = (checkedCount > 0 && checkedCount < boxes.length);
  }

  window.seToggleRow = function (id, el) {
    if (el.checked) selectedIds.add(id); else selectedIds.delete(id);
    updateBulkBar(); syncCheckAll();
  };
  window.seToggleAll = function (el) {
    document.querySelectorAll('.se-row-checkbox[data-id]').forEach(b => {
      const id = +b.dataset.id;
      b.checked = el.checked;
      if (el.checked) selectedIds.add(id); else selectedIds.delete(id);
    });
    updateBulkBar();
  };
  window.seClearSelection = function () {
    selectedIds.clear();
    document.querySelectorAll('.se-row-checkbox').forEach(b => { b.checked = false; b.indeterminate = false; });
    updateBulkBar();
  };

  window.seBulkDelete = async function () {
    if (!selectedIds.size) return;
    const { isConfirmed } = await Swal.fire({
      icon: 'warning',
      title: `ลบ ${selectedIds.size} event?`,
      text: 'การกระทำนี้ย้อนกลับไม่ได้',
      showCancelButton: true,
      confirmButtonText: 'ลบเลย', cancelButtonText: 'ยกเลิก',
      confirmButtonColor: '#ef4444',
    });
    if (!isConfirmed) return;

    const fd = new FormData();
    fd.set('ids', Array.from(selectedIds).join(','));
    fd.set('csrf_token', CSRF);
    try {
      const r = await fetch(`${AJAX}?action=bulk_delete`, { method: 'POST', body: fd });
      const j = await r.json();
      if (!j.ok) throw new Error(j.message);
      Swal.fire({ icon: 'success', title: `ลบแล้ว ${j.deleted} รายการ`, timer: 1400, showConfirmButton: false });
      seClearSelection();
      fetchList();
    } catch (e) {
      Swal.fire({ icon: 'error', title: 'ลบไม่สำเร็จ', text: e.message });
    }
  };

  window.sePurgeNoise = async function () {
    const { isConfirmed } = await Swal.fire({
      icon: 'question',
      title: 'ลบ noise events?',
      html: 'ลบทุกแถวที่ตรงกับ:<br><code style="font-size:11px">level=info · "AI QA*" · "Webhook events decoded*" · "Default reply*" · "GitHub issue create failed*" · "[TEST]*"</code>',
      showCancelButton: true,
      confirmButtonText: 'ลบเลย', cancelButtonText: 'ยกเลิก',
      confirmButtonColor: '#f59e0b',
    });
    if (!isConfirmed) return;

    const fd = new FormData();
    fd.set('csrf_token', CSRF);
    try {
      const r = await fetch(`${AJAX}?action=purge_noise`, { method: 'POST', body: fd });
      const j = await r.json();
      if (!j.ok) throw new Error(j.message);
      Swal.fire({ icon: 'success', title: `ลบ noise แล้ว ${j.deleted} รายการ`, timer: 1400, showConfirmButton: false });
      seClearSelection();
      fetchList();
    } catch (e) {
      Swal.fire({ icon: 'error', title: 'ลบไม่สำเร็จ', text: e.message });
    }
  };

  window.seGoto = function (p) { state.page = p; fetchList(); };

  window.seSetPeriod = function (p, el) {
    state.period = p; state.page = 1;
    document.querySelectorAll('[data-period]').forEach(b => b.classList.remove('is-active'));
    el.classList.add('is-active');
    fetchList();
  };

  window.seRefresh = fetchList;

  ['se-resource','se-level','se-github'].forEach(id => {
    $(id).addEventListener('change', e => { state[id.slice(3)] = e.target.value; state.page = 1; fetchList(); });
  });
  $('se-q').addEventListener('input', e => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => { state.q = e.target.value.trim(); state.page = 1; fetchList(); }, 350);
  });

  window.seView = async function (id) {
    $('se-modal-raw').textContent = 'กำลังโหลด…';
    $('se-modal').classList.add('is-open');
    try {
      const r = await fetch(`${AJAX}?action=get_raw&id=${id}`);
      const j = await r.json();
      if (!j.ok) throw new Error(j.message);
      const row = j.row;
      $('se-modal-title').textContent   = row.title || '(no title)';
      $('se-modal-culprit').textContent = row.culprit || '';
      $('se-modal-sid').textContent     = row.sentry_id || '—';
      $('se-modal-level').className     = 'se-pill ' + (['fatal','error','warning','info','debug'].includes((row.level||'').toLowerCase()) ? 'se-pill-' + row.level.toLowerCase() : 'se-pill-default');
      $('se-modal-level').textContent   = row.level || '—';
      $('se-modal-ra').textContent      = `${row.resource || '—'} / ${row.action || '—'}`;
      $('se-modal-env').textContent     = row.environment || '—';
      $('se-modal-recv').textContent    = row.received_at;
      const link = $('se-modal-link');
      if (row.url) { link.href = row.url; link.style.display = ''; } else { link.style.display = 'none'; }
      $('se-modal-gh').innerHTML = row.github_issue_url
        ? `<a href="${esc(row.github_issue_url)}" target="_blank" style="color:#15803d;font-weight:800"><i class="fa-brands fa-github"></i> #${row.github_issue_number || '?'} (เปิดใน GitHub →)</a>`
        : (row.github_error ? `<span style="color:#b91c1c">❌ ${esc(row.github_error)}</span>` : '<span style="color:#94a3b8">— ยังไม่สร้าง —</span>');
      $('se-modal-raw').textContent = row.raw_pretty || row.raw_payload || '(ไม่มี payload)';
    } catch (e) {
      $('se-modal-raw').textContent = 'โหลดล้มเหลว: ' + e.message;
    }
  };

  window.seCloseModal = function () { $('se-modal').classList.remove('is-open'); };

  window.seRetry = async function (id, btn) {
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> กำลังสร้าง…';
    try {
      const fd = new FormData();
      fd.set('id', id); fd.set('csrf_token', CSRF);
      const r = await fetch(`${AJAX}?action=retry_github`, { method: 'POST', body: fd });
      const j = await r.json();
      if (j.ok) {
        Swal.fire({ icon:'success', title:'สร้าง Issue แล้ว', html:`<a href="${j.url}" target="_blank">${j.url}</a>`, confirmButtonColor:'#7c3aed' });
        fetchList();
      } else {
        Swal.fire({ icon:'error', title:'สร้างไม่ได้', text:j.message });
        btn.disabled = false; btn.innerHTML = orig;
      }
    } catch (e) {
      Swal.fire({ icon:'error', title:'Network error', text:e.message });
      btn.disabled = false; btn.innerHTML = orig;
    }
  };

  document.addEventListener('DOMContentLoaded', fetchList);
  if (document.readyState !== 'loading') setTimeout(fetchList, 50);
})();
</script>
