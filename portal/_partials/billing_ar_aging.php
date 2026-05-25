<?php
/**
 * portal/_partials/billing_ar_aging.php
 * Patient Billing — AR Aging dashboard
 *
 * Backend: portal/ajax_billing.php (entity=aging)
 *   - aging:summary  → buckets + top patients
 *   - aging:detail   → invoices in a specific bucket
 *   - aging:export   → CSV (stream)
 *
 * Loaded by portal/billing_ar_aging.php after role gate.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/patient_billing_helper.php';

$_pdo = db();
pb_ensure_schema($_pdo);
?>

<style>
    #section-billing_ar_aging .ar-card {
        background: #fff; border-radius: 16px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 2px 8px rgba(15,23,42,.04);
        padding: 18px 20px;
    }
    body[data-theme='dark'] #section-billing_ar_aging .ar-card {
        background: #1e293b; border-color: #334155;
    }

    /* KPI tiles */
    #section-billing_ar_aging .ar-kpi-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 12px;
    }
    @media (max-width: 880px) {
        #section-billing_ar_aging .ar-kpi-grid { grid-template-columns: repeat(2, 1fr); }
    }
    #section-billing_ar_aging .ar-kpi {
        background: #fff;
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        padding: 16px 18px;
        position: relative;
        overflow: hidden;
    }
    body[data-theme='dark'] #section-billing_ar_aging .ar-kpi {
        background: #1e293b; border-color: #334155;
    }
    #section-billing_ar_aging .ar-kpi::before {
        content: ''; position: absolute; left: 0; top: 0; bottom: 0;
        width: 4px; background: var(--accent, #2e9e63);
    }
    #section-billing_ar_aging .ar-kpi-label {
        font-size: 11px; font-weight: 700; color: #64748b;
        text-transform: uppercase; letter-spacing: 0.04em;
    }
    #section-billing_ar_aging .ar-kpi-value {
        font-size: 24px; font-weight: 900; color: #0f172a; line-height: 1.1;
        margin-top: 4px; font-variant-numeric: tabular-nums;
    }
    body[data-theme='dark'] #section-billing_ar_aging .ar-kpi-value { color: #f1f5f9; }
    #section-billing_ar_aging .ar-kpi-sub {
        font-size: 11px; color: #64748b; margin-top: 6px;
    }

    /* Bucket cards (clickable to drill-down) */
    #section-billing_ar_aging .ar-bucket-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 12px;
    }
    @media (max-width: 880px) {
        #section-billing_ar_aging .ar-bucket-grid { grid-template-columns: repeat(2, 1fr); }
    }
    #section-billing_ar_aging .ar-bucket {
        background: #fff;
        border-radius: 14px;
        border: 1.5px solid #e2e8f0;
        padding: 16px 18px;
        cursor: pointer;
        transition: all .18s ease;
    }
    #section-billing_ar_aging .ar-bucket:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(15,23,42,.08);
    }
    #section-billing_ar_aging .ar-bucket.is-active {
        background: linear-gradient(180deg, #fff, #f0fdf4);
        border-color: #2e9e63;
        box-shadow: 0 8px 20px rgba(46,158,99,.18);
    }
    body[data-theme='dark'] #section-billing_ar_aging .ar-bucket {
        background: #1e293b; border-color: #334155;
    }
    body[data-theme='dark'] #section-billing_ar_aging .ar-bucket:hover {
        background: #0f172a;
    }
    body[data-theme='dark'] #section-billing_ar_aging .ar-bucket.is-active {
        background: linear-gradient(180deg, #1e293b, #064e3b);
        border-color: #2e9e63;
    }
    #section-billing_ar_aging .ar-bucket-range {
        font-size: 12px; font-weight: 700; color: #475569;
        text-transform: uppercase; letter-spacing: 0.05em;
    }
    #section-billing_ar_aging .ar-bucket-total {
        font-size: 22px; font-weight: 900; color: #0f172a;
        font-variant-numeric: tabular-nums;
        margin-top: 4px;
    }
    body[data-theme='dark'] #section-billing_ar_aging .ar-bucket-total { color: #f1f5f9; }
    #section-billing_ar_aging .ar-bucket-count {
        font-size: 11px; color: #64748b; margin-top: 4px;
    }
    #section-billing_ar_aging .ar-bucket[data-bucket="0-30"]  .ar-bucket-range { color: #15803d; }
    #section-billing_ar_aging .ar-bucket[data-bucket="31-60"] .ar-bucket-range { color: #b45309; }
    #section-billing_ar_aging .ar-bucket[data-bucket="61-90"] .ar-bucket-range { color: #c2410c; }
    #section-billing_ar_aging .ar-bucket[data-bucket="90+"]   .ar-bucket-range { color: #b91c1c; }

    /* Tables */
    #section-billing_ar_aging .ar-table {
        width: 100%; border-collapse: collapse; font-size: 13px;
    }
    #section-billing_ar_aging .ar-table th {
        background: #f8fafc; padding: 10px 12px;
        text-align: left; font-weight: 800; color: #475569;
        font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em;
        border-bottom: 2px solid #e2e8f0;
    }
    #section-billing_ar_aging .ar-table td {
        padding: 10px 12px; border-bottom: 1px solid #f1f5f9;
    }
    body[data-theme='dark'] #section-billing_ar_aging .ar-table th {
        background: #0f172a; color: #cbd5e1; border-color: #334155;
    }
    body[data-theme='dark'] #section-billing_ar_aging .ar-table td {
        border-color: #334155; color: #e2e8f0;
    }
    #section-billing_ar_aging .ar-table tr:hover td { background: #f8fafc; }
    body[data-theme='dark'] #section-billing_ar_aging .ar-table tr:hover td { background: #0f172a; }

    #section-billing_ar_aging .ar-age-pill {
        display: inline-block;
        padding: 2px 8px; border-radius: 999px;
        font-size: 11px; font-weight: 700;
        font-variant-numeric: tabular-nums;
    }

    #section-billing_ar_aging .ar-input {
        padding: 9px 12px; border-radius: 10px;
        border: 1.5px solid #e2e8f0; background: #f8fafc;
        font-size: 14px; font-weight: 500; outline: none;
    }
    body[data-theme='dark'] #section-billing_ar_aging .ar-input {
        background: #0f172a; border-color: #334155; color: #e2e8f0;
    }

    #section-billing_ar_aging .ar-empty {
        text-align: center; padding: 40px 20px; color: #94a3b8;
    }
    #section-billing_ar_aging .ar-empty i {
        font-size: 40px; margin-bottom: 10px; opacity: 0.5; display: block;
    }
