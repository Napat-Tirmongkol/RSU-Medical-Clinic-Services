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
require_once __DIR__ . '/../../includes/kpi_override_helper.php';

$pdo       = db();
$csrfToken = get_csrf_token();
$canEdit   = ($_SESSION['admin_role'] ?? '') === 'superadmin' || !empty($_SESSION['access_dashboard_admin']);
$kpiCatalogGlobal = function_exists('kpi_override_catalog') ? kpi_override_catalog() : [];

// โหลด workbooks + active workbook
$workbooks = [];
$activeWorkbook = null;
try {
    $workbooks = $pdo->query("
        SELECT w.*, (SELECT COUNT(*) FROM ins_dashboard_widgets WHERE workbook_id = w.id) AS widget_count
        FROM ins_dashboard_workbooks w
        ORDER BY w.sort_order ASC, w.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // หา active workbook จาก ?wb=slug หรือ default
    $wbReq = isset($_GET['wb']) ? trim((string)$_GET['wb']) : '';
    if ($wbReq !== '') {
        foreach ($workbooks as $wb) if ($wb['slug'] === $wbReq) { $activeWorkbook = $wb; break; }
    }
    if (!$activeWorkbook) {
        foreach ($workbooks as $wb) if ((int)$wb['is_default'] === 1) { $activeWorkbook = $wb; break; }
    }
    if (!$activeWorkbook && !empty($workbooks)) $activeWorkbook = $workbooks[0];
} catch (PDOException $e) { /* tables not migrated */ }

// โหลด widget ของ active workbook
$widgets = [];
if ($activeWorkbook) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM ins_dashboard_widgets WHERE workbook_id = ? ORDER BY sort_order ASC, id ASC");
        $stmt->execute([(int)$activeWorkbook['id']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $w) {
            $w['data'] = dashboard_resolve_data($pdo, (string)$w['data_source']);
            $widgets[] = $w;
        }
    } catch (PDOException $e) { /* tables may not exist */ }
}

$_scheme = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://');
$_basePath = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$publicUrlBase = $_scheme . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $_basePath . '/public/insurance_dashboard.php';
$publicUrl = $publicUrlBase . ($activeWorkbook ? ('?wb=' . urlencode($activeWorkbook['slug'])) : '');
?>

