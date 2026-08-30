<?php
/**
 * GET  /api/usage.php?item=<item_key>              -> full usage history
 * POST /api/usage.php?item=<item_key>               body: { "month": "2026-07-01", "usage": 5 }
 *      -> add or overwrite one month's usage number directly.
 */
require __DIR__ . '/config.php';
block_department_user();

$itemKey = $_GET['item'] ?? '';
if ($itemKey === '') {
    json_error('missing required query param: item');
}
get_item_or_404($itemKey);

$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare('SELECT month, usage_qty FROM usage_log WHERE item_key = ? ORDER BY month ASC');
    $stmt->execute([$itemKey]);
    $rows = array_map(
        fn($r) => ['month' => $r['month'], 'usage' => (float) $r['usage_qty']],
        $stmt->fetchAll()
    );
    echo json_encode($rows);
    exit;
}

// POST
$body = read_json_body();
$month = $body['month'] ?? null;
$usage = $body['usage'] ?? null;
if ($month === null || $usage === null) {
    json_error("body must include 'month' (YYYY-MM-DD) and 'usage' (number)");
}

$stmt = $pdo->prepare(
    'INSERT INTO usage_log (item_key, month, usage_qty) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE usage_qty = VALUES(usage_qty)'
);
$stmt->execute([$itemKey, $month, $usage]);

echo json_encode(['status' => 'ok', 'item' => $itemKey, 'month' => $month, 'usage' => $usage]);