</style>

<!-- Header -->
<div class="flex items-center justify-between mb-5 flex-wrap gap-3">
    <div>
        <h2 class="text-2xl font-black text-slate-900 dark:text-slate-100 flex items-center gap-3">
            <span class="inline-flex w-10 h-10 rounded-xl items-center justify-center"
                  style="background:linear-gradient(135deg,#2e9e63,#3bba7a);color:#fff">
                <i class="fa-solid fa-chart-bar"></i>
            </span>
            ลูกหนี้คงค้าง (AR Aging)
        </h2>
        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1 ml-[52px]">
            แยกใบแจ้งหนี้ที่ยังเก็บเงินไม่ได้ตามอายุ · ดูภาพรวมและตามเก็บได้รวดเร็ว
        </p>
    </div>
    <div class="flex items-center gap-2 flex-wrap">
        <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400">ประเภทผู้ชำระ</label>
        <select id="arFilterPayer" class="ar-input" style="min-width:160px">
            <option value="">— ทุกประเภท —</option>
            <option value="patient">ผู้ป่วยจ่ายเอง</option>
            <option value="insurance">ประกัน</option>
            <option value="gold_card">บัตรทอง</option>
            <option value="other">อื่นๆ</option>
        </select>
        <button type="button" onclick="arExport()" class="ds-btn ds-btn-ghost"
                title="ดาวน์โหลด CSV ของช่วงที่กำลังดูอยู่">
            <i class="fa-solid fa-file-csv mr-1"></i> ดาวน์โหลด CSV
        </button>
    </div>
</div>

