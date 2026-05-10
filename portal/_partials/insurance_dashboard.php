<?php
/**
 * portal/_partials/insurance_dashboard.php — Insurance Dashboard (Admin View + Edit)
 *
 * โหลดผ่าน portal/index.php?section=insurance_dashboard
 * AJAX: portal/ajax_dashboard_admin.php (entity:action pattern)
 *
 * Edit mode เข้าได้เฉพาะ admin ที่มี access_dashboard_admin / superadmin
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/dashboard_data_sources.php';

$pdo       = db();
$csrfToken = get_csrf_token();
$canEdit   = ($_SESSION['admin_role'] ?? '') === 'superadmin' || !empty($_SESSION['access_dashboard_admin']);

// โหลดทุก widget + resolve data server-side
$widgets = [];
try {
    $rows = $pdo->query("SELECT * FROM ins_dashboard_widgets ORDER BY sort_order ASC, id ASC")
                ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $w) {
        $w['data'] = dashboard_resolve_data($pdo, (string)$w['data_source']);
        $widgets[] = $w;
    }
} catch (PDOException $e) { /* tables may not exist */ }

$_scheme = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://');
$_basePath = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$publicUrl = $_scheme . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $_basePath . '/public/insurance_dashboard.php';
?>

<style>
    #section-insurance_dashboard .id-page { background:#f8fafc; }
    #section-insurance_dashboard .id-card {
        background:#fff; border:1.5px solid #e2e8f0; border-radius:1.5rem;
        padding:1.5rem; transition:all .15s; position:relative;
    }
    #section-insurance_dashboard .id-card:hover { box-shadow: 0 8px 25px rgba(0,0,0,.05); }
    #section-insurance_dashboard .id-card.id-edit-mode { border-style:dashed; border-color:#94a3b8; }
    #section-insurance_dashboard .id-card.id-edit-mode:hover { border-color:#0284c7; box-shadow: 0 0 0 4px rgba(2,132,199,.08); }
    #section-insurance_dashboard .id-edit-overlay {
        position:absolute; top:.75rem; right:.75rem; display:none;
        background:rgba(15,23,42,.9); backdrop-filter:blur(8px);
        border-radius:.75rem; padding:.4rem; gap:.25rem;
    }
    #section-insurance_dashboard .id-card.id-edit-mode .id-edit-overlay { display:flex; }
    #section-insurance_dashboard .id-edit-btn {
        width:30px; height:30px; border-radius:.5rem; border:none;
        background:transparent; color:#fff; cursor:pointer; font-size:12px;
        display:flex; align-items:center; justify-content:center;
        transition:all .15s;
    }
    #section-insurance_dashboard .id-edit-btn:hover { background:rgba(255,255,255,.15); }
    #section-insurance_dashboard .id-kpi-value {
        font-size: 2.5rem; font-weight: 900; line-height: 1; color:#0f172a; letter-spacing:-.02em;
    }
    #section-insurance_dashboard .id-kpi-label {
        font-size:.7rem; font-weight:900; color:#64748b;
        text-transform:uppercase; letter-spacing:.1em;
    }
    #section-insurance_dashboard .id-modal { z-index:200; }
    #section-insurance_dashboard .id-modal-box { max-height:90vh; }
    #section-insurance_dashboard .id-color-pill {
        width:32px; height:32px; border-radius:50%; cursor:pointer;
        border:3px solid transparent; transition:all .15s;
    }
    #section-insurance_dashboard .id-color-pill.id-color-selected { border-color:#0f172a; transform:scale(1.1); }
    #section-insurance_dashboard .id-private-badge {
        position:absolute; top:.75rem; left:.75rem;
        padding:.2rem .55rem; border-radius:99px;
        background:#f1f5f9; color:#64748b; font-size:.65rem; font-weight:900;
        letter-spacing:.05em;
    }
</style>

