<?php
// user/line_login.php — redirect shim
// LINE Developer Console or old links may point here; forward to the actual handler.
declare(strict_types=1);
header('Location: ../line_api/line_login.php', true, 302);
exit;
