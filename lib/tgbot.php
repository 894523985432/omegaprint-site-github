<?php
require_once __DIR__ . '/repairs.php';
require_once __DIR__ . '/antispam.php';

const TG_REQUEST_LIMITS = [[3, 3600], [10, 86400]];

const TG_STAFF_DEFAULT = [716674686, 7515536920, -5352919343];
const TG_SERVICE_PHONE = '+380 97 012 33 18';
const TG_SERVICE_USERNAME = '@omega_print_pp';

const TG_BTN_NEW = '🆕 Створити заявку';
const TG_BTN_MINE = '📋 Мої заявки';
const TG_BTN_CONTACTS = '☎️ Контакти сервісу';
const TG_BTN_SEND_PHONE = '📱 Надіслати мій номер';
const TG_BTN_CANCEL = '✖️ Скасувати';

const TG_DEVICES = ['Лазерний принтер (БФП)', 'Струменевий принтер (БФП)', 'ДТФ принтер', 'УФ принтер', 'Різальний плотер',
    'Струменевий плотер', 'Біндер', 'Ламінатор', 'Шредер (знищувач паперу)', 'Дублікатор (ризограф)', 'інше (оргтехніка)'];
const TG_URGENCY = ['1 день (терміновий ремонт)', '2- 5 днів', '6-14 днів'];

function tgSchema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS tg_users (
        user_id    INTEGER PRIMARY KEY,
        username   TEXT NOT NULL DEFAULT \'\',
        name       TEXT NOT NULL DEFAULT \'\',
        blocked    INTEGER NOT NULL DEFAULT 0,
        state      TEXT NOT NULL DEFAULT \'\',
        data       TEXT NOT NULL DEFAULT \'{}\',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    addColumn($pdo, 'repair_orders', 'tg_user_id', 'INTEGER');
}

function tgToken(): string { return trim((string)config('bot_token', '')); }

function tgWebhookSecret(): string { return substr(hash('sha256', 'omega-webhook|' . tgToken()), 0, 48); }

function tgStaffIds(): array
{
    $ids = config('bot_staff_ids', null);
    if (!is_array($ids) || !$ids) {
        $ids = TG_STAFF_DEFAULT;
        if (trim((string)config('chat_id', '')) !== '') $ids[] = (int)config('chat_id');
    }
    return array_values(array_unique(array_map('intval', $ids)));
}

function tgIsStaff(int $userId): bool { return $userId > 0 && in_array($userId, tgStaffIds(), true); }

function tgApi(string $method, array $params = [])
{
    $token = tgToken();
    if (getenv('SITE_TELEGRAM_OFF')) {

        @file_put_contents(sys_get_temp_dir() . '/omega-tg-test.log', json_encode([$method, $params], JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
        return null;
    }
    if ($token === '') return null;
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'timeout' => 10, 'ignore_errors' => true,
        'content' => json_encode($params, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
    ]]);
    $raw = @file_get_contents("https://api.telegram.org/bot$token/$method", false, $ctx);
    $res = $raw ? json_decode($raw, true) : null;
    if (empty($res['ok'])) {
        error_log("tgbot $method: " . ($res['description'] ?? 'немає відповіді'));
        return null;
    }
    return $res['result'];
}

function tgSend(int $chatId, string $text, ?array $keyboard = null): void
{
    $p = ['chat_id' => $chatId, 'text' => mb_substr($text, 0, 4000)];
    if ($keyboard !== null) $p['reply_markup'] = $keyboard;
    tgApi('sendMessage', $p);
}

function tgKeyboard(array $rows, bool $once = false): array
{
    return ['keyboard' => array_map(fn($row) => array_map(fn($b) => is_array($b) ? $b : ['text' => $b], (array)$row), $rows),
            'resize_keyboard' => true, 'one_time_keyboard' => $once];
}

function tgMainMenu(): array { return tgKeyboard([[TG_BTN_NEW], [TG_BTN_MINE], [TG_BTN_CONTACTS]]); }
function tgRemoveKeyboard(): array { return ['remove_keyboard' => true]; }
function tgNum(int $id): string { return '№' . str_pad((string)$id, 5, '0', STR_PAD_LEFT); }

