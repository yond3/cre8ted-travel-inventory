<?php
/**
 * POST /api/stock.php?item=<item_key>
 *   body: { "current": 12, "min": 5 }   (either or both keys)
 *
 * Manager+ only. Update current stock / minimum threshold directly.
 */
require __DIR__ . '/config.php';
block_department_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('method not allowed', 405);
}
require_manager_or_above();

$itemKey = $_GET['item'] ?? '';
if (!is_string($itemKey) || $itemKey === '' || strlen($itemKey) > 50) {
    json_error('missing required query param: item');
}
$pdo = get_pdo();
stock_begin($pdo, 'stock_adjustment');
$item = stock_lock_item($pdo, $itemKey);

$body = read_json_body();
request_reconcile($pdo,$body);
$pdo = get_pdo();

$fields = [];
$values = [];
$changes = [];
$equipment = ($item['item_type'] ?? '') === 'equipment';
if (array_key_exists('current', $body)) {
    $changes['current'] = parse_nonnegative_quantity($body['current'], 'Current quantity', $equipment);
    $fields[] = 'current_qty = ?';
    $values[] = $changes['current'];
}
if (array_key_exists('min', $body)) {
    if ($equipment && $body['min'] !== null) {
        json_error('equipment does not use a minimum stock threshold');
    }
    $changes['min'] = $body['min'] === null ? null : parse_nonnegative_quantity($body['min'], 'Minimum quantity');
    if ($changes['min'] !== null && $item['max_qty'] !== null && $changes['min'] > (float) $item['max_qty']) {
        json_error('minimum quantity cannot exceed maximum quantity');
    }
    $fields[] = 'min_qty = ?';
    $values[] = $changes['min'];
}
if (!$fields) {
    json_error('body must include current or min');
}

if (array_key_exists('current', $body)) stock_expect_version($body, $item);
$quantityChanged = array_key_exists('current', $changes) && stock_cents($changes['current']) !== stock_cents($item['current_qty']);
$reason = usage_reason($body['reason'] ?? null, $quantityChanged);
// Validate the entire body first; one update avoids a partially applied edit.
$values[] = $itemKey;
$pdo->prepare('UPDATE items SET ' . implode(', ', $fields) . ' WHERE item_key = ?')->execute($values);
record_audit('stock.adjust', 'item', $itemKey,
    ['current_qty' => $item['current_qty'], 'min_qty' => $item['min_qty']], array_merge($changes, ['reason' => $reason]), true);
stock_commit($pdo);

echo json_encode(array_merge(['status' => 'ok', 'item' => $itemKey], $changes));
