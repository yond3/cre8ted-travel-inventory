<?php
/**
 * GET  /api/purchase_orders.php                 -> list all POs (request + supplier details)
 * POST /api/purchase_orders.php                  body: { request_id, supplier_id, procurement_method, assigned_to, amount }
 *      -> only for Approved requests. Sets request -> Ordered, PO -> Placed.
 * PUT  /api/purchase_orders.php?id=<id>          body: { action: 'receive' | 'cancel' }
 *      -> 'receive' is where the locked-in automation happens:
 *         PO -> Received, request -> Completed, and inventory.current_qty
 *         increases by the requested quantity automatically. This is the
 *         only place stock goes up from a purchase.
 *         Requires proof of purchase — a receipt upload via receipts.php or a
 *         manager-only lost-receipt declaration (declare_receipt_lost).
 *
 * A PO is automatically sent to Financial Management for disbursement when
 * created; PUT ?id=<id> { action: 'resend_finance' } (manager+) retries it.
 * PUT ?id=<id> { action: 'declare_receipt_lost', actual_amount, note }
 * (manager+) records a lost receipt and forwards the note to Finance.
 * PUT ?id=<id> { action: 'reject_receipt', note }
 * (manager+) rejects an uploaded receipt (wrong file, wrong amount, unreadable,
 * etc.) with a required note. Blocks 'receive' until the order gets a fresh
 * upload via receipts.php, which clears the rejection.
 * See finance_client.php.
 */
require __DIR__ . '/config.php';
require __DIR__ . '/finance_client.php';

$pdo = get_pdo();
$method = $_SERVER['REQUEST_METHOD'];

function format_order(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'po_code' => $row['po_code'],
        'request_id' => (int) $row['request_id'],
        'request_code' => $row['request_code'],
        'item_key' => $row['item_key'],
        'item_label' => $row['label'],
        'item_type' => $row['item_type'] ?? null,
        'unit' => $row['unit'],
        'qty' => (float) $row['qty'],
        'department' => $row['department'] ?? null,
        'reason' => $row['reason'] ?? null,
        'supplier_id' => $row['supplier_id'] !== null ? (int) $row['supplier_id'] : null,
        'supplier_name' => $row['supplier_name'],
        'procurement_method' => $row['procurement_method'],
        'assigned_to' => $row['assigned_to'],
        'amount' => $row['amount'] !== null ? (float) $row['amount'] : null,
        'status' => $row['status'],
        'created_at' => $row['created_at'],
        'received_at' => $row['received_at'],
        'receipt_uploaded' => $row['receipt_filename'] !== null,
        'receipt_amount' => $row['receipt_amount'] !== null ? (float) $row['receipt_amount'] : null,
        'receipt_number' => $row['receipt_number'],
        'receipt_notes' => $row['receipt_notes'],
        'receipt_uploaded_at' => $row['receipt_uploaded_at'],
        'receipt_uploaded_by' => $row['receipt_uploaded_by'],
        'receipt_waived' => !empty($row['receipt_waived']),
        'receipt_waiver_note' => $row['receipt_waiver_note'],
        'receipt_waived_at' => $row['receipt_waived_at'],
        'receipt_waived_by' => $row['receipt_waived_by'],
        'receipt_rejected' => !empty($row['receipt_rejected']),
        'receipt_rejection_note' => $row['receipt_rejection_note'],
        'receipt_rejected_at' => $row['receipt_rejected_at'],
        'receipt_rejected_by' => $row['receipt_rejected_by'],
        'finance_status' => $row['finance_status'],
        'finance_disbursement_id' => $row['finance_disbursement_id'],
        'finance_expense_id' => $row['finance_expense_id'],
        'expense_category' => $row['expense_category'],
        'finance_sent_at' => $row['finance_sent_at'],
        'finance_funded_at' => $row['finance_funded_at'],
        'finance_expense_sent_at' => $row['finance_expense_sent_at'],
    ];
}

