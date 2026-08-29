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

// Session timeouts, enforced by enforce_session_timeout() below. Declared
// here (before session_start()) since top-level `const` statements run in
// file order, unlike function declarations — enforce_session_timeout() is
// called immediately after starting the session, so these must exist first.
const SESSION_IDLE_TIMEOUT_SECONDS = 10 * 60;      // 10 minutes of inactivity
const SESSION_ABSOLUTE_TIMEOUT_SECONDS = 60 * 60;  // 1 hour max, even if active

if (session_status() !== PHP_SESSION_ACTIVE) {
    // Secure/HttpOnly/SameSite cookie flags. 'secure' only turns on over
    // HTTPS — forcing it on plain http:// would silently break local dev,
    // since browsers refuse to send secure cookies back over http.
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

enforce_session_timeout();

const DB_HOST = '127.0.0.1';
const DB_NAME = 'wayfarer_inventory';
const DB_USER = 'root';
const DB_PASSWORD = '';

// Python forecast microservice — kept off the public internet; only this
// PHP layer is expected to reach it.
const FORECAST_SERVICE_URL = 'http://127.0.0.1:5050';

// Python receipt-OCR microservice (ocr_service.py) — same pattern as the
// forecast service, on its own port. Used to suggest an amount and OR
// number when a receipt is uploaded; the user still reviews it before
// submitting, so this service being down is never a hard blocker.
const OCR_SERVICE_URL = 'http://127.0.0.1:5051';

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
// Passwords below are the same demo passwords as before (staff123 / manager123
// / admin123) — just stored as password_hash() output instead of plaintext,
// so nothing changes for the three demo accounts except how the hash is
// checked (password_verify() instead of a plain ===).
const AUTH_USERS = [
    'juan' => [
        'password' => '$2y$10$7pMcPfUMLtT6aWZ.ojuzu.moTtrpFV0TiiacECtIaFHOf0H/I464a',
        'name' => 'Juan Dela Cruz',
        'role' => 'staff',
    ],
    'maria' => [
        'password' => '$2y$10$x1iYBp20Zhvbq798nnUnAemwxW8A0CDA8TApsGkoH.bj0CZaan1P.',
        'name' => 'Maria Santos',
        'role' => 'manager',
    ],
    'admin' => [
        'password' => '$2y$10$aZHU4bDaT0CaqOOt3GVyu.Gk2EM7tYI7JaLuN12qR5S1vGBoSdcUq',
        'name' => 'System Administrator',
        'role' => 'super_admin',
    ],
];

// Login rate limiting (see login_attempts table + auth.php). Separate
// per-username and per-IP thresholds: the per-IP one is looser since a
// shared office connection can have several staff logging in at once.
const LOGIN_MAX_ATTEMPTS_PER_USER = 5;
const LOGIN_MAX_ATTEMPTS_PER_IP = 15;
const LOGIN_ATTEMPT_WINDOW_MINUTES = 10;
const LOGIN_LOCKOUT_MINUTES = 15;

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

function is_valid_person_name(string $name): bool
{
    $name = trim($name);
    if ($name === '') {
        return false;
    }

    return (bool) preg_match("/^[A-Za-z][A-Za-z\s.'-]*$/", $name);
}

const LIMIT_PR_ITEM = 100;
const LIMIT_ITEM_LABEL = 60;
const LIMIT_EQUIP_GROUP = 40;
const LIMIT_EQUIP_VARIANT = 60;
const LIMIT_UNIT = 15;
const LIMIT_PERSON_NAME = 50;
const LIMIT_NOTE = 200;
const LIMIT_SUPPLIER_CONTACT = 180;
const LIMIT_SUPPLIER_ADDRESS = 200;
const LIMIT_VENDOR_COMPANY = 80;
const LIMIT_VENDOR_PHONES = 120;
const LIMIT_VENDOR_PHONE_LINE = 25;
const LIMIT_VENDOR_EMAILS = 120;
const LIMIT_VENDOR_EMAIL_LINE = 80;
const LIMIT_VENDOR_ADDRESS = 200;
const LIMIT_LOCATION_NAME = 60;
const LIMIT_LOCATION_DESC = 200;
const LIMIT_VENDOR_CONTACT_LINES = 3;

function text_length(string $value): int
{
    return mb_strlen($value);
}

function parse_required_text(?string $value, int $maxLen, string $fieldLabel): string
{
    $value = trim($value ?? '');
    if ($value === '') {
        json_error("$fieldLabel is required");
    }
    if (text_length($value) > $maxLen) {
        json_error("$fieldLabel must be at most $maxLen characters");
    }

    return $value;
}

function parse_optional_text(?string $value, int $maxLen, string $fieldLabel): ?string
{
    $value = trim($value ?? '');
    if ($value === '') {
        return null;
    }
    if (text_length($value) > $maxLen) {
        json_error("$fieldLabel must be at most $maxLen characters");
    }

    return $value;
}

function parse_optional_note(?string $value, string $fieldLabel = 'Notes'): ?string
{
    return parse_optional_text($value, LIMIT_NOTE, $fieldLabel);
}

/** Strip formatting so OR 0001-052026-01845 matches 000105202601845. */
function normalize_receipt_number(?string $value): string
{
    $v = strtoupper(trim($value ?? ''));
    if ($v === '') {
        return '';
    }
    $v = preg_replace('/^(OR|O\.R\.|OFFICIAL RECEIPT)\s*[#:\-]?\s*/i', '', $v);

    return preg_replace('/[^A-Z0-9]/', '', $v);
}

/** Other POs that already use this OR number (excluding $excludePoId). */
function find_receipt_number_duplicates(PDO $pdo, string $receiptNumber, int $excludePoId = 0): array
{
    $needle = normalize_receipt_number($receiptNumber);
    if ($needle === '') {
        return [];
    }

    $stmt = $pdo->query(
        "SELECT id, po_code, receipt_number, receipt_uploaded_at
         FROM purchase_orders
         WHERE receipt_number IS NOT NULL AND TRIM(receipt_number) <> ''"
    );
    $matches = [];
    foreach ($stmt->fetchAll() as $row) {
        if ($excludePoId > 0 && (int) $row['id'] === $excludePoId) {
            continue;
        }
        if (normalize_receipt_number($row['receipt_number']) !== $needle) {
            continue;
        }
        $matches[] = [
            'id' => (int) $row['id'],
            'po_code' => $row['po_code'],
            'receipt_number' => $row['receipt_number'],
            'receipt_uploaded_at' => $row['receipt_uploaded_at'],
        ];
    }

    return $matches;
}

function split_contact_lines(?string $value): array
{
    if ($value === null || trim($value) === '') {
        return [];
    }
    $parts = [];
    foreach (preg_split('/[\r\n,]+/', $value) as $line) {
        $line = trim($line);
        if ($line !== '') {
            $parts[] = $line;
        }
    }

    return $parts;
}

function is_valid_phone_line(string $line): bool
{
    return (bool) preg_match('/^[\d\s()+.-]{7,25}$/', $line);
}

function is_valid_email_line(string $line): bool
{
    if (text_length($line) > LIMIT_VENDOR_EMAIL_LINE) {
        return false;
    }

    return (bool) filter_var($line, FILTER_VALIDATE_EMAIL);
}

function parse_vendor_phones(?string $value): ?string
{
    $lines = split_contact_lines($value);
    if ($lines === []) {
        return null;
    }
    if (count($lines) > LIMIT_VENDOR_CONTACT_LINES) {
        json_error('provide at most ' . LIMIT_VENDOR_CONTACT_LINES . ' phone numbers');
    }
    $normalized = [];
    foreach ($lines as $line) {
        if (text_length($line) > LIMIT_VENDOR_PHONE_LINE) {
            json_error('each phone number must be at most ' . LIMIT_VENDOR_PHONE_LINE . ' characters');
        }
        if (!is_valid_phone_line($line)) {
            json_error('invalid phone number — use digits and + - ( ) spaces only');
        }
        $normalized[] = $line;
    }
    $joined = implode("\n", $normalized);
    if (text_length($joined) > LIMIT_VENDOR_PHONES) {
        json_error('phone numbers must fit within ' . LIMIT_VENDOR_PHONES . ' characters total');
    }

    return $joined;
}

function parse_vendor_emails(?string $value): ?string
{
    $lines = split_contact_lines($value);
    if ($lines === []) {
        return null;
    }
    if (count($lines) > LIMIT_VENDOR_CONTACT_LINES) {
        json_error('provide at most ' . LIMIT_VENDOR_CONTACT_LINES . ' email addresses');
    }
    $normalized = [];
    foreach ($lines as $line) {
        if (!is_valid_email_line($line)) {
            json_error('invalid email address — use a format like sales@abc.com');
        }
        $normalized[] = $line;
    }
    $joined = implode("\n", $normalized);
    if (text_length($joined) > LIMIT_VENDOR_EMAILS) {
        json_error('email addresses must fit within ' . LIMIT_VENDOR_EMAILS . ' characters total');
    }

    return $joined;
}

/** Optional person name; null when blank. Rejects digits and other non-name input. */
function parse_optional_person_name(?string $value, string $fieldLabel = 'Issued to'): ?string
{
    $value = trim($value ?? '');
    if ($value === '') {
        return null;
    }
    if (text_length($value) > LIMIT_PERSON_NAME) {
        json_error("$fieldLabel must be at most " . LIMIT_PERSON_NAME . ' characters');
    }
    if (!is_valid_person_name($value)) {
        json_error("$fieldLabel must be a person's name (letters only — no numbers)");
    }

    return $value;
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

/**
 * Logs out a session that's gone idle too long or has lived past its
 * absolute lifetime, regardless of activity. Called once per request right
 * after session_start() — every other auth check (get_session_user(),
 * require_auth(), ...) then naturally sees "not logged in" once expired,
 * with no extra checks needed anywhere else in the codebase.
 */
function enforce_session_timeout(): void
{
    if (!isset($_SESSION['user'])) {
        return;
    }
    $now = time();
    $lastActivity = $_SESSION['last_activity'] ?? $now;
    $loginTime = $_SESSION['login_time'] ?? $now;

    if (($now - $lastActivity) > SESSION_IDLE_TIMEOUT_SECONDS
        || ($now - $loginTime) > SESSION_ABSOLUTE_TIMEOUT_SECONDS) {
        logout_user();
        return;
    }
    $_SESSION['last_activity'] = $now;
}

function authenticate_user(string $username, string $password): ?array
{
    $key = strtolower(trim($username));
    if (!isset(AUTH_USERS[$key]) || !password_verify($password, AUTH_USERS[$key]['password'])) {
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
    // Regenerate the session ID on every successful login so a session ID
    // seen (or fixed) before authentication can't be reused afterward.
    session_regenerate_id(true);
    $_SESSION['user'] = $user;
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();
}

function logout_user(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function get_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/**
 * Records one login attempt (success or failure) for rate limiting. Only
 * failures actually count toward a lockout (see login_lockout_seconds_remaining()),
 * but successes are logged too so the table is a full, honest audit trail.
 */
function record_login_attempt(string $username, string $ip, bool $success): void
{
    $pdo = get_pdo();
    $pdo->prepare('INSERT INTO login_attempts (username, ip_address, success) VALUES (?, ?, ?)')
        ->execute([$username, $ip, $success ? 1 : 0]);

    // Cheap, occasional cleanup instead of a separate cron job — fine for
    // a small internal tool's login table.
    if (random_int(1, 200) === 1) {
        $pdo->exec('DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)');
    }
}

/**
 * Returns how many seconds remain on an active lockout for this username
 * or IP, or 0 if neither is currently locked out. Checked before the
 * password is even verified, so a locked-out attacker can't keep guessing.
 *
 * All time math (the attempt window and the remaining-lockout time) is done
 * in SQL with NOW() rather than PHP's time()/date(), so a mismatch between
 * PHP's configured timezone and MySQL's server timezone can't throw the
 * lockout window off — everything is compared against MySQL's own clock.
 */
function login_lockout_seconds_remaining(string $username, string $ip): int
{
    $pdo = get_pdo();
    $windowMinutes = LOGIN_ATTEMPT_WINDOW_MINUTES;
    $lockoutMinutes = LOGIN_LOCKOUT_MINUTES;

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS cnt,
                GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), MAX(created_at) + INTERVAL {$lockoutMinutes} MINUTE)) AS remaining
         FROM login_attempts
         WHERE username = ? AND success = 0 AND created_at >= NOW() - INTERVAL {$windowMinutes} MINUTE"
    );
    $stmt->execute([$username]);
    $userRow = $stmt->fetch();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS cnt,
                GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), MAX(created_at) + INTERVAL {$lockoutMinutes} MINUTE)) AS remaining
         FROM login_attempts
         WHERE ip_address = ? AND success = 0 AND created_at >= NOW() - INTERVAL {$windowMinutes} MINUTE"
    );
    $stmt->execute([$ip]);
    $ipRow = $stmt->fetch();

    $remaining = 0;
    if ($userRow && (int) $userRow['cnt'] >= LOGIN_MAX_ATTEMPTS_PER_USER) {
        $remaining = max($remaining, (int) $userRow['remaining']);
    }
    if ($ipRow && (int) $ipRow['cnt'] >= LOGIN_MAX_ATTEMPTS_PER_IP) {
        $remaining = max($remaining, (int) $ipRow['remaining']);
    }
    return $remaining;
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

