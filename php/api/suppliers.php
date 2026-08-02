<?php
/**
 * GET  /api/suppliers.php                 -> list suppliers with their item prices
 * POST /api/suppliers.php                  body: { name, contact, rating, procurement_methods: ['walk_in',...], notes }
 * PUT  /api/suppliers.php?id=<id>          body: any of the above -> edit a supplier
 *
 * procurement_methods is stored as a comma list (walk_in,pickup,delivery,online)
 * — what the office can actually do with that vendor (locked-in design decision).
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
        'rating' => $row['rating'] !== null ? (float) $row['rating'] : null,
        'procurement_methods' => $row['procurement_methods'] ? explode(',', $row['procurement_methods']) : [],
        'notes' => $row['notes'],
        'prices' => $prices,
    ];
}

if ($method === 'GET') {
    $rows = $pdo->query('SELECT * FROM suppliers ORDER BY name')->fetchAll();
    echo json_encode(array_map(fn($r) => format_supplier($pdo, $r), $rows));
    exit;
}

if ($method === 'POST') {
    $body = read_json_body();
    $name = trim($body['name'] ?? '');
    if ($name === '') {
        json_error('name is required');
    }
    $methods = $body['procurement_methods'] ?? ['walk_in'];
    $methods = is_array($methods) ? implode(',', $methods) : (string) $methods;

    $stmt = $pdo->prepare(
        'INSERT INTO suppliers (name, contact, rating, procurement_methods, notes) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $name,
        $body['contact'] ?? null,
        $body['rating'] ?? null,
        $methods,
        $body['notes'] ?? null,
    ]);
    $id = (int) $pdo->lastInsertId();

    // Optional: seed one item price at creation time, if provided.
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

    $fields = [];
    $values = [];
    foreach (['name', 'contact', 'rating', 'notes'] as $field) {
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
    if (!empty($fields)) {
        $values[] = $id;
        $stmt = $pdo->prepare('UPDATE suppliers SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->execute($values);
    }

    // Optional: upsert a single item price in the same call.
    if (!empty($body['item_key']) && isset($body['price'])) {
        $priceStmt = $pdo->prepare(
            'INSERT INTO supplier_prices (supplier_id, item_key, price, last_purchase_date)
             VALUES (?, ?, ?, CURDATE())
             ON DUPLICATE KEY UPDATE price = VALUES(price), last_purchase_date = VALUES(last_purchase_date)'
        );
        $priceStmt->execute([$id, $body['item_key'], $body['price']]);
    }

    $row = $pdo->query("SELECT * FROM suppliers WHERE id = $id")->fetch();
    if (!$row) {
        json_error('unknown supplier', 404);
    }
    echo json_encode(format_supplier($pdo, $row));
    exit;
}

json_error('method not allowed', 405);
