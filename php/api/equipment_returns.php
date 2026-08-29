<?php
/**
 * POST /api/equipment_returns.php
 *      body: { item_key, department, qty, condition: 'good'|'damaged'|'broken', returned_by?, notes? }
 *      -> staff+ for good/damaged; manager+ required when condition is broken (write-off).
 */
require __DIR__ . '/config.php';

$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('method not allowed', 405);
}

$user = require_staff_or_above();
$body = read_json_body();
echo json_encode(apply_equipment_return($pdo, $body, $user));
