<?php
$file = __DIR__ . '/hub.php';
$content = file($file);
echo "<h2>Code around line 209 of hub.php</h2>";
echo "<pre>";
for ($i = 200; $i < 220; $i++) {
    if (isset($content[$i])) {
        echo ($i + 1) . ": " . htmlspecialchars($content[$i]);
    }
}
echo "</pre>";