<style>
    #section-insurance_dashboard .id-page { background:#f8fafc; }
    #section-insurance_dashboard .id-card {
        background:#fff; border:1.5px solid #e2e8f0; border-radius:1.5rem;
        padding:1.5rem; transition:all .15s; position:relative;
    }
    #section-insurance_dashboard .id-card:hover { box-shadow: 0 8px 25px rgba(0,0,0,.05); }
    #section-insurance_dashboard .id-card.id-edit-mode { border-style:dashed; border-color:#94a3b8; cursor:grab; }
    #section-insurance_dashboard .id-card.id-edit-mode:hover { border-color:#0284c7; box-shadow: 0 0 0 4px rgba(2,132,199,.08); }
    #section-insurance_dashboard .id-card.id-edit-mode:active { cursor:grabbing; }
    /* Drag-and-drop visual states (edit mode only) */
    #section-insurance_dashboard .id-card.id-dragging {
        opacity:.4; transform:scale(.97);
        box-shadow: 0 12px 30px rgba(2,132,199,.25) !important;
    }
    #section-insurance_dashboard .id-card.id-drop-before::before,
    #section-insurance_dashboard .id-card.id-drop-after::after {
        content:''; position:absolute; top:8px; bottom:8px; width:4px;
        background: linear-gradient(180deg, #0284c7, #38bdf8);
        border-radius:99px; box-shadow:0 0 12px rgba(2,132,199,.55);
        z-index:5; pointer-events:none;
    }
    #section-insurance_dashboard .id-card.id-drop-before::before { left:-9px; }
    #section-insurance_dashboard .id-card.id-drop-after::after { right:-9px; }
    /* Subtle "grab" pill in edit overlay */
    #section-insurance_dashboard .id-edit-mode .id-grab-hint {
        position:absolute; top:.75rem; left:.75rem;
        background:rgba(15,23,42,.85); color:#fff;
        font-size:10px; font-weight:800; padding:3px 9px; border-radius:99px;
        display:inline-flex; align-items:center; gap:5px;
        backdrop-filter:blur(6px); pointer-events:none;
        animation: idGrabPulse 2s ease-in-out infinite;
        z-index: 6; /* above km-override-badge if it lingers */
    }
    @keyframes idGrabPulse {
        0%,100% { opacity:.7; }
        50% { opacity:1; }
    }
    @media (prefers-reduced-motion: reduce) {
        #section-insurance_dashboard .id-edit-mode .id-grab-hint { animation:none; opacity:.85; }
        #section-insurance_dashboard .id-card.id-dragging { transform:none; }
    }
    /* Hide KPI override widgets (km-*) while in dashboard edit mode —
       prevents overlap with .id-edit-overlay (top-right) and id-grab-hint (top-left).
       In edit mode admin is managing layout/visibility, not values, so the
       inline override pencil + OVERRIDE badge would just clutter. */
    #section-insurance_dashboard .id-card.id-edit-mode .km-edit-btn,
    #section-insurance_dashboard .id-card.id-edit-mode .km-override-badge {
        display: none !important;
    }
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

    /* Workbook tabs */
    #section-insurance_dashboard .wb-tab {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 8px 14px; border-radius: 11px;
        font-size: 12.5px; font-weight: 800; color: #64748b;
        text-decoration: none; white-space: nowrap;
        transition: all .15s; border: 1.5px solid transparent;
        flex-shrink: 0;
    }
    #section-insurance_dashboard .wb-tab:hover { background: #f1f5f9; color: #0f172a; }
    #section-insurance_dashboard .wb-tab.wb-tab-active {
        background: linear-gradient(135deg, var(--wb-color, #3b82f6), color-mix(in srgb, var(--wb-color, #3b82f6) 75%, white));
        color: #fff;
        box-shadow: 0 6px 14px -4px color-mix(in srgb, var(--wb-color, #3b82f6) 40%, transparent);
    }
    #section-insurance_dashboard .wb-tab-count {
        background: rgba(255,255,255,.25); padding: 1px 7px; border-radius: 99px;
        font-size: 10px; font-weight: 900; letter-spacing: .04em;
        color: inherit;
    }
    #section-insurance_dashboard .wb-tab:not(.wb-tab-active) .wb-tab-count {
        background: #f1f5f9; color: #94a3b8;
    }
    #section-insurance_dashboard .wb-tab-add {
        height: 36px; padding: 0 14px;
        border-radius: 11px; border: 1.5px dashed #cbd5e1;
        background: #fff; color: #64748b;
        font-size: 12px; font-weight: 800; cursor: pointer;
        display: inline-flex; align-items: center; gap: 6px;
        transition: all .15s; flex-shrink: 0;
    }
    #section-insurance_dashboard .wb-tab-add:hover {
        border-color: #3b82f6; color: #1d4ed8; background: #eff6ff;
    }

    /* Workbook icon picker / color picker */
    #section-insurance_dashboard .wb-pill {
        width: 38px; height: 38px; border-radius: 11px;
        border: 2px solid transparent; cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        transition: all .15s;
    }
    #section-insurance_dashboard .wb-pill:hover { transform: scale(1.05); }
    #section-insurance_dashboard .wb-pill.wb-selected {
        border-color: #0f172a; transform: scale(1.08);
    }

    /* ── Bold & Colorful: tilt-aware lift + icon micro-interaction ── */
    #section-insurance_dashboard .id-card { isolation: isolate; }
    #section-insurance_dashboard .id-card:hover:not(.fx-tilt) { transform: translateY(-3px); box-shadow: 0 22px 42px -20px rgba(15,23,42,.20); }
    #section-insurance_dashboard .id-card.fx-tilt:hover { --lift: -3px; box-shadow: 0 22px 42px -20px rgba(15,23,42,.20); }
    #section-insurance_dashboard .id-card .w-12.h-12.rounded-2xl { transition: transform .25s cubic-bezier(.16,1,.3,1); }
    #section-insurance_dashboard .id-card:hover .w-12.h-12.rounded-2xl { transform: scale(1.08) rotate(-4deg); }

    /* ── DARK MODE ─────────────────────────────────────────────────── */
    body[data-theme='dark'] #section-insurance_dashboard-content { background: transparent; }
    body[data-theme='dark'] #section-insurance_dashboard .id-page { background: transparent; }
    body[data-theme='dark'] #section-insurance_dashboard .id-card {
        background: #0f172a; border-color: #1e293b;
        box-shadow: 0 1px 0 rgba(255,255,255,.04), 0 8px 22px rgba(0,0,0,.35);
    }
    body[data-theme='dark'] #section-insurance_dashboard .id-card:hover { border-color: #334155; }
    body[data-theme='dark'] #section-insurance_dashboard .id-card.id-edit-mode { border-color:#475569; }
    body[data-theme='dark'] #section-insurance_dashboard .id-card.id-edit-mode:hover { border-color:#38bdf8; box-shadow: 0 0 0 4px rgba(56,189,248,.12); }
    body[data-theme='dark'] #section-insurance_dashboard .id-kpi-value { color: #f1f5f9; }
    body[data-theme='dark'] #section-insurance_dashboard .id-kpi-label { color: #94a3b8; }
    body[data-theme='dark'] #section-insurance_dashboard .id-private-badge { background:#1e293b; color:#94a3b8; }

    body[data-theme='dark'] #section-insurance_dashboard .wb-tab { color:#94a3b8; }
    body[data-theme='dark'] #section-insurance_dashboard .wb-tab:hover { background:#1e293b; color:#f1f5f9; }
    body[data-theme='dark'] #section-insurance_dashboard .wb-tab:not(.wb-tab-active) .wb-tab-count { background:#1e293b; color:#64748b; }
    body[data-theme='dark'] #section-insurance_dashboard .wb-tab-add { border-color:#334155; background:transparent; color:#94a3b8; }
    body[data-theme='dark'] #section-insurance_dashboard .wb-tab-add:hover { border-color:#3b82f6; background:rgba(59,130,246,.10); color:#93c5fd; }
    body[data-theme='dark'] #section-insurance_dashboard .wb-pill { background:#1e293b; color:#cbd5e1; }
    body[data-theme='dark'] #section-insurance_dashboard .wb-pill.wb-selected { border-color:#f1f5f9; }

    /* workbook tab strip surface + header buttons */
    body[data-theme='dark'] #section-insurance_dashboard .bg-white { background: #0f172a !important; }
    body[data-theme='dark'] #section-insurance_dashboard .bg-white.border-slate-200 { border-color: #1e293b !important; }
    body[data-theme='dark'] #section-insurance_dashboard .bg-white.hover\:bg-slate-50:hover { background: #1e293b !important; }
    body[data-theme='dark'] #section-insurance_dashboard h1.text-slate-800,
    body[data-theme='dark'] #section-insurance_dashboard .text-slate-800 { color:#f1f5f9 !important; }
    body[data-theme='dark'] #section-insurance_dashboard .text-slate-700 { color:#e2e8f0 !important; }
    body[data-theme='dark'] #section-insurance_dashboard .text-slate-600 { color:#cbd5e1 !important; }
    body[data-theme='dark'] #section-insurance_dashboard .text-slate-500 { color:#94a3b8 !important; }
    body[data-theme='dark'] #section-insurance_dashboard .text-slate-400 { color:#64748b !important; }
    body[data-theme='dark'] #section-insurance_dashboard .text-slate-300 { color:#475569 !important; }

    /* tone backgrounds inside KPI icon — keep contrast */
    body[data-theme='dark'] #section-insurance_dashboard .bg-blue-50    { background: rgba(59,130,246,.18) !important; }
    body[data-theme='dark'] #section-insurance_dashboard .bg-emerald-50 { background: rgba(16,185,129,.18) !important; }
    body[data-theme='dark'] #section-insurance_dashboard .bg-amber-50   { background: rgba(245,158,11,.18) !important; }
    body[data-theme='dark'] #section-insurance_dashboard .bg-rose-50    { background: rgba(244,63,94,.18) !important; }
    body[data-theme='dark'] #section-insurance_dashboard .bg-purple-50  { background: rgba(168,85,247,.18) !important; }
    body[data-theme='dark'] #section-insurance_dashboard .bg-cyan-50    { background: rgba(6,182,212,.18) !important; }
    body[data-theme='dark'] #section-insurance_dashboard .bg-indigo-50  { background: rgba(99,102,241,.18) !important; }
    body[data-theme='dark'] #section-insurance_dashboard .bg-slate-50   { background: rgba(148,163,184,.10) !important; }
    body[data-theme='dark'] #section-insurance_dashboard .bg-slate-100  { background: rgba(148,163,184,.14) !important; }
    body[data-theme='dark'] #section-insurance_dashboard .border-slate-200 { border-color:#1e293b !important; }
    body[data-theme='dark'] #section-insurance_dashboard .border-slate-100 { border-color:#1e293b !important; }

    @media (prefers-reduced-motion: reduce) {
        #section-insurance_dashboard .id-card,
        #section-insurance_dashboard .id-card .w-12.h-12.rounded-2xl { transition: none !important; transform: none !important; }
    }
