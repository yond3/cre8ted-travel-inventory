<?php
require __DIR__ . '/config.php';

try {
    get_pdo()->query('SELECT 1');
    $dbStatus = 'connected';
} catch (Exception $e) {
    $dbStatus = 'unreachable';
}

echo json_encode(['status' => 'ok', 'database' => $dbStatus]);
