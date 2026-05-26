<?php
/**
 * portal/_partials/line_settings.php — ส่วนตั้งค่า LINE Messaging API (Partial for SPA)
 */
declare(strict_types=1);

// กรณีเรียกแยกไฟล์ (ไม่ใช่ผ่าน index.php)
if (!isset($secrets)) {
    $secrets = require __DIR__ . '/../../config/secrets.php';
}

// ชอบ line_user_id_new (new channel) มากกว่า line_user_id เดิม เพื่อให้ test push ตรง channel ปัจจุบัน
$_prefillLineId = '';
if (!empty($_SESSION['student_id'])) {
    try {
        $_pdoLine = db();
        $_stmtLine = $_pdoLine->prepare("SELECT line_user_id, line_user_id_new FROM sys_users WHERE id = :id LIMIT 1");
        $_stmtLine->execute([':id' => (int)$_SESSION['student_id']]);
        $_rowLine = $_stmtLine->fetch(PDO::FETCH_ASSOC);
        if ($_rowLine) {
            $_prefillLineId = (string)($_rowLine['line_user_id_new'] ?: $_rowLine['line_user_id'] ?: '');
        }
    } catch (Throwable $e) {
        $_prefillLineId = (string)($_SESSION['line_user_id'] ?? '');
    }
} else {
    $_prefillLineId = (string)($_SESSION['line_user_id'] ?? '');
}

// ดึง Webhook URL อัตโนมัติ
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$uri = str_replace(['portal/index.php', 'portal/_partials/line_settings.php'], 'api/line_webhook.php', $_SERVER['REQUEST_URI']);
$uri = strtok($uri, '?');
$webhookUrl = "$protocol://$host$uri";
?>