<div id="section-insurance_dashboard-content" class="px-5 md:px-8 py-8 space-y-7 id-page">

    <!-- ── Header ─────────────────────────────────────────────────────── -->
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-slate-800 flex items-center gap-3">
                <span class="text-3xl">📊</span> Insurance Dashboard
            </h1>
            <p class="text-sm text-slate-500 font-bold mt-1">ภาพรวมประกันอุบัติเหตุ + บัตรทอง · แก้ไขได้ตามต้องการ</p>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            <a href="<?= htmlspecialchars($publicUrl) ?>" target="_blank"
               class="h-11 px-4 bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 font-black rounded-xl text-xs flex items-center gap-2 transition-all">
                <i class="fa-solid fa-globe text-blue-500"></i> ดูหน้า Public
            </a>
            <?php if ($canEdit): ?>
            <button id="idToggleEditBtn" onclick="idToggleEditMode()"
                class="h-11 px-5 bg-blue-500 hover:bg-blue-600 text-white font-black rounded-xl text-xs shadow-lg shadow-blue-200 active:scale-95 transition-all flex items-center gap-2">
                <i class="fa-solid fa-pen-to-square"></i> โหมดแก้ไข
            </button>
            <button id="idAddWidgetBtn" onclick="idOpenWidgetModal(null)" class="hidden h-11 px-5 bg-emerald-500 hover:bg-emerald-600 text-white font-black rounded-xl text-xs shadow-lg shadow-emerald-200 active:scale-95 transition-all flex items-center gap-2">
                <i class="fa-solid fa-plus"></i> เพิ่ม Widget
            </button>
            <button id="idDatasetBtn" onclick="idOpenDatasetModal()" class="hidden h-11 px-4 bg-purple-50 hover:bg-purple-100 text-purple-700 border border-purple-200 font-black rounded-xl text-xs flex items-center gap-2 transition-all">
                <i class="fa-solid fa-file-csv"></i> CSV Datasets
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($widgets)): ?>
        <div class="id-card text-center py-16 text-slate-400 font-bold">
            <i class="fa-solid fa-chart-pie text-5xl mb-3 opacity-40"></i>
            <p class="text-base">ยังไม่มี widget ใน Dashboard</p>
            <?php if ($canEdit): ?>
                <p class="text-xs mt-1 text-slate-300">คลิก "โหมดแก้ไข" → "เพิ่ม Widget" เพื่อเริ่มสร้าง</p>
            <?php endif; ?>
        </div>
    <?php else: ?>

    <!-- ── Widgets Grid ───────────────────────────────────────────────── -->
    <div id="idWidgetsGrid" class="grid grid-cols-12 gap-5">
        <?php foreach ($widgets as $w):
            $sizeClass = match ($w['size']) {
                'sm' => 'col-span-12 md:col-span-6 xl:col-span-3',
                'md' => 'col-span-12 md:col-span-6 xl:col-span-4',
                'lg' => 'col-span-12 md:col-span-6 xl:col-span-6',
                'xl' => 'col-span-12',
                default => 'col-span-12 md:col-span-6 xl:col-span-4',
            };
            $isPrivate = (int)$w['is_public'] === 0;
            $isHidden  = (int)$w['is_visible'] === 0;
        ?>
        <div class="<?= $sizeClass ?> id-card" data-widget-id="<?= (int)$w['id'] ?>" data-widget-type="<?= htmlspecialchars($w['widget_type']) ?>" style="<?= $isHidden ? 'opacity:.5' : '' ?>">
            <?php if ($isPrivate): ?>
                <span class="id-private-badge"><i class="fa-solid fa-lock"></i> Internal</span>
            <?php endif; ?>
            <div class="id-edit-overlay">
                <button class="id-edit-btn" onclick="idOpenWidgetModal(<?= (int)$w['id'] ?>)" title="แก้ไข"><i class="fa-solid fa-pen"></i></button>
                <button class="id-edit-btn" onclick="idToggleVisible(<?= (int)$w['id'] ?>)" title="ซ่อน/แสดง"><i class="fa-solid fa-eye"></i></button>
                <button class="id-edit-btn" onclick="idTogglePublic(<?= (int)$w['id'] ?>)" title="Public/Internal"><i class="fa-solid fa-globe"></i></button>
                <button class="id-edit-btn" onclick="idDeleteWidget(<?= (int)$w['id'] ?>)" title="ลบ" style="color:#fca5a5"><i class="fa-solid fa-trash"></i></button>
            </div>

            <?php if ($w['widget_type'] === 'kpi'):
                $val = (int)($w['data']['value'] ?? 0);
                $autoVal = (int)($w['data']['auto'] ?? $val);
                $isOverridden = $autoVal !== $val;
                $kpiKey = $w['data_source'] ?? '';
                $kpiAllowed = require_once __DIR__ . '/../../includes/kpi_override_helper.php';
                $kpiCatalog = function_exists('kpi_override_catalog') ? kpi_override_catalog() : [];
                $isEditableKpi = isset($kpiCatalog[$kpiKey]);
                $colorMap = ['blue'=>'bg-blue-50 text-blue-500','emerald'=>'bg-emerald-50 text-emerald-500','amber'=>'bg-amber-50 text-amber-500','rose'=>'bg-rose-50 text-rose-500','purple'=>'bg-purple-50 text-purple-500','cyan'=>'bg-cyan-50 text-cyan-500','indigo'=>'bg-indigo-50 text-indigo-500','slate'=>'bg-slate-50 text-slate-500'];
                $iconBg = $colorMap[$w['color_theme']] ?? $colorMap['blue'];
            ?>
                <div class="flex items-start gap-4 <?= $isEditableKpi ? 'km-card' : '' ?>"
                     <?php if ($isEditableKpi): ?>
                        data-kpi-key="<?= htmlspecialchars($kpiKey) ?>"
                        data-kpi-label="<?= htmlspecialchars($w['title']) ?>"
                     <?php endif; ?>>
                    <div class="w-12 h-12 rounded-2xl <?= $iconBg ?> flex items-center justify-center text-xl shrink-0">
                        <i class="fa-solid fa-chart-simple"></i>
                    </div>
                    <div class="flex-1 min-w-0 km-body">
                        <p class="km-label id-kpi-label mb-1 truncate"><?= htmlspecialchars($w['title']) ?></p>
                        <p class="km-value id-kpi-value" data-value="<?= $val ?>"><?= number_format($val) ?></p>
                        <?php if (!empty($w['subtitle'])): ?>
                            <p class="text-xs text-slate-400 font-bold mt-2"><?= htmlspecialchars($w['subtitle']) ?></p>
                        <?php endif; ?>
                    </div>
                    <?php if ($isOverridden): ?><span class="km-override-badge">OVERRIDE</span><?php endif; ?>
                </div>
            <?php else: ?>
                <div class="mb-3">
                    <h3 class="text-sm font-black text-slate-800"><?= htmlspecialchars($w['title']) ?></h3>
                    <?php if (!empty($w['subtitle'])): ?>
                        <p class="text-xs text-slate-400 font-bold mt-0.5"><?= htmlspecialchars($w['subtitle']) ?></p>
                    <?php endif; ?>
                </div>
                <div style="height:240px"><canvas id="idChart_<?= (int)$w['id'] ?>"></canvas></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <?php endif; ?>
