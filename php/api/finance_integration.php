<?php
/**
 * GET  /api/finance_integration.php   -> integration contract for the Finance team
 *      (current mode, outbound endpoints + sample payloads, inbound webhook spec).
 *      No auth required — it contains no secrets, only shapes.
 *
 * POST /api/finance_integration.php   -> webhook receiver for Finance callbacks.
 *      Header: X-Finance-Secret: <FINANCE_WEBHOOK_SECRET>
 *      Body:   { "event": "...", "po_code": "PO-2026-031", ... }
 *      Events:
 *        disbursement.approved  { disbursement_id }
 *        disbursement.rejected  { reason }
 *        expense.recorded       { expense_id }
 */
require __DIR__ . '/config.php';
require __DIR__ . '/finance_client.php';

$pdo = get_pdo();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    echo json_encode([
        'mode' => FINANCE_MODE,
        'note' => FINANCE_MODE === 'stub'
            ? 'Stub mode: SCIM auto-approves disbursements and auto-records expenses locally. No outbound calls are made yet.'
            : 'Live mode: SCIM POSTs to the URLs below and expects the inbound webhook for approvals.',
        'outbound' => [
            'disbursement_request' => [
                'method' => 'POST',
                'url' => rtrim(FINANCE_API_BASE_URL, '/') . '/disbursement-requests',
                'auth' => 'Authorization: Bearer <FINANCE_API_KEY>',
                'sent_when' => 'A purchase order is created (and on manual resend by a manager)',
                'sample_payload' => [
                    'po_code' => 'PO-2026-031',
                    'request_code' => 'PR-2026-035',
                    'item_key' => 'bondpaper',
                    'item_label' => 'Bond paper (A4)',
                    'qty' => 1,
                    'unit' => 'reams',
                    'estimated_amount' => 200.00,
                    'supplier_name' => 'National Book Store',
                    'procurement_method' => 'walk_in',
                    'assigned_to' => 'Juan Dela Cruz',
                    'expense_category' => 'office_supplies',
                    'created_at' => '2026-08-14 12:20:26',
                ],
                'expected_response' => ['disbursement_id' => 'DR-2026-00123', 'status' => 'pending'],
            ],
            'expense_submit' => [
                'method' => 'POST',
                'url' => rtrim(FINANCE_API_BASE_URL, '/') . '/expenses',
                'auth' => 'Authorization: Bearer <FINANCE_API_KEY>',
                'sent_when' => 'A receipt is uploaded to a funded purchase order, or a manager records a lost receipt (no file — note only)',
                'sample_payload' => [
                    'po_code' => 'PO-2026-031',
                    'disbursement_id' => 'DR-2026-00123',
                    'actual_amount' => 195.50,
                    'receipt_unavailable' => false,
                    'receipt_number' => 'OR-123456',
                    'receipt_file_url' => rtrim(APP_BASE_URL, '/') . '/api/receipts.php?po_id=25',
                    'expense_category' => 'office_supplies',
                ],
                'sample_payload_lost_receipt' => [
                    'po_code' => 'PO-2026-031',
                    'disbursement_id' => 'DR-2026-00123',
                    'actual_amount' => 195.50,
                    'receipt_unavailable' => true,
                    'receipt_waiver_note' => 'OR lost after walk-in purchase at National Book Store; paid cash ₱195.50 on 14 Aug.',
                    'receipt_file_url' => null,
                    'receipt_number' => null,
                    'expense_category' => 'office_supplies',
                ],
                'expected_response' => ['expense_id' => 'EX-2026-00456', 'status' => 'recorded'],
            ],
        ],
        'inbound_webhook' => [
            'method' => 'POST',
            'url' => rtrim(APP_BASE_URL, '/') . '/api/finance_integration.php',
            'header' => 'X-Finance-Secret: <shared secret — see FINANCE_WEBHOOK_SECRET>',
            'events' => [
                'disbursement.approved' => ['event' => 'disbursement.approved', 'po_code' => 'PO-2026-031', 'disbursement_id' => 'DR-2026-00123'],
                'disbursement.rejected' => ['event' => 'disbursement.rejected', 'po_code' => 'PO-2026-031', 'reason' => 'Over budget'],
                'expense.recorded' => ['event' => 'expense.recorded', 'po_code' => 'PO-2026-031', 'expense_id' => 'EX-2026-00456'],
            ],
        ],
    ]);
    exit;
}

if ($method === 'POST') {
    $secret = $_SERVER['HTTP_X_FINANCE_SECRET'] ?? '';
    if ($secret === '' || !hash_equals(FINANCE_WEBHOOK_SECRET, $secret)) {
        json_error('invalid or missing X-Finance-Secret header', 401);
    }

    $body = read_json_body();
    $event = $body['event'] ?? '';
    $poCode = $body['po_code'] ?? '';
    if ($event === '' || $poCode === '') {
        json_error('event and po_code are required');
    }

    $stmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE po_code = ?');
    $stmt->execute([$poCode]);
    $po = $stmt->fetch();
    if (!$po) {
        json_error("unknown po_code '$poCode'", 404);
    }

    switch ($event) {
        case 'disbursement.approved':
            $pdo->prepare(
                "UPDATE purchase_orders SET finance_status = 'funded', finance_disbursement_id = ?, finance_funded_at = NOW() WHERE id = ?"
            )->execute([$body['disbursement_id'] ?? $po['finance_disbursement_id'], $po['id']]);
            break;
        case 'disbursement.rejected':
            $pdo->prepare(
                "UPDATE purchase_orders SET finance_status = 'disbursement_rejected' WHERE id = ?"
            )->execute([$po['id']]);
            break;
        case 'expense.recorded':
            $pdo->prepare(
                "UPDATE purchase_orders SET finance_status = 'expense_recorded', finance_expense_id = ?, finance_expense_sent_at = COALESCE(finance_expense_sent_at, NOW()) WHERE id = ?"
            )->execute([$body['expense_id'] ?? $po['finance_expense_id'], $po['id']]);
            break;
        default:
            json_error("unknown event '$event'");
    }

    finance_log($pdo, $po['id'], $event, 'inbound', $body, 200, 'processed');

    echo json_encode(['status' => 'ok']);
    exit;
}

json_error('method not allowed', 405);
