<?php
require_once __DIR__ . '/clients.php';

const BONUS_TIERS = [
    ['key' => 'new',      'name' => 'Новий клієнт',     'over' => 0,     'percent' => 5],
    ['key' => 'basic',    'name' => 'Базовий клієнт',   'over' => 1000,  'percent' => 6],
    ['key' => 'constant', 'name' => 'Постійний клієнт', 'over' => 10000, 'percent' => 7],
    ['key' => 'valuable', 'name' => 'Цінний клієнт',    'over' => 25000, 'percent' => 8],
    ['key' => 'vip',      'name' => 'VIP-клієнт',       'over' => 75000, 'percent' => 9],
];

const BONUS_MAX_EARN = 8000;

const BONUS_SPEND_SHARE = 0.5;

function bonusSchema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS bonus_log (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        order_id   INTEGER,
        delta      REAL NOT NULL,
        reason     TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS bonus_log_user ON bonus_log (user_id)');

    addColumn($pdo, 'shop_orders', 'user_id', 'INTEGER REFERENCES users(id) ON DELETE SET NULL');
    addColumn($pdo, 'shop_orders', 'bonus_spent', 'REAL NOT NULL DEFAULT 0');
    addColumn($pdo, 'shop_orders', 'bonus_earned', 'REAL NOT NULL DEFAULT 0');
    addColumn($pdo, 'shop_orders', 'bonus_refunded', 'INTEGER NOT NULL DEFAULT 0');
    addColumn($pdo, 'repair_orders', 'user_id', 'INTEGER REFERENCES users(id) ON DELETE SET NULL');

    addColumn($pdo, 'users', 'last_name', "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'users', 'middle_name', "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'users', 'birth_date', "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'users', 'gender', "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'users', 'delivery', "TEXT NOT NULL DEFAULT ''");
}

function bonusBalance(PDO $pdo, int $userId): float
{
    $st = $pdo->prepare('SELECT COALESCE(SUM(delta), 0) FROM bonus_log WHERE user_id = ?');
    $st->execute([$userId]);
    return round((float)$st->fetchColumn(), 2);
}

function bonusAdd(PDO $pdo, int $userId, ?int $orderId, float $delta, string $reason): void
{
    if (abs($delta) < 0.005) return;
    $pdo->prepare('INSERT INTO bonus_log (user_id, order_id, delta, reason) VALUES (?, ?, ?, ?)')
        ->execute([$userId, $orderId, round($delta, 2), $reason]);
}

function bonusLevel(PDO $pdo, int $userId, ?int $exceptOrderId = null): array
{
    $st = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM shop_orders WHERE user_id = ? AND status = 'done' AND id <> ?");
    $st->execute([$userId, (int)$exceptOrderId]);
    return bonusTier((float)$st->fetchColumn());
}

function bonusTier(float $sum): array
{
    $at = 0;
    foreach (BONUS_TIERS as $i => $tier) {
        if ($sum > $tier['over']) $at = $i;
    }
    $next = BONUS_TIERS[$at + 1] ?? null;
    return ['percent' => BONUS_TIERS[$at]['percent'], 'category' => BONUS_TIERS[$at]['key'], 'category_name' => BONUS_TIERS[$at]['name'],
            'sum' => round($sum, 2), 'next' => $next ? round($next['over'] - $sum, 2) : null, 'next_percent' => $next['percent'] ?? null];
}

function bonusSpendable(PDO $pdo, int $userId, float $total, $requested): float
{
    $want = is_numeric($requested) ? (float)$requested : 0.0;
    return (float)max(0, floor(min($want, bonusBalance($pdo, $userId), $total * BONUS_SPEND_SHARE)));
}

function bonusOnStatus(PDO $pdo, int $orderId, string $status): void
{
    $st = $pdo->prepare('SELECT id, user_id, total, bonus_spent, bonus_earned, bonus_refunded FROM shop_orders WHERE id = ?');
    $st->execute([$orderId]);
    $o = $st->fetch();
    if (!$o || $o['user_id'] === null) return;
    $userId = (int)$o['user_id'];
    $spent = (float)$o['bonus_spent'];
    $earned = (float)$o['bonus_earned'];

    if ($status === 'done' && $earned == 0) {
        $level = bonusLevel($pdo, $userId, $orderId);
        $sum = min(BONUS_MAX_EARN, floor(max(0, (float)$o['total'] - $spent) * $level['percent'] / 100));
        if ($sum > 0) {
            bonusAdd($pdo, $userId, $orderId, $sum, 'Нараховано ' . $level['percent'] . '% за замовлення №' . $orderId);
            $pdo->prepare('UPDATE shop_orders SET bonus_earned = ? WHERE id = ?')->execute([$sum, $orderId]);
        }
    } elseif ($status !== 'done' && $earned > 0) {
        bonusAdd($pdo, $userId, $orderId, -$earned, 'Скасовано нарахування за замовлення №' . $orderId);
        $pdo->prepare('UPDATE shop_orders SET bonus_earned = 0 WHERE id = ?')->execute([$orderId]);
    }

    if ($spent > 0) {
        if ($status === 'cancelled' && !$o['bonus_refunded']) {
            bonusAdd($pdo, $userId, $orderId, $spent, 'Повернено бонуси за скасоване замовлення №' . $orderId);
            $pdo->prepare('UPDATE shop_orders SET bonus_refunded = 1 WHERE id = ?')->execute([$orderId]);
        } elseif ($status !== 'cancelled' && $o['bonus_refunded']) {
            bonusAdd($pdo, $userId, $orderId, -$spent, 'Оплата бонусами замовлення №' . $orderId);
            $pdo->prepare('UPDATE shop_orders SET bonus_refunded = 0 WHERE id = ?')->execute([$orderId]);
        }
    }
}

function sessionUser(): ?array
{
    if (empty($_COOKIE[session_name()])) return null;
    return currentUser();
}

function userDelivery(array $userRow): array
{
    $d = json_decode((string)($userRow['delivery'] ?? ''), true);
    return is_array($d) ? $d : [];
}
