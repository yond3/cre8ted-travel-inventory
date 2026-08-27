<?php
/**
 * Vendor quotation submissions — no login; vendors use vendor-apply.html.
 *
 * GET  /api/vendor_applications.php?quotable=1
 *      -> active catalog items vendors can quote (consumables + equipment).
 * GET  /api/vendor_applications.php
 * GET  /api/vendor_applications.php?status=Pending
 *      -> list applications with line-item prices (admin).
 * GET  /api/vendor_applications.php?id=<id>
 *      -> single application.
 * POST /api/vendor_applications.php
 *      body: { company_name, contact, procurement_methods: ['walk_in',...], notes,
 *              prices: [{ item_key, price }, ...] }
 * PUT  /api/vendor_applications.php?id=<id>
 *      body: { action: 'approve' | 'reject', rating?: number }
 *      -> approve copies prices into suppliers + supplier_prices.
 */
require __DIR__ . '/config.php';

$pdo = get_pdo();
$method = $_SERVER['REQUEST_METHOD'];

function fetch_application(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM vendor_applications WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    return format_application($pdo, $row);
}

function fetch_prices(PDO $pdo, int $applicationId): array
{
    $stmt = $pdo->prepare(
        'SELECT vap.item_key, i.label, i.unit, vap.price
         FROM vendor_application_prices vap
         JOIN items i ON i.item_key = vap.item_key
         WHERE vap.application_id = ?
         ORDER BY i.label'
    );
    $stmt->execute([$applicationId]);
    return array_map(fn($r) => [
        'item_key' => $r['item_key'],
        'label' => $r['label'],
        'unit' => $r['unit'],
        'price' => (float) $r['price'],
    ], $stmt->fetchAll());
}

function format_application(PDO $pdo, array $row): array
{
    return [
        'id' => (int) $row['id'],
        'application_code' => $row['application_code'],
        'company_name' => $row['company_name'],
        'contact' => $row['contact'],
        'phones' => $row['phones'] ?? null,
        'emails' => $row['emails'] ?? null,
        'address' => $row['address'] ?? null,
        'procurement_methods' => $row['procurement_methods']
            ? explode(',', $row['procurement_methods'])
            : [],
        'notes' => $row['notes'],
        'status' => $row['status'],
        'supplier_id' => $row['supplier_id'] !== null ? (int) $row['supplier_id'] : null,
        'created_at' => $row['created_at'],
        'reviewed_at' => $row['reviewed_at'],
        'prices' => fetch_prices($pdo, (int) $row['id']),
    ];
}

function normalize_methods($methods): string
{
    $allowed = ['walk_in', 'pickup', 'delivery', 'online'];
    if (!is_array($methods)) {
        $methods = [$methods ?: 'walk_in'];
    }
    $methods = array_values(array_intersect($methods, $allowed));
    if (empty($methods)) {
        $methods = ['walk_in'];
    }
    return implode(',', $methods);
}

function normalize_contact_list(?string $value): ?string
{
    if ($value === null) {
        return null;
    }
    $lines = preg_split('/[\r\n,]+/', $value);
    $parts = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
            $parts[] = $line;
        }
    }
    if ($parts === []) {
        return null;
    }
    return implode("\n", $parts);
}

function format_vendor_contact_summary(?string $phones, ?string $emails): ?string
{
    $parts = [];
    if ($phones !== null && $phones !== '') {
        foreach (preg_split('/[\r\n]+/', $phones) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $parts[] = $line;
            }
        }
    }
    if ($emails !== null && $emails !== '') {
        foreach (preg_split('/[\r\n]+/', $emails) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $parts[] = $line;
            }
        }
    }
    if ($parts === []) {
        return null;
    }
    return implode(' · ', $parts);
}

