<?php
/**
 * POST /api/receipts.php?po_id=<id>   multipart/form-data: receipt (file), amount, receipt_number, notes
 *      -> Attaches proof-of-purchase to a Placed order that Financial
 *         Management has already funded. Required before that order can be
 *         marked Received (see purchase_orders.php). Automatically forwards
 *         the receipt to Finance as an expense once saved (finance_client.php).
 *         A second upload is only allowed after a manager rejects the first
 *         one (PUT purchase_orders.php action=reject_receipt); it replaces
 *         the file and clears the rejection.
 * GET  /api/receipts.php?po_id=<id>   -> streams the stored receipt file (auth required).
 * GET  /api/receipts.php?check_number=<or>&exclude_po_id=<id> -> duplicate OR check (auth required).
 */
require __DIR__ . '/config.php';
block_department_user();
require __DIR__ . '/finance_client.php';

$pdo = get_pdo();
$method = $_SERVER['REQUEST_METHOD'];

$poId = (int) ($_GET['po_id'] ?? 0);
$checkNumber = trim($_GET['check_number'] ?? '');

function fetch_po_or_404(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        json_error('unknown purchase order', 404);
    }
    return $row;
}

if ($method === 'GET') {
    require_auth();

    if ($checkNumber !== '') {
        $excludePoId = (int) ($_GET['exclude_po_id'] ?? 0);
        $matches = find_receipt_number_duplicates($pdo, $checkNumber, $excludePoId);
        echo json_encode([
            'duplicate' => count($matches) > 0,
            'matches' => $matches,
        ]);
        exit;
    }

    if (!$poId) {
        json_error('missing required query param: po_id');
    }

    $po = fetch_po_or_404($pdo, $poId);
    if (!$po['receipt_filename']) {
        json_error('no receipt uploaded for this purchase order', 404);
    }
    $path = RECEIPT_UPLOAD_DIR . $po['receipt_filename'];
    if (!is_file($path)) {
        json_error('receipt file is missing on disk', 404);
    }
    header('Content-Type: ' . ($po['receipt_mime'] ?: 'application/octet-stream'));
    header('Content-Disposition: inline; filename="' . basename($po['receipt_original_name'] ?: $po['receipt_filename']) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

if ($method === 'POST') {
    if (!$poId) {
        json_error('missing required query param: po_id');
    }
    $user = require_staff_or_above();
    $po = fetch_po_or_404($pdo, $poId);

    if ($po['status'] !== 'Placed') {
        json_error("order is '{$po['status']}', not Placed — a receipt can only be attached before receiving", 409);
    }

    // A first upload requires 'funded'. A reupload (after rejection) happens
    // once the expense has already been sent, so finance_status has since
    // moved on to expense_pending/expense_recorded — that still counts as
    // funded for this check, it's just further along.
    $fundedOrBeyond = ['funded', 'expense_pending', 'expense_recorded'];
    if (!in_array($po['finance_status'], $fundedOrBeyond, true)) {
        json_error("purchase order is not funded yet (finance status: {$po['finance_status']}) — wait for Financial Management to release the budget before uploading a receipt", 409);
    }

    if (!empty($po['receipt_waived'])) {
        json_error('this order was marked as lost receipt by a manager — upload a file is not allowed', 409);
    }

    $isReupload = (bool) $po['receipt_filename'];
    if ($isReupload && empty($po['receipt_rejected'])) {
        json_error('a receipt is already on file for this order — ask a manager to review or reject it before uploading a replacement', 409);
    }

    if (empty($_FILES['receipt']) || $_FILES['receipt']['error'] === UPLOAD_ERR_NO_FILE) {
        json_error('receipt file is required');
    }
    $file = $_FILES['receipt'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        json_error('file upload failed');
    }
    if ($file['size'] > RECEIPT_MAX_BYTES) {
        json_error('file is too large (max 5 MB)');
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, RECEIPT_ALLOWED_EXTENSIONS, true)) {
        json_error('unsupported file type — use JPG, PNG, WEBP, or PDF');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, RECEIPT_ALLOWED_MIME, true)) {
        json_error('unsupported file type — use JPG, PNG, WEBP, or PDF');
    }

    $amount = $_POST['amount'] ?? null;
    if ($amount === null || $amount === '' || !is_numeric($amount) || (float) $amount <= 0) {
        json_error('receipt amount is required');
    }

    $receiptNumber = trim($_POST['receipt_number'] ?? '') ?: null;
    if ($receiptNumber) {
        $dupes = find_receipt_number_duplicates($pdo, $receiptNumber, $poId);
        if ($dupes) {
            $codes = implode(', ', array_column($dupes, 'po_code'));
            json_error("This OR number is already on file for $codes — use a different receipt or verify with a manager.", 409);
        }
    }

    if (!is_dir(RECEIPT_UPLOAD_DIR) && !mkdir(RECEIPT_UPLOAD_DIR, 0775, true) && !is_dir(RECEIPT_UPLOAD_DIR)) {
        json_error('receipt storage directory is not available', 500);
    }

    $storedName = 'po' . $poId . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], RECEIPT_UPLOAD_DIR . $storedName)) {
        json_error('failed to save receipt file', 500);
    }

    // On a reupload (after rejection), the old file is superseded — remove
    // it from disk. Only the latest receipt is kept; the rejection note is
    // cleared in the same update since it no longer applies.
    if ($po['receipt_filename'] && is_file(RECEIPT_UPLOAD_DIR . $po['receipt_filename'])) {
        @unlink(RECEIPT_UPLOAD_DIR . $po['receipt_filename']);
    }

    // A reupload replaces the file/metadata and clears any prior rejection —
    // it's a fresh receipt awaiting the manager's review again. A successful
    // upload also clears any in-flight lost-receipt report from staff.
    $stmt = $pdo->prepare(
        'UPDATE purchase_orders SET
            receipt_filename = ?, receipt_original_name = ?, receipt_mime = ?,
            receipt_amount = ?, receipt_number = ?, receipt_notes = ?,
            receipt_uploaded_at = NOW(), receipt_uploaded_by = ?,
            receipt_rejected = 0, receipt_rejection_note = NULL,
            receipt_rejected_at = NULL, receipt_rejected_by = NULL,
            receipt_lost_report_pending = 0,
            receipt_lost_report_amount = NULL, receipt_lost_report_note = NULL,
            receipt_lost_report_at = NULL, receipt_lost_report_by = NULL,
            receipt_lost_report_rejected = 0, receipt_lost_report_rejection_note = NULL,
            receipt_lost_report_rejected_at = NULL, receipt_lost_report_rejected_by = NULL
         WHERE id = ?'
    );
    $stmt->execute([
        $storedName,
        $file['name'],
        $mime,
        (float) $amount,
        $receiptNumber,
        trim($_POST['notes'] ?? '') ?: null,
        $user['name'],
        $poId,
    ]);

    $freshPo = fetch_po_or_404($pdo, $poId);
    try {
        // Reupload after a rejection still corrects the same expense record
        // Finance already has; stub mode simply resends with the new amount.
        finance_send_expense($pdo, $freshPo);
    } catch (Exception $e) {
        // A Finance hiccup should never block the receipt upload itself —
        // the expense send can be retried later (a manual retry endpoint
        // can be added if this ever fails in practice with a live API).
        error_log('finance_send_expense failed: ' . $e->getMessage());
    }
    $freshPo = fetch_po_or_404($pdo, $poId);

    echo json_encode([
        'status' => 'ok',
        'po_id' => $poId,
        'receipt_amount' => (float) $amount,
        'receipt_uploaded_at' => date('Y-m-d H:i:s'),
        'receipt_uploaded_by' => $user['name'],
        'finance_status' => $freshPo['finance_status'],
        'was_reupload' => $isReupload,
    ]);
    exit;
}

json_error('method not allowed', 405);
