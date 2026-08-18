<?php
/**
 * Shared MySQL connection + small helpers used by every endpoint in this
 * folder. Same database the Python forecast service reads from, so PHP
 * writes (usage, stock, close-month) show up in the very next forecast.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

const DB_HOST = '127.0.0.1';
const DB_NAME = 'wayfarer_inventory';
const DB_USER = 'root';
const DB_PASSWORD = '';

// Python forecast microservice — kept off the public internet; only this
// PHP layer is expected to reach it.
const FORECAST_SERVICE_URL = 'http://127.0.0.1:5050';

// Purchase order receipt uploads (proof of purchase, required before an
// order can be marked Received). Files are stored outside web-served paths
// in spirit — access is only via receipts.php, which checks login first.
const RECEIPT_UPLOAD_DIR = __DIR__ . '/../uploads/receipts/';
const RECEIPT_MAX_BYTES = 5 * 1024 * 1024;
const RECEIPT_ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
const RECEIPT_ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

/**
 * Financial Management integration (see finance_client.php).
 *
 * 'stub' — no real Finance API needed. Disbursement requests auto-approve
 *          and expense submissions auto-record immediately; every call is
 *          still written to finance_integration_log so the flow is visible
 *          and demoable end-to-end.
 * 'live' — POSTs to FINANCE_API_BASE_URL with FINANCE_API_KEY. Switch to
 *          this once the Finance team shares real (or staging) endpoints —
 *          no other code changes are needed.
 */
const FINANCE_MODE = 'stub';
const FINANCE_API_BASE_URL = 'http://127.0.0.1:9090/finance/api';
const FINANCE_API_KEY = '';
// Shared secret Finance must send back as X-Finance-Secret on webhooks to
// finance_integration.php. Change before going live.
const FINANCE_WEBHOOK_SECRET = 'dev-finance-webhook-secret';
// Used to build the receipt file URL sent to Finance in expense payloads.
const APP_BASE_URL = 'http://127.0.0.1:8000';

/**
 * Temporary RBAC — demo accounts only. Lead programmer will replace this
 * with the central super-admin login later; when that happens, swap
 * authenticate_user()/get_session_user() to read from his auth source and
 * every require_role() call below keeps working unchanged.
 */
const AUTH_USERS = [
    'juan' => [
        'password' => 'staff123',
        'name' => 'Juan Dela Cruz',
        'role' => 'staff',
    ],
    'maria' => [
        'password' => 'manager123',
        'name' => 'Maria Santos',
        'role' => 'manager',
    ],
    'admin' => [
        'password' => 'admin123',
        'name' => 'System Administrator',
        'role' => 'super_admin',
    ],
];

const ROLE_RANK = [
    'staff' => 1,
    'manager' => 2,
    'super_admin' => 3,
];

/** Official departments for stock requests and issue checkout logs. */
const DEPARTMENTS = [
    'Human resource management',
    'Financial management',
    'Fleet & Transportation management',
    'Facilities & Administration management',
    'Tour Operations',
    'Back-office',
];

function is_valid_department(string $department): bool
{
    return in_array($department, DEPARTMENTS, true);
}

function public_user(array $user): array
{
    return [
        'username' => $user['username'],
        'name' => $user['name'],
        'role' => $user['role'],
    ];
}

function get_session_user(): ?array
{
    return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
}

function authenticate_user(string $username, string $password): ?array
{
    $key = strtolower(trim($username));
    if (!isset(AUTH_USERS[$key]) || AUTH_USERS[$key]['password'] !== $password) {
        return null;
    }
    return [
        'username' => $key,
        'name' => AUTH_USERS[$key]['name'],
        'role' => AUTH_USERS[$key]['role'],
    ];
}

function login_user(array $user): void
{
    $_SESSION['user'] = $user;
}

