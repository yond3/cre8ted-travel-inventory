<?php
/** Emergency operator tool. Keep outside the public document root. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$options = getopt('', ['help', 'username:', 'confirm-database:', 'operator:', 'reason:',
    'execute', 'confirm-username:', 'acknowledge-all-admins-locked-out', 'output-dir:']);
if (isset($options['help'])) {
    echo "Emergency MFA recovery (authorized server/database operator only).\n"
        . "Default: read-only check with --username=USER --confirm-database=DATABASE.\n"
        . "Execute additionally requires --execute --confirm-username=USER --operator=NAME\n"
        . "  --reason=REASON --acknowledge-all-admins-locked-out --output-dir=NEW_PRIVATE_DIRECTORY\n"
        . "Pause writers and enable php/api/.inventory-maintenance before execution.\n"
        . "Recovers one existing named active Super Admin; preserves password and mandatory MFA.\n";
    exit(0);
}

function emergency_fail(string $message): never { throw new RuntimeException($message); }
function emergency_under(string $path, string $root): bool {
    // Case folding is needed on Windows; canonical parents resolve symlink/junction ancestors.
    if (PHP_OS_FAMILY === 'Windows') { $path = strtolower($path); $root = strtolower($root); }
    return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
}
function emergency_target(PDO $pdo, string $username): array {
    $q = $pdo->prepare('SELECT * FROM users WHERE username=?'); $q->execute([$username]); $user = $q->fetch();
    if (!$user || (int)$user['active'] !== 1 || $user['role'] !== 'super_admin' || $user['account_kind'] !== 'named') {
        emergency_fail('Target must be an existing active named Super Admin.');
    }
    $q = $pdo->prepare('SELECT secret_blob FROM user_authenticators WHERE user_id=?'); $q->execute([$user['id']]); $blob = $q->fetchColumn();
    if (!is_string($blob)) emergency_fail('Target has no previous authenticator enrollment; use normal enrollment administration.');
    // A different valid-length key must not silently become a recovery/key rotation.
    mfa_decrypt((int)$user['id'], $blob);
    return $user;
}

$pdo = null; $directory = null; $file = null; $handle = null; $commitAttempted = false; $committed = false;
try {
    $username = $options['username'] ?? null;
    if (!is_string($username) || !preg_match('/^[a-z][a-z0-9_]{2,29}$/D', $username)) emergency_fail('A valid --username is required.');
    if (!is_string($options['confirm-database'] ?? null) || $options['confirm-database'] === '') emergency_fail('--confirm-database is required.');
    $execute = isset($options['execute']);
    if ($execute) {
        if (($options['confirm-username'] ?? null) !== $username || !isset($options['acknowledge-all-admins-locked-out'])) {
            emergency_fail('Confirm the username and explicitly acknowledge that all administrators are locked out.');
        }
        foreach (['operator' => [1, 100], 'reason' => [5, 1000]] as $field => [$minimum, $maximum]) {
            $value = $options[$field] ?? null;
            if (!is_string($value) || strlen(trim($value)) < $minimum || strlen($value) > $maximum || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                emergency_fail('A valid --' . $field . ' is required.');
            }
        }
        $destination = $options['output-dir'] ?? null;
        if (!is_string($destination) || $destination === '') emergency_fail('A new private --output-dir is required.');
        $parent = realpath(dirname($destination)); $public = realpath(__DIR__ . '/../php');
        $leaf = basename($destination);
        if (!$parent || !$public || in_array($leaf, ['', '.', '..'], true) || emergency_under($parent, $public)) emergency_fail('Output must be a new directory outside the public PHP root.');
        $directory = $parent . DIRECTORY_SEPARATOR . $leaf;
        if (file_exists($directory) || is_link($directory)) emergency_fail('Output directory already exists; use a new private directory.');
    }

    define('SCIM_EMERGENCY_MFA_OPERATOR', true);
    $_SERVER['REQUEST_METHOD'] = 'CLI';
    require __DIR__ . '/../php/api/config.php';
    // This operator action never creates an authenticated browser session.
    if (session_status() === PHP_SESSION_ACTIVE) session_abort();
    $pdo = get_pdo();
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $options['confirm-database']) emergency_fail('Database confirmation does not match the configured database.');
    // Validate CLI prerequisites explicitly before the web-oriented readiness
    // helper (whose json_error exits are intended for HTTP callers).
    $tables = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('auth_policy','user_authenticators','auth_enrollment_grants','auth_challenges','user_recovery_codes')")->fetchColumn();
    $columns = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND ((table_name='users' AND column_name IN ('security_version','account_kind','mfa_required')) OR (table_name='login_attempts' AND column_name='stage'))")->fetchColumn();
    $key = base64_decode((string)env('MFA_ENCRYPTION_KEY', ''), true);
    if ($tables !== 5 || $columns !== 4 || !is_string($key) || strlen($key) !== 32 || !in_array('aes-256-gcm', openssl_get_cipher_methods(), true)
        || (int)$pdo->query('SELECT COUNT(*) FROM auth_policy WHERE id=1')->fetchColumn() !== 1) {
        emergency_fail('Complete account/MFA schema and encryption configuration are required.');
    }
    if (!mfa_ready($pdo)) emergency_fail('Complete account/MFA schema and encryption configuration are required.');
    $maintenance = __DIR__ . '/../php/api/.inventory-maintenance';

    if (!$execute) {
        $pdo->exec('START TRANSACTION READ ONLY');
        $user = emergency_target($pdo, $username);
        $policy = (int)$pdo->query('SELECT enforce_mfa FROM auth_policy WHERE id=1')->fetchColumn();
        $pdo->rollBack();
        echo json_encode(['mode' => 'read_only_check', 'database' => $options['confirm-database'], 'username' => $user['username'],
            'eligible_target' => true, 'enforce_mfa' => $policy, 'maintenance_enabled' => is_file($maintenance),
            'execution_requires' => 'enforced MFA, maintenance, paused writers, verified operator authority and explicit execution confirmations'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
        exit(0);
    }
    if (!is_file($maintenance)) emergency_fail('Maintenance must be enabled and writers paused before recovery.');
    account_change_begin($pdo);
    // This directory-lock then policy-lock order matches existing account operations.
    $policy = $pdo->query('SELECT enforce_mfa FROM auth_policy WHERE id=1 FOR UPDATE')->fetchColumn();
    if ((string)$policy !== '1') emergency_fail('Emergency recovery requires mandatory MFA to remain enabled.');
    if (!is_file($maintenance)) emergency_fail('Maintenance was removed; recovery stopped.');
    $user = emergency_target($pdo, $username);
    $actor = ['username' => 'operator_emergency', 'name' => trim($options['operator']), 'role' => 'system'];
    $token = mfa_issue_grant($pdo, $actor, $user, 'reset', trim($options['reason']));
    $q = $pdo->prepare('SELECT id,expires_at FROM auth_enrollment_grants WHERE user_id=? AND token_hash=?');
    $q->execute([$user['id'], hash('sha256', $token)]); $grant = $q->fetch();
    if (!$grant) emergency_fail('Recovery authorization could not be verified.');
    mfa_audit($pdo, $actor, 'mfa.emergency_recovery', (int)$user['id'], [
        'reason' => trim($options['reason']), 'grant_id' => (int)$grant['id'], 'security_version_before' => (string)$user['security_version'],
        'enforce_mfa' => 1, 'all_admins_locked_out_attested' => true, 'method' => 'operator_cli']);

    if (!@mkdir($directory, 0700)) emergency_fail('Cannot create the new private output directory.');
    $file = $directory . DIRECTORY_SEPARATOR . 'handover.json';
    $handle = @fopen($file, 'x');
    if (!$handle) emergency_fail('Cannot create the private authorization file.');
    if (!@chmod($file, 0600)) emergency_fail('Cannot restrict private authorization file permissions.');
    $payload = json_encode(['username' => $user['username'], 'grant' => $token, 'expires_at_database_time' => $grant['expires_at'],
        'expires_in' => 900, 'grant_id' => (int)$grant['id'],
        'instructions' => 'Use only after the operator reports a successful commit or verifies the matching audit/grant. Resume service after checking maintenance conditions. Use the existing login setup with this authorization and your existing password. Save new recovery codes and perform a fresh MFA login.'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    if (@fwrite($handle, $payload) !== strlen($payload) || !@fflush($handle) || (function_exists('fsync') && !@fsync($handle))) emergency_fail('Private authorization file could not be written completely.');
    fclose($handle); $handle = null;
    if (!is_file($maintenance)) emergency_fail('Maintenance was removed before commit; recovery stopped.');
    $commitAttempted = true;
    $pdo->commit(); $committed = true;
    echo "Emergency reset authorization committed for " . $user['username'] . ". Private handover: " . $file . "\n"
        . "Mandatory MFA remains enabled. Maintenance remains enabled. No browser session was created.\n";
} catch (Throwable $error) {
    if (is_resource($handle)) fclose($handle);
    if ($pdo instanceof PDO && $pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $ignored) {} }
    // A commit connection failure can have an uncertain outcome. Preserve its
    // private handover and require audit/grant inspection instead of retrying.
    if (!$commitAttempted) {
        if ($file !== null && is_file($file)) @unlink($file);
        // Only remove a directory this process actually created (tracked below).
        if ($file !== null && $directory !== null && is_dir($directory)) @rmdir($directory);
    }
    $message = $error instanceof PDOException ? 'Database operation failed.' : $error->getMessage();
    fwrite(STDERR, $message . ($commitAttempted && !$committed
        ? " Commit outcome is uncertain. Keep maintenance enabled; inspect the matching emergency audit/grant before using the private file or retrying.\n"
        : " No recovery changes were committed.\n"));
    exit(1);
}
