<?php
/**
 * GET  /api/close_month.php?item=<key>&month=2026-07 or 2026-07-01
 *      -> suggested opening (from previous month's closing), received (from POs).
 * POST /api/close_month.php?item=<key>
 *      body: { month, opening_qty, received_qty, closing_qty, usage_version, context_version, reason? }
 *      -> saves historical usage and closing figures; never changes current stock.
 */
require __DIR__ . '/config.php';
block_department_user();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_manager_or_above();

$itemKey = $_GET['item'] ?? '';
if (!is_string($itemKey) || $itemKey === '' || strlen($itemKey) > 50) {
    json_error('missing required query param: item');
}
$item = get_item_or_404($itemKey);

$pdo = get_pdo();
usage_require_schema($pdo);

function normalize_month($raw): string
{
    if (!is_string($raw)) json_error('month must be YYYY-MM or YYYY-MM-01');
    $raw = trim($raw);
    if (preg_match('/^\d{4}-\d{2}$/D', $raw)) {
        return parse_usage_month($raw . '-01');
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return parse_usage_month($raw);
    }
    json_error('month must be YYYY-MM or YYYY-MM-01');
}

function month_label(string $monthFirstDay): string
{
    $d = DateTime::createFromFormat('Y-m-d', $monthFirstDay);
    return $d ? $d->format('F Y') : $monthFirstDay;
}

function previous_month_first_day(string $monthFirstDay): string
{
    $d = DateTime::createFromFormat('Y-m-d', $monthFirstDay);
    $d->modify('first day of previous month');
    return $d->format('Y-m-d');
}

function received_qty_for_month(PDO $pdo, string $itemKey, string $monthFirstDay): float
{
    $nextMonth = (new DateTimeImmutable($monthFirstDay))->modify('+1 month')->format('Y-m-d');
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(pr.qty), 0)
         FROM purchase_orders po
         JOIN purchase_requests pr ON pr.id = po.request_id
         WHERE pr.item_key = ?
           AND po.status = 'Received'
           AND po.received_at IS NOT NULL
           AND po.received_at >= ? AND po.received_at < ?"
    );
    $stmt->execute([$itemKey, $monthFirstDay, $nextMonth]);
    return (float) $stmt->fetchColumn();
}

function po_codes_received_in_month(PDO $pdo, string $itemKey, string $monthFirstDay): array
{
    $nextMonth = (new DateTimeImmutable($monthFirstDay))->modify('+1 month')->format('Y-m-d');
    $stmt = $pdo->prepare(
        "SELECT po.po_code, pr.qty
         FROM purchase_orders po
         JOIN purchase_requests pr ON pr.id = po.request_id
         WHERE pr.item_key = ?
           AND po.status = 'Received'
           AND po.received_at IS NOT NULL
           AND po.received_at >= ? AND po.received_at < ?
         ORDER BY po.received_at, po.id"
    );
    $stmt->execute([$itemKey, $monthFirstDay, $nextMonth]);
    return $stmt->fetchAll();
}

function recent_received_by_month(PDO $pdo, string $itemKey, string $excludeYearMonth): array
{
    $stmt = $pdo->prepare(
        "SELECT DATE_FORMAT(po.received_at, '%Y-%m') AS ym,
                COALESCE(SUM(pr.qty), 0) AS qty,
                COUNT(*) AS po_count
         FROM purchase_orders po
         JOIN purchase_requests pr ON pr.id = po.request_id
         WHERE pr.item_key = ?
           AND po.status = 'Received'
           AND po.received_at IS NOT NULL
           AND DATE_FORMAT(po.received_at, '%Y-%m') <> ?
         GROUP BY ym
         ORDER BY ym DESC
         LIMIT 6"
    );
    $stmt->execute([$itemKey, $excludeYearMonth]);
    return array_map(fn($r) => [
        'month' => $r['ym'],
        'month_first_day' => $r['ym'] . '-01',
        'label' => month_label($r['ym'] . '-01'),
        'qty' => (float) $r['qty'],
        'po_count' => (int) $r['po_count'],
    ], $stmt->fetchAll());
}

