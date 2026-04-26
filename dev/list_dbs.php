<?php
try {
    $p = new PDO('mysql:host=127.0.0.1', 'root', '');
    $s = $p->query('SHOW DATABASES');
    print_r($s->fetchAll(PDO::FETCH_COLUMN));
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
