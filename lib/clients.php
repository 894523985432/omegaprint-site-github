<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

const CLIENT_QUARTER_DAYS = 90;

const CLIENT_CATEGORIES = [
    'new'      => 'Новий клієнт',
    'rare'     => 'Рідкісний клієнт',
    'regular'  => 'Регулярний клієнт',
    'constant' => 'Постійний клієнт',
    'vip'      => 'VIP-клієнт',
    'inactive' => 'Неактивний',
];

function phoneKey(string $phone): ?string
{
    $digits = preg_replace('/\D/', '', $phone);
    if (strlen($digits) < 9) return null;
    return '0' . substr($digits, -9);
}

function clientUpsert(PDO $pdo, string $phone, string $email, string $name, string $company = ''): ?int
{
    $key = phoneKey($phone);
    $email = mb_strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
    if (!$key && $email === '') return null;

    $client = null;
    if ($key) {
        $st = $pdo->prepare('SELECT * FROM clients WHERE phone_key = ?');
        $st->execute([$key]);
        $client = $st->fetch();
    }
    if (!$client && $email !== '') {
        $st = $pdo->prepare('SELECT * FROM clients WHERE email = ? ' . ($key ? 'AND phone_key IS NULL' : '') . ' LIMIT 1');
        $st->execute([$email]);
        $client = $st->fetch();
    }

    if ($client) {
        $pdo->prepare("UPDATE clients SET
                          phone_key = COALESCE(phone_key, ?),
                          phone   = CASE WHEN phone = '' THEN ? ELSE phone END,
                          email   = CASE WHEN ? <> '' THEN ? ELSE email END,
                          name    = CASE WHEN ? <> '' THEN ? ELSE name END,
                          company = CASE WHEN ? <> '' THEN ? ELSE company END
                       WHERE id = ?")
            ->execute([$key, trim($phone), $email, $email, trim($name), trim($name), trim($company), trim($company), $client['id']]);
        return (int)$client['id'];
    }
    $pdo->prepare('INSERT INTO clients (phone_key, phone, email, name, company) VALUES (?, ?, ?, ?, ?)')
        ->execute([$key, trim($phone), $email, trim($name), trim($company)]);
    return (int)$pdo->lastInsertId();
}

function clientOrdersSql(): string
{
    return "SELECT client_id, 'shop' AS kind, id, total, created_at FROM shop_orders
                WHERE client_id IS NOT NULL AND status <> 'cancelled'
            UNION ALL
            SELECT o.client_id, 'repair', o.id,
                   (SELECT COALESCE(SUM(qty * price), 0) FROM repair_items i WHERE i.order_id = o.id), o.created_at
                FROM repair_orders o WHERE o.client_id IS NOT NULL";
}

function clientCategory(int $total, int $quarter): string
{
    if ($quarter === 0) return 'inactive';
    if ($total <= 1) return 'new';
    if ($quarter <= 3) return 'rare';
    if ($quarter <= 6) return 'regular';
    if ($quarter <= 12) return 'constant';
    return 'vip';
}

function clientsWithStats(PDO $pdo, ?int $onlyId = null): array
{
    $since = gmdate('Y-m-d H:i:s', time() - CLIENT_QUARTER_DAYS * 86400);
    $sql = "SELECT c.*,
                   COUNT(o.id) AS orders_total,
                   SUM(CASE WHEN o.created_at >= :since THEN 1 ELSE 0 END) AS orders_quarter,
                   COALESCE(SUM(o.total), 0) AS revenue,
                   SUM(CASE WHEN o.total > 0 THEN 1 ELSE 0 END) AS paid_orders,
                   MAX(o.created_at) AS last_order_at
            FROM clients c LEFT JOIN (" . clientOrdersSql() . ") o ON o.client_id = c.id"
        . ($onlyId ? ' WHERE c.id = :id' : '') . "
            GROUP BY c.id ORDER BY last_order_at DESC, c.id DESC";
    $st = $pdo->prepare($sql);
    $params = [':since' => $since];
    if ($onlyId) $params[':id'] = $onlyId;
    $st->execute($params);
    return array_map(function ($c) {
        $total = (int)$c['orders_total'];
        $quarter = (int)$c['orders_quarter'];
        $category = clientCategory($total, $quarter);
        return [
            'id'             => (int)$c['id'],
            'name'           => $c['name'],
            'phone'          => $c['phone'],
            'email'          => $c['email'],
            'company'        => $c['company'],
            'notes'          => $c['notes'],
            'created_at'     => $c['created_at'],
            'orders_total'   => $total,
            'orders_quarter' => $quarter,
            'revenue'        => round((float)$c['revenue'], 2),
            'avg_check'      => (int)$c['paid_orders'] ? round((float)$c['revenue'] / (int)$c['paid_orders'], 2) : 0,
            'last_order_at'  => $c['last_order_at'],
            'category'       => $category,
            'category_name'  => CLIENT_CATEGORIES[$category],
        ];
    }, $st->fetchAll());
}
