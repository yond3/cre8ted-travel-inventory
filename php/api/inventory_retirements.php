<?php
/**
 * GET  /api/inventory_retirements.php                    -> list retirements, newest first
 * GET  /api/inventory_retirements.php?item=<key>         -> filter by item
 * POST /api/inventory_retirements.php
 *      body: { item_key, qty, source?: 'storage'|'department', department?, reason, notes? }
 *      -> manager+ only. Removes units from counts and logs the retirement.
 */
require __DIR__ . '/config.php';
block_department_user();

$pdo = get_pdo();
$method = $_SERVER['REQUEST_METHOD'];

const RETIREMENT_SELECT = 'SELECT ir.*, i.label, i.unit, i.item_type
    FROM inventory_retirements ir
    JOIN items i ON i.item_key = ir.item_key';

if ($method === 'GET') {
    require_auth();

    $where = [];
    $params = [];
    if (!empty($_GET['item'])) {
        $where[] = 'ir.item_key = ?';
        $params[] = $_GET['item'];
    }

    $sql = RETIREMENT_SELECT;
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY ir.created_at DESC, ir.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(array_map('format_inventory_retirement', $stmt->fetchAll()));
    exit;
}

if ($method === 'POST') {
    $user = require_manager_or_above();
    $body = read_json_body();
    echo json_encode(apply_inventory_retirement($pdo, $body, $user));
    exit;
}

json_error('method not allowed', 405);
