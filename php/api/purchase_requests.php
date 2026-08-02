<?php
/**
 * GET  /api/purchase_requests.php              -> list all requests (item label, est. cost, has_po flag)
 * POST /api/purchase_requests.php               body: { employee, item_key, qty, notes }
 *      -> creates request (status Pending) + one Purchase request document
 * PUT  /api/purchase_requests.php?id=<id>       body: { action: 'approve' | 'reject' }
 *      -> Pending only. Updates the same document's status.
 */
require __DIR__ . '/config.php';

$pdo = get_pdo();
$method = $_SERVER['REQUEST_METHOD'];

function format_request(array $row): array
{
    $qty = (float) $row['qty'];
    $estimate = $row['best_price'] !== null ? round((float) $row['best_price'] * $qty, 2) : null;
    return [
        'id' => (int) $row['id'],
        'request_code' => $row['request_code'],
        'employee' => $row['employee'],
        'item_key' => $row['item_key'],
        'item_label' => $row['label'],
        'unit' => $row['unit'],
        'qty' => $qty,
        'notes' => $row['notes'],
        'status' => $row['status'],
        'created_at' => $row['created_at'],
        'estimate_cost' => $estimate,
        'best_supplier' => $row['best_supplier'],
        'has_po' => (bool) $row['has_po'],
    ];
}

if ($method === 'GET') {
    $rows = $pdo->query(
        "SELECT r.*, i.label, i.unit,
                (SELECT sp.price FROM supplier_prices sp WHERE sp.item_key = r.item_key ORDER BY sp.price ASC LIMIT 1) AS best_price,
                (SELECT s.name FROM supplier_prices sp JOIN suppliers s ON s.id = sp.supplier_id
                    WHERE sp.item_key = r.item_key ORDER BY sp.price ASC LIMIT 1) AS best_supplier,
                (SELECT COUNT(*) FROM purchase_orders po WHERE po.request_id = r.id) AS has_po
         FROM purchase_requests r
         JOIN items i ON i.item_key = r.item_key
         ORDER BY r.created_at DESC, r.id DESC"
    )->fetchAll();
    echo json_encode(array_map('format_request', $rows));
    exit;
}

if ($method === 'POST') {
    $body = read_json_body();
    $employee = trim($body['employee'] ?? '');
    $itemKey = $body['item_key'] ?? '';
    $qty = $body['qty'] ?? null;

    if ($employee === '' || $itemKey === '' || $qty === null) {
        json_error('employee, item_key, and qty are required');
    }
    get_item_or_404($itemKey);

    $code = next_code('PR', 'purchase_requests', 'request_code');
    $stmt = $pdo->prepare(
        'INSERT INTO purchase_requests (request_code, employee, item_key, qty, notes, status) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$code, $employee, $itemKey, $qty, $body['notes'] ?? null, 'Pending']);
    create_document('Purchase request', $code, 'Pending');

    $row = $pdo->query(
        "SELECT r.*, i.label, i.unit, NULL AS best_price, NULL AS best_supplier, 0 AS has_po
         FROM purchase_requests r JOIN items i ON i.item_key = r.item_key
         WHERE r.request_code = " . $pdo->quote($code)
    )->fetch();
    echo json_encode(format_request($row));
    exit;
}

if ($method === 'PUT') {
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
