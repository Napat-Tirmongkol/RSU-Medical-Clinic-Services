<?php
// admin/print_barcode_bulk.php
include('../includes/check_session.php');
require_once(__DIR__ . '/../../../config/db_connect.php');
$pdo = db();

$allowed_roles = ['admin', 'editor'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    die("คุณไม่มีสิทธิ์เข้าถึงหน้านี้");
}

// รับข้อมูล JSON (โครงสร้างใหม่: [{ type_id: 1, selected_ids: [101, 102] }, ...])
$data_json = $_GET['data'] ?? '[]';
$requests = json_decode($data_json, true);

if (empty($requests)) {
    die("ไม่มีข้อมูลสำหรับพิมพ์");
}

$items_to_print = [];

// รวบรวม ID ทั้งหมดที่จะพิมพ์
$all_ids = [];
foreach ($requests as $req) {
    if (!empty($req['selected_ids']) && is_array($req['selected_ids'])) {
        foreach ($req['selected_ids'] as $id) {
            $all_ids[] = (int)$id;
        }
    }
}

if (!empty($all_ids)) {
    // Query ครั้งเดียวด้วย IN (...)
    $placeholders = implode(',', array_fill(0, count($all_ids), '?'));
    $sql = "SELECT id, name, serial_number FROM borrow_items WHERE id IN ($placeholders)";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($all_ids);
        $items_to_print = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("print_barcode error: " . $e->getMessage()); exit("เกิดข้อผิดพลาด");
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>พิมพ์บาร์โค้ดจำนวนมาก - MedLoan</title>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    
    <style>
        @font-face { font-family: 'RSU'; src: url('../assets/fonts/RSU_Regular.ttf') format('truetype'); }
        body { font-family: 'RSU', sans-serif; background: #f0f0f0; margin: 0; padding: 20px; }
        .page {
            background: white; width: 210mm; min-height: 297mm; margin: 0 auto; padding: 10mm;
            box-shadow: 0 0 10px rgba(0,0,0,0.1); box-sizing: border-box;
            display: grid; grid-template-columns: repeat(3, 1fr); grid-auto-rows: 120px; gap: 10px; align-content: start; 
        }
        .barcode-item { border: 1px dashed #ccc; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 5px; page-break-inside: avoid; }
        .item-name { font-size: 14px; font-weight: bold; text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; line-height: 1.2; }
        .item-serial { font-size: 12px; color: #555; margin-bottom: 2px; }
        .no-print { text-align: center; margin-bottom: 20px; }
        .btn { padding: 10px 20px; background: #0B6623; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; text-decoration: none; display: inline-block; }
        @media print {
            body { background: none; padding: 0; }
            .page { width: 100%; box-shadow: none; margin: 0; padding: 5mm; height: auto; }
            .no-print { display: none; }
            .barcode-item { border: 1px solid #eee; }
        }
    </style>
</head>
<body>

    <div class="no-print">
        <h2>รายการบาร์โค้ด (<?php echo count($items_to_print); ?> รายการ)</h2>
        <a href="javascript:window.print()" class="btn">🖨️ สั่งพิมพ์</a>
        <a href="javascript:window.close()" class="btn" style="background: #666;">ปิดหน้าต่าง</a>
    </div>

    <div class="page">
        <?php if (empty($items_to_print)): ?>
            <div style="grid-column: 1 / -1; text-align: center; padding: 50px;">
                <h3 style="color: red;">ไม่พบข้อมูลอุปกรณ์</h3>
            </div>
        <?php else: ?>
            <?php foreach ($items_to_print as $item): 
                $barcode_val = "EQ-" . $item['id'];
            ?>
                <div class="barcode-item">
                    <div class="item-name"><?php echo htmlspecialchars($item['name']); ?></div>
                    <?php if(!empty($item['serial_number'])): ?>
                        <div class="item-serial">S/N: <?php echo htmlspecialchars($item['serial_number']); ?></div>
                    <?php endif; ?>
                    
                    <svg class="barcode"
                         jsbarcode-format="CODE128"
                         jsbarcode-value="<?php echo $barcode_val; ?>"
                         jsbarcode-textmargin="0"
                         jsbarcode-fontoptions="bold"
                         jsbarcode-height="40"
                         jsbarcode-width="1.5"
                         jsbarcode-fontsize="14">
                    </svg>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script>
        window.onload = function() {
            try { JsBarcode(".barcode").init(); } catch (e) { console.error(e); }
        };
    </script>
</body>
</html>