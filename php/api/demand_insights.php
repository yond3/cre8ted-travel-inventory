<?php
/**
 * GET /api/demand_insights.php?limit=8
 *
 * Ranks active consumables by combined usage velocity (usage_log) and
 * recent purchase order activity (Placed + Received).
 */
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('method not allowed', 405);
}

require_auth();

/** Production velocity tiers (90-day window). */
const DEMAND_PO_DAYS = 90;
const DEMAND_PO_FAST_MIN = 3;      // ≥3 orders in 90d → fast
const DEMAND_PO_STEADY_MIN = 1;    // 1–2 orders → steady; 0 → slow (orders alone)
const DEMAND_USAGE_FAST_MULTIPLIER = 2.0;  // avg monthly usage ≥ 2× min → fast
const DEMAND_USAGE_STEADY_MULTIPLIER = 1.0; // avg monthly usage ≥ 1× min → steady

function tier_rank($tier)
{
    $ranks = ['slow' => 0, 'steady' => 1, 'fast' => 2];
    return $ranks[$tier] ?? 0;
}

function higher_velocity_tier($a, $b)
{
    return tier_rank($a) >= tier_rank($b) ? $a : $b;
}

function velocity_tier_from_orders($poCount)
{
    $poCount = (int) $poCount;
    if ($poCount >= DEMAND_PO_FAST_MIN) {
        return 'fast';
    }
    if ($poCount >= DEMAND_PO_STEADY_MIN) {
        return 'steady';
    }
    return 'slow';
}

function velocity_tier_from_usage($avgMonthly, $minQty)
{
    $avgMonthly = (float) $avgMonthly;
    if ($minQty === null || (float) $minQty <= 0 || $avgMonthly <= 0) {
        return null;
    }
    $minQty = (float) $minQty;
    if ($avgMonthly >= DEMAND_USAGE_FAST_MULTIPLIER * $minQty) {
        return 'fast';
    }
    if ($avgMonthly >= DEMAND_USAGE_STEADY_MULTIPLIER * $minQty) {
        return 'steady';
    }
    return 'slow';
}

/**
 * Fixed production rules — orders (90d) and close-month usage vs min stock.
 * Whichever signal is higher wins (e.g. high usage but few POs still fast).
 */
function velocity_tier($poCount, $avgMonthly, $minQty)
{
    $orderTier = velocity_tier_from_orders($poCount);
    $usageTier = velocity_tier_from_usage($avgMonthly, $minQty);
    if ($usageTier === null) {
        return $orderTier;
    }
    return higher_velocity_tier($orderTier, $usageTier);
}

function normalize_match_key($value)
{
    return strtolower(preg_replace('/[^a-z0-9]+/', '', (string) $value));
}

function str_contains_compat($haystack, $needle)
{
    return $needle !== '' && strpos($haystack, $needle) !== false;
}

function request_matches_item($itemKey, $itemLabel, array $poRow)
{
    if (!empty($poRow['item_key']) && $poRow['item_key'] === $itemKey) {
        return true;
    }

    $requested = trim((string) ($poRow['requested_label'] ?? ''));
    if ($requested === '') {
        return false;
    }

    $itemNorm = normalize_match_key($itemLabel);
    $reqNorm = normalize_match_key($requested);
    $keyNorm = normalize_match_key($itemKey);

    if ($reqNorm === '') {
        return false;
    }

    return str_contains_compat($itemNorm, $reqNorm)
        || str_contains_compat($reqNorm, $itemNorm)
        || str_contains_compat($reqNorm, $keyNorm)
        || str_contains_compat($keyNorm, $reqNorm);
}

function po_stats_for_item($itemKey, $itemLabel, array $recentPos)
{
    $count = 0;
    $qty = 0.0;
    $received = 0;
    $placed = 0;

    foreach ($recentPos as $po) {
        if (!request_matches_item($itemKey, $itemLabel, $po)) {
            continue;
        }
        $count++;
        $qty += (float) $po['qty'];
        if ($po['status'] === 'Received') {
            $received++;
        } else {
            $placed++;
        }
    }

    return [
        'count' => $count,
        'qty' => $qty,
        'received' => $received,
        'placed' => $placed,
    ];
}

