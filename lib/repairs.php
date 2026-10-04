<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

const REPAIR_TEXT_FIELDS = [
    'client_name'   => 120,
    'client_phone'  => 40,
    'client_company'=> 200,
    'edrpou'        => 20,
    'messenger'     => 20,
    'device_type'   => 120,
    'device_brand'  => 120,
    'device_model'  => 120,
    'serial'        => 120,
    'malfunction'   => 4000,
    'complectation' => 1000,
    'appearance'    => 1000,
];

function repairStatus(PDO $pdo, ?int $id): ?array
{
    if (!$id) return null;
    $st = $pdo->prepare('SELECT * FROM repair_statuses WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function repairDefaultStatusId(PDO $pdo): ?int
{
    $id = $pdo->query('SELECT id FROM repair_statuses ORDER BY is_default DESC, sort, id LIMIT 1')->fetchColumn();
    return $id === false ? null : (int)$id;
}

function repairLog(PDO $pdo, int $orderId, ?int $userId, string $text, string $kind = 'system'): void
{
    $pdo->prepare('INSERT INTO repair_comments (order_id, user_id, kind, text) VALUES (?, ?, ?, ?)')
        ->execute([$orderId, $userId, $kind, $text]);
}

function repairCreate(PDO $pdo, array $data, ?int $userId, string $source = 'admin'): int
{
    $statusId = $data['status_id'] ?? null;
    if (!$statusId || !repairStatus($pdo, $statusId)) $statusId = repairDefaultStatusId($pdo);
    $status = repairStatus($pdo, $statusId);

    $cols = array_keys(REPAIR_TEXT_FIELDS);
    $values = array_map(fn($c) => (string)($data[$c] ?? ''), $cols);
    $pdo->prepare('INSERT INTO repair_orders (' . implode(', ', $cols) . ', estimate, prepayment, due_date, status_id, source, created_by, closed_at)
                   VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ', ?, ?, ?, ?, ?, ?, ' . ($status && $status['is_closed'] ? 'CURRENT_TIMESTAMP' : 'NULL') . ')')
        ->execute([...$values, $data['estimate'] ?? null, $data['prepayment'] ?? 0, $data['due_date'] ?? null, $statusId, $source, $userId]);
    $id = (int)$pdo->lastInsertId();
    repairLinkClient($pdo, $id);

    repairLog($pdo, $id, $userId, ($source === 'site' ? 'Заявка з сайту' : ($source === 'telegram' ? 'Заявка з Telegram-бота' : 'Замовлення створено'))
        . ($status ? '. Статус: «' . $status['name'] . '»' : ''));
    return $id;
}

function repairSetStatus(PDO $pdo, int $orderId, ?int $statusId, ?int $userId): bool
{
    $st = $pdo->prepare('SELECT status_id FROM repair_orders WHERE id = ?');
    $st->execute([$orderId]);
    $current = $st->fetchColumn();
    $current = $current === null || $current === false ? null : (int)$current;
    if ($current === $statusId) return false;

    $old = repairStatus($pdo, $current);
    $new = repairStatus($pdo, $statusId);
    $pdo->prepare('UPDATE repair_orders SET status_id = ?, updated_at = CURRENT_TIMESTAMP,
                          closed_at = CASE WHEN ? THEN COALESCE(closed_at, CURRENT_TIMESTAMP) ELSE NULL END
                   WHERE id = ?')
        ->execute([$statusId, $new && $new['is_closed'] ? 1 : 0, $orderId]);
    repairLog($pdo, $orderId, $userId, 'Статус змінено: «' . ($old['name'] ?? 'без статусу') . '» → «' . ($new['name'] ?? 'без статусу') . '»');

    require_once __DIR__ . '/tgbot.php';
    tgNotifyStatus($pdo, $orderId);
    return true;
}

const REPAIR_DICT_KINDS = ['device_type', 'device_brand', 'device_model', 'serial', 'complectation', 'appearance', 'work', 'part'];

function repairDictRemember(PDO $pdo, string $kind, string $value, ?float $price = null): void
{
    $value = trim($value);
    if ($value === '' || mb_strlen($value) > 200 || !in_array($kind, REPAIR_DICT_KINDS, true)) return;

    $st = $pdo->prepare('SELECT 1 FROM repair_dicts WHERE kind = ? AND ulower(value) = ?');
    $st->execute([$kind, mb_strtolower($value)]);
    if ($st->fetchColumn()) return;
    $pdo->prepare('INSERT OR IGNORE INTO repair_dicts (kind, value, price) VALUES (?, ?, ?)')->execute([$kind, $value, $price]);
}

function repairLinkClient(PDO $pdo, int $orderId): void
{
    require_once __DIR__ . '/clients.php';
    $st = $pdo->prepare('SELECT client_phone, client_name, client_company FROM repair_orders WHERE id = ?');
    $st->execute([$orderId]);
    $o = $st->fetch();
    if (!$o) return;
    $clientId = clientUpsert($pdo, $o['client_phone'], '', $o['client_name'], $o['client_company']);
    $pdo->prepare('UPDATE repair_orders SET client_id = ? WHERE id = ?')->execute([$clientId, $orderId]);
}

function repairAfterStatus(PDO $pdo, int $orderId, ?int $statusId, ?int $userId): ?array
{
    $status = repairStatus($pdo, $statusId);
    if (!$status || empty($status['request_review'])) return null;
    require_once __DIR__ . '/sms.php';
    $result = requestReview($pdo, 'repair', $orderId);
    if ($result) {
        $labels = ['sent' => 'SMS з проханням про відгук надіслано', 'not_configured' => 'SMS не надіслано: сервіс SMS не налаштовано (текст у журналі SMS)',
                   'failed' => 'Не вдалося надіслати SMS з проханням про відгук', 'bad_phone' => 'SMS не надіслано: некоректний номер телефону'];
        repairLog($pdo, $orderId, $userId, $labels[$result['status']] ?? 'SMS: ' . $result['status']);
    }
    return $result;
}
