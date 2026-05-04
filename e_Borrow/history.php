<?php
// e_Borrow/history.php — user-facing history moved into user/hub.php
// (in-hub paginated history modal). Kept as a redirect for legacy links.
declare(strict_types=1);
@session_start();
header('Location: ../user/hub.php#borrow-history', true, 302);
exit;
