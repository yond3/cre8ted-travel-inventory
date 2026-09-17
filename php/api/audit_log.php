<?php
/**
 * GET /api/audit_log.php                       -> latest 200 audit entries (super admin only)
 * GET /api/audit_log.php?entity_type=<type>     -> filter by entity type (user, item, supplier, ...)
 * GET /api/audit_log.php?action=<action>        -> filter by exact action (e.g. user.edit)
 * GET /api/audit_log.php?actor=<username>       -> filter by who did it
 * GET /api/audit_log.php?from=<date>&to=<date>  -> filter by date range (YYYY-MM-DD, inclusive)
 * GET /api/audit_log.php?limit=<n>              -> cap rows (default 200, max 1000)
 *
 * Read-only — entries are written by record_audit() in config.php, called
 * from users.php, inventory.php, suppliers.php, locations.php, vouchers.php,
 * purchase_orders.php, close_month.php, and purchase_requests.php.
 */
require __DIR__ . '/config.php';
block_department_user();
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('method not allowed', 405);
}

$pdo = get_pdo();

$where = [];
$params = [];

if (!empty($_GET['entity_type'])) {
    $where[] = 'entity_type = ?';
    $params[] = trim((string) $_GET['entity_type']);
}
if (!empty($_GET['action'])) {
    $where[] = 'action = ?';
    $params[] = trim((string) $_GET['action']);
}
if (!empty($_GET['actor'])) {
    $where[] = 'actor_username = ?';
    $params[] = trim((string) $_GET['actor']);
}
if (!empty($_GET['from'])) {
    $where[] = 'created_at >= ?';
    $params[] = trim((string) $_GET['from']) . ' 00:00:00';
}
if (!empty($_GET['to'])) {
    $where[] = 'created_at <= ?';
    $params[] = trim((string) $_GET['to']) . ' 23:59:59';
}

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 200;
if ($limit <= 0) {
    $limit = 200;
}
$limit = min($limit, 1000);

$sql = 'SELECT * FROM audit_log';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

echo json_encode(array_map(function (array $row): array {
    return [
        'id' => (int) $row['id'],
        'actor_username' => $row['actor_username'],
        'actor_name' => $row['actor_name'],
        'actor_role' => $row['actor_role'],
        'action' => $row['action'],
        'entity_type' => $row['entity_type'],
        'entity_id' => $row['entity_id'],
        'before' => $row['before_json'] !== null ? json_decode($row['before_json'], true) : null,
        'after' => $row['after_json'] !== null ? json_decode($row['after_json'], true) : null,
        'ip_address' => $row['ip_address'],
        'created_at' => $row['created_at'],
    ];
}, $stmt->fetchAll()));
