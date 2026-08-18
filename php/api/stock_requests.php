<?php
/**
 * GET  /api/stock_requests.php                    -> list requests, newest first
 * GET  /api/stock_requests.php?status=Pending       -> filter by status
 * GET  /api/stock_requests.php?departments=1        -> official department list
 * POST /api/stock_requests.php  body: { department, item_key, qty, notes? }
 *      -> creates a Pending department stock request (consumables and equipment)
 * PUT  /api/stock_requests.php?id=<id>  body: { action: 'fulfill', issued_to? }
 *      -> staff+ only. Issues stock and marks request Fulfilled.
 * PUT  /api/stock_requests.php?id=<id>  body: { action: 'cancel' }
 *      -> requester can cancel own Pending request; staff+ can cancel any Pending.
 */
require __DIR__ . '/config.php';

$pdo = get_pdo();
$method = $_SERVER['REQUEST_METHOD'];

function format_stock_request(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'request_code' => $row['request_code'],
        'department' => $row['department'],
        'item_key' => $row['item_key'],
        'item_label' => $row['label'],
        'item_type' => $row['item_type'],
        'unit' => $row['unit'],
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

const STOCK_REQUEST_SELECT = 'SELECT sr.*, i.label, i.item_type, i.unit, i.current_qty, si.issue_code
    FROM stock_requests sr
    JOIN items i ON i.item_key = sr.item_key
    LEFT JOIN stock_issues si ON si.id = sr.fulfilled_issue_id';

if ($method === 'GET') {
    require_auth();

    if (!empty($_GET['departments'])) {
        echo json_encode(DEPARTMENTS);
        exit;
    }

    $where = [];
    $params = [];
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
    $user = require_staff_or_above();
    $body = read_json_body();

    $department = trim($body['department'] ?? '');
    $itemKey = trim($body['item_key'] ?? '');
    $qty = $body['qty'] ?? null;

    if ($department === '' || $itemKey === '' || $qty === null) {
        json_error('department, item_key, and qty are required');
    }
    if (!is_valid_department($department)) {
        json_error('invalid department — choose one of the official departments');
    }

    $qty = (float) $qty;
    if ($qty <= 0) {
        json_error('qty must be greater than 0');
    }

    $item = get_item_or_404($itemKey);
    if ((int) ($item['active'] ?? 1) !== 1) {
        json_error('cannot request an inactive item');
    }
    if (($item['item_type'] ?? '') !== 'consumable' && ($item['item_type'] ?? '') !== 'equipment') {
        json_error('only consumable items and equipment can be requested from stock');
    }
    if (($item['item_type'] ?? '') === 'equipment') {
        if (!empty($item['assigned_department'])) {
            json_error('that equipment row is legacy — use the catalog item in storage', 409);
        }
        if (empty($item['location_id'])) {
            json_error('that equipment has no storage location — assign a cabinet or shelf first', 409);
        }
        if ((float) $item['current_qty'] <= 0) {
            json_error('no units of that equipment are available in storage', 409);
        }
    } elseif ((float) $item['current_qty'] <= 0) {
        json_error('no stock on hand for that item', 409);
    }

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
        $user['name'],
        trim($body['notes'] ?? '') ?: null,
        'Pending',
    ]);

    $row = $pdo->query(STOCK_REQUEST_SELECT . ' WHERE sr.request_code = ' . $pdo->quote($code))->fetch();
    echo json_encode(format_stock_request($row));
    exit;
}

if ($method === 'PUT') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) {
        json_error('missing required query param: id');
    }
    $body = read_json_body();
    $action = $body['action'] ?? '';

    $row = $pdo->query(STOCK_REQUEST_SELECT . " WHERE sr.id = $id")->fetch();
    if (!$row) {
        json_error('unknown stock request', 404);
    }
    if ($row['status'] !== 'Pending') {
        json_error("request is '{$row['status']}', not Pending", 409);
    }

    if ($action === 'fulfill') {
        $user = require_staff_or_above();
        $qty = isset($body['qty']) ? (float) $body['qty'] : (float) $row['qty'];
        if ($qty <= 0) {
            json_error('qty must be greater than 0');
        }
        $itemKey = $row['item_key'];

        $item = get_item_or_404($itemKey);
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
        $issuedTo = trim($body['issued_to'] ?? '') ?: $row['requested_by'];
        $notes = trim($body['notes'] ?? '') ?: $row['notes'];

        $pdo->beginTransaction();
        try {
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

            $pdo->prepare('UPDATE items SET current_qty = current_qty - ? WHERE item_key = ?')
                ->execute([$qty, $itemKey]);

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

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            json_error('failed to fulfill request: ' . $e->getMessage(), 500);
        }

        $updated = $pdo->query(STOCK_REQUEST_SELECT . " WHERE sr.id = $id")->fetch();
        echo json_encode(format_stock_request($updated));
        exit;
    }

    if ($action === 'cancel') {
        $user = require_auth();
        $isOwner = $row['requested_by'] === $user['name'];
        if (!$isOwner) {
            require_staff_or_above();
        }

        $pdo->prepare(
            "UPDATE stock_requests SET status = 'Cancelled', cancelled_at = NOW() WHERE id = ?"
        )->execute([$id]);

        $updated = $pdo->query(STOCK_REQUEST_SELECT . " WHERE sr.id = $id")->fetch();
        echo json_encode(format_stock_request($updated));
        exit;
    }

    json_error("action must be 'fulfill' or 'cancel'");
}

json_error('method not allowed', 405);
