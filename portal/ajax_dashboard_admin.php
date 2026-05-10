<?php
/**
 * portal/ajax_dashboard_admin.php
 *
 * Dashboard Builder backend — เฉพาะ admin ที่มี access_dashboard_admin หรือ superadmin
 *
 * entity:action
 *   widget:list, widget:get, widget:save, widget:delete, widget:reorder, widget:toggle
 *   dataset:list, dataset:upload, dataset:delete, dataset:rows
 *   catalog:get        — รวม predefined + custom datasets
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ajax_helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/dashboard_data_sources.php';

$adminRole = $_SESSION['admin_role'] ?? '';
$adminId   = (int)($_SESSION['admin_id'] ?? 0);
$isSuper   = $adminRole === 'superadmin';
$canEdit   = $isSuper || !empty($_SESSION['access_dashboard_admin']);

if (!$canEdit) {
    json_err('ไม่มีสิทธิ์แก้ไข Dashboard', 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

if (function_exists('validate_csrf_or_die')) validate_csrf_or_die();

$pdo    = db();
$entity = $_POST['entity'] ?? '';
$action = $_POST['action'] ?? '';

// ── Auto-migrate guard ────────────────────────────────────────────────────────
try {
    $pdo->query("SELECT 1 FROM ins_dashboard_widgets LIMIT 1");
} catch (PDOException $e) {
    json_err('ตาราง ins_dashboard_widgets ยังไม่ถูกสร้าง — กรุณารัน migrate_dashboard_builder.php ก่อน');
}
try {
    $pdo->query("SELECT 1 FROM ins_dashboard_workbooks LIMIT 1");
} catch (PDOException $e) {
    json_err('ตาราง ins_dashboard_workbooks ยังไม่ถูกสร้าง — กรุณารัน migrate_dashboard_workbooks.php ก่อน');
}

// Helper: หา workbook_id ของ default (กรณีไม่ส่งมา)
function _default_workbook_id(PDO $pdo): int
{
    $id = (int)$pdo->query("SELECT id FROM ins_dashboard_workbooks WHERE is_default = 1 LIMIT 1")->fetchColumn();
    if (!$id) {
        $id = (int)$pdo->query("SELECT id FROM ins_dashboard_workbooks ORDER BY sort_order ASC, id ASC LIMIT 1")->fetchColumn();
    }
    return $id;
}

try {
    switch ("$entity:$action") {

        // ════════════ WORKBOOKS ════════════
        case 'workbook:list': {
            $rows = $pdo->query("
                SELECT w.*,
                    (SELECT COUNT(*) FROM ins_dashboard_widgets WHERE workbook_id = w.id) AS widget_count
                FROM ins_dashboard_workbooks w
                ORDER BY w.sort_order ASC, w.id ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
            json_ok(['workbooks' => $rows]);
        }

        case 'workbook:get': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) json_err('ระบุ id ไม่ถูกต้อง');
            $stmt = $pdo->prepare("SELECT * FROM ins_dashboard_workbooks WHERE id = ?");
            $stmt->execute([$id]);
            $w = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$w) json_err('ไม่พบ workbook');
            json_ok(['workbook' => $w]);
        }

        case 'workbook:save': {
            $id          = (int)($_POST['id'] ?? 0);
            $name        = trim((string)($_POST['name'] ?? ''));
            $slugInput   = trim((string)($_POST['slug'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $icon        = trim((string)($_POST['icon'] ?? 'fa-chart-pie'));
            $color       = trim((string)($_POST['color'] ?? 'blue'));
            $isPublic    = (int)($_POST['is_public'] ?? 0) ? 1 : 0;
            $isDefault   = (int)($_POST['is_default'] ?? 0) ? 1 : 0;

            if ($name === '') json_err('กรุณาระบุชื่อ workbook');

            // Build slug จาก name ถ้าไม่ส่งมา
            $slug = $slugInput !== '' ? $slugInput : $name;
            $slug = preg_replace('/[^a-z0-9_\-]/', '-', strtolower($slug));
            $slug = preg_replace('/-+/', '-', trim($slug, '-'));
            if ($slug === '') $slug = 'workbook-' . time();
            $slug = substr($slug, 0, 60);

            // Ensure uniqueness
            $base = $slug; $i = 2;
            while (true) {
                $check = $pdo->prepare("SELECT id FROM ins_dashboard_workbooks WHERE slug = ? AND id <> ? LIMIT 1");
                $check->execute([$slug, $id ?: 0]);
                if (!$check->fetchColumn()) break;
                $slug = $base . '-' . $i++;
            }

            $pdo->beginTransaction();
            try {
                // Default toggle: ถ้า is_default=1 → unset default ของอื่นๆ
                if ($isDefault) {
                    $pdo->prepare("UPDATE ins_dashboard_workbooks SET is_default = 0")->execute();
                }

                if ($id > 0) {
                    $pdo->prepare("UPDATE ins_dashboard_workbooks SET
                        slug=?, name=?, description=?, icon=?, color=?, is_public=?, is_default=?
                        WHERE id=?")
                        ->execute([$slug, $name, $description, $icon, $color, $isPublic, $isDefault, $id]);
                } else {
                    $maxOrder = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM ins_dashboard_workbooks")->fetchColumn();
                    $pdo->prepare("INSERT INTO ins_dashboard_workbooks
                        (slug, name, description, icon, color, is_public, is_default, sort_order, created_by)
                        VALUES (?,?,?,?,?,?,?,?,?)")
                        ->execute([$slug, $name, $description, $icon, $color, $isPublic, $isDefault, $maxOrder + 1, $adminId ?: null]);
                    $id = (int)$pdo->lastInsertId();
                }

                // ตรวจให้แน่ใจว่ามี default workbook อย่างน้อย 1 ตัว
                $hasDefault = (int)$pdo->query("SELECT COUNT(*) FROM ins_dashboard_workbooks WHERE is_default = 1")->fetchColumn();
                if (!$hasDefault) {
                    $firstId = (int)$pdo->query("SELECT id FROM ins_dashboard_workbooks ORDER BY sort_order ASC, id ASC LIMIT 1")->fetchColumn();
                    if ($firstId) $pdo->prepare("UPDATE ins_dashboard_workbooks SET is_default = 1 WHERE id = ?")->execute([$firstId]);
                }

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            json_ok(['id' => $id, 'slug' => $slug, 'message' => 'บันทึก workbook เรียบร้อย']);
        }

        case 'workbook:delete': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) json_err('ระบุ id ไม่ถูกต้อง');

            // ห้ามลบ default ถ้าเหลือ workbook ตัวเดียว
            $total = (int)$pdo->query("SELECT COUNT(*) FROM ins_dashboard_workbooks")->fetchColumn();
            if ($total <= 1) json_err('ลบไม่ได้ — ต้องมีอย่างน้อย 1 workbook ในระบบ');

            // หา default ใหม่ (ถ้าลบ default)
            $isDef = (int)$pdo->query("SELECT is_default FROM ins_dashboard_workbooks WHERE id = $id")->fetchColumn();

            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM ins_dashboard_widgets WHERE workbook_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM ins_dashboard_workbooks WHERE id = ?")->execute([$id]);

                if ($isDef) {
                    $first = (int)$pdo->query("SELECT id FROM ins_dashboard_workbooks ORDER BY sort_order ASC, id ASC LIMIT 1")->fetchColumn();
                    if ($first) $pdo->prepare("UPDATE ins_dashboard_workbooks SET is_default = 1 WHERE id = ?")->execute([$first]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            json_ok(['message' => 'ลบ workbook + widget ในนั้นเรียบร้อย']);
        }

        case 'workbook:reorder': {
            $orderJson = $_POST['order'] ?? '[]';
            $order = json_decode($orderJson, true);
            if (!is_array($order)) json_err('order ไม่ถูกต้อง');
            $stmt = $pdo->prepare("UPDATE ins_dashboard_workbooks SET sort_order = ? WHERE id = ?");
            $pdo->beginTransaction();
            try {
                foreach ($order as $i => $id) $stmt->execute([$i + 1, (int)$id]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack(); throw $e;
            }
            json_ok(['message' => 'จัดลำดับเรียบร้อย']);
        }

        case 'catalog:get': {
            $catalog = dashboard_data_sources_catalog();
            $custom  = dashboard_custom_datasets($pdo);
            $merged  = [];
            foreach ($catalog as $key => $meta) {
                $merged[] = ['key' => $key] + $meta;
            }
            foreach ($custom as $key => $meta) {
                $merged[] = ['key' => $key] + $meta;
            }
            json_ok([
                'sources'      => $merged,
                'widget_types' => ['kpi','line','bar','donut','pie','area'],
                'colors'       => ['blue','emerald','amber','rose','purple','cyan','indigo','slate'],
                'sizes'        => ['sm','md','lg','xl'],
            ]);
        }

        case 'widget:list': {
            $workbookId = (int)($_POST['workbook_id'] ?? 0);
            if ($workbookId <= 0) $workbookId = _default_workbook_id($pdo);
            $stmt = $pdo->prepare("SELECT * FROM ins_dashboard_widgets WHERE workbook_id = ? ORDER BY sort_order ASC, id ASC");
            $stmt->execute([$workbookId]);
            json_ok(['widgets' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'workbook_id' => $workbookId]);
        }

        case 'widget:get': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) json_err('ระบุ id ไม่ถูกต้อง');
            $stmt = $pdo->prepare("SELECT * FROM ins_dashboard_widgets WHERE id = ?");
            $stmt->execute([$id]);
            $w = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$w) json_err('ไม่พบ widget');
            json_ok(['widget' => $w]);
        }

        case 'widget:save': {
            $id          = (int)($_POST['id'] ?? 0);
            $workbookId  = (int)($_POST['workbook_id'] ?? 0);
            if ($workbookId <= 0) $workbookId = _default_workbook_id($pdo);
            $widgetType  = trim((string)($_POST['widget_type'] ?? 'kpi'));
            $title       = trim((string)($_POST['title'] ?? ''));
            $subtitle    = trim((string)($_POST['subtitle'] ?? ''));
            $dataSource  = trim((string)($_POST['data_source'] ?? ''));
            $colorTheme  = trim((string)($_POST['color_theme'] ?? 'blue'));
            $size        = trim((string)($_POST['size'] ?? 'md'));
            $isVisible   = (int)($_POST['is_visible'] ?? 1);
            $isPublic    = (int)($_POST['is_public'] ?? 1);

            $allowedTypes  = ['kpi','line','bar','donut','pie','area','stat_group'];
            $allowedColors = ['blue','emerald','amber','rose','purple','cyan','indigo','slate'];
            $allowedSizes  = ['sm','md','lg','xl'];
            if (!in_array($widgetType, $allowedTypes, true))  json_err('ชนิด widget ไม่ถูกต้อง');
            if (!in_array($colorTheme, $allowedColors, true)) $colorTheme = 'blue';
            if (!in_array($size, $allowedSizes, true))        $size = 'md';
            if ($title === '') json_err('กรุณาระบุชื่อ widget');

            // ตรวจ data source ว่ามีจริง
            $catalog = dashboard_data_sources_catalog();
            $custom  = dashboard_custom_datasets($pdo);
            if (!isset($catalog[$dataSource]) && !isset($custom[$dataSource])) {
                json_err('ไม่พบ data source: ' . htmlspecialchars($dataSource));
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE ins_dashboard_widgets SET
                    widget_type=?, title=?, subtitle=?, data_source=?, color_theme=?, size=?,
                    is_visible=?, is_public=?
                    WHERE id=?");
                $stmt->execute([
                    $widgetType, $title, $subtitle, $dataSource, $colorTheme, $size,
                    $isVisible, $isPublic, $id
                ]);
                json_ok(['id' => $id, 'message' => 'บันทึกการแก้ไขเรียบร้อย']);
            } else {
                $maxOrder = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM ins_dashboard_widgets WHERE workbook_id = $workbookId")->fetchColumn();
                $stmt = $pdo->prepare("INSERT INTO ins_dashboard_widgets
                    (workbook_id, widget_type, title, subtitle, data_source, color_theme, size,
                     is_visible, is_public, sort_order, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([
                    $workbookId, $widgetType, $title, $subtitle, $dataSource, $colorTheme, $size,
                    $isVisible, $isPublic, $maxOrder + 1, $adminId ?: null
                ]);
                json_ok(['id' => (int)$pdo->lastInsertId(), 'message' => 'เพิ่ม widget เรียบร้อย']);
            }
        }

        case 'widget:delete': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) json_err('ระบุ id ไม่ถูกต้อง');
            $pdo->prepare("DELETE FROM ins_dashboard_widgets WHERE id = ?")->execute([$id]);
            json_ok(['message' => 'ลบ widget เรียบร้อย']);
        }

        case 'widget:toggle': {
            $id    = (int)($_POST['id'] ?? 0);
            $field = trim((string)($_POST['field'] ?? 'is_visible'));
            if ($id <= 0) json_err('ระบุ id ไม่ถูกต้อง');
            if (!in_array($field, ['is_visible', 'is_public'], true)) json_err('field ไม่ถูกต้อง');
            $pdo->prepare("UPDATE ins_dashboard_widgets SET $field = 1 - $field WHERE id = ?")
                ->execute([$id]);
            json_ok(['message' => 'อัปเดตเรียบร้อย']);
        }

        case 'widget:reorder': {
            $orderJson = $_POST['order'] ?? '[]';
            $order = json_decode($orderJson, true);
            if (!is_array($order)) json_err('order ไม่ถูกต้อง');

            $stmt = $pdo->prepare("UPDATE ins_dashboard_widgets SET sort_order = ? WHERE id = ?");
            $pdo->beginTransaction();
            try {
                foreach ($order as $i => $id) {
                    $stmt->execute([$i + 1, (int)$id]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            json_ok(['message' => 'จัดลำดับใหม่เรียบร้อย']);
        }

        case 'dataset:list': {
            $rows = $pdo->query("SELECT id, dataset_key, dataset_name, description,
                                        label_column, value_column, row_count, uploaded_at
                                 FROM ins_dashboard_datasets ORDER BY uploaded_at DESC")
                        ->fetchAll(PDO::FETCH_ASSOC);
            json_ok(['datasets' => $rows]);
        }

        case 'dataset:rows': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) json_err('ระบุ id ไม่ถูกต้อง');
            $stmt = $pdo->prepare("SELECT * FROM ins_dashboard_datasets WHERE id = ?");
            $stmt->execute([$id]);
            $d = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$d) json_err('ไม่พบ dataset');
            $d['rows'] = json_decode($d['rows_json'] ?: '[]', true) ?: [];
            unset($d['rows_json']);
            json_ok(['dataset' => $d]);
        }

        case 'dataset:upload': {
            $name        = trim((string)($_POST['dataset_name'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $labelCol    = trim((string)($_POST['label_column'] ?? 'label'));
            $valueCol    = trim((string)($_POST['value_column'] ?? 'value'));

            if ($name === '') json_err('กรุณาระบุชื่อ dataset');
            if (empty($_FILES['file']['name'])) json_err('ไม่พบไฟล์');
            if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) json_err('อัปโหลดไม่สำเร็จ');

            $tmp = $_FILES['file']['tmp_name'];
            $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['csv'], true)) {
                json_err('รองรับเฉพาะไฟล์ CSV (UTF-8) — สำหรับ Excel กรุณา save as CSV ก่อน');
            }

            $rows = [];
            $headers = [];
            $fp = fopen($tmp, 'r');
            if (!$fp) json_err('เปิดไฟล์ไม่สำเร็จ');
            $first = true;
            while (($cells = fgetcsv($fp)) !== false) {
                if ($first) {
                    $headers = array_map(fn($h) => trim((string)$h), $cells);
                    $first = false;
                    continue;
                }
                $row = [];
                foreach ($headers as $i => $h) {
                    $row[$h] = $cells[$i] ?? '';
                }
                if (!empty(array_filter($row, fn($v) => $v !== ''))) $rows[] = $row;
            }
            fclose($fp);

            if (empty($rows)) json_err('CSV ว่างเปล่าหรืออ่านไม่ได้ — ตรวจสอบ encoding (UTF-8 BOM ก็ได้)');
            if (!in_array($labelCol, $headers, true)) json_err("ไม่พบคอลัมน์ '$labelCol' ใน header");
            if (!in_array($valueCol, $headers, true)) json_err("ไม่พบคอลัมน์ '$valueCol' ใน header");

            // Generate dataset_key (slug-like)
            $key = preg_replace('/[^a-z0-9_]/', '_', strtolower($name));
            $key = trim($key, '_') ?: 'dataset_' . time();
            $key = substr($key, 0, 60);

            // Ensure uniqueness
            $base = $key; $i = 2;
            while (true) {
                $check = $pdo->prepare("SELECT 1 FROM ins_dashboard_datasets WHERE dataset_key = ?");
                $check->execute([$key]);
                if (!$check->fetchColumn()) break;
                $key = $base . '_' . $i++;
            }

            $stmt = $pdo->prepare("INSERT INTO ins_dashboard_datasets
                (dataset_key, dataset_name, description, label_column, value_column, rows_json, row_count, uploaded_by)
                VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $key, $name, $description, $labelCol, $valueCol,
                json_encode($rows, JSON_UNESCAPED_UNICODE), count($rows), $adminId ?: null
            ]);
            json_ok([
                'id' => (int)$pdo->lastInsertId(),
                'dataset_key' => $key,
                'row_count' => count($rows),
                'message' => 'อัปโหลดเรียบร้อย — ' . count($rows) . ' แถว',
            ]);
        }

        case 'dataset:delete': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) json_err('ระบุ id ไม่ถูกต้อง');

            // Find widgets ที่อ้างอิง dataset นี้
            $key = $pdo->prepare("SELECT dataset_key FROM ins_dashboard_datasets WHERE id = ?");
            $key->execute([$id]);
            $dsKey = (string)$key->fetchColumn();
            if ($dsKey === '') json_err('ไม่พบ dataset');

            $rc = $pdo->prepare("SELECT COUNT(*) FROM ins_dashboard_widgets WHERE data_source = ?");
            $rc->execute(['custom_' . $dsKey]);
            $refCount = (int)$rc->fetchColumn();
            if ($refCount > 0) json_err("ไม่สามารถลบได้ — มี $refCount widget(s) ใช้ dataset นี้อยู่");

            $pdo->prepare("DELETE FROM ins_dashboard_datasets WHERE id = ?")->execute([$id]);
            json_ok(['message' => 'ลบ dataset เรียบร้อย']);
        }

        default:
            json_err('ไม่รู้จัก entity:action — ' . htmlspecialchars("$entity:$action"));
    }
} catch (Throwable $e) {
    error_log('[ajax_dashboard_admin] ' . $e->getMessage());
    json_err('ระบบขัดข้อง: ' . $e->getMessage(), 500);
}
