<?php
/**
 * POST /api/close_month.php?item=<item_key>
 *   body: { "month": "2026-07-01", "opening_qty": 8, "received_qty": 2, "closing_qty": 5 }
 *
 * Alternative to usage.php — instead of supplying the usage number
 * directly, supply opening/received/closing stock counts and let the
 * server compute usage = opening + received - closing. This mirrors how
 * a real monthly close would work off Inventory and Purchase Order
 * records, without needing someone to manually count "how much did we use".
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
$month = $body['month'] ?? null;
$opening = $body['opening_qty'] ?? null;
$received = $body['received_qty'] ?? null;
$closing = $body['closing_qty'] ?? null;

if ($month === null || $opening === null || $received === null || $closing === null) {
    json_error('body must include month, opening_qty, received_qty, closing_qty');
}

$usage = max(0, $opening + $received - $closing);

$pdo = get_pdo();
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO usage_log (item_key, month, usage_qty) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE usage_qty = VALUES(usage_qty)'
    );
    $stmt->execute([$itemKey, $month, $usage]);

    // the closing count becomes the new current stock on hand
    $stmt = $pdo->prepare('UPDATE items SET current_qty = ? WHERE item_key = ?');
    $stmt->execute([$closing, $itemKey]);

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    json_error('failed to close month: ' . $e->getMessage(), 500);
}

echo json_encode([
    'status' => 'ok',
    'item' => $itemKey,
    'month' => $month,
    'computed_usage' => $usage,
    'new_current_qty' => $closing,
]);