/** Variant label for equipment; plain label for consumables. */
function equipment_item_display_label(array $row): string
{
    if (($row['item_type'] ?? '') !== 'equipment') {
        return (string) ($row['label'] ?? '');
    }
    $group = trim((string) ($row['equipment_group'] ?? ''));
    $variant = trim((string) ($row['label'] ?? ''));
    if ($group === '') {
        return $variant;
    }
    if ($variant === '' || strcasecmp($variant, 'Standard') === 0) {
        return $group;
    }
    return "$group — $variant";
}

function items_have_equipment_group(PDO $pdo): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $stmt = $pdo->query("SHOW COLUMNS FROM items LIKE 'equipment_group'");
    $cached = (bool) $stmt->fetch();
    return $cached;
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
    ?string $referenceCode = null,
    ?string $returnCondition = null
): void {
    if ($qty <= 0) {
        return;
    }
    $item = get_item_or_404($itemKey);
    if (($item['item_type'] ?? '') !== 'equipment') {
        return;
    }
    $allowed = ['issue_from_storage', 'receive_to_storage', 'deploy_from_purchase', 'retired', 'return_to_storage'];
    if (!in_array($movementType, $allowed, true)) {
        return;
    }

    $code = next_code('EM', 'equipment_movements', 'movement_code');
    $pdo->prepare(
        'INSERT INTO equipment_movements
         (movement_code, item_key, qty, movement_type, department, location_id, issued_to, notes,
          return_condition, recorded_by, reference_type, reference_id, reference_code, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $code,
        $itemKey,
        $qty,
        $movementType,
        $department,
        $locationId,
        $issuedTo,
        $notes,
        $returnCondition,
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
const RETIREMENT_REASONS = ['broken', 'lost', 'expired', 'damaged', 'other'];
const RETURN_CONDITIONS = ['good', 'damaged', 'broken'];

function return_condition_label(string $condition): string
{
    $labels = [
        'good' => 'Good',
        'damaged' => 'Damaged',
        'broken' => 'Broken',
    ];

    return $labels[$condition] ?? $condition;
}

/**
 * Record equipment returned from a department. Good/damaged units re-enter storage;
 * broken units are written off without increasing storage qty.
 *
 * @return array Equipment movement row (same shape as equipment_movements GET).
 */
function apply_equipment_return(PDO $pdo, array $body, array $user): array
{
    $itemKey = trim($body['item_key'] ?? '');
    $department = trim($body['department'] ?? '');
    $qty = isset($body['qty']) ? (float) $body['qty'] : 0;
    $condition = strtolower(trim($body['condition'] ?? ''));
    $notes = parse_optional_note($body['notes'] ?? null);
    $returnedBy = parse_optional_person_name($body['returned_by'] ?? null, 'Returned by');

    if ($itemKey === '' || $department === '' || $qty <= 0) {
        json_error('item_key, department, and qty greater than 0 are required');
    }
    if (!is_valid_department($department)) {
        json_error('invalid department — choose one of the official departments');
    }
    if (!in_array($condition, RETURN_CONDITIONS, true)) {
        json_error('condition must be one of: good, damaged, broken');
    }
    if ($condition === 'broken' && (ROLE_RANK[$user['role'] ?? ''] ?? 0) < ROLE_RANK['manager']) {
        json_error('broken returns require a manager — ask a manager to record this write-off', 403);
    }

    $item = get_item_or_404($itemKey);
    if ((int) ($item['active'] ?? 1) !== 1) {
        json_error('cannot record a return for an inactive item');
    }
    if (($item['item_type'] ?? '') !== 'equipment') {
        json_error('returns only apply to equipment items');
    }

    $stmt = $pdo->prepare('SELECT qty FROM equipment_deployments WHERE item_key = ? AND department = ?');
    $stmt->execute([$itemKey, $department]);
    $deployed = (float) $stmt->fetchColumn();
    if ($deployed <= 0) {
        json_error("no units deployed to $department", 409);
    }
    if ($qty > $deployed) {
        json_error("only $deployed {$item['unit']} at $department — cannot return $qty", 409);
    }

    $recordedBy = $user['name'] ?? 'System';
    $retirementId = null;
    $retirementCode = null;
    $locationId = !empty($item['location_id']) ? (int) $item['location_id'] : null;

    $pdo->beginTransaction();
    try {
        subtract_equipment_deployment($pdo, $itemKey, $department, $qty);

        if ($condition === 'broken') {
            $retirementCode = next_code('RET', 'inventory_retirements', 'retirement_code');
            $pdo->prepare(
                'INSERT INTO inventory_retirements
                 (retirement_code, item_key, qty, source, department, reason, notes, recorded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $retirementCode,
                $itemKey,
                $qty,
                'department',
                $department,
                'broken',
                $notes,
                $recordedBy,
            ]);
            $retirementId = (int) $pdo->lastInsertId();
            $locationId = null;
        } else {
            $pdo->prepare('UPDATE items SET current_qty = current_qty + ? WHERE item_key = ?')
                ->execute([$qty, $itemKey]);
        }

        log_equipment_movement(
            $pdo,
            $itemKey,
            $qty,
            'return_to_storage',
            $recordedBy,
            $department,
            $locationId,
            $returnedBy,
            $notes,
            $retirementId ? 'inventory_retirement' : null,
            $retirementId,
            $retirementCode,
            $condition
        );

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        json_error('failed to record return: ' . $e->getMessage(), 500);
    }

    $extra = items_have_equipment_group($pdo) ? 'i.equipment_group, i.item_type,' : 'i.item_type,';
    $movement = $pdo->query(
        'SELECT em.*, i.label, ' . $extra . ' i.unit, l.name AS location_name
         FROM equipment_movements em
         JOIN items i ON i.item_key = em.item_key
         LEFT JOIN locations l ON l.id = em.location_id
         WHERE em.item_key = ' . $pdo->quote($itemKey) . '
         ORDER BY em.id DESC LIMIT 1'
    )->fetch();

    return [
        'id' => (int) $movement['id'],
        'movement_code' => $movement['movement_code'],
        'item_key' => $movement['item_key'],
        'item_label' => equipment_item_display_label($movement),
        'unit' => $movement['unit'],
        'qty' => (float) $movement['qty'],
        'movement_type' => $movement['movement_type'],
        'department' => $movement['department'],
        'location_id' => $movement['location_id'] !== null ? (int) $movement['location_id'] : null,
        'location_name' => $movement['location_name'],
        'issued_to' => $movement['issued_to'],
        'notes' => $movement['notes'],
        'condition' => $movement['return_condition'],
        'recorded_by' => $movement['recorded_by'],
        'reference_type' => $movement['reference_type'],
        'reference_id' => $movement['reference_id'] !== null ? (int) $movement['reference_id'] : null,
        'reference_code' => $movement['reference_code'],
        'status' => $movement['status'],
        'voided_reason' => $movement['voided_reason'],
        'created_at' => $movement['created_at'],
        'voided_at' => $movement['voided_at'],
    ];
}

function retirement_reason_label(string $reason): string
{
    $labels = [
        'broken' => 'Broken',
        'lost' => 'Lost',
        'expired' => 'Expired',
        'damaged' => 'Damaged',
        'other' => 'Other',
    ];

    return $labels[$reason] ?? $reason;
}

function format_retirement_note(string $reason, ?string $notes): string
{
    $label = retirement_reason_label($reason);
    return $notes !== null && $notes !== '' ? "$label — $notes" : $label;
}

/**
 * Remove units from storage or a department deployment and record the audit trail.
 */
function apply_inventory_retirement(PDO $pdo, array $body, array $user): array
{
    $itemKey = trim($body['item_key'] ?? '');
    $qty = isset($body['qty']) ? (float) $body['qty'] : 0;
    $source = $body['source'] ?? 'storage';
    $department = trim($body['department'] ?? '') ?: null;
    $reason = strtolower(trim($body['reason'] ?? ''));
    $notes = parse_optional_note($body['notes'] ?? null);

    if ($itemKey === '' || $qty <= 0) {
        json_error('item_key and qty greater than 0 are required');
    }
    if (!in_array($reason, RETIREMENT_REASONS, true)) {
        json_error('reason must be one of: ' . implode(', ', RETIREMENT_REASONS));
    }
    if (!in_array($source, ['storage', 'department'], true)) {
        json_error("source must be 'storage' or 'department'");
    }

    $item = get_item_or_404($itemKey);
    if ((int) ($item['active'] ?? 1) !== 1) {
        json_error('cannot retire units from an inactive item');
    }

    $itemType = $item['item_type'] ?? 'consumable';
    if ($itemType === 'consumable') {
        if ($source !== 'storage') {
            json_error('consumables can only be retired from storage');
        }
        if ($qty > (float) $item['current_qty']) {
            json_error(
                "only {$item['current_qty']} {$item['unit']} on hand — cannot retire $qty",
                409
            );
        }
    } elseif ($source === 'storage') {
        if ($qty > (float) $item['current_qty']) {
            json_error(
                "only {$item['current_qty']} {$item['unit']} in storage — cannot retire $qty",
                409
            );
        }
    } else {
        if ($department === null || !is_valid_department($department)) {
            json_error('department is required when retiring deployed equipment');
        }
        $stmt = $pdo->prepare('SELECT qty FROM equipment_deployments WHERE item_key = ? AND department = ?');
        $stmt->execute([$itemKey, $department]);
        $deployed = (float) $stmt->fetchColumn();
        if ($deployed <= 0) {
            json_error("no units deployed to $department", 409);
        }
        if ($qty > $deployed) {
            json_error("only $deployed {$item['unit']} at $department — cannot retire $qty", 409);
        }
    }

    $code = next_code('RET', 'inventory_retirements', 'retirement_code');
    $recordedBy = $user['name'] ?? 'System';

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO inventory_retirements
             (retirement_code, item_key, qty, source, department, reason, notes, recorded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $code,
            $itemKey,
            $qty,
            $source,
            $source === 'department' ? $department : null,
            $reason,
            $notes,
            $recordedBy,
        ]);
        $retirementId = (int) $pdo->lastInsertId();

        if ($itemType === 'consumable') {
            $pdo->prepare('UPDATE items SET current_qty = current_qty - ? WHERE item_key = ?')
                ->execute([$qty, $itemKey]);
        } elseif ($source === 'storage') {
            $pdo->prepare('UPDATE items SET current_qty = current_qty - ? WHERE item_key = ?')
                ->execute([$qty, $itemKey]);
            $locationId = !empty($item['location_id']) ? (int) $item['location_id'] : null;
            log_equipment_movement(
                $pdo,
                $itemKey,
                $qty,
                'retired',
                $recordedBy,
                null,
                $locationId,
                null,
                format_retirement_note($reason, $notes),
                'inventory_retirement',
                $retirementId,
                $code
            );
        } else {
            subtract_equipment_deployment($pdo, $itemKey, $department, $qty);
            log_equipment_movement(
                $pdo,
                $itemKey,
                $qty,
                'retired',
                $recordedBy,
                $department,
                null,
                null,
                format_retirement_note($reason, $notes),
                'inventory_retirement',
                $retirementId,
                $code
            );
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        json_error('failed to retire: ' . $e->getMessage(), 500);
    }

    $stmt = $pdo->prepare(
        'SELECT ir.*, i.label, i.unit, i.item_type
         FROM inventory_retirements ir
         JOIN items i ON i.item_key = ir.item_key
         WHERE ir.id = ?'
    );
    $stmt->execute([$retirementId]);

    return format_inventory_retirement($stmt->fetch());
}

function format_inventory_retirement(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'retirement_code' => $row['retirement_code'],
        'item_key' => $row['item_key'],
        'item_label' => $row['label'],
        'item_type' => $row['item_type'],
        'unit' => $row['unit'],
        'qty' => (float) $row['qty'],
        'source' => $row['source'],
        'department' => $row['department'],
        'reason' => $row['reason'],
        'reason_label' => retirement_reason_label($row['reason']),
        'notes' => $row['notes'],
        'recorded_by' => $row['recorded_by'],
        'created_at' => $row['created_at'],
    ];
}

function normalize_purchase_reason(?string $reason): ?string
{
    if ($reason === null || $reason === '') {
        return null;
    }
    $reason = strtolower(trim($reason));
    return in_array($reason, PURCHASE_REASONS, true) ? $reason : null;
}
