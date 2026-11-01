<?php
/**
 * GET  /api/stock_requests.php?requestable=1[&type=consumable|equipment]
 *      -> items available to request (same pool as central "Request from stock")
 * GET  /api/stock_requests.php                      -> list requests, newest first
 * GET  /api/stock_requests.php?status=Pending       -> filter by status
 * GET  /api/stock_requests.php?department=<name>      -> central only: filter by department
 * GET  /api/stock_requests.php?requested_by=<name>    -> central only: filter by requester
 * GET  /api/stock_requests.php?departments=1          -> official department list
 * POST /api/stock_requests.php  body (central): { department, item_key, qty, notes? }
 * POST /api/stock_requests.php  body (department): { item_key, qty, notes? }
 * PUT  /api/stock_requests.php?id=<id>  body: { action: 'fulfill', item_key?, qty?, issued_to?, notes? }
 * PUT  /api/stock_requests.php?id=<id>  body: { action: 'cancel' }
 */
require __DIR__ . '/config.php';

$pdo = get_pdo();
$method = $_SERVER['REQUEST_METHOD'];
if (request_schema_available($pdo)) require __DIR__ . '/stock_requests_v5.php';

const STOCK_REQUEST_SELECT = 'SELECT sr.*, i.label, i.equipment_group, i.item_type, i.unit, i.current_qty, si.issue_code
    FROM stock_requests sr
    LEFT JOIN items i ON i.item_key = sr.item_key
    LEFT JOIN stock_issues si ON si.id = sr.fulfilled_issue_id';

function format_stock_request(array $row): array
{
    $freeText = empty($row['item_key']);
    $label = $freeText
        ? ($row['requested_label'] ?? '—')
        : equipment_item_display_label($row);
    $unit = $freeText
        ? ($row['requested_unit'] ?? 'unit(s)')
        : ($row['unit'] ?? 'unit(s)');

    return [
        ...category_metadata_for_key(get_pdo(), $row['item_key'] ?? null),
        'id' => (int) $row['id'],
        'request_code' => $row['request_code'],
        'department' => $row['department'],
        'item_key' => $row['item_key'],
        'requested_label' => $row['requested_label'] ?? null,
        'item_label' => $label,
        'item_type' => $freeText ? null : ($row['item_type'] ?? null),
        'unit' => $unit,
        'is_free_text' => $freeText,
        'current_qty' => isset($row['current_qty']) ? (float) $row['current_qty'] : null,
        'qty' => (float) $row['qty'],
        'requested_by' => $row['requested_by'],
        'notes' => $row['notes'],
        'status' => $row['status'],
        'fulfilled_issue_id' => $row['fulfilled_issue_id'] !== null ? (int) $row['fulfilled_issue_id'] : null,
        'fulfilled_issue_code' => $row['issue_code'] ?? null,
        'fulfilled_by' => $row['fulfilled_by'],
        'fulfilled_at' => $row['fulfilled_at'],
        'cancelled_at' => $row['cancelled_at'],
        'created_at' => $row['created_at'],
    ];
}

function fetch_stock_request(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(STOCK_REQUEST_SELECT . ' WHERE sr.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function insert_catalog_stock_request(
    PDO $pdo,
    string $department,
    string $itemKey,
    float $qty,
    string $requestedBy,
    ?string $notes
): array {
    $item = get_item_or_404($itemKey);
    validate_catalog_stock_request_item($item, $qty);

    $code = next_code('SR', 'stock_requests', 'request_code');
    $stmt = $pdo->prepare(
        'INSERT INTO stock_requests (request_code, department, item_key, qty, requested_by, notes, status)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $code,
        $department,
        $itemKey,
        $qty,
        $requestedBy,
        parse_optional_note($notes),
        'Pending',
    ]);

    $row = $pdo->query(STOCK_REQUEST_SELECT . ' WHERE sr.request_code = ' . $pdo->quote($code))->fetch();
    return format_stock_request($row);
}

if ($method === 'GET') {
    $user = require_auth();

    if (!empty($_GET['requestable'])) {
        $type = trim((string) ($_GET['type'] ?? ''));
        if ($type !== '' && !in_array($type, ['consumable', 'equipment'], true)) {
            json_error('type must be consumable or equipment');
        }
        echo json_encode(fetch_requestable_stock_items($pdo, $type !== '' ? $type : null));
        exit;
    }

    if (!empty($_GET['departments'])) {
        if (is_department_user($user)) {
            echo json_encode([$user['department']]);
            exit;
        }
        echo json_encode(DEPARTMENTS);
        exit;
    }

    $where = [];
    $params = [];
    apply_stock_request_list_scope($user, $where, $params);

    if (!empty($_GET['status'])) {
        $where[] = 'sr.status = ?';
        $params[] = $_GET['status'];
    }

    $sql = STOCK_REQUEST_SELECT;
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY sr.created_at DESC, sr.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(array_map('format_stock_request', $stmt->fetchAll()));
    exit;
}

