<?php
/**
 * portal/_partials/edms/sla_dashboard.php
 * SLA Dashboard — KPI tiles + trend chart + by-dept donut + top overdue list
 *
 * Query: ?section=edms&edms_view=sla_dashboard
 */
declare(strict_types=1);
?>
<div class="max-w-6xl mx-auto px-4 md:px-6 py-6" id="sla-dashboard">
    <a href="?section=edms" class="inline-flex items-center gap-2 text-xs font-black text-slate-500 hover:text-sky-600 mb-4">
        <i class="fa-solid fa-chevron-left text-[10px]"></i>กลับหน้าหลัก EDMS
    </a>

    <!-- Header -->
    <div class="mb-5 flex items-center gap-4">
        <div class="w-12 h-12 bg-emerald-50 text-emerald-600 rounded-2xl border border-emerald-100 flex items-center justify-center text-xl">
            <i class="fa-solid fa-gauge-high"></i>
        </div>
        <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-bold text-slate-800">ภาพรวมเวลาดำเนินการ</h2>
            <p class="text-slate-500 text-sm">เอกสาร/งาน ทำตามเวลาที่ตกลงไว้หรือเปล่า — กี่ % ตรงเวลา ใกล้หมด หรือเกิน</p>
        </div>
        <div class="flex gap-2">
            <button onclick="slaSetPeriod('month')" id="sla-period-month"
                class="px-3 py-2 rounded-xl text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-100">
                30 วันล่าสุด
            </button>
            <button onclick="slaSetPeriod('year')" id="sla-period-year"
                class="px-3 py-2 rounded-xl text-xs font-semibold text-slate-500 hover:bg-slate-50 border border-slate-200">
                1 ปีล่าสุด
            </button>
            <button type="button" onclick="window._slaHelp && window._slaHelp()"
                class="px-3 py-2 rounded-xl text-xs font-semibold text-slate-600 bg-slate-50 border border-slate-200 hover:bg-slate-100 hover:text-slate-800"
                title="ดูคำอธิบายแต่ละส่วน">
                <i class="fa-solid fa-circle-question"></i>
            </button>
        </div>
    </div>

    <!-- KPI tiles — calm + clear hierarchy -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
        <div class="bg-white rounded-2xl border border-slate-200 border-l-4 border-l-emerald-500 p-4" title="เปอร์เซ็นต์ของเอกสาร/งานที่ปิดทันเวลา (ก่อนเลยกำหนด)">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold text-emerald-700">เสร็จทันเวลา</p>
                <i class="fa-solid fa-circle-check text-emerald-400 text-sm"></i>
            </div>
            <p class="text-3xl font-bold text-slate-900 mt-2"><span id="sla-kpi-ontime" data-counter="0">0</span><span class="text-lg text-slate-500">%</span></p>
            <p class="text-xs text-slate-400 mt-1" id="sla-kpi-ontime-delta">—</p>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 border-l-4 border-l-amber-500 p-4" title="เอกสาร/งานที่ยังไม่เสร็จและกำลังจะเลยกำหนด">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold text-amber-700">ใกล้หมดเวลา</p>
                <i class="fa-solid fa-triangle-exclamation text-amber-400 text-sm"></i>
            </div>
            <p class="text-3xl font-bold text-slate-900 mt-2"><span id="sla-kpi-warning" data-counter="0">0</span></p>
            <p class="text-xs text-slate-400 mt-1">ต้องเร่งทำให้เสร็จ</p>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 border-l-4 border-l-rose-500 p-4" title="เอกสาร/งานที่เลยกำหนดเวลาแล้ว แต่ยังไม่ปิด">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold text-rose-700">เลยกำหนด</p>
                <i class="fa-solid fa-circle-exclamation text-rose-400 text-sm"></i>
            </div>
            <p class="text-3xl font-bold text-slate-900 mt-2"><span id="sla-kpi-breached" data-counter="0">0</span></p>
            <p class="text-xs text-slate-400 mt-1" id="sla-kpi-breached-delta">—</p>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 border-l-4 border-l-sky-500 p-4" title="เวลาเฉลี่ยตั้งแต่รับเรื่องจนปิดเรื่อง (ของที่เสร็จแล้ว)">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold text-sky-700">เวลาเฉลี่ยที่ใช้</p>
                <i class="fa-solid fa-clock text-sky-400 text-sm"></i>
            </div>
            <p class="text-3xl font-bold text-slate-900 mt-2"><span id="sla-kpi-tat" data-counter="0">0</span><span class="text-base text-slate-500"> ชม.</span></p>
            <p class="text-xs text-slate-400 mt-1">ตั้งแต่เริ่ม ถึง ปิดเรื่อง</p>
        </div>
    </div>

    <!-- Charts row -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <!-- Trend (bar 12 เดือน) -->
        <div class="lg:col-span-2 bg-white rounded-3xl border border-slate-200 p-5">
            <p class="text-sm font-semibold text-slate-700 mb-3">ย้อนหลัง 12 เดือน · เทียบ "เสร็จทัน" กับ "เลยกำหนด"</p>
            <div class="relative h-72">
                <canvas id="sla-chart-trend"></canvas>
            </div>
        </div>
        <!-- By dept donut -->
        <div class="bg-white rounded-3xl border border-slate-200 p-5">
            <p class="text-sm font-semibold text-slate-700 mb-3">ฝ่ายที่เลยกำหนดบ่อย · ย้อนหลัง 90 วัน</p>
            <div class="relative h-72">
                <canvas id="sla-chart-dept"></canvas>
            </div>
        </div>
    </div>

    <!-- Top overdue list -->
    <div class="bg-white rounded-3xl border border-slate-200 overflow-hidden mb-6">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
            <div>
                <p class="text-base font-semibold text-slate-800">ต้องเร่งทำให้เสร็จ</p>
                <p class="text-xs text-slate-500 mt-0.5">ใกล้หมดเวลา หรือเลยกำหนดแล้ว · เรียงจากที่หมดก่อน</p>
            </div>
            <button onclick="slaReload()" class="text-xs font-semibold text-emerald-600 hover:text-emerald-700 hover:underline">
                <i class="fa-solid fa-rotate"></i> รีโหลด
            </button>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-xs font-semibold text-slate-500">
                    <tr>
                        <th class="px-4 py-3 text-left">เอกสาร / งาน</th>
                        <th class="px-4 py-3 text-left">ผู้รับผิดชอบ</th>
                        <th class="px-4 py-3 text-left">ต้องเสร็จก่อน</th>
                        <th class="px-4 py-3 text-center">เวลาที่เหลือ</th>
                        <th class="px-4 py-3 text-center">สถานะ</th>
                        <th class="px-4 py-3 text-right"></th>
                    </tr>
                </thead>
                <tbody id="sla-overdue-body" class="divide-y divide-slate-50">
                    <tr><td colspan="6" class="px-4 py-12 text-center text-slate-400 text-xs font-bold">กำลังโหลด…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function(){
    // portal_CSRF เป็น const declared ใน portal/index.php — เข้า window ไม่ได้ ต้อง resolve ตอน call
    function getCsrf() { try { return portal_CSRF; } catch { return window.portal_CSRF || ''; } }
    let chartTrend = null;
    let chartDept = null;
    let currentPeriod = 'month';

    function chartTheme() {
        const dark = document.body.getAttribute('data-theme') === 'dark';
        return {
            tick:   dark ? '#cbd5e1' : '#64748b',
            grid:   dark ? 'rgba(241,245,249,.08)' : 'rgba(15,23,42,.06)',
            legend: dark ? '#e2e8f0' : '#334155',
            border: dark ? '#1e293b' : '#fff',
        };
    }

    async function ajax(entity, action, params = {}) {
        const fd = new FormData();
        fd.append('csrf_token', getCsrf());
        fd.append('entity', entity);
        fd.append('action', action);
        Object.entries(params).forEach(([k, v]) => fd.append(k, v));
        const res = await fetch('ajax_edms_sla.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const text = await res.text();
        try { return JSON.parse(text); } catch { return { ok: false, message: text.substring(0,200) }; }
    }

    window.slaSetPeriod = function(p) {
        currentPeriod = p;
        document.getElementById('sla-period-month').className = (p === 'month')
            ? 'px-3 py-2 rounded-xl text-xs font-black bg-emerald-50 text-emerald-700 border border-emerald-100'
            : 'px-3 py-2 rounded-xl text-xs font-black text-slate-500 hover:bg-slate-50 border border-slate-200';
        document.getElementById('sla-period-year').className = (p === 'year')
            ? 'px-3 py-2 rounded-xl text-xs font-black bg-emerald-50 text-emerald-700 border border-emerald-100'
            : 'px-3 py-2 rounded-xl text-xs font-black text-slate-500 hover:bg-slate-50 border border-slate-200';
        loadKpi();
    };

    window.slaReload = function() { loadKpi(); loadTrend(); loadDept(); loadOverdue(); };

    async function loadKpi() {
        const r = await ajax('dashboard', 'kpi', { period: currentPeriod });
        if (!r.ok) return;
        document.getElementById('sla-kpi-ontime').setAttribute('data-counter', r.kpi.on_time_pct);
        document.getElementById('sla-kpi-warning').setAttribute('data-counter', r.kpi.warning_cnt);
        document.getElementById('sla-kpi-breached').setAttribute('data-counter', r.kpi.breached_cnt);
        document.getElementById('sla-kpi-tat').setAttribute('data-counter', r.kpi.avg_tat_hours);
        document.getElementById('sla-kpi-ontime').textContent = r.kpi.on_time_pct;
        document.getElementById('sla-kpi-warning').textContent = r.kpi.warning_cnt;
        document.getElementById('sla-kpi-breached').textContent = r.kpi.breached_cnt;
        document.getElementById('sla-kpi-tat').textContent = r.kpi.avg_tat_hours;

        const fmtDelta = (n, suffix='') => {
            if (n === 0) return '— ไม่เปลี่ยน';
            const arrow = n > 0 ? '▲' : '▼';
            const color = n > 0 ? 'text-emerald-600' : 'text-rose-600';
            return `<span class="${color}">${arrow} ${Math.abs(n).toFixed(1)}${suffix}</span>`;
        };
        document.getElementById('sla-kpi-ontime-delta').innerHTML = fmtDelta(r.delta.on_time_pct_delta, '%');
        // สำหรับ breached: ลดลง = ดี (กลับสี)
        const bd = r.delta.breached_delta;
        const bdHtml = bd === 0 ? '— ไม่เปลี่ยน'
            : (bd > 0 ? `<span class="text-rose-600">▲ ${bd}</span>` : `<span class="text-emerald-600">▼ ${Math.abs(bd)}</span>`);
        document.getElementById('sla-kpi-breached-delta').innerHTML = bdHtml;

        if (window.RsuFx) RsuFx.refresh(document.getElementById('sla-dashboard'));
    }

    async function loadTrend() {
        const r = await ajax('dashboard', 'trend');
        if (!r.ok) return;
        const t = chartTheme();
        if (chartTrend) chartTrend.destroy();
        chartTrend = new Chart(document.getElementById('sla-chart-trend'), {
            type: 'bar',
            data: {
                labels: r.labels,
                datasets: [
                    { label: 'เสร็จทันเวลา', data: r.met, backgroundColor: 'rgba(16,185,129,.85)', borderRadius: 6 },
                    { label: 'เลยกำหนด', data: r.breached, backgroundColor: 'rgba(244,63,94,.85)', borderRadius: 6 },
                ],
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { labels: { color: t.legend, font: { weight: 'bold' } } } },
                scales: {
                    x: { stacked: true, ticks: { color: t.tick }, grid: { display: false } },
                    y: { stacked: true, beginAtZero: true, ticks: { color: t.tick, precision: 0 }, grid: { color: t.grid } },
                },
            },
        });
    }

    async function loadDept() {
        const r = await ajax('dashboard', 'by_dept');
        if (!r.ok) return;
        const t = chartTheme();
        if (chartDept) chartDept.destroy();
        const palette = ['#f43f5e','#f97316','#f59e0b','#eab308','#84cc16','#10b981','#06b6d4','#3b82f6','#8b5cf6','#ec4899'];
        chartDept = new Chart(document.getElementById('sla-chart-dept'), {
            type: 'doughnut',
            data: {
                labels: r.labels,
                datasets: [{ data: r.data, backgroundColor: palette.slice(0, r.labels.length), borderColor: t.border, borderWidth: 2 }],
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '60%',
                plugins: { legend: { position: 'bottom', labels: { color: t.legend, font: { weight: 'bold', size: 10 }, boxWidth: 10 } } },
            },
        });
    }

    async function loadOverdue() {
        const r = await ajax('dashboard', 'overdue_list');
        const tbody = document.getElementById('sla-overdue-body');
        if (!r.ok || !r.rows || r.rows.length === 0) {
            tbody.innerHTML = `<tr><td colspan="6" class="px-4 py-16 text-center">
                <i class="fa-solid fa-check-double text-3xl mb-3 block text-emerald-300"></i>
                <p class="text-emerald-700 text-sm font-black mb-1">เยี่ยม! ทุกอย่างอยู่ในเวลา</p>
                <p class="text-slate-400 text-xs font-bold">ไม่มีเอกสาร/งานที่ใกล้หมดเวลา หรือเลยกำหนด</p>
                <a href="?section=edms&edms_view=myinbox" class="inline-flex items-center gap-1.5 mt-3 px-3 py-1.5 rounded-lg bg-emerald-50 text-emerald-700 border border-emerald-200 text-[11px] font-black hover:bg-emerald-100">
                    <i class="fa-solid fa-inbox"></i> ดูกล่องของฉัน
                </a>
            </td></tr>`;
            return;
        }
        const tones = {
            warning:  'bg-amber-50 text-amber-700 border-amber-200',
            breached: 'bg-rose-50 text-rose-700 border-rose-200',
        };
        const labels = {
            warning:  'ใกล้หมดเวลา',
            breached: 'เลยกำหนดแล้ว',
        };
        const fmtRemaining = (mins) => {
            const abs = Math.abs(mins);
            const h = Math.floor(abs / 60);
            const m = abs % 60;
            const parts = [];
            if (h > 0) parts.push(`${h} ชม.`);
            if (m > 0 || h === 0) parts.push(`${m} นาที`);
            const str = parts.join(' ');
            return mins < 0 ? `เลยมา ${str}` : `เหลือ ${str}`;
        };
        const escape = (s) => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        tbody.innerHTML = r.rows.map(row => {
            const tone = tones[row.sla_state] || tones.warning;
            const label = labels[row.sla_state] || row.sla_state;
            const dl = row.resolve_deadline_at ? new Date(row.resolve_deadline_at).toLocaleString('th-TH', { dateStyle: 'short', timeStyle: 'short' }) : '-';
            const remColor = row.minutes_left < 0 ? 'text-rose-600' : (row.minutes_left < 120 ? 'text-amber-600' : 'text-slate-600');
            return `<tr class="hover:bg-slate-50/60">
                <td class="px-4 py-3">
                    <a href="?section=edms&edms_view=detail&id=${row.doc_id}" class="block group">
                        <div class="font-black text-slate-800 group-hover:text-sky-600 line-clamp-1">${escape(row.subject)}</div>
                        <div class="text-[10px] font-bold text-slate-400 mt-0.5">${escape(row.doc_number || '—')}</div>
                    </a>
                </td>
                <td class="px-4 py-3 text-xs font-bold text-slate-600">${escape(row.assignee)}</td>
                <td class="px-4 py-3 text-xs font-bold text-slate-600 whitespace-nowrap">${dl}</td>
                <td class="px-4 py-3 text-center text-xs font-black ${remColor} whitespace-nowrap">${fmtRemaining(row.minutes_left)}</td>
                <td class="px-4 py-3 text-center">
                    <span class="inline-flex px-2.5 py-0.5 rounded-full text-[10px] font-black border ${tone}">${label}</span>
                </td>
                <td class="px-4 py-3 text-right">
                    <a href="?section=edms&edms_view=detail&id=${row.doc_id}" class="bg-emerald-500 hover:bg-emerald-600 text-white px-3 py-1.5 rounded-lg text-xs font-black inline-flex items-center gap-1.5">
                        <i class="fa-solid fa-arrow-right"></i> ดู
                    </a>
                </td>
            </tr>`;
        }).join('');
    }

    // Theme-aware chart re-render
    new MutationObserver(muts => {
        for (const m of muts) {
            if (m.attributeName === 'data-theme') { loadTrend(); loadDept(); break; }
        }
    }).observe(document.body, { attributes: true, attributeFilter: ['data-theme'] });

    // Initial load
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', slaReload);
    else slaReload();

    // ── Help tour ─────────────────────────────────────────
    const slaSteps = [
        { popover: { title: 'ภาพรวมเวลาดำเนินการ', description: 'หน้านี้บอกว่าเอกสารและงานของทีม ทำเสร็จตามเวลาที่ตกลงไว้หรือเปล่า' } },
        { element: '#sla-kpi-ontime', popover: { title: 'เสร็จทันเวลา (%)', description: '% ของเอกสารที่ปิดก่อนเลยกำหนด — สูง = ดี · ตัวเลขข้างใต้แสดงการเปลี่ยนแปลงเทียบช่วงก่อนหน้า', side: 'bottom' } },
        { element: '#sla-kpi-breached', popover: { title: 'เลยกำหนด', description: 'จำนวนเอกสารที่เลยเวลาที่ต้องเสร็จแล้ว — ลด = ดี · กดดูได้ในกล่องของฉัน', side: 'bottom' } },
        { element: '#sla-kpi-tat', popover: { title: 'เวลาเฉลี่ยที่ใช้', description: 'นับตั้งแต่รับเรื่องจนปิดเรื่อง (เฉพาะของที่เสร็จแล้ว) — น้อย = ดี', side: 'bottom' } },
        { element: '#sla-chart-trend', popover: { title: 'แนวโน้ม 12 เดือน', description: 'แท่งเขียว = เสร็จทัน · แท่งแดง = เลยกำหนด · ดูเดือนไหนหนัก/เบา', side: 'top' } },
        { element: '#sla-overdue-body', popover: { title: 'ต้องเร่งทำให้เสร็จ', description: 'รายการที่ใกล้หมดเวลา หรือเลยกำหนดแล้ว — เรียงจากเร่งสุด คลิกได้ทันที', side: 'top' } },
    ];
    window._slaHelp = function(){ window.RsuTour && RsuTour.start(slaSteps, 'sla_dash'); };
    if (window.RsuTour) RsuTour.maybeAutoStart('sla_dash', slaSteps);
})();
</script>
