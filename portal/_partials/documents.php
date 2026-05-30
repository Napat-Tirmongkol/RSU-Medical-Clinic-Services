<?php
/**
 * portal/_partials/documents.php
 * คลังเอกสาร / รายงานสำหรับผู้บริหาร — section "เอกสาร" ใน sidebar
 *
 * Registered documents are defined inline in $documents (below) so adding
 * new ones is just appending an array entry. Each entry can specify its
 * own access level (superadmin / admin) so the listing self-filters.
 *
 * Loaded via portal/index.php inside #section-documents.
 * Variables in scope: $adminRole (string), $_SESSION
 */
declare(strict_types=1);

$_role = $adminRole ?? ($_SESSION['admin_role'] ?? '');

$documents = [
    [
        'key'         => 'scholarship_pitch',
        'title'       => 'ระบบจัดการนักศึกษาทุน — เอกสารนำเสนอ',
        'subtitle'    => 'Scholarship Management System · Handout',
        'description' => 'สรุประบบนักศึกษาทุน 5 หน้า A4 — ปัญหาเดิม · 6 features หลัก · GPS check-in via LINE · approval workflow · auto payout + finance sync · Morning Brief · เปรียบเทียบก่อน/หลัง · เหมาะใช้พิมพ์แจกผู้ฟังหลังการนำเสนอ',
        'category'    => 'proposal',
        'access'      => ['superadmin', 'admin', 'editor'],
        'pages'       => 5,
        'updated'     => (int)date('j') . ' ' . ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'][(int)date('n')] . ' ' . (date('Y') + 543),
        'version'     => 'v1.0',
        'url'         => '../docs/scholarship_pitch.php',
        'icon'        => 'fa-graduation-cap',
        'color'       => '#2e9e63',
        'bg'          => '#f0fdf4',
    ],
    [
        'key'         => 'user_hub_proposal',
        'title'       => 'โครงการพัฒนาระบบบริการสุขภาพออนไลน์สำหรับผู้รับบริการ',
        'subtitle'    => 'User Hub — แพลตฟอร์มเข้าถึงบริการสุขภาพในจุดเดียว',
        'description' => 'เอกสารเสนอโครงการต่อผู้บริหารมหาวิทยาลัย ครอบคลุมหลักการ วัตถุประสงค์ ขอบเขตฟังก์ชัน KPIs งบประมาณ และประเมินมูลค่าระบบที่พัฒนาแล้ว',
        'category'    => 'proposal',
        'access'      => ['superadmin', 'admin'],
        'pages'       => 9,
        'updated'     => '13 พ.ค. 2569',
        'version'     => 'v1.1',
        'url'         => '../docs/user_hub_proposal.php',
        'icon'        => 'fa-file-lines',
        'color'       => '#0f7349',
        'bg'          => '#ecfdf5',
    ],
    [
        'key'         => 'user_manual',
        'title'       => 'คู่มือการใช้งานระบบสำหรับผู้ใช้',
        'subtitle'    => 'User Manual — RSU Medical Clinic User Hub',
        'description' => 'คู่มือการใช้งานสำหรับนักศึกษา/บุคลากร — เริ่มต้นใช้งาน, จองนัด, เช็คอิน, ตรวจประวัติวัคซีน, สมัครบัตรทอง, ยืมอุปกรณ์, FAQ',
        'category'    => 'guide',
        'access'      => ['superadmin', 'admin'],
        'pages'       => 5,
        'updated'     => '13 พ.ค. 2569',
        'version'     => 'v1.0',
        'url'         => '../docs/user_manual.php',
        'icon'        => 'fa-book-open',
        'color'       => '#0f7349',
        'bg'          => '#ecfdf5',
    ],
    [
        'key'         => 'admin_manual',
        'title'       => 'คู่มือการใช้งานระบบสำหรับเจ้าหน้าที่',
        'subtitle'    => 'Admin Manual — Portal Operations Guide',
        'description' => 'คู่มือ Portal Admin — จัดการแคมเปญ/booking, อนุมัติคำขอ (Gold Card, e-Borrow, ทุน), ตอบ chat, Activity Dashboard, LINE Rich Menu',
        'category'    => 'guide',
        'access'      => ['superadmin', 'admin'],
        'pages'       => 5,
        'updated'     => '13 พ.ค. 2569',
        'version'     => 'v1.0',
        'url'         => '../docs/admin_manual.php',
        'icon'        => 'fa-screwdriver-wrench',
        'color'       => '#4f46e5',
        'bg'          => '#eef2ff',
    ],
    // ── เพิ่มเอกสารใหม่ตรงนี้ตามรูปแบบเดียวกัน ─────────────────────────────
];

