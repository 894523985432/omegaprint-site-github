<?php
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/orders.php';

startSession();
requireSameOrigin();
$action = $_GET['action'] ?? '';
$isRead = in_array($action, ['profile', 'orders'], true);
if ($isRead !== ($_SERVER['REQUEST_METHOD'] === 'GET')) {
    respond(['ok' => false, 'message' => 'Метод не дозволено.'], 405);
}
if (!$isRead && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    respond(['ok' => false, 'message' => 'Метод не дозволено.'], 405);
}
$user = currentUser();
if (!$user) respond(['ok' => false, 'message' => 'Увійдіть у свій обліковий запис.'], 401);

function accountRow(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch();
}

function accountProfile(PDO $pdo, int $id): array
{
    $u = accountRow($pdo, $id);
    $st = $pdo->prepare('SELECT order_id, delta, reason, created_at FROM bonus_log WHERE user_id = ? ORDER BY id DESC LIMIT 50');
    $st->execute([$id]);
    return [
        'profile' => [
            'username' => $u['username'], 'last_name' => $u['last_name'], 'middle_name' => $u['middle_name'],
            'birth_date' => $u['birth_date'], 'gender' => $u['gender'], 'phone' => $u['phone'], 'email' => $u['email'],
        ],
        'delivery' => userDelivery($u) ?: null,
        'delivery_text' => userDelivery($u) ? shopDeliveryText(userDelivery($u) + ['address' => accountAddress(userDelivery($u))]) : '',
        'bonus' => ['balance' => bonusBalance($pdo, $id), 'level' => bonusLevel($pdo, $id), 'tiers' => BONUS_TIERS,
                    'max_earn' => BONUS_MAX_EARN, 'spend_share' => BONUS_SPEND_SHARE,
                    'log' => array_map(fn($r) => ['order_id' => $r['order_id'] === null ? null : (int)$r['order_id'], 'delta' => (float)$r['delta'],
                                                   'reason' => $r['reason'], 'created_at' => $r['created_at']], $st->fetchAll())],
    ];
}

function accountAddress(array $d): string
{
    if (($d['delivery'] ?? '') !== 'np_courier' || ($d['np_street'] ?? '') === '') return '';
    return trim($d['np_street'] . ', буд. ' . ($d['np_house'] ?? '') . (($d['np_flat'] ?? '') !== '' ? ', кв. ' . $d['np_flat'] : ''));
}