if ($method === 'GET') {
    if (isset($_GET['quotable'])) {
        $rows = $pdo->query(
            "SELECT item_key, label, unit, item_type FROM items
             WHERE active = 1
             AND (item_type = 'consumable'
                  OR (item_type = 'equipment' AND (assigned_department IS NULL OR TRIM(assigned_department) = '')))
             ORDER BY item_type, label"
        )->fetchAll();
        echo json_encode(array_map(fn($r) => [
            'item_key' => $r['item_key'],
            'label' => $r['label'],
            'unit' => $r['unit'],
            'item_type' => $r['item_type'],
        ], $rows));
        exit;
    }

    require_auth();

    $id = (int) ($_GET['id'] ?? 0);
    if ($id) {
        $app = fetch_application($pdo, $id);
        if (!$app) {
            json_error('unknown application', 404);
        }
        echo json_encode($app);
        exit;
    }

    $status = $_GET['status'] ?? null;
    $sql = 'SELECT * FROM vendor_applications';
    $params = [];
    if ($status !== null && $status !== '') {
        $sql .= ' WHERE status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY created_at DESC, id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(array_map(fn($r) => format_application($pdo, $r), $stmt->fetchAll()));
    exit;
}

if ($method === 'POST') {
    $body = read_json_body();
    $companyName = trim($body['company_name'] ?? '');
    if ($companyName === '') {
        json_error('company_name is required');
    }

    $prices = $body['prices'] ?? [];
    if (!is_array($prices) || empty($prices)) {
        json_error('prices must include at least one item with a unit price');
    }

    $validItems = $pdo->query(
        "SELECT item_key FROM items WHERE active = 1
         AND (item_type = 'consumable'
              OR (item_type = 'equipment' AND (assigned_department IS NULL OR TRIM(assigned_department) = '')))"
    )->fetchAll(PDO::FETCH_COLUMN);
    $validSet = array_flip($validItems);

    $lines = [];
    foreach ($prices as $line) {
        $itemKey = $line['item_key'] ?? '';
        $price = $line['price'] ?? null;
        if ($itemKey === '' || !isset($validSet[$itemKey])) {
            continue;
        }
        if ($price === null || $price === '' || (float) $price <= 0) {
            continue;
        }
        $lines[$itemKey] = (float) $price;
    }
    if (empty($lines)) {
        json_error('add at least one valid item price greater than zero');
    }

    $code = next_code('VQ', 'vendor_applications', 'application_code');
    $methods = normalize_methods($body['procurement_methods'] ?? ['walk_in']);
    $phones = normalize_contact_list($body['phones'] ?? null);
    $emails = normalize_contact_list($body['emails'] ?? null);
    $address = trim($body['address'] ?? '') ?: null;
    $legacyContact = trim($body['contact'] ?? '') ?: null;

    if ($phones === null && $emails === null && $legacyContact === null) {
        json_error('provide at least one phone number or email address');
    }

    $contactSummary = format_vendor_contact_summary($phones, $emails) ?: $legacyContact;

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO vendor_applications
             (application_code, company_name, contact, phones, emails, address, procurement_methods, notes, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $code,
            $companyName,
            $contactSummary,
            $phones,
            $emails,
            $address,
            $methods,
            trim($body['notes'] ?? '') ?: null,
            'Pending',
        ]);
        $appId = (int) $pdo->lastInsertId();

        $priceStmt = $pdo->prepare(
            'INSERT INTO vendor_application_prices (application_id, item_key, price) VALUES (?, ?, ?)'
        );
        foreach ($lines as $itemKey => $price) {
            $priceStmt->execute([$appId, $itemKey, $price]);
        }

        create_document('Vendor quotation', $code, 'Pending');
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        json_error('failed to submit quotation: ' . $e->getMessage(), 500);
    }

    echo json_encode(format_application($pdo, $pdo->query(
        "SELECT * FROM vendor_applications WHERE id = $appId"
    )->fetch()));
    exit;
}

if ($method === 'PUT') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) {
        json_error('missing required query param: id');
    }

    $row = $pdo->query("SELECT * FROM vendor_applications WHERE id = $id")->fetch();
    if (!$row) {
        json_error('unknown application', 404);
    }
    if ($row['status'] !== 'Pending') {
        json_error("application is '{$row['status']}', not Pending", 409);
    }

    require_manager_or_above();
    $body = read_json_body();
    $action = $body['action'] ?? '';

    if ($action === 'reject') {
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "UPDATE vendor_applications SET status = 'Rejected', reviewed_at = NOW() WHERE id = ?"
            )->execute([$id]);
            update_document('Vendor quotation', $row['application_code'], 'Rejected');
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            json_error('failed to reject: ' . $e->getMessage(), 500);
        }
        echo json_encode(format_application($pdo, $pdo->query(
            "SELECT * FROM vendor_applications WHERE id = $id"
        )->fetch()));
        exit;
    }

    if ($action === 'approve') {
        $prices = fetch_prices($pdo, $id);
        if (empty($prices)) {
            json_error('application has no prices', 400);
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT id FROM suppliers WHERE name = ? LIMIT 1');
            $stmt->execute([$row['company_name']]);
            $supplierId = $stmt->fetchColumn();

            if ($supplierId) {
                $supplierId = (int) $supplierId;
                $pdo->prepare(
                    'UPDATE suppliers SET contact = COALESCE(?, contact), address = COALESCE(?, address), procurement_methods = ?, notes = COALESCE(?, notes), active = 1 WHERE id = ?'
                )->execute([
                    $row['contact'],
                    $row['address'] ?? null,
                    $row['procurement_methods'],
                    $row['notes'],
                    $supplierId,
                ]);
            } else {
                $rating = isset($body['rating']) ? (float) $body['rating'] : null;
                $pdo->prepare(
                    'INSERT INTO suppliers (name, contact, address, rating, procurement_methods, notes)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([
                    $row['company_name'],
                    $row['contact'],
                    $row['address'] ?? null,
                    $rating,
                    $row['procurement_methods'],
                    $row['notes'],
                ]);
                $supplierId = (int) $pdo->lastInsertId();
            }

            $priceStmt = $pdo->prepare(
                'INSERT INTO supplier_prices (supplier_id, item_key, price, last_purchase_date)
                 VALUES (?, ?, ?, CURDATE())
                 ON DUPLICATE KEY UPDATE price = VALUES(price), last_purchase_date = VALUES(last_purchase_date)'
            );
            foreach ($prices as $line) {
                $priceStmt->execute([$supplierId, $line['item_key'], $line['price']]);
            }

            $pdo->prepare(
                "UPDATE vendor_applications
                 SET status = 'Approved', supplier_id = ?, reviewed_at = NOW()
                 WHERE id = ?"
            )->execute([$supplierId, $id]);

            update_document('Vendor quotation', $row['application_code'], 'Approved');
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            json_error('failed to approve: ' . $e->getMessage(), 500);
        }

        $approved = format_application($pdo, $pdo->query(
            "SELECT * FROM vendor_applications WHERE id = $id"
        )->fetch());
        $approved['message'] = 'Vendor added to Supplier Directory.';
        echo json_encode($approved);
        exit;
    }

    json_error("action must be 'approve' or 'reject'");
}

json_error('method not allowed', 405);
