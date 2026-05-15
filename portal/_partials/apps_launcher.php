<?php
/**
 * portal/_partials/apps_launcher.php
 *
 * App Launcher — grid of all clinic modules.
 *
 * เปิดผ่าน sidebar กลุ่ม OVERVIEW > "App Launcher" (?section=apps)
 *
 * Depends on globals defined in portal/index.php:
 *   $projects, $userPins, $categoryMap, $adminRole, $isSuper, $isStaff, $projectFlagMap
 */
if (!isset($projects) || !is_array($projects)) return;

// Map project → required access flag (mirrors the previous inline map in dashboard)
$projectFlagMap = $projectFlagMap ?? [
    'e_campaign'         => 'access_ecampaign',
    'staff_checkin'      => 'access_ecampaign',
    'e_borrow'           => 'access_eborrow',
    'asset_management'   => 'access_asset',
    'consumables'        => 'access_consumables',
    'system_logs'        => 'access_system_logs',
    'insurance_sync'     => 'access_insurance',
    'live_support_chat'  => 'access_ecampaign',
    'line_messaging'     => null,
    'privilege_inventory'=> null,
    'identity_governance'=> 'access_identity',
];
?>

<div class="px-5 md:px-8 py-8 max-w-[1600px] mx-auto">

    <!-- Page header -->
    <div style="display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:14px;margin-bottom:24px">
        <div>
            <div class="eyebrow" style="margin-bottom:6px">Portal · Overview</div>
            <div class="sec-title" style="font-size:1.35rem">
                App Launcher
            </div>
            <p style="font-size:13px;color:#64748b;margin-top:6px;max-width:640px">
                รวมทุกระบบและเครื่องมือไว้ที่นี่ — ค้นหา กรอง หรือปักหมุดระบบที่ใช้บ่อย แล้วเปิดได้ในคลิกเดียว
            </p>
        </div>
        <div style="display:flex;align-items:center;gap:10px">
            <button onclick="window.cmdkOpen && window.cmdkOpen()" type="button" class="cmdk-trigger" title="กด ⌘K เพื่อเปิด">
                <i class="fa-solid fa-magnifying-glass cmdk-trigger-icon"></i>
                <span>ค้นหาระบบ / คำสั่ง</span>
                <kbd>⌘K</kbd>
            </button>
        </div>
    </div>

    <!-- Control bar -->
    <div style="margin-bottom:20px">
        <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <input type="text" id="search-project" placeholder="กรอง..."
                    class="proj-search-inline" aria-label="กรองรายการระบบในหน้านี้">
            </div>

            <!-- View toggle -->
            <div style="display:flex;background:#f1f5f9;border-radius:10px;padding:3px;gap:2px">
                <button id="btn-grid" onclick="projSetView('grid')" title="มุมมองการ์ด"
                    style="padding:5px 10px;border-radius:8px;border:none;cursor:pointer;background:#fff;color:#2e9e63;box-shadow:0 1px 4px rgba(0,0,0,.08);transition:all .2s">
                    <i class="fa-solid fa-border-all" style="font-size:12px"></i>
                </button>
                <button id="btn-list" onclick="projSetView('list')" title="มุมมองรายการ"
                    style="padding:5px 10px;border-radius:8px;border:none;cursor:pointer;background:transparent;color:#94a3b8;transition:all .2s">
                    <i class="fa-solid fa-list" style="font-size:12px"></i>
                </button>
            </div>
        </div>

        <!-- Filter tabs -->
        <div style="display:flex;gap:6px;overflow-x:auto;padding-bottom:2px">
            <button class="proj-tab active" data-filter="all" onclick="projSetFilter(this)">ทั้งหมด</button>
            <button class="proj-tab" data-filter="core"  onclick="projSetFilter(this)">ระบบหลัก (Core)</button>
            <button class="proj-tab" data-filter="tools" onclick="projSetFilter(this)">เครื่องมือ (Tools)</button>
            <button class="proj-tab" data-filter="dev"   onclick="projSetFilter(this)">กำลังพัฒนา (Dev Stage)</button>
        </div>
    </div>

    <!-- Cards -->
    <div id="project-container" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
        <?php
        $cardIdx = 0;
        foreach ($projects as $proj):
            $hasAccess = false;
            if ($adminRole === 'superadmin') {
                $hasAccess = true;
            } else {
                $reqFlag = $projectFlagMap[$proj['id']] ?? '__unknown__';
                if ($reqFlag === null) {
                    $hasAccess = false;
                } elseif ($reqFlag === '') {
                    $hasAccess = in_array($adminRole, ['admin', 'superadmin'], true);
                } elseif ($reqFlag === '__unknown__') {
                    if (in_array($adminRole, $proj['allowed_roles'])) $hasAccess = true;
                    if ($isStaff && ($proj['staff_visible'] ?? false)) $hasAccess = true;
                } else {
                    $hasAccess = !empty($_SESSION[$reqFlag]);
                }
            }
            if (!$hasAccess) continue;

            $cardDelay = round(0.1 + $cardIdx * 0.08, 2);
            $cardIdx++;
            $cat = $categoryMap[$proj['id']] ?? 'core';
            $keywords = strtolower(implode(' ', $proj['badges']) . ' ' . $proj['title']);
            $isPinned = in_array($proj['id'], $userPins);
        ?>
            <div class="proj-card fx-tilt fx-tilt-light" id="proj-<?= $proj['id'] ?>" data-category="<?= $cat ?>"
                 data-name="<?= htmlspecialchars(strtolower($proj['title'])) ?>"
                 data-keywords="<?= htmlspecialchars($keywords) ?>"
                 data-pinned="<?= $isPinned ? '1' : '0' ?>"
                 data-tilt="5"
                 style="animation-delay:<?= $cardDelay ?>s">

                <button class="pin-btn <?= $isPinned ? 'active' : '' ?>" onclick="togglePin('<?= $proj['id'] ?>', this)" title="ปักหมุด">
                    <i class="fa-solid fa-thumbtack text-[10px]"></i>
                </button>

                <div class="proj-card-header">
                    <div class="proj-card-icon <?= $proj['bg_color'] ?> <?= $proj['icon_color'] ?> <?= $proj['border_color'] ?>">
                        <i class="fa-solid <?= $proj['icon'] ?>"></i>
                    </div>
                    <div class="proj-card-badges">
                        <?php foreach ($proj['badges'] as $b): ?>
                            <span class="proj-badge"><?= $b ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="proj-card-body">
                    <h3 class="text-[15px] font-black text-gray-900 mb-1.5 leading-tight">
                        <?= $proj['title'] ?>
                    </h3>
                    <p class="text-[12px] text-gray-500 leading-relaxed"><?= $proj['description'] ?></p>
                </div>

                <div class="proj-card-actions">
                    <?php foreach ($proj['actions'] as $act): ?>
                        <a href="<?= $act['url'] ?>"
                            class="proj-action <?= $act['primary'] ? 'primary' : 'secondary' ?>">
                            <?php if ($act['primary']): ?><i class="fa-solid fa-arrow-up-right-from-square mr-1.5 text-[10px]"></i><?php endif; ?>
                            <?= $act['label'] ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- Empty state -->
        <div id="proj-empty" style="display:none;grid-column:1/-1;padding:48px 24px;text-align:center">
            <i class="fa-solid fa-magnifying-glass" style="font-size:2rem;color:#cbd5e1;margin-bottom:12px;display:block"></i>
            <p style="font-size:13px;font-weight:700;color:#94a3b8">ไม่พบระบบที่ค้นหา</p>
            <p style="font-size:11px;color:#cbd5e1;margin-top:4px">ลองเปลี่ยนคำค้นหาหรือล้างตัวกรอง</p>
        </div>
    </div>
</div>
