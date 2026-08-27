<?php
/**
 * GET  /api/suppliers.php                      -> active suppliers only (default)
 * GET  /api/suppliers.php?include_inactive=1   -> all suppliers (admin directory)
 * POST /api/suppliers.php                       body: { name, contact, ... }
 * PUT  /api/suppliers.php?id=<id>               body: { active: 0|1, ... } or edit fields
 *
 * Inactive suppliers are hidden from the directory and PO supplier picker but kept
 * for order history. Set active = 0 to mark inactive; active = 1 to reactivate.
 */
require __DIR__ . '/config.php';

$pdo = get_pdo();
$method = $_SERVER['REQUEST_METHOD'];

function format_supplier(PDO $pdo, array $row): array
{
    $stmt = $pdo->prepare(
        'SELECT sp.item_key, i.label, sp.price, sp.last_purchase_date
         FROM supplier_prices sp
         JOIN items i ON i.item_key = sp.item_key
         WHERE sp.supplier_id = ?
         ORDER BY i.label'
    );
    $stmt->execute([$row['id']]);
    $prices = array_map(fn($p) => [
        'item_key' => $p['item_key'],
        'label' => $p['label'],
        'price' => (float) $p['price'],
        'last_purchase_date' => $p['last_purchase_date'],
    ], $stmt->fetchAll());

    return [
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'contact' => $row['contact'],
        'address' => $row['address'] ?? null,
        'rating' => $row['rating'] !== null ? (float) $row['rating'] : null,
        'procurement_methods' => $row['procurement_methods'] ? explode(',', $row['procurement_methods']) : [],
        'notes' => $row['notes'],
        'active' => (int) ($row['active'] ?? 1) === 1,
        'prices' => $prices,
    ];
}

if ($method === 'GET') {
    require_auth();
    $includeInactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] !== '0';
    if ($includeInactive) {
        require_manager_or_above();
    }
    $sql = 'SELECT * FROM suppliers';
    if (!$includeInactive) {
        $sql .= ' WHERE active = 1';
    }
    $sql .= ' ORDER BY name';
    $rows = $pdo->query($sql)->fetchAll();
    echo json_encode(array_map(fn($r) => format_supplier($pdo, $r), $rows));
    exit;
}

if ($method === 'POST') {
    require_manager_or_above();
    $body = read_json_body();
    $name = trim($body['name'] ?? '');
    if ($name === '') {
        json_error('name is required');
    }
    $methods = $body['procurement_methods'] ?? ['walk_in'];
    $methods = is_array($methods) ? implode(',', $methods) : (string) $methods;

    $stmt = $pdo->prepare(
        'INSERT INTO suppliers (name, contact, rating, procurement_methods, notes, active)
         VALUES (?, ?, ?, ?, ?, 1)'
    );
    $stmt->execute([
        $name,
        $body['contact'] ?? null,
        $body['rating'] ?? null,
        $methods,
        $body['notes'] ?? null,
    ]);
    $id = (int) $pdo->lastInsertId();

    if (!empty($body['item_key']) && isset($body['price'])) {
        $priceStmt = $pdo->prepare(
            'INSERT INTO supplier_prices (supplier_id, item_key, price) VALUES (?, ?, ?)'
        );
        $priceStmt->execute([$id, $body['item_key'], $body['price']]);
    }

    $row = $pdo->query("SELECT * FROM suppliers WHERE id = $id")->fetch();
    echo json_encode(format_supplier($pdo, $row));
    exit;
}

if ($method === 'PUT') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) {
        json_error('missing required query param: id');
    }
    $body = read_json_body();
    if (array_key_exists('active', $body)) {
        require_super_admin();
    } else {
        require_manager_or_above();
    }

    $fields = [];
    $values = [];
    foreach (['name', 'contact', 'address', 'rating', 'notes'] as $field) {
        if (array_key_exists($field, $body)) {
            $fields[] = "$field = ?";
            $values[] = $body[$field];
        }
    }
    if (array_key_exists('procurement_methods', $body)) {
        $methods = $body['procurement_methods'];
        $fields[] = 'procurement_methods = ?';
        $values[] = is_array($methods) ? implode(',', $methods) : (string) $methods;
    }
    if (array_key_exists('active', $body)) {
        $fields[] = 'active = ?';
        $values[] = filter_var($body['active'], FILTER_VALIDATE_BOOLEAN) || (int) $body['active'] === 1 ? 1 : 0;
    }
    if (!empty($fields)) {
        $values[] = $id;
        $stmt = $pdo->prepare('UPDATE suppliers SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->execute($values);
    }

    if (!empty($body['item_key']) && isset($body['price'])) {
        $priceStmt = $pdo->prepare(
            'INSERT INTO supplier_prices (supplier_id, item_key, price, last_purchase_date)
             VALUES (?, ?, ?, CURDATE())
             ON DUPLICATE KEY UPDATE price = VALUES(price), last_purchase_date = VALUES(last_purchase_date)'
        );
        $priceStmt->execute([$id, $body['item_key'], $body['price']]);
    }

    if (array_key_exists('prices', $body) && is_array($body['prices'])) {
        require_manager_or_above();
        $validItems = $pdo->query(
            "SELECT item_key FROM items WHERE item_type IN ('consumable', 'equipment') AND active = 1"
        )->fetchAll(PDO::FETCH_COLUMN);
        $validSet = array_flip($validItems);

        $priceStmt = $pdo->prepare(
            'INSERT INTO supplier_prices (supplier_id, item_key, price, last_purchase_date)
             VALUES (?, ?, ?, CURDATE())
             ON DUPLICATE KEY UPDATE price = VALUES(price), last_purchase_date = VALUES(last_purchase_date)'
        );
        foreach ($body['prices'] as $line) {
            $itemKey = $line['item_key'] ?? '';
            $price = $line['price'] ?? null;
            if ($itemKey === '' || !isset($validSet[$itemKey])) {
                continue;
            }
            if ($price === null || $price === '' || (float) $price <= 0) {
                continue;
            }
            $priceStmt->execute([$id, $itemKey, (float) $price]);
        }
    }

    if (array_key_exists('remove_item_keys', $body) && is_array($body['remove_item_keys'])) {
        require_manager_or_above();
        $delStmt = $pdo->prepare('DELETE FROM supplier_prices WHERE supplier_id = ? AND item_key = ?');
        foreach ($body['remove_item_keys'] as $itemKey) {
            if ($itemKey === '' || $itemKey === null) {
                continue;
            }
            $delStmt->execute([$id, $itemKey]);
        }
    }

    $row = $pdo->query("SELECT * FROM suppliers WHERE id = $id")->fetch();
    if (!$row) {
        json_error('unknown supplier', 404);
    }
    echo json_encode(format_supplier($pdo, $row));
    exit;
}

json_error('method not allowed', 405);
