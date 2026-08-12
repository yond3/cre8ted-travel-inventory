<?php
/**
 * GET    /api/auth.php  -> current session user (or authenticated: false)
 * POST   /api/auth.php  body: { username, password }
 * DELETE /api/auth.php  -> logout
 */
require __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $user = get_session_user();
    if (!$user) {
        echo json_encode(['authenticated' => false]);
        exit;
    }
    echo json_encode(['authenticated' => true, 'user' => public_user($user)]);
    exit;
}

if ($method === 'POST') {
    $body = read_json_body();
    $username = trim($body['username'] ?? '');
    $password = (string) ($body['password'] ?? '');
    if ($username === '' || $password === '') {
        json_error('username and password are required');
    }
    $user = authenticate_user($username, $password);
    if (!$user) {
        json_error('invalid username or password', 401);
    }
    login_user($user);
    echo json_encode(['status' => 'ok', 'user' => public_user($user)]);
    exit;
}

if ($method === 'DELETE') {
    logout_user();
    echo json_encode(['status' => 'ok']);
    exit;
}

json_error('method not allowed', 405);
