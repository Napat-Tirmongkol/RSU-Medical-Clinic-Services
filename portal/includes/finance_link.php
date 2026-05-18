<?php
// portal/includes/finance_link.php — signed URLs for finance receipt prints.
// Defeats ?id= enumeration: an authenticated finance user cannot iterate
// receipt URLs without also knowing the matching sig that we hand out only
// inside the list response (server-side computed, never accepted from client).
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';

function fin_link_secret(): string {
    static $s = null;
    if ($s === null) {
        // Reuse QR_SLOT_SECRET (loaded by config.php from secrets.php when
        // present); fall back to a deterministic seed so links stay stable
        // across requests on a fresh install.
        $s = defined('QR_SLOT_SECRET') ? (string)QR_SLOT_SECRET : hash('sha256', 'rsu-finance-link-v1');
    }
    return $s;
}

function fin_receipt_sig(int $id): string {
    return substr(hash_hmac('sha256', "receipt:{$id}", fin_link_secret()), 0, 16);
}

function fin_receipt_verify(int $id, string $sig): bool {
    if ($id <= 0 || $sig === '') return false;
    return hash_equals(fin_receipt_sig($id), $sig);
}
