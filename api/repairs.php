<?php
require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/repairs.php';

function money($value): ?float
{
    $value = str_replace([' ', ','], ['', '.'], trim((string)$value));
    if ($value === '') return null;
    return is_numeric($value) ? round((float)$value, 2) : NAN;
}

function orderRow(array $o): array
{
    return [
        'id'            => (int)$o['id'],
        'status_id'     => $o['status_id'] === null ? null : (int)$o['status_id'],
        'client_name'   => $o['client_name'],
        'client_phone'  => $o['client_phone'],
        'client_company'=> $o['client_company'],
        'edrpou'        => $o['edrpou'],
        'messenger'     => $o['messenger'],
        'device_type'   => $o['device_type'],
        'device_brand'  => $o['device_brand'],
        'device_model'  => $o['device_model'],
        'serial'        => $o['serial'],
        'malfunction'   => $o['malfunction'],
        'complectation' => $o['complectation'],
        'appearance'    => $o['appearance'],
        'estimate'      => $o['estimate'] === null ? null : (float)$o['estimate'],
        'prepayment'    => (float)$o['prepayment'],
        'due_date'      => $o['due_date'],
        'source'        => $o['source'],
        'created_at'    => $o['created_at'],
        'updated_at'    => $o['updated_at'],
        'closed_at'     => $o['closed_at'],
        'client_id'     => $o['client_id'] === null ? null : (int)$o['client_id'],
        'review_sms_at' => $o['review_sms_at'],
        'total'         => isset($o['total']) ? (float)$o['total'] : null,
    ];
}

function requireOrder(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT * FROM repair_orders WHERE id = ?');
    $st->execute([$id]);
    $o = $st->fetch();
    if (!$o) respond(['ok' => false, 'message' => 'Замовлення не знайдено.'], 404);
    return $o;
}

function touchOrder(PDO $pdo, int $id): void
{
    $pdo->prepare('UPDATE repair_orders SET updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$id]);
}

startSession();
requireSameOrigin();
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    respond(['ok' => false, 'message' => 'Метод не дозволено.'], 405);
}
$user = requireAdmin();
$userId = $user['id'];

$reads = ['statuses', 'orders', 'order', 'dicts', 'device'];
$action = $_GET['action'] ?? '';
if (in_array($action, $reads, true) !== ($method === 'GET')) {
    respond(['ok' => false, 'message' => 'Метод не дозволено.'], 405);
}

