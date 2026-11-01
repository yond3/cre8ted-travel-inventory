<?php
/**
 * GET  /api/usage.php?item=<item_key>              -> full usage history
 * POST /api/usage.php?item=<item_key>               body: { "month": "2026-07-01", "usage": 5 }
 *      -> manual history for completed months without a saved month close.
 */
require __DIR__ . '/config.php';
block_department_user();

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) {
    json_error('method not allowed', 405);
}
if ($method === 'GET') {
    require_staff_or_above();
} else {
    require_manager_or_above();
}

$itemKey = $_GET['item'] ?? '';
if (!is_string($itemKey) || $itemKey === '' || strlen($itemKey) > 50) {
    json_error('missing required query param: item');
}
get_item_or_404($itemKey);

$pdo = get_pdo();
usage_require_schema($pdo);

if ($method === 'GET') {
    $stmt = $pdo->prepare('SELECT u.month, u.usage_qty, u.usage_version, u.usage_source,
        mc.usage_qty AS close_usage FROM usage_log u LEFT JOIN month_closes mc
        ON mc.item_key=u.item_key AND mc.month=u.month WHERE u.item_key = ? ORDER BY u.month ASC');
    $stmt->execute([$itemKey]);
    $rows = array_map(
        fn($r) => ['month' => $r['month'], 'usage' => (float) $r['usage_qty'],
            'usage_version' => (string) $r['usage_version'], 'usage_source' => $r['usage_source'],
            'has_month_close' => $r['close_usage'] !== null,
            'usage_conflict' => $r['close_usage'] !== null && stock_cents($r['close_usage']) !== stock_cents($r['usage_qty'])],
        $stmt->fetchAll()
    );
    echo json_encode($rows);
    exit;
}

// POST
$body = read_json_body();
$month = $body['month'] ?? null;
$usage = $body['usage'] ?? null;
$month = parse_usage_month($month);
$usage = parse_nonnegative_quantity($usage, 'Usage quantity');

usage_completed_month($month);
$reason = usage_reason($body['reason'] ?? null);
usage_begin($pdo, $itemKey, $body, 'usage_record');
try {
    $before = usage_snapshot($pdo, $itemKey, $month);
    usage_expect_version($body, $before);
    if ($before['close']) json_error('This month has a saved close. Correct its closing figures through month close instead of editing usage independently.', 409);
    if ($before['usage']) $reason = usage_reason($reason, true);
    $version = usage_save($pdo, $itemKey, $month, $usage, 'manual');
    record_audit('usage.record', 'usage', "$itemKey:$month", $before,
        ['usage_qty' => $usage, 'usage_version' => $version, 'usage_source' => 'manual', 'reason' => $reason], true);
    stock_reply($pdo, ['status' => 'ok', 'item' => $itemKey, 'month' => $month, 'usage' => $usage, 'usage_version' => $version]);
} catch (Throwable $e) {
    stock_failure($e);
}
