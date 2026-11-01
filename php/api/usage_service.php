<?php
/** One monthly dataset: both existing write endpoints share these rules. */
function usage_schema_available(PDO $pdo): bool
{
    $count = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema=DATABASE() AND table_name='usage_log'
        AND column_name IN ('usage_version','usage_source')")->fetchColumn();
    return $count === 2;
}

function usage_require_schema(PDO $pdo): void
{
    if (!usage_schema_available($pdo)) {
        json_error('Monthly usage upgrade required. Apply migration_usage_consistency.sql with writes paused before using month closing or usage editing.', 503);
    }
}

function usage_completed_month(string $month): void
{
    // Use the database calendar, matching PO received_at/NOW() and summaries.
    if ($month >= usage_current_month(get_pdo())) {
        json_error('Only completed calendar months can be recorded as final usage.');
    }
}

function usage_current_month(PDO $pdo): string
{
    static $month = null;
    return $month ??= (string) $pdo->query("SELECT DATE_FORMAT(CURRENT_DATE, '%Y-%m-01')")->fetchColumn();
}

function usage_reason($value, bool $required = false): ?string
{
    if ($value !== null && !is_string($value)) json_error('Reason must be text.');
    return $required ? parse_required_text($value, LIMIT_NOTE, 'Reason')
        : parse_optional_text($value, LIMIT_NOTE, 'Reason');
}

function usage_snapshot(PDO $pdo, string $key, string $month): array
{
    $stmt = $pdo->prepare('SELECT * FROM usage_log WHERE item_key=? AND month=?');
    $stmt->execute([$key, $month]);
    $usage = $stmt->fetch() ?: null;
    $stmt = $pdo->prepare('SELECT * FROM month_closes WHERE item_key=? AND month=?');
    $stmt->execute([$key, $month]);
    $close = $stmt->fetch() ?: null;
    return ['usage' => $usage, 'close' => $close,
        'usage_version' => $usage ? (string) $usage['usage_version'] : '0',
        'usage_source' => $usage['usage_source'] ?? 'unrecorded',
        'usage_conflict' => $close && (!$usage || stock_cents($usage['usage_qty']) !== stock_cents($close['usage_qty']))];
}

function usage_begin(PDO $pdo, string $key, array $body, string $action): array
{
    usage_require_schema($pdo);
    stock_begin($pdo, $action);
    // Match the operation-key -> item lock order used by stock issuance.
    stock_operation($pdo, $body, false);
    // Share the established item-lock order with issuance and receiving, but do
    // not register a stock change or advance stock_version for historical edits.
    $stmt = $pdo->prepare('SELECT * FROM items WHERE item_key=? FOR UPDATE');
    $stmt->execute([$key]);
    $item = $stmt->fetch();
    if (!$item) json_error('Unknown inventory item.', 404);
    if ($item['item_type'] !== 'consumable') json_error('Monthly usage is only recorded for consumables.');
    return $item;
}

function usage_expect_version(array $body, array $snapshot): void
{
    $version = $body['usage_version'] ?? null;
    if ((!is_string($version) && !is_int($version)) || !preg_match('/^(0|[1-9][0-9]*)$/D', (string) $version)) {
        json_error('usage_version is required. Reload the selected month before saving.', 428);
    }
    if ((string) $version !== $snapshot['usage_version']) {
        json_error('This monthly record changed while the form was open. Reload and review it before saving.', 409);
    }
}

function usage_save(PDO $pdo, string $key, string $month, float $quantity, string $source): string
{
    $stmt = $pdo->prepare('INSERT INTO usage_log (item_key, month, usage_qty, usage_version, usage_source)
        VALUES (?, ?, ?, 1, ?) ON DUPLICATE KEY UPDATE usage_qty=VALUES(usage_qty),
        usage_version=usage_version+1, usage_source=VALUES(usage_source)');
    $stmt->execute([$key, $month, $quantity, $source]);
    $stmt = $pdo->prepare('SELECT usage_version FROM usage_log WHERE item_key=? AND month=?');
    $stmt->execute([$key, $month]);
    return (string) $stmt->fetchColumn();
}

function usage_adjacent_warnings(PDO $pdo, string $key, string $month, ?array $close): array
{
    if (!$close) return [];
    $date = new DateTimeImmutable($month);
    $previous = $date->modify('-1 month')->format('Y-m-d');
    $next = $date->modify('+1 month')->format('Y-m-d');
    $stmt = $pdo->prepare('SELECT month, opening_qty, closing_qty FROM month_closes
        WHERE item_key=? AND month IN (?, ?) ORDER BY month');
    $stmt->execute([$key, $previous, $next]);
    $warnings = [];
    foreach ($stmt->fetchAll() as $row) {
        if ($row['month'] === $previous && stock_cents($row['closing_qty']) !== stock_cents($close['opening_qty'])) {
            $warnings[] = 'Opening quantity differs from the previous month closing (' . substr($previous, 0, 7) . '). Review both records; no other month was changed.';
        }
        if ($row['month'] === $next && stock_cents($row['opening_qty']) !== stock_cents($close['closing_qty'])) {
            $warnings[] = 'Closing quantity differs from the next month opening (' . substr($next, 0, 7) . '). Review both records; no other month was changed.';
        }
    }
    return $warnings;
}