try {
    $pdo = db();
    $uid = (int)$user['id'];

    switch ($action) {
        case 'profile':
            respond(['ok' => true] + accountProfile($pdo, $uid));

        case 'orders':

            $st = $pdo->prepare('SELECT * FROM shop_orders WHERE user_id = ? ORDER BY id DESC LIMIT 100');
            $st->execute([$uid]);
            $items = $pdo->prepare('SELECT product_id, name, qty, price FROM shop_order_items WHERE order_id = ? ORDER BY id');
            $orders = [];
            foreach ($st->fetchAll() as $o) {
                $items->execute([$o['id']]);
                $orders[] = [
                    'id' => (int)$o['id'], 'status' => $o['status'], 'status_name' => SHOP_STATUSES[$o['status']] ?? $o['status'],
                    'created_at' => $o['created_at'], 'total' => (float)$o['total'], 'bonus_spent' => (float)$o['bonus_spent'],
                    'bonus_earned' => (float)$o['bonus_earned'], 'to_pay' => round((float)$o['total'] - (float)$o['bonus_spent'], 2),
                    'delivery_text' => shopDeliveryText($o), 'payment_text' => SHOP_PAYMENT[$o['payment']] ?? $o['payment'],
                    'items' => array_map(fn($i) => ['product_id' => $i['product_id'] === null ? null : (int)$i['product_id'], 'name' => $i['name'],
                                                    'qty' => (int)$i['qty'], 'price' => (float)$i['price']], $items->fetchAll()),
                ];
            }
            $st = $pdo->prepare('SELECT o.id, o.device_type, o.device_brand, o.device_model, o.created_at, o.closed_at,
                                        s.name AS status_name, s.color AS status_color
                                 FROM repair_orders o LEFT JOIN repair_statuses s ON s.id = o.status_id
                                 WHERE o.user_id = ? ORDER BY o.id DESC LIMIT 100');
            $st->execute([$uid]);
            $repairs = array_map(fn($r) => [
                'id' => (int)$r['id'], 'device' => trim($r['device_type'] . ' ' . $r['device_brand'] . ' ' . $r['device_model']),
                'created_at' => $r['created_at'], 'closed' => $r['closed_at'] !== null,
                'status_name' => $r['status_name'] ?? 'Прийнято', 'status_color' => $r['status_color'] ?? '',
            ], $st->fetchAll());
            respond(['ok' => true, 'orders' => $orders, 'repairs' => $repairs]);

        case 'profile_save':
            $name   = postText('username');
            $last   = postText('last_name');
            $middle = postText('middle_name');
            $birth  = postText('birth_date');
            $gender = postText('gender');
            $phone  = postText('phone');

            $errors = [];
            if ($name === '')                 $errors['username'] = "Вкажіть ім'я";
            elseif (mb_strlen($name) > 32)    $errors['username'] = 'Перевищена допустима довжина';
            if (mb_strlen($last) > 60)        $errors['last_name'] = 'Перевищена допустима довжина';
            if (mb_strlen($middle) > 60)      $errors['middle_name'] = 'Перевищена допустима довжина';
            if ($birth !== '') {
                $d = DateTime::createFromFormat('!Y-m-d', $birth);
                if (!$d || $d->format('Y-m-d') !== $birth || $birth < '1900-01-01' || $birth > gmdate('Y-m-d')) $errors['birth_date'] = 'Некоректна дата';
            }
            if (!in_array($gender, ['', 'm', 'f'], true)) $errors['gender'] = 'Оберіть зі списку';
            if (strlen(preg_replace('/\D/', '', $phone)) < 10) $errors['phone'] = 'Вкажіть номер телефону';
            elseif (mb_strlen($phone) > 40)   $errors['phone'] = 'Перевищена допустима довжина';
            if ($errors) respond(['ok' => false, 'errors' => $errors], 422);

            $pdo->prepare('UPDATE users SET username = ?, last_name = ?, middle_name = ?, birth_date = ?, gender = ?, phone = ? WHERE id = ?')
                ->execute([$name, $last, $middle, $birth, $gender, $phone, $uid]);
            respond(['ok' => true, 'user' => currentUser()] + accountProfile($pdo, $uid));

        case 'password':
            $row = accountRow($pdo, $uid);
            $new = (string)($_POST['password'] ?? '');
            $errors = [];
            if (!password_verify((string)($_POST['current'] ?? ''), $row['password_hash'])) $errors['current'] = 'Невірний поточний пароль';
            if (strlen($new) < 6)                              $errors['password'] = 'Пароль має містити щонайменше 6 символів';
            if ($new !== (string)($_POST['repeat'] ?? ''))     $errors['repeat'] = 'Паролі не збігаються';
            if ($errors) {

                if (isset($errors['current'])) sleep(1);
                respond(['ok' => false, 'errors' => $errors], 422);
            }
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
            session_regenerate_id(true);
            respond(['ok' => true]);

        case 'address_save':
            $d = ['delivery' => (string)($_POST['delivery'] ?? ''), 'np_city' => field($_POST, 'np_city', 200),
                  'np_city_ref' => field($_POST, 'np_city_ref', 64), 'np_settlement_ref' => field($_POST, 'np_settlement_ref', 64),
                  'np_warehouse' => field($_POST, 'np_warehouse', 300), 'np_warehouse_ref' => field($_POST, 'np_warehouse_ref', 64),
                  'np_street' => field($_POST, 'np_street', 200), 'np_house' => field($_POST, 'np_house', 20), 'np_flat' => field($_POST, 'np_flat', 20)];
            $errors = [];
            if (!in_array($d['delivery'], ['np_branch', 'np_postomat', 'np_courier'], true)) $errors['delivery'] = 'Оберіть спосіб доставки';
            if ($d['np_city'] === '' || $d['np_city_ref'] === '') $errors['np_city'] = 'Оберіть місто зі списку';
            if ($d['delivery'] === 'np_courier') {
                if ($d['np_street'] === '' || $d['np_house'] === '') $errors['np_street'] = 'Вкажіть вулицю і номер будинку';
                $d['np_warehouse'] = $d['np_warehouse_ref'] = '';
            } else {
                if ($d['np_warehouse'] === '' || $d['np_warehouse_ref'] === '') {
                    $errors['np_warehouse'] = $d['delivery'] === 'np_postomat' ? 'Оберіть поштомат зі списку' : 'Оберіть відділення зі списку';
                }
                $d['np_street'] = $d['np_house'] = $d['np_flat'] = '';
            }
            if ($errors) respond(['ok' => false, 'errors' => $errors], 422);
            $pdo->prepare('UPDATE users SET delivery = ? WHERE id = ?')
                ->execute([json_encode($d, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $uid]);
            respond(['ok' => true] + accountProfile($pdo, $uid));

        case 'address_delete':
            $pdo->prepare("UPDATE users SET delivery = '' WHERE id = ?")->execute([$uid]);
            respond(['ok' => true] + accountProfile($pdo, $uid));

        default:
            respond(['ok' => false, 'message' => 'Невідома дія.'], 400);
    }
} catch (Throwable $e) {
    error_log('api/account.php: ' . $e->getMessage());
    respond(['ok' => false, 'message' => 'Помилка сервера. Спробуйте пізніше.'], 500);
}
