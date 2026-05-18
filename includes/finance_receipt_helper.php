<?php
/**
 * includes/finance_receipt_helper.php
 *
 * Atomic receipt number generator for Cash Book transactions.
 * Uses a dedicated counter table (sys_finance_receipt_counter) with
 * SELECT … FOR UPDATE inside a transaction — no SELECT MAX()+1 race.
 *
 * Receipt number format:
 *   RCP-{yearBE}-{6 digits}  for income (ใบเสร็จรับเงิน)
 *   PV-{yearBE}-{6 digits}   for expense (ใบสำคัญจ่าย)
 *
 * Both portal/ajax_finance.php (txn:assign_receipt) and portal/finance_receipt.php
 * (auto-assign on print view) require this file. See Phase 4 audit finding
 * AI/logs/2026-05-18-security-audit-main.md Tier 1 C10.
 */
declare(strict_types=1);

function finance_ensure_receipt_counter_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_finance_receipt_counter (
            year_be    SMALLINT UNSIGNED NOT NULL,
            kind       ENUM('income','expense') NOT NULL,
            current_no INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (year_be, kind)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $done = true;
    } catch (PDOException $e) {
        error_log('[finance_receipt_helper schema] ' . $e->getMessage());
    }
}

/**
 * Atomically assign a receipt number to a finance transaction.
 *
 * Idempotent: if the transaction already has a receipt_no, returns it unchanged.
 *
 * Concurrency-safe: counter is locked with SELECT FOR UPDATE inside a
 * transaction, so two simultaneous callers cannot produce the same number.
 *
 * @param  PDO      $pdo
 * @param  int      $txnId    sys_finance_transactions.id
 * @param  int|null $adminId  Set on updated_by (audit trail)
 * @return string             Receipt number, or '' on error / unknown txn
 */
function finance_assign_receipt_no(PDO $pdo, int $txnId, ?int $adminId = null): string
{
    if ($txnId <= 0) return '';
    finance_ensure_receipt_counter_schema($pdo);

    // Read txn to determine kind + txn_date and check if already assigned.
    $stmt = $pdo->prepare("SELECT receipt_no, kind, txn_date FROM sys_finance_transactions WHERE id=?");
    $stmt->execute([$txnId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return '';
    if (!empty($row['receipt_no'])) return (string)$row['receipt_no'];

    $kind   = ($row['kind'] === 'income') ? 'income' : 'expense';
    $prefix = ($kind === 'income') ? 'RCP' : 'PV';
    $yearBE = (int)date('Y', strtotime((string)$row['txn_date'])) + 543;

    // Lock the counter row. If caller is already in a transaction, reuse it
    // (so the assignment is rolled back if the outer call fails).
    $ownTxn = !$pdo->inTransaction();
    if ($ownTxn) $pdo->beginTransaction();
    try {
        $sel = $pdo->prepare("SELECT current_no FROM sys_finance_receipt_counter WHERE year_be=? AND kind=? FOR UPDATE");
        $sel->execute([$yearBE, $kind]);
        $curr = $sel->fetchColumn();

        if ($curr === false) {
            // First receipt of this (year, kind) — bootstrap counter from any
            // pre-existing receipt_no values left over before the migration.
            $migr = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(receipt_no, " . (strlen($prefix) + 7) . ") AS UNSIGNED))
                                   FROM sys_finance_transactions WHERE receipt_no LIKE ?");
            $migr->execute([$prefix . '-' . $yearBE . '-%']);
            $curr = (int)$migr->fetchColumn();
            $pdo->prepare("INSERT INTO sys_finance_receipt_counter (year_be, kind, current_no)
                           VALUES (?, ?, ?)
                           ON DUPLICATE KEY UPDATE current_no = GREATEST(current_no, VALUES(current_no))")
                ->execute([$yearBE, $kind, $curr]);
        }
        $next = (int)$curr + 1;
        $pdo->prepare("UPDATE sys_finance_receipt_counter SET current_no=? WHERE year_be=? AND kind=?")
            ->execute([$next, $yearBE, $kind]);

        $receiptNo = sprintf('%s-%d-%06d', $prefix, $yearBE, $next);
        $pdo->prepare("UPDATE sys_finance_transactions SET receipt_no=?, updated_by=? WHERE id=?")
            ->execute([$receiptNo, $adminId, $txnId]);

        if ($ownTxn) $pdo->commit();
        return $receiptNo;
    } catch (Throwable $e) {
        if ($ownTxn && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[finance_assign_receipt_no] ' . $e->getMessage());
        return '';
    }
}