function tgUser(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM tg_users WHERE user_id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function tgSetState(PDO $pdo, int $id, string $state, array $data = []): void
{
    $pdo->prepare('UPDATE tg_users SET state = ?, data = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?')
        ->execute([$state, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $id]);
}

function tgFindStatus(PDO $pdo, array $names, string $fallbackSql): ?array
{
    foreach ($pdo->query('SELECT * FROM repair_statuses ORDER BY sort, id')->fetchAll() as $s) {
        if (in_array(mb_strtolower(trim($s['name'])), $names, true)) return $s;
    }
    return $pdo->query($fallbackSql)->fetch() ?: null;
}

function tgHandleUpdate(PDO $pdo, array $update): void
{
    $msg = $update['message'] ?? null;
    if (!$msg || ($msg['chat']['type'] ?? '') === 'channel' || empty($msg['from']['id'])) return;
    $from = $msg['from'];
    $uid = (int)$from['id'];
    $chat = (int)$msg['chat']['id'];
    $text = trim((string)($msg['text'] ?? ''));
    $private = ($msg['chat']['type'] ?? '') === 'private';

    $pdo->prepare("INSERT INTO tg_users (user_id, username, name) VALUES (?, ?, ?)
                   ON CONFLICT (user_id) DO UPDATE SET username = excluded.username, name = excluded.name")
        ->execute([$uid, (string)($from['username'] ?? ''), trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''))]);
    $user = tgUser($pdo, $uid);
    if ($user['blocked']) return;

    if (preg_match('#^/(requests|inprogress|close|block|unblock|blocked)(?:@\w+)?(?:\s+(.*))?$#su', $text, $m)) {
        if (tgIsStaff($uid)) tgStaffCommand($pdo, $chat, $m[1], trim($m[2] ?? ''));
        return;
    }
    if (!$private) return;

    if ($text === TG_BTN_CANCEL) {
        tgSetState($pdo, $uid, '');
        tgSend($chat, 'Заявку скасовано. Оберіть пункт нижче:', tgMainMenu());
        return;
    }
    if (preg_match('#^/start\b#', $text) || $text === TG_BTN_NEW && $user['state'] === '') {
        foreach (TG_REQUEST_LIMITS as [$count, $seconds]) {
            if (!tgIsStaff($uid) && antispamCount($pdo, 'tg_request', $seconds, 'tg' . $uid) >= $count) {
                tgSetState($pdo, $uid, '');
                tgSend($chat, "Ви вже надіслали кілька заявок — менеджер зв'яжеться з вами найближчим часом. "
                    . 'Якщо питання термінове, зателефонуйте: ' . TG_SERVICE_PHONE, tgMainMenu());
                return;
            }
        }
        tgSetState($pdo, $uid, 'name');
        tgSend($chat, "Вітаю! Вас вітає Omega Print! Заповніть заявку на ремонт техніки — це займе 6 кроків.\n\n1/6. Як до вас звертатись? (ім'я)",
            tgKeyboard([[TG_BTN_CANCEL]]));
        return;
    }

    $data = json_decode($user['data'] ?: '{}', true) ?: [];
    $cancelRow = [TG_BTN_CANCEL];
    switch ($user['state']) {
        case 'name':
            if ($text === '') { tgSend($chat, "Напишіть, будь ласка, ваше ім'я текстом."); return; }
            $data['name'] = mb_substr($text, 0, 120);
            tgSetState($pdo, $uid, 'phone', $data);
            tgSend($chat, "2/6. Номер телефону для зв'язку (або натисніть кнопку нижче):",
                tgKeyboard([[['text' => TG_BTN_SEND_PHONE, 'request_contact' => true]], $cancelRow], true));
            return;
        case 'phone':
            $phone = !empty($msg['contact']['phone_number']) ? (string)$msg['contact']['phone_number'] : $text;
            if (strlen(preg_replace('/\D/', '', $phone)) < 9) {
                tgSend($chat, 'Схоже, номер неповний. Напишіть номер телефону, наприклад 0951234567, або натисніть кнопку нижче.');
                return;
            }
            $digits = preg_replace('/\D/', '', $phone);
            $data['phone'] = strlen($digits) === 10 && $digits[0] === '0' ? '+38' . $digits : (str_starts_with($phone, '+') ? $phone : '+' . $digits);
            tgSetState($pdo, $uid, 'device_type', $data);
            tgSend($chat, '3/6. Який тип техніки?', tgKeyboard(array_merge(array_map(fn($d) => [$d], TG_DEVICES), [$cancelRow]), true));
            return;
        case 'device_type':
            if ($text === '') return;
            $data['device_type'] = mb_substr($text, 0, 120);
            tgSetState($pdo, $uid, 'device_model', $data);
            tgSend($chat, '4/6. Модель / марка пристрою:', tgKeyboard([$cancelRow]));
            return;
        case 'device_model':
            if ($text === '') return;
            $data['device_model'] = mb_substr($text, 0, 120);
            tgSetState($pdo, $uid, 'problem', $data);
            tgSend($chat, '5/6. Опишіть проблему детальніше:');
            return;
        case 'problem':
            if ($text === '') { tgSend($chat, 'Опишіть, будь ласка, проблему текстом.'); return; }
            $data['problem'] = mb_substr($text, 0, 3000);
            tgSetState($pdo, $uid, 'urgency', $data);
            tgSend($chat, '6/6. Наскільки це терміново?', tgKeyboard(array_merge(array_map(fn($d) => [$d], TG_URGENCY), [$cancelRow]), true));
            return;
        case 'urgency':
            if ($text === '') return;
            $data['urgency'] = mb_substr($text, 0, 120);
            tgSetState($pdo, $uid, '');
            tgFinishRequest($pdo, $chat, $uid, $from, $data);
            return;
    }

    if ($text === TG_BTN_MINE) { tgMyRequests($pdo, $chat, $uid); return; }
    if ($text === TG_BTN_CONTACTS) {
        tgSend($chat, "☎️ Контакти сервісу Omega Print:\n\nТелефон: " . TG_SERVICE_PHONE . "\nTelegram: " . TG_SERVICE_USERNAME .
            "\nАдреса: Київ, вул. Леоніда Первомайського, 9, офіс 12\nСайт: " . (rtrim((string)config('site_url', ''), '/') ?: 'https://omegaprint.com.ua'), tgMainMenu());
        return;
    }
    tgSend($chat, 'Вибачте, я не настільки розумний, щоб відповідати самостійно 🙂 Виберіть пункт нижче:', tgMainMenu());
}

