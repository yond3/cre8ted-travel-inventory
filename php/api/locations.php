<?php
/**
 * GET  /api/locations.php                -> list all locations (with item counts)
 * POST /api/locations.php                 body: { "name", "location_type", "description" }
 * PUT  /api/locations.php?id=<id>         body: any of the above -> edit a location
 *
 * Locations are split into two types (locked-in design decision):
 *   - storage: cabinets/shelves/lockers where consumables sit until needed
 *   - in_use:  desks/rooms where equipment lives permanently (e.g. a printer
 *              on the reception desk isn't "in storage")
 */
require __DIR__ . '/config.php';

$pdo = get_pdo();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $rows = $pdo->query(
        'SELECT l.*, COUNT(i.item_key) AS item_count
         FROM locations l
         LEFT JOIN items i ON i.location_id = l.id
         GROUP BY l.id
         ORDER BY l.location_type, l.name'
    )->fetchAll();
    echo json_encode($rows);
    exit;
}

if ($method === 'POST') {
    $body = read_json_body();
    $name = trim($body['name'] ?? '');
    $type = $body['location_type'] ?? 'storage';
    $description = $body['description'] ?? null;

    if ($name === '') {
        json_error('name is required');
    }
    if (!in_array($type, ['storage', 'in_use'], true)) {
        json_error("location_type must be 'storage' or 'in_use'");
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO locations (name, location_type, description) VALUES (?, ?, ?)');
        $stmt->execute([$name, $type, $description]);
    } catch (PDOException $e) {
        json_error('A location with that name already exists', 409);
    }

    echo json_encode(['status' => 'ok', 'id' => (int) $pdo->lastInsertId(), 'name' => $name, 'location_type' => $type]);
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
    foreach (['name', 'location_type', 'description'] as $field) {
        if (array_key_exists($field, $body)) {
            $fields[] = "$field = ?";
            $values[] = $body[$field];
        }
    }
    if (empty($fields)) {
        json_error('no fields to update');
    }
    $values[] = $id;
    $stmt = $pdo->prepare('UPDATE locations SET ' . implode(', ', $fields) . ' WHERE id = ?');
    $stmt->execute($values);

    echo json_encode(['status' => 'ok', 'id' => $id]);
    exit;
}

json_error('method not allowed', 405);
