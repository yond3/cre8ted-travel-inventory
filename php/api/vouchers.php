<?php
/**
 * GET  /api/vouchers.php              -> list voucher types and quantities
 * POST /api/vouchers.php              -> create a voucher type
 * PUT  /api/vouchers.php?id=<id>      -> edit, redeem one, or restock
 *
 * PUT actions:
 *   { action: "use_one" }
 *   { action: "restock", qty: 10 }
 *   { action: "edit", label, tour_package, current_qty, notes }
 */
require __DIR__ . '/config.php';
block_department_user();

$pdo = get_pdo();
$method = $_SERVER['REQUEST_METHOD'];

function fetch_voucher(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM tour_vouchers WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function format_voucher(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'voucher_key' => $row['voucher_key'],
        'label' => $row['label'],
        'tour_package' => $row['tour_package'],
        'current_qty' => (int) $row['current_qty'],
        'notes' => $row['notes'],
        'last_added_qty' => $row['last_added_qty'] !== null ? (int) $row['last_added_qty'] : null,
        'last_restocked_at' => $row['last_restocked_at'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}

if ($method === 'GET') {
    require_auth();
    $rows = $pdo->query('SELECT * FROM tour_vouchers ORDER BY label')->fetchAll();
    echo json_encode(array_map('format_voucher', $rows));
    exit;
}

if ($method === 'POST') {
    require_manager_or_above();
    $body = read_json_body();
    $label = trim($body['label'] ?? '');
    $currentQty = (int) ($body['current_qty'] ?? 0);

    if ($label === '') {
        json_error('name is required');
    }
    if ($currentQty < 0) {
        json_error('current quantity must be 0 or greater');
    }

    $voucherKey = slugify($label);
    $base = $voucherKey;
    $suffix = 2;
    $exists = $pdo->prepare('SELECT 1 FROM tour_vouchers WHERE voucher_key = ?');
    do {
        $exists->execute([$voucherKey]);
        if (!$exists->fetchColumn()) {
            break;
        }
        $voucherKey = $base . $suffix++;
    } while (true);

    $stmt = $pdo->prepare(
        'INSERT INTO tour_vouchers
         (voucher_key, label, tour_package, current_qty, notes, last_added_qty, last_restocked_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $voucherKey,
        $label,
        trim($body['tour_package'] ?? '') ?: null,
        $currentQty,
        trim($body['notes'] ?? '') ?: null,
        $currentQty > 0 ? $currentQty : null,
        $currentQty > 0 ? date('Y-m-d H:i:s') : null,
    ]);

    echo json_encode(format_voucher(fetch_voucher($pdo, (int) $pdo->lastInsertId())));
    exit;
}

if ($method === 'PUT') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) {
        json_error('missing required query param: id');
    }

    $voucher = fetch_voucher($pdo, $id);
    if (!$voucher) {
        json_error('unknown voucher', 404);
    }

    $body = read_json_body();
    $action = $body['action'] ?? 'edit';

    if ($action === 'use_one') {
        require_staff_or_above();
        if ((int) $voucher['current_qty'] <= 0) {
            json_error('No vouchers left to use', 409);
        }
        $pdo->prepare('UPDATE tour_vouchers SET current_qty = current_qty - 1 WHERE id = ?')
            ->execute([$id]);
    } elseif ($action === 'restock') {
        require_manager_or_above();
        $qty = (int) ($body['qty'] ?? 0);
        if ($qty <= 0) {
            json_error('restock quantity must be greater than 0');
        }
        $pdo->prepare(
            'UPDATE tour_vouchers
             SET current_qty = current_qty + ?, last_added_qty = ?, last_restocked_at = NOW()
             WHERE id = ?'
        )->execute([$qty, $qty, $id]);
    } elseif ($action === 'edit') {
        require_super_admin();
        $label = trim($body['label'] ?? '');
        $currentQty = (int) ($body['current_qty'] ?? -1);
        if ($label === '') {
            json_error('name is required');
        }
        if ($currentQty < 0) {
            json_error('current quantity must be 0 or greater');
        }

        $stmt = $pdo->prepare(
            'UPDATE tour_vouchers
             SET label = ?, tour_package = ?, current_qty = ?, notes = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $label,
            trim($body['tour_package'] ?? '') ?: null,
            $currentQty,
            trim($body['notes'] ?? '') ?: null,
            $id,
        ]);
    } else {
        json_error("action must be 'use_one', 'restock', or 'edit'");
    }

    echo json_encode(format_voucher(fetch_voucher($pdo, $id)));
    exit;
}

json_error('method not allowed', 405);
