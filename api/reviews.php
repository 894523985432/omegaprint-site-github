<?php
require dirname(__DIR__) . '/lib/bootstrap.php';

const REVIEW_STATUSES = ['new' => 'На модерації', 'published' => 'Опубліковано', 'hidden' => 'Приховано'];

function orderByToken(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-z0-9]{6,20}$/', $token)) return null;
    $st = $pdo->prepare("SELECT id, client_id, first_name, created_at FROM shop_orders WHERE review_token = ?");
    $st->execute([$token]);
    if ($o = $st->fetch()) {
        $items = $pdo->prepare('SELECT name, qty FROM shop_order_items WHERE order_id = ? ORDER BY id');
        $items->execute([$o['id']]);
        $what = implode(', ', array_map(fn($i) => $i['name'] . ($i['qty'] > 1 ? ' × ' . $i['qty'] : ''), $items->fetchAll()));
        return ['type' => 'shop', 'id' => (int)$o['id'], 'client_id' => $o['client_id'], 'name' => $o['first_name'],
                'date' => $o['created_at'], 'what' => $what, 'title' => 'Замовлення в інтернет-магазині'];
    }
    $st = $pdo->prepare("SELECT id, client_id, client_name, device_type, device_brand, device_model, created_at FROM repair_orders WHERE review_token = ?");
    $st->execute([$token]);
    if ($o = $st->fetch()) {
        return ['type' => 'repair', 'id' => (int)$o['id'], 'client_id' => $o['client_id'], 'name' => $o['client_name'],
                'date' => $o['created_at'], 'what' => trim($o['device_type'] . ' ' . $o['device_brand'] . ' ' . $o['device_model']),
                'title' => 'Ремонт у сервісному центрі'];
    }
    return null;
}

