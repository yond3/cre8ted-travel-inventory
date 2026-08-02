<?php
/**
 * GET /api/documents.php  -> full audit trail, newest first.
 * Documents are created/updated automatically by purchase_requests.php and
 * purchase_orders.php — one row per PR or PO, status kept in sync.
 */
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('method not allowed', 405);
}

$limit = isset($_GET['limit']) ? max(1, (int) $_GET['limit']) : 200;

$stmt = get_pdo()->prepare('SELECT * FROM documents ORDER BY created_at DESC, id DESC LIMIT ' . $limit);
$stmt->execute();
$rows = $stmt->fetchAll();

echo json_encode(array_map(fn($r) => [
    'id' => (int) $r['id'],
    'doc_code' => $r['doc_code'],
    'doc_type' => $r['doc_type'],
    'ref_code' => $r['ref_code'],
    'status' => $r['status'],
    'created_at' => $r['created_at'],
], $rows));
