<?php
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/clients.php';

startSession();
requireSameOrigin();
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    respond(['ok' => false, 'message' => 'Метод не дозволено.'], 405);
}
requireAdmin();
$action = $_GET['action'] ?? '';

function filtered(array $clients): array
{
    $category = (string)($_GET['category'] ?? '');
    $q = mb_strtolower(trim(mb_scrub((string)($_GET['q'] ?? ''), 'UTF-8')));
    $digits = preg_replace('/\D/', '', $q);
    return array_values(array_filter($clients, function ($c) use ($category, $q, $digits) {
        if ($category !== '' && $c['category'] !== $category) return false;
        if ($q === '') return true;
        return str_contains(mb_strtolower($c['name'] . ' ' . $c['email'] . ' ' . $c['company']), $q)
            || ($digits !== '' && str_contains(preg_replace('/\D/', '', $c['phone']), $digits));
    }));
}

try {
    $pdo = db();
    switch ($action) {
        case 'list':
            $all = clientsWithStats($pdo);
            $counts = array_fill_keys(array_keys(CLIENT_CATEGORIES), 0);
            foreach ($all as $c) $counts[$c['category']]++;
            respond(['ok' => true, 'clients' => filtered($all), 'counts' => $counts, 'categories' => CLIENT_CATEGORIES]);

        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            $client = clientsWithStats($pdo, $id)[0] ?? null;
            if (!$client) respond(['ok' => false, 'message' => 'Клієнта не знайдено.'], 404);
            $st = $pdo->prepare('SELECT id, status, total, created_at FROM shop_orders WHERE client_id = ? ORDER BY id DESC');
            $st->execute([$id]);
            $shop = $st->fetchAll();
            $st = $pdo->prepare('SELECT o.id, s.name AS status, o.device_type, o.device_brand, o.device_model, o.created_at,
                                        (SELECT COALESCE(SUM(qty * price), 0) FROM repair_items i WHERE i.order_id = o.id) AS total
                                 FROM repair_orders o LEFT JOIN repair_statuses s ON s.id = o.status_id WHERE o.client_id = ? ORDER BY o.id DESC');
            $st->execute([$id]);
            respond(['ok' => true, 'client' => $client, 'shop_orders' => $shop, 'repair_orders' => $st->fetchAll()]);

        case 'save':
            $id = (int)($_POST['id'] ?? 0);
            $email = mb_strtolower(postText('email'));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['ok' => false, 'errors' => ['email' => 'Некоректний email']], 422);
            $st = $pdo->prepare('UPDATE clients SET name = ?, email = ?, company = ?, notes = ? WHERE id = ?');
            $st->execute([mb_substr(postText('name'), 0, 120), $email, mb_substr(postText('company'), 0, 200), mb_substr(postText('notes'), 0, 4000), $id]);
            respond(['ok' => true]);

        case 'bans':
            require_once dirname(__DIR__) . '/lib/antispam.php';
            antispamBanSchema($pdo);
            $bans = $pdo->query('SELECT who, reason, created_at FROM ip_bans ORDER BY created_at DESC')->fetchAll();
            $acc = $pdo->prepare('SELECT username, email, created_at FROM users WHERE reg_who = ? ORDER BY id DESC LIMIT 30');
            foreach ($bans as &$b) {
                $acc->execute([$b['who']]);
                $b['accounts'] = $acc->fetchAll();
            }
            respond(['ok' => true, 'bans' => $bans]);

        case 'unban':
            require_once dirname(__DIR__) . '/lib/antispam.php';
            antispamBanSchema($pdo);
            $who = (string)($_POST['who'] ?? '');
            $pdo->prepare('DELETE FROM ip_bans WHERE who = ?')->execute([$who]);

            $pdo->prepare("UPDATE users SET reg_who = '' WHERE reg_who = ?")->execute([$who]);
            respond(['ok' => true]);

        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare('DELETE FROM clients WHERE id = ?');
            $st->execute([$id]);
            if (!$st->rowCount()) respond(['ok' => false, 'message' => 'Клієнта не знайдено.'], 404);

            $pdo->prepare('UPDATE shop_orders SET client_id = NULL WHERE client_id = ?')->execute([$id]);
            $pdo->prepare('UPDATE repair_orders SET client_id = NULL WHERE client_id = ?')->execute([$id]);
            respond(['ok' => true]);

        case 'export':
            $rows = filtered(clientsWithStats($pdo));
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="clients-' . date('Y-m-d') . '.csv"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ["Ім'я", 'Телефон', 'Email', 'Організація', 'Категорія', 'Замовлень усього', 'За квартал',
                           'Сума, грн', 'Середній чек, грн', 'Останнє замовлення', 'Примітки'], ';');
            foreach ($rows as $c) {
                fputcsv($out, [$c['name'], $c['phone'], $c['email'], $c['company'], $c['category_name'], $c['orders_total'],
                               $c['orders_quarter'], number_format($c['revenue'], 2, ',', ''), number_format($c['avg_check'], 2, ',', ''),
                               $c['last_order_at'], $c['notes']], ';');
            }
            exit;

        default:
            respond(['ok' => false, 'message' => 'Невідома дія.'], 400);
    }
} catch (Throwable $e) {
    error_log('api/clients.php: ' . $e->getMessage());
    respond(['ok' => false, 'message' => 'Помилка сервера. Спробуйте пізніше.'], 500);
}
