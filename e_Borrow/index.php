<?php
// e_Borrow/index.php — user-facing dashboard moved into user/hub.php.
// Kept as a thin redirect so old bookmarks, LIFF deep-links, and admin
// "back to student view" buttons still land users in the right place.
declare(strict_types=1);
@session_start();
header('Location: ../user/hub.php#borrow', true, 302);
exit;
