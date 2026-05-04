<?php
// e_Borrow/borrow.php — user-facing borrow form moved into user/hub.php
// (multi-step modal). Kept as a redirect for legacy links.
declare(strict_types=1);
@session_start();
header('Location: ../user/hub.php#borrow-flow', true, 302);
exit;
