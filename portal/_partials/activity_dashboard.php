<?php
/**
 * portal/_partials/activity_dashboard.php
 * Realtime status dashboard for sys_activity_logs — superadmin only
 *
 * Loaded by portal/index.php inside #section-activity_dashboard
 * Data populated by ajax_activity_dashboard.php (snapshot + tick)
 * Realtime via Pusher channel "admin-activity" (event "new") + 15s polling fallback
 */
declare(strict_types=1);
?>
<style>
    .ad-wrap   { padding: 24px; max-width: 1400px; margin: 0 auto; }
    @media (max-width:768px) { .ad-wrap { padding: 16px; } }
    .ad-h1     { font-size: 22px; font-weight: 900; color: #0f172a; letter-spacing: -.02em; }
    .ad-sub    { font-size: 12px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .2em; margin-top: 2px; }
    .ad-pulse  { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:999px; background:#dcfce7; color:#15803d; font-weight:800; font-size:11px; }
    .ad-pulse .dot { width:6px; height:6px; border-radius:50%; background:#22c55e; animation: adPulse 1.6s ease-in-out infinite; }
    @keyframes adPulse { 0%,100% { opacity:1 } 50% { opacity:.35 } }
    .ad-grid-kpi { display:grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap:12px; margin-top:18px; }
    @media (max-width:900px){ .ad-grid-kpi { grid-template-columns: repeat(2, 1fr); } }
    .ad-kpi { background:#fff; border:1px solid #e2e8f0; border-radius:18px; padding:16px; transition: transform .15s ease; }
    .ad-kpi:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(15,23,42,.06); }
    .ad-kpi .lbl   { font-size:11px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.12em; }
    .ad-kpi .val   { font-size:28px; font-weight:900; color:#0f172a; margin-top:6px; letter-spacing:-.02em; line-height:1; }
    .ad-kpi .sub   { font-size:11px; font-weight:700; color:#94a3b8; margin-top:6px; }
    .ad-delta-up   { color:#16a34a; }
    .ad-delta-down { color:#dc2626; }
    .ad-delta-flat { color:#94a3b8; }

    .ad-grid-2 { display:grid; grid-template-columns: 2fr 1fr; gap:16px; margin-top:16px; }
    @media (max-width:1000px){ .ad-grid-2 { grid-template-columns: 1fr; } }

    .ad-card { background:#fff; border:1px solid #e2e8f0; border-radius:20px; padding:18px; }
    .ad-card-title { display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; }
    .ad-card-title h3 { font-size:14px; font-weight:900; color:#0f172a; letter-spacing:-.01em; }
    .ad-card-title .meta { font-size:10px; font-weight:800; color:#94a3b8; text-transform:uppercase; letter-spacing:.16em; }

    /* Top admins leaderboard */
    .ad-top-row { display:flex; align-items:center; gap:10px; padding:10px 4px; border-bottom:1px solid #f1f5f9; }
    .ad-top-row:last-child { border-bottom: 0; }
    .ad-rank { width:22px; height:22px; border-radius:50%; background:#f1f5f9; color:#64748b; font-size:11px; font-weight:900; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .ad-rank.gold   { background:#fef3c7; color:#b45309; }
    .ad-rank.silver { background:#e2e8f0; color:#475569; }
    .ad-rank.bronze { background:#fce7f3; color:#9d174d; }
    .ad-top-name { flex:1; font-size:13px; font-weight:800; color:#1e293b; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .ad-top-bar  { flex:1; max-width:120px; height:6px; background:#f1f5f9; border-radius:3px; overflow:hidden; }
    .ad-top-bar > div { height:100%; background:linear-gradient(90deg,#8b5cf6,#ec4899); transition: width .3s ease; }
    .ad-top-count { font-size:12px; font-weight:900; color:#475569; min-width:38px; text-align:right; }

    /* Categories */
    .ad-cat-row { display:flex; align-items:center; gap:10px; padding:8px 4px; }
    .ad-cat-ic  { width:30px; height:30px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:13px; color:#fff; flex-shrink:0; }
    .ad-cat-name { flex:1; font-size:13px; font-weight:800; color:#1e293b; }
    .ad-cat-bar  { flex:1; max-width:130px; height:6px; background:#f1f5f9; border-radius:3px; overflow:hidden; }
    .ad-cat-bar > div { height:100%; transition: width .3s ease; }
    .ad-cat-count { font-size:12px; font-weight:900; color:#475569; min-width:36px; text-align:right; }

    /* Heatmap */
    .ad-heat { display:grid; grid-template-columns: 28px repeat(24, 1fr); gap:2px; }
    .ad-heat-lbl { font-size:9px; font-weight:800; color:#94a3b8; text-align:right; padding-right:4px; align-self:center; }
    .ad-heat-cell { aspect-ratio:1/1; border-radius:3px; background:#f1f5f9; min-height:16px; transition: transform .12s; cursor:default; position:relative; }
    .ad-heat-cell:hover { transform: scale(1.4); z-index:1; box-shadow: 0 4px 12px rgba(15,23,42,.18); }
    .ad-heat-hours { display:grid; grid-template-columns: 28px repeat(24, 1fr); gap:2px; margin-top:4px; }
    .ad-heat-hr { font-size:8px; font-weight:700; color:#cbd5e1; text-align:center; }

    /* Feed */
    .ad-feed { max-height:520px; overflow-y:auto; }
    .ad-feed::-webkit-scrollbar { width: 6px; }
    .ad-feed::-webkit-scrollbar-thumb { background:#e2e8f0; border-radius:3px; }
    .ad-feed-row { display:flex; gap:10px; padding:10px 4px; border-bottom:1px solid #f1f5f9; animation: adFeedIn .35s ease-out; }
    @keyframes adFeedIn { from { opacity:0; transform: translateX(-8px); background:#fef3c7; } to { opacity:1; transform: none; background:transparent; } }
    .ad-feed-ic { width:32px; height:32px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:13px; color:#fff; flex-shrink:0; }
    .ad-feed-body { flex:1; min-width:0; }
    .ad-feed-line1 { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .ad-feed-actor { font-size:13px; font-weight:900; color:#0f172a; }
    .ad-feed-act   { font-size:11px; font-weight:800; padding:1px 7px; border-radius:5px; }
    .ad-feed-time  { font-size:10px; font-weight:700; color:#94a3b8; margin-left:auto; flex-shrink:0; }
    .ad-feed-desc  { font-size:11px; color:#64748b; line-height:1.4; margin-top:2px; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; word-break:break-word; }
    .ad-feed-meta  { font-size:10px; color:#cbd5e1; margin-top:3px; font-family: ui-monospace, monospace; }

    .ad-skel { background: linear-gradient(90deg,#f1f5f9 0%,#e2e8f0 40%,#f1f5f9 80%); background-size:200% 100%; animation: adSkel 1.4s ease-in-out infinite; border-radius: 10px; }
    @keyframes adSkel { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }

    .ad-empty { padding:32px 20px; text-align:center; color:#94a3b8; font-weight:700; font-size:13px; }
</style>

<div class="ad-wrap">
    <!-- Header -->
    <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap;">
        <div>
            <div class="ad-sub">Live · realtime</div>
            <h1 class="ad-h1">Activity Dashboard</h1>
        </div>
        <div style="display:flex; align-items:center; gap:8px;">
            <span class="ad-pulse" id="adLiveBadge"><span class="dot"></span>LIVE</span>
            <button id="adRefreshBtn" onclick="adLoadSnapshot()" style="padding:6px 12px; border-radius:10px; border:1px solid #e2e8f0; background:#fff; color:#475569; font-weight:800; font-size:12px; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
                <i class="fa-solid fa-arrows-rotate"></i> Refresh
            </button>
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="ad-grid-kpi">
        <div class="ad-kpi">
            <div class="lbl"><i class="fa-solid fa-bolt" style="color:#f59e0b;margin-right:4px"></i>Actions วันนี้</div>
            <div class="val" id="adKpiToday">—</div>
            <div class="sub" id="adKpiTodayDelta">เทียบเมื่อวาน · กำลังโหลด…</div>
        </div>
        <div class="ad-kpi">
            <div class="lbl"><i class="fa-solid fa-users" style="color:#8b5cf6;margin-right:4px"></i>Active Admins (24h)</div>
            <div class="val" id="adKpiActive">—</div>
            <div class="sub" id="adKpiActiveSub">distinct user_id</div>
        </div>
        <div class="ad-kpi">
            <div class="lbl"><i class="fa-solid fa-chart-line" style="color:#10b981;margin-right:4px"></i>Peak Hour วันนี้</div>
            <div class="val" id="adKpiPeak">—</div>
            <div class="sub" id="adKpiPeakSub">—</div>
        </div>
        <div class="ad-kpi">
            <div class="lbl"><i class="fa-solid fa-database" style="color:#06b6d4;margin-right:4px"></i>รวมตั้งแต่เริ่มระบบ</div>
            <div class="val" id="adKpiTotal">—</div>
            <div class="sub">all-time records</div>
        </div>
    </div>

    <!-- 24h Timeline -->
    <div class="ad-card" style="margin-top:16px;">
        <div class="ad-card-title">
            <h3><i class="fa-solid fa-wave-square" style="color:#0ea5e9;margin-right:6px"></i>Activity 24 ชั่วโมงล่าสุด</h3>
            <span class="meta">Hourly · rolling 24h</span>
        </div>
        <div style="height:240px; position:relative;">
            <canvas id="adTimelineChart"></canvas>
        </div>
    </div>

    <!-- 2-column: Top Admins + Categories -->
    <div class="ad-grid-2">
        <div class="ad-card">
            <div class="ad-card-title">
                <h3><i class="fa-solid fa-medal" style="color:#f59e0b;margin-right:6px"></i>Top Admins (7 วัน)</h3>
                <span class="meta">By action count</span>
            </div>
            <div id="adTopAdmins">
                <div class="ad-skel" style="height:40px; margin-bottom:8px;"></div>
                <div class="ad-skel" style="height:40px; margin-bottom:8px;"></div>
                <div class="ad-skel" style="height:40px;"></div>
            </div>
        </div>
        <div class="ad-card">
            <div class="ad-card-title">
                <h3><i class="fa-solid fa-shapes" style="color:#8b5cf6;margin-right:6px"></i>หมวดหมู่ (7 วัน)</h3>
                <span class="meta">By category</span>
            </div>
            <div id="adCategories">
                <div class="ad-skel" style="height:40px; margin-bottom:8px;"></div>
                <div class="ad-skel" style="height:40px; margin-bottom:8px;"></div>
                <div class="ad-skel" style="height:40px;"></div>
            </div>
        </div>
    </div>

    <!-- Heatmap -->
    <div class="ad-card" style="margin-top:16px;">
        <div class="ad-card-title">
            <h3><i class="fa-solid fa-fire" style="color:#ec4899;margin-right:6px"></i>Heatmap วัน × ชั่วโมง (30 วัน)</h3>
            <span class="meta">Darker = busier</span>
        </div>
        <div id="adHeatmap" style="overflow-x:auto;">
            <div class="ad-skel" style="height:180px;"></div>
        </div>
    </div>

    <!-- Live Feed -->
    <div class="ad-card" style="margin-top:16px;">
        <div class="ad-card-title">
            <h3><i class="fa-solid fa-stream" style="color:#10b981;margin-right:6px"></i>Live Feed</h3>
            <span class="meta" id="adFeedMeta">— records</span>
        </div>
        <div class="ad-feed" id="adFeed">
            <div class="ad-skel" style="height:54px; margin-bottom:6px;"></div>
            <div class="ad-skel" style="height:54px; margin-bottom:6px;"></div>
            <div class="ad-skel" style="height:54px;"></div>
        </div>
    </div>
</div>

<!-- Chart.js + Pusher (Pusher may already be loaded by other modules) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="https://js.pusher.com/8.0.1/pusher.min.js"></script>

<script>
(function(){
    const AD_PUSHER_KEY     = '<?= defined('PUSHER_KEY') ? htmlspecialchars(PUSHER_KEY) : '' ?>';
    const AD_PUSHER_CLUSTER = '<?= defined('PUSHER_CLUSTER') ? htmlspecialchars(PUSHER_CLUSTER) : 'ap1' ?>';
    const DOW_LABELS = ['อา','จ','อ','พ','พฤ','ศ','ส'];

    let adTimelineChart = null;
    let adLatestId = 0;
    let adPusherWired = false;

    async function adFetch(action, params = {}) {
        const fd = new FormData();
        fd.append('action', action);
        Object.entries(params).forEach(([k, v]) => fd.append(k, v ?? ''));
        const res = await fetch('ajax_activity_dashboard.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
    }

    function adFmtTime(ts) {
        if (!ts) return '';
        const d = new Date(ts.replace(' ', 'T'));
        const now = new Date();
        const diff = (now - d) / 1000;
        if (diff < 60)    return Math.max(1, Math.floor(diff)) + 's ago';
        if (diff < 3600)  return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        return d.toLocaleDateString('th-TH', { day:'2-digit', month:'short' });
    }

    function adEsc(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function adRenderKPI(k) {
        document.getElementById('adKpiToday').textContent = (k.today || 0).toLocaleString();
        const delta = k.today_delta_pct || 0;
        const arrow = delta > 0 ? '↑' : (delta < 0 ? '↓' : '→');
        const cls   = delta > 0 ? 'ad-delta-up' : (delta < 0 ? 'ad-delta-down' : 'ad-delta-flat');
        document.getElementById('adKpiTodayDelta').innerHTML =
            `<span class="${cls}">${arrow} ${Math.abs(delta)}%</span> เทียบเมื่อวาน (${(k.yesterday || 0).toLocaleString()})`;
        document.getElementById('adKpiActive').textContent = (k.active_admins || 0).toLocaleString();
        document.getElementById('adKpiPeak').textContent   = k.peak_hour || '—';
        document.getElementById('adKpiPeakSub').textContent = k.peak_count
            ? `${k.peak_count.toLocaleString()} actions ในชั่วโมงนั้น` : 'ไม่มีข้อมูลวันนี้';
        document.getElementById('adKpiTotal').textContent = (k.total || 0).toLocaleString();
    }

    function adRenderTimeline(hourly) {
        const ctx = document.getElementById('adTimelineChart');
        if (!ctx) return;
        const labels = hourly.map(h => h.label);
        const data   = hourly.map(h => h.count);
        if (adTimelineChart) {
            adTimelineChart.data.labels = labels;
            adTimelineChart.data.datasets[0].data = data;
            adTimelineChart.update('none');
            return;
        }
        adTimelineChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    data, label: 'actions',
                    borderColor: '#0ea5e9',
                    backgroundColor: 'rgba(14,165,233,.12)',
                    borderWidth: 2, fill: true, tension: .35,
                    pointRadius: 0, pointHoverRadius: 5,
                    pointHoverBackgroundColor: '#0ea5e9',
                }],
            },
            options: {
                responsive: true, maintainAspectRatio: false, animation: { duration: 400 },
                plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 10 }, maxRotation: 0, autoSkip: true, maxTicksLimit: 8, color: '#94a3b8' } },
                    y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { font: { size: 10 }, color: '#94a3b8', precision: 0 } },
                },
            },
        });
    }

    function adRenderTopAdmins(rows) {
        const box = document.getElementById('adTopAdmins');
        if (!rows || rows.length === 0) {
            box.innerHTML = '<div class="ad-empty">ยังไม่มี admin ใช้งานในช่วง 7 วัน</div>';
            return;
        }
        const max = Math.max(...rows.map(r => parseInt(r.c, 10)));
        box.innerHTML = rows.map((r, i) => {
            const cls = i === 0 ? 'gold' : (i === 1 ? 'silver' : (i === 2 ? 'bronze' : ''));
            const pct = max ? Math.round(parseInt(r.c, 10) / max * 100) : 0;
            return `
                <div class="ad-top-row">
                    <span class="ad-rank ${cls}">${i+1}</span>
                    <span class="ad-top-name" title="${adEsc(r.name)}">${adEsc(r.name)}</span>
                    <div class="ad-top-bar"><div style="width:${pct}%"></div></div>
                    <span class="ad-top-count">${parseInt(r.c, 10).toLocaleString()}</span>
                </div>`;
        }).join('');
    }

    function adRenderCategories(cats) {
        const box = document.getElementById('adCategories');
        if (!cats || cats.length === 0) {
            box.innerHTML = '<div class="ad-empty">ไม่มี action ใน 7 วัน</div>';
            return;
        }
        const max = Math.max(...cats.map(c => c.count));
        box.innerHTML = cats.map(c => {
            const pct = max ? Math.round(c.count / max * 100) : 0;
            return `
                <div class="ad-cat-row">
                    <div class="ad-cat-ic" style="background:${c.color}"><i class="fa-solid ${c.icon}"></i></div>
                    <span class="ad-cat-name">${adEsc(c.label)}</span>
                    <div class="ad-cat-bar"><div style="width:${pct}%;background:${c.color}"></div></div>
                    <span class="ad-cat-count">${c.count.toLocaleString()}</span>
                </div>`;
        }).join('');
    }

    function adColor(c, max) {
        if (max === 0 || c === 0) return '#f1f5f9';
        const ratio = Math.min(1, c / max);
        // pink-rose gradient: light→deep
        const r = Math.round(254 - ratio * (254 - 219));
        const g = Math.round(226 - ratio * (226 - 39));
        const b = Math.round(226 - ratio * (226 - 119));
        return `rgb(${r},${g},${b})`;
    }

    function adRenderHeatmap(hm) {
        const box  = document.getElementById('adHeatmap');
        const max  = hm.max || 0;
        const data = hm.data || [];
        let html = '';
        for (let d = 0; d < 7; d++) {
            html += `<div class="ad-heat-lbl">${DOW_LABELS[d]}</div>`;
            for (let h = 0; h < 24; h++) {
                const c = (data[d] && data[d][h]) || 0;
                const bg = adColor(c, max);
                html += `<div class="ad-heat-cell" style="background:${bg}" title="${DOW_LABELS[d]} ${String(h).padStart(2,'0')}:00 → ${c} actions"></div>`;
            }
        }
        // hour axis
        let hours = '<div class="ad-heat-lbl"></div>';
        for (let h = 0; h < 24; h++) hours += `<div class="ad-heat-hr">${h % 3 === 0 ? String(h).padStart(2,'0') : ''}</div>`;

        box.innerHTML = `<div class="ad-heat" style="min-width:560px">${html}</div><div class="ad-heat-hours" style="min-width:560px">${hours}</div>`;
    }

    function adFeedRowHtml(it) {
        return `
            <div class="ad-feed-row" data-id="${it.id}">
                <div class="ad-feed-ic" style="background:${it.cat_color}"><i class="fa-solid ${it.cat_icon}"></i></div>
                <div class="ad-feed-body">
                    <div class="ad-feed-line1">
                        <span class="ad-feed-actor">${adEsc(it.actor)}</span>
                        <span class="ad-feed-act" style="background:${it.cat_color}22;color:${it.cat_color}">${adEsc(it.action)}</span>
                        <span class="ad-feed-time">${adFmtTime(it.timestamp)}</span>
                    </div>
                    ${it.desc ? `<div class="ad-feed-desc">${adEsc(it.desc)}</div>` : ''}
                    ${it.ip ? `<div class="ad-feed-meta">${adEsc(it.ip)}</div>` : ''}
                </div>
            </div>`;
    }

    function adRenderFeed(items) {
        const box = document.getElementById('adFeed');
        if (!items || items.length === 0) {
            box.innerHTML = '<div class="ad-empty">ยังไม่มี activity</div>';
            document.getElementById('adFeedMeta').textContent = '0 records';
            return;
        }
        box.innerHTML = items.map(adFeedRowHtml).join('');
        document.getElementById('adFeedMeta').textContent = items.length + ' รายการ · ล่าสุด';
    }

    function adPrependFeed(items) {
        const box = document.getElementById('adFeed');
        // newest first
        items.sort((a, b) => b.id - a.id);
        items.forEach(it => {
            box.insertAdjacentHTML('afterbegin', adFeedRowHtml(it));
        });
        // cap to 50 rows
        while (box.children.length > 50) box.removeChild(box.lastChild);
        document.getElementById('adFeedMeta').textContent = box.children.length + ' รายการ · ล่าสุด';
    }

    async function adLoadSnapshot() {
        const btn = document.getElementById('adRefreshBtn');
        if (btn) { btn.disabled = true; btn.style.opacity = '.6'; }
        try {
            const r = await adFetch('snapshot');
            if (!r.ok) {
                console.warn('[activity_dashboard] snapshot failed:', r.message);
                return;
            }
            adRenderKPI(r.kpi || {});
            adRenderTimeline(r.hourly || []);
            adRenderTopAdmins(r.top_admins || []);
            adRenderCategories(r.categories || []);
            adRenderHeatmap(r.heatmap || { data: [], max: 0 });
            adRenderFeed(r.recent || []);
            adLatestId = r.latest_id || 0;
        } catch (e) {
            console.error('[activity_dashboard]', e);
        } finally {
            if (btn) { btn.disabled = false; btn.style.opacity = ''; }
        }
    }

    async function adTick() {
        if (document.hidden) return;
        try {
            const r = await adFetch('tick', { since_id: adLatestId });
            if (!r.ok || !r.items || r.items.length === 0) return;
            adPrependFeed(r.items);
            adLatestId = r.latest_id || adLatestId;
            // Bump today KPI counter optimistically
            const adKpiTodayEl = document.getElementById('adKpiToday');
            const cur = parseInt((adKpiTodayEl.textContent || '0').replace(/,/g,''), 10) || 0;
            adKpiTodayEl.textContent = (cur + r.items.length).toLocaleString();
            // Flash live badge
            const badge = document.getElementById('adLiveBadge');
            if (badge) {
                badge.style.background = '#fef3c7';
                badge.style.color = '#b45309';
                setTimeout(() => { badge.style.background = ''; badge.style.color = ''; }, 600);
            }
        } catch (e) { /* ignore tick errors */ }
    }

    function adWirePusher() {
        if (adPusherWired) return;
        if (!AD_PUSHER_KEY || typeof Pusher === 'undefined') return;
        adPusherWired = true;
        try {
            const pusher = new Pusher(AD_PUSHER_KEY, { cluster: AD_PUSHER_CLUSTER });
            const ch = pusher.subscribe('admin-activity');
            ch.bind('new', () => adTick());
        } catch (e) { console.warn('[activity_dashboard] Pusher init failed:', e); }
    }

    // Public entry — called when section becomes active
    window.adActivate = function() {
        if (window._adActivated) { adLoadSnapshot(); return; }
        window._adActivated = true;
        adWirePusher();
        adLoadSnapshot();
        // Polling fallback every 15s
        setInterval(adTick, 15000);
        // Re-fetch full snapshot every 5 min to refresh aggregates
        setInterval(adLoadSnapshot, 5 * 60 * 1000);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) adTick();
        });
    };

    // If user lands directly on this section, kick off immediately
    if (document.getElementById('section-activity_dashboard')?.style.display !== 'none') {
        window.adActivate();
    }
})();
</script>
