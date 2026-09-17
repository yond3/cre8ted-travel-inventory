<?php
/**
 * GET /api/reports.php?type=<type>&from=<date>&to=<date>&format=csv|html
 *
 * Exports a date-range report as a CSV download or a printable HTML page
 * (open it and use the browser's own Print -> Save as PDF — no extra PDF
 * library needed). Manager and above only — these are the same aggregate,
 * cross-employee views as the rest of the admin/ops screens.
 *
 * type is one of:
 *   purchase_requests    (date range on created_at)
 *   purchase_orders      (date range on created_at)
 *   inventory_retirements (date range on created_at)
 *   equipment_movements  (date range on created_at)
 *   stock_issues         (date range on created_at)
 *   month_closes          (date range on closed_at — usage/closing history)
 *   inventory             (current snapshot — from/to ignored)
 *   suppliers              (current snapshot — from/to ignored)
 *   finance_log            (super admin only — date range on created_at)
 *
 * from/to are YYYY-MM-DD, inclusive. Omit both for "all time".
 */
require __DIR__ . '/config.php';
block_department_user();
require_manager_or_above();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('method not allowed', 405);
}

$pdo = get_pdo();
$type = $_GET['type'] ?? '';
$format = $_GET['format'] ?? 'csv';
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));

if (!in_array($format, ['csv', 'html'], true)) {
    json_error("format must be 'csv' or 'html'");
}
if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    json_error('from must be YYYY-MM-DD');
}
if ($to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    json_error('to must be YYYY-MM-DD');
}
if ($from !== '' && $to !== '') {
    $fromDt = DateTime::createFromFormat('Y-m-d', $from);
    $toDt = DateTime::createFromFormat('Y-m-d', $to);
    if (!$fromDt || !$toDt || $fromDt > $toDt) {
        json_error('from must be on or before to');
    }
    if ((int) $fromDt->diff($toDt)->format('%a') > 366) {
        json_error('date range cannot exceed 1 year');
    }
}

if ($type === 'finance_log') {
    require_super_admin();
}

/** Builds "WHERE <col> >= ? AND <col> <= ?" (whichever bound is present) for a date-range column. */
function date_range_clause(string $column, string $from, string $to, array &$params): string
{
    $clauses = [];
    if ($from !== '') {
        $clauses[] = "$column >= ?";
        $params[] = "$from 00:00:00";
    }
    if ($to !== '') {
        $clauses[] = "$column <= ?";
        $params[] = "$to 23:59:59";
    }
    return $clauses ? ('WHERE ' . implode(' AND ', $clauses)) : '';
}

/**
 * @return array{title: string, columns: list<string>, rows: list<list<string>>}
 */