<style>
    .line-input {
        width:100%; padding:.75rem 1rem;
        background:#f9fafb; border:1.5px solid #e5e7eb; border-radius:.875rem;
        font-size:.9rem; font-weight:500; color:#111827; outline:none;
        transition: all .2s;
    }
    .line-input:focus { background:#fff; border-color:#06b6d4; box-shadow:0 0 0 3px rgba(6,182,212,.1); }
    .line-label { display:block; font-size:.75rem; font-weight:800; color:#4b5563; text-transform:uppercase; letter-spacing:.05em; margin-bottom:.5rem; }
    .line-card  { background:#fff; border-radius:1.5rem; border:1.5px solid #e5e7eb; padding:1.75rem; margin-bottom:1.25rem; }

    /* Toggle switch */
    .line-toggle {
        --toggle-on: #0ea5e9;
        position: relative; display: inline-block; flex-shrink: 0;
        width: 44px; height: 24px;
    }
    .line-toggle input { position: absolute; opacity: 0; width: 0; height: 0; }
    .line-toggle .line-toggle-slider {
        position: absolute; inset: 0;
        background: #cbd5e1; border-radius: 24px;
        cursor: pointer;
        transition: background .2s;
    }
    .line-toggle .line-toggle-slider::before {
        content: ''; position: absolute;
        height: 18px; width: 18px;
        left: 3px; top: 3px;
        background: #fff; border-radius: 50%;
        box-shadow: 0 2px 6px rgba(15,23,42,.2);
        transition: transform .22s cubic-bezier(.34,1.56,.64,1);
    }
    .line-toggle input:checked + .line-toggle-slider { background: var(--toggle-on); }
    .line-toggle input:checked + .line-toggle-slider::before { transform: translateX(20px); }
    .line-toggle input:focus-visible + .line-toggle-slider {
        box-shadow: 0 0 0 3px rgba(14,165,233,.25);
    }
    .line-toggle.line-toggle--purple { --toggle-on: #7c3aed; }
    .line-toggle.line-toggle--purple input:focus-visible + .line-toggle-slider {
        box-shadow: 0 0 0 3px rgba(124,58,237,.25);
    }

    /* ── Bold & Colorful — tilt-aware lift on line-card panels ── */
    #section-line_settings .line-card { isolation: isolate; transition: transform .25s cubic-bezier(.16,1,.3,1), box-shadow .25s ease, border-color .25s ease; }
    #section-line_settings .line-card.fx-tilt:hover { --lift: -3px; box-shadow:0 18px 36px -18px rgba(6,182,212,.30); border-color:rgba(6,182,212,.30); }

    /* ── DARK MODE ──────────────────────────────────────────────── */
    body[data-theme='dark'] #section-line_settings .line-input { background:#0b1220; border-color:#1e293b; color:#e2e8f0; }
    body[data-theme='dark'] #section-line_settings .line-input:focus { background:#0f172a; }
    body[data-theme='dark'] #section-line_settings .line-label { color:#cbd5e1; }
    body[data-theme='dark'] #section-line_settings .line-card { background:#0f172a; border-color:#1e293b; box-shadow: 0 1px 0 rgba(255,255,255,.04), 0 8px 22px rgba(0,0,0,.35); }
    body[data-theme='dark'] #section-line_settings .line-toggle .line-toggle-slider { background:#334155; }

    body[data-theme='dark'] #section-line_settings .bg-white { background:#0f172a !important; }
    body[data-theme='dark'] #section-line_settings .bg-slate-50 { background: rgba(148,163,184,.08) !important; }
    body[data-theme='dark'] #section-line_settings .bg-slate-100 { background: rgba(148,163,184,.14) !important; }
    body[data-theme='dark'] #section-line_settings .bg-gray-50 { background: rgba(148,163,184,.08) !important; }
    body[data-theme='dark'] #section-line_settings .bg-gray-100 { background: rgba(148,163,184,.14) !important; }
    body[data-theme='dark'] #section-line_settings .bg-cyan-50 { background: rgba(6,182,212,.18) !important; }
    body[data-theme='dark'] #section-line_settings .bg-sky-50 { background: rgba(14,165,233,.18) !important; }
    body[data-theme='dark'] #section-line_settings .bg-emerald-50 { background: rgba(16,185,129,.18) !important; }
    body[data-theme='dark'] #section-line_settings .bg-amber-50 { background: rgba(245,158,11,.18) !important; }
    body[data-theme='dark'] #section-line_settings .bg-rose-50 { background: rgba(244,63,94,.18) !important; }
    body[data-theme='dark'] #section-line_settings .bg-purple-50 { background: rgba(168,85,247,.18) !important; }
    body[data-theme='dark'] #section-line_settings .bg-green-50 { background: rgba(16,185,129,.18) !important; }
    body[data-theme='dark'] #section-line_settings .text-gray-900 { color:#f1f5f9 !important; }
    body[data-theme='dark'] #section-line_settings .text-gray-700 { color:#e2e8f0 !important; }
    body[data-theme='dark'] #section-line_settings .text-gray-600 { color:#cbd5e1 !important; }
    body[data-theme='dark'] #section-line_settings .text-gray-500 { color:#94a3b8 !important; }
    body[data-theme='dark'] #section-line_settings .text-gray-400 { color:#64748b !important; }
    body[data-theme='dark'] #section-line_settings .text-slate-900 { color:#f1f5f9 !important; }
    body[data-theme='dark'] #section-line_settings .text-slate-700 { color:#e2e8f0 !important; }
    body[data-theme='dark'] #section-line_settings .text-slate-500 { color:#94a3b8 !important; }
    body[data-theme='dark'] #section-line_settings .border-gray-200 { border-color:#1e293b !important; }
    body[data-theme='dark'] #section-line_settings .border-gray-100 { border-color:#1e293b !important; }
    body[data-theme='dark'] #section-line_settings .border-slate-200 { border-color:#1e293b !important; }
    body[data-theme='dark'] #section-line_settings .border-slate-100 { border-color:#1e293b !important; }
    body[data-theme='dark'] #section-line_settings .border-cyan-200 { border-color: rgba(6,182,212,.30) !important; }
    body[data-theme='dark'] #section-line_settings .border-green-500 { border-color: #10b981 !important; }
    body[data-theme='dark'] #section-line_settings .border-t-green-500 { border-top-color: #10b981 !important; }

    @media (prefers-reduced-motion: reduce) {
        #section-line_settings .line-card { transition: none !important; transform: none !important; }
    }

    /* ════════════ Retro Power Switch (Rich Menu master on/off) ════════════ */
    .rm-power-switch {
        display: inline-flex;
        align-items: center;
        gap: 12px;
        padding: 10px 14px 10px 16px;
        background: linear-gradient(180deg, #f1f5f9 0%, #cbd5e1 100%);
        border: 2px solid #94a3b8;
        border-radius: 10px;
        box-shadow:
            inset 0 2px 0 rgba(255,255,255,.85),
            inset 0 -2px 0 rgba(0,0,0,.08),
            0 3px 6px rgba(15,23,42,.12);
        font-family: 'Courier New', ui-monospace, monospace;
        user-select: none;
    }
    .rm-power-label {
        font-size: 10px;
        font-weight: 900;
        letter-spacing: 0.18em;
        color: #475569;
        text-transform: uppercase;
        text-shadow: 1px 1px 0 rgba(255,255,255,.7);
    }
    .rm-power-led {
        position: relative;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background: radial-gradient(circle at 35% 35%, #7f1d1d 0%, #450a0a 70%);
        box-shadow:
            inset 0 1px 2px rgba(0,0,0,.7),
            0 0 0 2px #1e293b,
            0 0 0 3px #64748b;
        transition: background 0.25s, box-shadow 0.25s;
    }
    .rm-power-switch.is-on .rm-power-led {
        background: radial-gradient(circle at 35% 35%, #bbf7d0 0%, #16a34a 60%, #14532d 100%);
        box-shadow:
            inset 0 1px 2px rgba(255,255,255,.4),
            0 0 0 2px #14532d,
            0 0 0 3px #64748b,
            0 0 10px rgba(74,222,128,.7);
        animation: rmLedPulse 2.4s ease-in-out infinite;
    }
    @keyframes rmLedPulse {
        0%, 100% { box-shadow: inset 0 1px 2px rgba(255,255,255,.4), 0 0 0 2px #14532d, 0 0 0 3px #64748b, 0 0 10px rgba(74,222,128,.7); }
        50%      { box-shadow: inset 0 1px 2px rgba(255,255,255,.4), 0 0 0 2px #14532d, 0 0 0 3px #64748b, 0 0 14px rgba(74,222,128,1); }
    }
    .rm-toggle {
        background: none;
        border: 0;
        cursor: pointer;
        padding: 0;
        display: inline-flex;
        align-items: center;
        gap: 10px;
    }
    .rm-toggle-track {
        position: relative;
        display: inline-block;
        width: 62px;
        height: 28px;
        background: linear-gradient(180deg, #475569 0%, #1e293b 100%);
        border: 2px solid #0f172a;
        border-radius: 4px;
        box-shadow: inset 0 2px 5px rgba(0,0,0,.7);
    }
    .rm-toggle-track::before,
    .rm-toggle-track::after {
        content: '';
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
        font-family: 'Courier New', monospace;
        font-size: 8px;
        font-weight: 900;
        letter-spacing: 0.05em;
        color: rgba(255,255,255,.35);
        text-shadow: 1px 1px 0 rgba(0,0,0,.5);
    }
    .rm-toggle-track::before { content: 'ON';  left: 7px; }
    .rm-toggle-track::after  { content: 'OFF'; right: 6px; }
    .rm-toggle-thumb {
        position: absolute;
        top: 2px;
        left: 2px;
        width: 22px;
        height: 20px;
        background:
            linear-gradient(180deg, #fafafa 0%, #cbd5e1 50%, #94a3b8 100%);
        border: 1px solid #475569;
        border-radius: 3px;
        box-shadow:
            inset 0 1px 0 rgba(255,255,255,.9),
            inset 0 -1px 0 rgba(0,0,0,.2),
            0 2px 3px rgba(0,0,0,.4);
        transition: left 0.18s cubic-bezier(.4,1.5,.6,1);
    }
    .rm-toggle-thumb::before {
        content: '';
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        width: 14px;
        height: 2px;
        background: rgba(0,0,0,.25);
        border-radius: 1px;
        box-shadow: 0 3px 0 rgba(0,0,0,.25), 0 -3px 0 rgba(0,0,0,.25);
    }
    .rm-power-switch.is-on .rm-toggle-track {
        background: linear-gradient(180deg, #16a34a 0%, #14532d 100%);
        border-color: #052e16;
    }
    .rm-power-switch.is-on .rm-toggle-thumb {
        left: 36px;
    }
    .rm-toggle-state {
        font-size: 14px;
        font-weight: 900;
        color: #b91c1c;
        min-width: 36px;
        text-align: left;
        letter-spacing: 0.05em;
        text-shadow: 1px 1px 0 rgba(255,255,255,.6);
    }
    .rm-power-switch.is-on .rm-toggle-state { color: #15803d; }
    .rm-toggle:focus-visible { outline: 2px dashed #0ea5e9; outline-offset: 4px; border-radius: 4px; }

    /* Dark mode */
    body[data-theme='dark'] .rm-power-switch {
        background: linear-gradient(180deg, #334155 0%, #0f172a 100%);
        border-color: #475569;
        box-shadow: inset 0 2px 0 rgba(255,255,255,.08), inset 0 -2px 0 rgba(0,0,0,.4), 0 3px 6px rgba(0,0,0,.4);
    }
    body[data-theme='dark'] .rm-power-label { color: #cbd5e1; text-shadow: 1px 1px 0 rgba(0,0,0,.5); }
    body[data-theme='dark'] .rm-toggle-state { color: #f87171; text-shadow: 1px 1px 0 rgba(0,0,0,.6); }
    body[data-theme='dark'] .rm-power-switch.is-on .rm-toggle-state { color: #4ade80; }

    /* Disabled banner ครอบ section เมื่อปิด */
    .rm-disabled-banner {
        display: none;
        margin-top: 14px;
        padding: 10px 14px;
        background: linear-gradient(180deg, #fef3c7, #fde68a);
        border: 1.5px dashed #d97706;
        border-radius: 10px;
        color: #78350f;
        font-size: 12px;
        font-weight: 700;
        gap: 8px;
        align-items: center;
    }
    .rm-power-switch.is-off ~ .rm-disabled-banner,
    #rmSection.is-disabled .rm-disabled-banner { display: flex; }
    body[data-theme='dark'] .rm-disabled-banner {
        background: rgba(217,119,6,.15);
        border-color: rgba(217,119,6,.5);
        color: #fcd34d;
    }

    @media (prefers-reduced-motion: reduce) {
        .rm-power-switch.is-on .rm-power-led { animation: none; }
        .rm-toggle-thumb { transition: none; }
    }

    /* ════════════ Rich Menu picker cards ════════════ */
    .rm-menu-card {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        padding: 11px 14px;
        background: #f8fafc;
        border: 1.5px solid #e2e8f0;
        border-radius: 14px;
        transition: border-color 0.18s, background 0.18s, transform 0.18s, box-shadow 0.18s;
        flex-wrap: wrap;
    }
    .rm-menu-card:hover {
        background: #fff;
        border-color: #94a3b8;
        transform: translateY(-1px);
        box-shadow: 0 3px 8px rgba(15,23,42,.08);
    }
    .rm-menu-card.is-active {
        background: linear-gradient(180deg, #f0fdf4, #ecfdf5);
        border-color: #4ade80;
        box-shadow: inset 3px 0 0 #16a34a;
    }
    .rm-menu-card-info { flex: 1 1 280px; min-width: 0; }
    .rm-menu-card-name {
        font-size: 13.5px;
        font-weight: 800;
        color: #0f172a;
    }
    .rm-menu-card-id {
        font-family: ui-monospace, 'Courier New', monospace;
        font-size: 10.5px;
        color: #64748b;
        margin-top: 2px;
        word-break: break-all;
        line-height: 1.4;
    }
    .rm-menu-card-meta {
        font-size: 10px;
        color: #94a3b8;
        margin-top: 3px;
    }
    .rm-card-tag {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 2px 8px;
        border-radius: 999px;
        font-size: 9.5px;
        font-weight: 900;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }
    .rm-card-tag-amber   { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
    .rm-card-tag-emerald { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }

    .rm-menu-card-actions { display: flex; gap: 6px; flex-wrap: wrap; }
    .rm-card-btn {
        padding: 6px 11px;
        background: #fff;
        border: 1.5px solid #cbd5e1;
        border-radius: 9px;
        font-size: 11px;
        font-weight: 800;
        color: #475569;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        transition: all 0.15s;
    }
    .rm-card-btn:hover {
        background: #f1f5f9;
        border-color: #94a3b8;
        color: #1e293b;
        transform: translateY(-1px);
    }
    .rm-card-btn.is-success {
        background: #dcfce7 !important;
        border-color: #16a34a !important;
        color: #15803d !important;
    }
    .rm-card-btn-amber:hover {
        background: #fef3c7;
        border-color: #f59e0b;
        color: #92400e;
    }
    .rm-card-btn-emerald:hover {
        background: #dcfce7;
        border-color: #16a34a;
        color: #15803d;
    }

    .rm-input-flash {
        animation: rmInputFlash 0.85s ease;
    }
    @keyframes rmInputFlash {
        0%   { background: #fef3c7 !important; box-shadow: 0 0 0 4px rgba(245,158,11,.35); border-color: #f59e0b !important; }
        100% { box-shadow: 0 0 0 0 rgba(245,158,11,0); }
    }

    /* Dark mode */
    body[data-theme='dark'] .rm-menu-card {
        background: #0f172a;
        border-color: #1e293b;
    }
    body[data-theme='dark'] .rm-menu-card:hover {
        background: #1e293b;
        border-color: #475569;
    }
    body[data-theme='dark'] .rm-menu-card.is-active {
        background: linear-gradient(180deg, rgba(22,163,74,.12), rgba(22,163,74,.08));
        border-color: rgba(74,222,128,.5);
        box-shadow: inset 3px 0 0 #4ade80;
    }
    body[data-theme='dark'] .rm-menu-card-name { color: #f1f5f9; }
    body[data-theme='dark'] .rm-menu-card-id   { color: #94a3b8; }
    body[data-theme='dark'] .rm-menu-card-meta { color: #64748b; }
    body[data-theme='dark'] .rm-card-btn {
        background: #1e293b;
        border-color: #334155;
        color: #cbd5e1;
    }
    body[data-theme='dark'] .rm-card-btn:hover {
        background: #334155;
        border-color: #64748b;
        color: #f1f5f9;
    }
    body[data-theme='dark'] #rmMenuListEmpty {
        background: rgba(15,23,42,.5);
        border-color: #334155;
        color: #64748b !important;
    }

    /* ════════════ Bulk Sync Bar (prominent) ════════════ */
    .rm-bulk-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 16px 18px;
        background:
            linear-gradient(135deg, #faf5ff 0%, #ede9fe 60%, #ddd6fe 100%);
        border: 1.5px solid #c4b5fd;
        border-radius: 18px;
        flex-wrap: wrap;
        position: relative;
        overflow: hidden;
    }
    .rm-bulk-bar::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -10%;
        width: 200px;
        height: 200px;
        background: radial-gradient(circle, rgba(139,92,246,.25) 0%, transparent 70%);
        pointer-events: none;
    }
    .rm-bulk-info { flex: 1 1 320px; min-width: 0; position: relative; z-index: 1; }
    .rm-bulk-info-title {
        font-size: 14.5px;
        font-weight: 900;
        color: #5b21b6;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    .rm-bulk-info-title i {
        color: #8b5cf6;
        font-size: 17px;
        background: #fff;
        width: 32px;
        height: 32px;
        border-radius: 10px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 2px 6px rgba(139,92,246,.25);
    }
    .rm-bulk-info-desc {
        font-size: 11.5px;
        color: #6d28d9;
        font-weight: 500;
        margin-top: 6px;
        line-height: 1.55;
        max-width: 540px;
    }
    .rm-bulk-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        position: relative;
        z-index: 1;
    }
    .rm-bulk-btn {
        padding: 11px 18px;
        border-radius: 12px;
        font-size: 13px;
        font-weight: 900;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        border: 0;
        transition: transform 0.18s, box-shadow 0.18s, background 0.18s;
    }
    .rm-bulk-btn-primary {
        background: linear-gradient(180deg, #8b5cf6 0%, #7c3aed 100%);
        color: #fff;
        box-shadow: 0 4px 12px rgba(139,92,246,.4), inset 0 1px 0 rgba(255,255,255,.25);
    }
    .rm-bulk-btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 7px 18px rgba(139,92,246,.55), inset 0 1px 0 rgba(255,255,255,.25);
    }
    .rm-bulk-btn-primary:active { transform: translateY(0); }
    .rm-bulk-btn-primary i { font-size: 14px; }
    .rm-bulk-btn-ghost {
        background: rgba(255,255,255,.6);
        color: #6d28d9;
        border: 1.5px solid rgba(139,92,246,.3);
        backdrop-filter: blur(4px);
    }
    .rm-bulk-btn-ghost:hover {
        background: #fff;
        border-color: rgba(139,92,246,.55);
        color: #5b21b6;
    }
    .rm-bulk-btn-danger {
        background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        color: #fff;
        border: 1px solid rgba(220,38,38,.5);
        box-shadow: 0 4px 12px rgba(220,38,38,.45), inset 0 1px 0 rgba(255,255,255,.18);
    }
    .rm-bulk-btn-danger:hover {
        transform: translateY(-1px);
        box-shadow: 0 7px 18px rgba(220,38,38,.55), inset 0 1px 0 rgba(255,255,255,.25);
    }
    .rm-bulk-btn-danger:active { transform: translateY(0); }
    body[data-theme='dark'] .rm-bulk-btn-danger {
        background: linear-gradient(135deg, #b91c1c 0%, #991b1b 100%);
        border-color: rgba(248,113,113,.4);
    }

    /* Dark mode */
    body[data-theme='dark'] .rm-bulk-bar {
        background: linear-gradient(135deg, rgba(124,58,237,.18) 0%, rgba(139,92,246,.15) 100%);
        border-color: rgba(139,92,246,.4);
    }
    body[data-theme='dark'] .rm-bulk-bar::before {
        background: radial-gradient(circle, rgba(139,92,246,.35) 0%, transparent 70%);
    }
    body[data-theme='dark'] .rm-bulk-info-title { color: #c4b5fd; }
    body[data-theme='dark'] .rm-bulk-info-title i {
        background: rgba(15,23,42,.6);
        color: #a78bfa;
    }
    body[data-theme='dark'] .rm-bulk-info-desc { color: #a78bfa; }
    body[data-theme='dark'] .rm-bulk-btn-ghost {
        background: rgba(15,23,42,.6);
        color: #c4b5fd;
        border-color: rgba(139,92,246,.4);
    }
    body[data-theme='dark'] .rm-bulk-btn-ghost:hover {
        background: rgba(15,23,42,.85);
        border-color: rgba(139,92,246,.7);
        color: #ddd6fe;
    }

    /* ════════════════════════════════════════════════════
       Page-level UX: Sticky nav · Status grid · Banners · Back-to-top
       ════════════════════════════════════════════════════ */

    /* === Status Overview Grid === */
    .ls-status-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
        gap: 12px;
        margin: 0 0 22px;
    }
    .ls-status-tile {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 14px 16px;
        background: linear-gradient(135deg, #fff 0%, #f8fafc 100%);
        border: 1.5px solid #e2e8f0;
        border-radius: 16px;
        transition: transform 0.22s cubic-bezier(.16,1,.3,1), box-shadow 0.22s, border-color 0.22s;
        cursor: pointer;
        text-decoration: none;
        position: relative;
        overflow: hidden;
    }
    .ls-status-tile:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 18px rgba(15,23,42,.08);
        border-color: #94a3b8;
    }
    .ls-status-tile::after {
        content: '';
        position: absolute;
        right: -30px; top: -30px;
        width: 80px; height: 80px;
        background: radial-gradient(circle, var(--tile-glow, rgba(14,165,233,.12)) 0%, transparent 70%);
        pointer-events: none;
    }
    .ls-status-tile[data-tone="ok"]    { --tile-glow: rgba(16,185,129,.18); }
    .ls-status-tile[data-tone="warn"]  { --tile-glow: rgba(245,158,11,.18); }
    .ls-status-tile[data-tone="error"] { --tile-glow: rgba(239,68,68,.18); }
    .ls-status-tile[data-tone="info"]  { --tile-glow: rgba(14,165,233,.18); }
    .ls-status-tile[data-tone="muted"] { --tile-glow: rgba(148,163,184,.18); }

    .ls-status-icon {
        width: 42px; height: 42px;
        border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0;
        font-size: 16px;
        color: #fff;
        box-shadow: 0 3px 8px rgba(15,23,42,.18);
        position: relative;
        z-index: 1;
    }
    .ls-status-tile[data-tone="ok"]    .ls-status-icon { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
    .ls-status-tile[data-tone="warn"]  .ls-status-icon { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
    .ls-status-tile[data-tone="error"] .ls-status-icon { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }
    .ls-status-tile[data-tone="info"]  .ls-status-icon { background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%); }
    .ls-status-tile[data-tone="muted"] .ls-status-icon { background: linear-gradient(135deg, #94a3b8 0%, #64748b 100%); }

    .ls-status-body { flex: 1; min-width: 0; position: relative; z-index: 1; }
    .ls-status-label {
        font-size: 10px; font-weight: 900;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: 0.08em;
    }
    .ls-status-value {
        font-size: 16px; font-weight: 900;
        color: #0f172a;
        line-height: 1.2;
        margin-top: 2px;
        word-break: break-word;
    }
    .ls-status-meta {
        font-size: 10.5px;
        color: #64748b;
        margin-top: 3px;
        font-weight: 600;
    }

    /* === Sticky Quick Nav === */
    .ls-quick-nav {
        position: sticky;
        top: 0; /* overridden by JS to match portal-header height */
        z-index: 35;
        margin: 0 -1rem 24px;
        padding: 10px 16px;
        background: rgba(255,255,255,.92);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        border-bottom: 1.5px solid rgba(226,232,240,.7);
        display: flex;
        gap: 8px;
        overflow-x: auto;
        scrollbar-width: thin;
    }
    .ls-quick-nav::-webkit-scrollbar { height: 4px; }
    .ls-quick-nav::-webkit-scrollbar-track { background: transparent; }
    .ls-quick-nav::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 99px; }
    .ls-nav-chip {
        flex-shrink: 0;
        padding: 7px 14px;
        border-radius: 999px;
        background: #f1f5f9;
        border: 1.5px solid transparent;
        color: #475569;
        font-size: 12px;
        font-weight: 800;
        cursor: pointer;
        transition: all 0.18s;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        white-space: nowrap;
    }
    .ls-nav-chip:hover {
        background: #e2e8f0;
        transform: translateY(-1px);
        color: #1e293b;
    }
    .ls-nav-chip i { font-size: 11px; opacity: 0.8; }
    .ls-nav-chip.is-active {
        background: linear-gradient(180deg, #06b6d4 0%, #0891b2 100%);
        color: #fff;
        border-color: #0e7490;
        box-shadow: 0 3px 10px rgba(6,182,212,.4);
    }
    .ls-nav-chip.is-active i { opacity: 1; }

    /* === Section Banners (replaces hairline dividers) === */
    .ls-section-banner {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 14px 18px;
        margin: 36px 0 18px;
        background: linear-gradient(90deg, var(--banner-tint, #f0fdf4) 0%, transparent 75%);
        border-left: 5px solid var(--banner-accent, #22c55e);
        border-radius: 0 14px 14px 0;
        position: relative;
        scroll-margin-top: 80px; /* offset for sticky portal header + nav */
    }
    .ls-section-banner-icon {
        width: 42px; height: 42px;
        background: #fff;
        border: 1.5px solid var(--banner-accent, #22c55e);
        border-radius: 12px;
        display: inline-flex; align-items: center; justify-content: center;
        color: var(--banner-accent, #22c55e);
        font-size: 17px;
        box-shadow: 0 2px 6px rgba(15,23,42,.08);
        flex-shrink: 0;
    }
    .ls-section-banner-text { flex: 1; min-width: 0; }
    .ls-section-banner h2 {
        font-size: 16px;
        font-weight: 900;
        color: var(--banner-accent-dark, #15803d);
        margin: 0;
        line-height: 1.3;
        letter-spacing: -0.01em;
    }
    .ls-section-banner p {
        font-size: 12px;
        color: #64748b;
        margin: 3px 0 0;
        line-height: 1.5;
        font-weight: 500;
    }
    .ls-section-banner-aside {
        flex-shrink: 0;
        font-size: 11px;
        font-weight: 700;
        color: #64748b;
    }

    /* Section banner color presets */
    .ls-section-banner[data-tone="config"]   { --banner-tint: #f0f9ff; --banner-accent: #0ea5e9; --banner-accent-dark: #075985; }
    .ls-section-banner[data-tone="groups"]   { --banner-tint: #f0fdf4; --banner-accent: #22c55e; --banner-accent-dark: #15803d; }
    .ls-section-banner[data-tone="stats"]    { --banner-tint: #ecfdf5; --banner-accent: #2e9e63; --banner-accent-dark: #14532d; }
    .ls-section-banner[data-tone="richmenu"] { --banner-tint: #ecfeff; --banner-accent: #06b6d4; --banner-accent-dark: #0e7490; }
    .ls-section-banner[data-tone="creator"]  { --banner-tint: #fff7ed; --banner-accent: #f97316; --banner-accent-dark: #c2410c; }

    /* === Back to Top Button === */
    .ls-back-top {
        position: fixed;
        right: 20px;
        bottom: 92px; /* above the tour ? FAB at 20px bottom */
        width: 44px; height: 44px;
        background: linear-gradient(180deg, #0f172a 0%, #1e293b 100%);
        color: #fff;
        border: 0;
        border-radius: 50%;
        cursor: pointer;
        z-index: 80;
        box-shadow: 0 6px 18px rgba(15,23,42,.28);
        opacity: 0;
        pointer-events: none;
        transform: translateY(12px);
        transition: transform 0.25s cubic-bezier(.16,1,.3,1), opacity 0.25s, box-shadow 0.25s;
        display: flex; align-items: center; justify-content: center;
        font-size: 15px;
    }
    .ls-back-top.is-visible {
        opacity: 1;
        pointer-events: auto;
        transform: translateY(0);
    }
    .ls-back-top:hover {
        transform: translateY(-3px);
        box-shadow: 0 12px 26px rgba(15,23,42,.4);
    }
    .ls-back-top:active { transform: translateY(-1px); }

    /* === Dark Mode === */
    body[data-theme='dark'] .ls-status-tile {
        background: linear-gradient(135deg, #0f172a 0%, #0b1220 100%);
        border-color: #1e293b;
    }
    body[data-theme='dark'] .ls-status-tile:hover { border-color: #475569; }
    body[data-theme='dark'] .ls-status-label { color: #64748b; }
    body[data-theme='dark'] .ls-status-value { color: #f1f5f9; }
    body[data-theme='dark'] .ls-status-meta { color: #94a3b8; }

    body[data-theme='dark'] .ls-quick-nav {
        background: rgba(15,23,42,.92);
        border-bottom-color: #1e293b;
    }
    body[data-theme='dark'] .ls-nav-chip {
        background: #1e293b;
        color: #cbd5e1;
    }
    body[data-theme='dark'] .ls-nav-chip:hover {
        background: #334155;
        color: #f1f5f9;
    }

    body[data-theme='dark'] .ls-section-banner {
        background: linear-gradient(90deg, color-mix(in srgb, var(--banner-accent) 18%, transparent) 0%, transparent 75%);
    }
    body[data-theme='dark'] .ls-section-banner-icon {
        background: #0f172a;
        color: var(--banner-accent);
        border-color: color-mix(in srgb, var(--banner-accent) 50%, transparent);
    }
    body[data-theme='dark'] .ls-section-banner h2 { color: #f1f5f9; }
    body[data-theme='dark'] .ls-section-banner-aside { color: #94a3b8; }

    body[data-theme='dark'] .ls-back-top {
        background: linear-gradient(180deg, #f1f5f9 0%, #cbd5e1 100%);
        color: #0f172a;
    }

    @media (prefers-reduced-motion: reduce) {
        .ls-status-tile, .ls-back-top, .ls-nav-chip { transition: none !important; transform: none !important; }
    }

    @media (max-width: 640px) {
        .ls-section-banner { padding: 12px 14px; }
        .ls-section-banner h2 { font-size: 14.5px; }
        .ls-section-banner p { font-size: 11px; }
        .ls-section-banner-icon { width: 36px; height: 36px; font-size: 15px; }
    }
</style>

<div class="px-4 py-8">

    <!-- Header -->
    <div class="mb-8 flex items-center justify-between">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 bg-white rounded-xl shadow-sm border border-gray-200 flex items-center justify-center text-green-500 text-2xl">
                <i class="fa-brands fa-line"></i>
            </div>
            <div>
                <h2 class="text-2xl font-black text-slate-800">LINE Messaging API</h2>
                <p class="text-slate-500 text-sm font-medium">ตั้งค่า Webhook และทดสอบการส่งข้อความแจ้งเตือน</p>
            </div>
        </div>
        <button onclick="switchSection('settings')" class="px-4 py-2 bg-slate-100 text-slate-600 rounded-xl text-xs font-bold hover:bg-slate-200 transition-all flex items-center gap-2">
            <i class="fa-solid fa-arrow-left"></i> กลับไปที่ Settings
        </button>
    </div>

    <!-- ──────── Sticky quick-nav ──────── -->
    <nav class="ls-quick-nav" id="lsQuickNav" aria-label="LINE settings sections">
        <button type="button" class="ls-nav-chip is-active" data-target="ls-sec-config">
            <i class="fa-solid fa-key"></i><span>API & Webhook</span>
        </button>
        <button type="button" class="ls-nav-chip" data-target="ls-sec-groups">
            <i class="fa-solid fa-users"></i><span>กลุ่ม LINE</span>
        </button>
        <button type="button" class="ls-nav-chip" data-target="ls-sec-stats">
            <i class="fa-solid fa-chart-column"></i><span>สถิติ</span>
        </button>
        <button type="button" class="ls-nav-chip" data-target="ls-sec-richmenu">
            <i class="fa-solid fa-bars-staggered"></i><span>Rich Menu</span>
        </button>
        <button type="button" class="ls-nav-chip" data-target="ls-sec-creator">
            <i class="fa-solid fa-bolt"></i><span>สร้าง Menu</span>
        </button>
    </nav>

    <!-- ──────── Status Overview ──────── -->
    <?php
        $_tokenOk  = !empty($secrets['LINE_MESSAGING_CHANNEL_ACCESS_TOKEN']);
        $_secretOk = !empty($secrets['LINE_MESSAGING_CHANNEL_SECRET']);
    ?>
    <div class="ls-status-grid" id="lsStatusGrid">
        <div class="ls-status-tile" data-tone="<?= $_tokenOk ? 'ok' : 'error' ?>" id="lsStatusToken" onclick="lsScrollTo('ls-sec-config')">
            <div class="ls-status-icon">
                <i class="fa-solid <?= $_tokenOk ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i>
            </div>
            <div class="ls-status-body">
                <div class="ls-status-label">Channel API</div>
                <div class="ls-status-value"><?= $_tokenOk ? 'พร้อมใช้งาน' : 'ยังไม่ตั้งค่า' ?></div>
                <div class="ls-status-meta"><?= $_secretOk ? 'Token + Secret ครบ' : ($_tokenOk ? 'ขาด secret' : 'ใส่ token/secret') ?></div>
            </div>
        </div>

        <div class="ls-status-tile" data-tone="info" id="lsStatusWebhook" onclick="lsScrollTo('ls-sec-config')">
            <div class="ls-status-icon"><i class="fa-solid fa-link"></i></div>
            <div class="ls-status-body">
                <div class="ls-status-label">Webhook URL</div>
                <div class="ls-status-value">พร้อม</div>
                <div class="ls-status-meta">กดดูที่การ์ดด้านล่าง</div>
            </div>
        </div>

        <div class="ls-status-tile" data-tone="muted" id="lsStatusQuota" onclick="lsScrollTo('ls-sec-stats')">
            <div class="ls-status-icon"><i class="fa-solid fa-gauge"></i></div>
            <div class="ls-status-body">
                <div class="ls-status-label">โควต้าเดือนนี้</div>
                <div class="ls-status-value">—</div>
                <div class="ls-status-meta">โหลดสถิติเพื่อดูตัวเลข</div>
            </div>
        </div>

        <div class="ls-status-tile" data-tone="muted" id="lsStatusRichMenu" onclick="lsScrollTo('ls-sec-richmenu')">
            <div class="ls-status-icon"><i class="fa-solid fa-bars-staggered"></i></div>
            <div class="ls-status-body">
                <div class="ls-status-label">Rich Menu</div>
                <div class="ls-status-value">กำลังโหลด...</div>
                <div class="ls-status-meta">&nbsp;</div>
            </div>
        </div>

        <div class="ls-status-tile" data-tone="muted" id="lsStatusGroups" onclick="lsScrollTo('ls-sec-groups')">
            <div class="ls-status-icon"><i class="fa-solid fa-users"></i></div>
            <div class="ls-status-body">
                <div class="ls-status-label">กลุ่ม LINE</div>
                <div class="ls-status-value">—</div>
                <div class="ls-status-meta">รอข้อมูล</div>
            </div>
        </div>
    </div>

    <!-- ──────── Section banner: Config ──────── -->
    <div class="ls-section-banner" data-tone="config" id="ls-sec-config">
        <div class="ls-section-banner-icon"><i class="fa-solid fa-key"></i></div>
        <div class="ls-section-banner-text">
            <h2>Webhook & API Credentials</h2>
            <p>เชื่อมต่อ LINE Messaging API · ตั้งค่า Channel Token + Secret · ทดสอบส่งข้อความ</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- Left Column: Config -->
        <div class="lg:col-span-2 space-y-6">
            
            <!-- Webhook Info -->
            <div class="line-card bg-gradient-to-br from-slate-900 to-slate-800 border-none text-white shadow-xl overflow-hidden relative">
                <div class="absolute right-[-20px] top-[-20px] opacity-10 rotate-12">
                    <i class="fa-brands fa-line text-[120px]"></i>
                </div>
                <div class="relative z-10">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 bg-green-500 rounded-xl flex items-center justify-center shadow-lg shadow-green-500/20">
                            <i class="fa-solid fa-link text-white text-lg"></i>
                        </div>
                        <div>
                            <h3 class="font-black text-lg leading-tight">Webhook URL</h3>
                            <p class="text-[10px] text-green-400 font-bold uppercase tracking-widest">คัดลอกไปวางที่ LINE Developers</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 bg-black/30 p-4 rounded-2xl border border-white/10 group">
                        <code class="flex-1 font-mono text-sm text-blue-300 break-all" id="webhook_url_text_p"><?= $webhookUrl ?></code>
                        <button onclick="copyWebhookPartial()" class="p-2.5 bg-white/10 hover:bg-white/20 rounded-xl transition-all active:scale-95 flex-shrink-0">
                            <i id="copyIconP" class="fa-solid fa-copy text-sm"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- API Config Form -->
            <div class="line-card fx-tilt fx-tilt-light shadow-sm" data-tilt="3">
                <h2 class="font-black text-gray-900 mb-5 flex items-center gap-2">
                    <span class="w-8 h-8 bg-cyan-100 text-cyan-600 rounded-xl flex items-center justify-center">
                        <i class="fa-solid fa-key text-sm"></i>
                    </span>
                    LINE API Credentials
                </h2>

                <form id="lineFormP" class="space-y-5">
                    <?php csrf_field(); ?>
                    <div>
                        <label class="line-label">Channel Access Token</label>
                        <textarea name="LINE_MESSAGING_CHANNEL_ACCESS_TOKEN" id="line_token_p" class="line-input font-mono text-xs placeholder:text-slate-400" rows="3"
                                  placeholder="Long-lived access token..."><?= htmlspecialchars($secrets['LINE_MESSAGING_CHANNEL_ACCESS_TOKEN'] ?? '') ?></textarea>
                    </div>

                    <div>
                        <label class="line-label">Channel Secret</label>
                        <div class="relative">
                            <input type="password" name="LINE_MESSAGING_CHANNEL_SECRET" id="line_secret_p" class="line-input pr-10 placeholder:text-slate-400"
                                   value="<?= htmlspecialchars($secrets['LINE_MESSAGING_CHANNEL_SECRET'] ?? '') ?>"
                                   placeholder="Channel Secret">
                            <button type="button" onclick="toggleSecretP()" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                <i id="secretEyeP" class="fa-solid fa-eye-slash text-sm"></i>
                            </button>
                        </div>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <button type="button" onclick="saveLineConfigP()"
                                class="px-6 py-3 bg-gray-900 text-white rounded-xl font-black text-sm hover:opacity-90 transition-all active:scale-95 shadow-lg flex items-center gap-2">
                            <i class="fa-solid fa-floppy-disk"></i> บันทึกข้อมูล
                        </button>
                        <div id="saveStatusP" class="hidden flex items-center gap-2 text-sm font-bold text-emerald-600">
                            <i class="fa-solid fa-circle-check"></i> บันทึกแล้ว
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Right Column: Testing -->
        <div class="space-y-6">
            <div class="line-card fx-tilt fx-tilt-light shadow-sm border-t-4 border-t-green-500" data-tilt="3">
                <h2 class="font-black text-gray-900 mb-5 flex items-center gap-2">
                    <span class="w-8 h-8 bg-green-100 text-green-600 rounded-xl flex items-center justify-center">
                        <i class="fa-solid fa-paper-plane text-sm"></i>
                    </span>
                    Test Tool
                </h2>

                <div class="mb-5">
                    <label class="line-label">LINE User ID ผู้รับ</label>
                    <input type="text" id="toUserIdP" class="line-input font-mono text-sm placeholder:text-slate-400"
                           placeholder="Uxxxxxxxxxxxxxxxx..."
                           value="<?= htmlspecialchars($_prefillLineId) ?>">
                    <p class="text-[11px] text-slate-600 mt-2 font-medium leading-relaxed">
                        <i class="fa-solid fa-circle-info text-blue-500"></i> User ID ต้องเป็น ID จาก LINE OA (Messaging API) Channel นี้ ไม่ใช่ LINE Login Channel — ต้องเคย<strong>เพิ่ม OA เป็นเพื่อน</strong>ก่อน
                    </p>
                </div>

                <button onclick="sendTestLineP()" id="btnTestP"
                        class="w-full py-3 bg-[#06C755] text-white rounded-xl font-black text-sm hover:opacity-90 transition-all active:scale-[0.98] shadow-lg flex items-center justify-center gap-2">
                    <i class="fa-solid fa-flask"></i> ส่งข้อความทดสอบ
                </button>

                <div id="testResultP" class="hidden mt-4 p-4 rounded-xl text-xs font-semibold flex items-start gap-3"></div>
            </div>

            <!-- Helpful Links -->
            <div class="bg-blue-50 rounded-2xl p-5 border border-blue-100">
                <h4 class="text-blue-800 font-black text-xs uppercase tracking-wider mb-3">คู่มือเบื้องต้น</h4>
                <ul class="text-[11px] text-blue-700 space-y-2 font-bold">
                    <li><a href="https://developers.line.biz/console/" target="_blank" class="hover:underline flex items-center gap-2"><i class="fa-solid fa-external-link"></i> LINE Developers Console</a></li>
                    <li><a href="https://developers.line.biz/en/docs/messaging-api/overview/" target="_blank" class="hover:underline flex items-center gap-2"><i class="fa-solid fa-book"></i> API Documentation</a></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════ -->
    <!-- LINE Groups                                          -->
    <!-- ════════════════════════════════════════════════════ -->
    <div class="ls-section-banner" data-tone="groups" id="ls-sec-groups">
        <div class="ls-section-banner-icon"><i class="fa-solid fa-users"></i></div>
        <div class="ls-section-banner-text">
            <h2>กลุ่ม LINE ที่ OA อยู่ด้วย</h2>
            <p>OA ถูกเชิญเข้ากลุ่มไหนบ้าง · ตั้งกลุ่มหลักสำหรับ push SOS / ประกาศ · ทดสอบส่งข้อความเข้ากลุ่ม</p>
        </div>
    </div>

    <div class="line-card fx-tilt fx-tilt-light shadow-sm" data-tilt="3" style="border-top:4px solid #22c55e">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:18px">
            <div>
                <h3 style="font-weight:900;color:#0f172a;font-size:15px;margin-bottom:4px">
                    <i class="fa-solid fa-users" style="color:#22c55e;margin-right:6px"></i>กลุ่มที่ค้นพบ
                </h3>
                <p style="font-size:12px;color:#64748b;margin:0">
                    เมื่อ OA ถูกเชิญเข้ากลุ่ม ระบบจะบันทึก Group ID ไว้อัตโนมัติ — เลือกกลุ่มหลักสำหรับ push SOS / ประกาศ
                </p>
            </div>
            <button type="button" onclick="lineGroupsLoad()" class="flex items-center gap-2 px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-sm rounded-xl transition-colors">
                <i class="fa-solid fa-rotate-right"></i> รีเฟรช
            </button>
        </div>

        <!-- Group list -->
        <div id="lineGroupsList">
            <div class="flex items-center justify-center gap-3 py-8 text-slate-400">
                <i class="fa-solid fa-spinner fa-spin"></i>
                <span class="text-sm">กำลังโหลด...</span>
            </div>
        </div>

        <!-- How to add -->
        <div style="margin-top:16px;padding:14px 16px;background:#f0fdf4;border-radius:12px;border:1px solid #bbf7d0">
            <p style="font-size:11px;font-weight:700;color:#15803d;margin:0 0 6px">
                <i class="fa-solid fa-circle-info" style="margin-right:4px"></i>วิธีเพิ่มกลุ่ม
            </p>
            <ol style="font-size:11px;color:#166534;margin:0;padding-left:18px;line-height:1.8">
                <li>เปิด LINE → กลุ่มที่ต้องการ → เพิ่มสมาชิก</li>
                <li>ค้นหา LINE OA ของคลินิก แล้วเชิญเข้ากลุ่ม</li>
                <li>ระบบจะรับ <code style="background:#dcfce7;padding:1px 4px;border-radius:3px">join</code> event ผ่าน webhook และบันทึก Group ID ไว้อัตโนมัติ</li>
            </ol>
        </div>
    </div>

    <style>
        #lineGroupsList .group-card {
            border: 1.5px solid #e5e7eb; border-radius: 14px; padding: 14px 16px;
            margin-bottom: 10px; background: #fff; transition: border-color .15s, box-shadow .15s;
        }
        #lineGroupsList .group-card.is-default {
            border-color: #22c55e; background: #f0fdf4;
        }
        #lineGroupsList .group-card:hover { border-color: #94a3b8; box-shadow: 0 2px 8px rgba(0,0,0,.05); }
        #lineGroupsList .group-id { font-family: monospace; font-size: 11px; color: #64748b; word-break: break-all; }
    </style>

    <script>
    (function() {
        const AJAX_GROUPS = 'ajax_line_groups.php';
        const GROUPS_CSRF  = '<?= get_csrf_token() ?>';

        function renderGroups(groups, defaultId) {
            const el = document.getElementById('lineGroupsList');
            if (!groups || groups.length === 0) {
                el.innerHTML = `
                    <div style="text-align:center;padding:40px 20px">
                        <div style="font-size:48px;margin-bottom:12px">💬</div>
                        <p style="font-weight:700;color:#64748b;margin-bottom:4px">ยังไม่มีกลุ่มที่ค้นพบ</p>
                        <p style="font-size:12px;color:#94a3b8">เชิญ LINE OA เข้ากลุ่มตามวิธีด้านล่าง</p>
                    </div>`;
                return;
            }

            el.innerHTML = groups.map(g => {
                const isDefault = g.id === defaultId;
                const joinedDate = g.joined_at ? new Date(g.joined_at).toLocaleDateString('th-TH', { year:'numeric', month:'short', day:'numeric' }) : '—';
                const lastSeenDate = g.last_seen_at ? new Date(g.last_seen_at).toLocaleDateString('th-TH', { year:'numeric', month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' }) : '—';
                const typeLabel = g.type === 'room' ? '<span style="background:#e0f2fe;color:#0369a1;font-size:10px;font-weight:700;padding:2px 6px;border-radius:99px">Room</span>' : '<span style="background:#dcfce7;color:#15803d;font-size:10px;font-weight:700;padding:2px 6px;border-radius:99px">Group</span>';
                const defaultBadge = isDefault ? '<span style="background:#22c55e;color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px;margin-left:8px"><i class="fa-solid fa-star" style="margin-right:3px"></i>กลุ่มหลัก</span>' : '';
                const memberText = g.member_count != null ? `<span>${g.member_count} คน</span> · ` : '';

                return `<div class="group-card${isDefault ? ' is-default' : ''}" id="gc-${CSS.escape(g.id)}">
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap">
                        <div style="flex:1;min-width:0">
                            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:6px">
                                ${typeLabel}
                                <span style="font-weight:800;font-size:14px;color:#0f172a">${g.name || '(ไม่ทราบชื่อ)'}</span>
                                ${defaultBadge}
                            </div>
                            <div class="group-id">${g.id}</div>
                            <div style="font-size:11px;color:#94a3b8;margin-top:4px">
                                ${memberText}เข้าร่วม ${joinedDate} · พบล่าสุด ${lastSeenDate}
                            </div>
                        </div>
                        <div style="display:flex;gap:8px;flex-shrink:0">
                            ${!isDefault ? `<button type="button" onclick="lineGroupSetDefault('${g.id}')"
                                style="padding:6px 12px;border-radius:8px;border:1.5px solid #22c55e;background:#f0fdf4;color:#15803d;font-size:12px;font-weight:700;cursor:pointer;transition:.15s"
                                onmouseover="this.style.background='#dcfce7'" onmouseout="this.style.background='#f0fdf4'">
                                <i class="fa-regular fa-star" style="margin-right:4px"></i>ตั้งเป็นกลุ่มหลัก
                            </button>` : ''}
                            <button type="button" onclick="lineGroupTestPush('${g.id}', this)"
                                style="padding:6px 12px;border-radius:8px;border:1.5px solid #e5e7eb;background:#f8fafc;color:#374151;font-size:12px;font-weight:700;cursor:pointer;transition:.15s"
                                onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#f8fafc'">
                                <i class="fa-solid fa-paper-plane" style="margin-right:4px"></i>ทดสอบส่ง
                            </button>
                        </div>
                    </div>
                </div>`;
            }).join('');
        }

        window.lineGroupsLoad = async function() {
            const el = document.getElementById('lineGroupsList');
            el.innerHTML = `<div class="flex items-center justify-center gap-3 py-8 text-slate-400"><i class="fa-solid fa-spinner fa-spin"></i><span class="text-sm">กำลังโหลด...</span></div>`;
            try {
                const r = await fetch(AJAX_GROUPS + '?action=list').then(x => x.json());
                if (r.ok) renderGroups(r.groups, r.default_id);
                else el.innerHTML = `<p style="color:#ef4444;text-align:center;padding:20px;font-size:13px">เกิดข้อผิดพลาด: ${r.error || 'unknown'}</p>`;
            } catch(e) {
                el.innerHTML = `<p style="color:#ef4444;text-align:center;padding:20px;font-size:13px">โหลดไม่สำเร็จ</p>`;
            }
        };

        window.lineGroupSetDefault = async function(groupId) {
            const fd = new FormData();
            fd.append('csrf_token', GROUPS_CSRF);
            fd.append('action', 'set_default');
            fd.append('group_id', groupId);
            const r = await fetch(AJAX_GROUPS, { method: 'POST', body: fd }).then(x => x.json());
            if (r.ok) {
                await Swal.fire({ icon: 'success', title: 'ตั้งกลุ่มหลักสำเร็จ', timer: 1800, showConfirmButton: false });
                lineGroupsLoad();
            } else {
                Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: r.error || '' });
            }
        };

        window.lineGroupTestPush = async function(groupId, btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" style="margin-right:4px"></i>กำลังส่ง...';
            const fd = new FormData();
            fd.append('csrf_token', GROUPS_CSRF);
            fd.append('action', 'test_push');
            fd.append('group_id', groupId);
            const r = await fetch(AJAX_GROUPS, { method: 'POST', body: fd }).then(x => x.json());
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-paper-plane" style="margin-right:4px"></i>ทดสอบส่ง';
            if (r.ok) {
                Swal.fire({ icon: 'success', title: 'ส่งสำเร็จ ✅', text: 'ตรวจสอบกลุ่ม LINE ได้เลย', timer: 2000, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'ส่งไม่สำเร็จ', text: r.line_error || r.error || 'ไม่ทราบสาเหตุ' });
            }
        };

        // auto-load on page ready
        lineGroupsLoad();
    })();
    </script>

    <!-- ════════════════════════════════════════════════════ -->
    <!-- สถิติการส่งข้อความ                                   -->
    <!-- ════════════════════════════════════════════════════ -->
    <div class="ls-section-banner" data-tone="stats" id="ls-sec-stats">
        <div class="ls-section-banner-icon"><i class="fa-solid fa-chart-column"></i></div>
        <div class="ls-section-banner-text">
            <h2>สถิติการส่งข้อความ</h2>
            <p>โควต้าต่อเดือน · ใช้ไปกี่ข้อความ · breakdown ตามประเภท (Broadcast / Push / Multicast …) · กราฟ trend</p>
        </div>
    </div>

    <!-- Date Picker + Refresh -->
    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-bottom:20px">
        <div style="display:flex;align-items:center;gap:8px;background:#fff;border:1.5px solid #e5e7eb;border-radius:12px;padding:8px 14px;box-shadow:0 1px 4px rgba(0,0,0,.05)">
            <i class="fa-regular fa-calendar" style="color:#2e9e63;font-size:13px"></i>
            <label style="font-size:11px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em">วันที่</label>
            <input type="date" id="ls-date"
                   style="font-size:13px;font-weight:700;color:#1e293b;border:none;outline:none;background:transparent;cursor:pointer"
                   max="<?= date('Y-m-d', strtotime('-1 day')) ?>"
                   value="<?= date('Y-m-d', strtotime('-1 day')) ?>">
        </div>
        <button id="ls-btn-load"
                style="display:flex;align-items:center;gap:6px;padding:9px 18px;border-radius:11px;font-size:13px;font-weight:800;color:#fff;border:none;cursor:pointer;background:linear-gradient(135deg,#2e9e63,#3bba7a);box-shadow:0 4px 12px rgba(46,158,99,.3)">
            <i class="fa-solid fa-rotate"></i> โหลดสถิติ
        </button>
        <div id="ls-status" style="display:none;font-size:12px;font-weight:800;padding:5px 12px;border-radius:20px"></div>
        <div id="ls-spinner" style="display:none"><i class="fa-solid fa-circle-notch fa-spin" style="color:#2e9e63"></i></div>
    </div>

    <!-- Error -->
    <div id="ls-error" style="display:none;background:#fff1f2;border:1.5px solid #fecdd3;border-radius:14px;padding:14px 18px;font-size:13px;color:#be123c;align-items:flex-start;gap:10px;margin-bottom:20px">
        <i class="fa-solid fa-triangle-exclamation" style="color:#f43f5e;flex-shrink:0"></i>
        <span id="ls-error-msg"></span>
    </div>

    <!-- Quota Cards -->
    <p style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.2em;color:#94a3b8;margin-bottom:12px">โควต้าข้อความ (เดือนนี้)</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:16px">
        <?php foreach ([
            ['id'=>'ls-q-limit','icon'=>'fa-envelope',    'color'=>'#2e9e63','bg'=>'#e8f8f0','label'=>'โควต้าต่อเดือน'],
            ['id'=>'ls-q-used', 'icon'=>'fa-paper-plane', 'color'=>'#2563eb','bg'=>'#eff6ff','label'=>'ส่งไปแล้ว'],
            ['id'=>'ls-q-left', 'icon'=>'fa-gauge',       'color'=>'#d97706','bg'=>'#fffbeb','label'=>'คงเหลือ'],
        ] as $c): ?>
        <div class="line-card" style="display:flex;align-items:center;gap:14px;margin-bottom:0;padding:16px">
            <div style="width:42px;height:42px;border-radius:13px;background:<?= $c['bg'] ?>;color:<?= $c['color'] ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:16px">
                <i class="fa-solid <?= $c['icon'] ?>"></i>
            </div>
            <div>
                <div id="<?= $c['id'] ?>" style="font-size:22px;font-weight:900;color:#0f172a;line-height:1">—</div>
                <div style="font-size:11px;font-weight:600;color:#94a3b8;margin-top:3px"><?= $c['label'] ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Quota progress bar -->
    <div id="ls-quota-bar-wrap" style="display:none" class="line-card" style="padding:14px 18px;margin-bottom:20px">
        <div style="display:flex;justify-content:space-between;font-size:12px;font-weight:700;color:#64748b;margin-bottom:8px">
            <span>การใช้งาน</span><span id="ls-quota-pct">0%</span>
        </div>
        <div style="background:#f1f5f9;border-radius:99px;height:10px;overflow:hidden">
            <div id="ls-quota-bar" style="height:10px;border-radius:99px;width:0%;background:linear-gradient(90deg,#2e9e63,#86efac);transition:width .7s"></div>
        </div>
    </div>

    <!-- Delivery Cards -->
    <p id="ls-delivery-label" style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.2em;color:#94a3b8;margin-bottom:12px">
        สถิติการส่งข้อความ — <?= date('d/m/Y', strtotime('-1 day')) ?>
    </p>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:12px;margin-bottom:24px">
        <?php foreach ([
            ['key'=>'broadcast',        'icon'=>'fa-bullhorn',       'color'=>'#7c3aed','bg'=>'#f5f3ff','label'=>'Broadcast (OA)'],
            ['key'=>'targeting',        'icon'=>'fa-crosshairs',     'color'=>'#0891b2','bg'=>'#ecfeff','label'=>'Targeting (OA)'],
            ['key'=>'apiBroadcast',     'icon'=>'fa-satellite-dish', 'color'=>'#be185d','bg'=>'#fdf2f8','label'=>'API Broadcast'],
            ['key'=>'apiPush',          'icon'=>'fa-bell',           'color'=>'#2563eb','bg'=>'#eff6ff','label'=>'API Push'],
            ['key'=>'apiMulticast',     'icon'=>'fa-users',          'color'=>'#059669','bg'=>'#ecfdf5','label'=>'API Multicast'],
            ['key'=>'apiNarrowcast',    'icon'=>'fa-filter',         'color'=>'#d97706','bg'=>'#fffbeb','label'=>'API Narrowcast'],
            ['key'=>'apiReply',         'icon'=>'fa-reply',          'color'=>'#16a34a','bg'=>'#f0fdf4','label'=>'API Reply'],
            ['key'=>'pnpNoticeMessage', 'icon'=>'fa-mobile-screen',  'color'=>'#6b7280','bg'=>'#f9fafb','label'=>'PNP Notice'],
        ] as $d): ?>
        <div data-ls-key="<?= $d['key'] ?>" class="line-card" style="margin-bottom:0;padding:14px">
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

    <!-- Charts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px">
        <div class="line-card" style="margin-bottom:0">
            <p style="font-size:13px;font-weight:900;color:#374151;margin-bottom:16px">ปริมาณข้อความตามประเภท</p>
            <div style="position:relative;height:240px"><canvas id="ls-bar-chart"></canvas></div>
        </div>
        <div class="line-card" style="margin-bottom:0;display:flex;flex-direction:column">
            <p style="font-size:13px;font-weight:900;color:#374151;margin-bottom:16px">อัตราใช้โควต้า</p>
            <div style="flex:1;display:flex;align-items:center;justify-content:center;max-height:240px">
                <canvas id="ls-donut-chart"></canvas>
            </div>
        </div>
    </div>

</div>

<script>
function copyWebhookPartial() {
    const text = document.getElementById('webhook_url_text_p').innerText;
    const ico  = document.getElementById('copyIconP');
    navigator.clipboard.writeText(text).then(() => {
        ico.className = 'fa-solid fa-check text-green-400';
        setTimeout(() => ico.className = 'fa-solid fa-copy text-sm', 2000);
    });
}

function toggleSecretP() {
    const el = document.getElementById('line_secret_p');
    const ico = document.getElementById('secretEyeP');
    if (el.type === 'password') {
        el.type = 'text';
        ico.className = 'fa-solid fa-eye text-sm';
    } else {
        el.type = 'password';
        ico.className = 'fa-solid fa-eye-slash text-sm';
    }
}

function saveLineConfigP() {
    const fd = new FormData();
    fd.append('csrf_token', '<?= get_csrf_token() ?>');
    fd.append('action', 'save');
    fd.append('LINE_MESSAGING_CHANNEL_ACCESS_TOKEN', document.getElementById('line_token_p').value);
    fd.append('LINE_MESSAGING_CHANNEL_SECRET', document.getElementById('line_secret_p').value);

    fetch('ajax_test_line.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            const el = document.getElementById('saveStatusP');
            el.classList.remove('hidden');
            if (data.ok) {
                el.className = 'flex items-center gap-2 text-sm font-bold text-emerald-600';
                el.innerHTML = '<i class="fa-solid fa-circle-check"></i> ' + data.message;
            } else {
                el.className = 'flex items-center gap-2 text-sm font-bold text-red-500';
                el.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' + data.error;
            }
            setTimeout(() => el.classList.add('hidden'), 4000);
        });
}

function sendTestLineP() {
    const userId = document.getElementById('toUserIdP').value.trim();
    const btn = document.getElementById('btnTestP');
    const result = document.getElementById('testResultP');

    if (!userId) { Swal.fire('Error', 'กรุณาระบุ User ID', 'error'); return; }

    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> กำลังส่ง...';
    result.classList.add('hidden');

    const fd = new FormData();
    fd.append('csrf_token', '<?= get_csrf_token() ?>');
    fd.append('action', 'test');
    fd.append('to_user_id', userId);
    fd.append('LINE_MESSAGING_CHANNEL_ACCESS_TOKEN', document.getElementById('line_token_p').value);

    fetch('ajax_test_line.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            result.classList.remove('hidden');
            if (data.ok) {
                result.className = 'mt-4 p-4 rounded-xl text-xs font-semibold flex items-start gap-3 bg-emerald-50 border border-emerald-100 text-emerald-700';
                result.innerHTML = '<i class="fa-solid fa-circle-check mt-0.5 shrink-0"></i><span>' + data.message + '</span>';
                Swal.fire('สำเร็จ!', data.message, 'success');
            } else {
                result.className = 'mt-4 p-4 rounded-xl text-xs font-semibold flex items-start gap-3 bg-red-50 border border-red-100 text-red-600';
                result.innerHTML = '<i class="fa-solid fa-circle-exclamation mt-0.5 shrink-0"></i><span>' + data.error + '</span>';
                Swal.fire('ล้มเหลว', data.error, 'error');
            }
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-flask"></i> ส่งข้อความทดสอบ';
        });
}

// ── LINE Stats ───────────────────────────────────────────────────────────────
(function () {
    'use strict';

    var AJAX   = 'ajax_line_stats.php';
    var barChart = null, donutChart = null;
    var KEYS   = ['broadcast','targeting','apiBroadcast','apiPush','apiMulticast','apiNarrowcast','apiReply','pnpNoticeMessage'];
    var LABELS = ['Broadcast (OA)','Targeting (OA)','API Broadcast','API Push','API Multicast','API Narrowcast','API Reply','PNP Notice'];
    var COLORS = ['#7c3aed','#0891b2','#be185d','#2563eb','#059669','#d97706','#16a34a','#6b7280'];

    function fmt(n) { return (n == null || n === '') ? '—' : Number(n).toLocaleString('th-TH'); }

    function spin(on) {
        document.getElementById('ls-spinner').style.display = on ? 'inline' : 'none';
        document.getElementById('ls-btn-load').disabled = on;
    }

    function setStatus(text, type) {
        var el = document.getElementById('ls-status');
        el.textContent = text;
        el.style.display = 'inline-block';
        el.style.background = type==='ready' ? '#dcfce7' : type==='unready' ? '#fef9c3' : type==='err' ? '#fee2e2' : '#f1f5f9';
        el.style.color      = type==='ready' ? '#15803d' : type==='unready' ? '#a16207' : type==='err' ? '#be123c' : '#64748b';
    }

    function showError(msg) {
        var el = document.getElementById('ls-error');
        document.getElementById('ls-error-msg').textContent = msg;
        el.style.display = 'flex';
    }
    function hideError() { document.getElementById('ls-error').style.display = 'none'; }

    function buildDonut(used, left, limit) {
        if (typeof Chart === 'undefined') return;
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
        if (typeof Chart === 'undefined') return;
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

    function loadStats() {
        hideError();
        spin(true);
        var dateVal   = document.getElementById('ls-date').value;
        var dateParam = dateVal.replace(/-/g,'');
        var parts     = dateVal.split('-');
        document.getElementById('ls-delivery-label').textContent =
            'สถิติการส่งข้อความ — '+parts[2]+'/'+parts[1]+'/'+parts[0];

        Promise.all([
            fetch(AJAX+'?action=quota').then(function(r){ return r.json(); }),
            fetch(AJAX+'?action=delivery&date='+encodeURIComponent(dateParam)).then(function(r){ return r.json(); })
        ]).then(function(res) {
            // Quota
            var qRes = res[0];
            if (qRes.status === 'ok') {
                var q    = qRes.quota||{}, c = qRes.consumption||{};
                var used = Number(c.totalUsage||0);
                var limit = q.type==='limited' ? Number(q.value||0) : null;
                var left  = limit !== null ? Math.max(0,limit-used) : null;
                document.getElementById('ls-q-limit').textContent = limit !== null ? fmt(limit) : 'ไม่จำกัด';
                document.getElementById('ls-q-used').textContent  = fmt(used);
                document.getElementById('ls-q-left').textContent  = left !== null ? fmt(left) : '∞';
                if (limit !== null && limit > 0) {
                    var pct = Math.round((used/limit)*100);
                    var bw = document.getElementById('ls-quota-bar-wrap');
                    bw.style.display = 'block';
                    document.getElementById('ls-quota-bar').style.width = pct+'%';
                    document.getElementById('ls-quota-pct').textContent = pct+'%';
                    var bar = document.getElementById('ls-quota-bar');
                    bar.style.background = pct>=90 ? 'linear-gradient(90deg,#ef4444,#fca5a5)'
                                         : pct>=70 ? 'linear-gradient(90deg,#d97706,#fcd34d)'
                                         : 'linear-gradient(90deg,#2e9e63,#86efac)';
                }
                buildDonut(used, left, limit);
            }
            // Delivery
            var dRes = res[1];
            if (dRes.status === 'ok') {
                var d = dRes.data||{};
                if      (d.status==='ready')           setStatus('ข้อมูลพร้อม','ready');
                else if (d.status==='unready')         setStatus('ข้อมูลยังไม่พร้อม','unready');
                else if (d.status==='out_of_service')  setStatus('ไม่มีข้อมูลสำหรับวันนี้','err');
                else if (d._error)                     { setStatus('เกิดข้อผิดพลาด','err'); showError(d._error); }
                KEYS.forEach(function(key) {
                    var card = document.querySelector('[data-ls-key="'+key+'"]');
                    if (card) card.querySelector('.ls-dval').textContent = d[key]!=null ? fmt(d[key]) : '—';
                });
                buildBar(d);
            } else {
                showError('โหลดข้อมูล delivery ไม่สำเร็จ');
            }
        }).catch(function(){ showError('ไม่สามารถเชื่อมต่อ API ได้'); })
          .finally(function(){ spin(false); });
    }

    document.getElementById('ls-btn-load').addEventListener('click', loadStats);
    document.getElementById('ls-date').addEventListener('change', loadStats);

    // โหลดอัตโนมัติเมื่อ Chart.js พร้อม
    if (document.readyState === 'complete') {
        loadStats();
    } else {
        window.addEventListener('load', loadStats);
    }
})();
</script>

<!-- ════════════ Rich Menu (per-user binding) ════════════ -->
<div class="max-w-4xl mx-auto px-4 md:px-6">
    <div class="ls-section-banner" data-tone="richmenu" id="ls-sec-richmenu">
        <div class="ls-section-banner-icon"><i class="fa-solid fa-bars-staggered"></i></div>
        <div class="ls-section-banner-text">
            <h2>Rich Menu — สลับเมนูตามสถานะผู้ใช้</h2>
            <p>เลือก rich menu จาก LINE OA · per-user binding (guest/member) · sync ผู้ใช้เก่า · auto-compress ภาพ</p>
        </div>
    </div>
</div>
<div class="max-w-4xl mx-auto px-4 md:px-6 pb-12 mt-2">
    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6" id="rmSection">
        <div class="flex items-start justify-between gap-4 mb-1 flex-wrap">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-emerald-50 text-emerald-600 rounded-2xl border border-emerald-100 flex items-center justify-center">
                    <i class="fa-solid fa-bars-staggered"></i>
                </div>
                <div>
                    <h3 class="text-lg font-black text-slate-800">Rich Menu — สลับตามสถานะผู้ใช้</h3>
                    <p class="text-xs text-slate-500 font-medium">guest = ยังไม่ลงทะเบียน · member = มี record ใน sys_users + line_user_id</p>
                </div>
            </div>
            <div class="rm-power-switch" id="rmPowerSwitch" title="เปิด/ปิด auto-sync rich menu ทั้งระบบ">
                <span class="rm-power-led" aria-hidden="true"></span>
                <span class="rm-power-label">AUTO-SYNC</span>
                <button type="button" class="rm-toggle" onclick="rmToggleEnabled()" id="rmToggleBtn" aria-pressed="false" aria-label="เปิดปิดการทำงาน Rich Menu auto-sync">
                    <span class="rm-toggle-track" aria-hidden="true">
                        <span class="rm-toggle-thumb"></span>
                    </span>
                    <span class="rm-toggle-state" id="rmToggleState">OFF</span>
                </button>
            </div>
        </div>
        <div class="rm-disabled-banner" id="rmDisabledBanner">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span>ปิดอยู่ — webhook จะไม่ link rich menu ให้ user ใหม่ · user ที่ link อยู่แล้วยังเห็นเมนูเดิม</span>
        </div>

        <div class="bg-sky-50 border border-sky-100 rounded-2xl p-3 mt-4 text-xs text-sky-800 font-medium flex gap-2 items-start">
            <i class="fa-solid fa-circle-info text-sky-500 mt-0.5"></i>
            <div>
                สร้าง rich menu ผ่าน LINE OA Console แล้วเอา <span class="font-mono font-black">richMenuId</span>
                (รูปแบบ <span class="font-mono">richmenu-xxxxxxxxxxxx</span>) มาวางช่องด้านล่าง
                · ระบบจะ link เมนูที่ถูกให้ user ทุกคนอัตโนมัติเมื่อ follow / สมัครเสร็จ
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-5">
            <div>
                <label class="block text-xs font-black uppercase tracking-widest text-slate-500 mb-1.5">
                    <i class="fa-solid fa-user-plus text-amber-500 mr-1"></i> Guest Rich Menu ID
                </label>
                <input type="text" id="rmGuestId" placeholder="richmenu-xxxxxxxxxxxxxxxxxxxxxxxxxx"
                    class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-mono text-slate-800 outline-none focus:border-amber-400 focus:bg-white">
                <p class="text-[10px] text-slate-400 font-medium mt-1">สำหรับผู้ใช้ที่ยังไม่ลงทะเบียน (เมนูจะมีปุ่ม "สมัครสมาชิก")</p>
            </div>
            <div>
                <label class="block text-xs font-black uppercase tracking-widest text-slate-500 mb-1.5">
                    <i class="fa-solid fa-user-check text-emerald-500 mr-1"></i> Member Rich Menu ID
                </label>
                <input type="text" id="rmMemberId" placeholder="richmenu-xxxxxxxxxxxxxxxxxxxxxxxxxx"
                    class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-mono text-slate-800 outline-none focus:border-emerald-400 focus:bg-white">
                <p class="text-[10px] text-slate-400 font-medium mt-1">สำหรับผู้ใช้ที่ลงทะเบียนแล้ว (เมนูเต็ม)</p>
            </div>
        </div>

        <!-- ──────── Rich menus picker ──────── -->
        <div class="mt-5">
            <div class="flex items-center justify-between mb-2 flex-wrap gap-2">
                <h4 class="text-xs font-black uppercase tracking-widest text-slate-500 inline-flex items-center gap-1.5">
                    <i class="fa-solid fa-list-ul text-emerald-500"></i>
                    Rich menus ของ OA นี้ — กดปุ่มเพื่อวางลงช่องด้านบน
                </h4>
                <button type="button" onclick="rmRefreshList()" class="text-[10px] font-black text-slate-500 hover:text-emerald-600 inline-flex items-center gap-1 px-2 py-1 rounded-md hover:bg-slate-100 transition">
                    <i class="fa-solid fa-rotate" id="rmRefreshIcon"></i> รีเฟรช
                </button>
            </div>
            <div id="rmMenuCards" class="space-y-2"></div>
            <div id="rmMenuListEmpty" class="hidden text-[11px] text-slate-400 font-medium italic py-4 text-center bg-slate-50 border border-dashed border-slate-200 rounded-xl">
                <i class="fa-solid fa-circle-info mr-1"></i>
                ยังไม่มี rich menu — สร้างใน LINE OA Console หรือใช้ "สร้าง Rich Menu" ด้านล่าง
            </div>
            <div id="rmMenuListError" class="hidden text-[11px] text-rose-600 font-medium bg-rose-50 border border-rose-100 rounded-xl px-3 py-2"></div>
        </div>

        <div class="flex flex-wrap gap-2 mt-4 items-center">
            <button onclick="rmSaveIds()" class="px-4 py-2 rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-black inline-flex items-center gap-1.5 transition-colors">
                <i class="fa-solid fa-floppy-disk"></i> บันทึก ID
            </button>
            <button onclick="rmSetDefault('guest')" class="px-4 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-white text-xs font-black inline-flex items-center gap-1.5 transition-colors">
                <i class="fa-solid fa-globe"></i> ตั้ง Guest เป็น default
            </button>
            <button onclick="rmSetDefault('clear')" class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-black inline-flex items-center gap-1.5 border border-slate-200 transition-colors">
                <i class="fa-solid fa-xmark"></i> ลบ default
            </button>
            <label class="inline-flex items-center gap-1.5 text-[11px] font-bold text-slate-500 ml-auto cursor-pointer select-none" title="ข้ามการเรียก LINE API ตรวจว่า ID มีอยู่จริง (เร็วขึ้น แต่ใส่ ID ผิดแล้วจะไม่รู้)">
                <input type="checkbox" id="rmSkipVerify" class="accent-emerald-500">
                ข้าม verify กับ LINE
            </label>
        </div>

        <!-- ──────── Bulk sync bar (เด่นๆ — ใช้บ่อยเมื่อเปลี่ยน menu) ──────── -->
        <div class="rm-bulk-bar mt-5">
            <div class="rm-bulk-info">
                <div class="rm-bulk-info-title">
                    <i class="fa-solid fa-people-arrows"></i>
                    Sync rich menu ให้ user เก่า
                </div>
                <p class="rm-bulk-info-desc">
                    หลังเปลี่ยน Member/Guest ID ต้อง sync เพื่อให้ user ที่ link เมนูเก่าอยู่เห็นเมนูใหม่ ·
                    auto-detect member vs guest ตาม DB · batch 50/รอบ · LINE rate limit 100 req/sec
                </p>
            </div>
            <div class="rm-bulk-actions">
                <button type="button" onclick="rmSyncAll()" class="rm-bulk-btn rm-bulk-btn-primary">
                    <i class="fa-solid fa-arrows-spin"></i>
                    <span>Sync ทุก user</span>
                </button>
                <button type="button" onclick="rmUnlinkAll()" class="rm-bulk-btn rm-bulk-btn-danger"
                    title="ปลดเมนูจาก user ทุกคน → กลับไปใช้เมนู Default จาก LINE OA Console">
                    <i class="fa-solid fa-link-slash"></i>
                    <span>Unlink ทุก user</span>
                </button>
                <button type="button" onclick="rmShowAudit()" class="rm-bulk-btn rm-bulk-btn-ghost">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                    <span>Audit log</span>
                </button>
            </div>
        </div>

        <details class="mt-5 group">
            <summary class="cursor-pointer text-xs font-black text-slate-600 hover:text-slate-800 list-none inline-flex items-center gap-1.5">
                <i class="fa-solid fa-chevron-right group-open:rotate-90 transition-transform text-[10px]"></i>
                เครื่องมือทดสอบ / ซิงค์
            </summary>
            <div class="mt-3 pt-3 border-t border-slate-100 space-y-3">
                <div>
                    <label class="block text-[11px] font-black uppercase tracking-widest text-slate-500 mb-1.5">ทดสอบ sync เมนูให้ user คนเดียว</label>
                    <div class="flex flex-wrap gap-2">
                        <input type="text" id="rmTestUid" placeholder="lineUserId (Uxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx)"
                            class="flex-1 min-w-[260px] px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono text-slate-800 outline-none">
                        <select id="rmSyncMode" class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-black text-slate-700 outline-none">
                            <option value="auto">Auto (ตรวจ DB)</option>
                            <option value="guest">Force Guest</option>
                            <option value="member">Force Member</option>
                            <option value="unlink">Unlink (เห็น default)</option>
                        </select>
                        <button onclick="rmSyncUser()" class="px-3 py-2 rounded-xl bg-sky-500 hover:bg-sky-600 text-white text-xs font-black inline-flex items-center gap-1">
                            <i class="fa-solid fa-arrows-rotate"></i> ทดสอบ
                        </button>
                    </div>
                    <p class="text-[10px] text-slate-400 font-medium mt-1">
                        <span class="font-black">Force Guest/Member</span> = ไม่ดู DB (ทดสอบเห็นเมนูแต่ละแบบกับ user ที่อยู่ในระบบแล้ว) ·
                        <span class="font-black">Unlink</span> = ลบ binding → fallback default
                    </p>
                </div>

                <!-- Lookup Console rich menu ID -->
                <div class="pt-3 border-t border-slate-100 mt-2">
                    <p class="text-[11px] font-black uppercase tracking-widest text-slate-500 mb-1.5">
                        <i class="fa-solid fa-magnifying-glass text-emerald-500"></i> ดู ID ของ Console rich menu
                    </p>
                    <p class="text-[10px] text-slate-500 font-medium mb-2 leading-relaxed">
                        Rich menu ที่สร้างใน LINE OA Console ก็มี ID — ใช้กับระบบนี้ได้ ถ้าหา ID เจอ
                    </p>

                    <div class="flex flex-wrap gap-2 items-center">
                        <button onclick="rmLookupDefault()" class="px-3 py-2 rounded-xl bg-emerald-50 hover:bg-emerald-100 text-emerald-700 text-xs font-black border border-emerald-200 inline-flex items-center gap-1.5">
                            <i class="fa-solid fa-globe"></i> ดู ID ของ default ปัจจุบัน
                        </button>
                        <span class="text-[10px] text-slate-400">— ต้องตั้ง Console rich menu เป็น default ก่อน</span>
                    </div>

                    <div class="flex gap-2 mt-2">
                        <input type="text" id="rmLookupUid" placeholder="lineUserId ของ admin (Uxxxx...) — ดู rich menu ที่ user คนนี้เห็น"
                            class="flex-1 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono text-slate-800 outline-none">
                        <button onclick="rmLookupUser()" class="px-3 py-2 rounded-xl bg-emerald-50 hover:bg-emerald-100 text-emerald-700 text-xs font-black border border-emerald-200 inline-flex items-center gap-1.5">
                            <i class="fa-solid fa-user-magnifying-glass"></i> ดู ID
                        </button>
                    </div>
                    <p class="text-[10px] text-slate-400 font-medium mt-1">— add bot เป็นเพื่อน + เห็น Console rich menu ในเชต → คัด userId ตัวเองมาดูได้</p>
                </div>
            </div>
        </details>
    </div>
</div>

<script>
(function(){
    const CSRF = '<?= get_csrf_token() ?>';

    async function rmPost(action, data) {
        const fd = new FormData();
        fd.append('action', action);
        fd.append('csrf_token', CSRF);
        Object.entries(data || {}).forEach(([k, v]) => fd.append(k, v ?? ''));
        const r = await fetch('ajax_line_richmenu.php', { method: 'POST', body: fd });
        return r.json();
    }
    async function rmGet() {
        const r = await fetch('ajax_line_richmenu.php?action=get');
        return r.json();
    }

    window.rmSaveIds = async function() {
        const guest  = document.getElementById('rmGuestId').value.trim();
        const member = document.getElementById('rmMemberId').value.trim();
        const skip   = document.getElementById('rmSkipVerify')?.checked ? '1' : '';

        if (!guest && !member) {
            const c = await Swal.fire({
                icon: 'warning',
                title: 'ทั้งสองช่องว่าง',
                text: 'จะบันทึกเป็นค่าว่างทั้งคู่ — user ทุกคนจะไม่เห็น rich menu (ระบบจะ fallback ไป default ถ้ามี). ยืนยัน?',
                showCancelButton: true,
                confirmButtonText: 'บันทึกค่าว่าง',
                cancelButtonText: 'ยกเลิก',
            });
            if (!c.isConfirmed) return;
        }

        Swal.fire({ title: 'กำลังบันทึก...', didOpen: () => Swal.showLoading(), allowOutsideClick: false });
        const r = await rmPost('save_ids', { guest_id: guest, member_id: member, skip_verify: skip });
        Swal.fire({
            icon: r.ok ? 'success' : 'error',
            title: r.ok ? 'บันทึกแล้ว' : 'บันทึกไม่สำเร็จ',
            text: r.message || '',
            timer: r.ok ? 1800 : undefined,
            showConfirmButton: !r.ok,
        });
        // Focus ช่องที่ผิดถ้ามี field hint
        if (!r.ok && r.field) {
            const id = r.field === 'guest' ? 'rmGuestId' : 'rmMemberId';
            document.getElementById(id)?.focus();
        }
    };

    window.rmSetDefault = async function(target) {
        const c = await Swal.fire({
            title: target === 'clear' ? 'ลบ default richmenu?' : `ตั้ง ${target} เป็น default ของทุกคน?`,
            text: target === 'clear' ? 'ผู้ใช้ใหม่จะไม่เห็น rich menu' : 'ผู้ใช้ใหม่ที่ add friend จะเห็นเมนูนี้',
            icon: 'question', showCancelButton: true,
            confirmButtonText: 'ตกลง', cancelButtonText: 'ยกเลิก',
        });
        if (!c.isConfirmed) return;
        const r = await rmPost('set_default', { target });
        Swal.fire({
            icon: r.ok ? 'success' : 'error',
            title: r.ok ? 'สำเร็จ' : 'ล้มเหลว',
            text: r.message || '',
        });
    };

    window.rmSyncUser = async function() {
        const uid = document.getElementById('rmTestUid').value.trim();
        const mode = document.getElementById('rmSyncMode')?.value || 'auto';
        if (!uid) { Swal.fire({ icon: 'warning', title: 'กรุณาใส่ lineUserId' }); return; }
        const r = await rmPost('sync_user', { line_user_id: uid, mode });
        Swal.fire({
            icon: r.ok ? 'success' : 'error',
            title: r.ok
                ? (r.state === 'unlinked' ? 'Unlink สำเร็จ' : `Linked → ${r.state}`)
                : 'ล้มเหลว',
            text: r.message || '',
            timer: r.ok ? 2000 : undefined,
            showConfirmButton: !r.ok,
        });
    };

    window.rmSyncAll = async function() {
        const c = await Swal.fire({
            title: 'Sync ทุก user?',
            html: `
                <div style="text-align:left;font-size:13px;color:#475569;line-height:1.6">
                    ระบบจะวนทุก user ที่มี <code>line_user_id</code> ใน DB →
                    auto-detect ว่าเป็น <b style="color:#15803d">member</b> (มี record ใน sys_users) หรือ <b style="color:#92400e">guest</b> →
                    link rich menu ตามนั้น
                    <div style="margin-top:10px;padding:8px 10px;background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;font-size:11.5px;color:#78350f">
                        <i class="fa-solid fa-circle-info"></i>
                        การทำงานนี้จะ <b>เขียนทับ rich menu ที่ user link อยู่ปัจจุบัน</b> · batch 50/รอบ
                    </div>
                </div>
            `,
            icon: 'warning', showCancelButton: true,
            confirmButtonText: '<i class="fa-solid fa-arrows-spin"></i> เริ่ม Sync',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#7c3aed',
        });
        if (!c.isConfirmed) return;

        let offset = 0, totalOK = 0, totalFail = 0, total = 0, done = false;
        Swal.fire({
            title: 'กำลัง sync...',
            html: '<div id="rmSyncProg" style="font-size:13px;color:#475569">เริ่มต้น…</div>',
            allowOutsideClick: false,
            showConfirmButton: false,
        });
        const setProg = (msg) => {
            const el = document.getElementById('rmSyncProg');
            if (el) el.innerHTML = msg;
        };

        try {
            while (!done) {
                const r = await rmPost('sync_all', { offset });
                if (!r.ok) {
                    Swal.fire({ icon: 'error', title: 'ล้มเหลว', text: r.message || '' });
                    return;
                }
                total     = r.total;
                totalOK   += r.batch_ok;
                totalFail += r.batch_fail;
                offset    = r.processed;
                done      = r.done;

                const pct = total ? Math.min(100, Math.round((offset / total) * 100)) : 100;
                setProg(`
                    <div style="margin-bottom:8px"><b>${offset.toLocaleString()}</b> / ${total.toLocaleString()} (${pct}%)</div>
                    <div style="background:#e2e8f0;border-radius:6px;height:8px;overflow:hidden;margin-bottom:8px">
                        <div style="width:${pct}%;height:100%;background:#8b5cf6;transition:width .2s"></div>
                    </div>
                    <div style="font-size:11px;color:#64748b">สำเร็จ <b style="color:#16a34a">${totalOK}</b> · ล้มเหลว <b style="color:#dc2626">${totalFail}</b></div>
                `);
                // เล็กน้อยกัน rate limit
                if (!done) await new Promise(res => setTimeout(res, 250));
            }

            Swal.fire({
                icon: totalFail > 0 ? 'warning' : 'success',
                title: 'เสร็จสิ้น',
                html: `รวม ${total} คน · สำเร็จ <b>${totalOK}</b> · ล้มเหลว <b>${totalFail}</b>`,
            });
        } catch (e) {
            Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: e.message || String(e) });
        }
    };

    window.rmUnlinkAll = async function() {
        // Step 1 — warning + explain consequence
        const c1 = await Swal.fire({
            title: 'Unlink rich menu จากทุก user?',
            html: `
                <div style="text-align:left;font-size:13px;color:#475569;line-height:1.6">
                    ระบบจะวน <b>ทุก user</b> ที่มี <code>line_user_id</code> ใน DB →
                    ส่ง <code>DELETE /v2/bot/user/{userId}/richmenu</code> ที่ LINE
                    <div style="margin-top:10px;padding:10px 12px;background:#fee2e2;border:1.5px solid #fca5a5;border-radius:10px;font-size:11.5px;color:#7f1d1d">
                        <b><i class="fa-solid fa-triangle-exclamation"></i> ผลกระทบ:</b>
                        <ul style="margin:6px 0 0 18px;padding:0;list-style:disc">
                            <li>user ทุกคนจะ <b>กลับไปเห็นเมนู Default จาก OA Console</b></li>
                            <li>ถ้า OA Console ไม่ได้ตั้งเมนูไว้ → user จะ <b>ไม่เห็นเมนูเลย</b></li>
                            <li>ระบบจะไม่ link เมนูใหม่ให้ใครจนกว่าจะเปิด AUTO-SYNC อีกครั้ง</li>
                        </ul>
                    </div>
                    <div style="margin-top:8px;font-size:11.5px;color:#64748b">
                        <i class="fa-solid fa-circle-info"></i>
                        แนะนำให้ปิด AUTO-SYNC + ลบ default ก่อน เพื่อให้ flow สมบูรณ์
                    </div>
                </div>
            `,
            icon: 'warning', showCancelButton: true,
            confirmButtonText: 'ดำเนินการต่อ',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#dc2626',
        });
        if (!c1.isConfirmed) return;

        // Step 2 — require typing UNLINK to confirm
        const c2 = await Swal.fire({
            title: 'ยืนยันด้วยข้อความ',
            html: `
                <div style="text-align:left;font-size:13px;color:#475569;line-height:1.6;margin-bottom:8px">
                    พิมพ์ <b style="color:#dc2626;font-family:monospace;letter-spacing:.05em">UNLINK</b> ในช่องด้านล่างเพื่อยืนยัน
                </div>
            `,
            input: 'text',
            inputPlaceholder: 'พิมพ์ UNLINK',
            inputAttributes: { autocapitalize: 'characters', autocomplete: 'off' },
            showCancelButton: true,
            confirmButtonText: '<i class="fa-solid fa-link-slash"></i> ดำเนินการ',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#dc2626',
            inputValidator: (v) => (v || '').trim().toUpperCase() === 'UNLINK' ? null : 'ต้องพิมพ์ UNLINK ให้ตรงตัว',
        });
        if (!c2.isConfirmed) return;

        let offset = 0, totalOK = 0, totalFail = 0, total = 0, done = false;
        Swal.fire({
            title: 'กำลัง unlink...',
            html: '<div id="rmUnlinkProg" style="font-size:13px;color:#475569">เริ่มต้น…</div>',
            allowOutsideClick: false,
            showConfirmButton: false,
        });
        const setProg = (msg) => {
            const el = document.getElementById('rmUnlinkProg');
            if (el) el.innerHTML = msg;
        };

        try {
            while (!done) {
                const r = await rmPost('unlink_all', { offset, confirm: 'UNLINK_ALL_USERS' });
                if (!r.ok) {
                    Swal.fire({ icon: 'error', title: 'ล้มเหลว', text: r.message || '' });
                    return;
                }
                total     = r.total;
                totalOK   += r.batch_ok;
                totalFail += r.batch_fail;
                offset    = r.processed;
                done      = r.done;

                const pct = total ? Math.min(100, Math.round((offset / total) * 100)) : 100;
                setProg(`
                    <div style="margin-bottom:8px"><b>${offset.toLocaleString()}</b> / ${total.toLocaleString()} (${pct}%)</div>
                    <div style="background:#e2e8f0;border-radius:6px;height:8px;overflow:hidden;margin-bottom:8px">
                        <div style="width:${pct}%;height:100%;background:#dc2626;transition:width .2s"></div>
                    </div>
                    <div style="font-size:11px;color:#64748b">unlink สำเร็จ <b style="color:#16a34a">${totalOK}</b> · ล้มเหลว <b style="color:#dc2626">${totalFail}</b></div>
                `);
                if (!done) await new Promise(res => setTimeout(res, 250));
            }

            Swal.fire({
                icon: totalFail > 0 ? 'warning' : 'success',
                title: 'Unlink เสร็จสิ้น',
                html: `
                    รวม ${total} คน · สำเร็จ <b>${totalOK}</b> · ล้มเหลว <b>${totalFail}</b>
                    <div style="margin-top:10px;padding:8px 10px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;font-size:11.5px;color:#14532d;text-align:left">
                        <i class="fa-solid fa-circle-check"></i>
                        ตอนนี้ user จะกลับไปใช้เมนู default จาก LINE OA Console
                        (ถ้ายังเห็นเมนูเดิม ให้ปิด-เปิดแชต LINE ใหม่ — client cache อาจค้าง 1-2 นาที)
                    </div>
                `,
            });
        } catch (e) {
            Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: e.message || String(e) });
        }
    };

    window.rmShowAudit = async function() {
        Swal.fire({ title: 'กำลังโหลด audit log...', didOpen: () => Swal.showLoading(), allowOutsideClick: false });
        const r = await rmPost('audit_recent', {});
        if (!r.ok) {
            Swal.fire({ icon: 'error', title: 'โหลดไม่สำเร็จ', text: r.message || '' });
            return;
        }
        const rows = r.rows || [];
        const fmtTime = (s) => (s || '').substring(0, 19).replace('T', ' ');
        const actBadge = (a, state) => {
            const color = {
                'sync_ok': 'bg-emerald-100 text-emerald-700',
                'sync_failed': 'bg-rose-100 text-rose-700',
                'unlink_ok': 'bg-slate-100 text-slate-600',
                'unlink_failed': 'bg-amber-100 text-amber-700',
            }[a] || 'bg-slate-100 text-slate-700';
            return `<span class="${color}" style="padding:1px 6px;border-radius:6px;font-size:9px;font-weight:800">${a}</span>`;
        };
        const html = rows.length === 0
            ? '<p style="text-align:center;color:#94a3b8;padding:24px 0;font-size:12px">ยังไม่มี audit record</p>'
            : `<div style="max-height:60vh;overflow-y:auto;text-align:left">
                <table style="width:100%;font-size:11px;border-collapse:collapse">
                    <thead style="position:sticky;top:0;background:#f8fafc;border-bottom:1px solid #e2e8f0">
                        <tr style="text-align:left;color:#64748b">
                            <th style="padding:6px 4px">เวลา</th>
                            <th style="padding:6px 4px">User</th>
                            <th style="padding:6px 4px">Action</th>
                            <th style="padding:6px 4px">State</th>
                            <th style="padding:6px 4px">Source</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rows.map(r => `
                            <tr style="border-bottom:1px solid #f1f5f9">
                                <td style="padding:5px 4px;font-family:monospace;color:#64748b;white-space:nowrap">${fmtTime(r.created_at)}</td>
                                <td style="padding:5px 4px;font-family:monospace;color:#0f172a">${(r.line_user_id || '').substring(0,8)}…</td>
                                <td style="padding:5px 4px">${actBadge(r.action, r.state)}</td>
                                <td style="padding:5px 4px;color:#475569">${r.state || '-'}</td>
                                <td style="padding:5px 4px;color:#64748b;font-size:10px">${r.source || '-'}</td>
                            </tr>
                            ${r.error_message ? `<tr><td colspan="5" style="padding:0 4px 6px 4px;color:#dc2626;font-size:10px;font-family:monospace">└─ ${r.error_message.substring(0,180)}</td></tr>` : ''}
                        `).join('')}
                    </tbody>
                </table>
            </div>`;
        Swal.fire({
            title: `Audit Log (${rows.length} ล่าสุด)`,
            html,
            width: 720,
            confirmButtonText: 'ปิด',
        });
    };

    function rmShowLookupResult(r, contextLabel) {
        if (r.ok && r.richMenuId) {
            Swal.fire({
                icon: 'success',
                title: 'พบ richMenuId',
                html: `${contextLabel}<br>
                    <code style="font-size:11px;background:#f1f5f9;padding:4px 8px;border-radius:6px;display:inline-block;margin-top:8px;word-break:break-all">${r.richMenuId}</code>
                    <br><small>คัดลอกแล้วเอาไปวางในช่อง Guest / Member ID ด้านบน</small>`,
                showCancelButton: true, showDenyButton: true,
                confirmButtonText: 'วางใน Guest', denyButtonText: 'วางใน Member', cancelButtonText: 'ปิด',
                confirmButtonColor: '#f59e0b', denyButtonColor: '#10b981',
            }).then(res => {
                if (res.isConfirmed) document.getElementById('rmGuestId').value = r.richMenuId;
                else if (res.isDenied) document.getElementById('rmMemberId').value = r.richMenuId;
            });
        } else {
            Swal.fire({ icon: 'warning', title: 'ไม่พบ', text: r.message || '' });
        }
    }

    window.rmLookupDefault = async function() {
        const r = await rmPost('lookup_default', {});
        rmShowLookupResult(r, 'Default rich menu ปัจจุบัน:');
    };

    window.rmLookupUser = async function() {
        const uid = document.getElementById('rmLookupUid').value.trim();
        if (!uid) { Swal.fire({ icon: 'warning', title: 'กรุณาใส่ lineUserId' }); return; }
        const r = await rmPost('lookup_user', { line_user_id: uid });
        rmShowLookupResult(r, `Rich menu ที่ผูกกับ <code>${uid.substring(0,12)}…</code>:`);
    };

    function rmApplyEnabledUI(enabled) {
        const sw  = document.getElementById('rmPowerSwitch');
        const btn = document.getElementById('rmToggleBtn');
        const lbl = document.getElementById('rmToggleState');
        const sec = document.getElementById('rmSection');
        if (!sw) return;
        sw.classList.toggle('is-on',  !!enabled);
        sw.classList.toggle('is-off', !enabled);
        if (sec) sec.classList.toggle('is-disabled', !enabled);
        if (btn) btn.setAttribute('aria-pressed', enabled ? 'true' : 'false');
        if (lbl) lbl.textContent = enabled ? 'ON' : 'OFF';
    }

    window.rmToggleEnabled = async function() {
        const sw = document.getElementById('rmPowerSwitch');
        const currentlyOn = sw && sw.classList.contains('is-on');
        const next = !currentlyOn;

        const c = await Swal.fire({
            icon: 'question',
            title: next ? 'เปิดใช้งาน Rich Menu?' : 'ปิดใช้งาน Rich Menu?',
            html: next
                ? 'webhook จะ link เมนูให้ user ใหม่ที่ follow OA อัตโนมัติ'
                : 'webhook จะ <b>หยุด link</b> เมนูให้ user ใหม่<br><small class="text-slate-500">user ที่ link เมนูอยู่แล้วจะยังเห็นเมนูเดิม</small>',
            showCancelButton: true,
            confirmButtonText: next ? 'เปิด' : 'ปิด',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: next ? '#16a34a' : '#dc2626',
        });
        if (!c.isConfirmed) return;

        const r = await rmPost('toggle_enabled', { enabled: next ? '1' : '0' });
        if (r.ok) {
            rmApplyEnabledUI(!!r.enabled);
            Swal.fire({
                icon: 'success',
                title: r.message || 'บันทึกแล้ว',
                timer: 1500,
                showConfirmButton: false,
            });
        } else {
            Swal.fire({ icon: 'error', title: 'บันทึกไม่สำเร็จ', text: r.message || '' });
        }
    };

    function rmEscapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }

    function rmRenderMenuCards(menus, errorMsg) {
        const wrap   = document.getElementById('rmMenuCards');
        const empty  = document.getElementById('rmMenuListEmpty');
        const errBox = document.getElementById('rmMenuListError');
        if (!wrap) return;

        wrap.innerHTML = '';
        empty.classList.add('hidden');
        errBox.classList.add('hidden');

        if (errorMsg) {
            errBox.innerHTML = `<i class="fa-solid fa-triangle-exclamation mr-1"></i> ดึง list จาก LINE ไม่ได้: ${rmEscapeHtml(errorMsg)}`;
            errBox.classList.remove('hidden');
            return;
        }
        if (!menus || !menus.length) {
            empty.classList.remove('hidden');
            return;
        }

        const guestId  = document.getElementById('rmGuestId').value.trim();
        const memberId = document.getElementById('rmMemberId').value.trim();

        menus.forEach(m => {
            const id   = m.richMenuId || '';
            const name = m.name || '(no name)';
            const size = m.size ? `${m.size.width}×${m.size.height}` : '';
            const chat = m.chatBarText || '';
            const isGuest  = id === guestId  && id !== '';
            const isMember = id === memberId && id !== '';

            const tags = [];
            if (isGuest)  tags.push('<span class="rm-card-tag rm-card-tag-amber">Guest ปัจจุบัน</span>');
            if (isMember) tags.push('<span class="rm-card-tag rm-card-tag-emerald">Member ปัจจุบัน</span>');

            const card = document.createElement('div');
            card.className = 'rm-menu-card';
            if (isGuest || isMember) card.classList.add('is-active');
            card.innerHTML = `
                <div class="rm-menu-card-info">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="rm-menu-card-name">${rmEscapeHtml(name)}</span>
                        ${tags.join('')}
                    </div>
                    <div class="rm-menu-card-id" title="${rmEscapeHtml(id)}">${rmEscapeHtml(id)}</div>
                    ${(size || chat) ? `<div class="rm-menu-card-meta">${size}${size && chat ? ' · ' : ''}${rmEscapeHtml(chat)}</div>` : ''}
                </div>
                <div class="rm-menu-card-actions">
                    <button type="button" class="rm-card-btn" data-action="copy" data-id="${rmEscapeHtml(id)}" title="คัดลอก ID ไปยัง clipboard">
                        <i class="fa-solid fa-copy"></i><span>คัด ID</span>
                    </button>
                    <button type="button" class="rm-card-btn rm-card-btn-amber" data-action="use-guest" data-id="${rmEscapeHtml(id)}" title="วางลงช่อง Guest">
                        <i class="fa-solid fa-user-plus"></i><span>Guest</span>
                    </button>
                    <button type="button" class="rm-card-btn rm-card-btn-emerald" data-action="use-member" data-id="${rmEscapeHtml(id)}" title="วางลงช่อง Member">
                        <i class="fa-solid fa-user-check"></i><span>Member</span>
                    </button>
                </div>
            `;
            wrap.appendChild(card);
        });

        wrap.querySelectorAll('.rm-card-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                const action = btn.dataset.action;
                const id     = btn.dataset.id || '';
                if (!id) return;
                if (action === 'copy') {
                    try {
                        await navigator.clipboard.writeText(id);
                        const orig = btn.innerHTML;
                        btn.innerHTML = '<i class="fa-solid fa-check"></i><span>คัดแล้ว</span>';
                        btn.classList.add('is-success');
                        setTimeout(() => {
                            btn.innerHTML = orig;
                            btn.classList.remove('is-success');
                        }, 1500);
                    } catch (e) {
                        Swal.fire({ icon: 'error', title: 'คัดลอกไม่สำเร็จ', text: String(e.message || e) });
                    }
                } else {
                    const target  = action === 'use-guest' ? 'guest' : 'member';
                    const fieldId = target === 'guest' ? 'rmGuestId' : 'rmMemberId';
                    const input   = document.getElementById(fieldId);
                    input.value = id;
                    input.classList.add('rm-input-flash');
                    setTimeout(() => input.classList.remove('rm-input-flash'), 900);
                    Swal.fire({
                        toast: true,
                        position: 'top',
                        icon: 'info',
                        title: `วางลงช่อง ${target === 'guest' ? 'Guest' : 'Member'} แล้ว`,
                        text: 'อย่าลืมกด "บันทึก ID" ด้านล่าง',
                        timer: 2400,
                        showConfirmButton: false,
                    });
                    rmRenderMenuCards(menus); // re-render เพื่ออัพเดท active tag
                }
            });
        });
    }

    window.rmRefreshList = async function() {
        const icon = document.getElementById('rmRefreshIcon');
        if (icon) icon.classList.add('fa-spin');
        try {
            const r = await fetch('ajax_line_richmenu.php?action=get').then(x => x.json());
            if (r && r.ok) rmRenderMenuCards(r.richmenus || [], r.list_error || '');
        } catch (e) {
            rmRenderMenuCards([], String(e.message || e));
        } finally {
            if (icon) setTimeout(() => icon.classList.remove('fa-spin'), 400);
        }
    };

    // โหลด initial state
    rmGet().then(d => {
        if (!d || !d.ok) return;
        rmApplyEnabledUI(!!d.enabled);
        document.getElementById('rmGuestId').value  = d.ids.guest  || '';
        document.getElementById('rmMemberId').value = d.ids.member || '';
        rmRenderMenuCards(d.richmenus || [], d.list_error || '');
    });
})();
</script>

<!-- ════════════ Rich Menu Creator (เรียก API LINE สร้างให้) ════════════ -->
<div class="max-w-4xl mx-auto px-4 md:px-6">
    <div class="ls-section-banner" data-tone="creator" id="ls-sec-creator">
        <div class="ls-section-banner-icon"><i class="fa-solid fa-bolt"></i></div>
        <div class="ls-section-banner-text">
            <h2>สร้าง Rich Menu ใหม่ผ่าน API</h2>
            <p>สร้าง config + อัพภาพได้ในคลิกเดียว · รับไฟล์ ≤10 MB ระบบบีบให้ · ทางเลือกสำหรับ admin ที่ไม่อยากเข้า OA Console</p>
        </div>
    </div>
</div>
<div class="max-w-4xl mx-auto px-4 md:px-6 pb-12 mt-2">
    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6">
        <div class="flex items-center gap-3 mb-1">
            <div class="w-10 h-10 bg-purple-50 text-purple-600 rounded-2xl border border-purple-100 flex items-center justify-center">
                <i class="fa-solid fa-wand-magic-sparkles"></i>
            </div>
            <div>
                <h3 class="text-lg font-black text-slate-800">สร้าง Rich Menu ผ่าน API</h3>
                <p class="text-xs text-slate-500 font-medium">กรอกฟอร์ม + อัพรูป → ระบบเรียก API LINE สร้างให้ → ได้ richMenuId กลับมาวางในช่องบนทันที</p>
            </div>
        </div>

        <div class="bg-amber-50 border border-amber-100 rounded-2xl p-3 mt-4 text-xs text-amber-800 font-medium flex gap-2 items-start">
            <i class="fa-solid fa-triangle-exclamation text-amber-500 mt-0.5"></i>
            <div>
                <span class="font-black">ข้อกำหนดของ LINE:</span>
                ขนาดภาพต้องตรงกับ size ที่เลือกเป๊ะ ๆ · PNG/JPEG · <b>ไม่เกิน 10 MB</b> (ระบบบีบให้อัตโนมัติเหลือ ≤1 MB ก่อนส่ง LINE) · click areas ต้องไม่ออกนอกขอบภาพ
            </div>
        </div>

        <form id="rmCreateForm" onsubmit="rmCreate(event)" class="mt-5 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div class="md:col-span-2">
                    <label class="block text-[11px] font-black uppercase tracking-widest text-slate-500 mb-1.5">ชื่อ (ใช้ภายใน)</label>
                    <input type="text" name="rc_name" value="Guest Menu" required
                        class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-purple-400">
                </div>
                <div>
                    <label class="block text-[11px] font-black uppercase tracking-widest text-slate-500 mb-1.5">Chat Bar Text</label>
                    <input type="text" name="rc_chatbar" value="เมนู" required maxlength="14"
                        class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-purple-400">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                    <label class="block text-[11px] font-black uppercase tracking-widest text-slate-500 mb-1.5">ขนาด</label>
                    <select name="rc_size" id="rcSize" onchange="rmSizeChange()" class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none">
                        <option value="2500x1686">Large 2500×1686</option>
                        <option value="2500x843">Compact 2500×843</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-black uppercase tracking-widest text-slate-500 mb-1.5">selected (auto-show)</label>
                    <select name="rc_selected" class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none">
                        <option value="true">true (แสดง expanded ทันที)</option>
                        <option value="false">false (แสดง icon ให้กดเปิด)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-black uppercase tracking-widest text-slate-500 mb-1.5">ไฟล์ภาพ (PNG/JPEG, ≤10MB · ระบบบีบให้)</label>
                    <input type="file" name="image" id="rcImage" accept="image/png,image/jpeg" required
                        class="w-full text-xs font-bold text-slate-600 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-black file:bg-purple-50 file:text-purple-700 hover:file:bg-purple-100">
                </div>
            </div>

            <div>
                <label class="block text-[11px] font-black uppercase tracking-widest text-slate-500 mb-1.5">
                    Click Areas (JSON) — array ของ {bounds, action}
                </label>
                <textarea name="rc_areas" id="rcAreas" rows="10" required
                    class="w-full px-3 py-2.5 bg-slate-900 border border-slate-700 rounded-xl text-[12px] font-mono text-emerald-300 outline-none focus:border-purple-400"
                    spellcheck="false"></textarea>
                <div class="flex flex-wrap gap-2 mt-2">
                    <button type="button" onclick="rmTemplate('grid_3x2')" class="text-[10px] font-black px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 border border-slate-200">
                        Template 3×2 (6 ปุ่ม)
                    </button>
                    <button type="button" onclick="rmTemplate('grid_2x1')" class="text-[10px] font-black px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 border border-slate-200">
                        Template 2×1 (2 ปุ่ม)
                    </button>
                    <button type="button" onclick="rmTemplate('single')" class="text-[10px] font-black px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 border border-slate-200">
                        Template 1 ปุ่มเต็มภาพ
                    </button>
                    <span class="mx-1 text-slate-300">|</span>
                    <button type="button" onclick="rmImportFromId()" class="text-[10px] font-black px-2.5 py-1 rounded-lg bg-cyan-50 hover:bg-cyan-100 text-cyan-700 border border-cyan-200 inline-flex items-center gap-1">
                        <i class="fa-solid fa-file-import"></i> นำเข้า areas จาก richMenuId
                    </button>
                </div>
                <p class="text-[10px] text-slate-400 font-medium mt-1.5">
                    Action types: <span class="font-mono">uri</span> (เปิด URL), <span class="font-mono">message</span> (ส่ง text), <span class="font-mono">postback</span>, <span class="font-mono">richmenuswitch</span>
                </p>
                <p class="text-[10px] text-cyan-600 font-medium mt-1">
                    <i class="fa-solid fa-circle-info"></i> "นำเข้า areas" — ใส่ ID ของ rich menu ที่อยากเลียนแบบ layout → ระบบดึง size + areas มา auto-fill (ใช้กับ Console menu อาจติด channel limitation)
                </p>
            </div>

            <div class="flex items-center justify-between pt-2 border-t border-slate-100">
                <p class="text-[11px] text-slate-500 font-medium">เมื่อสร้างสำเร็จ ID จะปรากฏใน popup และ auto-paste ลงช่อง guest หรือ member ที่เลือก</p>
                <div class="flex gap-2">
                    <select id="rcTarget" class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-black text-slate-700 outline-none">
                        <option value="guest">→ Guest ID</option>
                        <option value="member">→ Member ID</option>
                        <option value="none">ไม่ auto-paste</option>
                    </select>
                    <button type="submit" class="px-5 py-2 rounded-xl bg-purple-500 hover:bg-purple-600 text-white text-xs font-black inline-flex items-center gap-1.5 transition-colors">
                        <i class="fa-solid fa-bolt"></i> สร้าง Rich Menu
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
(function(){
    const CSRF = '<?= get_csrf_token() ?>';

    const TEMPLATES = {
        // 2500×1686 Large
        grid_3x2: JSON.stringify([
            { bounds: { x: 0,    y: 0,   width: 833,  height: 843 }, action: { type: 'uri',    uri: 'https://example.com/a' } },
            { bounds: { x: 833,  y: 0,   width: 834,  height: 843 }, action: { type: 'uri',    uri: 'https://example.com/b' } },
            { bounds: { x: 1667, y: 0,   width: 833,  height: 843 }, action: { type: 'uri',    uri: 'https://example.com/c' } },
            { bounds: { x: 0,    y: 843, width: 833,  height: 843 }, action: { type: 'message',text: 'สมัครสมาชิก' } },
            { bounds: { x: 833,  y: 843, width: 834,  height: 843 }, action: { type: 'message',text: 'ติดต่อ' } },
            { bounds: { x: 1667, y: 843, width: 833,  height: 843 }, action: { type: 'message',text: 'ช่วยเหลือ' } },
        ], null, 2),
        // 2500×843 Compact
        grid_2x1: JSON.stringify([
            { bounds: { x: 0,    y: 0, width: 1250, height: 843 }, action: { type: 'uri',     uri: 'https://example.com/register' } },
            { bounds: { x: 1250, y: 0, width: 1250, height: 843 }, action: { type: 'message', text: 'ข้อมูล' } },
        ], null, 2),
        // ทั้งภาพ
        single: JSON.stringify([
            { bounds: { x: 0, y: 0, width: 2500, height: 1686 }, action: { type: 'uri', uri: 'https://example.com/' } },
        ], null, 2),
    };

    window.rmTemplate = function(key) {
        const ta = document.getElementById('rcAreas');
        if (!ta) return;
        // ตั้ง size ให้ตรงกับ template
        const sizeSel = document.getElementById('rcSize');
        if (key === 'grid_2x1') sizeSel.value = '2500x843';
        else sizeSel.value = '2500x1686';
        ta.value = TEMPLATES[key] || '';
    };

    window.rmSizeChange = function(){ /* placeholder */ };

    window.rmImportFromId = async function() {
        const { value: rid } = await Swal.fire({
            title: 'นำเข้า areas จาก richMenuId',
            input: 'text',
            inputPlaceholder: 'richmenu-xxxxxxxxxxxxxxxxxxxxxxxxxx',
            inputAttributes: { autocapitalize: 'off', style: 'font-family:monospace;font-size:12px' },
            showCancelButton: true,
            confirmButtonText: 'ดึง config',
            cancelButtonText: 'ยกเลิก',
            inputValidator: v => !v ? 'กรุณาใส่ richMenuId' : undefined,
        });
        if (!rid) return;

        Swal.fire({ title: 'กำลังดึง...', didOpen: () => Swal.showLoading(), allowOutsideClick: false });
        const fd = new FormData();
        fd.append('action', 'import_detail');
        fd.append('csrf_token', '<?= get_csrf_token() ?>');
        fd.append('richMenuId', rid.trim());
        const r = await fetch('ajax_line_richmenu.php', { method: 'POST', body: fd }).then(x => x.json());

        if (!r.ok) {
            Swal.fire({ icon: 'warning', title: 'ดึงไม่ได้', html: (r.message || '').replace(/\n/g, '<br>') });
            return;
        }

        const d = r.data || {};
        // Fill form
        if (d.size && d.size.width && d.size.height) {
            document.getElementById('rcSize').value = `${d.size.width}x${d.size.height}`;
        }
        if (d.name) document.querySelector('input[name="rc_name"]').value = d.name;
        if (d.chatBarText) document.querySelector('input[name="rc_chatbar"]').value = d.chatBarText.substring(0, 14);
        if (typeof d.selected !== 'undefined') {
            document.querySelector('select[name="rc_selected"]').value = d.selected ? 'true' : 'false';
        }
        if (Array.isArray(d.areas)) {
            document.getElementById('rcAreas').value = JSON.stringify(d.areas, null, 2);
        }

        Swal.fire({
            icon: 'success',
            title: 'นำเข้าสำเร็จ',
            html: `ดึง config มาเรียบร้อย:<br>
                <ul style="text-align:left;display:inline-block;font-size:13px;margin-top:8px">
                    <li>Size: ${d.size?.width}×${d.size?.height}</li>
                    <li>Name: ${d.name || '-'}</li>
                    <li>Chat bar: ${d.chatBarText || '-'}</li>
                    <li>Areas: ${(d.areas || []).length} ปุ่ม</li>
                </ul>
                <small>อย่าลืมอัพรูป (ขนาดต้องตรงกับ size)</small>`,
        });
    };

    // ตั้งค่า default template ตอนโหลด
    document.getElementById('rcAreas').value = TEMPLATES.grid_3x2;

    window.rmCreate = async function(e) {
        e.preventDefault();
        const form = e.target;
        const fd = new FormData(form);

        // Build config JSON
        const [w, h] = (fd.get('rc_size') || '2500x1686').split('x').map(Number);
        let areas;
        try {
            areas = JSON.parse(fd.get('rc_areas') || '[]');
            if (!Array.isArray(areas)) throw new Error('areas ต้องเป็น array');
        } catch (err) {
            Swal.fire({ icon: 'error', title: 'Areas JSON ไม่ถูกต้อง', text: err.message });
            return;
        }
        const config = {
            size:         { width: w, height: h },
            selected:     fd.get('rc_selected') === 'true',
            name:         fd.get('rc_name'),
            chatBarText:  fd.get('rc_chatbar'),
            areas:        areas,
        };

        const target = document.getElementById('rcTarget').value;

        const payload = new FormData();
        payload.append('action', 'create');
        payload.append('csrf_token', CSRF);
        payload.append('config', JSON.stringify(config));
        payload.append('image', fd.get('image'));

        Swal.fire({ title: 'กำลังสร้าง...', text: 'สร้าง config + อัพโหลดรูป', didOpen: () => Swal.showLoading(), allowOutsideClick: false });
        const r = await fetch('ajax_line_richmenu.php', { method: 'POST', body: payload }).then(x => x.json());

        if (!r.ok) {
            const verboseHtml = r.verbose
                ? `<details style="text-align:left;margin-top:12px"><summary style="cursor:pointer;font-size:11px;color:#64748b">▸ curl verbose log (สำหรับ debug — copy ส่งมาให้ดูได้)</summary>
                   <pre style="font-size:10px;background:#0f172a;color:#94a3b8;padding:8px;border-radius:6px;max-height:300px;overflow:auto;white-space:pre-wrap;text-align:left;margin-top:4px">${r.verbose.replace(/[<>&]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[c]))}</pre></details>`
                : '';
            Swal.fire({
                icon: 'error',
                title: 'สร้างไม่สำเร็จ' + (r.step ? ` (${r.step})` : '') + (r.http ? ` · HTTP ${r.http}` : ''),
                html: `<div style="text-align:left;font-size:13px">${r.message || ''}</div>${verboseHtml}`,
                width: 700,
            });
            return;
        }

        // Auto-paste
        if (target === 'guest') document.getElementById('rmGuestId').value = r.richMenuId;
        if (target === 'member') document.getElementById('rmMemberId').value = r.richMenuId;

        const auto = (target === 'guest' || target === 'member') ? `<br><small>วางลงช่อง <b>${target}</b> แล้ว — อย่าลืมกด "บันทึก ID" ด้านบน</small>` : '';
        const compressBadge = r.compressed
            ? `<div style="margin-top:10px;padding:8px 12px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;font-size:11.5px;color:#065f46;display:inline-block">
                 <i class="fa-solid fa-compress" style="margin-right:4px"></i>
                 บีบจาก <b>${r.compressed.original_mb} MB</b> → <b>${r.compressed.compressed_mb} MB</b> (JPEG quality ${r.compressed.quality})
               </div>`
            : '';
        Swal.fire({
            icon: 'success',
            title: 'สร้างสำเร็จ',
            html: `<code style="font-size:11px;background:#f1f5f9;padding:4px 8px;border-radius:6px;display:inline-block;margin-top:8px;word-break:break-all">${r.richMenuId}</code>${compressBadge}${auto}`,
        });

        if (typeof rmRefreshList === 'function') rmRefreshList();
    };
})();
</script>

<!-- ════════════ Back-to-Top Button ════════════ -->
<button type="button" class="ls-back-top" id="lsBackToTop" aria-label="กลับขึ้นด้านบน" title="กลับขึ้นด้านบน">
    <i class="fa-solid fa-arrow-up"></i>
</button>

<script>
/* ════════════════════════════════════════════════════
   Page UX: Scroll-spy nav · Status populate · Back-to-top
   ════════════════════════════════════════════════════ */
(function lineSettingsPageUx() {
    'use strict';

    // ─── Position sticky nav below portal header ───
    function alignStickyNav() {
        const nav    = document.getElementById('lsQuickNav');
        const header = document.querySelector('.portal-header');
        if (!nav) return;
        const top = header ? header.offsetHeight : 0;
        nav.style.top = top + 'px';
        // Update scroll-margin-top on banners so anchors land below nav
        document.querySelectorAll('.ls-section-banner').forEach(b => {
            b.style.scrollMarginTop = (top + nav.offsetHeight + 8) + 'px';
        });
    }
    alignStickyNav();
    window.addEventListener('resize', alignStickyNav, { passive: true });

    // ─── Smooth scroll on chip click ───
    const chips = document.querySelectorAll('.ls-nav-chip');
    window.lsScrollTo = function(targetId) {
        const t = document.getElementById(targetId);
        if (t) t.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };
    chips.forEach(c => {
        c.addEventListener('click', e => {
            e.preventDefault();
            window.lsScrollTo(c.dataset.target);
        });
    });

    // ─── Scroll-spy ───
    if ('IntersectionObserver' in window) {
        const targets = Array.from(chips)
            .map(c => document.getElementById(c.dataset.target))
            .filter(Boolean);
        if (targets.length) {
            const io = new IntersectionObserver(entries => {
                for (const e of entries) {
                    if (e.isIntersecting) {
                        const id = e.target.id;
                        chips.forEach(c => c.classList.toggle('is-active', c.dataset.target === id));
                    }
                }
            }, { rootMargin: '-25% 0px -55% 0px', threshold: 0 });
            targets.forEach(t => io.observe(t));
        }
    }

    // ─── Back-to-top ───
    const backBtn = document.getElementById('lsBackToTop');
    if (backBtn) {
        const onScroll = () => {
            backBtn.classList.toggle('is-visible', window.scrollY > 600);
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        backBtn.addEventListener('click', () => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
        onScroll();
    }

    // ─── Populate status tiles ───
    function updateTile(id, opts) {
        const tile = document.getElementById(id);
        if (!tile) return;
        if (opts.tone) tile.setAttribute('data-tone', opts.tone);
        if (opts.value !== undefined) {
            const v = tile.querySelector('.ls-status-value');
            if (v) v.textContent = opts.value;
        }
        if (opts.meta !== undefined) {
            const m = tile.querySelector('.ls-status-meta');
            if (m) m.textContent = opts.meta;
        }
        if (opts.icon) {
            const i = tile.querySelector('.ls-status-icon i');
            if (i) i.className = opts.icon;
        }
    }

    // Rich Menu state — fetch from settings
    fetch('ajax_line_richmenu.php?action=get')
        .then(r => r.json())
        .then(d => {
            if (!d || !d.ok) return;
            const enabled = !!d.enabled;
            const guest   = d.ids?.guest  || '';
            const member  = d.ids?.member || '';
            const bothSet = !!(guest && member);
            updateTile('lsStatusRichMenu', {
                tone: enabled ? (bothSet ? 'ok' : 'warn') : 'warn',
                value: enabled ? 'เปิดใช้งาน' : 'ปิดอยู่',
                meta: `Guest ${guest ? '✓' : '—'} · Member ${member ? '✓' : '—'}`,
                icon: enabled ? 'fa-solid fa-toggle-on' : 'fa-solid fa-toggle-off',
            });
        })
        .catch(() => updateTile('lsStatusRichMenu', { tone: 'muted', value: 'โหลดไม่ได้', meta: '—' }));

    // Groups count — fetch from groups API
    fetch('ajax_line_groups.php?action=list')
        .then(r => r.json())
        .then(d => {
            if (!d || !d.ok) return;
            const groups = d.groups || [];
            const n = groups.length;
            updateTile('lsStatusGroups', {
                tone: n > 0 ? 'info' : 'muted',
                value: n > 0 ? `${n} กลุ่ม` : 'ยังไม่มีกลุ่ม',
                meta: d.default_id ? 'มีกลุ่มหลัก ✓' : (n > 0 ? 'ยังไม่ตั้งกลุ่มหลัก' : 'เชิญ OA เข้ากลุ่ม'),
            });
        })
        .catch(() => updateTile('lsStatusGroups', { tone: 'muted', value: 'โหลดไม่ได้', meta: '—' }));

    // Quota tile — listen to stats panel data when it loads
    // ls-q-used and ls-q-limit are populated by the existing loadStats() function
    const observeQuota = () => {
        const used  = document.getElementById('ls-q-used');
        const limit = document.getElementById('ls-q-limit');
        if (!used || !limit) return;
        const refresh = () => {
            const u = (used.textContent || '').trim();
            const l = (limit.textContent || '').trim();
            if (u === '—' || u === '') return;
            const uNum = parseInt(u.replace(/[^\d]/g, '')) || 0;
            const lText = l.toLowerCase();
            if (lText.includes('ไม่จำกัด') || lText.includes('unlimited') || lText === '∞') {
                updateTile('lsStatusQuota', { tone: 'ok', value: `${uNum.toLocaleString()} ข้อความ`, meta: 'โควต้าไม่จำกัด' });
                return;
            }
            const lNum = parseInt(l.replace(/[^\d]/g, '')) || 0;
            if (lNum > 0) {
                const pct = Math.round((uNum / lNum) * 100);
                const tone = pct >= 90 ? 'error' : pct >= 70 ? 'warn' : 'ok';
                updateTile('lsStatusQuota', {
                    tone,
                    value: `${pct}% ใช้แล้ว`,
                    meta: `${uNum.toLocaleString()} / ${lNum.toLocaleString()}`,
                });
            }
        };
        const mo = new MutationObserver(refresh);
        mo.observe(used,  { childList: true, characterData: true, subtree: true });
        mo.observe(limit, { childList: true, characterData: true, subtree: true });
        refresh();
    };
    observeQuota();
})();
</script>
