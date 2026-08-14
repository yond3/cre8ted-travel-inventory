<?php
/**
 * Financial Management integration client.
 *
 * Two outbound events, each logged to finance_integration_log:
 *   - finance_send_disbursement(): PO created -> ask Finance to release budget.
 *   - finance_send_expense():      receipt uploaded -> forward proof of purchase.
 *
 * In FINANCE_MODE = 'stub' (see config.php), both calls skip the network
 * entirely and auto-approve immediately, so the whole PO -> funded -> receipt
 * -> expense recorded flow is demoable without a real Finance API. Switching
 * to 'live' only requires FINANCE_API_BASE_URL / FINANCE_API_KEY — nothing
 * in purchase_orders.php or receipts.php needs to change.
 *
 * Real approvals in 'live' mode arrive later via finance_integration.php
 * (Finance calls back with disbursement.approved / disbursement.rejected /
 * expense.recorded).
 */

function expense_category_for_item_type(?string $itemType): string
{
    return $itemType === 'equipment' ? 'equipment' : 'office_supplies';
}

function finance_log(PDO $pdo, int $poId, string $eventType, string $direction, $payload, ?int $responseStatus = null, ?string $responseBody = null): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO finance_integration_log (po_id, event_type, direction, payload, response_status, response_body)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $poId,
        $eventType,
        $direction,
        json_encode($payload),
        $responseStatus,
        $responseBody,
    ]);
}

/**
 * POSTs a JSON payload to a Finance endpoint. Only used in 'live' mode.
 * Never throws — network failures are reported back as a 0 status so
 * callers can log the failure without crashing the request that triggered it.
 */
function finance_http_post(string $path, array $payload): array
{
    $url = rtrim(FINANCE_API_BASE_URL, '/') . '/' . ltrim($path, '/');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . FINANCE_API_KEY,
        ],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        return [0, null, $err !== '' ? $err : 'request failed'];
    }
    return [$status, json_decode($raw, true), $raw];
}

function finance_disbursement_payload(array $po, string $category): array
{
    return [
        'po_code' => $po['po_code'],
        'request_code' => $po['request_code'],
        'item_key' => $po['item_key'],
        'item_label' => $po['label'],
        'qty' => (float) $po['qty'],
        'unit' => $po['unit'],
        'estimated_amount' => $po['amount'] !== null ? (float) $po['amount'] : null,
        'supplier_name' => $po['supplier_name'],
        'procurement_method' => $po['procurement_method'],
        'assigned_to' => $po['assigned_to'],
        'expense_category' => $category,
        'created_at' => $po['created_at'],
    ];
}

/**
 * Sends (or re-sends) a disbursement request for a Placed PO. $po must come
 * from purchase_orders.php's ORDER_SELECT (joined with items/suppliers).
 */
function finance_send_disbursement(PDO $pdo, array $po): void
{
    $itemStmt = $pdo->prepare('SELECT item_type FROM items WHERE item_key = ?');
    $itemStmt->execute([$po['item_key']]);
    $category = expense_category_for_item_type($itemStmt->fetchColumn() ?: null);
    $payload = finance_disbursement_payload($po, $category);

    $pdo->prepare(
        "UPDATE purchase_orders SET finance_status = 'pending_disbursement', expense_category = ?, finance_sent_at = NOW() WHERE id = ?"
    )->execute([$category, $po['id']]);

    if (FINANCE_MODE === 'stub') {
        finance_log($pdo, $po['id'], 'disbursement.request', 'outbound', $payload, 200, 'stub: not sent over the network');
        $disbursementId = 'STUB-DR-' . $po['po_code'];
        $pdo->prepare(
            "UPDATE purchase_orders SET finance_status = 'funded', finance_disbursement_id = ?, finance_funded_at = NOW() WHERE id = ?"
        )->execute([$disbursementId, $po['id']]);
        finance_log($pdo, $po['id'], 'disbursement.approved', 'inbound', ['disbursement_id' => $disbursementId], 200, 'stub: auto-approved');
        return;
    }

    [$status, $body, $raw] = finance_http_post('disbursement-requests', $payload);
    finance_log($pdo, $po['id'], 'disbursement.request', 'outbound', $payload, $status ?: null, $raw);

    if ($status >= 200 && $status < 300 && !empty($body['disbursement_id'])) {
        $pdo->prepare(
            'UPDATE purchase_orders SET finance_disbursement_id = ? WHERE id = ?'
        )->execute([$body['disbursement_id'], $po['id']]);
    }
    // Real approval/rejection arrives later via finance_integration.php.
}

function finance_expense_payload(array $po): array
{
    $base = [
        'po_code' => $po['po_code'],
        'disbursement_id' => $po['finance_disbursement_id'],
        'actual_amount' => $po['receipt_amount'] !== null ? (float) $po['receipt_amount'] : null,
        'expense_category' => $po['expense_category'],
    ];

    if (!empty($po['receipt_waived'])) {
        return array_merge($base, [
            'receipt_unavailable' => true,
            'receipt_waiver_note' => $po['receipt_waiver_note'],
            'receipt_file_url' => null,
            'receipt_number' => null,
        ]);
    }

    return array_merge($base, [
        'receipt_unavailable' => false,
        'receipt_number' => $po['receipt_number'],
        'receipt_file_url' => rtrim(APP_BASE_URL, '/') . '/api/receipts.php?po_id=' . $po['id'],
    ]);
}

/**
 * Sends the just-uploaded receipt as an expense/AP record. $po is a plain
 * row from purchase_orders (as returned by receipts.php's fetch_po_or_404).
 */
function finance_send_expense(PDO $pdo, array $po): void
{
    $payload = finance_expense_payload($po);

    $pdo->prepare("UPDATE purchase_orders SET finance_status = 'expense_pending' WHERE id = ?")->execute([$po['id']]);

    if (FINANCE_MODE === 'stub') {
        finance_log($pdo, $po['id'], 'expense.submit', 'outbound', $payload, 200, 'stub: not sent over the network');
        $expenseId = 'STUB-EX-' . $po['po_code'];
        $pdo->prepare(
            "UPDATE purchase_orders SET finance_status = 'expense_recorded', finance_expense_id = ?, finance_expense_sent_at = NOW() WHERE id = ?"
        )->execute([$expenseId, $po['id']]);
        finance_log($pdo, $po['id'], 'expense.recorded', 'inbound', ['expense_id' => $expenseId], 200, 'stub: auto-recorded');
        return;
    }

    [$status, $body, $raw] = finance_http_post('expenses', $payload);
    finance_log($pdo, $po['id'], 'expense.submit', 'outbound', $payload, $status ?: null, $raw);

    if ($status >= 200 && $status < 300 && !empty($body['expense_id'])) {
        $pdo->prepare(
            "UPDATE purchase_orders SET finance_expense_id = ?, finance_expense_sent_at = NOW() WHERE id = ?"
        )->execute([$body['expense_id'], $po['id']]);
    }
}
