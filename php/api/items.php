<?php
/**
 * GET /api/items.php — consumable items only, with how many months of usage
 * history each has on record. Used to populate the AI Demand Forecast
 * dropdown — equipment (printers, extension cords, etc.) has no usage
 * history and isn't forecastable, so it's excluded here. For the full
 * inventory (including equipment), see inventory.php.
 */
require __DIR__ . '/config.php';

$pdo = get_pdo();
$items = $pdo->query(
    "SELECT * FROM items WHERE item_type = 'consumable' AND active = 1 ORDER BY label"
)->fetchAll();

$result = [];
foreach ($items as $item) {
    $stmt = $pdo->prepare('SELECT COUNT(*) AS n FROM usage_log WHERE item_key = ?');
    $stmt->execute([$item['item_key']]);
    $count = (int) $stmt->fetch()['n'];

    $result[] = [
        'item_key' => $item['item_key'],
        'label' => $item['label'],
        'unit' => $item['unit'],
        'current_qty' => (float) $item['current_qty'],
        'min_qty' => $item['min_qty'] !== null ? (float) $item['min_qty'] : null,
        'months_of_history' => $count,
    ];
}

echo json_encode($result);