const ORDER_SELECT = "SELECT po.*, pr.request_code, pr.item_key, pr.requested_label, pr.qty, pr.department, pr.reason,
        COALESCE(pr.requested_label, i.label) AS label, i.unit, i.item_type, s.name AS supplier_name
    FROM purchase_orders po
    JOIN purchase_requests pr ON pr.id = po.request_id
    LEFT JOIN items i ON i.item_key = pr.item_key
    LEFT JOIN suppliers s ON s.id = po.supplier_id";

if ($method === 'GET') {
    require_auth();
    $rows = $pdo->query(ORDER_SELECT . ' ORDER BY po.created_at DESC, po.id DESC')->fetchAll();
    echo json_encode(array_map('format_order', $rows));
    exit;
}

if ($method === 'POST') {
    require_manager_or_above();
    $body = read_json_body();
    $requestId = (int) ($body['request_id'] ?? 0);
    if (!$requestId) {
        json_error('request_id is required');
    }

    $stmt = $pdo->prepare('SELECT * FROM purchase_requests WHERE id = ?');
    $stmt->execute([$requestId]);
    $req = $stmt->fetch();
    if (!$req) {
        json_error('unknown request', 404);
    }
    if ($req['status'] !== 'Approved') {
        json_error("request is '{$req['status']}', not Approved — approve it before creating a PO", 409);
    }

    if (empty($req['item_key'])) {
        $linkKey = trim($body['item_key'] ?? '');
        if ($linkKey === '') {
            json_error('link this request to a catalog item before creating a PO — pick the inventory item that matches what staff asked for');
        }
        get_item_or_404($linkKey);
        $pdo->prepare('UPDATE purchase_requests SET item_key = ? WHERE id = ?')->execute([$linkKey, $requestId]);
        $req['item_key'] = $linkKey;
    }

    $openPo = $pdo->prepare(
        "SELECT COUNT(*) FROM purchase_orders WHERE request_id = ? AND status = 'Placed'"
    );
    $openPo->execute([$requestId]);
    if ((int) $openPo->fetchColumn() > 0) {
        json_error('this request already has an open purchase order', 409);
    }

    $method_ = $body['procurement_method'] ?? 'walk_in';
    if (!in_array($method_, ['walk_in', 'pickup', 'delivery', 'online'], true)) {
        json_error("procurement_method must be one of walk_in, pickup, delivery, online");
    }

    $poCode = next_code('PO', 'purchase_orders', 'po_code');

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO purchase_orders (po_code, request_id, supplier_id, procurement_method, assigned_to, amount, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $poCode,
            $requestId,
            $body['supplier_id'] ?? null,
            $method_,
            $body['assigned_to'] ?? null,
            $body['amount'] ?? null,
            'Placed',
        ]);
        $pdo->prepare("UPDATE purchase_requests SET status = 'Ordered' WHERE id = ?")->execute([$requestId]);
        create_document('Purchase order', $poCode, 'Placed');
        update_document('Purchase request', $req['request_code'], 'Ordered');
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        json_error('failed to create purchase order: ' . $e->getMessage(), 500);
    }

    $row = $pdo->query(ORDER_SELECT . " WHERE po.po_code = " . $pdo->quote($poCode))->fetch();

    try {
        finance_send_disbursement($pdo, $row);
    } catch (Exception $e) {
        // A Finance hiccup should never block creating the PO itself — the
        // manager can retry with PUT ?action=resend_finance.
        error_log('finance_send_disbursement failed: ' . $e->getMessage());
    }

    $row = $pdo->query(ORDER_SELECT . " WHERE po.po_code = " . $pdo->quote($poCode))->fetch();
    echo json_encode(format_order($row));
    exit;
}

