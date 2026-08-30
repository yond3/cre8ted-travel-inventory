<?php
/**
 * GET  /api/stock_issues.php                        -> list issues, newest first
 * GET  /api/stock_issues.php?item=<key>              -> issues for one item
 * GET  /api/stock_issues.php?department=Marketing    -> issues for one department
 * POST /api/stock_issues.php  body: { item_key, qty, department, issued_to?, notes? }
 *      -> records a checkout: current_qty decreases immediately (unlike
 *         Close month, which only reconciles against a physical count).
 * PUT  /api/stock_issues.php?id=<id>  body: { action: 'void', reason? }
 *      -> manager+ only. Reverses the stock deduction and marks Voided.
 */
require __DIR__ . '/config.php';
block_department_user();

$pdo = get_pdo();
$method = $_SERVER['REQUEST_METHOD'];

function format_issue(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'issue_code' => $row['issue_code'],
        'item_key' => $row['item_key'],
        'item_label' => $row['label'],
        'unit' => $row['unit'],
        'qty' => (float) $row['qty'],
        'department' => $row['department'],
        'issued_to' => $row['issued_to'],
        'notes' => $row['notes'],
        'recorded_by' => $row['recorded_by'],
        'status' => $row['status'],
        'voided_reason' => $row['voided_reason'],
        'created_at' => $row['created_at'],
        'voided_at' => $row['voided_at'],
    ];
}

const ISSUE_SELECT = 'SELECT si.*, i.label, i.unit FROM stock_issues si
    JOIN items i ON i.item_key = si.item_key';

if ($method === 'GET') {
    require_auth();

    $where = [];
    $params = [];
    if (!empty($_GET['item'])) {
        $where[] = 'si.item_key = ?';
        $params[] = $_GET['item'];
    }
    if (!empty($_GET['department'])) {
        $where[] = 'si.department = ?';
        $params[] = $_GET['department'];
    }

    $sql = ISSUE_SELECT;
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY si.created_at DESC, si.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(array_map('format_issue', $stmt->fetchAll()));
    exit;
}

if ($method === 'POST') {
    $user = require_staff_or_above();
    $body = read_json_body();

    $itemKey = trim($body['item_key'] ?? '');
    $qty = $body['qty'] ?? null;
    $department = trim($body['department'] ?? '');

    if ($itemKey === '' || $qty === null || $department === '') {
        json_error('item_key, qty, and department are required');
    }
    $qty = (float) $qty;
    if ($qty <= 0) {
        json_error('qty must be greater than 0');
    }
    if (!is_valid_department($department)) {
        json_error('invalid department — choose one of the official departments');
    }

    $issuedTo = parse_optional_person_name($body['issued_to'] ?? null);

    $item = get_item_or_404($itemKey);
    if ((int) ($item['active'] ?? 1) !== 1) {
        json_error('cannot issue an inactive item');
    }
    if ($qty > (float) $item['current_qty']) {
        json_error("only {$item['current_qty']} {$item['unit']} of {$item['label']} on hand — cannot issue $qty", 409);
    }

    $code = next_code('ISS', 'stock_issues', 'issue_code');

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO stock_issues (issue_code, item_key, qty, department, issued_to, notes, recorded_by, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $code,
            $itemKey,
            $qty,
            $department,
            $issuedTo,
            parse_optional_note($body['notes'] ?? null),
            $user['name'],
            'Active',
        ]);
        $issueId = (int) $pdo->lastInsertId();

        $pdo->prepare('UPDATE items SET current_qty = current_qty - ? WHERE item_key = ?')
            ->execute([$qty, $itemKey]);

        apply_equipment_checkout($pdo, $itemKey, $department, $qty);

        if (($item['item_type'] ?? '') === 'equipment') {
            log_equipment_movement(
                $pdo,
                $itemKey,
                $qty,
                'issue_from_storage',
                $user['name'],
                $department,
                null,
                $issuedTo,
                parse_optional_note($body['notes'] ?? null),
                'stock_issue',
                $issueId,
                $code
            );
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        json_error('failed to record issue: ' . $e->getMessage(), 500);
    }

    $row = $pdo->query(ISSUE_SELECT . ' WHERE si.issue_code = ' . $pdo->quote($code))->fetch();
    echo json_encode(format_issue($row));
    exit;
}

if ($method === 'PUT') {
    require_manager_or_above();
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) {
        json_error('missing required query param: id');
    }
    $body = read_json_body();
    if (($body['action'] ?? '') !== 'void') {
        json_error("action must be 'void'");
    }

    $row = $pdo->query(ISSUE_SELECT . " WHERE si.id = $id")->fetch();
    if (!$row) {
        json_error('unknown issue', 404);
    }
    if ($row['status'] !== 'Active') {
        json_error("issue is already '{$row['status']}'", 409);
    }

    $voidReason = parse_optional_note($body['reason'] ?? null, 'Void reason');

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "UPDATE stock_issues SET status = 'Voided', voided_reason = ?, voided_at = NOW() WHERE id = ?"
        )->execute([$voidReason, $id]);

        // Reverse the deduction so the stock originally decremented is
        // credited back — mirrors how cancelling a PO restores its request.
        $pdo->prepare('UPDATE items SET current_qty = current_qty + ? WHERE item_key = ?')
            ->execute([$row['qty'], $row['item_key']]);

        reverse_equipment_checkout($pdo, $row['item_key'], $row['department'], (float) $row['qty']);

        void_equipment_movements_for_reference(
            $pdo,
            'stock_issue',
            $id,
            $voidReason
        );

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        json_error('failed to void issue: ' . $e->getMessage(), 500);
    }

    $updated = $pdo->query(ISSUE_SELECT . " WHERE si.id = $id")->fetch();
    echo json_encode(format_issue($updated));
    exit;
}

json_error('method not allowed', 405);
