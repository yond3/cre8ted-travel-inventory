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
    $requestId=null;
    if(!empty($row['request_item_id'])){
        $q=get_pdo()->prepare('SELECT request_id FROM stock_request_items WHERE id=?');$q->execute([$row['request_item_id']]);$requestId=(int)$q->fetchColumn();
    }
    return [
        ...category_metadata_for_key(get_pdo(), $row['item_key'] ?? null),
        'id' => (int) $row['id'],
        'request_item_id' => isset($row['request_item_id'])?(int)$row['request_item_id']:null,
        'request_id' => $requestId,
        'issue_code' => $row['issue_code'],
        'item_key' => $row['item_key'],
        'item_label' => equipment_item_display_label($row),
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

const ISSUE_SELECT = 'SELECT si.*, i.label, i.equipment_group, i.item_type, i.unit FROM stock_issues si
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
    if(request_schema_available($pdo)){
        $actor=require_manager_or_above();$body=read_json_body();
        stock_begin($pdo,'expedited_issue');stock_operation($pdo,$body);
        $id=request_create($pdo,$body,$actor);$r=request_lock($pdo,$id);
        $decisions=[];$issues=[];
        foreach(request_lines($pdo,$id)as $line){
            $decisions[]=['id'=>(int)$line['id'],'approved_qty'=>(float)$line['requested_qty'],'override_reason'=>$body['override_reason']??null];
            $issues[]=['id'=>(int)$line['id'],'qty'=>(float)$line['requested_qty']];
        }
        request_approve($pdo,$r,['items'=>$decisions],$actor);
        request_issue($pdo,request_lock($pdo,$id),array_merge($body,['items'=>$issues]),$actor);
        request_bump($pdo,$id);stock_reply($pdo,request_snapshot($pdo,$id));exit;
    }
    $user = require_staff_or_above();
    $body = read_json_body();

    $itemKey = trim($body['item_key'] ?? '');
    $qty = $body['qty'] ?? null;
    $department = trim($body['department'] ?? '');

    if ($itemKey === '' || $qty === null || $department === '') {
        json_error('item_key, qty, and department are required');
    }
    $qty = parse_nonnegative_quantity($qty, 'Quantity');
    if ($qty <= 0) {
        json_error('qty must be greater than 0');
    }
    if (!is_valid_department($department)) {
        json_error('invalid department — choose one of the official departments');
    }

    $issuedTo = parse_optional_person_name($body['issued_to'] ?? null);

    stock_begin($pdo, 'issue');
    stock_operation($pdo, $body);
    $item = stock_lock_item($pdo, $itemKey);
    $qty = parse_nonnegative_quantity($body['qty'], 'Quantity', $item['item_type'] === 'equipment');
    if ($qty <= 0) json_error('qty must be greater than 0');
    if ((int) ($item['active'] ?? 1) !== 1) {
        json_error('cannot issue an inactive item');
    }
    if ($qty > (float) $item['current_qty']) {
        json_error("only {$item['current_qty']} {$item['unit']} of {$item['label']} on hand — cannot issue $qty", 409);
    }

    $code = next_code('ISS', 'stock_issues', 'issue_code');

    // Transaction already holds the item lock.
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
        stock_reference('stock_issue', $issueId, $code);

        stock_change_storage($pdo, $itemKey, -(float) $qty);

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

        stock_commit($pdo);
    } catch (Exception $e) {
        stock_failure($e);
    }

    $row = $pdo->query(ISSUE_SELECT . ' WHERE si.issue_code = ' . $pdo->quote($code))->fetch();
    stock_reply($pdo, format_issue($row));
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

    stock_begin($pdo, 'issue_reversal');
    $row = $pdo->query(ISSUE_SELECT . " WHERE si.id = $id FOR UPDATE")->fetch();
    if (!$row) {
        json_error('unknown issue', 404);
    }
    if ($row['status'] !== 'Active') {
        json_error("issue is already '{$row['status']}'", 409);
    }

    $voidReason = parse_optional_note($body['reason'] ?? null, 'Void reason');
    $requestLine=null;$linkedRequest=null;
    if(request_schema_available($pdo)){
        $voidReason=request_reason($body['reason']??null,'Void reason');
        $q=$pdo->prepare("SELECT id FROM equipment_movements WHERE item_key=? AND status='Active'
          AND id>(SELECT COALESCE(MAX(em.id),0) FROM equipment_movements em WHERE em.reference_type='stock_issue' AND em.reference_id=?) LIMIT 1");
        $q->execute([$row['item_key'],$id]);
        $itemForVoid=get_item_or_404($row['item_key']);
        if($itemForVoid['item_type']==='equipment'&&$q->fetchColumn())json_error('Later equipment movements make this reversal ambiguous. Use a reviewed correction.',409);
        if(!empty($row['request_item_id'])){
            $q=$pdo->prepare('SELECT * FROM stock_request_items WHERE id=?');$q->execute([$row['request_item_id']]);$requestLine=$q->fetch();
            $linkedRequest=request_lock($pdo,(int)$requestLine['request_id']);request_revision($body,$linkedRequest);
            if(!in_array($body['reservation_disposition']??'', ['release','reissue'],true))json_error('Choose release or reissue for the reversed quantity');
            if(in_array($linkedRequest['status'],['Closed','Cancelled','Rejected'],true)&&$body['reservation_disposition']==='reissue')json_error('Closed requests cannot reopen through an issue reversal',409);
        }
    }

    $item = stock_lock_item($pdo, $row['item_key']);
    stock_reference('stock_issue', $id, $row['issue_code']);
    try {
        $pdo->prepare(
            "UPDATE stock_issues SET status = 'Voided', voided_reason = ?, voided_at = NOW() WHERE id = ?"
        )->execute([$voidReason, $id]);

        // Reverse the deduction so the stock originally decremented is
        // credited back — mirrors how cancelling a PO restores its request.
        stock_change_storage($pdo, $row['item_key'], (float) $row['qty']);

        reverse_equipment_checkout($pdo, $row['item_key'], $row['department'], (float) $row['qty']);

        void_equipment_movements_for_reference(
            $pdo,
            'stock_issue',
            $id,
            $voidReason
        );
        if($requestLine){
            if($body['reservation_disposition']==='release'){
                $pdo->prepare('UPDATE stock_request_items SET released_qty=released_qty+? WHERE id=?')->execute([(float)$row['qty'],$requestLine['id']]);
            }
            request_recompute($pdo,(int)$requestLine['request_id']);request_bump($pdo,(int)$requestLine['request_id']);
            record_audit('stock_request.issue_void','stock_request',(string)$requestLine['request_id'],null,['issue_id'=>$id,'reason'=>$voidReason,'disposition'=>$body['reservation_disposition']],true);
        }

        stock_commit($pdo);
    } catch (Exception $e) {
        stock_failure($e);
    }

    $updated = $pdo->query(ISSUE_SELECT . " WHERE si.id = $id")->fetch();
    echo json_encode(format_issue($updated));
    exit;
}

json_error('method not allowed', 405);