try {
    $limit = isset($_GET['limit']) ? max(1, min(20, (int) $_GET['limit'])) : 8;
    $lookbackMonths = 6;
    $poDays = DEMAND_PO_DAYS;

    $pdo = get_pdo();

    $items = $pdo->query(
        "SELECT item_key, label, unit, current_qty, min_qty
         FROM items
         WHERE item_type = 'consumable' AND active = 1
         ORDER BY label"
    )->fetchAll();

    $recentPos = $pdo->query(
        "SELECT pr.item_key, pr.requested_label, pr.qty, po.status, po.created_at, po.received_at
         FROM purchase_orders po
         JOIN purchase_requests pr ON pr.id = po.request_id
         WHERE po.status IN ('Placed', 'Received')
           AND COALESCE(po.received_at, po.created_at) >= DATE_SUB(NOW(), INTERVAL {$poDays} DAY)"
    )->fetchAll();

    // Inline LIMIT — PDO cannot bind LIMIT reliably on all MySQL drivers.
    $usageStmt = $pdo->prepare(
        'SELECT usage_qty FROM usage_log WHERE item_key = ? ORDER BY month DESC LIMIT ' . (int) $lookbackMonths
    );

    $rows = [];
    foreach ($items as $item) {
        $key = $item['item_key'];

        $usageStmt->execute([$key]);
        $usageValues = [];
        foreach ($usageStmt->fetchAll() as $usageRow) {
            $usageValues[] = (float) $usageRow['usage_qty'];
        }

        $monthsOfHistory = count($usageValues);
        $avgMonthly = $monthsOfHistory > 0
            ? round(array_sum($usageValues) / $monthsOfHistory, 1)
            : 0.0;

        $recentMonths = min(3, $monthsOfHistory);
        $recentAvg = 0.0;
        if ($recentMonths > 0) {
            $recentSlice = array_slice($usageValues, 0, $recentMonths);
            $recentAvg = round(array_sum($recentSlice) / $recentMonths, 1);
        }

        $poStats = po_stats_for_item($key, $item['label'], $recentPos);
        $poCount = $poStats['count'];
        $poQty = $poStats['qty'];
        $poReceived = $poStats['received'];
        $poPlaced = $poStats['placed'];

        $usageComponent = $avgMonthly > 0 ? $avgMonthly : $recentAvg;
        $poMonthlyRate = $poCount > 0
            ? round($poQty / ($poDays / 30.0), 1)
            : 0.0;
        $velocityScore = round($usageComponent + $poMonthlyRate + ($poCount * 2), 1);

        $currentQty = (float) $item['current_qty'];
        $minQty = $item['min_qty'] !== null ? (float) $item['min_qty'] : null;
        $isLowStock = $minQty !== null && $currentQty <= $minQty;

        $rows[] = [
            'item_key' => $key,
            'label' => $item['label'],
            'unit' => $item['unit'],
            'current_qty' => $currentQty,
            'min_qty' => $minQty,
            'is_low_stock' => $isLowStock,
            'avg_monthly_usage' => $avgMonthly,
            'recent_avg_usage' => $recentAvg,
            'months_of_history' => $monthsOfHistory,
            'po_count_90d' => $poCount,
            'po_qty_90d' => $poQty,
            'po_received_90d' => $poReceived,
            'po_placed_90d' => $poPlaced,
            'po_monthly_rate' => $poMonthlyRate,
            'velocity_score' => $velocityScore,
            'velocity_tier' => velocity_tier($poCount, $avgMonthly, $minQty),
        ];
    }

    usort($rows, function ($a, $b) {
        if ($b['velocity_score'] !== $a['velocity_score']) {
            return $b['velocity_score'] <=> $a['velocity_score'];
        }
        if ($b['po_count_90d'] !== $a['po_count_90d']) {
            return $b['po_count_90d'] <=> $a['po_count_90d'];
        }
        return strcmp($a['label'], $b['label']);
    });

    $top = array_slice($rows, 0, $limit);

    echo json_encode([
        'items' => $top,
        'meta' => [
            'lookback_months' => $lookbackMonths,
            'po_days' => $poDays,
            'consumables_ranked' => count($rows),
            'recent_po_rows' => count($recentPos),
            'tier_standards' => [
                'fast' => '≥' . DEMAND_PO_FAST_MIN . ' orders in ' . $poDays . 'd, or avg usage ≥ ' . DEMAND_USAGE_FAST_MULTIPLIER . '× min stock',
                'steady' => '1–2 orders in ' . $poDays . 'd, or avg usage ≥ ' . DEMAND_USAGE_STEADY_MULTIPLIER . '× min stock',
                'slow' => '0 orders and usage below min stock',
            ],
        ],
    ]);
} catch (Throwable $e) {
    error_log('demand_insights.php failed: ' . $e->getMessage());
    json_error('Could not load demand insights: ' . $e->getMessage(), 500);
}