function opening_for_month(PDO $pdo, string $itemKey, string $monthFirstDay): array
{
    $stmt = $pdo->prepare('SELECT opening_qty, closing_qty FROM month_closes WHERE item_key = ? AND month = ?');
    $stmt->execute([$itemKey, $monthFirstDay]);
    $saved = $stmt->fetch();
    if ($saved) {
        return [
            'opening_qty' => (float) $saved['opening_qty'],
            'opening_source' => 'saved',
            'opening_note' => 'Re-opening this month — starting stock from when it was first closed.',
        ];
    }

    $prevMonth = previous_month_first_day($monthFirstDay);
    $stmt = $pdo->prepare('SELECT closing_qty FROM month_closes WHERE item_key = ? AND month = ?');
    $stmt->execute([$itemKey, $prevMonth]);
    $prevClosing = $stmt->fetchColumn();
    if ($prevClosing !== false) {
        return [
            'opening_qty' => (float) $prevClosing,
            'opening_source' => 'previous_close',
            'opening_note' => 'From ' . month_label($prevMonth) . ' closing count — you don\'t need to remember this.',
        ];
    }

    return [
        'opening_qty' => null,
        'opening_source' => 'unknown',
        'opening_note' => 'No previous close is available. Enter the documented opening balance for this month; today\'s stock is not a historical opening balance.',
    ];
}

