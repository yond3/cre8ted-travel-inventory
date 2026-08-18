<?php
/**
 * GET  /api/purchase_requests.php              -> list all requests (item label, est. cost, has_po flag)
 * POST /api/purchase_requests.php               body: { requested_label, qty, item_key?, notes?, department?, reason?, request_type? }
 *      Staff describe what to buy in requested_label. Catalog item_key is optional for equipment.
 * PUT  /api/purchase_requests.php?id=<id>       body: { action: 'approve' | 'reject' }
 */
require __DIR__ . '/config.php';

$pdo = get_pdo();
$method = $_SERVER['REQUEST_METHOD'];

function find_item_key_by_label(PDO $pdo, string $label): ?string
{
    $stmt = $pdo->prepare('SELECT item_key FROM items WHERE label = ? AND active = 1 ORDER BY item_key LIMIT 1');
    $stmt->execute([$label]);
    $key = $stmt->fetchColumn();
    return $key !== false ? (string) $key : null;
}

function format_request(array $row): array
{
    $qty = (float) $row['qty'];
    $estimate = $row['best_price'] !== null ? round((float) $row['best_price'] * $qty, 2) : null;
    $displayLabel = trim($row['requested_label'] ?? '') ?: ($row['label'] ?? '—');
    $unit = $row['unit'] ?? 'unit(s)';
    return [
        'id' => (int) $row['id'],
        'request_code' => $row['request_code'],
        'employee' => $row['employee'],
        'department' => $row['department'] ?? null,
        'item_key' => $row['item_key'] ?? null,
        'requested_label' => $row['requested_label'] ?? null,
        'display_label' => $displayLabel,
        'item_label' => $displayLabel,
        'item_type' => $row['item_type']
            ?? (!empty($row['department']) || ($row['reason'] ?? '') === 'stock_up' ? 'equipment' : null),
        'unit' => $unit,
        'qty' => $qty,
        'notes' => $row['notes'],
        'reason' => $row['reason'] ?? null,
        'status' => $row['status'],
        'created_at' => $row['created_at'],
        'estimate_cost' => $estimate,
        'best_supplier' => $row['best_supplier'],
        'has_po' => (bool) $row['has_po'],
        'needs_catalog_link' => empty($row['item_key']),
    ];
}

const REQUEST_SELECT = "SELECT r.*, i.label, i.unit, i.item_type,
                (SELECT sp.price FROM supplier_prices sp
                    JOIN suppliers s ON s.id = sp.supplier_id AND s.active = 1
                    WHERE sp.item_key = r.item_key ORDER BY sp.price ASC LIMIT 1) AS best_price,
                (SELECT s.name FROM supplier_prices sp JOIN suppliers s ON s.id = sp.supplier_id AND s.active = 1
                    WHERE sp.item_key = r.item_key ORDER BY sp.price ASC LIMIT 1) AS best_supplier,
                (SELECT COUNT(*) FROM purchase_orders po
                    WHERE po.request_id = r.id AND po.status = 'Placed') AS has_po
         FROM purchase_requests r
         LEFT JOIN items i ON i.item_key = r.item_key";

if ($method === 'GET') {
    require_auth();
    $rows = $pdo->query(REQUEST_SELECT . ' ORDER BY r.created_at DESC, r.id DESC')->fetchAll();
    echo json_encode(array_map('format_request', $rows));
    exit;
}

if ($method === 'POST') {
    $user = require_staff_or_above();
    $body = read_json_body();
    $employee = $user['name'];
    $qty = $body['qty'] ?? null;
    $requestedLabel = trim($body['requested_label'] ?? '');
    $itemKey = trim($body['item_key'] ?? '') ?: null;
    $requestType = $body['request_type'] ?? null;

    if ($employee === '' || $qty === null) {
        json_error('employee and qty are required');
    }
    if ($requestedLabel === '' && $itemKey === null) {
        json_error('describe the item needed in requested_label');
    }

    $qty = (float) $qty;
    if ($qty <= 0) {
        json_error('qty must be greater than 0');
    }

    if ($requestedLabel === '' && $itemKey !== null) {
        $item = get_item_or_404($itemKey);
        $requestedLabel = $item['label'];
    }

    if ($itemKey === null) {
        $itemKey = find_item_key_by_label($pdo, $requestedLabel);
    }

    $item = null;
    if ($itemKey !== null) {
        $item = get_item_or_404($itemKey);
        if ((int) ($item['active'] ?? 1) !== 1) {
            json_error('cannot request an inactive catalog item');
        }
    }

    $isEquipment = $requestType === 'equipment'
        || ($item !== null && ($item['item_type'] ?? '') === 'equipment');

    if ($requestType === 'consumable' || ($item !== null && ($item['item_type'] ?? '') === 'consumable')) {
        if ($itemKey === null) {
            json_error('consumable requests must match a catalog item — choose supplies type only for listed stock, or pick Equipment for new items');
        }
        $isEquipment = false;
    }

    $department = trim($body['department'] ?? '') ?: null;
    $reason = normalize_purchase_reason($body['reason'] ?? null);

    if ($isEquipment) {
        if ($reason === 'stock_up') {
            $department = null;
        } elseif ($department === null || !is_valid_department($department)) {
            json_error('department is required for department equipment requests — or choose stock for storage');
        }
        if ($reason === null) {
            json_error('reason is required for equipment — use replacement, new_need, stock_up, or other');
        }
        if ($qty != floor($qty)) {
            json_error('equipment quantity must be a whole number');
        }
    } else {
        $department = null;
        $reason = null;
    }

    $code = next_code('PR', 'purchase_requests', 'request_code');
    $stmt = $pdo->prepare(
        'INSERT INTO purchase_requests (request_code, employee, department, item_key, requested_label, qty, notes, reason, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $code,
        $employee,
        $department,
        $itemKey,
        $requestedLabel,
        $qty,
        trim($body['notes'] ?? '') ?: null,
        $reason,
        'Pending',
    ]);
    create_document('Purchase request', $code, 'Pending');

    $row = $pdo->query(
        REQUEST_SELECT . ' WHERE r.request_code = ' . $pdo->quote($code)
    )->fetch();
    echo json_encode(format_request($row));
    exit;
}

if ($method === 'PUT') {
    require_manager_or_above();
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) {
        json_error('missing required query param: id');
    }
    $body = read_json_body();
    $action = $body['action'] ?? '';

    $stmt = $pdo->prepare('SELECT * FROM purchase_requests WHERE id = ?');
    $stmt->execute([$id]);
    $req = $stmt->fetch();
    if (!$req) {
        json_error('unknown request', 404);
    }
    if ($req['status'] !== 'Pending') {
        json_error("request is '{$req['status']}', not Pending — can't approve/reject again", 409);
    }

    if ($action === 'approve') {
        $newStatus = 'Approved';
    } elseif ($action === 'reject') {
        $newStatus = 'Rejected';
    } else {
        json_error("action must be 'approve' or 'reject'");
    }

    $pdo->prepare('UPDATE purchase_requests SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
    update_document('Purchase request', $req['request_code'], $newStatus);

    echo json_encode(['status' => 'ok', 'id' => $id, 'new_status' => $newStatus]);
    exit;
}

json_error('method not allowed', 405);
