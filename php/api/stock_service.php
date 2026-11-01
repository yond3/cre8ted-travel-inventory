<?php
/** Shared transaction boundary for inventory writes. Call only after authorization. */
function stock_begin(PDO $pdo, string $action): void
{
    stock_require_schema($pdo);
    if ($pdo->inTransaction()) {
        return;
    }
    $pdo->beginTransaction();
    $GLOBALS['stock_transaction'] = ['pdo' => $pdo, 'action' => $action, 'items' => [], 'operation' => null,
        'reference_type' => null, 'reference_id' => null, 'reference_code' => null];
    set_exception_handler('stock_failure');
    // One transaction gate covers all inventory mutations during this phase.
    // Acquired before any request/item/issue lock, including other endpoints.
    if (function_exists('request_schema_available') && request_schema_available($pdo)) {
        if((int)$pdo->query('SELECT id FROM inventory_flow_lock WHERE id=1 FOR UPDATE')->fetchColumn()!==1)
            json_error('Inventory transaction gate is missing; review database integrity before writing.',503);
    }
}

function stock_require_schema(PDO $pdo): void
{
    static $verified = false;
    if ($verified) return;
    $count = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND
        ((table_name = 'items' AND column_name = 'stock_version') OR
         (table_name = 'stock_movements' AND column_name = 'quantity_delta') OR
         (table_name = 'inventory_operations' AND column_name = 'request_hash') OR
         (table_name = 'code_counters' AND column_name = 'counter_value'))")->fetchColumn();
    if ($count !== 4) {
        json_error('Inventory upgrade required: apply migration_stock_integrity.sql with writes paused before using this version.', 503);
    }
    $verified = true;
}

function stock_rollback(): void
{
    $context = $GLOBALS['stock_transaction'] ?? null;
    if ($context && $context['pdo']->inTransaction()) {
        $context['pdo']->rollBack();
    }
    unset($GLOBALS['stock_transaction']);
}

function stock_cents($quantity): int
{
    return (int) round((float) $quantity * 100);
}

/** All normal storage deltas use this one guarded mechanism under the item lock. */
function stock_change_storage(PDO $pdo, string $key, float $delta): void
{
    $item = stock_lock_item($pdo, $key);
    $new = stock_cents($item['current_qty']) + stock_cents($delta);
    if ($new < 0 || $new > 9999999999) {
        json_error('This operation would put stock outside its permitted range.', 409);
    }
    if (function_exists('request_schema_available') && request_schema_available($pdo)
        && $new < request_reserved($pdo,$key)) {
        json_error('This stock is committed to approved requests. Explicitly reconcile reservations first.',409);
    }
    $pdo->prepare('UPDATE items SET current_qty = ? WHERE item_key = ?')->execute([$new / 100, $key]);
}

function stock_failure(Throwable $error): void
{
    stock_rollback();
    error_log('Inventory operation failed: ' . $error->getMessage());
    $retryable = $error instanceof PDOException && in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213], true);
    json_error($retryable ? 'Another operation changed this record. Refresh and retry.' : 'The operation failed; no changes were saved.', $retryable ? 409 : 500);
}

function stock_balances(PDO $pdo, string $key): array
{
    $stmt = $pdo->prepare('SELECT department, qty FROM equipment_deployments WHERE item_key = ? ORDER BY department FOR UPDATE');
    $stmt->execute([$key]);
    $balances = [];
    foreach ($stmt->fetchAll() as $row) {
        $balances[$row['department']] = stock_cents($row['qty']);
    }
    return $balances;
}

function stock_lock_item(PDO $pdo, string $key): array
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Stock writes require a transaction');
    }
    $stmt = $pdo->prepare('SELECT * FROM items WHERE item_key = ? FOR UPDATE');
    $stmt->execute([$key]);
    $item = $stmt->fetch();
    if (!$item) {
        json_error("unknown item '$key'", 404);
    }
    if (!isset($GLOBALS['stock_transaction']['items'][$key])) {
        $GLOBALS['stock_transaction']['items'][$key] = ['storage' => stock_cents($item['current_qty']),
            'departments' => stock_balances($pdo, $key)];
    }
    return $item;
}

function stock_reference(string $type, int $id, ?string $code = null): void
{
    $GLOBALS['stock_transaction']['reference_type'] = $type;
    $GLOBALS['stock_transaction']['reference_id'] = $id;
    $GLOBALS['stock_transaction']['reference_code'] = $code;
}

function stock_reference_item(string $key,string $type,int $id,?string $code=null): void
{
    $GLOBALS['stock_transaction']['item_references'][$key]=[$type,$id,$code];
}