function logout_user(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function require_auth(): array
{
    $user = get_session_user();
    if (!$user) {
        json_error('authentication required', 401);
    }
    return $user;
}

function require_role(string ...$roles): array
{
    $user = require_auth();
    if (!in_array($user['role'], $roles, true)) {
        json_error('insufficient permissions', 403);
    }
    return $user;
}

function require_staff_or_above(): array
{
    return require_role('staff', 'manager', 'super_admin');
}

function require_manager_or_above(): array
{
    return require_role('manager', 'super_admin');
}

function require_super_admin(): array
{
    return require_role('super_admin');
}

function get_pdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function json_error(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

function get_item_or_404(string $itemKey): array
{
    $stmt = get_pdo()->prepare('SELECT * FROM items WHERE item_key = ?');
    $stmt->execute([$itemKey]);
    $item = $stmt->fetch();
    if (!$item) {
        json_error("unknown item '$itemKey'", 404);
    }
    return $item;
}

function read_json_body(): array
{
    $body = json_decode(file_get_contents('php://input'), true);
    return is_array($body) ? $body : [];
}

/**
 * Generates the next sequential code for a table+column, formatted like
 * PR-2026-015 / PO-2026-010. Scoped per calendar year so numbering restarts
 * each year, matching how the original prototype's demo IDs looked.
 */
function next_code(string $prefix, string $table, string $column): string
{
    $year = date('Y');
    $like = "$prefix-$year-%";
    $stmt = get_pdo()->prepare("SELECT $column FROM $table WHERE $column LIKE ? ORDER BY $column DESC LIMIT 1");
    $stmt->execute([$like]);
    $last = $stmt->fetchColumn();
    $next = 1;
    if ($last) {
        $parts = explode('-', $last);
        $next = ((int) end($parts)) + 1;
    }
    return sprintf('%s-%s-%03d', $prefix, $year, $next);
}

/** One document row per PR or PO — status updates in place as the flow moves. */
function create_document(string $type, string $refCode, string $status): string
{
    $code = next_code('DOC', 'documents', 'doc_code');
    $stmt = get_pdo()->prepare(
        'INSERT INTO documents (doc_code, doc_type, ref_code, status) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$code, $type, $refCode, $status]);
    return $code;
}

function update_document(string $type, string $refCode, string $status): void
{
    $stmt = get_pdo()->prepare(
        'UPDATE documents SET status = ? WHERE doc_type = ? AND ref_code = ?'
    );
    $stmt->execute([$status, $type, $refCode]);
    if ($stmt->rowCount() === 0) {
        create_document($type, $refCode, $status);
    }
}

function slugify(string $label): string
{
    $slug = strtolower(trim($label));
    $slug = preg_replace('/[^a-z0-9]+/', '', $slug);
    return $slug !== '' ? $slug : 'item' . time();
}

/**
 * Department deployment counts for one equipment catalog item.
 *
 * @return list<array{department: string, qty: float}>
 */
function fetch_equipment_deployments(PDO $pdo, string $itemKey): array
{
    $stmt = $pdo->prepare(
        'SELECT department, qty FROM equipment_deployments WHERE item_key = ? AND qty > 0 ORDER BY department'
    );
    $stmt->execute([$itemKey]);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'department' => $row['department'],
            'qty' => (float) $row['qty'],
        ];
    }
    return $rows;
}

function deployed_equipment_qty(PDO $pdo, string $itemKey): float
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(qty), 0) FROM equipment_deployments WHERE item_key = ?');
    $stmt->execute([$itemKey]);
    return (float) $stmt->fetchColumn();
}

function add_equipment_deployment(PDO $pdo, string $itemKey, string $department, float $qty): void
{
    if ($qty <= 0) {
        return;
    }
    $pdo->prepare(
        'INSERT INTO equipment_deployments (item_key, department, qty) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)'
    )->execute([$itemKey, $department, $qty]);
}

