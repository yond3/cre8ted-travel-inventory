<?php
/**
 * GET    /api/inventory.php                       -> active items (default)
 * GET    /api/inventory.php?include_inactive=1     -> all items (admin)
 * GET    /api/inventory.php?item=<key>            -> single item
 * POST   /api/inventory.php                       -> create item (active by default)
 * PUT    /api/inventory.php?item=<key>            -> edit or set active: 0|1
 *
 * Equipment: one catalog row per product. current_qty = storage pool only.
 * Department counts are in equipment_deployments (see config.php).
 */
require __DIR__ . '/config.php';
block_department_user();

$pdo = get_pdo();
$method = $_SERVER['REQUEST_METHOD'];

function equipment_item_status(array $row, float $deployedQty): string
{
    if ((int) ($row['active'] ?? 1) !== 1) {
        return 'Inactive';
    }
    $storageQty = (float) ($row['current_qty'] ?? 0);
    if ($storageQty > 0 && $deployedQty > 0) {
        return 'Mixed';
    }
    if ($storageQty > 0) {
        return 'In storage';
    }
    if ($deployedQty > 0) {
        return 'In use';
    }
    return 'Unassigned';
}

function item_status(PDO $pdo, array $item): string
{
    if ((int) ($item['active'] ?? 1) !== 1) {
        return 'Inactive';
    }
    if (($item['item_type'] ?? '') === 'equipment') {
        $deployedQty = deployed_equipment_qty($pdo, $item['item_key']);
        return equipment_item_status($item, $deployedQty);
    }
    if ($item['min_qty'] === null) {
        return 'Not tracked';
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

function format_item(PDO $pdo, array $row): array
{
    $storageQty = (float) $row['current_qty'];
    $deployments = [];
    $deployedQty = 0.0;
    if (($row['item_type'] ?? '') === 'equipment') {
        $deployments = fetch_equipment_deployments($pdo, $row['item_key']);
        $deployedQty = deployed_equipment_qty($pdo, $row['item_key']);
    }

    return [
        'item_key' => $row['item_key'],
        'label' => $row['label'],
        'equipment_group' => ($row['item_type'] ?? '') === 'equipment'
            ? ($row['equipment_group'] ?? null) : null,
        'display_label' => equipment_item_display_label($row),
        'unit' => $row['unit'],
        'item_type' => $row['item_type'],
        'location_id' => $row['location_id'] !== null ? (int) $row['location_id'] : null,
        'location_name' => $row['location_name'],
        'location_type' => $row['location_type'],
        'assigned_department' => null,
        'current_qty' => $storageQty,
        'storage_qty' => ($row['item_type'] ?? '') === 'equipment' ? $storageQty : null,
        'deployed_qty' => ($row['item_type'] ?? '') === 'equipment' ? $deployedQty : null,
        'total_qty' => ($row['item_type'] ?? '') === 'equipment' ? $storageQty + $deployedQty : $storageQty,
        'deployments' => $deployments,
        'min_qty' => $row['min_qty'] !== null ? (float) $row['min_qty'] : null,
        'max_qty' => $row['max_qty'] !== null ? (float) $row['max_qty'] : null,
        'active' => (int) ($row['active'] ?? 1) === 1,
        'status' => item_status($pdo, $row),
        'is_equipment_catalog' => ($row['item_type'] ?? '') === 'equipment'
            && empty($row['assigned_department']),
    ];
}

function normalize_equipment_placement(string $itemType, array $body): array
{
    if ($itemType !== 'equipment') {
        return [
            'location_id' => isset($body['location_id']) && $body['location_id'] !== '' && $body['location_id'] !== null
                ? (int) $body['location_id'] : null,
            'assigned_department' => null,
        ];
    }

    $locationId = isset($body['location_id']) && $body['location_id'] !== '' && $body['location_id'] !== null
        ? (int) $body['location_id'] : null;
    if ($locationId !== null) {
        $stmt = get_pdo()->prepare('SELECT location_type FROM locations WHERE id = ? AND active = 1');
        $stmt->execute([$locationId]);
        $type = $stmt->fetchColumn();
        if (!$type) {
            json_error('unknown or inactive location');
        }
        if ($type !== 'storage') {
            json_error('equipment storage must use a cabinet or shelf — issue to a department instead');
        }
    }

    return ['location_id' => $locationId, 'assigned_department' => null];
}

/** Lowercase alphanumeric only — for typo / near-duplicate label checks. */
function normalize_item_label(string $label): string
{
    $slug = strtolower(trim($label));
    $slug = preg_replace('/[^a-z0-9]+/', '', $slug);
    return $slug;
}

function find_similar_items(PDO $pdo, string $label, ?string $excludeItemKey = null, ?string $equipmentGroup = null): array
{
    if ($equipmentGroup !== null && trim($equipmentGroup) !== '') {
        $normVariant = normalize_item_label($label);
        if ($normVariant === '') {
            return [];
        }
        $normGroup = normalize_item_label($equipmentGroup);
        $stmt = $pdo->query(
            'SELECT item_key, label, equipment_group, active, item_type, assigned_department, location_id
             FROM items WHERE active = 1 AND item_type = \'equipment\''
        );
        $matches = [];
        foreach ($stmt->fetchAll() as $row) {
            if ($excludeItemKey !== null && $row['item_key'] === $excludeItemKey) {
                continue;
            }
            if (!empty($row['assigned_department'])) {
                continue;
            }
            if (normalize_item_label((string) ($row['equipment_group'] ?? '')) !== $normGroup) {
                continue;
            }
            if (normalize_item_label((string) $row['label']) === $normVariant) {
                $matches[] = $row;
            }
        }
        return $matches;
    }

    $norm = normalize_item_label($label);
    if (strlen($norm) < 3) {
        return [];
    }

    $stmt = $pdo->query('SELECT item_key, label, active, item_type, assigned_department, location_id FROM items WHERE active = 1');
    $matches = [];
    foreach ($stmt->fetchAll() as $row) {
        if ($excludeItemKey !== null && $row['item_key'] === $excludeItemKey) {
            continue;
        }
        if (($row['item_type'] ?? '') === 'equipment' && !empty($row['assigned_department'])) {
            continue;
        }
        $other = normalize_item_label($row['label']);
        if ($other === '') {
            continue;
        }
        if ($other === $norm) {
            $matches[] = $row;
            continue;
        }
        if (strlen($norm) >= 5 && strlen($other) >= 5 && levenshtein($norm, $other) <= 2) {
            $matches[] = $row;
        }
    }
    return $matches;
}

/** Block duplicate catalog rows — equipment should add qty on existing item. */
function similar_blocks_new_item(array $similar, string $itemType, array $placement): bool
{
    if ($similar === []) {
        return false;
    }
    if ($itemType !== 'equipment') {
        return !empty($similar);
    }
    return !empty($similar);
}

const EQUIPMENT_CATALOG_FILTER = "(i.item_type != 'equipment' OR i.assigned_department IS NULL OR TRIM(i.assigned_department) = '')";

if ($method === 'GET') {
    require_auth();
    $itemKey = $_GET['item'] ?? null;
    if ($itemKey !== null) {
        $row = fetch_item_row($pdo, $itemKey);
        if (!$row) {
            json_error("unknown item '$itemKey'", 404);
        }
        echo json_encode(format_item($pdo, $row));
        exit;
    }

    $includeInactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] !== '0';
    if ($includeInactive) {
        require_manager_or_above();
    }
    $sql = 'SELECT i.*, l.name AS location_name, l.location_type
         FROM items i
         LEFT JOIN locations l ON l.id = i.location_id
         WHERE ' . EQUIPMENT_CATALOG_FILTER;
    if (!$includeInactive) {
        $sql .= ' AND i.active = 1';
    }
    if (items_have_equipment_group($pdo)) {
        $sql .= ' ORDER BY i.item_type, COALESCE(i.equipment_group, i.label), i.label';
    } else {
        $sql .= ' ORDER BY i.label';
    }
    $rows = $pdo->query($sql)->fetchAll();
    echo json_encode(array_map(fn ($row) => format_item($pdo, $row), $rows));
    exit;
}

if ($method === 'POST') {
    require_manager_or_above();
    $body = read_json_body();
    $label = trim($body['label'] ?? '');
    $unit = parse_required_text($body['unit'] ?? '', LIMIT_UNIT, 'Unit');
    $itemType = $body['item_type'] ?? 'consumable';
    $current = (float) ($body['current_qty'] ?? 0);
    $equipmentGroup = trim($body['equipment_group'] ?? '');

    if ($itemType === 'equipment') {
        $equipmentGroup = parse_required_text($equipmentGroup, LIMIT_EQUIP_GROUP, 'Equipment group');
        $label = parse_required_text($label, LIMIT_EQUIP_VARIANT, 'Variant name');
    } else {
        $label = parse_required_text($label, LIMIT_ITEM_LABEL, 'Item name');
    }
    if (!in_array($itemType, ['consumable', 'equipment'], true)) {
        json_error("item_type must be 'consumable' or 'equipment'");
    }

    $similar = find_similar_items(
        $pdo,
        $label,
        null,
        $itemType === 'equipment' ? $equipmentGroup : null
    );
    $placement = normalize_equipment_placement($itemType, $body);
    if (similar_blocks_new_item($similar, $itemType, $placement)) {
        if ($itemType === 'equipment') {
            $names = implode(', ', array_map(
                fn ($r) => equipment_item_display_label($r),
                $similar
            ));
            json_error("this variant already exists under $equipmentGroup: $names — edit that row instead", 409);
        }
        $names = implode(', ', array_map(fn ($r) => $r['label'], $similar));
        json_error("similar item already exists: $names — edit that item and add storage qty instead", 409);
    }

    $minQty = $itemType === 'consumable' && isset($body['min_qty']) ? (float) $body['min_qty'] : null;
    $maxQty = $itemType === 'consumable' && isset($body['max_qty']) ? (float) $body['max_qty'] : null;

    $slugSource = $itemType === 'equipment' ? "$equipmentGroup $label" : $label;
    $itemKey = slugify($slugSource);
    $base = $itemKey;
    $suffix = 2;
    while (fetch_item_row($pdo, $itemKey) !== null) {
        $itemKey = $base . $suffix;
        $suffix++;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO items (item_key, label, equipment_group, unit, item_type, location_id, assigned_department, current_qty, min_qty, max_qty, active)
         VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, 1)'
    );
    $stmt->execute([
        $itemKey,
        $label,
        $itemType === 'equipment' ? $equipmentGroup : null,
        $unit,
        $itemType,
        $placement['location_id'],
        $current,
        $minQty,
        $maxQty,
    ]);

    $created = fetch_item_row($pdo, $itemKey);
    record_audit('item.create', 'item', $itemKey, null, [
        'label' => $created['label'],
        'item_type' => $created['item_type'],
        'current_qty' => (float) $created['current_qty'],
    ]);
    echo json_encode(format_item($pdo, $created));
    exit;
}