</style>

<div id="section-insurance_dashboard-content" class="px-5 md:px-8 py-8 space-y-7 id-page">

    <!-- ── Header ─────────────────────────────────────────────────────── -->
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-slate-800 flex items-center gap-3">
                <span class="text-3xl">📊</span> Dashboard Workbook
                <?php if ($activeWorkbook): ?>
                    <span class="text-sm font-bold text-slate-400">/ <?= htmlspecialchars($activeWorkbook['name']) ?></span>
                <?php endif; ?>
            </h1>
            <p class="text-sm text-slate-500 font-bold mt-1">
                <?= $activeWorkbook && !empty($activeWorkbook['description'])
                    ? htmlspecialchars($activeWorkbook['description'])
                    : 'ภาพรวมประกันอุบัติเหตุ + บัตรทอง · แก้ไขได้ตามต้องการ' ?>
            </p>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            <?php if ($canEdit && $activeWorkbook): ?>
            <button onclick="idOpenWorkbookModal(<?= (int)$activeWorkbook['id'] ?>)"
               class="h-11 px-4 bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 font-black rounded-xl text-xs flex items-center gap-2 transition-all">
                <i class="fa-solid fa-cog text-purple-500"></i> ตั้งค่า workbook
            </button>
            <?php endif; ?>
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

    <!-- ── Workbook Tabs ──────────────────────────────────────────── -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm px-3 py-2 flex items-center gap-2 overflow-x-auto">
        <?php foreach ($workbooks as $wb):
            $isActive = $activeWorkbook && (int)$activeWorkbook['id'] === (int)$wb['id'];
        ?>
            <a href="?section=insurance_dashboard&wb=<?= urlencode($wb['slug']) ?>"
               class="wb-tab <?= $isActive ? 'wb-tab-active' : '' ?>"
               style="<?= $isActive ? '--wb-color:#'.([
                   'blue'=>'3b82f6','emerald'=>'10b981','amber'=>'f59e0b','rose'=>'f43f5e',
                   'purple'=>'a855f7','cyan'=>'06b6d4','indigo'=>'6366f1','slate'=>'64748b'
               ][$wb['color']] ?? '3b82f6').';' : '' ?>">
                <i class="fa-solid <?= htmlspecialchars($wb['icon'] ?: 'fa-chart-pie') ?>"></i>
                <span><?= htmlspecialchars($wb['name']) ?></span>
                <?php if ((int)$wb['is_public'] === 1): ?>
                    <i class="fa-solid fa-globe text-[9px] opacity-60" title="Public"></i>
                <?php endif; ?>
                <span class="wb-tab-count"><?= (int)$wb['widget_count'] ?></span>
            </a>
        <?php endforeach; ?>
        <?php if ($canEdit): ?>
            <button onclick="idOpenWorkbookModal(null)" class="wb-tab-add ml-auto" title="สร้าง Workbook ใหม่">
                <i class="fa-solid fa-plus"></i> สร้าง Workbook
            </button>
        <?php endif; ?>
    </div>

    <?php if (empty($widgets)): ?>
        <div class="id-card text-center py-16 text-slate-400 font-bold">
            <i class="fa-solid fa-chart-pie text-5xl mb-3 opacity-40"></i>
            <p class="text-base">
                <?= $activeWorkbook ? 'Workbook นี้ยังไม่มี widget' : 'ยังไม่มี widget ใน Dashboard' ?>
            </p>
            <?php if ($canEdit): ?>
                <p class="text-xs mt-1 text-slate-300">คลิก "โหมดแก้ไข" → "เพิ่ม Widget" เพื่อเริ่มสร้าง</p>
                <?php if ($activeWorkbook): ?>
                    <button onclick="idOpenWorkbookModal(<?= (int)$activeWorkbook['id'] ?>)" class="mt-3 h-9 px-4 bg-slate-100 hover:bg-slate-200 text-slate-700 font-black rounded-lg text-xs">
                        <i class="fa-solid fa-cog mr-1"></i> ตั้งค่า workbook
                    </button>
                <?php endif; ?>
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
        <div class="<?= $sizeClass ?> id-card <?= $w['widget_type'] === 'kpi' ? 'fx-tilt fx-tilt-light' : '' ?>" data-widget-id="<?= (int)$w['id'] ?>" data-widget-type="<?= htmlspecialchars($w['widget_type']) ?>" data-tilt="4" style="<?= $isHidden ? 'opacity:.5' : '' ?>">
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
                $isEditableKpi = isset($kpiCatalogGlobal[$kpiKey]);
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
                        <p class="km-value id-kpi-value" data-value="<?= $val ?>"><span data-counter="<?= $val ?>">0</span></p>
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