function tgFinishRequest(PDO $pdo, int $chat, int $uid, array $from, array $d): void
{
    antispamHit($pdo, 'tg_request', 'tg' . $uid);
    $id = repairCreate($pdo, [
        'client_name'  => $d['name'] ?? '',
        'client_phone' => $d['phone'] ?? '',
        'messenger'    => 'Telegram',
        'device_type'  => $d['device_type'] ?? '',
        'device_model' => $d['device_model'] ?? '',
        'malfunction'  => trim(($d['problem'] ?? '') . "\nТерміновість: " . ($d['urgency'] ?? '')),
    ], null, 'telegram');
    $pdo->prepare('UPDATE repair_orders SET tg_user_id = ? WHERE id = ?')->execute([$uid, $id]);

    $summary = "👤 Ім'я: {$d['name']}\n📞 Телефон: {$d['phone']}\n🖥 Тип техніки: {$d['device_type']}\n🔧 Модель: {$d['device_model']}\n"
        . "📝 Проблема: {$d['problem']}\n⏱ Терміновість: {$d['urgency']}";
    tgSend($chat, '✅ Заявку прийнято! Номер: ' . tgNum($id) . "\n\n$summary\n\nМи зв'яжемось з вами найближчим часом. "
        . 'Статус заявки можна подивитись у «' . TG_BTN_MINE . '» — і ми повідомимо тут про кожну зміну.', tgMainMenu());

    $who = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));
    $note = '🔔 Нова заявка з бота ' . tgNum($id) . "\nВід: $who" . (!empty($from['username']) ? ' (@' . $from['username'] . ')' : '') . ", id $uid\n\n$summary"
        . "\n\nВ адмін-панелі: розділ «Ремонти», " . tgNum($id);
    foreach (tgStaffIds() as $staff) tgSend($staff, $note);
}

