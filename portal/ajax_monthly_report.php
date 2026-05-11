<?php
/**
 * portal/ajax_monthly_report.php
 * AJAX endpoint สำหรับรายงานการดำเนินงานประจำเดือน
 *
 * Pattern: entity:action
 *   department:list, department:save, department:delete
 *   template:list, template:save, template:delete
 *   report:list, report:get, report:create_from_template, report:save_meta,
 *               report:submit, report:approve, report:revert
 *   item:save, item:delete
 *   history:list
 *   staff:departments_for_self
 *
 * Auth: access_monthly_report หรือ access_director_view หรือ superadmin
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ajax_helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

$adminRole = $_SESSION['admin_role'] ?? '';
$adminId   = (int)($_SESSION['admin_id'] ?? 0);
$isSuper   = $adminRole === 'superadmin';
$isDirector = $isSuper || !empty($_SESSION['access_director_view']);
$canEdit    = $isSuper || !empty($_SESSION['access_monthly_report']);

if (!$canEdit && !$isDirector) {
    json_err('ไม่มีสิทธิ์เข้าถึงระบบรายงาน', 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}
validate_csrf_or_die();

$entity = $_POST['entity'] ?? '';
$action = $_POST['action'] ?? '';
$key    = "$entity:$action";

$pdo = db();

// ── helpers ────────────────────────────────────────────────────────────────
function mr_log(PDO $pdo, int $reportId, string $action, $oldValue, $newValue, ?int $byId, string $note = ''): void {
    $stmt = $pdo->prepare("INSERT INTO sys_monthly_report_history
        (report_id, action, changed_by, old_value, new_value, note)
        VALUES (?,?,?,?,?,?)");
    $stmt->execute([
        $reportId, $action, $byId ?: null,
        is_string($oldValue) ? $oldValue : json_encode($oldValue, JSON_UNESCAPED_UNICODE),
        is_string($newValue) ? $newValue : json_encode($newValue, JSON_UNESCAPED_UNICODE),
        $note,
    ]);
}

function mr_user_department_id(): ?int {
    $deptId = $_SESSION['department_id'] ?? null;
    return $deptId ? (int)$deptId : null;
}

function mr_can_edit_report(PDO $pdo, int $reportId, bool $isSuper, bool $isDirector, ?int $userDeptId): bool {
    if ($isSuper) return true;
    $stmt = $pdo->prepare("SELECT department_id, status FROM sys_monthly_reports WHERE id = ?");
    $stmt->execute([$reportId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) return false;
    // approved → ห้ามแก้ (ยกเว้น director revert)
    if ($r['status'] === 'approved') return false;
    return $userDeptId !== null && (int)$r['department_id'] === $userDeptId;
}

// ── routes ─────────────────────────────────────────────────────────────────
try {
    switch ($key) {

        // ───────── Departments ─────────
        case 'department:list': {
            $rows = $pdo->query("SELECT id, name, description, sort_order, active
                                 FROM sys_departments
                                 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
            json_ok(['departments' => $rows]);
        }

        case 'department:save': {
            if (!$isSuper) json_err('เฉพาะ superadmin', 403);
            $id    = (int)($_POST['id'] ?? 0);
            $name  = trim((string)($_POST['name'] ?? ''));
            $desc  = trim((string)($_POST['description'] ?? '')) ?: null;
            $sort  = (int)($_POST['sort_order'] ?? 0);
            $active= (int)($_POST['active'] ?? 1);
            if ($name === '') json_err('กรุณาระบุชื่อฝ่าย');

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE sys_departments
                    SET name=?, description=?, sort_order=?, active=? WHERE id=?");
                $stmt->execute([$name, $desc, $sort, $active, $id]);
                json_ok(['id' => $id, 'message' => 'บันทึกฝ่ายเรียบร้อย']);
            }
            $stmt = $pdo->prepare("INSERT INTO sys_departments (name, description, sort_order, active)
                                   VALUES (?,?,?,?)");
            $stmt->execute([$name, $desc, $sort, $active]);
            json_ok(['id' => (int)$pdo->lastInsertId(), 'message' => 'เพิ่มฝ่ายเรียบร้อย']);
        }

        case 'department:delete': {
            if (!$isSuper) json_err('เฉพาะ superadmin', 403);
            $id = (int)($_POST['id'] ?? 0);
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM sys_monthly_reports WHERE department_id = ?");
            $cnt->execute([$id]);
            if ((int)$cnt->fetchColumn() > 0) {
                json_err('ลบไม่ได้ — มีรายงานของฝ่ายนี้อยู่ในระบบ');
            }
            $pdo->prepare("UPDATE sys_staff SET department_id = NULL WHERE department_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM sys_report_templates WHERE department_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM sys_departments WHERE id = ?")->execute([$id]);
            json_ok(['message' => 'ลบฝ่ายเรียบร้อย']);
        }

        // ───────── Templates ─────────
        case 'template:list': {
            $deptId = (int)($_POST['department_id'] ?? 0);
            $where  = $deptId > 0 ? 'WHERE department_id = ?' : '';
            $params = $deptId > 0 ? [$deptId] : [];
            $stmt = $pdo->prepare("SELECT t.*, d.name AS department_name
                                   FROM sys_report_templates t
                                   JOIN sys_departments d ON d.id = t.department_id
                                   $where
                                   ORDER BY t.department_id, t.sort_order, t.id");
            $stmt->execute($params);
            json_ok(['templates' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }

        case 'template:save': {
            if (!$isSuper) json_err('เฉพาะ superadmin', 403);
            $id     = (int)($_POST['id'] ?? 0);
            $deptId = (int)($_POST['department_id'] ?? 0);
            $cat    = trim((string)($_POST['category'] ?? '')) ?: null;
            $act    = trim((string)($_POST['activity'] ?? ''));
            $detail = trim((string)($_POST['detail_default'] ?? '')) ?: null;
            $hint   = trim((string)($_POST['hint'] ?? '')) ?: null;
            $sort   = (int)($_POST['sort_order'] ?? 0);
            $active = (int)($_POST['active'] ?? 1);
            if ($deptId <= 0 || $act === '') json_err('กรุณาระบุฝ่ายและชื่อกิจกรรม');

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE sys_report_templates SET
                    department_id=?, category=?, activity=?, detail_default=?, hint=?, sort_order=?, active=?
                    WHERE id=?");
                $stmt->execute([$deptId, $cat, $act, $detail, $hint, $sort, $active, $id]);
                json_ok(['id' => $id]);
            }
            $stmt = $pdo->prepare("INSERT INTO sys_report_templates
                (department_id, category, activity, detail_default, hint, sort_order, active)
                VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$deptId, $cat, $act, $detail, $hint, $sort, $active]);
            json_ok(['id' => (int)$pdo->lastInsertId()]);
        }

        case 'template:delete': {
            if (!$isSuper) json_err('เฉพาะ superadmin', 403);
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM sys_report_templates WHERE id = ?")->execute([$id]);
            json_ok(['message' => 'ลบ template เรียบร้อย']);
        }

        // ───────── Reports ─────────
        case 'report:list': {
            $year   = (int)($_POST['year'] ?? 0);
            $month  = (int)($_POST['month'] ?? 0);
            $deptId = (int)($_POST['department_id'] ?? 0);
            $page   = max(1, (int)($_POST['page'] ?? 1));
            $size   = min(50, max(5, (int)($_POST['page_size'] ?? 20)));
            $offset = ($page - 1) * $size;

            $where = []; $params = [];
            // Restrict ดูรายงาน:
            // - director / superadmin: ทุกฝ่าย
            // - editor (มี access_monthly_report): เฉพาะฝ่ายตัวเอง
            $userDept = mr_user_department_id();
            if (!$isDirector) {
                if (!$userDept) json_err('บัญชีของคุณยังไม่ได้กำหนดฝ่าย กรุณาติดต่อ admin', 403);
                $where[] = 'r.department_id = ?'; $params[] = $userDept;
            } elseif ($deptId > 0) {
                $where[] = 'r.department_id = ?'; $params[] = $deptId;
            }
            if ($year  > 0)  { $where[] = 'r.report_year = ?';  $params[] = $year; }
            if ($month > 0)  { $where[] = 'r.report_month = ?'; $params[] = $month; }

            $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM sys_monthly_reports r $sqlWhere");
            $cntStmt->execute($params);
            $total = (int)$cntStmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT r.*, d.name AS department_name,
                                          (SELECT COUNT(*) FROM sys_monthly_report_items WHERE report_id = r.id) AS item_count
                                   FROM sys_monthly_reports r
                                   JOIN sys_departments d ON d.id = r.department_id
                                   $sqlWhere
                                   ORDER BY r.report_year DESC, r.report_month DESC, d.sort_order
                                   LIMIT $size OFFSET $offset");
            $stmt->execute($params);
            json_ok([
                'reports' => $stmt->fetchAll(PDO::FETCH_ASSOC),
                'total'   => $total,
                'page'    => $page,
                'pages'   => max(1, (int)ceil($total / $size)),
            ]);
        }

        case 'report:get': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) json_err('ระบุ id ไม่ถูกต้อง');

            $rs = $pdo->prepare("SELECT r.*, d.name AS department_name
                                 FROM sys_monthly_reports r
                                 JOIN sys_departments d ON d.id = r.department_id
                                 WHERE r.id = ?");
            $rs->execute([$id]);
            $report = $rs->fetch(PDO::FETCH_ASSOC);
            if (!$report) json_err('ไม่พบรายงาน');

            // สิทธิ์ดู: director เห็นทุก / editor เฉพาะฝ่ายตัวเอง
            if (!$isDirector) {
                $userDept = mr_user_department_id();
                if (!$userDept || (int)$report['department_id'] !== $userDept) {
                    json_err('ไม่มีสิทธิ์ดูรายงานของฝ่ายอื่น', 403);
                }
            }

            $items = $pdo->prepare("SELECT * FROM sys_monthly_report_items
                                    WHERE report_id = ? ORDER BY sort_order, id");
            $items->execute([$id]);

            $hist = $pdo->prepare("SELECT h.*, COALESCE(s.full_name, s.username) AS by_name
                                   FROM sys_monthly_report_history h
                                   LEFT JOIN sys_staff s ON s.id = h.changed_by
                                   WHERE h.report_id = ?
                                   ORDER BY h.changed_at DESC LIMIT 100");
            $hist->execute([$id]);

            json_ok([
                'report'  => $report,
                'items'   => $items->fetchAll(PDO::FETCH_ASSOC),
                'history' => $hist->fetchAll(PDO::FETCH_ASSOC),
            ]);
        }

        case 'report:create_from_template': {
            $deptId = (int)($_POST['department_id'] ?? 0);
            $year   = (int)($_POST['year'] ?? 0);
            $month  = (int)($_POST['month'] ?? 0);

            if (!$isDirector) {
                $userDept = mr_user_department_id();
                if (!$userDept) json_err('บัญชีของคุณยังไม่ได้กำหนดฝ่าย', 403);
                $deptId = $userDept; // editor → ใช้ฝ่ายตัวเองเสมอ
            }
            if ($deptId <= 0 || $year < 2020 || $month < 1 || $month > 12) {
                json_err('กรุณาระบุฝ่าย/ปี/เดือนให้ถูกต้อง');
            }

            // ถ้ามีอยู่แล้ว — return existing
            $exists = $pdo->prepare("SELECT id FROM sys_monthly_reports
                                     WHERE department_id=? AND report_year=? AND report_month=?");
            $exists->execute([$deptId, $year, $month]);
            $existingId = (int)$exists->fetchColumn();
            if ($existingId > 0) json_ok(['id' => $existingId, 'created' => false]);

            $pdo->beginTransaction();
            try {
                $ins = $pdo->prepare("INSERT INTO sys_monthly_reports
                    (department_id, report_year, report_month, status, created_by)
                    VALUES (?,?,?, 'draft', ?)");
                $ins->execute([$deptId, $year, $month, $adminId ?: null]);
                $reportId = (int)$pdo->lastInsertId();

                $tpl = $pdo->prepare("SELECT id, category, activity, detail_default, sort_order
                                      FROM sys_report_templates
                                      WHERE department_id = ? AND active = 1
                                      ORDER BY sort_order, id");
                $tpl->execute([$deptId]);
                $insItem = $pdo->prepare("INSERT INTO sys_monthly_report_items
                    (report_id, template_id, category, activity, detail, sort_order)
                    VALUES (?,?,?,?,?,?)");
                foreach ($tpl->fetchAll(PDO::FETCH_ASSOC) as $t) {
                    $insItem->execute([
                        $reportId, $t['id'], $t['category'], $t['activity'],
                        $t['detail_default'], $t['sort_order']
                    ]);
                }

                mr_log($pdo, $reportId, 'created', null, ['year' => $year, 'month' => $month, 'department_id' => $deptId], $adminId);
                $pdo->commit();
                json_ok(['id' => $reportId, 'created' => true]);
            } catch (Throwable $e) {
                $pdo->rollBack();
                json_err('สร้างรายงานไม่สำเร็จ: ' . $e->getMessage());
            }
        }

        case 'report:save_meta': {
            $id      = (int)($_POST['id'] ?? 0);
            $meeting = trim((string)($_POST['meeting_info'] ?? '')) ?: null;
            $userDept = mr_user_department_id();
            if (!mr_can_edit_report($pdo, $id, $isSuper, $isDirector, $userDept)) {
                json_err('ไม่มีสิทธิ์แก้ไขรายงานนี้ (อาจถูก approve แล้ว)', 403);
            }
            $stmt = $pdo->prepare("UPDATE sys_monthly_reports SET meeting_info=? WHERE id=?");
            $stmt->execute([$meeting, $id]);
            mr_log($pdo, $id, 'meta_saved', null, ['meeting_info' => $meeting], $adminId);
            json_ok(['message' => 'บันทึกแล้ว']);
        }

        case 'report:submit': {
            $id = (int)($_POST['id'] ?? 0);
            $userDept = mr_user_department_id();
            if (!mr_can_edit_report($pdo, $id, $isSuper, $isDirector, $userDept)) {
                json_err('ไม่มีสิทธิ์ส่งรายงานนี้', 403);
            }
            $stmt = $pdo->prepare("UPDATE sys_monthly_reports
                SET status='submitted', submitted_at=NOW(), submitted_by=?
                WHERE id=? AND status='draft'");
            $stmt->execute([$adminId ?: null, $id]);
            if ($stmt->rowCount() === 0) json_err('รายงานนี้ไม่ใช่ draft แล้ว');
            mr_log($pdo, $id, 'submitted', null, null, $adminId);
            json_ok(['message' => 'ส่งรายงานเรียบร้อย — รอผู้อำนวยการตรวจสอบ']);
        }

        case 'report:approve': {
            if (!$isDirector) json_err('เฉพาะผู้อำนวยการ', 403);
            $id   = (int)($_POST['id'] ?? 0);
            $note = trim((string)($_POST['note'] ?? '')) ?: null;
            $stmt = $pdo->prepare("UPDATE sys_monthly_reports
                SET status='approved', approved_at=NOW(), approved_by=?, approved_note=?
                WHERE id=? AND status IN ('submitted','draft')");
            $stmt->execute([$adminId ?: null, $note, $id]);
            if ($stmt->rowCount() === 0) json_err('รายงานนี้ถูก approve ไปแล้ว');
            mr_log($pdo, $id, 'approved', null, ['note' => $note], $adminId);
            json_ok(['message' => 'อนุมัติรายงานเรียบร้อย']);
        }

        case 'report:revert': {
            // กลับไป draft (เพื่อให้แก้ไขได้ต่อ)
            if (!$isDirector) json_err('เฉพาะผู้อำนวยการ', 403);
            $id   = (int)($_POST['id'] ?? 0);
            $note = trim((string)($_POST['note'] ?? '')) ?: null;
            $stmt = $pdo->prepare("UPDATE sys_monthly_reports
                SET status='draft', approved_at=NULL, approved_by=NULL, submitted_at=NULL, submitted_by=NULL
                WHERE id=?");
            $stmt->execute([$id]);
            mr_log($pdo, $id, 'reverted', null, ['note' => $note], $adminId);
            json_ok(['message' => 'ส่งกลับให้แก้ไขเรียบร้อย']);
        }

        // ───────── Items (line in report) ─────────
        case 'item:save': {
            $id        = (int)($_POST['id'] ?? 0);
            $reportId  = (int)($_POST['report_id'] ?? 0);
            $category  = trim((string)($_POST['category'] ?? '')) ?: null;
            $activity  = trim((string)($_POST['activity'] ?? ''));
            $detail    = trim((string)($_POST['detail'] ?? '')) ?: null;
            $result    = trim((string)($_POST['result'] ?? '')) ?: null;
            $suggestion= trim((string)($_POST['suggestion'] ?? '')) ?: null;
            $sort      = (int)($_POST['sort_order'] ?? 0);

            $userDept = mr_user_department_id();
            if (!mr_can_edit_report($pdo, $reportId, $isSuper, $isDirector, $userDept)) {
                json_err('ไม่มีสิทธิ์แก้ไขรายงานนี้', 403);
            }
            if ($activity === '') json_err('กรุณาระบุชื่อกิจกรรม');

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE sys_monthly_report_items SET
                    category=?, activity=?, detail=?, result=?, suggestion=?, sort_order=?
                    WHERE id=? AND report_id=?");
                $stmt->execute([$category, $activity, $detail, $result, $suggestion, $sort, $id, $reportId]);
                mr_log($pdo, $reportId, 'item_edited', null, ['item_id' => $id, 'activity' => $activity], $adminId);
                json_ok(['id' => $id]);
            }
            $stmt = $pdo->prepare("INSERT INTO sys_monthly_report_items
                (report_id, category, activity, detail, result, suggestion, sort_order)
                VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$reportId, $category, $activity, $detail, $result, $suggestion, $sort]);
            $newId = (int)$pdo->lastInsertId();
            mr_log($pdo, $reportId, 'item_added', null, ['item_id' => $newId, 'activity' => $activity], $adminId);
            json_ok(['id' => $newId]);
        }

        case 'item:delete': {
            $id = (int)($_POST['id'] ?? 0);
            $row = $pdo->prepare("SELECT report_id, activity FROM sys_monthly_report_items WHERE id = ?");
            $row->execute([$id]);
            $r = $row->fetch(PDO::FETCH_ASSOC);
            if (!$r) json_err('ไม่พบข้อมูล');

            $userDept = mr_user_department_id();
            if (!mr_can_edit_report($pdo, (int)$r['report_id'], $isSuper, $isDirector, $userDept)) {
                json_err('ไม่มีสิทธิ์แก้ไขรายงานนี้', 403);
            }

            $pdo->prepare("DELETE FROM sys_monthly_report_items WHERE id = ?")->execute([$id]);
            mr_log($pdo, (int)$r['report_id'], 'item_deleted', $r['activity'], null, $adminId);
            json_ok(['message' => 'ลบเรียบร้อย']);
        }

        // ───────── Self info ─────────
        case 'staff:departments_for_self': {
            // ใช้ตอนสร้างรายงาน — ส่งคืนรายการฝ่ายที่ user เลือกได้
            if ($isDirector) {
                $rows = $pdo->query("SELECT id, name FROM sys_departments WHERE active=1
                                     ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
                json_ok(['departments' => $rows, 'fixed' => false]);
            }
            $userDept = mr_user_department_id();
            if (!$userDept) json_err('บัญชีของคุณยังไม่ได้กำหนดฝ่าย กรุณาติดต่อ admin', 403);
            $rs = $pdo->prepare("SELECT id, name FROM sys_departments WHERE id = ?");
            $rs->execute([$userDept]);
            json_ok(['departments' => $rs->fetchAll(PDO::FETCH_ASSOC), 'fixed' => true]);
        }

        default:
            json_err("ไม่รู้จัก action: $key");
    }
} catch (Throwable $e) {
    json_err('เกิดข้อผิดพลาด: ' . $e->getMessage());
}
