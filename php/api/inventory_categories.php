<?php
require __DIR__ . '/config.php';
block_department_user();
require_staff_or_above();
$pdo = get_pdo();
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET') {
    echo json_encode(category_rows($pdo));
    exit;
}
require_manager_or_above();
if (!in_array($method, ['POST','PUT'], true)) json_error('method not allowed', 405);
if (!categories_available($pdo)) json_error('Category upgrade has not been applied yet.', 503);
$body = read_json_body();
stock_begin($pdo, 'category_edit');
$rows = category_rows($pdo, true);
$map = array_column($rows, null, 'id');
$id = $method === 'PUT' ? category_id_value($_GET['id'] ?? null) : null;
$old = $id !== null ? ($map[$id] ?? null) : null;
if ($method === 'PUT' && !$old) json_error('Unknown category.', 404);
if ($old) category_expect_revision($body['revision'] ?? null, $old['revision']);
$rawName = $body['name'] ?? ($old['name'] ?? '');
if (!is_string($rawName)) json_error('Category name must be text.');
$name = parse_required_text(trim(preg_replace('/\s+/u', ' ', $rawName)), 80, 'Category name');
$normalized = strtolower($name);
$parent = array_key_exists('parent_id', $body) ? category_id_value($body['parent_id']) : ($old['parent_id'] ?? null);
$parent = $parent === null ? null : (int) $parent;
$active = (int) ($old['active'] ?? 1);
if (array_key_exists('active', $body)) {
    if (!in_array($body['active'], [true,false,0,1], true)) json_error('active must be true or false.');
    $active = (int) (bool) $body['active'];
}
if ($parent !== null) {
    if ($parent === $id || !isset($map[$parent]) || $map[$parent]['parent_id'] !== null) json_error('Choose a root parent; only two category levels are supported.');
    if ($active && !(int) $map[$parent]['active']) json_error('An active category requires an active parent.');
}
foreach ($rows as $row) {
    if ($id !== null && (int) ($row['parent_id'] ?? 0) === $id && ($parent !== null || (!$active && (int) $row['active']))) json_error('Move children before nesting this category, or archive active children before archiving their parent.', 409);
}
try {
    if ($old) {
        $pdo->prepare('UPDATE inventory_categories SET name=?, normalized_name=?, parent_id=?, active=?, revision=revision+1 WHERE id=?')->execute([$name,$normalized,$parent,$active,$id]);
    } else {
        $pdo->prepare('INSERT INTO inventory_categories(name,normalized_name,parent_id,active) VALUES(?,?,?,?)')->execute([$name,$normalized,$parent,$active]);
        $id = (int) $pdo->lastInsertId();
    }
} catch (PDOException $e) {
    if (($e->errorInfo[1] ?? null) === 1062) json_error('A category with this name already exists under that parent.', 409);
    throw $e;
}
$new = ['id'=>$id,'name'=>$name,'parent_id'=>$parent,'active'=>$active,'revision'=>$old ? (int)$old['revision']+1 : 0];
record_audit($old ? 'category.edit' : 'category.create', 'inventory_category', (string)$id, $old, $new, true);
stock_commit($pdo);
echo json_encode($new);