function stock_expect_version(array $body, array $item): void
{
    $version = $body['stock_version'] ?? null;
    if ((!is_int($version) && !is_string($version)) || !ctype_digit((string) $version)) {
        json_error('stock_version is required; refresh the item before editing its quantity', 428);
    }
    if ((string) $version !== (string) $item['stock_version']) {
        json_error('Stock changed while this form was open. Refresh and review the new quantities before saving.', 409);
    }
}

function stock_canonical_body(array $body): array
{
    if (!array_is_list($body)) ksort($body);
    foreach ($body as &$value) {
        if (is_array($value)) $value = stock_canonical_body($value);
    }
    unset($value);
    return $body;
}

/** Required for unbound stock writes; request/PO transitions also guard their existing record. */
function stock_operation(PDO $pdo, array $body, bool $required = true): void
{
    $key = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? '';
    if ($key === '' && !$required) {
        return;
    }
    if (!is_string($key) || !preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $key)) {
        json_error('X-Idempotency-Key must be a unique operation identifier (16-100 letters, digits, underscores or hyphens)');
    }
    $user = get_session_user();
    $route = basename($_SERVER['SCRIPT_NAME']) . '?' . ($_SERVER['QUERY_STRING'] ?? '');
    $hash = hash('sha256', $_SERVER['REQUEST_METHOD'] . ':' . $route . ':' . json_encode(stock_canonical_body($body))
        . ':' . $user['role'] . ':' . ($user['department'] ?? ''));
    $stmt = $pdo->prepare('INSERT IGNORE INTO inventory_operations (actor_username, operation_key, request_hash) VALUES (?, ?, ?)');
    $stmt->execute([$user['username'], $key, $hash]);
    $stmt = $pdo->prepare('SELECT request_hash, response_json FROM inventory_operations WHERE actor_username = ? AND operation_key = ? FOR UPDATE');
    $stmt->execute([$user['username'], $key]);
    $existing = $stmt->fetch();
    if (!hash_equals($existing['request_hash'], $hash)) {
        json_error('This operation identifier was already used for a different request.', 409);
    }
    if ($existing['response_json'] !== null) {
        $response = $existing['response_json'];
        stock_rollback();
        echo $response;
        exit;
    }
    $GLOBALS['stock_transaction']['operation'] = [$user['username'], $key];
}

function stock_write_history(PDO $pdo): void
{
    $context = $GLOBALS['stock_transaction'];
    $user = get_session_user();
    $insert = $pdo->prepare('INSERT INTO stock_movements
        (item_key, balance_scope, department, movement_type, quantity_delta, quantity_before, quantity_after,
         actor_username, actor_name, reference_type, reference_id, reference_code)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($context['items'] as $key => $before) {
        $stmt = $pdo->prepare('SELECT current_qty FROM items WHERE item_key = ?');
        $stmt->execute([$key]);
        $storage = stock_cents($stmt->fetchColumn());
        if (function_exists('request_schema_available') && request_schema_available($pdo)
            && $storage < request_reserved($pdo,$key)) {
            json_error('Quantity correction conflicts with approved reservations. Reconcile affected requests first.',409);
        }
        $reference=$context['item_references'][$key]??[$context['reference_type'],$context['reference_id'],$context['reference_code']];
        $departments = stock_balances($pdo, $key);
        $changes = [['storage', '', $before['storage'], $storage]];
        foreach (array_unique(array_merge(array_keys($before['departments']), array_keys($departments))) as $department) {
            $changes[] = ['department', $department, $before['departments'][$department] ?? 0, $departments[$department] ?? 0];
        }
        foreach ($changes as [$scope, $department, $old, $new]) {
            if ($new < 0 || $new > 9999999999) {
                throw new RuntimeException('Inventory balance is outside its permitted range');
            }
            if ($new !== $old) {
                $insert->execute([$key, $scope, $department, $context['action'], ($new - $old) / 100,
                    $old / 100, $new / 100, $user['username'], $user['name'],
                    ...$reference]);
            }
        }
        $pdo->prepare('UPDATE items SET stock_version = stock_version + 1 WHERE item_key = ?')->execute([$key]);
    }
}

/** Operations with replay keys finish only once the response is available. */
function stock_commit(PDO $pdo): void
{
    if (!empty($GLOBALS['stock_transaction']['operation'])) {
        return;
    }
    stock_write_history($pdo);
    $pdo->commit();
    unset($GLOBALS['stock_transaction']);
}

function stock_reply(PDO $pdo, array $response): void
{
    $json = json_encode($response, JSON_THROW_ON_ERROR);
    if ($pdo->inTransaction()) {
        $operation = $GLOBALS['stock_transaction']['operation'] ?? null;
        if ($operation) {
            $pdo->prepare('UPDATE inventory_operations SET response_json = ? WHERE actor_username = ? AND operation_key = ?')
                ->execute([$json, ...$operation]);
        }
        stock_write_history($pdo);
        $pdo->commit();
        unset($GLOBALS['stock_transaction']);
    }
    echo $json;
}