// Filter ตาม role
$visible = array_filter($documents, function ($d) use ($_role) {
    return in_array($_role, $d['access'] ?? [], true);
});

$catLabels = [
    'proposal' => ['label' => 'เอกสารโครงการ', 'icon' => 'fa-folder-tree', 'color' => '#0f7349'],
    'iso'      => ['label' => 'ISO / Compliance', 'icon' => 'fa-shield-halved', 'color' => '#6366f1'],
    'report'   => ['label' => 'รายงาน',         'icon' => 'fa-chart-line', 'color' => '#f59e0b'],
    'guide'    => ['label' => 'คู่มือ',          'icon' => 'fa-book',       'color' => '#0ea5e9'],
];
?>
<style>
    .docs-wrap { padding: 24px; max-width: 1200px; margin: 0 auto; }
    @media (max-width: 768px) { .docs-wrap { padding: 16px; } }
    .docs-header {
        display: flex; align-items: flex-start; justify-content: space-between;
        gap: 16px; flex-wrap: wrap; margin-bottom: 20px;
    }
    .docs-header h1 {
        font-size: 22px; font-weight: 900; color: #0f172a;
        margin: 0; letter-spacing: -0.02em;
    }
    .docs-header .sub {
        font-size: 12px; font-weight: 700; color: #94a3b8;
        text-transform: uppercase; letter-spacing: 0.18em; margin-bottom: 4px;
    }
    .docs-meta {
        font-size: 11px; font-weight: 700; color: #64748b;
    }
    .docs-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 16px;
    }
    .doc-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 18px;
        padding: 20px;
        transition: transform .15s ease, box-shadow .15s ease;
        display: flex; flex-direction: column; gap: 8px;
    }
    .doc-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 32px rgba(15, 23, 42, 0.08);
    }
    .doc-card .head {
        display: flex; align-items: flex-start; gap: 12px;
    }
    .doc-card .head .ic {
        width: 44px; height: 44px; flex-shrink: 0;
        border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-size: 18px; color: #fff;
    }
    .doc-card .head .info { flex: 1; min-width: 0; }
    .doc-card .head h3 {
        font-size: 14px; font-weight: 900;
        color: #0f172a; line-height: 1.35;
        margin: 0 0 4px 0;
    }
    .doc-card .head .subtitle {
        font-size: 11px; color: #64748b; font-weight: 700;
        margin: 0;
    }
    .doc-card .description {
        font-size: 12px; color: #475569;
        line-height: 1.55; margin: 4px 0;
    }
    .doc-card .meta-row {
        display: flex; align-items: center; gap: 8px;
        flex-wrap: wrap; margin-top: 6px;
    }
    .doc-meta-pill {
        font-size: 10px; font-weight: 800;
        padding: 2px 8px; border-radius: 99px;
        background: #f1f5f9; color: #475569;
    }
    .doc-meta-pill.v { background: #fef3c7; color: #92400e; }
    .doc-card .actions {
        display: flex; gap: 6px;
        margin-top: 12px;
        padding-top: 12px;
        border-top: 1px solid #f1f5f9;
    }
    .doc-card .btn {
        flex: 1;
        padding: 8px 12px;
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        background: #fff;
        color: #475569;
        font-size: 11px; font-weight: 800;
        cursor: pointer;
        text-decoration: none; text-align: center;
        display: inline-flex; align-items: center; justify-content: center; gap: 5px;
        transition: all .15s ease;
    }
    .doc-card .btn:hover { background: #f8fafc; }
    .doc-card .btn.primary {
        background: #0f7349; border-color: #0f7349; color: #fff;
    }
    .doc-card .btn.primary:hover { background: #0c5d3a; }

    .docs-empty {
        background: #fff; border: 1px dashed #cbd5e1; border-radius: 16px;
        padding: 48px 20px; text-align: center;
        color: #64748b; font-size: 13px; font-weight: 700;
    }
    .docs-empty .ic {
        font-size: 36px; color: #cbd5e1; margin-bottom: 12px;
    }

    .docs-category-header {
        display: flex; align-items: center; gap: 8px;
        font-size: 11px; font-weight: 900;
        color: #64748b; text-transform: uppercase; letter-spacing: 0.16em;
        margin: 24px 0 10px 0;
    }
    .docs-category-header .line { flex: 1; height: 1px; background: #e2e8f0; }

    /* Restricted notice for non-superadmin viewing cost-sensitive docs */
    .doc-confidential {
        font-size: 10px; font-weight: 800;
        color: #b91c1c; background: #fee2e2;
        padding: 2px 6px; border-radius: 4px;
        border: 1px solid #fecaca;
    }

    /* ── Bold & Colorful — tilt-aware lift on doc cards ── */
    #section-documents .doc-card { isolation: isolate; transition: transform .25s cubic-bezier(.16,1,.3,1), box-shadow .25s ease, border-color .25s ease; }
    #section-documents .doc-card.fx-tilt:hover { --lift: -3px; box-shadow:0 18px 36px -18px rgba(15,115,73,.30); border-color:rgba(15,115,73,.30); }

    /* ── DARK MODE ──────────────────────────────────────────────── */
    body[data-theme='dark'] #section-documents .doc-card { background:#0f172a; border-color:#1e293b; box-shadow: 0 1px 0 rgba(255,255,255,.04), 0 8px 22px rgba(0,0,0,.35); }
    body[data-theme='dark'] #section-documents .doc-card .head h3 { color:#f1f5f9; }
    body[data-theme='dark'] #section-documents .doc-card .head .subtitle { color:#94a3b8; }
    body[data-theme='dark'] #section-documents .doc-card .description { color:#cbd5e1; }
    body[data-theme='dark'] #section-documents .doc-card .actions { border-color:#1e293b; }
    body[data-theme='dark'] #section-documents .doc-card .btn { background:#0f172a; border-color:#1e293b; color:#cbd5e1; }
    body[data-theme='dark'] #section-documents .doc-card .btn:hover { background:#1e293b; }
    body[data-theme='dark'] #section-documents .doc-card .btn.primary { background:#10b981; border-color:#10b981; color:#0f172a; }
    body[data-theme='dark'] #section-documents .doc-card .btn.primary:hover { background:#059669; }
    body[data-theme='dark'] #section-documents .doc-meta-pill { background:#1e293b; color:#cbd5e1; }
    body[data-theme='dark'] #section-documents .doc-meta-pill.v { background:rgba(245,158,11,.18); color:#fbbf24; }
    body[data-theme='dark'] #section-documents .docs-empty { background:#0f172a; border-color:#334155; color:#94a3b8; }
    body[data-theme='dark'] #section-documents .docs-empty .ic { color:#475569; }
    body[data-theme='dark'] #section-documents .docs-category-header { color:#94a3b8; }
    body[data-theme='dark'] #section-documents .docs-category-header .line { background:#1e293b; }
    body[data-theme='dark'] #section-documents .doc-confidential { background:rgba(244,63,94,.18); color:#fb7185; border-color:rgba(244,63,94,.40); }

    body[data-theme='dark'] #section-documents .bg-white { background:#0f172a !important; }
    body[data-theme='dark'] #section-documents .bg-slate-50 { background: rgba(148,163,184,.08) !important; }
    body[data-theme='dark'] #section-documents .bg-slate-100 { background: rgba(148,163,184,.14) !important; }
    body[data-theme='dark'] #section-documents .text-slate-900 { color:#f1f5f9 !important; }
    body[data-theme='dark'] #section-documents .text-slate-800 { color:#f1f5f9 !important; }
    body[data-theme='dark'] #section-documents .text-slate-700 { color:#e2e8f0 !important; }
    body[data-theme='dark'] #section-documents .text-slate-600 { color:#cbd5e1 !important; }
    body[data-theme='dark'] #section-documents .text-slate-500 { color:#94a3b8 !important; }
    body[data-theme='dark'] #section-documents .text-slate-400 { color:#64748b !important; }
    body[data-theme='dark'] #section-documents .border-slate-200 { border-color:#1e293b !important; }
    body[data-theme='dark'] #section-documents .border-slate-100 { border-color:#1e293b !important; }

    @media (prefers-reduced-motion: reduce) {
        #section-documents .doc-card { transition: none !important; transform: none !important; }
    }
</style>

<div class="docs-wrap">
    <!-- Header -->
    <div class="docs-header">
        <div>
            <div class="sub">RSU Medical Clinic · Document Library</div>
            <h1>📚 เอกสาร / รายงาน</h1>
            <div class="docs-meta" style="margin-top:6px">
                คลังเอกสารโครงการ รายงาน และคู่มือสำหรับผู้บริหาร
                · <?= count($visible) ?> เอกสารที่คุณเข้าถึงได้
            </div>
        </div>
    </div>

    <?php if (empty($visible)): ?>
        <div class="docs-empty">
            <div class="ic"><i class="fa-solid fa-folder-open"></i></div>
            <p>ยังไม่มีเอกสารในคลังที่คุณมีสิทธิ์เข้าถึง</p>
        </div>
    <?php else:
        // จัดกลุ่มตาม category
        $grouped = [];
        foreach ($visible as $d) {
            $cat = $d['category'] ?? 'proposal';
            $grouped[$cat][] = $d;
        }
        foreach ($grouped as $cat => $docs):
            $catMeta = $catLabels[$cat] ?? $catLabels['proposal'];
    ?>
        <div class="docs-category-header">
            <i class="fa-solid <?= htmlspecialchars($catMeta['icon']) ?>" style="color:<?= htmlspecialchars($catMeta['color']) ?>"></i>
            <span><?= htmlspecialchars($catMeta['label']) ?></span>
            <span style="color:#cbd5e1;font-weight:700">(<?= count($docs) ?>)</span>
            <div class="line"></div>
        </div>

        <div class="docs-grid">
            <?php foreach ($docs as $d):
                $bg     = $d['bg']    ?? '#ecfdf5';
                $color  = $d['color'] ?? '#0f7349';
                $icon   = $d['icon']  ?? 'fa-file-lines';
            ?>
                <div class="doc-card fx-tilt fx-tilt-light" data-tilt="3">
                    <div class="head">
                        <div class="ic" style="background:<?= htmlspecialchars($color) ?>">
                            <i class="fa-solid <?= htmlspecialchars($icon) ?>"></i>
                        </div>
                        <div class="info">
                            <h3><?= htmlspecialchars($d['title']) ?></h3>
                            <?php if (!empty($d['subtitle'])): ?>
                            <p class="subtitle"><?= htmlspecialchars($d['subtitle']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($d['description'])): ?>
                    <p class="description"><?= htmlspecialchars($d['description']) ?></p>
                    <?php endif; ?>

                    <div class="meta-row">
                        <?php if (!empty($d['pages'])): ?>
                        <span class="doc-meta-pill"><i class="fa-solid fa-file"></i> <?= (int)$d['pages'] ?> หน้า</span>
                        <?php endif; ?>
                        <?php if (!empty($d['version'])): ?>
                        <span class="doc-meta-pill v"><?= htmlspecialchars($d['version']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($d['updated'])): ?>
                        <span class="doc-meta-pill"><i class="fa-regular fa-clock"></i> <?= htmlspecialchars($d['updated']) ?></span>
                        <?php endif; ?>
                        <?php if (count($d['access'] ?? []) === 1 && $d['access'][0] === 'superadmin'): ?>
                        <span class="doc-confidential">SUPERADMIN ONLY</span>
                        <?php endif; ?>
                    </div>

                    <div class="actions">
                        <a class="btn primary" href="<?= htmlspecialchars($d['url']) ?>" target="_blank" rel="noopener">
                            <i class="fa-solid fa-arrow-up-right-from-square"></i> เปิดเอกสาร
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; endif; ?>

    <!-- Help footer -->
    <div style="margin-top:32px;padding:16px 20px;background:#fff;border:1px dashed #e2e8f0;border-radius:14px;font-size:12px;color:#64748b">
        <i class="fa-solid fa-circle-info" style="color:#0ea5e9;margin-right:6px"></i>
        <strong>วิธีดาวน์โหลด:</strong>
        เปิดเอกสาร → ใช้ปุ่มที่มุมขวาล่าง:
        <strong style="color:#475569">🖨️ Print</strong> (เลือก Save as PDF จาก dialog) ·
        <strong style="color:#dc2626">📕 PDF</strong> (ดาวน์โหลดทันที) ·
        <strong style="color:#2563eb">📘 .doc</strong> (เปิดใน Word/Google Docs)
    </div>
</div>
