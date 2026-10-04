<?php
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/orders.php';
require dirname(__DIR__) . '/lib/sms.php';

function orderOut(array $o): array
{
    return [
        'id' => (int)$o['id'], 'client_id' => $o['client_id'] === null ? null : (int)$o['client_id'],
        'status' => $o['status'], 'status_name' => SHOP_STATUSES[$o['status']] ?? $o['status'],
        'name' => trim($o['last_name'] . ' ' . $o['first_name'] . ' ' . $o['middle_name']),
        'phone' => $o['phone'], 'email' => $o['email'],
        'delivery' => $o['delivery'], 'delivery_text' => shopDeliveryText($o),
        'payment' => $o['payment'], 'payment_text' => SHOP_PAYMENT[$o['payment']] ?? $o['payment'],
        'comment' => $o['comment'], 'call_back' => (bool)$o['call_back'], 'total' => (float)$o['total'],
        'review_sms_at' => $o['review_sms_at'], 'created_at' => $o['created_at'], 'done_at' => $o['done_at'],
        'user_id' => ($o['user_id'] ?? null) === null ? null : (int)$o['user_id'],
        'bonus_spent' => (float)($o['bonus_spent'] ?? 0), 'bonus_earned' => (float)($o['bonus_earned'] ?? 0),
        'to_pay' => round((float)$o['total'] - (float)($o['bonus_spent'] ?? 0), 2),
    ];
}

startSession();
requireSameOrigin();
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    respond(['ok' => false, 'message' => 'Метод не дозволено.'], 405);
}
requireAdmin();
$action = $_GET['action'] ?? '';
if (in_array($action, ['list', 'get', 'sms_log'], true) !== ($method === 'GET')) {
    respond(['ok' => false, 'message' => 'Метод не дозволено.'], 405);
}

try {
    $pdo = db();
    switch ($action) {
        case 'list':
            $where = [];
            $args = [];
            $status = (string)($_GET['status'] ?? '');
            if ($status === 'open') $where[] = "status IN ('new', 'confirmed', 'shipped')";
            elseif (isset(SHOP_STATUSES[$status])) { $where[] = 'status = ?'; $args[] = $status; }
            $q = trim(mb_scrub((string)($_GET['q'] ?? ''), 'UTF-8'));
            if ($q !== '') {
                $like = '%' . addcslashes(mb_strtolower($q), '%_\\') . '%';
                $digits = preg_replace('/\D/', '', $q);
                $parts = ["ulower(last_name || ' ' || first_name) LIKE ? ESCAPE '\\'", "ulower(email) LIKE ? ESCAPE '\\'"];
                array_push($args, $like, $like);
                if ($digits !== '') {
                    $parts[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '(', ''), ')', ''), '-', ''), '+', '') LIKE ?";
                    $args[] = "%$digits%";
                    $parts[] = 'id = ?';
                    $args[] = (int)$digits;
                }
                $where[] = '(' . implode(' OR ', $parts) . ')';
            }
            $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            $per = 30;
            $st = $pdo->prepare("SELECT COUNT(*) FROM shop_orders $w");
            $st->execute($args);
            $total = (int)$st->fetchColumn();
            $pages = max(1, (int)ceil($total / $per));
            $page = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
            $st = $pdo->prepare("SELECT * FROM shop_orders $w ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
            $st->execute($args);
            $counts = [];
            foreach ($pdo->query('SELECT status, COUNT(*) n FROM shop_orders GROUP BY status') as $r) $counts[$r['status']] = (int)$r['n'];
            respond(['ok' => true, 'orders' => array_map('orderOut', $st->fetchAll()), 'total' => $total, 'page' => $page,
                     'pages' => $pages, 'counts' => $counts, 'statuses' => SHOP_STATUSES]);

        case 'get':
            $st = $pdo->prepare('SELECT * FROM shop_orders WHERE id = ?');
            $st->execute([(int)($_GET['id'] ?? 0)]);
            $o = $st->fetch();
            if (!$o) respond(['ok' => false, 'message' => 'Замовлення не знайдено.'], 404);
            $st = $pdo->prepare('SELECT product_id, sku, name, qty, price FROM shop_order_items WHERE order_id = ? ORDER BY id');
            $st->execute([$o['id']]);
            respond(['ok' => true, 'order' => orderOut($o), 'items' => $st->fetchAll()]);

        case 'status':
            $id = (int)($_POST['id'] ?? 0);
            $status = (string)($_POST['status'] ?? '');
            if (!isset(SHOP_STATUSES[$status])) respond(['ok' => false, 'message' => 'Невідомий статус.'], 422);
            $st = $pdo->prepare("UPDATE shop_orders SET status = ?, updated_at = CURRENT_TIMESTAMP,
                                        done_at = CASE WHEN ? = 'done' THEN COALESCE(done_at, CURRENT_TIMESTAMP) ELSE NULL END WHERE id = ?");
            $st->execute([$status, $status, $id]);
            if (!$st->rowCount()) respond(['ok' => false, 'message' => 'Замовлення не знайдено.'], 404);
            bonusOnStatus($pdo, $id, $status);
            $sms = $status === 'done' ? requestReview($pdo, 'shop', $id) : null;
            respond(['ok' => true, 'sms' => $sms]);

        case 'review_sms':
            $type = ($_POST['type'] ?? '') === 'repair' ? 'repair' : 'shop';
            $sms = requestReview($pdo, $type, (int)($_POST['id'] ?? 0), true);
            if (!$sms) respond(['ok' => false, 'message' => 'Замовлення не знайдено.'], 404);
            respond(['ok' => true, 'sms' => $sms]);

        case 'sms_log':
            $rows = $pdo->query('SELECT * FROM sms_log ORDER BY id DESC LIMIT 200')->fetchAll();
            respond(['ok' => true, 'log' => $rows, 'configured' => trim((string)config('sms_token', '')) !== '' && trim((string)config('sms_sender', '')) !== '',
                     'site_url' => siteUrl()]);

        default:
            respond(['ok' => false, 'message' => 'Невідома дія.'], 400);
    }
} catch (Throwable $e) {
    error_log('api/orders.php: ' . $e->getMessage());
    respond(['ok' => false, 'message' => 'Помилка сервера. Спробуйте пізніше.'], 500);
}
