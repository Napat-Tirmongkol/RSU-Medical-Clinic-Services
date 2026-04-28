<?php // portal/_partials/line_stats.php ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>

<div class="px-5 md:px-8 py-8">

    <!-- Header -->
    <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:24px">
        <div>
            <div class="sec-title" style="margin-bottom:2px">
                <i class="fa-brands fa-line" style="color:#00b900"></i> LINE OA Statistics
            </div>
            <p style="font-size:13px;color:#64748b">สถิติการส่งข้อความผ่าน LINE Messaging API</p>
        </div>
    </div>

    <!-- Date Picker + Refresh -->
    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-bottom:24px">
        <div style="display:flex;align-items:center;gap:8px;background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:8px 14px;box-shadow:0 1px 4px rgba(0,0,0,.05)">
            <i class="fa-regular fa-calendar" style="color:#2e9e63;font-size:13px"></i>
            <label style="font-size:11px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em">วันที่</label>
            <input type="date" id="ls-date"
                   style="font-size:13px;font-weight:700;color:#1e293b;border:none;outline:none;background:transparent;cursor:pointer"
                   max="<?= date('Y-m-d', strtotime('-1 day')) ?>"
                   value="<?= date('Y-m-d', strtotime('-1 day')) ?>">
        </div>
        <button id="ls-btn-load"
                style="display:flex;align-items:center;gap:6px;padding:9px 18px;border-radius:11px;font-size:13px;font-weight:800;color:#fff;border:none;cursor:pointer;background:linear-gradient(135deg,#2e9e63,#3bba7a);box-shadow:0 4px 12px rgba(46,158,99,.3);transition:opacity .18s">
            <i class="fa-solid fa-rotate"></i> โหลดข้อมูล
        </button>
        <div id="ls-status" style="display:none;font-size:12px;font-weight:800;padding:5px 12px;border-radius:20px"></div>
        <div id="ls-spinner" style="display:none"><i class="fa-solid fa-circle-notch fa-spin" style="color:#2e9e63"></i></div>
    </div>

    <!-- Error -->
    <div id="ls-error" style="display:none;background:#fff1f2;border:1.5px solid #fecdd3;border-radius:14px;padding:14px 18px;font-size:13px;color:#be123c;display:none;align-items:flex-start;gap:10px;margin-bottom:20px">
        <i class="fa-solid fa-triangle-exclamation" style="color:#f43f5e;margin-top:1px;flex-shrink:0"></i>
        <span id="ls-error-msg"></span>
    </div>

    <!-- ── Quota Cards ── -->
    <p style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.2em;color:#94a3b8;margin-bottom:12px">โควต้าข้อความ (เดือนนี้)</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:16px">
        <?php
        $quotaCards = [
            ['id'=>'ls-q-limit', 'icon'=>'fa-envelope',    'color'=>'#2e9e63', 'bg'=>'#e8f8f0', 'label'=>'โควต้าต่อเดือน'],
            ['id'=>'ls-q-used',  'icon'=>'fa-paper-plane', 'color'=>'#2563eb', 'bg'=>'#eff6ff', 'label'=>'ส่งไปแล้ว'],
            ['id'=>'ls-q-left',  'icon'=>'fa-gauge',       'color'=>'#d97706', 'bg'=>'#fffbeb', 'label'=>'คงเหลือ'],
        ];
        foreach ($quotaCards as $c): ?>
        <div style="background:#fff;border-radius:18px;border:1.5px solid #e2e8f0;padding:18px;display:flex;align-items:center;gap:14px;box-shadow:0 1px 4px rgba(0,0,0,.04)">
            <div style="width:44px;height:44px;border-radius:14px;background:<?= $c['bg'] ?>;color:<?= $c['color'] ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:17px">
                <i class="fa-solid <?= $c['icon'] ?>"></i>
            </div>
            <div>
                <div id="<?= $c['id'] ?>" style="font-size:22px;font-weight:900;color:#0f172a;line-height:1">—</div>
                <div style="font-size:11px;font-weight:600;color:#94a3b8;margin-top:3px"><?= $c['label'] ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Quota Progress Bar -->
    <div id="ls-quota-bar-wrap" style="display:none;background:#fff;border-radius:14px;border:1.5px solid #e2e8f0;padding:14px 18px;margin-bottom:24px;box-shadow:0 1px 4px rgba(0,0,0,.04)">
        <div style="display:flex;justify-content:space-between;font-size:12px;font-weight:700;color:#64748b;margin-bottom:8px">
            <span>การใช้งาน</span>
            <span id="ls-quota-pct">0%</span>
        </div>
        <div style="background:#f1f5f9;border-radius:99px;height:10px;overflow:hidden">
            <div id="ls-quota-bar" style="height:10px;border-radius:99px;width:0%;background:linear-gradient(90deg,#2e9e63,#86efac);transition:width .7s"></div>
        </div>
    </div>

    <!-- ── Delivery Cards ── -->
    <p id="ls-delivery-label" style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.2em;color:#94a3b8;margin-bottom:12px">
        สถิติการส่งข้อความ — <?= date('d/m/Y', strtotime('-1 day')) ?>
    </p>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:24px">
        <?php
        $deliveryDefs = [
            ['key'=>'broadcast',       'icon'=>'fa-bullhorn',       'color'=>'#7c3aed','bg'=>'#f5f3ff','label'=>'Broadcast (OA)'],
            ['key'=>'targeting',       'icon'=>'fa-crosshairs',     'color'=>'#0891b2','bg'=>'#ecfeff','label'=>'Targeting (OA)'],
            ['key'=>'apiBroadcast',    'icon'=>'fa-satellite-dish', 'color'=>'#be185d','bg'=>'#fdf2f8','label'=>'API Broadcast'],
            ['key'=>'apiPush',         'icon'=>'fa-bell',           'color'=>'#2563eb','bg'=>'#eff6ff','label'=>'API Push'],
            ['key'=>'apiMulticast',    'icon'=>'fa-users',          'color'=>'#059669','bg'=>'#ecfdf5','label'=>'API Multicast'],
            ['key'=>'apiNarrowcast',   'icon'=>'fa-filter',         'color'=>'#d97706','bg'=>'#fffbeb','label'=>'API Narrowcast'],
            ['key'=>'apiReply',        'icon'=>'fa-reply',          'color'=>'#16a34a','bg'=>'#f0fdf4','label'=>'API Reply'],
            ['key'=>'pnpNoticeMessage','icon'=>'fa-mobile-screen',  'color'=>'#6b7280','bg'=>'#f9fafb','label'=>'PNP Notice'],
        ];
        foreach ($deliveryDefs as $d): ?>
        <div data-ls-key="<?= $d['key'] ?>" style="background:#fff;border-radius:16px;border:1.5px solid #e2e8f0;padding:14px;box-shadow:0 1px 4px rgba(0,0,0,.04)">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
                <div style="width:30px;height:30px;border-radius:10px;background:<?= $d['bg'] ?>;color:<?= $d['color'] ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:12px">
                    <i class="fa-solid <?= $d['icon'] ?>"></i>
                </div>
                <span style="font-size:11px;font-weight:700;color:#64748b;line-height:1.3"><?= $d['label'] ?></span>
            </div>
            <div class="ls-dval" style="font-size:22px;font-weight:900;color:#0f172a">—</div>
            <div style="font-size:10px;font-weight:600;color:#94a3b8;margin-top:2px">ข้อความ</div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ── Charts ── -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;margin-bottom:16px">
        <div style="background:#fff;border-radius:18px;border:1.5px solid #e2e8f0;padding:20px;box-shadow:0 1px 4px rgba(0,0,0,.04)">
            <p style="font-size:13px;font-weight:900;color:#374151;margin-bottom:16px">ปริมาณข้อความตามประเภท</p>
            <div style="position:relative;height:240px"><canvas id="ls-bar-chart"></canvas></div>
        </div>
        <div style="background:#fff;border-radius:18px;border:1.5px solid #e2e8f0;padding:20px;box-shadow:0 1px 4px rgba(0,0,0,.04);display:flex;flex-direction:column">
            <p style="font-size:13px;font-weight:900;color:#374151;margin-bottom:16px">อัตราใช้โควต้า</p>
            <div style="flex:1;display:flex;align-items:center;justify-content:center;max-height:240px">
                <canvas id="ls-donut-chart"></canvas>
            </div>
        </div>
    </div>

