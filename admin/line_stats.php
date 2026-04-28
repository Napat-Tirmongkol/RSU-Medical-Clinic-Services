<?php
// admin/line_stats.php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

require_once __DIR__ . '/includes/header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js" defer></script>

<div class="animate-slide-up">

<?php renderPageHeader(
    '<i class="fa-brands fa-line" style="color:#00b900"></i> LINE OA Statistics',
    'สถิติการส่งข้อความผ่าน LINE Messaging API'
); ?>

<!-- ── Date Picker + Refresh ──────────────────────────────────────────────── -->
<div class="flex flex-wrap items-center gap-3 mb-6">
    <div class="flex items-center gap-2 bg-white border border-gray-200 rounded-xl px-4 py-2 shadow-sm">
        <i class="fa-regular fa-calendar text-green-600 text-sm"></i>
        <label class="text-xs font-bold text-gray-500 uppercase tracking-wider">วันที่</label>
        <input type="date" id="statDate"
               class="text-sm font-semibold text-gray-800 border-none outline-none bg-transparent cursor-pointer"
               max="<?= date('Y-m-d', strtotime('-1 day')) ?>"
               value="<?= date('Y-m-d', strtotime('-1 day')) ?>">
    </div>
    <button id="btnLoad"
            class="flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold text-white transition-all shadow-sm hover:shadow-md active:scale-95"
            style="background:linear-gradient(135deg,#2e9e63,#3bba7a)">
        <i class="fa-solid fa-rotate"></i> โหลดข้อมูล
    </button>
    <div id="statusBadge" class="hidden text-xs font-bold px-3 py-1.5 rounded-full"></div>
    <div id="spinner" class="hidden">
        <i class="fa-solid fa-circle-notch fa-spin text-green-500"></i>
    </div>
</div>

<!-- ── Quota Cards ─────────────────────────────────────────────────────────── -->
<div class="mb-3">
    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-3">โควต้าข้อความ (เดือนนี้)</p>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6" id="quotaCards">
        <?php foreach ([
            ['id' => 'cardQuotaLimit', 'icon' => 'fa-envelope', 'color' => '#2e9e63', 'bg' => '#e8f8f0', 'label' => 'โควต้าต่อเดือน'],
            ['id' => 'cardQuotaUsed',  'icon' => 'fa-paper-plane', 'color' => '#2563eb', 'bg' => '#eff6ff', 'label' => 'ส่งไปแล้วเดือนนี้'],
            ['id' => 'cardQuotaLeft',  'icon' => 'fa-gauge',      'color' => '#d97706', 'bg' => '#fffbeb', 'label' => 'คงเหลือ'],
        ] as $c): ?>
        <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 flex items-center gap-4" id="<?= $c['id'] ?>">
            <div class="w-12 h-12 rounded-2xl flex items-center justify-center shrink-0 shadow-sm"
                 style="background:<?= $c['bg'] ?>;color:<?= $c['color'] ?>">
                <i class="fa-solid <?= $c['icon'] ?> text-lg"></i>
            </div>
            <div>
                <div class="text-2xl font-black text-gray-900 leading-none quota-value">—</div>
                <div class="text-[11px] font-semibold text-gray-400 mt-1"><?= $c['label'] ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Quota progress bar -->
    <div id="quotaBarWrap" class="hidden bg-white rounded-2xl p-4 shadow-sm border border-gray-100 mb-6">
        <div class="flex justify-between text-xs font-semibold text-gray-500 mb-2">
            <span>การใช้งาน</span>
            <span id="quotaBarPct">0%</span>
        </div>
        <div class="w-full bg-gray-100 rounded-full h-3 overflow-hidden">
            <div id="quotaBar" class="h-3 rounded-full transition-all duration-700"
                 style="width:0%;background:linear-gradient(90deg,#2e9e63,#86efac)"></div>
        </div>
    </div>
</div>

