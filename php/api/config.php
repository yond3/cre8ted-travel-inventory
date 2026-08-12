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
