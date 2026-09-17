<?php
/**
 * GET  /api/users.php                    -> list all accounts (super admin only)
 * POST /api/users.php                     body: { username, password, name, role, department? }
 *      -> create a new account (super admin only)
 * PUT  /api/users.php?id=me               body: { action: 'change_password', current_password, new_password }
 *      -> any authenticated user changes their own password (self-service)
 * PUT  /api/users.php?id=<id>             body: { name?, role?, department?, active? } (super admin only)
 * PUT  /api/users.php?id=<id>             body: { action: 'set_password', password } (super admin only)
 *      -> super admin resets someone else's password
 *
 * Accounts are disabled (active = 0), never deleted, so history (documents,
 * audit log, purchase requests, etc. all store the person's name as text)
 * stays intact. A super admin can never deactivate/demote/disable the last
 * remaining active super_admin account — that would lock everyone out of
 * account management.
 */
require __DIR__ . '/config.php';
block_department_user();

$pdo = get_pdo();
$method = $_SERVER['REQUEST_METHOD'];

function format_user(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'username' => $row['username'],
        'name' => $row['name'],
        'role' => $row['role'],
        'department' => $row['department'],
        'active' => (int) ($row['active'] ?? 1) === 1,
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}

function fetch_user_or_404(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        json_error('unknown user', 404);
    }
    return $row;
}

function count_other_active_super_admins(PDO $pdo, int $excludeId): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM users WHERE role = 'super_admin' AND active = 1 AND id != ?"
    );
    $stmt->execute([$excludeId]);
    return (int) $stmt->fetchColumn();
}

function validate_role(string $role): void
{
    if (!array_key_exists($role, ROLE_RANK)) {
        json_error('role must be one of: ' . implode(', ', array_keys(ROLE_RANK)));
    }
}

function validate_password(string $password): void
{
    if (strlen($password) < MIN_PASSWORD_LENGTH) {
        json_error('password must be at least ' . MIN_PASSWORD_LENGTH . ' characters');
    }
}

if ($method === 'GET') {
    require_super_admin();
    $rows = $pdo->query('SELECT * FROM users ORDER BY active DESC, role DESC, username')->fetchAll();
    echo json_encode(array_map('format_user', $rows));
    exit;
}

if ($method === 'POST') {
    require_super_admin();
    $body = read_json_body();

    $username = strtolower(trim($body['username'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    $name = trim($body['name'] ?? '');
    $role = $body['role'] ?? '';
    $department = trim($body['department'] ?? '') ?: null;

    if (!is_valid_username($username)) {
        json_error('username must be 3-30 characters, lowercase letters/numbers/underscore, starting with a letter');
    }
    validate_password($password);
    if (!is_valid_person_name($name) || text_length($name) > LIMIT_PERSON_NAME) {
        json_error("name must be a person's or department's name (letters only), at most " . LIMIT_PERSON_NAME . ' characters');
    }
    validate_role($role);
    if ($role === 'department') {
        if ($department === null || !is_valid_department($department)) {
            json_error('department is required (and must be an official department) for department accounts');
        }
    } else {
        $department = null;
    }

    $exists = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
    $exists->execute([$username]);
    if ($exists->fetchColumn()) {
        json_error('that username is already taken', 409);
    }

    $actor = require_super_admin();
    $stmt = $pdo->prepare(
        'INSERT INTO users (username, password_hash, name, role, department, created_by)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $username,
        password_hash($password, PASSWORD_BCRYPT),
        $name,
        $role,
        $department,
        $actor['username'],
    ]);
    $id = (int) $pdo->lastInsertId();
    $row = fetch_user_or_404($pdo, $id);

    record_audit('user.create', 'user', $username, null, format_user($row));

    echo json_encode(format_user($row));
    exit;
}

if ($method === 'PUT') {
    $idParam = $_GET['id'] ?? '';
    if ($idParam === '') {
        json_error('missing required query param: id');
    }
    $body = read_json_body();
    $action = $body['action'] ?? null;

    // Self-service password change — any authenticated user, own account only.
    if ($idParam === 'me') {
        $actor = require_auth();
        if ($action !== 'change_password') {
            json_error("action must be 'change_password' when id=me");
        }
        $current = (string) ($body['current_password'] ?? '');
        $new = (string) ($body['new_password'] ?? '');
        validate_password($new);

        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? AND active = 1');
        $stmt->execute([$actor['username']]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($current, $row['password_hash'])) {
            json_error('current password is incorrect', 403);
        }
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_BCRYPT), (int) $row['id']]);

        record_audit('user.change_own_password', 'user', $actor['username']);

        echo json_encode(['status' => 'ok']);
        exit;
    }

    require_super_admin();
    $id = (int) $idParam;
    if (!$id) {
        json_error('missing required query param: id');
    }
    $row = fetch_user_or_404($pdo, $id);
    $before = format_user($row);

    if ($action === 'set_password') {
        $password = (string) ($body['password'] ?? '');
        validate_password($password);
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_BCRYPT), $id]);
        record_audit('user.set_password', 'user', $row['username']);
        echo json_encode(format_user(fetch_user_or_404($pdo, $id)));
        exit;
    }

    $fields = [];
    $values = [];

    if (array_key_exists('name', $body)) {
        $name = trim($body['name']);
        if (!is_valid_person_name($name) || text_length($name) > LIMIT_PERSON_NAME) {
            json_error("name must be a person's or department's name (letters only), at most " . LIMIT_PERSON_NAME . ' characters');
        }
        $fields[] = 'name = ?';
        $values[] = $name;
    }

    $newRole = $body['role'] ?? $row['role'];
    $newDepartment = array_key_exists('department', $body) ? (trim((string) $body['department']) ?: null) : $row['department'];
    if (array_key_exists('role', $body)) {
        validate_role($newRole);
        $fields[] = 'role = ?';
        $values[] = $newRole;
    }
    if ($newRole === 'department') {
        if ($newDepartment === null || !is_valid_department($newDepartment)) {
            json_error('department is required (and must be an official department) for department accounts');
        }
    } else {
        $newDepartment = null;
    }
    if (array_key_exists('department', $body) || array_key_exists('role', $body)) {
        $fields[] = 'department = ?';
        $values[] = $newDepartment;
    }

    $newActive = (int) $row['active'];
    if (array_key_exists('active', $body)) {
        $newActive = filter_var($body['active'], FILTER_VALIDATE_BOOLEAN) || (int) $body['active'] === 1 ? 1 : 0;
        $fields[] = 'active = ?';
        $values[] = $newActive;
    }

    // Guard against locking everyone out of account management: never allow
    // the last active super_admin to be demoted or deactivated.
    $wasActiveSuperAdmin = $row['role'] === 'super_admin' && (int) $row['active'] === 1;
    $stillActiveSuperAdmin = $newRole === 'super_admin' && $newActive === 1;
    if ($wasActiveSuperAdmin && !$stillActiveSuperAdmin && count_other_active_super_admins($pdo, $id) === 0) {
        json_error('cannot demote or deactivate the last active super admin', 409);
    }

    if (empty($fields)) {
        json_error('no fields to update');
    }

    $values[] = $id;
    $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($values);

    $after = format_user(fetch_user_or_404($pdo, $id));
    record_audit('user.edit', 'user', $row['username'], $before, $after);

    echo json_encode($after);
    exit;
}

json_error('method not allowed', 405);