function close_context(PDO $pdo, string $key, string $month): array
{
    $opening = opening_for_month($pdo, $key, $month);
    $snapshot = usage_snapshot($pdo, $key, $month);
    $received = received_qty_for_month($pdo, $key, $month);
    $pos = po_codes_received_in_month($pdo, $key, $month);
    $warnings = usage_adjacent_warnings($pdo, $key, $month, $snapshot['close']);
    if ($snapshot['usage_conflict']) $warnings[] = 'Saved month-close usage and recorded usage disagree. Review both values and provide a correction reason.';
    return ['opening' => $opening, 'snapshot' => $snapshot, 'received' => $received, 'pos' => $pos,
        'warnings' => $warnings, 'context_version' => hash('sha256', json_encode([
            $opening, $snapshot, $received, $pos, $warnings
        ], JSON_THROW_ON_ERROR))];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $month = normalize_month($_GET['month'] ?? '');
    $pdo->beginTransaction();
    // All figures and revision tokens describe one consistent read snapshot.
    $context = close_context($pdo, $itemKey, $month);
    $existing = $context['snapshot']['close'];
    $received = $context['received'];
    $pos = $context['pos'];
    $elsewhere = $received > 0 ? [] : recent_received_by_month($pdo, $itemKey, substr($month, 0, 7));
    $currentMonth = usage_current_month($pdo);
    $response = [
        'month' => $month,
        'usage_version' => $context['snapshot']['usage_version'],
        'context_version' => $context['context_version'],
        'usage_source' => $context['snapshot']['usage_source'],
        'recorded_usage' => $context['snapshot']['usage'] ? (float) $context['snapshot']['usage']['usage_qty'] : null,
        'close_usage' => $existing ? (float) $existing['usage_qty'] : null,
        'usage_conflict' => $context['snapshot']['usage_conflict'],
        'opening_qty' => $context['opening']['opening_qty'],
        'opening_source' => $context['opening']['opening_source'],
        'opening_note' => $context['opening']['opening_note'],
        'received_qty' => $existing ? (float) $existing['received_qty'] : $received,
        'po_received_qty' => $received,
        'received_note' => 'PO receipts recorded in this month: ' . $received . ' across ' . count($pos) . ' order(s). '
            . implode(', ', array_map(fn($r) => $r['po_code'] . ' (+' . (float) $r['qty'] . ')', $pos))
            . ' Saved overrides are retained when reopening.',
        'received_hint' => $elsewhere ? 'Deliveries were recorded in ' . implode('; ', array_map(
            fn($r) => $r['label'] . ' (' . $r['qty'] . ', ' . $r['po_count'] . ' PO(s))', array_slice($elsewhere, 0, 3)))
            . '. Choose the historical period you are reconciling.' : null,
        'received_elsewhere' => $elsewhere,
        'already_closed' => (bool) $existing,
        'existing_closing_qty' => $existing ? (float) $existing['closing_qty'] : null,
        'reason_required' => (bool) ($existing || $context['snapshot']['usage']),
        'can_close' => $month < $currentMonth,
        'latest_completed_month' => (new DateTimeImmutable($currentMonth))->modify('-1 month')->format('Y-m'),
        'warnings' => $context['warnings'],
    ];
    $pdo->commit();
    echo json_encode($response);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = read_json_body();
    $month = normalize_month($body['month'] ?? '');
    usage_completed_month($month);
    $opening = parse_nonnegative_quantity($body['opening_qty'] ?? null, 'Opening quantity');
    $received = parse_nonnegative_quantity($body['received_qty'] ?? null, 'Received quantity');
    $closing = parse_nonnegative_quantity($body['closing_qty'] ?? null, 'Closing quantity');
    $usageCents = stock_cents($opening) + stock_cents($received) - stock_cents($closing);
    if ($usageCents < 0) {
        json_error('Closing quantity exceeds opening plus receipts. Review missing receipts or stock discrepancies; usage cannot be silently set to zero.');
    }
    $usage = parse_nonnegative_quantity($usageCents / 100, 'Computed usage');
    $reason = usage_reason($body['reason'] ?? null);
    $item = usage_begin($pdo, $itemKey, $body, 'month_close');
    try {
        $context = close_context($pdo, $itemKey, $month);
        usage_expect_version($body, $context['snapshot']);
        if (!is_string($body['context_version'] ?? null)) {
            json_error('context_version is required. Reload the selected month.', 428);
        }
        if (!hash_equals($context['context_version'], $body['context_version'])) {
            json_error('The monthly figures changed while this form was open. Reload and review the selected month.', 409);
        }
        $expectedOpening = $context['opening']['opening_qty'];
        $requiresReason = $context['snapshot']['usage'] || $context['snapshot']['close']
            || ($expectedOpening !== null && stock_cents($opening) !== stock_cents($expectedOpening))
            || stock_cents($received) !== stock_cents($context['received']);
        if ($requiresReason) $reason = usage_reason($reason, true);
        $version = usage_save($pdo, $itemKey, $month, $usage, 'month_close');
        $stmt = $pdo->prepare('INSERT INTO month_closes (item_key, month, opening_qty, received_qty, closing_qty, usage_qty)
            VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE opening_qty=VALUES(opening_qty),
            received_qty=VALUES(received_qty), closing_qty=VALUES(closing_qty), usage_qty=VALUES(usage_qty), closed_at=CURRENT_TIMESTAMP');
        $stmt->execute([$itemKey, $month, $opening, $received, $closing, $usage]);
        $after = ['opening_qty' => $opening, 'received_qty' => $received, 'closing_qty' => $closing,
            'usage_qty' => $usage, 'usage_version' => $version, 'usage_source' => 'month_close', 'reason' => $reason];
        record_audit('month_close.record', 'month_close', "$itemKey:$month", $context['snapshot'], $after, true);
        $warnings = usage_adjacent_warnings($pdo, $itemKey, $month, $after);
        stock_reply($pdo, ['status' => 'ok', 'item' => $itemKey, 'month' => $month,
            'computed_usage' => $usage, 'usage_version' => $version, 'current_qty' => (float) $item['current_qty'],
            'stock_changed' => false, 'next_month_opening' => $closing, 'warnings' => $warnings]);
    } catch (Throwable $e) {
        stock_failure($e);
    }
    exit;
}

json_error('method not allowed', 405);
