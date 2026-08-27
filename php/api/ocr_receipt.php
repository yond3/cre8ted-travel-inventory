<?php
/**
 * POST /api/ocr_receipt.php   multipart/form-data: receipt (file)
 *
 * Proxies an in-progress receipt upload to the Python OCR microservice
 * (ocr_service.py) so the receipt form can suggest an amount and OR
 * number before the user submits. Nothing is saved to disk or to the
 * purchase order here — that only happens on the real POST to
 * receipts.php. If the OCR service is unavailable, this returns a 200
 * with ok:false rather than a hard error, so the form just falls back to
 * manual entry without alarming the user.
 */
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('method not allowed', 405);
}

require_staff_or_above();

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

// Scanning is only offered for straightforward image formats for now —
// PDFs aren't rasterized server-side, so skip the round trip entirely.
if ($mime === 'application/pdf') {
    echo json_encode(['ok' => false, 'error' => 'automatic scanning is only available for JPG/PNG/WEBP receipts — enter the amount and OR number manually']);
    exit;
}

$ch = curl_init(OCR_SERVICE_URL . '/api/ocr/receipt');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, [
    'receipt' => new CURLFile($file['tmp_name'], $mime, $file['name']),
]);
$response = curl_exec($ch);
$curlErrno = curl_errno($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlErrno !== 0 || $response === false) {
    echo json_encode(['ok' => false, 'error' => 'OCR service unavailable right now — enter the amount and OR number manually']);
    exit;
}

http_response_code($httpCode ?: 502);
echo $response;