<!-- ── Delivery Stats Cards ─────────────────────────────────────────────────── -->
<div class="mb-3">
    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-3" id="deliveryLabel">
        สถิติการส่งข้อความ — <?= date('d/m/Y', strtotime('-1 day')) ?>
    </p>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6" id="deliveryCards">
        <?php
        $deliveryDefs = [
            ['key' => 'broadcast',      'icon' => 'fa-bullhorn',       'color' => '#7c3aed', 'bg' => '#f5f3ff', 'label' => 'Broadcast (OA)'],
            ['key' => 'targeting',      'icon' => 'fa-crosshairs',     'color' => '#0891b2', 'bg' => '#ecfeff', 'label' => 'Targeting (OA)'],
            ['key' => 'apiBroadcast',   'icon' => 'fa-satellite-dish', 'color' => '#be185d', 'bg' => '#fdf2f8', 'label' => 'API Broadcast'],
            ['key' => 'apiPush',        'icon' => 'fa-bell',           'color' => '#2563eb', 'bg' => '#eff6ff', 'label' => 'API Push'],
            ['key' => 'apiMulticast',   'icon' => 'fa-users',          'color' => '#059669', 'bg' => '#ecfdf5', 'label' => 'API Multicast'],
            ['key' => 'apiNarrowcast',  'icon' => 'fa-filter',         'color' => '#d97706', 'bg' => '#fffbeb', 'label' => 'API Narrowcast'],
            ['key' => 'apiReply',       'icon' => 'fa-reply',          'color' => '#16a34a', 'bg' => '#f0fdf4', 'label' => 'API Reply'],
            ['key' => 'pnpNoticeMessage','icon'=> 'fa-mobile-screen',  'color' => '#6b7280', 'bg' => '#f9fafb', 'label' => 'PNP Notice'],
        ];
        foreach ($deliveryDefs as $d):
        ?>
        <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100"
             data-key="<?= $d['key'] ?>">
            <div class="flex items-center gap-2 mb-3">
                <div class="w-8 h-8 rounded-xl flex items-center justify-center shrink-0"
                     style="background:<?= $d['bg'] ?>;color:<?= $d['color'] ?>">
                    <i class="fa-solid <?= $d['icon'] ?> text-xs"></i>
                </div>
                <span class="text-[11px] font-bold text-gray-500 leading-tight"><?= $d['label'] ?></span>
            </div>
            <div class="text-2xl font-black text-gray-900 delivery-value">—</div>
            <div class="text-[10px] font-semibold text-gray-400 mt-0.5">ข้อความ</div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ── Chart ──────────────────────────────────────────────────────────────── -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
    <!-- Bar chart: Delivery breakdown -->
    <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
        <p class="text-sm font-black text-gray-700 mb-4">ปริมาณข้อความตามประเภท</p>
        <div style="position:relative;height:260px">
            <canvas id="deliveryChart"></canvas>
        </div>
    </div>
    <!-- Doughnut: Quota usage -->
    <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 flex flex-col">
        <p class="text-sm font-black text-gray-700 mb-4">อัตราใช้โควต้า</p>
        <div class="flex-1 flex items-center justify-center" style="max-height:260px">
            <canvas id="quotaChart"></canvas>
        </div>
    </div>
</div>

<!-- ── Error banner ───────────────────────────────────────────────────────── -->
<div id="errorBanner" class="hidden bg-red-50 border border-red-200 rounded-2xl p-4 mb-4 text-sm text-red-700 flex gap-3 items-start">
    <i class="fa-solid fa-triangle-exclamation text-red-400 mt-0.5 shrink-0"></i>
    <span id="errorMsg"></span>
</div>

</div><!-- /.animate-slide-up -->

