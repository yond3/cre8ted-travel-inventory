<?php
/**
 * GET    /api/inventory.php                  -> list every item (with location + computed status)
 * GET    /api/inventory.php?item=<key>        -> single item
 * POST   /api/inventory.php                   -> create a new item
 *        body: { label, unit, item_type, location_id, current_qty, min_qty, max_qty }
 *        item_type: 'consumable' | 'equipment' — equipment ignores min_qty/max_qty (forced NULL)
 * PUT    /api/inventory.php?item=<key>        -> edit / relocate an existing item
 *        body: any of { label, unit, item_type, location_id, current_qty, min_qty, max_qty }
 *
 * This replaces the old items.php for anything beyond the forecast dropdown
 * — full CRUD, plus the "Edit / Relocate" flow the plan called for.
 */
require __DIR__ . '/config.php';

$pdo = get_pdo();
$method = $_SERVER['REQUEST_METHOD'];

function item_status(array $item): string
{
    if ($item['min_qty'] === null) {
        return 'Not tracked'; // equipment — no reorder threshold
    }
    return ((float) $item['current_qty'] <= (float) $item['min_qty']) ? 'Low stock' : 'In stock';
}

function fetch_item_row(PDO $pdo, string $itemKey): ?array
{
    $stmt = $pdo->prepare(
        'SELECT i.*, l.name AS location_name, l.location_type
         FROM items i
         LEFT JOIN locations l ON l.id = i.location_id
         WHERE i.item_key = ?'
    );
    $stmt->execute([$itemKey]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function format_item(array $row): array
{
    return [
        'item_key' => $row['item_key'],
        'label' => $row['label'],
        'unit' => $row['unit'],
        'item_type' => $row['item_type'],
        'location_id' => $row['location_id'] !== null ? (int) $row['location_id'] : null,
        'location_name' => $row['location_name'],
        'location_type' => $row['location_type'],
        'current_qty' => (float) $row['current_qty'],
        'min_qty' => $row['min_qty'] !== null ? (float) $row['min_qty'] : null,
        'max_qty' => $row['max_qty'] !== null ? (float) $row['max_qty'] : null,
        'status' => item_status($row),
    ];
}

if ($method === 'GET') {
    $itemKey = $_GET['item'] ?? null;
    if ($itemKey !== null) {
        $row = fetch_item_row($pdo, $itemKey);
        if (!$row) {
            json_error("unknown item '$itemKey'", 404);
        }
        echo json_encode(format_item($row));
        exit;
    }

    $rows = $pdo->query(
        'SELECT i.*, l.name AS location_name, l.location_type
         FROM items i
         LEFT JOIN locations l ON l.id = i.location_id
         ORDER BY i.label'
    )->fetchAll();
    echo json_encode(array_map('format_item', $rows));
    exit;
}

if ($method === 'POST') {
    $body = read_json_body();
    $label = trim($body['label'] ?? '');
    $unit = trim($body['unit'] ?? '');
    $itemType = $body['item_type'] ?? 'consumable';
    $locationId = isset($body['location_id']) && $body['location_id'] !== '' ? (int) $body['location_id'] : null;
    $current = (float) ($body['current_qty'] ?? 0);

    if ($label === '' || $unit === '') {
        json_error('label and unit are required');
    }
    if (!in_array($itemType, ['consumable', 'equipment'], true)) {
        json_error("item_type must be 'consumable' or 'equipment'");
    }

    // Equipment doesn't get reorder thresholds — nothing to "run low" on a printer.
    $minQty = $itemType === 'consumable' && isset($body['min_qty']) ? (float) $body['min_qty'] : null;
    $maxQty = $itemType === 'consumable' && isset($body['max_qty']) ? (float) $body['max_qty'] : null;

    $itemKey = slugify($label);
    // Ensure uniqueness if two items share a slug (e.g. "Bond paper" twice).
    $base = $itemKey;
    $suffix = 2;
    while (fetch_item_row($pdo, $itemKey) !== null) {
        $itemKey = $base . $suffix;
        $suffix++;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO items (item_key, label, unit, item_type, location_id, current_qty, min_qty, max_qty)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$itemKey, $label, $unit, $itemType, $locationId, $current, $minQty, $maxQty]);

    echo json_encode(format_item(fetch_item_row($pdo, $itemKey)));
    exit;
}

if ($method === 'PUT') {
    $itemKey = $_GET['item'] ?? '';
    if ($itemKey === '') {
        json_error('missing required query param: item');
    }
    $existing = fetch_item_row($pdo, $itemKey);
    if (!$existing) {
        json_error("unknown item '$itemKey'", 404);
    }

    $body = read_json_body();
    $itemType = $body['item_type'] ?? $existing['item_type'];

    $fields = [];
    $values = [];
    foreach (['label', 'unit', 'item_type', 'current_qty'] as $field) {
        if (array_key_exists($field, $body)) {
            $fields[] = "$field = ?";
            $values[] = $body[$field];
        }
    }
    if (array_key_exists('location_id', $body)) {
        $fields[] = 'location_id = ?';
        $values[] = $body['location_id'] !== '' && $body['location_id'] !== null ? (int) $body['location_id'] : null;
    }
    // Switching to equipment always clears thresholds; switching to/staying
    // consumable accepts whatever min/max was supplied.
    if ($itemType === 'equipment') {
        $fields[] = 'min_qty = NULL';
        $fields[] = 'max_qty = NULL';
    } else {
        if (array_key_exists('min_qty', $body)) {
            $fields[] = 'min_qty = ?';
            $values[] = $body['min_qty'];
        }
        if (array_key_exists('max_qty', $body)) {
            $fields[] = 'max_qty = ?';
            $values[] = $body['max_qty'];
        }
    }

    if (empty($fields)) {
        json_error('no fields to update');
    }

    $values[] = $itemKey;
    $stmt = $pdo->prepare('UPDATE items SET ' . implode(', ', $fields) . ' WHERE item_key = ?');
    $stmt->execute($values);

    echo json_encode(format_item(fetch_item_row($pdo, $itemKey)));
    exit;
}

json_error('method not allowed', 405);
