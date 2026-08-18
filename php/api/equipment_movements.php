<?php
/**
 * GET /api/equipment_movements.php                    -> all movements, newest first
 * GET /api/equipment_movements.php?item=<key>         -> filter by catalog item
 * GET /api/equipment_movements.php?department=<name>  -> filter by department
 */
require __DIR__ . '/config.php';

$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('method not allowed', 405);
}

require_auth();

function format_equipment_movement(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'movement_code' => $row['movement_code'],
        'item_key' => $row['item_key'],
        'item_label' => $row['label'],
        'unit' => $row['unit'],
        'qty' => (float) $row['qty'],
        'movement_type' => $row['movement_type'],
        'department' => $row['department'],
        'location_id' => $row['location_id'] !== null ? (int) $row['location_id'] : null,
        'location_name' => $row['location_name'],
        'issued_to' => $row['issued_to'],
        'notes' => $row['notes'],
        'recorded_by' => $row['recorded_by'],
        'reference_type' => $row['reference_type'],
        'reference_id' => $row['reference_id'] !== null ? (int) $row['reference_id'] : null,
        'reference_code' => $row['reference_code'],
        'status' => $row['status'],
        'voided_reason' => $row['voided_reason'],
        'created_at' => $row['created_at'],
        'voided_at' => $row['voided_at'],
    ];
}

const MOVEMENT_SELECT = 'SELECT em.*, i.label, i.unit, l.name AS location_name
    FROM equipment_movements em
    JOIN items i ON i.item_key = em.item_key
    LEFT JOIN locations l ON l.id = em.location_id';

$where = [];
$params = [];
if (!empty($_GET['item'])) {
    $where[] = 'em.item_key = ?';
    $params[] = $_GET['item'];
}
if (!empty($_GET['department'])) {
    $where[] = 'em.department = ?';
    $params[] = $_GET['department'];
}

$sql = MOVEMENT_SELECT;
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY em.created_at DESC, em.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
echo json_encode(array_map('format_equipment_movement', $stmt->fetchAll()));