<script>
(function () {
    'use strict';

    var AJAX_BASE = 'ajax/ajax_line_stats.php';

    // ── Chart instances ─────────────────────────────────────────────────────
    var deliveryChart = null;
    var quotaChart    = null;

    var DELIVERY_KEYS    = ['broadcast','targeting','apiBroadcast','apiPush','apiMulticast','apiNarrowcast','apiReply','pnpNoticeMessage'];
    var DELIVERY_LABELS  = ['Broadcast (OA)','Targeting (OA)','API Broadcast','API Push','API Multicast','API Narrowcast','API Reply','PNP Notice'];
    var DELIVERY_COLORS  = ['#7c3aed','#0891b2','#be185d','#2563eb','#059669','#d97706','#16a34a','#6b7280'];

    // ── Helpers ─────────────────────────────────────────────────────────────
    function fmt(n) {
        if (n === null || n === undefined || n === '') return '—';
        return Number(n).toLocaleString('th-TH');
    }

    function setSpinner(on) {
        document.getElementById('spinner').classList.toggle('hidden', !on);
        document.getElementById('btnLoad').disabled = on;
    }

    function showError(msg) {
        var el = document.getElementById('errorBanner');
        document.getElementById('errorMsg').textContent = msg;
        el.classList.remove('hidden');
    }

    function hideError() {
        document.getElementById('errorBanner').classList.add('hidden');
    }

    function setStatus(text, type) {
        var b = document.getElementById('statusBadge');
        b.textContent = text;
        b.className = 'text-xs font-bold px-3 py-1.5 rounded-full ' +
            (type === 'ready'   ? 'bg-green-100 text-green-700' :
             type === 'unready' ? 'bg-amber-100 text-amber-700' :
             type === 'error'   ? 'bg-red-100 text-red-700'     :
                                  'bg-gray-100 text-gray-500');
        b.classList.remove('hidden');
    }

    // ── Quota ────────────────────────────────────────────────────────────────
    function loadQuota() {
        fetch(AJAX_BASE + '?action=quota')
            .then(function(r){ return r.json(); })
            .then(function(res) {
                if (res.status !== 'ok') { showError('โหลดข้อมูลโควต้าไม่สำเร็จ'); return; }

                var quota  = res.quota  || {};
                var cons   = res.consumption || {};
                var used   = Number(cons.totalUsage || 0);
                var limit  = quota.type === 'limited' ? Number(quota.value || 0) : null;
                var left   = limit !== null ? Math.max(0, limit - used) : null;

                var cards  = document.querySelectorAll('#quotaCards .quota-value');
                cards[0].textContent = limit !== null ? fmt(limit) : 'ไม่จำกัด';
                cards[1].textContent = fmt(used);
                cards[2].textContent = left !== null ? fmt(left) : '∞';

                // Progress bar
                if (limit !== null && limit > 0) {
                    var pct = Math.round((used / limit) * 100);
                    document.getElementById('quotaBarWrap').classList.remove('hidden');
                    document.getElementById('quotaBar').style.width = pct + '%';
                    document.getElementById('quotaBarPct').textContent = pct + '%';
                    if (pct >= 90) document.getElementById('quotaBar').style.background = 'linear-gradient(90deg,#ef4444,#fca5a5)';
                    else if (pct >= 70) document.getElementById('quotaBar').style.background = 'linear-gradient(90deg,#d97706,#fcd34d)';
                }

                // Doughnut chart
                buildQuotaChart(used, left, limit);
            })
            .catch(function(){ showError('ไม่สามารถเชื่อมต่อ API ได้'); });
    }

    function buildQuotaChart(used, left, limit) {
        var ctx = document.getElementById('quotaChart').getContext('2d');
        if (quotaChart) { quotaChart.destroy(); }

        var isUnlimited = limit === null;
        quotaChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: isUnlimited ? ['ส่งแล้ว (ไม่จำกัดโควต้า)'] : ['ส่งแล้ว', 'คงเหลือ'],
                datasets: [{
                    data: isUnlimited ? [used || 1] : [used, Math.max(0, left)],
                    backgroundColor: isUnlimited ? ['#2e9e63'] : ['#2e9e63', '#e5e7eb'],
                    borderWidth: 0,
                    hoverOffset: 6,
                }]
            },
            options: {
                cutout: '72%',
                plugins: {
                    legend: { position: 'bottom', labels: { font: { size: 11, weight: 'bold' }, padding: 12 } },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                return ' ' + ctx.label + ': ' + Number(ctx.raw).toLocaleString('th-TH');
                            }
                        }
                    }
                }
            }
        });
    }

    // ── Delivery ─────────────────────────────────────────────────────────────
    function loadDelivery(dateVal) {
        var dateParam = dateVal.replace(/-/g, '');

        // Update label
        var parts = dateVal.split('-');
        var displayDate = parts[2] + '/' + parts[1] + '/' + parts[0];
        document.getElementById('deliveryLabel').textContent = 'สถิติการส่งข้อความ — ' + displayDate;

        fetch(AJAX_BASE + '?action=delivery&date=' + encodeURIComponent(dateParam))
            .then(function(r){ return r.json(); })
            .then(function(res) {
                if (res.status !== 'ok') { showError('โหลดข้อมูล delivery ไม่สำเร็จ'); return; }

                var d = res.data || {};

                // Status badge
                if (d.status === 'ready') {
                    setStatus('ข้อมูลพร้อม', 'ready');
                } else if (d.status === 'unready') {
                    setStatus('ข้อมูลยังไม่พร้อม (กรุณารอ)', 'unready');
                } else if (d.status === 'out_of_service') {
                    setStatus('ไม่มีข้อมูลสำหรับวันนี้', 'error');
                } else if (d._error) {
                    setStatus('เกิดข้อผิดพลาด', 'error');
                    showError(d._error);
                    return;
                }

                // Update delivery cards
                DELIVERY_KEYS.forEach(function(key) {
                    var card = document.querySelector('#deliveryCards [data-key="' + key + '"]');
                    if (card) {
                        var valEl = card.querySelector('.delivery-value');
                        if (valEl) valEl.textContent = d[key] !== undefined ? fmt(d[key]) : '—';
                    }
                });

                // Build bar chart
                buildDeliveryChart(d);
            })
            .catch(function(){ showError('ไม่สามารถเชื่อมต่อ API ได้'); });
    }

    function buildDeliveryChart(d) {
        var ctx = document.getElementById('deliveryChart').getContext('2d');
        if (deliveryChart) { deliveryChart.destroy(); }

        var values = DELIVERY_KEYS.map(function(k){ return Number(d[k] || 0); });

        deliveryChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: DELIVERY_LABELS,
                datasets: [{
                    label: 'จำนวนข้อความ',
                    data: values,
                    backgroundColor: DELIVERY_COLORS.map(function(c){ return c + 'cc'; }),
                    borderColor: DELIVERY_COLORS,
                    borderWidth: 1.5,
                    borderRadius: 6,
                    borderSkipped: false,
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                return ' ' + Number(ctx.raw).toLocaleString('th-TH') + ' ข้อความ';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            font: { size: 10 },
                            callback: function(v) { return Number(v).toLocaleString('th-TH'); }
                        },
                        grid: { color: '#f0f0f0' }
                    },
                    y: { ticks: { font: { size: 10, weight: 'bold' } }, grid: { display: false } }
                }
            }
        });
    }

    // ── Load all ─────────────────────────────────────────────────────────────
    function loadAll() {
        hideError();
        setSpinner(true);
        var dateVal = document.getElementById('statDate').value;
        var done = 0;
        function finish() { if (++done >= 2) setSpinner(false); }
        Promise.all([
            fetch(AJAX_BASE + '?action=quota').then(function(r){ return r.json(); }).then(function(res){
                if (res.status === 'ok') {
                    var quota = res.quota || {}, cons = res.consumption || {};
                    var used  = Number(cons.totalUsage || 0);
                    var limit = quota.type === 'limited' ? Number(quota.value || 0) : null;
                    var left  = limit !== null ? Math.max(0, limit - used) : null;
                    var cards = document.querySelectorAll('#quotaCards .quota-value');
                    cards[0].textContent = limit !== null ? fmt(limit) : 'ไม่จำกัด';
                    cards[1].textContent = fmt(used);
                    cards[2].textContent = left !== null ? fmt(left) : '∞';
                    if (limit !== null && limit > 0) {
                        var pct = Math.round((used / limit) * 100);
                        document.getElementById('quotaBarWrap').classList.remove('hidden');
                        document.getElementById('quotaBar').style.width = pct + '%';
                        document.getElementById('quotaBarPct').textContent = pct + '%';
                        if (pct >= 90) document.getElementById('quotaBar').style.background = 'linear-gradient(90deg,#ef4444,#fca5a5)';
                        else if (pct >= 70) document.getElementById('quotaBar').style.background = 'linear-gradient(90deg,#d97706,#fcd34d)';
                    }
                    buildQuotaChart(used, left, limit);
                }
            }),
            fetch(AJAX_BASE + '?action=delivery&date=' + dateVal.replace(/-/g, '')).then(function(r){ return r.json(); }).then(function(res){
                var parts = dateVal.split('-');
                document.getElementById('deliveryLabel').textContent =
                    'สถิติการส่งข้อความ — ' + parts[2] + '/' + parts[1] + '/' + parts[0];
                if (res.status !== 'ok') { showError('โหลดข้อมูล delivery ไม่สำเร็จ'); return; }
                var d = res.data || {};
                if (d.status === 'ready') setStatus('ข้อมูลพร้อม', 'ready');
                else if (d.status === 'unready') setStatus('ข้อมูลยังไม่พร้อม (กรุณารอ)', 'unready');
                else if (d.status === 'out_of_service') setStatus('ไม่มีข้อมูลสำหรับวันนี้', 'error');
                else if (d._error) { setStatus('เกิดข้อผิดพลาด', 'error'); showError(d._error); return; }
                DELIVERY_KEYS.forEach(function(key) {
                    var card = document.querySelector('#deliveryCards [data-key="' + key + '"]');
                    if (card) {
                        var valEl = card.querySelector('.delivery-value');
                        if (valEl) valEl.textContent = d[key] !== undefined ? fmt(d[key]) : '—';
                    }
                });
                buildDeliveryChart(d);
            }),
        ]).finally(function(){ setSpinner(false); });
    }

    // ── Events ────────────────────────────────────────────────────────────────
    document.getElementById('btnLoad').addEventListener('click', loadAll);
    document.getElementById('statDate').addEventListener('change', loadAll);

    // Auto-load on DOMContentLoaded (after Chart.js is ready)
    window.addEventListener('load', loadAll);

})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
