<?php
/**
 * GET  /api/close_month.php?item=<key>&month=2026-07 or 2026-07-01
 *      -> suggested opening (from previous month's closing), received (from POs).
 * POST /api/close_month.php?item=<key>
 *      body: { month, opening_qty, received_qty, closing_qty }
 *      -> logs usage, updates stock, saves month_closes row for next month.
 */
require __DIR__ . '/config.php';
block_department_user();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_manager_or_above();

$itemKey = $_GET['item'] ?? '';
if ($itemKey === '') {
    json_error('missing required query param: item');
}
get_item_or_404($itemKey);

$pdo = get_pdo();

function normalize_month(string $raw): string
{
    $raw = trim($raw);
    if (preg_match('/^\d{4}-\d{2}$/', $raw)) {
        return $raw . '-01';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        $d = DateTime::createFromFormat('Y-m-d', $raw);
        if ($d) {
            return $d->format('Y-m-01');
        }
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
    $yearMonth = substr($monthFirstDay, 0, 7);
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(pr.qty), 0)
         FROM purchase_orders po
         JOIN purchase_requests pr ON pr.id = po.request_id
         WHERE pr.item_key = ?
           AND po.status = 'Received'
           AND po.received_at IS NOT NULL
           AND DATE_FORMAT(po.received_at, '%Y-%m') = ?"
    );
    $stmt->execute([$itemKey, $yearMonth]);
    return (float) $stmt->fetchColumn();
}

function po_codes_received_in_month(PDO $pdo, string $itemKey, string $monthFirstDay): array
{
    $yearMonth = substr($monthFirstDay, 0, 7);
    $stmt = $pdo->prepare(
        "SELECT po.po_code, pr.qty
         FROM purchase_orders po
         JOIN purchase_requests pr ON pr.id = po.request_id
         WHERE pr.item_key = ?
           AND po.status = 'Received'
           AND po.received_at IS NOT NULL
           AND DATE_FORMAT(po.received_at, '%Y-%m') = ?
         ORDER BY po.received_at"
    );
    $stmt->execute([$itemKey, $yearMonth]);
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

    $item = get_item_or_404($itemKey);
    return [
        'opening_qty' => (float) $item['current_qty'],
        'opening_source' => 'first_close',
        'opening_note' => 'First month close for this item — no prior record. Adjust only if this wasn\'t the starting stock.',
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $month = normalize_month($_GET['month'] ?? '');
    $opening = opening_for_month($pdo, $itemKey, $month);
    $received = received_qty_for_month($pdo, $itemKey, $month);
    $pos = po_codes_received_in_month($pdo, $itemKey, $month);

    $receivedNote = $received > 0
        ? 'From ' . count($pos) . ' purchase order(s) marked received: ' . implode(', ', array_map(
            fn($r) => $r['po_code'] . ' (+' . (float) $r['qty'] . ')',
            $pos
        ))
        : 'No purchase orders marked received in ' . month_label($month) . '.';

    $yearMonth = substr($month, 0, 7);
    $receivedElsewhere = $received > 0 ? [] : recent_received_by_month($pdo, $itemKey, $yearMonth);
    $receivedHint = null;
    if ($received === 0.0 && !empty($receivedElsewhere)) {
        $parts = array_map(
            fn($r) => $r['label'] . ' (' . $r['qty'] . ' pcs, ' . $r['po_count'] . ' PO' . ($r['po_count'] === 1 ? '' : 's') . ')',
            array_slice($receivedElsewhere, 0, 3)
        );
        $receivedHint = 'Deliveries were recorded in ' . implode('; ', $parts)
            . ' — change the month above to match when items were received.';
    }

    $stmt = $pdo->prepare('SELECT closing_qty, closed_at FROM month_closes WHERE item_key = ? AND month = ?');
    $stmt->execute([$itemKey, $month]);
    $existing = $stmt->fetch();

    echo json_encode([
        'month' => $month,
        'opening_qty' => $opening['opening_qty'],
        'opening_source' => $opening['opening_source'],
        'opening_note' => $opening['opening_note'],
        'received_qty' => $received,
        'received_note' => $receivedNote,
        'received_hint' => $receivedHint,
        'received_elsewhere' => $receivedElsewhere,
        'already_closed' => (bool) $existing,
        'existing_closing_qty' => $existing ? (float) $existing['closing_qty'] : null,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = read_json_body();
    $month = normalize_month($body['month'] ?? '');
    $opening = $body['opening_qty'] ?? null;
    $received = $body['received_qty'] ?? null;
    $closing = $body['closing_qty'] ?? null;

    if ($opening === null || $received === null || $closing === null) {
        json_error('body must include month, opening_qty, received_qty, closing_qty');
    }

    $opening = (float) $opening;
    $received = (float) $received;
    $closing = (float) $closing;

    if ($closing < 0) {
        json_error('closing quantity cannot be negative');
    }

    $usage = max(0, $opening + $received - $closing);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO usage_log (item_key, month, usage_qty) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE usage_qty = VALUES(usage_qty)'
        );
        $stmt->execute([$itemKey, $month, $usage]);

        $stmt = $pdo->prepare(
            'INSERT INTO month_closes (item_key, month, opening_qty, received_qty, closing_qty, usage_qty)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                opening_qty = VALUES(opening_qty),
                received_qty = VALUES(received_qty),
                closing_qty = VALUES(closing_qty),
                usage_qty = VALUES(usage_qty),
                closed_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([$itemKey, $month, $opening, $received, $closing, $usage]);

        $stmt = $pdo->prepare('UPDATE items SET current_qty = ? WHERE item_key = ?');
        $stmt->execute([$closing, $itemKey]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        json_error('failed to close month: ' . $e->getMessage(), 500);
    }

    echo json_encode([
        'status' => 'ok',
        'item' => $itemKey,
        'month' => $month,
        'computed_usage' => $usage,
        'new_current_qty' => $closing,
        'next_month_opening' => $closing,
    ]);
    exit;
}

json_error('method not allowed', 405);