</div>

<!-- ════════════ Widget Modal ════════════ -->
<?php if ($canEdit): ?>
<div id="idWidgetModal" class="fixed inset-0 hidden items-center justify-center bg-black/50 backdrop-blur-sm id-modal">
    <div class="bg-white rounded-[2rem] w-full max-w-2xl mx-4 shadow-2xl flex flex-col id-modal-box">
        <div class="px-7 pt-6 pb-4 border-b border-slate-100 flex items-center justify-between shrink-0">
            <h3 id="idWidgetModalTitle" class="text-lg font-black text-slate-900">เพิ่ม Widget</h3>
            <button onclick="idCloseWidgetModal()" class="w-9 h-9 bg-slate-50 text-slate-400 hover:text-slate-600 rounded-full flex items-center justify-center">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="overflow-y-auto flex-1 px-7 py-5 space-y-5">
            <input type="hidden" id="idwIdField" value="0">

            <!-- Step 1: Widget Type -->
            <div>
                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">ชนิด Widget <span class="text-rose-500">*</span></label>
                <div class="grid grid-cols-3 md:grid-cols-6 gap-2">
                    <?php
                    $types = [
                        'kpi'   => ['fa-square-poll-vertical', 'KPI'],
                        'line'  => ['fa-chart-line', 'Line'],
                        'bar'   => ['fa-chart-bar', 'Bar'],
                        'donut' => ['fa-chart-pie', 'Donut'],
                        'pie'   => ['fa-chart-pie', 'Pie'],
                        'area'  => ['fa-chart-area', 'Area'],
                    ];
                    foreach ($types as $t => [$ic, $lb]): ?>
                        <button type="button" data-type="<?= $t ?>" onclick="idSelectType('<?= $t ?>')"
                            class="idw-type-btn p-3 border-2 border-slate-200 rounded-xl flex flex-col items-center gap-1.5 hover:border-blue-400 transition-all">
                            <i class="fa-solid <?= $ic ?> text-lg text-slate-500"></i>
                            <span class="text-[10px] font-black text-slate-600"><?= $lb ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Step 2: Title -->
            <div>
                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">ชื่อ Widget <span class="text-rose-500">*</span></label>
                <input type="text" id="idwTitle" placeholder="เช่น ผู้มีสิทธิ์ทั้งหมด"
                    class="w-full h-11 px-4 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-blue-500/10 outline-none bg-slate-50">
            </div>

            <div>
                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">คำอธิบายย่อย</label>
                <input type="text" id="idwSubtitle" placeholder="แสดงใต้ชื่อ widget (ไม่บังคับ)"
                    class="w-full h-11 px-4 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-blue-500/10 outline-none bg-slate-50">
            </div>

            <!-- Step 3: Data Source -->
            <div>
                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">แหล่งข้อมูล <span class="text-rose-500">*</span></label>
                <select id="idwDataSource"
                    class="w-full h-11 px-4 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-blue-500/10 outline-none bg-slate-50">
                    <option value="">— เลือกแหล่งข้อมูล —</option>
                </select>
                <p class="text-[10px] text-slate-400 font-bold mt-1.5">รายการจะกรองตามชนิด widget ที่เลือก</p>
            </div>

            <!-- Step 4: Color -->
            <div>
                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">สีธีม</label>
                <div class="flex items-center gap-2 flex-wrap">
                    <?php $colors = [
                        'blue'=>'#3b82f6','emerald'=>'#10b981','amber'=>'#f59e0b',
                        'rose'=>'#f43f5e','purple'=>'#a855f7','cyan'=>'#06b6d4',
                        'indigo'=>'#6366f1','slate'=>'#64748b'
                    ]; foreach ($colors as $name => $hex): ?>
                        <div class="id-color-pill" data-color="<?= $name ?>" onclick="idSelectColor('<?= $name ?>')"
                             style="background:<?= $hex ?>" title="<?= $name ?>"></div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Step 5: Size & Visibility -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">ขนาด</label>
                    <select id="idwSize" class="w-full h-11 px-4 border border-slate-200 rounded-xl text-sm font-bold outline-none bg-slate-50">
                        <option value="sm">เล็ก (1/4)</option>
                        <option value="md" selected>กลาง (1/3)</option>
                        <option value="lg">ใหญ่ (1/2)</option>
                        <option value="xl">เต็มแถว</option>
                    </select>
                </div>
                <div class="space-y-2">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="idwIsVisible" checked class="w-4 h-4 accent-blue-600">
                        <span class="text-xs font-black text-slate-600">แสดง widget นี้</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="idwIsPublic" checked class="w-4 h-4 accent-blue-600">
                        <span class="text-xs font-black text-slate-600">แสดงในหน้า Public</span>
                    </label>
                </div>
            </div>

            <div id="idwError" class="hidden text-xs font-bold text-rose-600 bg-rose-50 border border-rose-100 rounded-xl px-4 py-3"></div>
        </div>

        <div class="px-7 py-4 bg-slate-50 border-t border-slate-100 flex gap-3 shrink-0">
            <button onclick="idCloseWidgetModal()" class="flex-1 h-11 bg-white border border-slate-200 text-slate-600 font-black rounded-xl text-sm hover:bg-slate-100">ยกเลิก</button>
            <button onclick="idSaveWidget()" class="h-11 px-6 bg-blue-500 hover:bg-blue-600 text-white font-black rounded-xl text-sm shadow-lg shadow-blue-200 flex items-center gap-2" style="flex:2">
                <i class="fa-solid fa-floppy-disk"></i> บันทึก
            </button>
        </div>
    </div>