<!-- ════════════ Workbook Modal ════════════ -->
<?php if ($canEdit): ?>
<div id="idWorkbookModal" class="fixed inset-0 hidden items-center justify-center bg-black/50 backdrop-blur-sm id-modal">
    <div class="bg-white rounded-[2rem] w-full max-w-xl mx-4 shadow-2xl flex flex-col id-modal-box">
        <div class="px-7 pt-6 pb-4 border-b border-slate-100 flex items-center justify-between shrink-0">
            <h3 id="idWorkbookModalTitle" class="text-lg font-black text-slate-900">สร้าง Workbook</h3>
            <button onclick="idCloseWorkbookModal()" class="w-9 h-9 bg-slate-50 text-slate-400 hover:text-slate-600 rounded-full flex items-center justify-center">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="overflow-y-auto flex-1 px-7 py-5 space-y-5">
            <input type="hidden" id="idwbIdField" value="0">

            <div>
                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">ชื่อ Workbook <span class="text-rose-500">*</span></label>
                <input type="text" id="idwbName" placeholder="เช่น ภาพรวม / ผู้บริหาร / สาธารณะ"
                    class="w-full h-11 px-4 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-blue-500/10 outline-none bg-slate-50">
            </div>

            <div>
                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">
                    Slug (URL) <span class="text-slate-400 font-normal text-[11px]">— เว้นว่างให้ระบบ generate ให้</span>
                </label>
                <div class="flex items-center bg-slate-50 border border-slate-200 rounded-xl overflow-hidden focus-within:ring-4 focus-within:ring-blue-500/10">
                    <span class="px-3 text-xs font-mono text-slate-400 border-r border-slate-200 bg-slate-100 h-11 flex items-center">/public/?wb=</span>
                    <input type="text" id="idwbSlug" placeholder="auto-generate"
                        class="flex-1 h-11 px-3 text-sm font-bold font-mono outline-none bg-transparent">
                </div>
            </div>

            <div>
                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">คำอธิบาย</label>
                <textarea id="idwbDescription" rows="2" placeholder="บอกย่อๆ ว่า workbook นี้คืออะไร"
                    class="w-full px-4 py-3 border border-slate-200 rounded-xl text-sm font-bold focus:ring-4 focus:ring-blue-500/10 outline-none bg-slate-50"></textarea>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">ไอคอน</label>
                    <div class="flex items-center gap-2 flex-wrap">
                        <?php foreach (['fa-chart-pie','fa-chart-line','fa-chart-bar','fa-shield-halved','fa-id-card','fa-hospital','fa-user-tie','fa-globe','fa-briefcase','fa-flag'] as $icn): ?>
                            <div class="wb-pill bg-slate-100 text-slate-600" data-icon="<?= $icn ?>" onclick="idSelectWBIcon('<?= $icn ?>')">
                                <i class="fa-solid <?= $icn ?>"></i>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">สี</label>
                    <div class="flex items-center gap-2 flex-wrap">
                        <?php foreach ([
                            'blue'=>'#3b82f6','emerald'=>'#10b981','amber'=>'#f59e0b','rose'=>'#f43f5e',
                            'purple'=>'#a855f7','cyan'=>'#06b6d4','indigo'=>'#6366f1','slate'=>'#64748b'
                        ] as $name => $hex): ?>
                            <div class="wb-pill" data-color="<?= $name ?>" style="background:<?= $hex ?>" onclick="idSelectWBColor('<?= $name ?>')"></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <label class="flex items-center gap-2 cursor-pointer p-3 bg-emerald-50 border border-emerald-200 rounded-xl">
                    <input type="checkbox" id="idwbIsPublic" class="w-4 h-4 accent-emerald-600">
                    <div class="flex-1">
                        <div class="text-xs font-black text-emerald-800">เปิด Public</div>
                        <div class="text-[10px] font-bold text-emerald-600">เข้าถึงผ่าน URL ได้</div>
                    </div>
                </label>
                <label class="flex items-center gap-2 cursor-pointer p-3 bg-blue-50 border border-blue-200 rounded-xl">
                    <input type="checkbox" id="idwbIsDefault" class="w-4 h-4 accent-blue-600">
                    <div class="flex-1">
                        <div class="text-xs font-black text-blue-800">Workbook หลัก</div>
                        <div class="text-[10px] font-bold text-blue-600">โหลดเป็น default</div>
                    </div>
                </label>
            </div>

            <div id="idwbPublicUrlPreview" class="bg-slate-50 border border-slate-200 rounded-xl p-3 hidden">
                <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Public URL</div>
                <div class="text-xs font-mono text-blue-600 break-all" id="idwbPublicUrlText"></div>
            </div>

            <div id="idwbError" class="hidden text-xs font-bold text-rose-600 bg-rose-50 border border-rose-100 rounded-xl px-4 py-3"></div>
        </div>
        <div class="px-7 py-4 bg-slate-50 border-t border-slate-100 flex gap-3 shrink-0">
            <button onclick="idCloseWorkbookModal()" class="flex-1 h-11 bg-white border border-slate-200 text-slate-600 font-black rounded-xl text-sm hover:bg-slate-100">ยกเลิก</button>
            <button id="idwbDeleteBtn" onclick="idDeleteWorkbook()" class="hidden h-11 px-4 bg-rose-50 border border-rose-200 text-rose-600 font-black rounded-xl text-sm hover:bg-rose-100">
                <i class="fa-solid fa-trash"></i> ลบ
            </button>
            <button onclick="idSaveWorkbook()" class="h-11 px-6 bg-blue-500 hover:bg-blue-600 text-white font-black rounded-xl text-sm shadow-lg shadow-blue-200 flex items-center gap-2" style="flex:2">
                <i class="fa-solid fa-floppy-disk"></i> บันทึก
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

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
    const ACTIVE_WORKBOOK = <?= json_encode($activeWorkbook ? [
        'id'   => (int)$activeWorkbook['id'],
        'slug' => $activeWorkbook['slug'],
        'name' => $activeWorkbook['name'],
    ] : null, JSON_UNESCAPED_UNICODE) ?>;
    const PUBLIC_URL_BASE = <?= json_encode($publicUrlBase) ?>;
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
    function chartTheme() {
        const dark = document.body.getAttribute('data-theme') === 'dark';
        return {
            tick:   dark ? '#cbd5e1' : '#64748b',
            grid:   dark ? 'rgba(241,245,249,.08)' : 'rgba(15,23,42,.06)',
            legend: dark ? '#e2e8f0' : '#334155',
            border: dark ? '#0f172a' : '#fff',
        };
    }
    const chartInstances = {};

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
        const th = chartTheme();
        const axisOpt = { ticks: { color: th.tick, font: { weight: 700 }}, grid: { color: th.grid }};

        if (chartInstances[w.id]) { chartInstances[w.id].destroy(); delete chartInstances[w.id]; }

        if (['line', 'area'].includes(w.type) && data.shape === 'timeseries') {
            chartInstances[w.id] = new Chart(canvas, {
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
                options: { responsive: true, maintainAspectRatio: false, scales: { x: axisOpt, y: axisOpt }, plugins: { legend: { position: 'bottom', labels: { color: th.legend, font: { weight: 700 }}}}}
            });
        } else if (w.type === 'bar' && data.shape === 'breakdown') {
            chartInstances[w.id] = new Chart(canvas, {
                type: 'bar',
                data: { labels: data.labels || [], datasets: [{ data: data.values || [], backgroundColor: c, borderRadius: 6 }] },
                options: { indexAxis: (data.labels || []).length > 5 ? 'y' : 'x', responsive: true, maintainAspectRatio: false, scales: { x: axisOpt, y: axisOpt }, plugins: { legend: { display: false }}}
            });
        } else if (w.type === 'bar' && data.shape === 'timeseries') {
            chartInstances[w.id] = new Chart(canvas, {
                type: 'bar',
                data: { labels: data.labels || [], datasets: (data.series || []).map((s, i) => ({ label: s.name, data: s.data, backgroundColor: i === 0 ? c : '#94a3b8', borderRadius: 4 })) },
                options: { responsive: true, maintainAspectRatio: false, scales: { x: axisOpt, y: axisOpt }, plugins: { legend: { position: 'bottom', labels: { color: th.legend, font: { weight: 700 }}}}}
            });
        } else if (['donut', 'pie'].includes(w.type) && data.shape === 'breakdown') {
            const palette = [c, '#f59e0b', '#10b981', '#a855f7', '#ef4444', '#06b6d4', '#6366f1', '#94a3b8'];
            chartInstances[w.id] = new Chart(canvas, {
                type: w.type === 'donut' ? 'doughnut' : 'pie',
                data: { labels: data.labels || [], datasets: [{ data: data.values || [], backgroundColor: palette, borderWidth: 2, borderColor: th.border }] },
                options: { responsive: true, maintainAspectRatio: false, cutout: w.type === 'donut' ? '60%' : 0, plugins: { legend: { position: 'bottom', labels: { color: th.legend, font: { weight: 700 }, boxWidth: 12 }}}}
            });
        } else {
            const ctx = canvas.getContext('2d');
            ctx.font = '14px sans-serif'; ctx.fillStyle = th.tick; ctx.textAlign = 'center';
            ctx.fillText('ไม่รองรับชนิดข้อมูลนี้', canvas.width / 2, canvas.height / 2);
        }
    }

    // Theme-toggle: re-render charts so axes/legend colors flip live
    new MutationObserver(muts => {
        for (const m of muts) if (m.attributeName === 'data-theme') { renderAllCharts(); break; }
    }).observe(document.body, { attributes: true, attributeFilter: ['data-theme'] });

    // ── Edit mode toggle ─────────────────────────────────────────────
    let editMode = false;
    window.idToggleEditMode = function() {
        editMode = !editMode;
        document.querySelectorAll('#idWidgetsGrid .id-card').forEach(c => {
            c.classList.toggle('id-edit-mode', editMode);
            // Drag-drop enabled only in edit mode
            if (editMode) {
                c.setAttribute('draggable', 'true');
                if (!c.querySelector('.id-grab-hint')) {
                    const hint = document.createElement('span');
                    hint.className = 'id-grab-hint';
                    hint.innerHTML = '<i class="fa-solid fa-grip-vertical"></i> ลากเพื่อจัดลำดับ';
                    c.appendChild(hint);
                }
            } else {
                c.removeAttribute('draggable');
                const hint = c.querySelector('.id-grab-hint');
                if (hint) hint.remove();
            }
        });
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

    // ── Drag-and-drop reorder (edit mode only) ───────────────────────
    (() => {
        const grid = document.getElementById('idWidgetsGrid');
        if (!grid) return;
        let dragSrc = null;
        let lastTarget = null;

        const clearHints = () => {
            grid.querySelectorAll('.id-drop-before, .id-drop-after').forEach(el => {
                el.classList.remove('id-drop-before', 'id-drop-after');
            });
        };

        grid.addEventListener('dragstart', (e) => {
            const card = e.target.closest('.id-card.id-edit-mode');
            if (!card) { e.preventDefault(); return; }
            dragSrc = card;
            card.classList.add('id-dragging');
            e.dataTransfer.effectAllowed = 'move';
            // Required for Firefox to initiate drag
            try { e.dataTransfer.setData('text/plain', card.dataset.widgetId || ''); } catch (_) {}
        });

        grid.addEventListener('dragover', (e) => {
            if (!dragSrc) return;
            const card = e.target.closest('.id-card.id-edit-mode');
            if (!card || card === dragSrc) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            // Decide before/after based on cursor X position
            const rect = card.getBoundingClientRect();
            const halfX = rect.left + rect.width / 2;
            const dropBefore = e.clientX < halfX;
            if (card !== lastTarget) {
                clearHints();
                lastTarget = card;
            }
            card.classList.toggle('id-drop-before', dropBefore);
            card.classList.toggle('id-drop-after', !dropBefore);
        });

        grid.addEventListener('dragleave', (e) => {
            // Clear when leaving the grid entirely (relatedTarget is outside grid)
            if (!grid.contains(e.relatedTarget)) {
                clearHints();
                lastTarget = null;
            }
        });

        grid.addEventListener('drop', (e) => {
            if (!dragSrc) return;
            const card = e.target.closest('.id-card.id-edit-mode');
            if (!card || card === dragSrc) { clearHints(); return; }
            e.preventDefault();
            const rect = card.getBoundingClientRect();
            const halfX = rect.left + rect.width / 2;
            if (e.clientX < halfX) {
                card.parentNode.insertBefore(dragSrc, card);
            } else {
                card.parentNode.insertBefore(dragSrc, card.nextSibling);
            }
            clearHints();
            lastTarget = null;
            persistOrder();
        });

        grid.addEventListener('dragend', () => {
            if (dragSrc) dragSrc.classList.remove('id-dragging');
            dragSrc = null;
            clearHints();
            lastTarget = null;
        });

        function persistOrder() {
            const ids = Array.from(grid.querySelectorAll('.id-card[data-widget-id]'))
                .map(c => +c.dataset.widgetId)
                .filter(Boolean);
            if (!ids.length) return;
            adPost('widget', 'reorder', { order: JSON.stringify(ids) })
                .then(r => {
                    if (r && r.status === 'ok') {
                        Swal.fire({
                            toast: true, position: 'top-end', icon: 'success',
                            title: 'จัดลำดับใหม่เรียบร้อย',
                            timer: 1600, showConfirmButton: false,
                        });
                    } else {
                        Swal.fire({ icon: 'error', title: 'บันทึกลำดับล้มเหลว', text: (r && r.message) || 'ไม่ทราบสาเหตุ' });
                    }
                })
                .catch(err => {
                    Swal.fire({ icon: 'error', title: 'บันทึกลำดับล้มเหลว', text: err.message || String(err) });
                });
        }
    })();

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
            workbook_id: ACTIVE_WORKBOOK ? ACTIVE_WORKBOOK.id : 0,
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

    // ── Workbook CRUD ───────────────────────────────────────────────
    let selectedWBIcon = 'fa-chart-pie';
    let selectedWBColor = 'blue';

    window.idOpenWorkbookModal = function(id) {
        const modal = document.getElementById('idWorkbookModal');
        document.getElementById('idwbIdField').value = id || 0;
        document.getElementById('idwbError').classList.add('hidden');

        if (id) {
            document.getElementById('idWorkbookModalTitle').textContent = 'แก้ไข Workbook';
            document.getElementById('idwbDeleteBtn').classList.remove('hidden');
            adPost('workbook', 'get', { id }).then(r => {
                if (r.status !== 'ok') { Swal.fire({icon:'error',title:'ผิดพลาด',text:r.message}); return; }
                const w = r.workbook;
                document.getElementById('idwbName').value = w.name || '';
                document.getElementById('idwbSlug').value = w.slug || '';
                document.getElementById('idwbDescription').value = w.description || '';
                document.getElementById('idwbIsPublic').checked = parseInt(w.is_public) === 1;
                document.getElementById('idwbIsDefault').checked = parseInt(w.is_default) === 1;
                selectedWBIcon = w.icon || 'fa-chart-pie';
                selectedWBColor = w.color || 'blue';
                syncWBPills();
                updatePublicUrlPreview();
            });
        } else {
            document.getElementById('idWorkbookModalTitle').textContent = 'สร้าง Workbook';
            document.getElementById('idwbDeleteBtn').classList.add('hidden');
            ['idwbName','idwbSlug','idwbDescription'].forEach(k => document.getElementById(k).value = '');
            document.getElementById('idwbIsPublic').checked = false;
            document.getElementById('idwbIsDefault').checked = false;
            selectedWBIcon = 'fa-chart-pie'; selectedWBColor = 'blue';
            syncWBPills();
            updatePublicUrlPreview();
        }
        modal.classList.remove('hidden'); modal.classList.add('flex');
    };

    window.idCloseWorkbookModal = function() {
        const modal = document.getElementById('idWorkbookModal');
        modal.classList.add('hidden'); modal.classList.remove('flex');
    };

    window.idSelectWBIcon = function(icon) { selectedWBIcon = icon; syncWBPills(); };
    window.idSelectWBColor = function(color) { selectedWBColor = color; syncWBPills(); };

    function syncWBPills() {
        document.querySelectorAll('.wb-pill[data-icon]').forEach(p =>
            p.classList.toggle('wb-selected', p.dataset.icon === selectedWBIcon));
        document.querySelectorAll('.wb-pill[data-color]').forEach(p =>
            p.classList.toggle('wb-selected', p.dataset.color === selectedWBColor));
    }

    function updatePublicUrlPreview() {
        const slug = document.getElementById('idwbSlug').value.trim() ||
            slugify(document.getElementById('idwbName').value.trim());
        const isPublic = document.getElementById('idwbIsPublic').checked;
        const wrap = document.getElementById('idwbPublicUrlPreview');
        if (isPublic && slug) {
            document.getElementById('idwbPublicUrlText').textContent = PUBLIC_URL_BASE + '?wb=' + slug;
            wrap.classList.remove('hidden');
        } else {
            wrap.classList.add('hidden');
        }
    }
    function slugify(s) {
        return String(s || '').toLowerCase().replace(/[^a-z0-9_\-]+/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '').substr(0, 60);
    }
    ['idwbName','idwbSlug','idwbIsPublic'].forEach(k => {
        const el = document.getElementById(k);
        if (el) el.addEventListener('input', updatePublicUrlPreview);
        if (el && el.type === 'checkbox') el.addEventListener('change', updatePublicUrlPreview);
    });

    window.idSaveWorkbook = function() {
        const id = document.getElementById('idwbIdField').value;
        const data = {
            id,
            name: document.getElementById('idwbName').value.trim(),
            slug: document.getElementById('idwbSlug').value.trim(),
            description: document.getElementById('idwbDescription').value.trim(),
            icon: selectedWBIcon,
            color: selectedWBColor,
            is_public: document.getElementById('idwbIsPublic').checked ? 1 : 0,
            is_default: document.getElementById('idwbIsDefault').checked ? 1 : 0,
        };
        adPost('workbook', 'save', data).then(r => {
            const err = document.getElementById('idwbError');
            if (r.status === 'ok') {
                err.classList.add('hidden');
                Swal.fire({ icon:'success', title:'บันทึกแล้ว', timer:1200, showConfirmButton:false }).then(() => {
                    location.href = '?section=insurance_dashboard&wb=' + encodeURIComponent(r.slug);
                });
            } else {
                err.textContent = r.message || 'บันทึกไม่สำเร็จ';
                err.classList.remove('hidden');
            }
        });
    };

    window.idDeleteWorkbook = async function() {
        const id = document.getElementById('idwbIdField').value;
        if (!id || id == 0) return;
        const { isConfirmed } = await Swal.fire({
            icon: 'warning', title: 'ลบ Workbook นี้?',
            html: '<b class="text-rose-700">Widget ทั้งหมดใน workbook นี้จะถูกลบด้วย</b><br>การลบนี้ไม่สามารถย้อนกลับได้',
            showCancelButton: true, confirmButtonText: 'ลบ', cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#dc2626', reverseButtons: true
        });
        if (!isConfirmed) return;
        adPost('workbook', 'delete', { id }).then(r => {
            if (r.status === 'ok') {
                Swal.fire({ icon:'success', title:'ลบแล้ว', timer:1200, showConfirmButton:false })
                    .then(() => location.href = '?section=insurance_dashboard');
            } else Swal.fire({ icon:'error', title:'ผิดพลาด', text:r.message });
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