<!-- KPI tiles -->
<div class="ar-kpi-grid mb-4">
    <div class="ar-kpi" style="--accent:#2e9e63">
        <div class="ar-kpi-label">ยอดคงค้างรวม</div>
        <div class="ar-kpi-value" id="arKpiTotal">—</div>
        <div class="ar-kpi-sub" id="arKpiCount">— ใบ</div>
    </div>
    <div class="ar-kpi" style="--accent:#3b82f6">
        <div class="ar-kpi-label">อายุเฉลี่ย</div>
        <div class="ar-kpi-value" id="arKpiAvgAge">—</div>
        <div class="ar-kpi-sub">วันนับจากครบกำหนด</div>
    </div>
    <div class="ar-kpi" style="--accent:#dc2626">
        <div class="ar-kpi-label">ใบที่เก่าสุด</div>
        <div class="ar-kpi-value" id="arKpiOldest">—</div>
        <div class="ar-kpi-sub" id="arKpiOldestNo">—</div>
    </div>
    <div class="ar-kpi" style="--accent:#f59e0b">
        <div class="ar-kpi-label">เลย 90 วัน</div>
        <div class="ar-kpi-value" id="arKpi90Plus">—</div>
        <div class="ar-kpi-sub" id="arKpi90PlusCount">— ใบ (Action ด่วน)</div>
    </div>
</div>

<!-- Bucket cards -->
<div class="ar-bucket-grid mb-4">
    <div class="ar-bucket is-active" data-bucket="0-30"  onclick="arSetBucket('0-30')">
        <div class="ar-bucket-range">0 – 30 วัน</div>
        <div class="ar-bucket-total" id="arBucket0_30">—</div>
        <div class="ar-bucket-count" id="arBucket0_30_count">— ใบ</div>
    </div>
    <div class="ar-bucket" data-bucket="31-60" onclick="arSetBucket('31-60')">
        <div class="ar-bucket-range">31 – 60 วัน</div>
        <div class="ar-bucket-total" id="arBucket31_60">—</div>
        <div class="ar-bucket-count" id="arBucket31_60_count">— ใบ</div>
    </div>
    <div class="ar-bucket" data-bucket="61-90" onclick="arSetBucket('61-90')">
        <div class="ar-bucket-range">61 – 90 วัน</div>
        <div class="ar-bucket-total" id="arBucket61_90">—</div>
        <div class="ar-bucket-count" id="arBucket61_90_count">— ใบ</div>
    </div>
    <div class="ar-bucket" data-bucket="90+" onclick="arSetBucket('90+')">
        <div class="ar-bucket-range">เลย 90 วัน</div>
        <div class="ar-bucket-total" id="arBucket90Plus">—</div>
        <div class="ar-bucket-count" id="arBucket90Plus_count">— ใบ</div>
    </div>
</div>