function userOrders(PDO $pdo, array $user): array
{
    require_once dirname(__DIR__) . '/lib/clients.php';
    $key = phoneKey($user['phone'] ?? '');
    $email = mb_strtolower(trim($user['email'] ?? ''));
    $ids = [];
    $st = $pdo->prepare("SELECT id FROM clients WHERE (phone_key IS NOT NULL AND phone_key = ?) OR (email <> '' AND email = ?)");
    $st->execute([$key, $email]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if (!$ids) return [];
    $in = implode(',', $ids);
    $orders = [];
    foreach ($pdo->query("SELECT o.id, o.client_id, o.created_at, o.done_at,
                                 (SELECT GROUP_CONCAT(name || CASE WHEN qty > 1 THEN ' × ' || qty ELSE '' END, ', ') FROM shop_order_items i WHERE i.order_id = o.id) AS what
                          FROM shop_orders o WHERE o.status = 'done' AND o.client_id IN ($in)
                            AND NOT EXISTS (SELECT 1 FROM reviews r WHERE r.order_type = 'shop' AND r.order_id = o.id)") as $o) {
        $orders[] = ['type' => 'shop', 'id' => (int)$o['id'], 'client_id' => (int)$o['client_id'], 'date' => $o['done_at'] ?: $o['created_at'],
                     'title' => 'Замовлення в магазині №' . $o['id'], 'what' => (string)$o['what']];
    }
    foreach ($pdo->query("SELECT id, client_id, created_at, closed_at, device_type, device_brand, device_model
                          FROM repair_orders o WHERE closed_at IS NOT NULL AND client_id IN ($in)
                            AND NOT EXISTS (SELECT 1 FROM reviews r WHERE r.order_type = 'repair' AND r.order_id = o.id)") as $o) {
        $orders[] = ['type' => 'repair', 'id' => (int)$o['id'], 'client_id' => (int)$o['client_id'], 'date' => $o['closed_at'],
                     'title' => 'Ремонт №' . str_pad((string)$o['id'], 5, '0', STR_PAD_LEFT),
                     'what' => trim($o['device_type'] . ' ' . $o['device_brand'] . ' ' . $o['device_model'])];
    }
    usort($orders, fn($a, $b) => strcmp((string)$b['date'], (string)$a['date']));
    return $orders;
}

function reviewOut(array $r, bool $admin): array
{
    $out = ['id' => (int)$r['id'], 'name' => $r['name'], 'stars' => (int)$r['stars'], 'text' => $r['text'],
            'pros' => $r['pros'], 'cons' => $r['cons'], 'created_at' => $r['created_at'],
            'verified' => $r['order_type'] !== null, 'order_type' => $r['order_type']];
    if ($admin) {
        $out += ['status' => $r['status'], 'order_id' => $r['order_id'] === null ? null : (int)$r['order_id'],
                 'client_id' => $r['client_id'] === null ? null : (int)$r['client_id']];
    }
    return $out;
}

startSession();
requireSameOrigin();
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    $pdo = db();
    switch ($action) {
        case 'context':
            $o = orderByToken($pdo, (string)($_GET['t'] ?? ''));
            if (!$o) respond(['ok' => false, 'message' => 'Посилання недійсне або застаріле.'], 404);
            $st = $pdo->prepare('SELECT 1 FROM reviews WHERE order_type = ? AND order_id = ?');
            $st->execute([$o['type'], $o['id']]);
            respond(['ok' => true, 'order' => ['number' => $o['id'], 'title' => $o['title'], 'what' => $o['what'], 'date' => $o['date'],
                     'name' => $o['name']], 'already' => (bool)$st->fetchColumn()]);

        case 'my_orders':
            $user = currentUser();
            if (!$user) respond(['ok' => true, 'logged_in' => false, 'orders' => []]);
            $orders = array_map(fn($o) => array_diff_key($o, ['client_id' => 1]), userOrders($pdo, $user));
            respond(['ok' => true, 'logged_in' => true, 'name' => $user['username'], 'orders' => $orders]);

        case 'submit':
            if ($method !== 'POST') respond(['ok' => false, 'message' => 'Метод не дозволено.'], 405);

            $_SESSION['reviews_sent'] = ($_SESSION['reviews_sent'] ?? 0) + 1;
            if ($_SESSION['reviews_sent'] > 5) respond(['ok' => false, 'message' => 'Забагато відгуків. Спробуйте пізніше.'], 429);
            require_once dirname(__DIR__) . '/lib/antispam.php';
            if (antispamBanned($pdo)) respond(['ok' => false, 'message' => 'Надсилання з вашої мережі заблоковано.'], 403);
            if (!antispamLimit($pdo, 'review', [[3, 3600], [10, 86400]])) respond(['ok' => false, 'message' => 'Забагато відгуків. Спробуйте пізніше.'], 429);

            $name = mb_substr(postText('name'), 0, 80);
            $stars = (int)($_POST['stars'] ?? 0);
            $text = mb_substr(str_replace("\r\n", "\n", postText('text')), 0, 3000);
            $errors = [];
            if ($name === '') $errors['name'] = "Вкажіть ім'я";
            if ($stars < 1 || $stars > 5) $errors['stars'] = 'Поставте оцінку';
            if (mb_strlen($text) < 3) $errors['text'] = 'Напишіть кілька слів';
            $order = null;
            if (!empty($_POST['t'])) {
                $order = orderByToken($pdo, (string)$_POST['t']);
                if (!$order) respond(['ok' => false, 'message' => 'Посилання недійсне або застаріле.'], 404);
                $st = $pdo->prepare('SELECT 1 FROM reviews WHERE order_type = ? AND order_id = ?');
                $st->execute([$order['type'], $order['id']]);
                if ($st->fetchColumn()) respond(['ok' => false, 'message' => 'Відгук на це замовлення вже залишено. Дякуємо!'], 409);
            } elseif (!empty($_POST['order_id'])) {

                $user = currentUser();
                if (!$user) respond(['ok' => false, 'message' => 'Увійдіть, щоб залишити відгук на своє замовлення.'], 401);
                $type = ($_POST['order_type'] ?? '') === 'repair' ? 'repair' : 'shop';
                foreach (userOrders($pdo, $user) as $o) {
                    if ($o['type'] === $type && $o['id'] === (int)$_POST['order_id']) { $order = $o; break; }
                }
                if (!$order) respond(['ok' => false, 'message' => 'Це замовлення не знайдено серед ваших виконаних або на нього вже є відгук.'], 422);
            }
            if ($errors) respond(['ok' => false, 'errors' => $errors], 422);
            $pdo->prepare('INSERT INTO reviews (order_type, order_id, client_id, name, stars, text, pros, cons) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$order['type'] ?? null, $order['id'] ?? null, $order['client_id'] ?? null, $name, $stars, $text,
                           mb_substr(postText('pros'), 0, 1000), mb_substr(postText('cons'), 0, 1000)]);
            respond(['ok' => true]);

        case 'published':
            $rows = $pdo->query("SELECT * FROM reviews WHERE status = 'published' ORDER BY id DESC LIMIT 200")->fetchAll();
            $stats = array_fill(1, 5, 0);
            foreach ($rows as $r) $stats[(int)$r['stars']]++;
            $count = count($rows);
            respond(['ok' => true, 'reviews' => array_map(fn($r) => reviewOut($r, false), $rows), 'count' => $count,
                     'average' => $count ? round(array_sum(array_map(fn($r) => (int)$r['stars'], $rows)) / $count, 1) : 0,
                     'by_stars' => $stats]);

        case 'list':
        case 'status':
        case 'delete':
            requireAdmin();
            if ($action === 'list') {
                $status = (string)($_GET['status'] ?? '');
                $st = $pdo->prepare('SELECT * FROM reviews' . (isset(REVIEW_STATUSES[$status]) ? ' WHERE status = ?' : '') . ' ORDER BY id DESC LIMIT 500');
                $st->execute(isset(REVIEW_STATUSES[$status]) ? [$status] : []);
                $counts = [];
                foreach ($pdo->query('SELECT status, COUNT(*) n FROM reviews GROUP BY status') as $r) $counts[$r['status']] = (int)$r['n'];
                respond(['ok' => true, 'reviews' => array_map(fn($r) => reviewOut($r, true), $st->fetchAll()), 'counts' => $counts,
                         'statuses' => REVIEW_STATUSES]);
            }
            if ($method !== 'POST' || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
                respond(['ok' => false, 'message' => 'Метод не дозволено.'], 405);
            }
            $id = (int)($_POST['id'] ?? 0);
            if ($action === 'status') {
                $status = (string)($_POST['status'] ?? '');
                if (!isset(REVIEW_STATUSES[$status])) respond(['ok' => false, 'message' => 'Невідомий статус.'], 422);
                $st = $pdo->prepare('UPDATE reviews SET status = ? WHERE id = ?');
                $st->execute([$status, $id]);
            } else {
                $st = $pdo->prepare('DELETE FROM reviews WHERE id = ?');
                $st->execute([$id]);
            }
            if (!$st->rowCount()) respond(['ok' => false, 'message' => 'Відгук не знайдено.'], 404);
            respond(['ok' => true]);

        default:
            respond(['ok' => false, 'message' => 'Невідома дія.'], 400);
    }
} catch (Throwable $e) {
    error_log('api/reviews.php: ' . $e->getMessage());
    respond(['ok' => false, 'message' => 'Помилка сервера. Спробуйте пізніше.'], 500);
}
