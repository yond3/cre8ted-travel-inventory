<?php
/**
 * POST /api/stock.php?item=<item_key>
 *   body: { "current": 12, "min": 5 }   (either or both keys)
 *
 * Update current stock / minimum threshold directly, without touching code.
 */
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('method not allowed', 405);
}

$itemKey = $_GET['item'] ?? '';
if ($itemKey === '') {
    json_error('missing required query param: item');
}
get_item_or_404($itemKey);

$body = read_json_body();
$pdo = get_pdo();

if (array_key_exists('current', $body)) {
    $stmt = $pdo->prepare('UPDATE items SET current_qty = ? WHERE item_key = ?');
    $stmt->execute([$body['current'], $itemKey]);
}
if (array_key_exists('min', $body)) {
    $stmt = $pdo->prepare('UPDATE items SET min_qty = ? WHERE item_key = ?');
    $stmt->execute([$body['min'], $itemKey]);
}

echo json_encode(array_merge(['status' => 'ok', 'item' => $itemKey], $body));