if ($method === 'POST') {
    $user = require_auth();
    $body = read_json_body();

    $itemKey = trim($body['item_key'] ?? '');
    $qty = $body['qty'] ?? null;
    if ($itemKey === '' || $qty === null) {
        json_error('item_key and qty are required');
    }
    $qty = parse_nonnegative_quantity($qty, 'Quantity');
    if ($qty <= 0) {
        json_error('qty must be greater than 0');
    }

    stock_begin($pdo, 'stock_request_create');
    stock_operation($pdo, $body, false);
    if (is_department_user($user)) {
        if (!empty($body['department']) && trim($body['department']) !== $user['department']) {
            json_error('department accounts cannot submit requests for other departments', 403);
        }
        $allowed = fetch_requestable_stock_items($pdo);
        $allowedKeys = array_column($allowed, 'item_key');
        if (!in_array($itemKey, $allowedKeys, true)) {
            json_error('item is not available to request from stock', 409);
        }
        stock_reply($pdo, insert_catalog_stock_request(
            $pdo,
            $user['department'],
            $itemKey,
            $qty,
            $user['name'],
            $body['notes'] ?? null
        ));
        exit;
    }

    $user = require_staff_or_above();
    $department = trim($body['department'] ?? '');
    if ($department === '') {
        json_error('department, item_key, and qty are required');
    }
    if (!is_valid_department($department)) {
        json_error('invalid department — choose one of the official departments');
    }

    stock_reply($pdo, insert_catalog_stock_request(
        $pdo,
        $department,
        $itemKey,
        $qty,
        $user['name'],
        $body['notes'] ?? null
    ));
    exit;
}

if ($method === 'PUT') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) {
        json_error('missing required query param: id');
    }
    $body = read_json_body();
    $action = $body['action'] ?? '';

    require_auth();
    stock_begin($pdo, 'request_fulfillment');
    $lock = $pdo->prepare('SELECT id FROM stock_requests WHERE id = ? FOR UPDATE');
    $lock->execute([$id]);
    $row = fetch_stock_request($pdo, $id);
    if (!$row) {
        json_error('unknown stock request', 404);
    }
    if ($row['status'] !== 'Pending') {
        json_error("request is '{$row['status']}', not Pending", 409);
    }

    if ($action === 'fulfill') {
        $user = require_staff_or_above();
        assert_stock_request_access($user, $row);

        $itemKey = trim($body['item_key'] ?? $row['item_key'] ?? '');
        if ($itemKey === '') {
            json_error('item_key is required to fulfill this request — pick the catalog item when issuing');
        }

        $qty = parse_nonnegative_quantity($body['qty'] ?? $row['qty'], 'Quantity');
        if ($qty <= 0) {
            json_error('qty must be greater than 0');
        }

        $item = stock_lock_item($pdo, $itemKey);
        $qty = parse_nonnegative_quantity($body['qty'] ?? $row['qty'], 'Quantity', $item['item_type'] === 'equipment');
        if ($qty <= 0) json_error('qty must be greater than 0');
        if ((int) ($item['active'] ?? 1) !== 1) {
            json_error('cannot fulfill — item is inactive');
        }
        if ($qty > (float) $item['current_qty']) {
            json_error(
                "only {$item['current_qty']} {$item['unit']} of {$item['label']} on hand — cannot issue $qty",
                409
            );
        }

        $issueCode = next_code('ISS', 'stock_issues', 'issue_code');
        $issuedToInput = trim($body['issued_to'] ?? '');
        $issuedTo = $issuedToInput !== ''
            ? parse_optional_person_name($issuedToInput)
            : $row['requested_by'];
        $notes = array_key_exists('notes', $body) && trim($body['notes'] ?? '') !== ''
            ? parse_optional_note($body['notes'] ?? null)
            : ($row['notes'] ?? null);

        // Request and item are already locked.
        try {
            if (empty($row['item_key'])) {
                $pdo->prepare('UPDATE stock_requests SET item_key = ? WHERE id = ?')
                    ->execute([$itemKey, $id]);
            }

            $pdo->prepare(
                'INSERT INTO stock_issues (issue_code, item_key, qty, department, issued_to, notes, recorded_by, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $issueCode,
                $itemKey,
                $qty,
                $row['department'],
                $issuedTo,
                $notes,
                $user['name'],
                'Active',
            ]);

            $issueId = (int) $pdo->lastInsertId();
            stock_reference('stock_issue', $issueId, $issueCode);

            stock_change_storage($pdo, $itemKey, -(float) $qty);

            apply_equipment_checkout($pdo, $itemKey, $row['department'], $qty);

            if (($item['item_type'] ?? '') === 'equipment') {
                log_equipment_movement(
                    $pdo,
                    $itemKey,
                    $qty,
                    'issue_from_storage',
                    $user['name'],
                    $row['department'],
                    null,
                    $issuedTo,
                    $notes,
                    'stock_issue',
                    $issueId,
                    $issueCode
                );
            }

            $pdo->prepare(
                "UPDATE stock_requests
                 SET status = 'Fulfilled', fulfilled_issue_id = ?, fulfilled_by = ?, fulfilled_at = NOW()
                 WHERE id = ?"
            )->execute([$issueId, $user['name'], $id]);

            stock_commit($pdo);
        } catch (Exception $e) {
            stock_failure($e);
        }

        $updated = fetch_stock_request($pdo, $id);
        echo json_encode(format_stock_request($updated));
        exit;
    }

    if ($action === 'cancel') {
        $user = require_auth();
        assert_stock_request_access($user, $row);

        $isOwner = $row['requested_by'] === $user['name'];
        if (is_department_user($user)) {
            if (!$isOwner) {
                json_error('department accounts can only cancel their own pending requests', 403);
            }
        } elseif (!$isOwner) {
            require_staff_or_above();
        }

        $pdo->prepare(
            "UPDATE stock_requests SET status = 'Cancelled', cancelled_at = NOW() WHERE id = ?"
        )->execute([$id]);

        stock_commit($pdo);
        $updated = fetch_stock_request($pdo, $id);
        echo json_encode(format_stock_request($updated));
        exit;
    }

    json_error("action must be 'fulfill' or 'cancel'");
}

json_error('method not allowed', 405);