if ($method === 'PUT') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) {
        json_error('missing required query param: id');
    }
    $body = read_json_body();
    $action = $body['action'] ?? '';

    if ($action === 'receive') {
        // Manager sign-off required: staff upload the receipt, but a second
        // pair of eyes must verify it against the PO before stock/finance
        // are finalized — prevents a wrong-receipt-then-receive mistake.
        require_manager_or_above();
    } elseif ($action === 'cancel') {
        require_super_admin();
    } elseif ($action === 'resend_finance') {
        require_manager_or_above();
    } elseif ($action === 'declare_receipt_lost') {
        require_manager_or_above();
    } elseif ($action === 'reject_receipt') {
        require_manager_or_above();
    } else {
        json_error("action must be 'receive', 'cancel', 'resend_finance', 'declare_receipt_lost', or 'reject_receipt'");
    }

    $row = $pdo->query(ORDER_SELECT . " WHERE po.id = $id")->fetch();
    if (!$row) {
        json_error('unknown purchase order', 404);
    }
    if ($row['status'] !== 'Placed') {
        json_error("order is '{$row['status']}', not Placed", 409);
    }

    if ($action === 'resend_finance') {
        try {
            finance_send_disbursement($pdo, $row);
        } catch (Exception $e) {
            json_error('failed to resend to Finance: ' . $e->getMessage(), 500);
        }
        $updated = $pdo->query(ORDER_SELECT . " WHERE po.id = $id")->fetch();
        echo json_encode(format_order($updated));
        exit;
    }

    if ($action === 'declare_receipt_lost') {
        if ($row['finance_status'] !== 'funded') {
            json_error("purchase order is not funded yet (finance status: {$row['finance_status']})", 409);
        }
        if ($row['receipt_filename']) {
            json_error('a receipt is already uploaded for this order', 409);
        }
        if (!empty($row['receipt_waived'])) {
            json_error('lost receipt was already recorded for this order', 409);
        }

        $amount = $body['actual_amount'] ?? null;
        if ($amount === null || $amount === '' || !is_numeric($amount) || (float) $amount <= 0) {
            json_error('actual_amount is required');
        }
        $note = trim($body['note'] ?? '');
        if (strlen($note) < 10) {
            json_error('note is required (at least 10 characters — explain what was purchased and why the receipt is missing)');
        }

        $user = get_session_user();
        $pdo->prepare(
            'UPDATE purchase_orders SET
                receipt_waived = 1,
                receipt_waiver_note = ?,
                receipt_waived_at = NOW(),
                receipt_waived_by = ?,
                receipt_amount = ?
             WHERE id = ?'
        )->execute([$note, $user['name'], (float) $amount, $id]);

        $updated = $pdo->query(ORDER_SELECT . " WHERE po.id = $id")->fetch();
        try {
            finance_send_expense($pdo, $updated);
        } catch (Exception $e) {
            error_log('finance_send_expense failed after declare_receipt_lost: ' . $e->getMessage());
        }
        $updated = $pdo->query(ORDER_SELECT . " WHERE po.id = $id")->fetch();
        echo json_encode(format_order($updated));
        exit;
    }

    if ($action === 'reject_receipt') {
        if (!$row['receipt_filename']) {
            json_error('no receipt has been uploaded for this order yet', 409);
        }
        if (!empty($row['receipt_rejected'])) {
            json_error('this receipt was already rejected — waiting for a new upload', 409);
        }
        $note = trim($body['note'] ?? '');
        if (strlen($note) < 10) {
            json_error('note is required (at least 10 characters — explain what is wrong so staff can fix it)');
        }

        $user = get_session_user();
        $pdo->prepare(
            'UPDATE purchase_orders SET
                receipt_rejected = 1,
                receipt_rejection_note = ?,
                receipt_rejected_at = NOW(),
                receipt_rejected_by = ?
             WHERE id = ?'
        )->execute([$note, $user['name'], $id]);

        $updated = $pdo->query(ORDER_SELECT . " WHERE po.id = $id")->fetch();
        echo json_encode(format_order($updated));
        exit;
    }

    // A rejected receipt no longer counts as proof — the order must get a
    // fresh upload (which clears the rejection) before it can be received.
    $hasProof = ($row['receipt_filename'] && empty($row['receipt_rejected'])) || !empty($row['receipt_waived']);
    if ($action === 'receive' && !$hasProof) {
        $reason = !empty($row['receipt_rejected'])
            ? 'the uploaded receipt was rejected — wait for a corrected upload before marking this order received'
            : 'upload the purchase receipt or record a lost receipt (manager) before marking this order received';
        json_error($reason, 409);
    }

    if ($action === 'receive') {
        $pdo->beginTransaction();
        try {
            $user = get_session_user();
            $pdo->prepare("UPDATE purchase_orders SET status = 'Received', received_at = NOW() WHERE id = ?")
                ->execute([$id]);
            $pdo->prepare("UPDATE purchase_requests SET status = 'Completed' WHERE id = ?")
                ->execute([$row['request_id']]);

            $receiveResult = null;
            if (($row['item_type'] ?? '') === 'equipment') {
                $receiveResult = receive_purchased_equipment($pdo, $row, $body);
                $itemKey = $receiveResult['storage_item_key'] ?? $row['item_key'];
                if ($receiveResult['received_to'] === 'department') {
                    log_equipment_movement(
                        $pdo,
                        $itemKey,
                        (float) $receiveResult['qty_added'],
                        'deploy_from_purchase',
                        $user['name'] ?? 'System',
                        $receiveResult['deployed_to'],
                        null,
                        null,
                        null,
                        'purchase_order',
                        $id,
                        $row['po_code']
                    );
                } elseif ($receiveResult['received_to'] === 'storage') {
                    log_equipment_movement(
                        $pdo,
                        $itemKey,
                        (float) $receiveResult['qty_added'],
                        'receive_to_storage',
                        $user['name'] ?? 'System',
                        null,
                        $receiveResult['storage_location_id'] ?? null,
                        null,
                        null,
                        'purchase_order',
                        $id,
                        $row['po_code']
                    );
                }
            } else {
                $pdo->prepare('UPDATE items SET current_qty = current_qty + ? WHERE item_key = ?')
                    ->execute([$row['qty'], $row['item_key']]);
                $newQty = $pdo->prepare('SELECT current_qty FROM items WHERE item_key = ?');
                $newQty->execute([$row['item_key']]);
                $receiveResult = [
                    'qty_added' => (float) $row['qty'],
                    'new_stock' => (float) $newQty->fetchColumn(),
                    'deployed_to' => null,
                    'received_to' => 'consumable',
                    'storage_item_key' => $row['item_key'],
                ];
            }

            update_document('Purchase order', $row['po_code'], 'Received');
            update_document('Purchase request', $row['request_code'], 'Completed');
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            json_error('failed to mark received: ' . $e->getMessage(), 500);
        }

        echo json_encode([
            'status' => 'ok',
            'id' => $id,
            'new_status' => 'Received',
            'item_key' => $receiveResult['storage_item_key'] ?? $row['item_key'],
            'item_label' => $row['label'],
            'item_type' => $row['item_type'] ?? null,
            'unit' => $row['unit'],
            'qty_added' => $receiveResult['qty_added'],
            'new_stock' => $receiveResult['new_stock'],
            'deployed_to' => $receiveResult['deployed_to'],
            'received_to' => $receiveResult['received_to'],
            'department' => $row['department'] ?? null,
        ]);
        exit;
    }

    if ($action === 'cancel') {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE purchase_orders SET status = 'Cancelled' WHERE id = ?")->execute([$id]);
            // Back to Approved so admin can re-create a PO (e.g. with a different supplier).
            $pdo->prepare("UPDATE purchase_requests SET status = 'Approved' WHERE id = ?")->execute([$row['request_id']]);
            update_document('Purchase order', $row['po_code'], 'Cancelled');
            update_document('Purchase request', $row['request_code'], 'Approved');
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            json_error('failed to cancel: ' . $e->getMessage(), 500);
        }
        echo json_encode(['status' => 'ok', 'id' => $id, 'new_status' => 'Cancelled']);
        exit;
    }

    json_error("action must be 'receive' or 'cancel'");
}

json_error('method not allowed', 405);