function build_report(PDO $pdo, string $type, string $from, string $to): array
{
    switch ($type) {
        case 'purchase_requests':
            $params = [];
            $where = date_range_clause('r.created_at', $from, $to, $params);
            $rows = $pdo->prepare(
                "SELECT r.request_code, r.created_at, r.employee, r.department,
                        COALESCE(r.requested_label, i.label) AS item_label, r.qty, i.unit,
                        r.reason, r.status, r.notes
                 FROM purchase_requests r
                 LEFT JOIN items i ON i.item_key = r.item_key
                 $where
                 ORDER BY r.created_at DESC"
            );
            $rows->execute($params);
            return [
                'title' => 'Purchase requests',
                'columns' => ['Request #', 'Date', 'Employee', 'Department', 'Item', 'Qty', 'Unit', 'Reason', 'Status', 'Notes'],
                'rows' => array_map(fn ($r) => [
                    $r['request_code'], $r['created_at'], $r['employee'], $r['department'] ?? '',
                    $r['item_label'] ?? '', $r['qty'], $r['unit'] ?? '', $r['reason'] ?? '', $r['status'], $r['notes'] ?? '',
                ], $rows->fetchAll()),
            ];

        case 'purchase_orders':
            $params = [];
            $where = date_range_clause('po.created_at', $from, $to, $params);
            $rows = $pdo->prepare(
                "SELECT po.po_code, po.created_at, pr.request_code,
                        COALESCE(pr.requested_label, i.label) AS item_label, pr.qty, i.unit,
                        s.name AS supplier_name, po.procurement_method, po.amount, po.status,
                        po.received_at, po.finance_status
                 FROM purchase_orders po
                 JOIN purchase_requests pr ON pr.id = po.request_id
                 LEFT JOIN items i ON i.item_key = pr.item_key
                 LEFT JOIN suppliers s ON s.id = po.supplier_id
                 $where
                 ORDER BY po.created_at DESC"
            );
            $rows->execute($params);
            return [
                'title' => 'Purchase orders',
                'columns' => ['PO #', 'Date', 'Request #', 'Item', 'Qty', 'Unit', 'Supplier', 'Method', 'Amount', 'Status', 'Received at', 'Finance status'],
                'rows' => array_map(fn ($r) => [
                    $r['po_code'], $r['created_at'], $r['request_code'], $r['item_label'] ?? '', $r['qty'],
                    $r['unit'] ?? '', $r['supplier_name'] ?? '', $r['procurement_method'], $r['amount'] ?? '',
                    $r['status'], $r['received_at'] ?? '', $r['finance_status'] ?? '',
                ], $rows->fetchAll()),
            ];

        case 'inventory_retirements':
            $params = [];
            $where = date_range_clause('ir.created_at', $from, $to, $params);
            $rows = $pdo->prepare(
                "SELECT ir.retirement_code, ir.created_at, i.label, ir.qty, i.unit, ir.source,
                        ir.department, ir.reason, ir.notes, ir.recorded_by
                 FROM inventory_retirements ir
                 JOIN items i ON i.item_key = ir.item_key
                 $where
                 ORDER BY ir.created_at DESC"
            );
            $rows->execute($params);
            return [
                'title' => 'Inventory write-offs / retirements',
                'columns' => ['Retirement #', 'Date', 'Item', 'Qty', 'Unit', 'Source', 'Department', 'Reason', 'Notes', 'Recorded by'],
                'rows' => array_map(fn ($r) => [
                    $r['retirement_code'], $r['created_at'], $r['label'], $r['qty'], $r['unit'],
                    $r['source'], $r['department'] ?? '', $r['reason'], $r['notes'] ?? '', $r['recorded_by'],
                ], $rows->fetchAll()),
            ];

        case 'equipment_movements':
            $params = [];
            $where = date_range_clause('em.created_at', $from, $to, $params);
            $rows = $pdo->prepare(
                "SELECT em.movement_code, em.created_at, i.label, em.qty, i.unit, em.movement_type,
                        em.department, l.name AS location_name, em.issued_to, em.recorded_by, em.status
                 FROM equipment_movements em
                 JOIN items i ON i.item_key = em.item_key
                 LEFT JOIN locations l ON l.id = em.location_id
                 $where
                 ORDER BY em.created_at DESC"
            );
            $rows->execute($params);
            return [
                'title' => 'Equipment movements',
                'columns' => ['Movement #', 'Date', 'Item', 'Qty', 'Unit', 'Type', 'Department', 'Location', 'Issued to', 'Recorded by', 'Status'],
                'rows' => array_map(fn ($r) => [
                    $r['movement_code'], $r['created_at'], $r['label'], $r['qty'], $r['unit'], $r['movement_type'],
                    $r['department'] ?? '', $r['location_name'] ?? '', $r['issued_to'] ?? '', $r['recorded_by'], $r['status'],
                ], $rows->fetchAll()),
            ];

        case 'month_closes':
            $params = [];
            $where = date_range_clause('mc.closed_at', $from, $to, $params);
            $rows = $pdo->prepare(
                "SELECT mc.item_key, i.label, mc.month, mc.opening_qty, mc.received_qty,
                        mc.closing_qty, mc.usage_qty, mc.closed_at
                 FROM month_closes mc
                 JOIN items i ON i.item_key = mc.item_key
                 $where
                 ORDER BY mc.closed_at DESC"
            );
            $rows->execute($params);
            return [
                'title' => 'Month close / usage history',
                'columns' => ['Item', 'Month', 'Opening', 'Received', 'Closing', 'Usage', 'Closed at'],
                'rows' => array_map(fn ($r) => [
                    $r['label'], substr($r['month'], 0, 7), $r['opening_qty'], $r['received_qty'],
                    $r['closing_qty'], $r['usage_qty'], $r['closed_at'],
                ], $rows->fetchAll()),
            ];

        case 'inventory':
            $rows = $pdo->query(
                "SELECT i.label, i.equipment_group, i.item_type, i.unit, i.current_qty, i.min_qty, i.max_qty,
                        l.name AS location_name, i.active
                 FROM items i
                 LEFT JOIN locations l ON l.id = i.location_id
                 ORDER BY i.item_type, COALESCE(i.equipment_group, i.label), i.label"
            );
            return [
                'title' => 'Inventory snapshot (as of now)',
                'columns' => ['Item', 'Group', 'Type', 'Unit', 'Qty on hand', 'Min', 'Max', 'Location', 'Active'],
                'rows' => array_map(fn ($r) => [
                    $r['label'], $r['equipment_group'] ?? '', $r['item_type'], $r['unit'], $r['current_qty'],
                    $r['min_qty'] ?? '', $r['max_qty'] ?? '', $r['location_name'] ?? '', $r['active'] ? 'Yes' : 'No',
                ], $rows->fetchAll()),
            ];

        case 'suppliers':
            $rows = $pdo->query(
                "SELECT name, contact, address, rating, procurement_methods, active FROM suppliers ORDER BY name"
            );
            return [
                'title' => 'Supplier directory (as of now)',
                'columns' => ['Name', 'Contact', 'Address', 'Rating', 'Procurement methods', 'Active'],
                'rows' => array_map(fn ($r) => [
                    $r['name'], $r['contact'] ?? '', $r['address'] ?? '', $r['rating'] ?? '',
                    $r['procurement_methods'] ?? '', $r['active'] ? 'Yes' : 'No',
                ], $rows->fetchAll()),
            ];

        case 'stock_issues':
            $params = [];
            $where = date_range_clause('si.created_at', $from, $to, $params);
            $rows = $pdo->prepare(
                "SELECT si.issue_code, si.created_at, i.label, si.qty, i.unit, si.department,
                        si.issued_to, si.recorded_by, si.status, si.voided_reason
                 FROM stock_issues si
                 JOIN items i ON i.item_key = si.item_key
                 $where
                 ORDER BY si.created_at DESC"
            );
            $rows->execute($params);
            return [
                'title' => 'Stock issues / checkouts',
                'columns' => ['Issue #', 'Date', 'Item', 'Qty', 'Unit', 'Department', 'Issued to', 'Recorded by', 'Status', 'Void reason'],
                'rows' => array_map(fn ($r) => [
                    $r['issue_code'], $r['created_at'], $r['label'], $r['qty'], $r['unit'],
                    $r['department'], $r['issued_to'] ?? '', $r['recorded_by'], $r['status'], $r['voided_reason'] ?? '',
                ], $rows->fetchAll()),
            ];

        case 'finance_log':
            $params = [];
            $where = date_range_clause('fil.created_at', $from, $to, $params);
            $rows = $pdo->prepare(
                "SELECT fil.created_at, po.po_code, fil.event_type, fil.direction,
                        fil.response_status, fil.response_body
                 FROM finance_integration_log fil
                 JOIN purchase_orders po ON po.id = fil.po_id
                 $where
                 ORDER BY fil.created_at DESC"
            );
            $rows->execute($params);
            return [
                'title' => 'Finance integration log',
                'columns' => ['Date', 'PO #', 'Event', 'Direction', 'HTTP status', 'Response'],
                'rows' => array_map(fn ($r) => [
                    $r['created_at'], $r['po_code'], $r['event_type'], $r['direction'],
                    $r['response_status'] ?? '', mb_substr((string) ($r['response_body'] ?? ''), 0, 200),
                ], $rows->fetchAll()),
            ];

        default:
            json_error(
                "type must be one of: purchase_requests, purchase_orders, inventory_retirements, " .
                "equipment_movements, stock_issues, month_closes, inventory, suppliers, finance_log"
            );
    }
}

