<?php
/** One taxonomy; item_type and all stock rules remain independent. */
function categories_available(PDO $pdo): bool
{
    static $available = null;
    if ($available === null) {
        $available = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='items' AND column_name IN ('category_id','category_version')")->fetchColumn() === 2;
    }
    return $available;
}

function category_rows(PDO $pdo, bool $lock = false): array
{
    if (!categories_available($pdo)) return [];
    // All administrators serialize on the seeded roots, including empty sibling groups.
    return $pdo->query('SELECT id,name,parent_id,active,revision FROM inventory_categories ORDER BY id' . ($lock ? ' FOR UPDATE' : ''))->fetchAll();
}

function category_metadata(PDO $pdo, array $item): array
{
    static $map = null;
    if ($map === null) $map = array_column(category_rows($pdo), null, 'id');
    $id = $item['category_id'] ?? null;
    $cat = $id !== null ? ($map[$id] ?? null) : null;
    $parent = $cat && $cat['parent_id'] !== null ? ($map[$cat['parent_id']] ?? null) : null;
    return ['category_id' => $id === null ? null : (int) $id,
        'category_version' => (int) ($item['category_version'] ?? 0),
        'category_name' => $cat['name'] ?? null,
        'category_parent_id' => $parent ? (int) $parent['id'] : null,
        'category_parent_name' => $parent['name'] ?? null,
        'category_label' => $cat ? ($parent ? $parent['name'] . ' / ' : '') . $cat['name'] : 'Uncategorized',
        'category_active' => $cat ? (bool) $cat['active'] : null];
}

/** Enrich already-authorized history rows, including inactive catalog items.
 * Batch once per request; no separate classification or transaction snapshot.
 */
function category_metadata_for_key(PDO $pdo, ?string $key): array
{
    static $catalog = null;
    if ($catalog === null) {
        $columns = categories_available($pdo) ? ',category_id,category_version' : '';
        $catalog = array_column($pdo->query('SELECT item_key,item_type' . $columns . ' FROM items')->fetchAll(), null, 'item_key');
    }
    $item = $key !== null ? ($catalog[$key] ?? []) : [];
    return ['item_type' => $item['item_type'] ?? null, ...category_metadata($pdo, $item)];
}

function category_id_value($value): ?int
{
    if ($value === null) return null;
    if ((!is_int($value) && !is_string($value)) || !ctype_digit((string) $value) || (int) $value < 1 || (float) $value > 2147483647) {
        json_error('Category must be a valid positive ID or null.');
    }
    return (int) $value;
}

function category_expect_revision($value, $actual): void
{
    if ((!is_int($value) && !is_string($value)) || !ctype_digit((string) $value)) json_error('A revision is required. Reload and review before saving.', 428);
    if ((string) $value !== (string) $actual) json_error('Category changed while this form was open. Reload and review before saving.', 409);
}

function category_assignment(PDO $pdo, $value, ?array $existing = null, $version = null): ?int
{
    $id = category_id_value($value);
    if (!categories_available($pdo)) {
        if ($id !== null) json_error('Category upgrade has not been applied yet.', 503);
        return null;
    }
    if ($existing !== null) category_expect_revision($version, $existing['category_version']);
    $map = array_column(category_rows($pdo, true), null, 'id');
    if ($id !== null && (!isset($map[$id]) || !(int) $map[$id]['active']) && ($existing === null || (string) ($existing['category_id'] ?? '') !== (string) $id)) {
        json_error('Choose an active category.');
    }
    return $id;
}

function category_filter_rows(PDO $pdo, array $rows): array
{
    if (!isset($_GET['category']) || $_GET['category'] === '') return $rows;
    $selected = $_GET['category'];
    if ($selected !== 'uncategorized') $selected = category_id_value($selected);
    return array_values(array_filter($rows, function ($row) use ($pdo, $selected) {
        $meta = isset($row['category_label']) ? $row : category_metadata($pdo, $row);
        return $selected === 'uncategorized' ? $meta['category_id'] === null
            : $meta['category_id'] === $selected || $meta['category_parent_id'] === $selected;
    }));
}