function subtract_equipment_deployment(PDO $pdo, string $itemKey, string $department, float $qty): void
{
    if ($qty <= 0) {
        return;
    }
    $stmt = $pdo->prepare('SELECT qty FROM equipment_deployments WHERE item_key = ? AND department = ?');
    $stmt->execute([$itemKey, $department]);
    $current = (float) $stmt->fetchColumn();
    if ($current <= 0) {
        return;
    }
    $newQty = $current - $qty;
    if ($newQty <= 0) {
        $pdo->prepare('DELETE FROM equipment_deployments WHERE item_key = ? AND department = ?')
            ->execute([$itemKey, $department]);
    } else {
        $pdo->prepare('UPDATE equipment_deployments SET qty = ? WHERE item_key = ? AND department = ?')
            ->execute([$newQty, $itemKey, $department]);
    }
}

/** Active catalog row for an equipment label (one row per product). */
function find_equipment_catalog_row(PDO $pdo, string $label): ?array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM items
         WHERE item_type = 'equipment' AND label = ? AND active = 1
         AND (assigned_department IS NULL OR assigned_department = '')
         ORDER BY item_key LIMIT 1"
    );
    $stmt->execute([$label]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function upsert_equipment_deployment(PDO $pdo, string $itemKey, string $department, float $qty): void
{
    add_equipment_deployment($pdo, $itemKey, $department, $qty);
}

/**
 * After issuing equipment from storage, increment the department deployment ledger.
 */
function apply_equipment_checkout(PDO $pdo, string $itemKey, string $department, float $qtyIssued): void
{
    $item = get_item_or_404($itemKey);
    if (($item['item_type'] ?? '') !== 'equipment') {
        return;
    }
    if (!is_valid_department($department)) {
        return;
    }
    if ($qtyIssued <= 0) {
        return;
    }

    add_equipment_deployment($pdo, $itemKey, $department, $qtyIssued);

    // Legacy rows may still carry assigned_department — keep catalog row storage-only.
    if (!empty($item['assigned_department'])) {
        $pdo->prepare('UPDATE items SET assigned_department = NULL WHERE item_key = ?')
            ->execute([$itemKey]);
    }
}

/** Undo deployment when a void returns units to storage. */
function reverse_equipment_checkout(PDO $pdo, string $itemKey, string $department, float $qty): void
{
    $item = get_item_or_404($itemKey);
    if (($item['item_type'] ?? '') !== 'equipment') {
        return;
    }
    if ($qty <= 0) {
        return;
    }

    subtract_equipment_deployment($pdo, $itemKey, $department, $qty);

    if (!empty($item['assigned_department'])) {
        $pdo->prepare('UPDATE items SET assigned_department = NULL WHERE item_key = ?')
            ->execute([$itemKey]);
    }
}

function get_default_storage_location_id(PDO $pdo): ?int
{
    $id = $pdo->query(
        "SELECT id FROM locations WHERE active = 1 AND location_type = 'storage' ORDER BY name LIMIT 1"
    )->fetchColumn();

    return $id !== false ? (int) $id : null;
}

/** Ensure catalog item has a storage location; returns item_key (never creates duplicate product rows). */
function ensure_equipment_storage_item_key(PDO $pdo, array $catalogItem, ?int $locationId): string
{
    $itemKey = $catalogItem['item_key'];
    $loc = $locationId
        ?: (!empty($catalogItem['location_id']) ? (int) $catalogItem['location_id'] : get_default_storage_location_id($pdo));
    if (!$loc) {
        json_error('no storage location configured — add a cabinet or shelf first', 409);
    }
    if (empty($catalogItem['location_id']) || (int) $catalogItem['location_id'] !== $loc) {
        $pdo->prepare('UPDATE items SET location_id = ?, assigned_department = NULL WHERE item_key = ?')
            ->execute([$loc, $itemKey]);
    }
    return $itemKey;
}

/**
 * Receive purchased equipment: into storage (default) or deploy straight to the requesting department.
 *
 * @return array{qty_added: float, new_stock: ?float, deployed_to: ?string, received_to: string, storage_item_key: ?string}
 */
function receive_purchased_equipment(PDO $pdo, array $orderRow, array $body): array
{
    $qty = (float) $orderRow['qty'];
    $catalogItem = get_item_or_404($orderRow['item_key']);
    $department = trim($orderRow['department'] ?? '');
    $deploy = !empty($body['deploy_to_department'])
        && $department !== ''
        && is_valid_department($department);

    if ($deploy) {
        upsert_equipment_deployment($pdo, $catalogItem['item_key'], $department, $qty);
        return [
            'qty_added' => $qty,
            'new_stock' => null,
            'deployed_to' => $department,
            'received_to' => 'department',
            'storage_item_key' => null,
        ];
    }

    $locationId = isset($body['storage_location_id'])
        && $body['storage_location_id'] !== ''
        && $body['storage_location_id'] !== null
        ? (int) $body['storage_location_id']
        : null;

    $storageKey = ensure_equipment_storage_item_key($pdo, $catalogItem, $locationId);
    $pdo->prepare('UPDATE items SET current_qty = current_qty + ? WHERE item_key = ?')
        ->execute([$qty, $storageKey]);

    $stmt = $pdo->prepare('SELECT current_qty, location_id FROM items WHERE item_key = ?');
    $stmt->execute([$storageKey]);
    $after = $stmt->fetch();

    return [
        'qty_added' => $qty,
        'new_stock' => (float) $after['current_qty'],
        'deployed_to' => null,
        'received_to' => 'storage',
        'storage_item_key' => $storageKey,
        'storage_location_id' => $after['location_id'] !== null ? (int) $after['location_id'] : $loc,
    ];
}

/**
 * Append an equipment movement audit row (no-op for non-equipment items).
 */
function log_equipment_movement(
    PDO $pdo,
    string $itemKey,
    float $qty,
    string $movementType,
    string $recordedBy,
    ?string $department = null,
    ?int $locationId = null,
    ?string $issuedTo = null,
    ?string $notes = null,
    ?string $referenceType = null,
    ?int $referenceId = null,
    ?string $referenceCode = null
): void {
    if ($qty <= 0) {
        return;
    }
    $item = get_item_or_404($itemKey);
    if (($item['item_type'] ?? '') !== 'equipment') {
        return;
    }
    $allowed = ['issue_from_storage', 'receive_to_storage', 'deploy_from_purchase'];
    if (!in_array($movementType, $allowed, true)) {
        return;
    }

    $code = next_code('EM', 'equipment_movements', 'movement_code');
    $pdo->prepare(
        'INSERT INTO equipment_movements
         (movement_code, item_key, qty, movement_type, department, location_id, issued_to, notes,
          recorded_by, reference_type, reference_id, reference_code, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $code,
        $itemKey,
        $qty,
        $movementType,
        $department,
        $locationId,
        $issuedTo,
        $notes,
        $recordedBy,
        $referenceType,
        $referenceId,
        $referenceCode,
        'Active',
    ]);
}

/** Mark movement rows void when a linked stock issue is reversed. */
function void_equipment_movements_for_reference(
    PDO $pdo,
    string $referenceType,
    int $referenceId,
    ?string $reason = null
): void {
    $pdo->prepare(
        "UPDATE equipment_movements
         SET status = 'Voided', voided_reason = ?, voided_at = NOW()
         WHERE reference_type = ? AND reference_id = ? AND status = 'Active'"
    )->execute([$reason, $referenceType, $referenceId]);
}

const PURCHASE_REASONS = ['replacement', 'new_need', 'other', 'stock_up'];

function normalize_purchase_reason(?string $reason): ?string
{
    if ($reason === null || $reason === '') {
        return null;
    }
    $reason = strtolower(trim($reason));
    return in_array($reason, PURCHASE_REASONS, true) ? $reason : null;
}
