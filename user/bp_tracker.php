<?php
// user/bp_tracker.php — Patient self-service BP tracker (LINE-gated)
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/vitals_helper.php';

$lineUserId = $_SESSION['line_user_id'] ?? '';
if ($lineUserId === '') {
    header('Location: index.php');
    exit;
}

$pdo = db();
vitals_bp_ensure_schema($pdo);

// Resolve user
$stu = $pdo->prepare("SELECT id, full_name, prefix FROM sys_users
                      WHERE line_user_id = :lid OR line_user_id_new = :lid2 LIMIT 1");
$stu->execute([':lid' => $lineUserId, ':lid2' => $lineUserId]);
$user = $stu->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    header('Location: profile.php?redirect_back=bp_tracker.php');
    exit;
}

$siteLogo = defined('SITE_LOGO') && SITE_LOGO !== ''
    ? '../' . htmlspecialchars(SITE_LOGO, ENT_QUOTES, 'UTF-8')
    : '../favicon.ico';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>สมุดความดัน · บันทึกของฉัน</title>
<link rel="icon" href="<?= $siteLogo ?>">
<link rel="stylesheet" href="../assets/css/tailwind.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="../assets/css/rsufont.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
    * { font-family: 'Sarabun', sans-serif; -webkit-tap-highlight-color: transparent; }
    body {
        background: linear-gradient(135deg, #fef2f2 0%, #fff 30%, #fef9c3 100%);
        min-height: 100vh;
        padding-bottom: 100px;
    }

    .bpt-card {
        background: #fff; border-radius: 22px;
        box-shadow: 0 4px 16px rgba(15,23,42,.05);
        border: 1px solid #fee2e2;
    }

    .bpt-input {
        width: 100%; padding: 12px 14px; border-radius: 14px;
        border: 2px solid #fecaca; background: #fff;
        font-size: 16px; font-weight: 600; outline: none;
        transition: all .15s ease;
    }
    .bpt-input:focus {
        border-color: #dc2626;
        box-shadow: 0 0 0 4px rgba(220,38,38,.12);
    }

    .bpt-big-input {
        font-size: 36px !important; font-weight: 900 !important;
        text-align: center; padding: 10px !important;
        font-variant-numeric: tabular-nums;
    }

    .bpt-cls {
        display: inline-flex; align-items: center; gap: 4px;
        padding: 4px 12px; border-radius: 999px;
        font-size: 12px; font-weight: 800;
    }
    .bpt-cls-normal   { background: #dcfce7; color: #15803d; }
    .bpt-cls-elevated { background: #fef9c3; color: #a16207; }
    .bpt-cls-stage1   { background: #fed7aa; color: #c2410c; }
    .bpt-cls-stage2   { background: #fee2e2; color: #b91c1c; }
    .bpt-cls-crisis   { background: #7f1d1d; color: #fff; box-shadow: 0 0 0 2px rgba(127,29,29,.3); }

    .bpt-cls-preview {
        padding: 16px; border-radius: 14px; text-align: center;
        font-weight: 800; transition: all .2s ease;
        background: #f1f5f9; color: #94a3b8; font-size: 14px;
    }
    .bpt-cls-preview.bpt-cls-normal   { background: #dcfce7; color: #15803d; }
    .bpt-cls-preview.bpt-cls-elevated { background: #fef9c3; color: #a16207; }
    .bpt-cls-preview.bpt-cls-stage1   { background: #fed7aa; color: #c2410c; }
    .bpt-cls-preview.bpt-cls-stage2   { background: #fee2e2; color: #b91c1c; }
    .bpt-cls-preview.bpt-cls-crisis   { background: #7f1d1d; color: #fff; }

    .bpt-history-card {
        background: #fff; border: 1.5px solid #f1f5f9;
        border-radius: 14px; padding: 12px 14px;
        display: flex; align-items: center; justify-content: space-between;
        gap: 10px;
    }
    .bpt-history-card.is-self {
        border-left: 4px solid #2e9e63;
    }
    .bpt-history-card.is-staff {
        border-left: 4px solid #3b82f6;
        background: #f8fafc;
    }

    .submit-btn {
        background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%);
        color: #fff; padding: 16px;
        border-radius: 18px; font-weight: 900; font-size: 16px;
        width: 100%; border: none;
        box-shadow: 0 10px 25px rgba(220,38,38,.3);
        transition: all .2s;
    }
    .submit-btn:active { transform: scale(.98); }
    .submit-btn:disabled { opacity: .6; cursor: not-allowed; }

    @keyframes popIn {
        from { opacity: 0; transform: translateY(8px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    .pop-in { animation: popIn .4s cubic-bezier(.16,1,.3,1) both; }
</style>
</head>
<body>

<div class="max-w-md mx-auto p-4 pt-6 pop-in">

    <!-- Header -->
    <div class="flex items-center justify-between mb-5">
        <button onclick="window.location.href='hub.php'"
                class="w-10 h-10 rounded-xl bg-white shadow-sm border border-slate-100 flex items-center justify-center text-slate-500">
            <i class="fa-solid fa-arrow-left"></i>
        </button>
        <div class="text-center">
            <div class="text-[10px] font-black uppercase tracking-widest text-rose-600">RSU Medical Clinic</div>
            <h1 class="text-base font-black text-slate-900 mt-0.5">
                <i class="fa-solid fa-heart-pulse text-rose-500 mr-1"></i>สมุดความดันของฉัน
            </h1>
        </div>
        <div class="w-10"></div>
    </div>

    <!-- Latest reading + stats -->
    <div id="bptStatsArea" class="mb-5"></div>

    <!-- Quick Add -->
    <div class="bpt-card p-5 mb-5">
        <div class="flex items-center gap-2 mb-3">
            <span class="w-8 h-8 rounded-lg bg-rose-100 text-rose-600 flex items-center justify-center">
                <i class="fa-solid fa-plus"></i>
            </span>
            <h2 class="text-base font-black text-slate-900">บันทึกค่าใหม่</h2>
        </div>

        <form id="bptForm">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="">

            <div class="grid grid-cols-2 gap-3 mb-3">
                <div>
                    <label class="text-[10px] font-black uppercase tracking-wider text-rose-600 mb-1.5 block">
                        SBP (ตัวบน)
                    </label>
                    <input name="systolic" id="bptSys" type="number" min="60" max="260"
                           class="bpt-input bpt-big-input" placeholder="120" inputmode="numeric" required>
                </div>
                <div>
                    <label class="text-[10px] font-black uppercase tracking-wider text-rose-600 mb-1.5 block">
                        DBP (ตัวล่าง)
                    </label>
                    <input name="diastolic" id="bptDia" type="number" min="30" max="180"
                           class="bpt-input bpt-big-input" placeholder="80" inputmode="numeric" required>
                </div>
            </div>

            <div class="mb-3">
                <label class="text-[10px] font-black uppercase tracking-wider text-rose-600 mb-1.5 block">
                    ชีพจร (bpm) <span class="text-slate-400 font-normal">— ถ้ามี</span>
                </label>
                <input name="pulse_rate" id="bptPulse" type="number" min="30" max="220"
                       class="bpt-input" style="text-align:center;font-weight:700"
                       placeholder="เช่น 72" inputmode="numeric">
            </div>

            <!-- Live preview -->
            <div id="bptClsPreview" class="bpt-cls-preview mb-3">— กรอก SBP/DBP เพื่อดูระดับ —</div>

            <!-- When + position -->
            <div class="grid grid-cols-2 gap-3 mb-3">
                <div>
                    <label class="text-[10px] font-black uppercase tracking-wider text-rose-600 mb-1.5 block">
                        วัน-เวลา
                    </label>
                    <input name="measured_at" id="bptMeasuredAt" type="datetime-local" class="bpt-input text-sm" required>
                </div>
                <div>
                    <label class="text-[10px] font-black uppercase tracking-wider text-rose-600 mb-1.5 block">
                        ท่าวัด
                    </label>
                    <select name="position" class="bpt-input text-sm">
                        <?php foreach (VITALS_POSITIONS as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $k === 'sitting' ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="mb-4">
                <label class="text-[10px] font-black uppercase tracking-wider text-rose-600 mb-1.5 block">
                    หมายเหตุ <span class="text-slate-400 font-normal">— ถ้ามี</span>
                </label>
                <input name="notes" type="text" maxlength="500" class="bpt-input text-sm"
                       placeholder="เช่น หลังตื่นนอน, หลังออกกำลังกาย">
            </div>

            <button type="submit" id="bptSubmitBtn" class="submit-btn">
                <i class="fa-solid fa-floppy-disk mr-1"></i> บันทึก
            </button>
        </form>
    </div>

    <!-- Trend chart -->
    <div class="bpt-card p-5 mb-5">
        <div class="flex items-center gap-2 mb-3">
            <span class="w-8 h-8 rounded-lg bg-rose-100 text-rose-600 flex items-center justify-center">
                <i class="fa-solid fa-chart-line"></i>
            </span>
            <h2 class="text-base font-black text-slate-900">แนวโน้ม (60 ครั้งล่าสุด)</h2>
        </div>
        <div id="bptChartWrap" style="height:240px">
            <div class="text-center text-slate-400 py-12 text-sm">
                <i class="fa-solid fa-spinner fa-spin text-2xl mb-2 block"></i>
                กำลังโหลด...
            </div>
        </div>
        <p class="text-[10px] text-slate-400 mt-2 text-center">
            <span class="inline-block w-2 h-2 rounded-full bg-rose-500 mr-1"></span> SBP
            <span class="inline-block w-2 h-2 rounded-full bg-blue-500 mr-1 ml-3"></span> DBP
        </p>
    </div>

    <!-- History -->
    <div class="bpt-card p-5 mb-5">
        <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2">
                <span class="w-8 h-8 rounded-lg bg-rose-100 text-rose-600 flex items-center justify-center">
                    <i class="fa-solid fa-list"></i>
                </span>
                <h2 class="text-base font-black text-slate-900">ประวัติทั้งหมด</h2>
            </div>
            <span id="bptCount" class="text-xs text-slate-500 font-bold">—</span>
        </div>
        <div id="bptList" class="space-y-2"></div>
        <div id="bptLoadMore" class="text-center mt-3"></div>
    </div>

    <p class="text-center text-[10px] text-slate-400 mb-4">
        🟢 ที่จดเอง · 🔵 ที่เจ้าหน้าที่จดให้
    </p>
</div>

<?php $__navActive = ''; include __DIR__ . '/../includes/user_bottom_nav.php'; ?>

<script>
(function () {
    'use strict';
    const AJAX_URL = 'ajax_bp_submit.php';
    const CLS_LABELS = <?= json_encode(VITALS_BP_CLASSIFICATIONS, JSON_UNESCAPED_UNICODE) ?>;
    const POS_LABELS = <?= json_encode(VITALS_POSITIONS, JSON_UNESCAPED_UNICODE) ?>;

    let bptPage = 1;
    let bptHasMore = false;
    let chartInstance = null;

    function escapeHtml(s) {
        return String(s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
    function thDate(d) {
        if (!d) return '—';
        const m = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
        const p = String(d).split(' ')[0].split('-');
        if (p.length !== 3) return d;
        return Number(p[2]) + ' ' + m[Number(p[1])-1] + ' ' + (Number(p[0])+543);
    }
    function thDateTime(s) {
        if (!s) return '—';
        const parts = String(s).split(' ');
        const t = parts[1] ? parts[1].slice(0,5) : '';
        return thDate(parts[0]) + (t ? ' · ' + t + ' น.' : '');
    }
    function nowLocalForInput() {
        const d = new Date();
        const tz = d.getTimezoneOffset() * 60000;
        return new Date(d - tz).toISOString().slice(0, 16);
    }
    function classifyBp(s, d) {
        if (!s || !d) return null;
        if (s >= 180 || d >= 120) return 'crisis';
        if (s >= 140 || d >=  90) return 'stage2';
        if (s >= 130 || d >=  80) return 'stage1';
        if (s >= 120)             return 'elevated';
        return 'normal';
    }

    document.getElementById('bptMeasuredAt').value = nowLocalForInput();

    function updateClsPreview() {
        const s = parseInt(document.getElementById('bptSys').value, 10);
        const d = parseInt(document.getElementById('bptDia').value, 10);
        const el = document.getElementById('bptClsPreview');
        el.className = 'bpt-cls-preview mb-3';
        if (s && d && s > d) {
            const c = classifyBp(s, d);
            el.classList.add('bpt-cls-' + c);
            el.innerHTML = '<i class="fa-solid fa-heart-pulse mr-1"></i> ' +
                s + '/' + d + ' mmHg · <b>' + (CLS_LABELS[c] || c) + '</b>';
        } else {
            el.textContent = '— กรอก SBP/DBP เพื่อดูระดับ —';
        }
    }
    ['bptSys', 'bptDia'].forEach(id => {
        document.getElementById(id).addEventListener('input', updateClsPreview);
    });

    // ── Submit ────────────────────────────────────────────────────
    document.getElementById('bptForm').addEventListener('submit', function (e) {
        e.preventDefault();
        const btn = document.getElementById('bptSubmitBtn');
        btn.disabled = true;
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> กำลังบันทึก...';

        const fd = new FormData(this);
        fetch(AJAX_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(d => {
                if (d.ok) {
                    Swal.fire({
                        icon: 'success',
                        title: 'บันทึกเรียบร้อย',
                        text: 'ระดับ: ' + (CLS_LABELS[d.classification] || ''),
                        timer: 1600, showConfirmButton: false,
                    });
                    document.getElementById('bptForm').reset();
                    document.getElementById('bptMeasuredAt').value = nowLocalForInput();
                    updateClsPreview();
                    bptPage = 1;
                    loadAll();
                } else {
                    let detail = '';
                    if (d.errors) {
                        detail = '<ul style="text-align:left;font-size:13px;margin-top:8px">' +
                            Object.values(d.errors).map(v => '<li>' + escapeHtml(v) + '</li>').join('') +
                            '</ul>';
                    }
                    Swal.fire({ icon:'error', title:'บันทึกไม่ได้', html: escapeHtml(d.message||'') + detail });
                }
            })
            .catch(() => Swal.fire({ icon:'error', title:'เชื่อมต่อไม่ได้' }))
            .finally(() => { btn.disabled = false; btn.innerHTML = orig; });
    });

    // ── Load summary + trend chart ────────────────────────────────
    function loadSummary() {
        fetch(AJAX_URL + '?action=summary')
            .then(r => r.json())
            .then(d => {
                if (!d.ok) return;
                renderStats(d.latest, d.stats_30d || {});
                renderChart(d.trend_points || []);
            });
    }

    function renderStats(latest, stats) {
        const area = document.getElementById('bptStatsArea');
        if (!latest) {
            area.innerHTML =
                '<div class="bpt-card p-5 text-center">' +
                    '<div class="text-4xl mb-2">💝</div>' +
                    '<div class="font-black text-slate-700">ยังไม่มีบันทึก</div>' +
                    '<div class="text-xs text-slate-500 mt-1">เริ่มบันทึกค่าความดันครั้งแรกได้เลย</div>' +
                '</div>';
            return;
        }
        const cls = latest.classification;
        const colors = {normal:'#15803d',elevated:'#a16207',stage1:'#c2410c',stage2:'#b91c1c',crisis:'#7f1d1d'};
        const bgs    = {normal:'#dcfce7',elevated:'#fef9c3',stage1:'#fed7aa',stage2:'#fee2e2',crisis:'#7f1d1d'};
        const fg     = cls === 'crisis' ? '#fff' : colors[cls];

        area.innerHTML =
            '<div class="bpt-card p-5" style="background:' + (bgs[cls] || '#f1f5f9') + '">' +
                '<div class="text-[10px] font-black uppercase tracking-widest" style="color:' + fg + ';opacity:.8">' +
                    'ค่าล่าสุด · ' + thDateTime(latest.measured_at) +
                '</div>' +
                '<div class="flex items-baseline gap-2 mt-1">' +
                    '<div style="font-size:48px;font-weight:900;line-height:1;color:' + fg + ';font-variant-numeric:tabular-nums">' +
                        latest.systolic + '<span style="opacity:.5">/</span>' + latest.diastolic +
                    '</div>' +
                    '<div style="font-size:13px;font-weight:700;color:' + fg + ';opacity:.7">mmHg</div>' +
                '</div>' +
                '<div class="mt-2">' +
                    '<span class="bpt-cls bpt-cls-' + cls + '">' + (CLS_LABELS[cls] || cls) + '</span>' +
                    (latest.pulse_rate ? '<span class="ml-2 text-xs font-bold" style="color:' + fg + ';opacity:.7">♥ ' + latest.pulse_rate + ' bpm</span>' : '') +
                '</div>' +
                (stats.count > 0
                    ? '<div class="mt-3 pt-3 border-t flex justify-between text-xs" style="border-color:rgba(0,0,0,.1);color:' + fg + '">' +
                        '<div><div class="opacity-70">เฉลี่ย 30 วัน</div><div class="font-black">' + stats.avg_systolic + '/' + stats.avg_diastolic + '</div></div>' +
                        '<div><div class="opacity-70">บันทึก</div><div class="font-black">' + stats.count + ' ครั้ง</div></div>' +
                        '<div><div class="opacity-70">สูงสุด</div><div class="font-black">' + stats.max_systolic + '/' + stats.max_diastolic + '</div></div>' +
                      '</div>'
                    : '') +
            '</div>';
    }

    function renderChart(points) {
        const wrap = document.getElementById('bptChartWrap');
        if (points.length === 0) {
            wrap.innerHTML = '<div class="text-center text-slate-400 py-12 text-sm">' +
                '<i class="fa-regular fa-folder-open text-2xl mb-2 block"></i>ยังไม่มีข้อมูลพอจะวาด trend</div>';
            return;
        }
        wrap.innerHTML = '<canvas id="bptChart"></canvas>';
        if (chartInstance) chartInstance.destroy();
        const labels = points.map(p => p.measured_at.slice(5, 10));
        chartInstance = new Chart(document.getElementById('bptChart'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    { label: 'SBP', data: points.map(p => Number(p.systolic)),
                      borderColor: '#dc2626', backgroundColor: 'rgba(220,38,38,.1)',
                      tension: .25, fill: false, pointRadius: 2.5 },
                    { label: 'DBP', data: points.map(p => Number(p.diastolic)),
                      borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,.1)',
                      tension: .25, fill: false, pointRadius: 2.5 },
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { font: { size: 9 } } },
                    y: { beginAtZero: false, suggestedMin: 50, suggestedMax: 180,
                         ticks: { font: { size: 10 } } },
                }
            }
        });
    }

    // ── List + history ────────────────────────────────────────────
    function loadList(append) {
        const params = new URLSearchParams({ action: 'list', page: bptPage, per_page: 20 });
        fetch(AJAX_URL + '?' + params.toString())
            .then(r => r.json())
            .then(d => {
                if (!d.ok) return;
                bptHasMore = bptPage < d.pages;
                document.getElementById('bptCount').textContent = d.total + ' รายการ';
                renderList(d.rows, append);
                renderLoadMore();
            });
    }

    function renderList(rows, append) {
        const wrap = document.getElementById('bptList');
        if (!append) wrap.innerHTML = '';
        if (rows.length === 0 && !append) {
            wrap.innerHTML = '<div class="text-center text-slate-400 py-6 text-sm">' +
                '<i class="fa-regular fa-folder-open text-2xl mb-2 block"></i>ยังไม่มีประวัติ</div>';
            return;
        }
        rows.forEach(r => {
            const isSelf = r.source === 'self';
            const cls = r.classification;
            const div = document.createElement('div');
            div.className = 'bpt-history-card ' + (isSelf ? 'is-self' : 'is-staff');
            div.innerHTML =
                '<div class="flex-1 min-w-0">' +
                    '<div class="flex items-baseline gap-1">' +
                        '<span class="font-black text-slate-900 text-lg" style="font-variant-numeric:tabular-nums">' +
                            r.systolic + '/' + r.diastolic + '</span>' +
                        '<span class="text-[10px] text-slate-400 font-bold">mmHg</span>' +
                        (r.pulse_rate ? '<span class="ml-1 text-xs text-slate-500">♥' + r.pulse_rate + '</span>' : '') +
                    '</div>' +
                    '<div class="text-[11px] text-slate-500 mt-0.5">' +
                        thDateTime(r.measured_at) +
                        (r.position ? ' · ' + (POS_LABELS[r.position] || r.position) : '') +
                    '</div>' +
                    (r.notes ? '<div class="text-[11px] text-slate-600 mt-1 italic">📝 ' + escapeHtml(r.notes) + '</div>' : '') +
                    (!isSelf ? '<div class="text-[10px] text-blue-600 font-bold mt-1">👨‍⚕️ ' + escapeHtml(r.recorded_by_name || 'เจ้าหน้าที่') + ' บันทึก</div>' : '') +
                '</div>' +
                '<div class="flex flex-col items-end gap-1 flex-shrink-0">' +
                    '<span class="bpt-cls bpt-cls-' + cls + '">' + (CLS_LABELS[cls] || cls) + '</span>' +
                    (isSelf
                        ? '<button onclick="bptDelete(' + r.id + ')" class="text-xs text-rose-500 mt-1"><i class="fa-solid fa-trash-can"></i></button>'
                        : '<span class="text-[9px] text-slate-300 mt-1">เจ้าหน้าที่จด</span>') +
                '</div>';
            wrap.appendChild(div);
        });
    }

    function renderLoadMore() {
        const wrap = document.getElementById('bptLoadMore');
        if (bptHasMore) {
            wrap.innerHTML = '<button onclick="bptLoadMore()" class="text-sm text-rose-600 font-bold hover:underline">' +
                '<i class="fa-solid fa-chevron-down mr-1"></i> โหลดเพิ่ม</button>';
        } else {
            wrap.innerHTML = '';
        }
    }

    window.bptLoadMore = function () {
        bptPage += 1;
        loadList(true);
    };

    window.bptDelete = async function (id) {
        const { isConfirmed } = await Swal.fire({
            icon: 'warning', title: 'ลบบันทึกนี้?',
            text: 'ลบแล้วกู้คืนไม่ได้', showCancelButton: true,
            confirmButtonText: 'ลบ', cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#dc2626',
        });
        if (!isConfirmed) return;
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', id);
        fetch(AJAX_URL, { method:'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.ok) {
                    Swal.fire({ icon:'success', title:d.message, timer:1100, showConfirmButton:false });
                    bptPage = 1;
                    loadAll();
                } else {
                    Swal.fire({ icon:'error', title: d.message || '' });
                }
            });
    };

    function loadAll() {
        loadSummary();
        loadList(false);
    }

    loadAll();
})();
</script>
</body>
</html>
