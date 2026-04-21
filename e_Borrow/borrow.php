<?php
// e_Borrow/borrow.php
declare(strict_types=1);
@session_start();
include('includes/check_student_session.php');

// ใช้ DB กลาง (Correct path to Root)
require_once __DIR__ . '/includes/db_connect.php';

$student_id = (int)$_SESSION['student_id'];

try {
    $pdo = db();
    $sql = "SELECT id, name, description, image_url, available_quantity 
            FROM borrow_categories 
            WHERE available_quantity > 0
            ORDER BY name ASC";
    $stmt_equip = $pdo->query($sql);
    $equipment_types = $stmt_equip->fetchAll();
} catch (PDOException $e) {
    $equipment_types = [];
    $equip_error = "เกิดข้อผิดพลาด: " . $e->getMessage();
}

$page_title  = "ยืมอุปกรณ์";
$active_page = 'borrow';
include('includes/student_header.php');
?>

<style>
/* ===== PAGE WRAPPER ===== */
.page-wrap { padding: 0 0 80px; }

/* ===== TOP HEADER ===== */
.borrow-header {
    background: linear-gradient(135deg, #0052CC 0%, #0070f3 100%);
    padding: 30px 20px 45px; /* เผื่อที่ให้ search bar ลอย */
    text-align: center;
    position: relative;
}
.borrow-header::before {
    content: ''; position: absolute; inset: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Ccircle cx='30' cy='30' r='28'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    pointer-events: none;
}
.borrow-header h2 { color: #fff; font-size: 1.3rem; font-weight: 700; margin: 0 0 4px; position: relative; z-index: 1;}
.borrow-header p  { color: rgba(255,255,255,.8); font-size: .85rem; margin: 0; position: relative; z-index: 1;}

/* ===== FLOATING SEARCH ===== */
.search-container {
    margin: -24px 16px 20px;
    position: relative; z-index: 10;
}
.search-box {
    background: #fff;
    border-radius: 16px;
    padding: 12px 18px;
    display: flex; align-items: center; gap: 12px;
    box-shadow: 0 8px 24px rgba(0,0,0,.08);
    border: 1px solid rgba(0,82,204,.08);
    transition: box-shadow .2s;
}
.search-box:focus-within {
    box-shadow: 0 8px 24px rgba(11,102,35,.15);
    border-color: rgba(11,102,35,.2);
}
.search-box i.fa-search { color: #888; font-size: 1.1rem; }
.search-box input {
    flex: 1; border: none; outline: none;
    font-size: .95rem; font-family: inherit;
    background: transparent; color: #1a1a1a;
}
.search-box input::placeholder { color: #aaa; }
.btn-clear {
    background: #f1f5f9; color: #64748b;
    border: none; border-radius: 50%;
    width: 28px; height: 28px;
    display: none; align-items: center; justify-content: center;
    cursor: pointer; font-size: .8rem;
    transition: background .2s, color .2s;
}
.btn-clear:hover { background: #e2e8f0; color: #334155; }

/* ===== SECTION BODY ===== */
.section-body { padding: 0 16px; }

/* ===== GRID & CARDS ===== */
.equip-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 14px;
}
@media (min-width: 500px) {
    .equip-grid { grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); }
}

.equip-card {
    background: #fff;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 2px 12px rgba(0,0,0,.06);
    border: 1px solid rgba(0,0,0,.04);
    display: flex; flex-direction: column;
    transition: transform .2s, box-shadow .2s;
    position: relative;
}
.equip-card:hover { transform: translateY(-3px); box-shadow: 0 6px 16px rgba(0,0,0,.1); }

/* รูปภาพ */
.card-img-wrap {
    width: 100%; aspect-ratio: 4/3;
    background: #f8fafc;
    position: relative;
    display: flex; align-items: center; justify-content: center;
    overflow: hidden;
}
.card-img-wrap img { width: 100%; height: 100%; object-fit: cover; }
.card-img-placeholder { font-size: 2.5rem; color: #cbd5e1; }

/* Stock Badge มุมขวาบนของรูป */
.stock-badge {
    position: absolute; top: 10px; right: 10px;
    background: rgba(255,255,255,.9); backdrop-filter: blur(4px);
    padding: 4px 8px; border-radius: 8px;
    font-size: .7rem; font-weight: 700; color: #0052CC;
    box-shadow: 0 2px 8px rgba(0,0,0,.1);
    display: flex; align-items: center; gap: 4px;
}

/* เนื้อหา Card */
.card-body { padding: 12px; flex: 1; display: flex; flex-direction: column; }
.card-body h3 {
    font-size: .9rem; font-weight: 700; color: #1e293b;
    margin: 0 0 4px;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
    line-height: 1.3;
}
.card-body p {
    font-size: .75rem; color: #64748b; margin: 0 0 12px;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
    line-height: 1.4; flex: 1;
}

/* ปุ่มยืม */
.btn-request {
    width: 100%; padding: 10px; border-radius: 10px;
    border: none; background: #e0f2fe; color: #0369a1;
    font-size: .85rem; font-weight: 700; font-family: inherit;
    cursor: pointer; transition: all .2s;
    display: flex; align-items: center; justify-content: center; gap: 6px;
}
.btn-request i { font-size: .9rem; }
.btn-request:hover { background: #bae6fd; color: #0284c7; }
.btn-request:active { transform: scale(.97); }

.empty-state {
    text-align: center; padding: 40px 20px;
    background: #fff; border-radius: 18px; grid-column: 1/-1;
    box-shadow: 0 2px 10px rgba(0,0,0,.05);
}
.empty-state i { font-size: 3rem; color: #cbd5e1; margin-bottom: 12px; }
.empty-state p { color: #64748b; font-size: .9rem; margin: 0; }

/* ===== DARK MODE OVERRIDES ===== */
body.dark-mode .search-box { background: #162040; border-color: rgba(255,255,255,.08); }
body.dark-mode .search-box input { color: #e2e8f0; }
body.dark-mode .btn-clear { background: rgba(255,255,255,.1); color: #94a3b8; }
body.dark-mode .equip-card { background: #162040; border-color: rgba(255,255,255,.05); }
body.dark-mode .card-body h3 { color: #e2e8f0; }
body.dark-mode .card-body p { color: #94a3b8; }
body.dark-mode .card-img-wrap { background: #0f1a35; }
body.dark-mode .card-img-placeholder { color: #334155; }
body.dark-mode .stock-badge { background: rgba(30,50,80,.9); color: #60a5fa; border: 1px solid rgba(255,255,255,.1); }
body.dark-mode .btn-request { background: rgba(14,165,233,.15); color: #38bdf8; }
body.dark-mode .empty-state { background: #162040; }
</style>

<div class="page-wrap">
    
    <div class="borrow-header">
        <h2><i class="fas fa-boxes-stacked" style="margin-right:8px;"></i>ยืมอุปกรณ์</h2>
        <p>เลือกอุปกรณ์การแพทย์ที่ต้องการยืม</p>
    </div>

    <!-- แถบค้นหา -->
    <div class="search-container">
        <div class="search-box">
            <i class="fas fa-search"></i>
            <input type="text" id="liveSearchInput" placeholder="พิมพ์ชื่ออุปกรณ์เพื่อค้นหา...">
            <button type="button" id="clearSearchBtn" class="btn-clear"><i class="fas fa-times"></i></button>
        </div>
    </div>

    <div class="section-body">
        <?php if (!empty($equip_error)): ?>
            <div style="background:#fee2e2; color:#991b1b; padding:12px; border-radius:12px; margin-bottom:16px; font-size:.85rem; font-weight:600;">
                <?= htmlspecialchars($equip_error) ?>
            </div>
        <?php endif; ?>

        <div class="equip-grid" id="equipment-grid-container">
            <?php if (empty($equipment_types)): ?>
                <div class="empty-state">
                    <i class="fas fa-box-open"></i>
                    <p>ไม่มีอุปกรณ์ว่างในขณะนี้</p>
                </div>
            <?php else: ?>
                <?php foreach ($equipment_types as $item): ?>
                    <div class="equip-card" data-name="<?= htmlspecialchars(strtolower($item['name'])) ?>">
                        <div class="card-img-wrap">
                            <?php if (!empty($item['image_url'])): ?>
                                <img src="<?= htmlspecialchars($item['image_url']) ?>" alt="รูปภาพ"
                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <div class="card-img-placeholder" style="display:none;"><i class="fas fa-image"></i></div>
                            <?php else: ?>
                                <div class="card-img-placeholder"><i class="fas fa-camera"></i></div>
                            <?php endif; ?>

                            <div class="stock-badge">
                                <i class="fas fa-check-circle"></i> ว่าง <?= $item['available_quantity'] ?>
                            </div>
                        </div>

                        <div class="card-body">
                            <h3><?= htmlspecialchars($item['name']) ?></h3>
                            <p><?= htmlspecialchars($item['description'] ?: 'ไม่มีรายละเอียด') ?></p>
                            
                            <button class="btn-request" onclick="openRequestPopup(<?= $item['id'] ?>, '<?= htmlspecialchars(addslashes($item['name'])) ?>')">
                                <i class="fas fa-hand-holding-medical"></i> ขอยืม
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- ใช้ script ชุดเดิมสำหรับการทำงานของ Popup (student_app.js) -->
<script src="//cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="assets/js/student_app.js"></script>

<script>
// Live Search แบบ Client-side (กรองจากของในหน้า)
document.addEventListener('DOMContentLoaded', () => {
    const input = document.getElementById('liveSearchInput');
    const clearBtn = document.getElementById('clearSearchBtn');
    const cards = document.querySelectorAll('.equip-card');

    function filterCards() {
        const query = input.value.trim().toLowerCase();
        let found = 0;

        clearBtn.style.display = query.length > 0 ? 'flex' : 'none';

        cards.forEach(card => {
            const name = card.getAttribute('data-name') || '';
            if (name.includes(query)) {
                card.style.display = 'flex';
                found++;
            } else {
                card.style.display = 'none';
            }
        });
    }

    input.addEventListener('input', filterCards);
    
    clearBtn.addEventListener('click', () => {
        input.value = '';
        filterCards();
        input.focus();
    });
});
</script>

<?php include('includes/student_footer.php'); ?>