$report = build_report($pdo, $type, $from, $to);

$rangeLabel = ($from !== '' || $to !== '')
    ? ('From ' . ($from ?: 'the beginning') . ' to ' . ($to ?: 'today'))
    : 'All time';

record_audit('report.export', 'report', $type, null, ['format' => $format, 'from' => $from ?: null, 'to' => $to ?: null]);

if ($format === 'csv') {
    $filename = $type . '_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens ₱/– correctly
    fputcsv($out, $report['columns']);
    foreach ($report['rows'] as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

// format === 'html' — a printable page; use the browser's Print -> Save as PDF for a PDF copy.
header('Content-Type: text/html; charset=utf-8');
$user = get_session_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($report['title']) ?> — Cre8ted Travel</title>
<style>
  body{ font-family: Arial, Helvetica, sans-serif; color:#0f2242; margin: 2rem; }
  h1{ font-size: 1.3rem; margin-bottom: .1rem; }
  .meta{ color:#5b6b85; font-size: .85rem; margin-bottom: 1.2rem; }
  table{ width:100%; border-collapse: collapse; font-size: .82rem; }
  th, td{ border: 1px solid #d7dee8; padding: .4rem .5rem; text-align: left; }
  th{ background:#eef2f7; }
  tr:nth-child(even){ background:#fafbfc; }
  .print-btn{ margin-bottom: 1rem; padding:.5rem 1rem; border-radius:8px; border:1px solid #c8d9f5; background:#fff; cursor:pointer; }
  @media print{ .print-btn{ display:none; } body{ margin: 0.5in; } }
</style>
</head>
<body>
  <button class="print-btn" onclick="window.print()">Print / Save as PDF</button>
  <h1><?= htmlspecialchars($report['title']) ?></h1>
  <div class="meta">
    <?= htmlspecialchars($rangeLabel) ?> · Generated <?= htmlspecialchars(date('Y-m-d H:i')) ?>
    by <?= htmlspecialchars($user['name'] ?? '') ?> · <?= count($report['rows']) ?> row(s)
  </div>
  <table>
    <thead><tr><?php foreach ($report['columns'] as $c): ?><th><?= htmlspecialchars($c) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
      <?php if (!$report['rows']): ?>
        <tr><td colspan="<?= count($report['columns']) ?>">No rows in this date range.</td></tr>
      <?php endif; ?>
      <?php foreach ($report['rows'] as $row): ?>
        <tr><?php foreach ($row as $cell): ?><td><?= htmlspecialchars((string) $cell) ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</body>
</html>
