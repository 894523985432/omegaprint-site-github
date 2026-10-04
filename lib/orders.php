<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/clients.php';
require_once __DIR__ . '/bonus.php';

const SHOP_STATUSES = [
    'new'       => 'Нове',
    'confirmed' => 'Підтверджене',
    'shipped'   => 'Відправлене',
    'done'      => 'Виконано',
    'cancelled' => 'Скасоване',
];

const SHOP_DELIVERY = [
    'pickup'      => 'Самовивіз (Київ, вул. Леоніда Первомайського, 9, офіс 12)',
    'np_branch'   => 'Нова пошта — відділення',
    'np_postomat' => 'Нова пошта — поштомат',
    'np_courier'  => "Нова пошта — кур'єр",
];

const SHOP_PAYMENT = [
    'cash'    => 'Готівкою при самовивозі',
    'cod'     => 'Накладений платіж (оплата при отриманні)',
    'invoice' => 'Безготівковий рахунок',
];

function shopPaymentsFor(string $delivery): array
{
    return $delivery === 'pickup' ? ['cash', 'invoice'] : ['cod', 'invoice'];
}

function field(array $src, string $key, int $max): string
{
    return mb_substr(trim(mb_scrub((string)($src[$key] ?? ''), 'UTF-8')), 0, $max);
}

function shopOrderCreate(PDO $pdo, array $post, ?array $user = null): array
{
    $o = $post['Order'] ?? [];
    $phone = rtrim(str_replace('_', '', field($o, 'phone', 40)), ' (-');
    $data = [
        'last_name'   => field($o, 'f_name', 80),
        'first_name'  => field($o, 'username', 80),
        'middle_name' => field($o, 'l_name', 80),
        'phone'       => $phone,
        'email'       => field($o, 'email', 120),
        'comment'     => field($o, 'comment', 2000),
        'delivery'    => (string)($post['delivery'] ?? ''),
        'payment'     => (string)($post['payment'] ?? ''),
        'np_city'     => field($post, 'np_city', 200),
        'np_city_ref' => field($post, 'np_city_ref', 64),
        'np_warehouse'     => field($post, 'np_warehouse', 300),
        'np_warehouse_ref' => field($post, 'np_warehouse_ref', 64),
        'call_back'   => empty($post['call_back_flag']) ? 0 : 1,
    ];
    $street = field($post, 'np_street', 200);
    $house  = field($post, 'np_house', 20);
    $flat   = field($post, 'np_flat', 20);

    $errors = [];
    if ($data['last_name'] === '')  $errors['f_name'] = 'Вкажіть прізвище';
    if ($data['first_name'] === '') $errors['username'] = "Вкажіть ім'я";
    if (strlen(preg_replace('/\D/', '', $phone)) < 12) $errors['phone'] = 'Вкажіть телефон повністю';
    if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Некоректний email';
    if (!isset(SHOP_DELIVERY[$data['delivery']])) $errors['delivery'] = 'Оберіть спосіб доставки';
    elseif (!in_array($data['payment'], shopPaymentsFor($data['delivery']), true)) {
        $errors['payment'] = $data['payment'] === 'cash' ? 'Готівкою можна оплатити лише при самовивозі' : 'Оберіть спосіб оплати';
    }
    if (str_starts_with($data['delivery'], 'np_')) {
        if ($data['np_city'] === '') $errors['np_city'] = 'Оберіть місто';
        if ($data['delivery'] === 'np_courier') {
            if ($street === '' || $house === '') $errors['np_street'] = 'Вкажіть вулицю і будинок';
            $data['address'] = trim("$street, буд. $house" . ($flat !== '' ? ", кв. $flat" : ''));
            $data['np_warehouse'] = $data['np_warehouse_ref'] = '';
        } elseif ($data['np_warehouse'] === '') {
            $errors['np_warehouse'] = $data['delivery'] === 'np_postomat' ? 'Оберіть поштомат' : 'Оберіть відділення';
        }
    } else {
        $data['np_city'] = $data['np_city_ref'] = $data['np_warehouse'] = $data['np_warehouse_ref'] = '';
    }
    $data['address'] = $data['address'] ?? '';

    $qty = [];
    foreach ((array)json_decode((string)($post['cart'] ?? ''), true) as $id => $n) {
        $id = (int)$id;
        $n = max(0, min(999, (int)$n));
        if ($id > 0 && $n > 0) $qty[$id] = $n;
    }
    $items = [];
    $total = 0.0;
    if ($qty) {
        $st = $pdo->prepare('SELECT id, sku, name, price, availability FROM products WHERE id IN (' . implode(',', array_fill(0, count($qty), '?')) . ')');
        $st->execute(array_keys($qty));
        foreach ($st->fetchAll() as $p) {
            if ($p['availability'] === 'absent') continue;
            $n = $qty[(int)$p['id']];
            $items[] = ['product_id' => (int)$p['id'], 'sku' => $p['sku'], 'name' => $p['name'], 'qty' => $n, 'price' => (float)$p['price']];
            $total += $p['price'] * $n;
        }
    }
    if (!$items) $errors['cart'] = 'Кошик порожній або товари більше не продаються.';
    if ($errors) return ['ok' => false, 'errors' => $errors];

    $pdo->beginTransaction();
    $data['client_id'] = clientUpsert($pdo, $phone, $data['email'], trim($data['first_name'] . ' ' . $data['last_name']));
    $data['total'] = round($total, 2);
    $bonus = 0.0;
    if ($user) {
        $data['user_id'] = (int)$user['id'];
        $bonus = bonusSpendable($pdo, (int)$user['id'], $data['total'], $post['bonus_use'] ?? 0);
        $data['bonus_spent'] = $bonus;
    }
    $cols = array_keys($data);
    $pdo->prepare('INSERT INTO shop_orders (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
        ->execute(array_values($data));
    $id = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO shop_order_items (order_id, product_id, sku, name, qty, price) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($items as $i) $ins->execute([$id, $i['product_id'], $i['sku'], $i['name'], $i['qty'], $i['price']]);
    if ($user) {
        if ($bonus > 0) bonusAdd($pdo, (int)$user['id'], $id, -$bonus, 'Оплата бонусами замовлення №' . $id);

        if (!empty($post['save_address']) && str_starts_with($data['delivery'], 'np_')) {
            $saved = ['delivery' => $data['delivery'], 'np_city' => $data['np_city'], 'np_city_ref' => $data['np_city_ref'],
                      'np_settlement_ref' => field($post, 'np_settlement_ref', 64),
                      'np_warehouse' => $data['np_warehouse'], 'np_warehouse_ref' => $data['np_warehouse_ref'],
                      'np_street' => $street, 'np_house' => $house, 'np_flat' => $flat];
            $pdo->prepare('UPDATE users SET delivery = ? WHERE id = ?')
                ->execute([json_encode($saved, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), (int)$user['id']]);
        }
    }
    $pdo->commit();

    return ['ok' => true, 'id' => $id, 'items' => $items, 'total' => $data['total'], 'order' => $data,
            'bonus_spent' => $bonus, 'to_pay' => round($data['total'] - $bonus, 2)];
}

function shopDeliveryText(array $o): string
{
    $text = SHOP_DELIVERY[$o['delivery']] ?? $o['delivery'];
    if (str_starts_with($o['delivery'], 'np_')) {
        $text .= ': ' . $o['np_city'] . ($o['np_warehouse'] !== '' ? ', ' . $o['np_warehouse'] : '') . ($o['address'] !== '' ? ', ' . $o['address'] : '');
    }
    return $text;
}