if ($method === 'PUT') {
    require_auth();
    $itemKey = $_GET['item'] ?? '';
    if ($itemKey === '') {
        json_error('missing required query param: item');
    }
    $existing = fetch_item_row($pdo, $itemKey);
    if (!$existing) {
        json_error("unknown item '$itemKey'", 404);
    }

    $body = read_json_body();
    if (array_key_exists('active', $body)) {
        require_super_admin();
    } else {
        require_manager_or_above();
    }
    $itemType = $body['item_type'] ?? $existing['item_type'];
    $equipmentGroup = array_key_exists('equipment_group', $body)
        ? trim((string) $body['equipment_group'])
        : (string) ($existing['equipment_group'] ?? '');

    if (array_key_exists('label', $body) || array_key_exists('equipment_group', $body)) {
        $newLabel = $itemType === 'equipment'
            ? parse_required_text($body['label'] ?? $existing['label'], LIMIT_EQUIP_VARIANT, 'Variant name')
            : parse_required_text($body['label'] ?? $existing['label'], LIMIT_ITEM_LABEL, 'Item name');
        $newGroup = $itemType === 'equipment'
            ? parse_required_text($equipmentGroup, LIMIT_EQUIP_GROUP, 'Equipment group')
            : '';
        $body['label'] = $newLabel;
        if ($itemType === 'equipment') {
            $equipmentGroup = $newGroup;
            $body['equipment_group'] = $newGroup;
        }
        $labelChanged = normalize_item_label($newLabel) !== normalize_item_label((string) $existing['label']);
        $groupChanged = $itemType === 'equipment'
            && normalize_item_label($newGroup) !== normalize_item_label((string) ($existing['equipment_group'] ?? ''));
        if ($labelChanged || $groupChanged) {
            $similar = find_similar_items(
                $pdo,
                $newLabel,
                $itemKey,
                $itemType === 'equipment' ? $newGroup : null
            );
            if ($similar) {
                $names = implode(', ', array_map(
                    fn ($r) => $itemType === 'equipment' ? equipment_item_display_label($r) : $r['label'],
                    $similar
                ));
                json_error("similar item already exists: $names — use a distinct group/variant or edit the existing item", 409);
            }
        }
    }

    $fields = [];
    $values = [];
    if (array_key_exists('label', $body)) {
        $fields[] = 'label = ?';
        $values[] = $body['label'];
    }
    if (array_key_exists('unit', $body)) {
        $fields[] = 'unit = ?';
        $values[] = parse_required_text($body['unit'], LIMIT_UNIT, 'Unit');
    }
    foreach (['item_type', 'current_qty'] as $field) {
        if (array_key_exists($field, $body)) {
            $fields[] = "$field = ?";
            $values[] = $body[$field];
        }
    }
    if ($itemType === 'equipment' && array_key_exists('equipment_group', $body)) {
        $fields[] = 'equipment_group = ?';
        $values[] = $equipmentGroup !== '' ? $equipmentGroup : null;
    } elseif ($itemType !== 'equipment') {
        $fields[] = 'equipment_group = NULL';
    }

    if ($itemType === 'equipment' && array_key_exists('location_id', $body)) {
        $placement = normalize_equipment_placement($itemType, $body);
        $fields[] = 'location_id = ?';
        $values[] = $placement['location_id'];
        $fields[] = 'assigned_department = NULL';
    } elseif ($itemType !== 'equipment' && array_key_exists('location_id', $body)) {
        $fields[] = 'location_id = ?';
        $values[] = $body['location_id'] !== '' && $body['location_id'] !== null ? (int) $body['location_id'] : null;
        $fields[] = 'assigned_department = NULL';
    }

    if (array_key_exists('active', $body)) {
        $fields[] = 'active = ?';
        $values[] = filter_var($body['active'], FILTER_VALIDATE_BOOLEAN) || (int) $body['active'] === 1 ? 1 : 0;
    }
    if ($itemType === 'equipment') {
        $fields[] = 'min_qty = NULL';
        $fields[] = 'max_qty = NULL';
        $fields[] = 'assigned_department = NULL';
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

    $updatedRow = fetch_item_row($pdo, $itemKey);
    record_audit(
        'item.edit',
        'item',
        $itemKey,
        ['label' => $existing['label'], 'active' => (int) ($existing['active'] ?? 1), 'current_qty' => (float) $existing['current_qty'], 'min_qty' => $existing['min_qty'], 'max_qty' => $existing['max_qty']],
        ['label' => $updatedRow['label'], 'active' => (int) ($updatedRow['active'] ?? 1), 'current_qty' => (float) $updatedRow['current_qty'], 'min_qty' => $updatedRow['min_qty'], 'max_qty' => $updatedRow['max_qty']]
    );
    echo json_encode(format_item($pdo, $updatedRow));
    exit;
}

json_error('method not allowed', 405);
