<?php
/**
 * Legacy Redirect File
 * From: /archive/e_Borrow/login.php
 * To:   /e_Borrow/login.php
 */
header("HTTP/1.1 301 Moved Permanently");
header("Location: ../../e_Borrow/login.php");
exit;
