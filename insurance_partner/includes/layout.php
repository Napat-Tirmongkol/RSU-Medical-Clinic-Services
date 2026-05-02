<?php
/**
 * insurance_partner/includes/layout.php
 * Shared header + sidebar layout for Insurance Partner Portal
 *
 * Usage:
 *   $pageTitle = 'Dashboard';
 *   $activePage = 'dashboard';   // dashboard | export | import | history
 *   require __DIR__ . '/includes/layout.php';
 *   ins_partner_layout_start();
 *   // ... page content ...
 *   ins_partner_layout_end();
 */
declare(strict_types=1);

function ins_partner_layout_start(string $pageTitle, string $activePage): void
{
    $partner = current_ins_partner();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> — Insurance Partner Portal</title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/rsufont.css">
    <style>
        * { font-family: 'rsufont', 'Prompt', sans-serif; box-sizing: border-box; }
        body { margin: 0; background: #f0fdf4; color: #064e3b; min-height: 100vh; }

        .ipp-shell { display: flex; min-height: 100vh; }
        .ipp-sidebar {
            width: 260px; background: linear-gradient(180deg, #047857 0%, #065f46 100%);
            color: #fff; padding: 1.5rem 1rem; flex-shrink: 0;
            position: sticky; top: 0; height: 100vh;
            display: flex; flex-direction: column;
        }
        .ipp-brand { display: flex; align-items: center; gap: .65rem; margin-bottom: 2rem; padding: 0 .25rem; }
        .ipp-brand .heart { width: 2rem; height: 2rem; background: #fff; border-radius: 50%;
            display:flex; align-items:center; justify-content:center; }
        .ipp-brand .heart i { color: #047857; font-size: .85rem; }
        .ipp-brand-text { font-weight: 800; font-size: .9rem; line-height: 1.2; }
        .ipp-brand-sub { font-size: .65rem; opacity: .7; letter-spacing: .1em; text-transform: uppercase; }

        .ipp-nav { display: flex; flex-direction: column; gap: .25rem; flex: 1; }
        .ipp-nav a {
            display: flex; align-items: center; gap: .65rem;
            padding: .7rem .85rem; border-radius: .65rem;
            color: rgba(255,255,255,.85); text-decoration: none;
            font-size: .85rem; font-weight: 600;
            transition: background .15s, color .15s;
        }
        .ipp-nav a:hover { background: rgba(255,255,255,.1); color: #fff; }
        .ipp-nav a.active { background: rgba(255,255,255,.2); color: #fff; }
        .ipp-nav a i { width: 1rem; text-align: center; }

        .ipp-user-card {
            background: rgba(0,0,0,.15); border-radius: .75rem;
            padding: .8rem; font-size: .75rem;
            margin-top: 1rem;
        }
        .ipp-user-card .name { font-weight: 700; color: #fff; }
        .ipp-user-card .company { opacity: .8; font-size: .7rem; margin-top: .15rem; }
        .ipp-logout-btn {
            display: block; width: 100%; margin-top: .5rem;
            padding: .55rem; background: rgba(220,38,38,.85); color: #fff;
            border: none; border-radius: .5rem; cursor: pointer;
            font-weight: 700; font-size: .75rem;
            text-decoration: none; text-align: center;
            font-family: 'Prompt', sans-serif;
        }
        .ipp-logout-btn:hover { background: rgba(220,38,38,1); }

        .ipp-main { flex: 1; padding: 2rem 2.5rem; overflow-x: auto; }
        .ipp-page-title {
            font-size: 1.5rem; font-weight: 800; color: #064e3b;
            margin: 0 0 .25rem 0;
        }
        .ipp-page-sub { font-size: .85rem; color: #047857; margin: 0 0 1.75rem 0; }

        .ipp-card {
            background: #fff; border-radius: 1rem;
            box-shadow: 0 4px 14px rgba(0,0,0,.05);
            padding: 1.5rem;
            margin-bottom: 1.25rem;
        }
        .ipp-card h3 {
            font-size: 1rem; font-weight: 700; color: #064e3b;
            margin: 0 0 .85rem 0;
        }
        .ipp-btn {
            display: inline-flex; align-items: center; gap: .5rem;
            padding: .65rem 1.2rem; border-radius: .65rem;
            background: #059669; color: #fff;
            font-weight: 700; font-size: .85rem;
            text-decoration: none; cursor: pointer; border: none;
            transition: background .15s;
            font-family: 'Prompt', sans-serif;
        }
        .ipp-btn:hover { background: #047857; }
        .ipp-btn.secondary { background: #fff; color: #047857; border: 1.5px solid #d1fae5; }
        .ipp-btn.secondary:hover { background: #ecfdf5; }
        .ipp-btn.danger { background: #dc2626; }
        .ipp-btn.danger:hover { background: #b91c1c; }

        .ipp-stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; }
        .ipp-stat {
            background: #fff; padding: 1.1rem 1.25rem; border-radius: .85rem;
            box-shadow: 0 4px 14px rgba(0,0,0,.05);
            border-left: 4px solid #10b981;
        }
        .ipp-stat .label { font-size: .72rem; color: #6b7280; text-transform: uppercase; letter-spacing: .08em; font-weight: 700; }
        .ipp-stat .value { font-size: 1.6rem; font-weight: 800; color: #064e3b; margin-top: .15rem; }

        .ipp-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
        .ipp-table th, .ipp-table td { padding: .65rem .75rem; text-align: left; border-bottom: 1px solid #f0fdf4; }
        .ipp-table th { background: #f0fdf4; font-weight: 700; color: #064e3b; font-size: .78rem; text-transform: uppercase; letter-spacing: .05em; }
        .ipp-table tbody tr:hover { background: #f9fafb; }

        .ipp-pagination { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #d1fae5; }
        .ipp-pagination-info { font-size: .8rem; color: #047857; }
        .ipp-pagination-controls { display: flex; gap: .25rem; }
        .ipp-page-btn {
            min-width: 2.25rem; height: 2.25rem; padding: 0 .5rem;
            border-radius: .5rem; border: 1.5px solid #d1fae5;
            background: #fff; color: #047857;
            cursor: pointer; font-weight: 700; font-size: .8rem;
            display: inline-flex; align-items: center; justify-content: center;
            text-decoration: none;
            font-family: 'Prompt', sans-serif;
        }
        .ipp-page-btn:hover:not(.disabled):not(.active) { background: #ecfdf5; border-color: #10b981; }
        .ipp-page-btn.active { background: #059669; color: #fff; border-color: #059669; }
        .ipp-page-btn.disabled { opacity: .4; pointer-events: none; }

        .ipp-alert { padding: .85rem 1rem; border-radius: .65rem; font-size: .85rem; margin-bottom: 1rem; }
        .ipp-alert.success { background: #ecfdf5; border: 1px solid #6ee7b7; color: #065f46; }
        .ipp-alert.error   { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
        .ipp-alert.info    { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }

        .ipp-form-row { display: flex; flex-direction: column; gap: .35rem; margin-bottom: .85rem; }
        .ipp-form-row label { font-size: .8rem; font-weight: 700; color: #064e3b; }
        .ipp-form-row input[type=file], .ipp-form-row input[type=text] {
            padding: .65rem .8rem; border: 1.5px solid #d1fae5;
            border-radius: .55rem; font-size: .85rem;
            font-family: 'Prompt', sans-serif;
        }

        @media (max-width: 768px) {
            .ipp-shell { flex-direction: column; }
            .ipp-sidebar { width: 100%; height: auto; position: static; }
            .ipp-nav { flex-direction: row; flex-wrap: wrap; }
            .ipp-main { padding: 1.25rem; }
        }
    </style>
</head>
<body>

<div class="ipp-shell">
    <aside class="ipp-sidebar">
        <div class="ipp-brand">
            <div class="heart"><i class="fa-solid fa-heart"></i></div>
            <div>
                <div class="ipp-brand-text">RSU Medical</div>
                <div class="ipp-brand-sub">Partner Portal</div>
            </div>
        </div>

        <nav class="ipp-nav">
            <a href="index.php" class="<?= $activePage === 'dashboard' ? 'active' : '' ?>">
                <i class="fa-solid fa-gauge-high"></i> Dashboard
            </a>
            <a href="export.php" class="<?= $activePage === 'export' ? 'active' : '' ?>">
                <i class="fa-solid fa-file-arrow-down"></i> ดาวน์โหลดรายชื่อ
            </a>
            <a href="import_policy.php" class="<?= $activePage === 'import' ? 'active' : '' ?>">
                <i class="fa-solid fa-file-arrow-up"></i> อัปโหลดเลขกรมธรรม์
            </a>
            <a href="history.php" class="<?= $activePage === 'history' ? 'active' : '' ?>">
                <i class="fa-solid fa-clock-rotate-left"></i> ประวัติการดำเนินการ
            </a>
        </nav>

        <div class="ipp-user-card">
            <div class="name"><i class="fa-regular fa-user mr-1"></i> <?= htmlspecialchars($partner['full_name']) ?></div>
            <div class="company"><i class="fa-solid fa-building mr-1"></i> <?= htmlspecialchars($partner['company_name']) ?></div>
            <a href="logout.php" class="ipp-logout-btn"><i class="fa-solid fa-right-from-bracket mr-1"></i> ออกจากระบบ</a>
        </div>
    </aside>

    <main class="ipp-main">
<?php
}

function ins_partner_layout_end(): void
{
?>
    </main>
</div>

</body>
</html>
<?php
}