function tgMyRequests(PDO $pdo, int $chat, int $uid): void
{
    $st = $pdo->prepare('SELECT o.id, o.device_type, o.device_model, o.malfunction, o.created_at, s.name AS status
                         FROM repair_orders o LEFT JOIN repair_statuses s ON s.id = o.status_id
                         WHERE o.tg_user_id = ? ORDER BY o.id DESC LIMIT 10');
    $st->execute([$uid]);
    $rows = $st->fetchAll();
    if (!$rows) {
        tgSend($chat, 'У вас ще немає заявок. Натисніть «' . TG_BTN_NEW . '».', tgMainMenu());
        return;
    }
    $lines = ['📋 Ваші останні заявки:'];
    foreach ($rows as $r) {
        $lines[] = "\n" . tgNum((int)$r['id']) . ' — ' . trim($r['device_type'] . ' ' . $r['device_model']) . ' [' . ($r['status'] ?? 'прийнято') . ']'
            . "\n   " . mb_substr(strtok($r['malfunction'], "\n"), 0, 150) . "\n   Дата: " . tgLocalTime($r['created_at']);
    }
    tgSend($chat, implode("\n", $lines), tgMainMenu());
}

function tgLocalTime(string $utc): string
{
    try {
        return (new DateTime($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Kyiv'))->format('d.m.Y H:i');
    } catch (Throwable $e) {
        return $utc;
    }
}

function tgStaffCommand(PDO $pdo, int $chat, string $cmd, string $arg): void
{
    if ($cmd === 'requests') {
        $rows = $pdo->query('SELECT o.id, o.client_name, o.client_phone, o.device_type, o.created_at, s.name AS status
                             FROM repair_orders o LEFT JOIN repair_statuses s ON s.id = o.status_id ORDER BY o.id DESC LIMIT 20')->fetchAll();
        if (!$rows) { tgSend($chat, 'Заявок ще немає.'); return; }
        $lines = ['📋 Останні 20 замовлень на ремонт (сайт, бот, адмін-панель):', ''];
        foreach ($rows as $r) {
            $lines[] = tgNum((int)$r['id']) . ' [' . ($r['status'] ?? 'без статусу') . '] — ' . $r['client_name'] . ', ' . $r['client_phone'] . ', '
                . $r['device_type'] . ' | ' . tgLocalTime($r['created_at']);
        }
        tgSend($chat, implode("\n", $lines));
        return;
    }
    if ($cmd === 'inprogress' || $cmd === 'close') {
        $usage = "Використання: /$cmd <номер заявки>\nНаприклад: /$cmd 12";
        if (!preg_match('/^[#№]?0*(\d+)$/u', $arg, $m)) { tgSend($chat, $usage); return; }
        $id = (int)$m[1];
        $st = $pdo->prepare('SELECT id FROM repair_orders WHERE id = ?');
        $st->execute([$id]);
        if (!$st->fetchColumn()) { tgSend($chat, 'Заявку ' . tgNum($id) . ' не знайдено.'); return; }
        $status = $cmd === 'close'
            ? tgFindStatus($pdo, ['видано', 'закрита', 'виконано'], 'SELECT * FROM repair_statuses WHERE is_closed = 1 ORDER BY sort, id LIMIT 1')
            : tgFindStatus($pdo, ['ремонт', 'в роботі'], 'SELECT * FROM repair_statuses WHERE is_closed = 0 AND is_default = 0 ORDER BY sort, id LIMIT 1');
        if (!$status) { tgSend($chat, 'Не знайшов відповідного статусу в адмін-панелі (розділ «Статуси ремонтів»).'); return; }
        if (repairSetStatus($pdo, $id, (int)$status['id'], null)) {
            repairAfterStatus($pdo, $id, (int)$status['id'], null);
            tgSend($chat, '✅ Заявку ' . tgNum($id) . ' переведено у статус «' . $status['name'] . '».');
        } else {
            tgSend($chat, 'Заявка ' . tgNum($id) . ' вже має статус «' . $status['name'] . '».');
        }
        return;
    }
    if ($cmd === 'blocked') {
        $rows = $pdo->query('SELECT user_id, username, name FROM tg_users WHERE blocked = 1 ORDER BY updated_at DESC')->fetchAll();
        if (!$rows) { tgSend($chat, 'Заблокованих користувачів немає.'); return; }
        tgSend($chat, "🚫 Заблоковані користувачі:\n\n" . implode("\n", array_map(fn($r) => 'id ' . $r['user_id'] . ' — '
            . ($r['username'] !== '' ? '@' . $r['username'] : ($r['name'] ?: '(без username)')), $rows)));
        return;
    }

    if ($arg === '') { tgSend($chat, "Використання: /$cmd @username  або  /$cmd 123456789"); return; }
    if (preg_match('/^-?\d+$/', $arg)) {
        $target = (int)$arg;
        $pdo->prepare('INSERT OR IGNORE INTO tg_users (user_id) VALUES (?)')->execute([$target]);
    } else {
        $st = $pdo->prepare('SELECT user_id FROM tg_users WHERE lower(username) = ?');
        $st->execute([strtolower(ltrim($arg, '@'))]);
        $target = $st->fetchColumn();
        if ($target === false) {
            tgSend($chat, "Не знайшов такого користувача. Бот пам'ятає лише тих, хто хоч раз йому писав.\nСпробуйте передати числовий id замість @username.");
            return;
        }
        $target = (int)$target;
    }
    $block = $cmd === 'block';
    $pdo->prepare('UPDATE tg_users SET blocked = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?')->execute([$block ? 1 : 0, $target]);
    tgSend($chat, $block ? "🚫 Користувача $arg (id $target) заблоковано. Бот ігноруватиме його повідомлення."
                         : "✅ Користувача $arg (id $target) розблоковано.");
}

function tgNotifyStatus(PDO $pdo, int $orderId): void
{
    $st = $pdo->prepare('SELECT o.tg_user_id, s.name FROM repair_orders o LEFT JOIN repair_statuses s ON s.id = o.status_id WHERE o.id = ?');
    $st->execute([$orderId]);
    $r = $st->fetch();
    if (!$r || !$r['tg_user_id']) return;
    tgSend((int)$r['tg_user_id'], 'ℹ️ Статус вашої заявки ' . tgNum($orderId) . ' змінено: «' . ($r['name'] ?? 'без статусу') . '»');
}