try {
    $pdo = db();

    switch ($action) {

        case 'statuses':
            $rows = $pdo->query('SELECT s.*, (SELECT COUNT(*) FROM repair_orders o WHERE o.status_id = s.id) AS orders
                                 FROM repair_statuses s ORDER BY s.sort, s.id')->fetchAll();
            respond(['ok' => true, 'statuses' => array_map(fn($s) => [
                'id'         => (int)$s['id'],
                'name'       => $s['name'],
                'color'      => $s['color'],
                'sort'       => (int)$s['sort'],
                'is_default' => (bool)$s['is_default'],
                'is_closed'  => (bool)$s['is_closed'],
                'request_review' => (bool)$s['request_review'],
                'orders'     => (int)$s['orders'],
            ], $rows)]);

        case 'status_save':
            $id    = (int)($_POST['id'] ?? 0);
            $name  = postText('name');
            $color = strtolower(trim((string)($_POST['color'] ?? '')));
            $errors = [];
            if ($name === '')                $errors['name'] = 'Вкажіть назву статусу';
            elseif (mb_strlen($name) > 60)   $errors['name'] = 'Назва задовга';
            if (!preg_match('/^#[0-9a-f]{6}$/', $color)) $errors['color'] = 'Оберіть колір';
            if ($errors) respond(['ok' => false, 'errors' => $errors], 422);

            $isDefault = empty($_POST['is_default']) ? 0 : 1;
            $isClosed  = empty($_POST['is_closed']) ? 0 : 1;
            $review    = empty($_POST['request_review']) ? 0 : 1;
            $pdo->beginTransaction();
            if ($isDefault) $pdo->exec('UPDATE repair_statuses SET is_default = 0');
            if ($id) {
                $st = $pdo->prepare('UPDATE repair_statuses SET name = ?, color = ?, sort = ?, is_default = ?, is_closed = ?, request_review = ? WHERE id = ?');
                $st->execute([$name, $color, (int)($_POST['sort'] ?? 0), $isDefault, $isClosed, $review, $id]);
                if (!repairStatus($pdo, $id)) { $pdo->rollBack(); respond(['ok' => false, 'message' => 'Статус не знайдено.'], 404); }
            } else {
                $sort = $_POST['sort'] ?? '';
                if ($sort === '') $sort = (int)$pdo->query('SELECT COALESCE(MAX(sort), 0) + 1 FROM repair_statuses')->fetchColumn();
                $pdo->prepare('INSERT INTO repair_statuses (name, color, sort, is_default, is_closed, request_review) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$name, $color, (int)$sort, $isDefault, $isClosed, $review]);
                $id = (int)$pdo->lastInsertId();
            }
            $pdo->commit();
            respond(['ok' => true, 'id' => $id]);

        case 'status_delete':
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare('SELECT COUNT(*) FROM repair_orders WHERE status_id = ?');
            $st->execute([$id]);
            if ($n = (int)$st->fetchColumn()) {
                respond(['ok' => false, 'message' => "Статус використовується в замовленнях ($n). Спочатку змініть їм статус."], 422);
            }
            $st = $pdo->prepare('DELETE FROM repair_statuses WHERE id = ?');
            $st->execute([$id]);
            if (!$st->rowCount()) respond(['ok' => false, 'message' => 'Статус не знайдено.'], 404);
            respond(['ok' => true]);

        case 'orders':
            $where = [];
            $args = [];
            $status = (string)($_GET['status'] ?? '');
            if ($status === 'open')        $where[] = '(s.is_closed IS NULL OR s.is_closed = 0)';
            elseif ($status === 'closed')  $where[] = 's.is_closed = 1';
            elseif ($status === 'none')    $where[] = 'o.status_id IS NULL';
            elseif ($status !== '')        { $where[] = 'o.status_id = ?'; $args[] = (int)$status; }

            $q = trim(mb_scrub((string)($_GET['q'] ?? ''), 'UTF-8'));
            if ($q !== '') {
                $like = '%' . addcslashes(mb_strtolower($q), '%_\\') . '%';
                $digits = preg_replace('/\D/', '', $q);
                $parts = [];
                foreach (['client_name', 'device_type', 'device_brand', 'device_model', 'serial', 'malfunction'] as $col) {
                    $parts[] = "ulower(o.$col) LIKE ? ESCAPE '\\'";
                    $args[] = $like;
                }
                if ($digits !== '') {
                    $parts[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(o.client_phone, ' ', ''), '(', ''), ')', ''), '-', ''), '+', '') LIKE ?";
                    $args[] = '%' . $digits . '%';
                    $parts[] = 'o.id = ?';
                    $args[] = (int)$digits;
                }
                $where[] = '(' . implode(' OR ', $parts) . ')';
            }
            $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            $from = 'FROM repair_orders o LEFT JOIN repair_statuses s ON s.id = o.status_id';

            $per  = max(1, min(100, (int)($_GET['per'] ?? 30)));
            $page = max(1, (int)($_GET['page'] ?? 1));
            $st = $pdo->prepare("SELECT COUNT(*) $from $whereSql");
            $st->execute($args);
            $total = (int)$st->fetchColumn();
            $pages = max(1, (int)ceil($total / $per));
            $page = min($page, $pages);

            $st = $pdo->prepare("SELECT o.*, (SELECT COALESCE(SUM(qty * price), 0) FROM repair_items i WHERE i.order_id = o.id) AS total
                                 $from $whereSql ORDER BY o.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
            $st->execute($args);
            respond(['ok' => true, 'orders' => array_map('orderRow', $st->fetchAll()), 'total' => $total, 'page' => $page, 'pages' => $pages]);

        case 'order':
            $o = requireOrder($pdo, (int)($_GET['id'] ?? 0));
            $st = $pdo->prepare('SELECT id, kind, name, code, qty, price FROM repair_items WHERE order_id = ? ORDER BY id');
            $st->execute([$o['id']]);
            $items = array_map(fn($i) => ['id' => (int)$i['id'], 'kind' => $i['kind'], 'name' => $i['name'], 'code' => $i['code'],
                                          'qty' => (float)$i['qty'], 'price' => (float)$i['price']], $st->fetchAll());
            $st = $pdo->prepare('SELECT c.id, c.kind, c.text, c.created_at, c.user_id, u.username
                                 FROM repair_comments c LEFT JOIN users u ON u.id = c.user_id
                                 WHERE c.order_id = ? ORDER BY c.id');
            $st->execute([$o['id']]);
            $comments = array_map(fn($c) => ['id' => (int)$c['id'], 'kind' => $c['kind'], 'text' => $c['text'],
                                             'created_at' => $c['created_at'], 'author' => $c['username'],
                                             'mine' => (int)$c['user_id'] === $userId], $st->fetchAll());
            respond(['ok' => true, 'order' => orderRow($o), 'items' => $items, 'comments' => $comments]);

        case 'order_save':
            $id = (int)($_POST['id'] ?? 0);
            $data = [];
            $errors = [];
            foreach (REPAIR_TEXT_FIELDS as $field => $max) {
                $data[$field] = postText($field);
                if (mb_strlen($data[$field]) > $max) $errors[$field] = 'Задовгий текст';
            }

            $phoneDigits = preg_replace('/\D/', '', $data['client_phone']);
            if ($phoneDigits === '' || $phoneDigits === '380') {
                $data['client_phone'] = '';
            } elseif (strlen($phoneDigits) !== 12 || !str_starts_with($phoneDigits, '380')) {
                $errors['client_phone'] = 'Введіть номер повністю: +380 (XX) XXX-XX-XX';
            } else {
                $data['client_phone'] = sprintf('+380 (%s) %s-%s-%s', substr($phoneDigits, 3, 2), substr($phoneDigits, 5, 3), substr($phoneDigits, 8, 2), substr($phoneDigits, 10, 2));
            }
            if ($data['client_name'] === '' && $data['client_phone'] === '') $errors['client_name'] = "Вкажіть ім'я або телефон клієнта";
            if ($data['device_type'] === '' && $data['device_model'] === '')   $errors['device_type'] = 'Вкажіть пристрій';
            $data['estimate']   = money($_POST['estimate'] ?? '');
            $data['prepayment'] = money($_POST['prepayment'] ?? '') ?? 0.0;
            if ($data['estimate'] !== null && (is_nan($data['estimate']) || $data['estimate'] < 0)) $errors['estimate'] = 'Некоректна сума';
            if (is_nan($data['prepayment']) || $data['prepayment'] < 0) $errors['prepayment'] = 'Некоректна сума';
            $due = trim((string)($_POST['due_date'] ?? ''));
            if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) $errors['due_date'] = 'Некоректна дата';
            $data['due_date'] = $due === '' ? null : $due;
            $statusId = (int)($_POST['status_id'] ?? 0) ?: null;
            if ($statusId && !repairStatus($pdo, $statusId)) $errors['status_id'] = 'Статус не знайдено';
            if (!in_array($data['messenger'], ['', 'Viber', 'Telegram', 'WhatsApp', 'немає'], true)) $errors['messenger'] = 'Оберіть месенджер';
            if ($data['edrpou'] !== '' && !preg_match('/^\d{8,10}$/', $data['edrpou'])) $errors['edrpou'] = 'Код ЄДРПОУ - 8 цифр (або 10 для ФОП)';
            if ($errors) respond(['ok' => false, 'errors' => $errors], 422);

            $pdo->beginTransaction();

            repairDictRemember($pdo, 'device_type', $data['device_type']);
            repairDictRemember($pdo, 'device_brand', $data['device_brand']);
            repairDictRemember($pdo, 'device_model', $data['device_model']);
            repairDictRemember($pdo, 'serial', $data['serial']);
            if ($id) {
                requireOrder($pdo, $id);
                $cols = array_keys(REPAIR_TEXT_FIELDS);
                $set = implode(', ', array_map(fn($c) => "$c = ?", $cols));
                $pdo->prepare("UPDATE repair_orders SET $set, estimate = ?, prepayment = ?, due_date = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                    ->execute([...array_map(fn($c) => $data[$c], $cols), $data['estimate'], $data['prepayment'], $data['due_date'], $id]);
                repairLinkClient($pdo, $id);
                if (array_key_exists('status_id', $_POST) && repairSetStatus($pdo, $id, $statusId, $userId)) {
                    $sms = repairAfterStatus($pdo, $id, $statusId, $userId);
                }
            } else {
                $data['status_id'] = $statusId;
                $id = repairCreate($pdo, $data, $userId);
            }
            $pdo->commit();
            respond(['ok' => true, 'id' => $id, 'sms' => $sms ?? null]);

        case 'order_status':
            $id = (int)($_POST['id'] ?? 0);
            requireOrder($pdo, $id);
            $statusId = (int)($_POST['status_id'] ?? 0) ?: null;
            if ($statusId && !repairStatus($pdo, $statusId)) respond(['ok' => false, 'message' => 'Статус не знайдено.'], 422);
            $sms = repairSetStatus($pdo, $id, $statusId, $userId) ? repairAfterStatus($pdo, $id, $statusId, $userId) : null;
            respond(['ok' => true, 'sms' => $sms]);

        case 'order_delete':
            $st = $pdo->prepare('DELETE FROM repair_orders WHERE id = ?');
            $st->execute([(int)($_POST['id'] ?? 0)]);
            if (!$st->rowCount()) respond(['ok' => false, 'message' => 'Замовлення не знайдено.'], 404);
            respond(['ok' => true]);

        case 'item_save':
            $orderId = (int)($_POST['order_id'] ?? 0);
            $id      = (int)($_POST['id'] ?? 0);
            $kind    = ($_POST['kind'] ?? 'work') === 'part' ? 'part' : 'work';
            $name    = postText('name');
            $code    = mb_substr(postText('code'), 0, 60);
            $qty     = money($_POST['qty'] ?? '1') ?? 1.0;
            $price   = money($_POST['price'] ?? '');
            $errors = [];
            if ($name === '')              $errors['name'] = 'Вкажіть назву';
            elseif (mb_strlen($name) > 200) $errors['name'] = 'Назва задовга';
            if (is_nan($qty) || $qty <= 0) $errors['qty'] = 'Некоректна кількість';
            if ($price === null || is_nan($price) || $price < 0) $errors['price'] = 'Вкажіть ціну';
            if ($errors) respond(['ok' => false, 'errors' => $errors], 422);
            requireOrder($pdo, $orderId);

            if ($id) {
                $st = $pdo->prepare('UPDATE repair_items SET kind = ?, name = ?, code = ?, qty = ?, price = ? WHERE id = ? AND order_id = ?');
                $st->execute([$kind, $name, $code, $qty, $price, $id, $orderId]);
                if (!$st->rowCount()) {
                    $check = $pdo->prepare('SELECT 1 FROM repair_items WHERE id = ? AND order_id = ?');
                    $check->execute([$id, $orderId]);
                    if (!$check->fetchColumn()) respond(['ok' => false, 'message' => 'Позицію не знайдено.'], 404);
                }
            } else {
                $pdo->prepare('INSERT INTO repair_items (order_id, kind, name, code, qty, price) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$orderId, $kind, $name, $code, $qty, $price]);
                $id = (int)$pdo->lastInsertId();
            }
            repairDictRemember($pdo, $kind, $name, $price);
            touchOrder($pdo, $orderId);
            respond(['ok' => true, 'id' => $id]);

        case 'item_delete':
            $st = $pdo->prepare('SELECT order_id FROM repair_items WHERE id = ?');
            $st->execute([(int)($_POST['id'] ?? 0)]);
            $orderId = $st->fetchColumn();
            if ($orderId === false) respond(['ok' => false, 'message' => 'Позицію не знайдено.'], 404);
            $pdo->prepare('DELETE FROM repair_items WHERE id = ?')->execute([(int)$_POST['id']]);
            touchOrder($pdo, (int)$orderId);
            respond(['ok' => true]);

        case 'comment_add':
            $orderId = (int)($_POST['order_id'] ?? 0);
            $text = trim(mb_scrub((string)($_POST['text'] ?? ''), 'UTF-8'));
            if ($text === '')              respond(['ok' => false, 'errors' => ['text' => 'Напишіть коментар']], 422);
            if (mb_strlen($text) > 4000)   respond(['ok' => false, 'errors' => ['text' => 'Коментар задовгий']], 422);
            requireOrder($pdo, $orderId);
            repairLog($pdo, $orderId, $userId, $text, 'comment');
            touchOrder($pdo, $orderId);
            respond(['ok' => true]);

        case 'comment_delete':
            $st = $pdo->prepare("DELETE FROM repair_comments WHERE id = ? AND user_id = ? AND kind = 'comment'");
            $st->execute([(int)($_POST['id'] ?? 0), $userId]);
            if (!$st->rowCount()) respond(['ok' => false, 'message' => 'Можна видаляти лише власні коментарі.'], 422);
            respond(['ok' => true]);

        case 'device':
            $serial = mb_strtolower(trim(mb_scrub((string)($_GET['serial'] ?? ''), 'UTF-8')));
            if ($serial === '') respond(['ok' => true, 'device' => null]);
            $st = $pdo->prepare('SELECT id, device_type, device_brand, device_model, client_name, client_phone
                                 FROM repair_orders WHERE ulower(TRIM(serial)) = ? ORDER BY id DESC LIMIT 1');
            $st->execute([$serial]);
            $row = $st->fetch();
            respond(['ok' => true, 'device' => $row ? ['order_id' => (int)$row['id']] + $row : null]);

        case 'dicts':

            if ($pdo->query("SELECT value FROM site_meta WHERE key = 'repair_dict_devices'")->fetchColumn() !== '1') {
                foreach (['device_model', 'serial'] as $col) {
                    $pdo->exec("INSERT OR IGNORE INTO repair_dicts (kind, value)
                                SELECT '$col', TRIM($col) FROM repair_orders WHERE TRIM($col) <> '' GROUP BY ulower(TRIM($col))");
                }
                $pdo->exec("INSERT OR REPLACE INTO site_meta (key, value) VALUES ('repair_dict_devices', '1')");
            }
            $out = array_fill_keys(REPAIR_DICT_KINDS, []);
            foreach ($pdo->query('SELECT id, kind, value, price FROM repair_dicts ORDER BY value COLLATE NOCASE') as $d) {
                if (!isset($out[$d['kind']])) continue;
                $out[$d['kind']][] = ['id' => (int)$d['id'], 'value' => $d['value'], 'price' => $d['price'] === null ? null : (float)$d['price']];
            }
            respond(['ok' => true, 'dicts' => $out]);

        case 'dict_add':
            $kind  = (string)($_POST['kind'] ?? '');
            $value = postText('value');
            $price = money($_POST['price'] ?? '');
            if (!in_array($kind, REPAIR_DICT_KINDS, true)) respond(['ok' => false, 'message' => 'Невідомий довідник.'], 422);
            if ($value === '' || mb_strlen($value) > 200) respond(['ok' => false, 'errors' => ['value' => 'Вкажіть значення']], 422);
            if ($price !== null && (is_nan($price) || $price < 0)) $price = null;
            repairDictRemember($pdo, $kind, $value, $price);
            $st = $pdo->prepare('SELECT id FROM repair_dicts WHERE kind = ? AND ulower(value) = ?');
            $st->execute([$kind, mb_strtolower($value)]);
            respond(['ok' => true, 'id' => (int)$st->fetchColumn()]);

        case 'dict_delete':
            $st = $pdo->prepare('DELETE FROM repair_dicts WHERE id = ?');
            $st->execute([(int)($_POST['id'] ?? 0)]);
            if (!$st->rowCount()) respond(['ok' => false, 'message' => 'Значення не знайдено.'], 404);
            respond(['ok' => true]);

        default:
            respond(['ok' => false, 'message' => 'Невідома дія.'], 400);
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('api/repairs.php: ' . $e->getMessage());
    respond(['ok' => false, 'message' => 'Помилка сервера. Спробуйте пізніше.'], 500);
}
