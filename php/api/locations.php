<?php
/**
 * GET  /api/locations.php                      -> active locations (default)
 * GET  /api/locations.php?include_inactive=1   -> all locations (admin)
 * POST /api/locations.php                       body: { name, location_type, description }
 * PUT  /api/locations.php?id=<id>               body: fields or active: 0|1
 */
require __DIR__ . '/config.php';

$pdo = get_pdo();
$method = $_SERVER['REQUEST_METHOD'];

function format_location(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'location_type' => $row['location_type'],
        'description' => $row['description'],
        'item_count' => (int) ($row['item_count'] ?? 0),
        'active' => (int) ($row['active'] ?? 1) === 1,
    ];
}

if ($method === 'GET') {
    require_auth();
    $includeInactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] !== '0';
    if ($includeInactive) {
        require_manager_or_above();
    }
    $sql = 'SELECT l.*, COUNT(i.item_key) AS item_count
         FROM locations l
         LEFT JOIN items i ON i.location_id = l.id';
    if (!$includeInactive) {
        $sql .= ' WHERE l.active = 1';
    }
    $sql .= ' GROUP BY l.id ORDER BY l.location_type, l.name';
    $rows = $pdo->query($sql)->fetchAll();
    echo json_encode(array_map('format_location', $rows));
    exit;
}

if ($method === 'POST') {
    require_manager_or_above();
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
        $stmt = $pdo->prepare(
            'INSERT INTO locations (name, location_type, description, active) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$name, $type, $description]);
    } catch (PDOException $e) {
        json_error('A location with that name already exists', 409);
    }

    $id = (int) $pdo->lastInsertId();
    $row = $pdo->query(
        "SELECT l.*, COUNT(i.item_key) AS item_count FROM locations l
         LEFT JOIN items i ON i.location_id = l.id WHERE l.id = $id GROUP BY l.id"
    )->fetch();
    echo json_encode(format_location($row));
    exit;
}

if ($method === 'PUT') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) {
        json_error('missing required query param: id');
    }

    $row = $pdo->query(
        "SELECT l.*, COUNT(i.item_key) AS item_count FROM locations l
         LEFT JOIN items i ON i.location_id = l.id WHERE l.id = $id GROUP BY l.id"
    )->fetch();
    if (!$row) {
        json_error('unknown location', 404);
    }

    $body = read_json_body();
    if (array_key_exists('active', $body)) {
        require_super_admin();
    } else {
        require_manager_or_above();
    }

    if (array_key_exists('active', $body)) {
        $active = filter_var($body['active'], FILTER_VALIDATE_BOOLEAN) || (int) $body['active'] === 1 ? 1 : 0;
        if ($active === 0 && (int) $row['item_count'] > 0) {
            json_error('Move or hide items at this location before marking it inactive', 409);
        }
    }

    $fields = [];
    $values = [];
    foreach (['name', 'location_type', 'description'] as $field) {
        if (array_key_exists($field, $body)) {
            $fields[] = "$field = ?";
            $values[] = $body[$field];
        }
    }
    if (array_key_exists('active', $body)) {
        $fields[] = 'active = ?';
        $values[] = $active;
    }
    if (empty($fields)) {
        json_error('no fields to update');
    }
    $values[] = $id;
    $stmt = $pdo->prepare('UPDATE locations SET ' . implode(', ', $fields) . ' WHERE id = ?');
    $stmt->execute($values);

    $updated = $pdo->query(
        "SELECT l.*, COUNT(i.item_key) AS item_count FROM locations l
         LEFT JOIN items i ON i.location_id = l.id WHERE l.id = $id GROUP BY l.id"
    )->fetch();
    echo json_encode(format_location($updated));
    exit;
}

json_error('method not allowed', 405);
