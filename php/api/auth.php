<?php
/**
 * GET    /api/auth.php  -> current session user (or authenticated: false)
 * POST   /api/auth.php  body: { username, password }
 * DELETE /api/auth.php  -> logout
 */
require __DIR__ . '/config.php';
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && ($_GET['transport'] ?? '') === '1') {
    // No user, database, key or deployment configuration is disclosed.
    mfa_secure_transport();
    echo json_encode(['transport_allowed' => true]);
    exit;
}

if ($method === 'GET') {
    $user = get_session_user();
    if (!$user) {
        header('Cache-Control: no-store');
        echo json_encode(mfa_ready(get_pdo())?mfa_pending_response(get_pdo()):['authenticated' => false]);
        exit;
    }
    echo json_encode(['authenticated' => true, 'user' => public_user($user), 'csrf_token' => get_csrf_token()]);
    exit;
}

if ($method === 'POST') {
    $body = read_json_body();
    if(mfa_ready(get_pdo())){echo json_encode(mfa_auth_post(get_pdo(),$body));exit;}
    $username = trim($body['username'] ?? '');
    $password = (string) ($body['password'] ?? '');
    if ($username === '' || $password === '') {
        json_error('username and password are required');
    }

    $usernameKey = strtolower($username);
    $ip = get_client_ip();

    $lockoutRemaining = login_lockout_seconds_remaining($usernameKey, $ip);
    if ($lockoutRemaining > 0) {
        $minutes = (int) ceil($lockoutRemaining / 60);
        json_error(
            "too many failed login attempts — try again in {$minutes} minute" . ($minutes === 1 ? '' : 's'),
            429
        );
    }

    $user = authenticate_user($username, $password);
    record_login_attempt($usernameKey, $ip, $user !== null);

    if (!$user) {
        json_error('invalid username or password', 401);
    }
    login_user($user);
    echo json_encode(['status' => 'ok', 'user' => public_user($user), 'csrf_token' => get_csrf_token()]);
    exit;
}

if ($method === 'DELETE') {
    logout_user();
    echo json_encode(['status' => 'ok']);
    exit;
}

json_error('method not allowed', 405);