</div>

<!-- ════════════ Dataset Modal ════════════ -->
<div id="idDatasetModal" class="fixed inset-0 hidden items-center justify-center bg-black/50 backdrop-blur-sm id-modal">
    <div class="bg-white rounded-[2rem] w-full max-w-3xl mx-4 shadow-2xl flex flex-col id-modal-box">
        <div class="px-7 pt-6 pb-4 border-b border-slate-100 flex items-center justify-between shrink-0">
            <h3 class="text-lg font-black text-slate-900"><i class="fa-solid fa-file-csv text-purple-500 mr-2"></i> Custom Datasets (CSV)</h3>
            <button onclick="idCloseDatasetModal()" class="w-9 h-9 bg-slate-50 text-slate-400 hover:text-slate-600 rounded-full flex items-center justify-center">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="overflow-y-auto flex-1 px-7 py-5 space-y-5">
            <!-- Upload form -->
            <div class="bg-purple-50 border border-purple-200 rounded-2xl p-5">
                <h4 class="text-sm font-black text-purple-900 mb-3"><i class="fa-solid fa-cloud-arrow-up mr-1"></i> อัปโหลด CSV ใหม่</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div class="md:col-span-2">
                        <label class="block text-[10px] font-black text-purple-700 uppercase tracking-widest mb-1.5">ชื่อ Dataset <span class="text-rose-500">*</span></label>
                        <input type="text" id="idsName" placeholder="เช่น แผนกผู้ป่วย Q1"
                            class="w-full h-10 px-3 border border-purple-200 rounded-lg text-sm font-bold outline-none bg-white">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-purple-700 uppercase tracking-widest mb-1.5">คอลัมน์ป้าย (label)</label>
                        <input type="text" id="idsLabelCol" value="label"
                            class="w-full h-10 px-3 border border-purple-200 rounded-lg text-sm font-bold outline-none bg-white">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-purple-700 uppercase tracking-widest mb-1.5">คอลัมน์ค่า (value)</label>
                        <input type="text" id="idsValueCol" value="value"
                            class="w-full h-10 px-3 border border-purple-200 rounded-lg text-sm font-bold outline-none bg-white">
                    </div>
                    <div class="md:col-span-2">
                        <input type="file" id="idsFile" accept=".csv"
                            class="w-full text-xs font-bold file:mr-3 file:px-4 file:py-2 file:rounded-lg file:border-0 file:bg-purple-600 file:text-white file:font-black file:cursor-pointer">
                    </div>
                </div>
                <button onclick="idUploadDataset()" class="mt-3 h-10 px-5 bg-purple-600 hover:bg-purple-700 text-white font-black rounded-lg text-xs flex items-center gap-2">
                    <i class="fa-solid fa-upload"></i> อัปโหลด
                </button>
                <p class="text-[10px] font-bold text-purple-700 mt-2"><i class="fa-solid fa-circle-info"></i> CSV ต้องมีบรรทัดแรกเป็น header · UTF-8 (ถ้า Excel ให้ save as "CSV UTF-8")</p>
            </div>

            <!-- Existing datasets list -->
            <div>
                <h4 class="text-sm font-black text-slate-700 mb-3">Dataset ที่มีอยู่</h4>
                <div id="idsList" class="space-y-2">
                    <div class="text-center text-slate-400 font-bold text-sm py-4">กำลังโหลด...</div>
                </div>
            </div>
        </div>
        <div class="px-7 py-4 bg-slate-50 border-t border-slate-100 flex justify-end shrink-0">
            <button onclick="idCloseDatasetModal()" class="h-11 px-5 bg-white border border-slate-200 text-slate-600 font-black rounded-xl text-sm hover:bg-slate-100">ปิด</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function(){
    const CSRF = '<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>';
    const ENDPOINT = 'ajax_dashboard_admin.php';
    const CAN_EDIT = <?= $canEdit ? 'true' : 'false' ?>;

    let widgetData = <?= json_encode(array_map(fn($w) => [
        'id'=>(int)$w['id'], 'type'=>$w['widget_type'], 'data_source'=>$w['data_source'],
        'color'=>$w['color_theme'], 'data'=>$w['data']
    ], $widgets), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    let catalog = null;

    const COLOR_HEX = {
        blue:'#3b82f6', emerald:'#10b981', amber:'#f59e0b', rose:'#f43f5e',
        purple:'#a855f7', cyan:'#06b6d4', indigo:'#6366f1', slate:'#64748b'
    };

    function adPost(entity, action, extra = {}, isFormData = false) {
        let body;
        if (isFormData) {
            body = extra; body.append('entity', entity); body.append('action', action); body.append('csrf_token', CSRF);
        } else {
            body = new FormData();
            body.append('entity', entity); body.append('action', action); body.append('csrf_token', CSRF);
            for (const [k, v] of Object.entries(extra)) body.append(k, v);
        }
        return fetch(ENDPOINT, { method: 'POST', body, credentials: 'same-origin' }).then(r => r.json());
    }

    // ── Render charts ────────────────────────────────────────────────
    function renderAllCharts() {
        if (typeof Chart === 'undefined') { setTimeout(renderAllCharts, 200); return; }
        widgetData.forEach(w => {
            if (w.type === 'kpi') return;
            const el = document.getElementById('idChart_' + w.id);
            if (!el) return;
            renderChart(el, w);
        });
    }

    function renderChart(canvas, w) {
        const c = COLOR_HEX[w.color] || COLOR_HEX.blue;
        const cAlpha = c + '20';
        const data = w.data || {};

        if (['line', 'area'].includes(w.type) && data.shape === 'timeseries') {
            new Chart(canvas, {
                type: 'line',
                data: {
                    labels: data.labels || [],
                    datasets: (data.series || []).map((s, i) => ({
                        label: s.name, data: s.data,
                        borderColor: i === 0 ? c : '#94a3b8',
                        backgroundColor: w.type === 'area' ? (i === 0 ? cAlpha : 'rgba(148,163,184,.15)') : 'transparent',
                        tension: 0.3, fill: w.type === 'area', borderWidth: 2.5, pointRadius: 3,
                    }))
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { font: { weight: 700 }}}}}
            });
        } else if (w.type === 'bar' && data.shape === 'breakdown') {
            new Chart(canvas, {
                type: 'bar',
                data: { labels: data.labels || [], datasets: [{ data: data.values || [], backgroundColor: c, borderRadius: 6 }] },
                options: { indexAxis: (data.labels || []).length > 5 ? 'y' : 'x', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }}}
            });
        } else if (w.type === 'bar' && data.shape === 'timeseries') {
            new Chart(canvas, {
                type: 'bar',
                data: { labels: data.labels || [], datasets: (data.series || []).map((s, i) => ({ label: s.name, data: s.data, backgroundColor: i === 0 ? c : '#94a3b8', borderRadius: 4 })) },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { font: { weight: 700 }}}}}
            });
        } else if (['donut', 'pie'].includes(w.type) && data.shape === 'breakdown') {
            const palette = [c, '#f59e0b', '#10b981', '#a855f7', '#ef4444', '#06b6d4', '#6366f1', '#94a3b8'];
            new Chart(canvas, {
                type: w.type === 'donut' ? 'doughnut' : 'pie',
                data: { labels: data.labels || [], datasets: [{ data: data.values || [], backgroundColor: palette, borderWidth: 2, borderColor: '#fff' }] },
                options: { responsive: true, maintainAspectRatio: false, cutout: w.type === 'donut' ? '60%' : 0, plugins: { legend: { position: 'bottom', labels: { font: { weight: 700 }, boxWidth: 12 }}}}
            });
        } else {
            const ctx = canvas.getContext('2d');
            ctx.font = '14px sans-serif'; ctx.fillStyle = '#94a3b8'; ctx.textAlign = 'center';
            ctx.fillText('ไม่รองรับชนิดข้อมูลนี้', canvas.width / 2, canvas.height / 2);
        }
    }

    // ── Edit mode toggle ─────────────────────────────────────────────
    let editMode = false;
    window.idToggleEditMode = function() {
        editMode = !editMode;
        document.querySelectorAll('#idWidgetsGrid .id-card').forEach(c => c.classList.toggle('id-edit-mode', editMode));
        const btn = document.getElementById('idToggleEditBtn');
        const addBtn = document.getElementById('idAddWidgetBtn');
        const dsBtn = document.getElementById('idDatasetBtn');
        if (editMode) {
            btn.innerHTML = '<i class="fa-solid fa-check"></i> เสร็จสิ้น';
            btn.classList.replace('bg-blue-500', 'bg-emerald-500');
            btn.classList.replace('hover:bg-blue-600', 'hover:bg-emerald-600');
            btn.classList.replace('shadow-blue-200', 'shadow-emerald-200');
            addBtn.classList.remove('hidden'); dsBtn.classList.remove('hidden');
        } else {
            btn.innerHTML = '<i class="fa-solid fa-pen-to-square"></i> โหมดแก้ไข';
            btn.classList.replace('bg-emerald-500', 'bg-blue-500');
            btn.classList.replace('hover:bg-emerald-600', 'hover:bg-blue-600');
            btn.classList.replace('shadow-emerald-200', 'shadow-blue-200');
            addBtn.classList.add('hidden'); dsBtn.classList.add('hidden');
        }
    };

    // ── Widget Modal ─────────────────────────────────────────────────
    let selectedType = 'kpi';
    let selectedColor = 'blue';

    function loadCatalog() {
        return adPost('catalog', 'get').then(r => {
            if (r.status === 'ok') catalog = r;
            return catalog;
        });
    }

    window.idOpenWidgetModal = function(id) {
        const modal = document.getElementById('idWidgetModal');
        document.getElementById('idwIdField').value = id || 0;
        document.getElementById('idwError').classList.add('hidden');

        loadCatalog().then(() => {
            if (id) {
                document.getElementById('idWidgetModalTitle').textContent = 'แก้ไข Widget';
                adPost('widget', 'get', { id }).then(r => {
                    if (r.status !== 'ok') { Swal.fire({icon:'error',title:'ผิดพลาด',text:r.message}); return; }
                    const w = r.widget;
                    selectedType = w.widget_type; selectedColor = w.color_theme;
                    document.getElementById('idwTitle').value = w.title || '';
                    document.getElementById('idwSubtitle').value = w.subtitle || '';
                    document.getElementById('idwSize').value = w.size || 'md';
                    document.getElementById('idwIsVisible').checked = parseInt(w.is_visible) === 1;
                    document.getElementById('idwIsPublic').checked  = parseInt(w.is_public) === 1;
                    syncTypeUI(); syncColorUI();
                    populateDataSources(w.data_source);
                });
            } else {
                document.getElementById('idWidgetModalTitle').textContent = 'เพิ่ม Widget';
                selectedType = 'kpi'; selectedColor = 'blue';
                document.getElementById('idwTitle').value = '';
                document.getElementById('idwSubtitle').value = '';
                document.getElementById('idwSize').value = 'md';
                document.getElementById('idwIsVisible').checked = true;
                document.getElementById('idwIsPublic').checked = true;
                syncTypeUI(); syncColorUI();
                populateDataSources('');
            }
            modal.classList.remove('hidden'); modal.classList.add('flex');
        });
    };

    window.idCloseWidgetModal = function() {
        const modal = document.getElementById('idWidgetModal');
        modal.classList.add('hidden'); modal.classList.remove('flex');
    };

    window.idSelectType = function(t) { selectedType = t; syncTypeUI(); populateDataSources(''); };
    window.idSelectColor = function(c) { selectedColor = c; syncColorUI(); };

    function syncTypeUI() {
        document.querySelectorAll('.idw-type-btn').forEach(b => {
            const sel = b.dataset.type === selectedType;
            b.classList.toggle('border-blue-500', sel);
            b.classList.toggle('bg-blue-50', sel);
            b.classList.toggle('border-slate-200', !sel);
        });
    }

    function syncColorUI() {
        document.querySelectorAll('.id-color-pill').forEach(p => p.classList.toggle('id-color-selected', p.dataset.color === selectedColor));
    }

    function populateDataSources(currentValue) {
        const sel = document.getElementById('idwDataSource');
        sel.innerHTML = '<option value="">— เลือกแหล่งข้อมูล —</option>';
        if (!catalog) return;
        catalog.sources.forEach(s => {
            if (s.widgets.includes(selectedType)) {
                const opt = document.createElement('option');
                opt.value = s.key; opt.textContent = s.label;
                if (s.key === currentValue) opt.selected = true;
                sel.appendChild(opt);
            }
        });
    }

    window.idSaveWidget = function() {
        const id = document.getElementById('idwIdField').value;
        const data = {
            id, widget_type: selectedType,
            title: document.getElementById('idwTitle').value.trim(),
            subtitle: document.getElementById('idwSubtitle').value.trim(),
            data_source: document.getElementById('idwDataSource').value,
            color_theme: selectedColor,
            size: document.getElementById('idwSize').value,
            is_visible: document.getElementById('idwIsVisible').checked ? 1 : 0,
            is_public:  document.getElementById('idwIsPublic').checked ? 1 : 0,
        };
        adPost('widget', 'save', data).then(r => {
            const err = document.getElementById('idwError');
            if (r.status === 'ok') {
                err.classList.add('hidden');
                Swal.fire({ icon:'success', title:'บันทึกแล้ว', timer:1200, showConfirmButton:false }).then(() => location.reload());
            } else { err.textContent = r.message || 'บันทึกไม่สำเร็จ'; err.classList.remove('hidden'); }
        });
    };

    window.idDeleteWidget = async function(id) {
        const { isConfirmed } = await Swal.fire({
            icon: 'warning', title: 'ลบ widget นี้?', showCancelButton: true,
            confirmButtonText: 'ลบ', cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#dc2626', reverseButtons: true
        });
        if (!isConfirmed) return;
        adPost('widget', 'delete', { id }).then(r => {
            if (r.status === 'ok') location.reload();
            else Swal.fire({ icon: 'error', title: 'ผิดพลาด', text: r.message });
        });
    };

    window.idToggleVisible = function(id) {
        adPost('widget', 'toggle', { id, field: 'is_visible' }).then(r => { if (r.status === 'ok') location.reload(); });
    };

    window.idTogglePublic = function(id) {
        adPost('widget', 'toggle', { id, field: 'is_public' }).then(r => { if (r.status === 'ok') location.reload(); });
    };

    // ── Dataset Modal ────────────────────────────────────────────────
    window.idOpenDatasetModal = function() {
        const modal = document.getElementById('idDatasetModal');
        modal.classList.remove('hidden'); modal.classList.add('flex');
        loadDatasets();
    };
    window.idCloseDatasetModal = function() {
        const modal = document.getElementById('idDatasetModal');
        modal.classList.add('hidden'); modal.classList.remove('flex');
    };

    function loadDatasets() {
        adPost('dataset', 'list').then(r => {
            const list = document.getElementById('idsList');
            if (r.status !== 'ok') { list.innerHTML = `<div class="text-rose-500 text-sm font-bold">${r.message}</div>`; return; }
            if (!r.datasets.length) {
                list.innerHTML = `<div class="text-center text-slate-400 font-bold text-sm py-4">ยังไม่มี dataset</div>`;
                return;
            }
            list.innerHTML = r.datasets.map(d => `
                <div class="flex items-center justify-between bg-slate-50 border border-slate-200 rounded-xl px-4 py-3">
                    <div class="flex-1 min-w-0">
                        <p class="font-black text-slate-800 text-sm">${escapeHtml(d.dataset_name)}</p>
                        <p class="text-xs text-slate-500 font-bold mt-0.5">key: <code class="text-purple-600">custom_${escapeHtml(d.dataset_key)}</code> · ${d.row_count} แถว · ${d.uploaded_at}</p>
                    </div>
                    <button onclick="idDeleteDataset(${d.id})" class="px-3 h-9 bg-rose-50 text-rose-600 hover:bg-rose-100 rounded-lg text-xs font-black"><i class="fa-solid fa-trash"></i></button>
                </div>
            `).join('');
        });
    }

    window.idUploadDataset = function() {
        const file = document.getElementById('idsFile').files[0];
        if (!file) { Swal.fire({icon:'info',title:'กรุณาเลือกไฟล์ CSV'}); return; }
        const fd = new FormData();
        fd.append('dataset_name', document.getElementById('idsName').value.trim());
        fd.append('label_column', document.getElementById('idsLabelCol').value.trim());
        fd.append('value_column', document.getElementById('idsValueCol').value.trim());
        fd.append('file', file);
        adPost('dataset', 'upload', fd, true).then(r => {
            if (r.status === 'ok') {
                Swal.fire({icon:'success',title:'อัปโหลดแล้ว',text:r.message,timer:1500,showConfirmButton:false});
                document.getElementById('idsName').value = '';
                document.getElementById('idsFile').value = '';
                loadDatasets();
            } else Swal.fire({icon:'error',title:'ผิดพลาด',text:r.message});
        });
    };

    window.idDeleteDataset = async function(id) {
        const { isConfirmed } = await Swal.fire({
            icon: 'warning', title: 'ลบ dataset นี้?', showCancelButton: true,
            confirmButtonText: 'ลบ', cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#dc2626', reverseButtons: true
        });
        if (!isConfirmed) return;
        adPost('dataset', 'delete', { id }).then(r => {
            if (r.status === 'ok') loadDatasets();
            else Swal.fire({icon:'error',title:'ผิดพลาด',text:r.message});
        });
    };

    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    // ── init ────────────────────────────────────────────────────────
    renderAllCharts();
})();
</script>

<!-- ⚡ Cinematic KPI Morph (overdrive) ─────────────────────────── -->
<script src="../assets/js/kpi-morph.js"></script>
<script>
    (function bootKPIMorph(){
        const init = () => {
            if (!window.KPIMorph) { return setTimeout(init, 50); }
            window.KPIMorph.init({
                csrf: '<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>',
                endpoint: 'ajax_kpi_override.php',
                editable: <?= $canEdit ? 'true' : 'false' ?>,
            });
        };
        init();
    })();
</script>