</div><!-- /.px-5 -->

<script>
(function () {
    'use strict';

    var AJAX = 'ajax_line_stats.php';
    var barChart = null, donutChart = null;

    var KEYS   = ['broadcast','targeting','apiBroadcast','apiPush','apiMulticast','apiNarrowcast','apiReply','pnpNoticeMessage'];
    var LABELS = ['Broadcast (OA)','Targeting (OA)','API Broadcast','API Push','API Multicast','API Narrowcast','API Reply','PNP Notice'];
    var COLORS = ['#7c3aed','#0891b2','#be185d','#2563eb','#059669','#d97706','#16a34a','#6b7280'];

    function fmt(n) {
        if (n == null || n === '') return '—';
        return Number(n).toLocaleString('th-TH');
    }

    function spin(on) {
        document.getElementById('ls-spinner').style.display = on ? 'inline' : 'none';
        document.getElementById('ls-btn-load').disabled = on;
    }

    function showError(msg) {
        var el = document.getElementById('ls-error');
        document.getElementById('ls-error-msg').textContent = msg;
        el.style.display = 'flex';
    }

    function hideError() { document.getElementById('ls-error').style.display = 'none'; }

    function setStatus(text, type) {
        var el = document.getElementById('ls-status');
        el.textContent = text;
        el.style.display = 'inline-block';
        el.style.background = type === 'ready'   ? '#dcfce7' :
                              type === 'unready' ? '#fef9c3' :
                              type === 'err'     ? '#fee2e2' : '#f1f5f9';
        el.style.color      = type === 'ready'   ? '#15803d' :
                              type === 'unready' ? '#a16207' :
                              type === 'err'     ? '#be123c' : '#64748b';
    }

    function buildDonut(used, left, limit) {
        var ctx = document.getElementById('ls-donut-chart').getContext('2d');
        if (donutChart) donutChart.destroy();
        var unlimited = (limit === null);
        donutChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: unlimited ? ['ส่งแล้ว (ไม่จำกัด)'] : ['ส่งแล้ว','คงเหลือ'],
                datasets: [{ data: unlimited ? [used||1] : [used, Math.max(0,left)],
                    backgroundColor: unlimited ? ['#2e9e63'] : ['#2e9e63','#e5e7eb'],
                    borderWidth: 0, hoverOffset: 6 }]
            },
            options: { cutout:'72%', plugins: {
                legend: { position:'bottom', labels:{ font:{size:11,weight:'bold'}, padding:12 } },
                tooltip: { callbacks: { label: function(c){ return ' '+c.label+': '+Number(c.raw).toLocaleString('th-TH'); } } }
            }}
        });
    }

    function buildBar(d) {
        var ctx = document.getElementById('ls-bar-chart').getContext('2d');
        if (barChart) barChart.destroy();
        barChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: LABELS,
                datasets: [{ label:'ข้อความ', data: KEYS.map(function(k){ return Number(d[k]||0); }),
                    backgroundColor: COLORS.map(function(c){ return c+'cc'; }),
                    borderColor: COLORS, borderWidth:1.5, borderRadius:5, borderSkipped:false }]
            },
            options: {
                indexAxis:'y', responsive:true, maintainAspectRatio:false,
                plugins: { legend:{display:false}, tooltip:{ callbacks:{ label:function(c){ return ' '+Number(c.raw).toLocaleString('th-TH')+' ข้อความ'; } } } },
                scales: {
                    x: { beginAtZero:true, ticks:{ font:{size:10}, callback:function(v){ return Number(v).toLocaleString('th-TH'); } }, grid:{color:'#f0f0f0'} },
                    y: { ticks:{ font:{size:10,weight:'bold'} }, grid:{display:false} }
                }
            }
        });
    }

    function loadAll() {
        hideError();
        spin(true);
        var dateVal  = document.getElementById('ls-date').value;
        var dateParam = dateVal.replace(/-/g,'');
        var parts = dateVal.split('-');
        document.getElementById('ls-delivery-label').textContent =
            'สถิติการส่งข้อความ — ' + parts[2] + '/' + parts[1] + '/' + parts[0];

        Promise.all([
            fetch(AJAX + '?action=quota').then(function(r){ return r.json(); }),
            fetch(AJAX + '?action=delivery&date=' + encodeURIComponent(dateParam)).then(function(r){ return r.json(); })
        ]).then(function(results) {
            var qRes = results[0], dRes = results[1];

            // Quota
            if (qRes.status === 'ok') {
                var q    = qRes.quota || {}, c = qRes.consumption || {};
                var used  = Number(c.totalUsage || 0);
                var limit = q.type === 'limited' ? Number(q.value || 0) : null;
                var left  = limit !== null ? Math.max(0, limit - used) : null;

                document.getElementById('ls-q-limit').textContent = limit !== null ? fmt(limit) : 'ไม่จำกัด';
                document.getElementById('ls-q-used').textContent  = fmt(used);
                document.getElementById('ls-q-left').textContent  = left !== null ? fmt(left) : '∞';

                if (limit !== null && limit > 0) {
                    var pct = Math.round((used / limit) * 100);
                    var barWrap = document.getElementById('ls-quota-bar-wrap');
                    barWrap.style.display = 'block';
                    document.getElementById('ls-quota-bar').style.width = pct + '%';
                    document.getElementById('ls-quota-pct').textContent = pct + '%';
                    var bar = document.getElementById('ls-quota-bar');
                    bar.style.background = pct >= 90 ? 'linear-gradient(90deg,#ef4444,#fca5a5)'
                                         : pct >= 70 ? 'linear-gradient(90deg,#d97706,#fcd34d)'
                                         : 'linear-gradient(90deg,#2e9e63,#86efac)';
                }
                buildDonut(used, left, limit);
            }

            // Delivery
            if (dRes.status === 'ok') {
                var d = dRes.data || {};
                if (d.status === 'ready')            setStatus('ข้อมูลพร้อม', 'ready');
                else if (d.status === 'unready')     setStatus('ข้อมูลยังไม่พร้อม', 'unready');
                else if (d.status === 'out_of_service') setStatus('ไม่มีข้อมูลสำหรับวันนี้', 'err');
                else if (d._error)                   { setStatus('เกิดข้อผิดพลาด', 'err'); showError(d._error); }

                KEYS.forEach(function(key) {
                    var card = document.querySelector('[data-ls-key="' + key + '"]');
                    if (card) card.querySelector('.ls-dval').textContent = d[key] != null ? fmt(d[key]) : '—';
                });
                buildBar(d);
            } else {
                showError('โหลดข้อมูล delivery ไม่สำเร็จ');
            }
        }).catch(function(){ showError('ไม่สามารถเชื่อมต่อ API ได้'); })
          .finally(function(){ spin(false); });
    }

    document.getElementById('ls-btn-load').addEventListener('click', loadAll);
    document.getElementById('ls-date').addEventListener('change', loadAll);

    // โหลดเมื่อ section ถูกเปิดครั้งแรก
    var _loaded = false;
    var _orig = window.switchSection;
    window.switchSection = function(sectionId, btn) {
        if (typeof _orig === 'function') _orig(sectionId, btn);
        if (sectionId === 'line_stats' && !_loaded) {
            _loaded = true;
            loadAll();
        }
    };
    // กรณีเปิดมาตรงๆ ด้วย ?section=line_stats
    if (document.getElementById('section-line_stats') &&
        document.getElementById('section-line_stats').style.display !== 'none') {
        loadAll();
    }
})();
</script>