<!-- Two-column: top patients + drill-down -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">
    <!-- Top patients -->
    <div class="ar-card lg:col-span-1">
        <h3 class="text-sm font-black text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-2">
            <i class="fa-solid fa-trophy text-amber-500"></i>
            ผู้ป่วยที่คงค้างสูงสุด (Top 10)
        </h3>
        <div id="arTopPatients">
            <div class="ar-empty"><i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลด...</div>
        </div>
    </div>

    <!-- Drill-down table -->
    <div class="ar-card lg:col-span-2">
        <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
            <h3 class="text-sm font-black text-slate-700 dark:text-slate-200 flex items-center gap-2">
                <i class="fa-solid fa-list-ul text-slate-500"></i>
                รายการใบแจ้งหนี้ใน <span id="arBucketLabel" class="text-emerald-600">0–30 วัน</span>
            </h3>
            <div class="text-xs text-slate-500" id="arDetailCount">— รายการ</div>
        </div>
        <div id="arDetailTable" style="max-height:480px;overflow-y:auto">
            <div class="ar-empty"><i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลด...</div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    const AJAX_URL = 'ajax_billing.php';
    const PAYER_LABELS = {
        patient: 'ผู้ป่วยจ่ายเอง', insurance: 'ประกัน',
        gold_card: 'บัตรทอง', other: 'อื่นๆ',
    };

    let arCurrentBucket = '0-30';
    let arCurrentPayer  = '';

    function thBaht(n) {
        return Number(n || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function thInt(n) {
        return Number(n || 0).toLocaleString('th-TH');
    }
    function thDate(d) {
        if (!d) return '—';
        const m = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
        const p = String(d).split(' ')[0].split('-');
        if (p.length !== 3) return d;
        return Number(p[2]) + ' ' + m[Number(p[1]) - 1] + ' ' + (Number(p[0]) + 543);
    }
    function escapeHtml(s) {
        return String(s || '').replace(/[&<>"']/g, c => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        }[c]));
    }
    function ageColor(age) {
        if (age <= 30) return { bg:'#dcfce7', fg:'#15803d' };
        if (age <= 60) return { bg:'#fef3c7', fg:'#92400e' };
        if (age <= 90) return { bg:'#ffedd5', fg:'#c2410c' };
        return                { bg:'#fee2e2', fg:'#b91c1c' };
    }
    const BUCKET_LABELS = { '0-30':'0–30 วัน', '31-60':'31–60 วัน', '61-90':'61–90 วัน', '90+':'เลย 90 วัน', 'all':'ทั้งหมด' };

    // ── Load summary + buckets + top patients ────────────────────
    function loadSummary() {
        const params = new URLSearchParams({ action: 'aging:summary' });
        if (arCurrentPayer) params.set('payer_type', arCurrentPayer);

        fetch(AJAX_URL + '?' + params.toString(), { credentials: 'same-origin' })
            .then(r => r.json())
            .then(d => {
                if (!d.ok) return;
                renderKpis(d.summary, d.buckets);
                renderBuckets(d.buckets);
                renderTopPatients(d.top_patients);
            });
    }

    function renderKpis(sum, buckets) {
        document.getElementById('arKpiTotal').textContent  = thBaht(sum.total) + ' ฿';
        document.getElementById('arKpiCount').textContent  = thInt(sum.count) + ' ใบ';
        document.getElementById('arKpiAvgAge').textContent = sum.count > 0 ? sum.avg_age_days + ' วัน' : '—';
        document.getElementById('arKpiOldest').textContent = sum.count > 0 ? sum.oldest_age_days + ' วัน' : '—';
        document.getElementById('arKpiOldestNo').textContent = sum.oldest_invoice_no || '—';

        const b90 = buckets['90+'] || { count: 0, total: 0 };
        document.getElementById('arKpi90Plus').textContent      = thBaht(b90.total) + ' ฿';
        document.getElementById('arKpi90PlusCount').textContent = thInt(b90.count) + ' ใบ' + (b90.count > 0 ? ' (Action ด่วน)' : '');
    }

    function renderBuckets(b) {
        document.getElementById('arBucket0_30').textContent   = thBaht(b['0-30'].total) + ' ฿';
        document.getElementById('arBucket31_60').textContent  = thBaht(b['31-60'].total) + ' ฿';
        document.getElementById('arBucket61_90').textContent  = thBaht(b['61-90'].total) + ' ฿';
        document.getElementById('arBucket90Plus').textContent = thBaht(b['90+'].total) + ' ฿';
        document.getElementById('arBucket0_30_count').textContent   = thInt(b['0-30'].count) + ' ใบ';
        document.getElementById('arBucket31_60_count').textContent  = thInt(b['31-60'].count) + ' ใบ';
        document.getElementById('arBucket61_90_count').textContent  = thInt(b['61-90'].count) + ' ใบ';
        document.getElementById('arBucket90Plus_count').textContent = thInt(b['90+'].count) + ' ใบ';
    }

    function renderTopPatients(rows) {
        if (!rows || rows.length === 0) {
            document.getElementById('arTopPatients').innerHTML =
                '<div class="ar-empty"><i class="fa-regular fa-face-smile"></i>' +
                'ไม่มีลูกหนี้คงค้างขณะนี้ 🎉</div>';
            return;
        }
        let html = '<table class="ar-table"><thead><tr>' +
            '<th style="width:30px">#</th>' +
            '<th>ผู้ป่วย</th>' +
            '<th style="text-align:right">คงค้าง</th>' +
            '</tr></thead><tbody>';
        rows.forEach((r, i) => {
            html += '<tr>' +
                '<td><span style="color:' + (i === 0 ? '#d97706' : '#94a3b8') + ';font-weight:800">' +
                    (i === 0 ? '🏆 ' : '') + (i + 1) + '</span></td>' +
                '<td>' +
                    '<div class="font-semibold">' + escapeHtml(r.patient_name || '—') + '</div>' +
                    '<div class="text-xs text-slate-500">' +
                        (r.patient_code ? escapeHtml(r.patient_code) + ' · ' : '') +
                        thInt(r.invoice_count) + ' ใบ · เก่าสุด ' + thInt(r.max_age_days) + ' วัน' +
                    '</div>' +
                '</td>' +
                '<td style="text-align:right;font-weight:800;font-variant-numeric:tabular-nums;color:#b91c1c">' +
                    thBaht(r.balance) + ' ฿</td>' +
                '</tr>';
        });
        html += '</tbody></table>';
        document.getElementById('arTopPatients').innerHTML = html;
    }

    // ── Drill-down detail ────────────────────────────────────────
    function loadDetail() {
        const params = new URLSearchParams({
            action: 'aging:detail',
            bucket: arCurrentBucket,
        });
        if (arCurrentPayer) params.set('payer_type', arCurrentPayer);

        document.getElementById('arBucketLabel').textContent = BUCKET_LABELS[arCurrentBucket] || arCurrentBucket;

        fetch(AJAX_URL + '?' + params.toString())
            .then(r => r.json())
            .then(d => {
                if (!d.ok) {
                    document.getElementById('arDetailTable').innerHTML =
                        '<div class="ar-empty"><i class="fa-solid fa-circle-exclamation"></i>' +
                        escapeHtml(d.message || 'โหลดไม่สำเร็จ') + '</div>';
                    return;
                }
                renderDetail(d.rows || []);
            });
    }

    function renderDetail(rows) {
        document.getElementById('arDetailCount').textContent = thInt(rows.length) + ' รายการ';

        if (rows.length === 0) {
            document.getElementById('arDetailTable').innerHTML =
                '<div class="ar-empty"><i class="fa-regular fa-folder-open"></i>' +
                'ไม่มีใบในช่วงนี้</div>';
            return;
        }

        let html = '<table class="ar-table"><thead><tr>' +
            '<th>เลขที่</th>' +
            '<th>ผู้ป่วย</th>' +
            '<th style="text-align:center">อายุ</th>' +
            '<th>ครบกำหนด</th>' +
            '<th style="text-align:right">คงค้าง</th>' +
            '<th style="text-align:center">จัดการ</th>' +
            '</tr></thead><tbody>';

        rows.forEach(r => {
            const age = parseInt(r.age_days) || 0;
            const c   = ageColor(age);
            html += '<tr>' +
                '<td><code style="background:#f1f5f9;padding:2px 8px;border-radius:5px;font-size:11px;font-weight:700;color:#0f172a">' +
                    escapeHtml(r.invoice_no) + '</code></td>' +
                '<td>' +
                    '<div class="font-semibold">' + escapeHtml(r.patient_name || '—') + '</div>' +
                    (r.patient_phone ? '<div class="text-xs text-slate-500">📞 ' + escapeHtml(r.patient_phone) + '</div>' : '') +
                '</td>' +
                '<td style="text-align:center"><span class="ar-age-pill" style="background:' + c.bg + ';color:' + c.fg + '">' +
                    age + ' วัน</span></td>' +
                '<td class="text-xs text-slate-500">' + thDate(r.due_date || r.issue_date) + '</td>' +
                '<td style="text-align:right;font-weight:800;font-variant-numeric:tabular-nums;color:#b91c1c">' +
                    thBaht(r.balance) + ' ฿</td>' +
                '<td style="text-align:center">' +
                    '<a href="billing_invoices.php?focus_invoice=' + r.id + '" ' +
                    'class="text-xs font-bold text-emerald-700 hover:underline">' +
                    '<i class="fa-solid fa-cash-register mr-0.5"></i>รับชำระ</a>' +
                '</td>' +
                '</tr>';
        });
        html += '</tbody></table>';
        document.getElementById('arDetailTable').innerHTML = html;
    }

    window.arSetBucket = function (bucket) {
        arCurrentBucket = bucket;
        document.querySelectorAll('#section-billing_ar_aging .ar-bucket').forEach(el => {
            el.classList.toggle('is-active', el.dataset.bucket === bucket);
        });
        loadDetail();
    };

    window.arExport = function () {
        const params = new URLSearchParams({
            action: 'aging:export',
            bucket: arCurrentBucket,
        });
        if (arCurrentPayer) params.set('payer_type', arCurrentPayer);
        window.location.href = AJAX_URL + '?' + params.toString();
    };

    document.getElementById('arFilterPayer').addEventListener('change', function () {
        arCurrentPayer = this.value;
        loadSummary();
        loadDetail();
    });

    // Initial load
    loadSummary();
    loadDetail();

    // Auto-refresh every 60s (read-only view, low cost)
    setInterval(() => {
        if (!document.hidden) {
            loadSummary();
            loadDetail();
        }
    }, 60000);
})();
</script>
