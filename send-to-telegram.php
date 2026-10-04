<?php
require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.html');
    exit;
}

$botToken = (string)config('bot_token', '');
$chatId   = (string)config('chat_id', '');

$labels = [
    'Repair[name]'          => "Ім'я",
    'Repair[phone]'         => 'Телефон',
    'Repair[device]'        => 'Пристрій',
    'Repair[series_device]' => 'Серія/модель',
    'Repair[lament]'        => 'Опис несправності',

    'CallMe[name]'          => "Ім'я",
    'CallMe[phone]'         => 'Телефон',
    'CallMe[descript]'      => 'Коментар',

    'Writing[name]'         => "Ім'я",
    'Writing[email]'        => 'Email',
    'Writing[title]'        => 'Тема',
    'Writing[text]'         => 'Повідомлення',

    'Order[f_name]'         => 'Прізвище',
    'Order[username]'       => "Ім'я",
    'Order[l_name]'         => 'По батькові',
    'Order[email]'          => 'Email',
    'Order[phone]'          => 'Телефон',

    'Comment[stars]'         => 'Оцінка (зірки)',
    'Comment[text]'          => 'Відгук',
    'Comment[virtues]'       => 'Переваги',
    'Comment[disadvantages]' => 'Недоліки',

    'Subscriber[email]'      => 'Email',
];

$values = [
    'call_back_flag' => ['0' => 'так', '1' => 'так'],
];

$skip = ['_csrf', 'cart', 'Order[sc_t]', 'contact_url', '_human', '_elapsed', 'captcha'];

function detectFormType(array $post): string
{
    if (isset($post['Repair']))     return 'Заявка на ремонт';
    if (isset($post['CallMe']))     return 'Замовлення дзвінка';
    if (isset($post['Writing']))    return 'Повідомлення з форми "Написати"';
    if (isset($post['Order']))      return 'Оформлення замовлення';
    if (isset($post['Comment']))    return 'Новий відгук';
    if (isset($post['Subscriber'])) return 'Підписка на розсилку';
    return 'Нове повідомлення з сайту';
}

function flatten($data, $prefix = ''): array
{
    $out = [];
    foreach ($data as $key => $value) {
        $flatKey = $prefix === '' ? $key : $prefix . '[' . $key . ']';
        if (is_array($value)) {
            $out += flatten($value, $flatKey);
        } else {
            $out[$flatKey] = $value;
        }
    }
    return $out;
}

$type = detectFormType($_POST);
$isOrder = $type === 'Оформлення замовлення';

require_once __DIR__ . '/lib/antispam.php';
if ($isOrder) startSession();
$blocked = antispamBanned(db()) ? 'banned' : antispamFormCheck($_POST);
if ($blocked === null && $isOrder) {

    if (antispamOrderNeedsCaptcha(db()) && !antispamCaptchaValid((string)($_POST['captcha'] ?? ''))) $blocked = 'captcha';
}
if ($blocked === null) {
    $limits = ['Підписка на розсилку' => [[3, 3600], [10, 86400]]][$type] ?? [[5, 3600], [20, 86400]];
    if (!$isOrder && !antispamLimit(db(), 'form', $limits)) {
        $blocked = 'limit';
    } else {

        $sig = $_POST;
        unset($sig['_csrf'], $sig['_elapsed'], $sig['_human'], $sig['contact_url'], $sig['captcha']);
        $sig = hash('sha256', $type . '|' . json_encode($sig, JSON_UNESCAPED_UNICODE));
        if (!antispamLimit(db(), 'dup', [[1, 600]], substr($sig, 0, 24))) $blocked = 'dup';
    }
}

$lines = ['<b>' . htmlspecialchars($type) . '</b>'];
$fields = [];
foreach (flatten($_POST) as $key => $value) {
    if (str_ends_with($key, '[phone]')) {

        $value = rtrim(str_replace('_', '', (string)$value), ' (-');
        if (strlen(preg_replace('/\D/', '', $value)) <= 3) $value = '';
    }
    $fields[$key] = trim(mb_scrub((string)$value, 'UTF-8'));
    if ($isOrder || in_array($key, $skip, true) || trim((string)$value) === '') continue;
    $label = $labels[$key] ?? $key;
    $value = $values[$key][$value] ?? $value;
    $lines[] = htmlspecialchars($label) . ': ' . htmlspecialchars((string)$value);
}

$error = '';

if ($blocked === null && $type === 'Заявка на ремонт') {
    try {
        require_once __DIR__ . '/lib/repairs.php';
        $repairId = repairCreate(db(), [
            'client_name'  => mb_substr($fields['Repair[name]'] ?? '', 0, 120),
            'client_phone' => mb_substr($fields['Repair[phone]'] ?? '', 0, 40),
            'device_type'  => mb_substr($fields['Repair[device]'] ?? '', 0, 120),
            'device_model' => mb_substr($fields['Repair[series_device]'] ?? '', 0, 120),
            'malfunction'  => mb_substr($fields['Repair[lament]'] ?? '', 0, 4000),
        ], null, 'site');
        array_splice($lines, 1, 0, 'Замовлення в адмін-панелі: №' . $repairId);

        if ($repairUser = sessionUser()) {
            db()->prepare('UPDATE repair_orders SET user_id = ? WHERE id = ?')->execute([(int)$repairUser['id'], $repairId]);
        }
    } catch (Throwable $e) {
        error_log('send-to-telegram.php (repair): ' . $e->getMessage());
    }
}

$saved = false;
$orderId = null;
if ($blocked === null && $isOrder) {
    try {
        require_once __DIR__ . '/lib/orders.php';
        $res = shopOrderCreate(db(), $_POST, sessionUser());
    } catch (Throwable $e) {
        error_log('send-to-telegram.php (order): ' . $e->getMessage());
        $res = ['ok' => false, 'errors' => ['server' => 'Помилка сервера. Спробуйте пізніше або зателефонуйте нам.']];
    }
    if (!$res['ok']) {
        $error = implode(' ', array_unique(array_values($res['errors'])));
    } else {
        $saved = true;
        $orderId = $res['id'];
        antispamOrderPlaced(db());
        $o = $res['order'];
        $lines[0] = '<b>Нове замовлення №' . $orderId . '</b>';
        $lines[] = 'Клієнт: ' . htmlspecialchars(trim($o['last_name'] . ' ' . $o['first_name'] . ' ' . $o['middle_name']));
        $lines[] = 'Телефон: ' . htmlspecialchars($o['phone']);
        if ($o['email'] !== '') $lines[] = 'Email: ' . htmlspecialchars($o['email']);
        $lines[] = 'Доставка: ' . htmlspecialchars(shopDeliveryText($o));
        $lines[] = 'Оплата: ' . htmlspecialchars(SHOP_PAYMENT[$o['payment']]);
        if ($o['call_back']) $lines[] = 'Передзвонити перед відправкою: так';
        if ($o['comment'] !== '') $lines[] = 'Коментар: ' . htmlspecialchars($o['comment']);
        $lines[] = '';
        $lines[] = '<b>Товари:</b>';
        foreach ($res['items'] as $i) {
            $lines[] = htmlspecialchars(sprintf('• %s%s — %d × %s = %s грн', $i['name'], $i['sku'] !== '' ? ' (арт. ' . $i['sku'] . ')' : '',
                $i['qty'], number_format($i['price'], 2, '.', ' '), number_format($i['price'] * $i['qty'], 2, '.', ' ')));
        }
        $lines[] = '<b>Разом: ' . number_format($res['total'], 2, '.', ' ') . ' грн</b>';
        if ($res['bonus_spent'] > 0) {
            $lines[] = 'Оплачено бонусами: ' . number_format($res['bonus_spent'], 0, '.', ' ') . ' грн';
            $lines[] = '<b>До сплати: ' . number_format($res['to_pay'], 2, '.', ' ') . ' грн</b>';
        }
    }
}
if (isset($repairId)) $saved = true;

$lines[] = '';
$lines[] = 'Джерело: ' . htmlspecialchars($_SERVER['HTTP_REFERER'] ?? 'сайт');
$message = implode("\n", $lines);

$ok = false;
if ($blocked === 'bot' || $blocked === 'dup') {

    $ok = true;
    $isOrder = false;
} elseif ($blocked === 'limit') {
    $error = 'Забагато заявок з вашого пристрою за короткий час. Спробуйте пізніше або зателефонуйте нам: +380 (95) 501-19-70.';
} elseif ($blocked === 'banned') {
    $error = 'Надсилання з вашої мережі заблоковано через підозрілу активність. Якщо це помилка - зателефонуйте нам: +380 (95) 501-19-70.';
} elseif ($blocked === 'captcha') {
    $error = 'Ви нещодавно вже оформили замовлення, тож для наступного потрібно ввести код з картинки. Поверніться до оформлення, введіть код і надішліть ще раз.';
} elseif ($blocked === 'nojs') {
    $error = 'Не вдалося надіслати форму: оновіть сторінку і спробуйте ще раз. Якщо не виходить - зателефонуйте нам: +380 (95) 501-19-70.';
} elseif ($error === '') {
    if (getenv('SITE_TELEGRAM_OFF')) {

        $ok = true;
    } elseif ($botToken === '' || $chatId === '') {
        $error = 'Бот не налаштований: заповни config.php своїм токеном та chat_id.';
    } else {
        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
        $data = http_build_query([
            'chat_id'    => $chatId,
            'text'       => $message,
            'parse_mode' => 'HTML',
        ]);

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => 'Content-Type: application/x-www-form-urlencoded',
                'content' => $data,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $ctx);
        if ($result !== false) {
            $decoded = json_decode($result, true);
            $ok = isset($decoded['ok']) && $decoded['ok'] === true;
            if (!$ok) {
                $error = $decoded['description'] ?? 'Telegram повернув помилку.';
            }
        } else {
            $error = 'Не вдалося з\'єднатися з Telegram API.';
        }
    }

    if (!$ok && $saved) {
        error_log('send-to-telegram.php: Telegram - ' . $error);
        $ok = true;
        $error = '';
    }
}

?><!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $ok ? 'Заявку надіслано' : 'Не вдалося надіслати' ?> | OmegaPrint</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;800&display=swap" rel="stylesheet">
<style>
    body { font-family: Manrope, Roboto, Arial, sans-serif; color: #1e293b; background: #f5f7fb; margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; box-sizing: border-box; }
    .card { max-width: 520px; background: #fff; border-radius: 22px; padding: 40px 32px; text-align: center; box-shadow: 0 30px 80px rgba(15,23,42,.12); animation: in .5s cubic-bezier(.2,.8,.2,1) both; }
    .icon { width: 64px; height: 64px; margin: 0 auto 16px; border-radius: 50%; display: grid; place-items: center; font-size: 30px; color: #fff; }
    .ok .icon { background: linear-gradient(135deg, #22c55e, #16a34a); }
    .err .icon { background: linear-gradient(135deg, #ff5a5f, #e11d48); }
    h1 { font-size: 24px; font-weight: 800; letter-spacing: -.02em; margin: 0 0 10px; color: #0f172a; }
    p { color: #64748b; margin: 0 0 8px; }
    a { display: inline-block; margin: 18px 6px 0; padding: 10px 20px; border-radius: 12px; text-decoration: none; font-weight: 700; color: #fff; background: linear-gradient(135deg, #0a8dff, #5b5bf6); box-shadow: 0 8px 20px rgba(24,110,250,.28); transition: transform .2s; }
    a.secondary { background: #eef2f7; color: #1e293b; box-shadow: none; }
    a:hover { transform: translateY(-2px); }
    @keyframes in { from { opacity: 0; transform: translateY(16px) scale(.98); } }
</style>
</head>
<body>
<?php if ($ok): ?>
<div class="card ok">
    <div class="icon">✓</div>
    <h1><?= $isOrder ? 'Дякуємо! Замовлення №' . (int)$orderId . ' прийнято.' : 'Дякуємо! Заявку надіслано.' ?></h1>
    <p><?= $isOrder ? "Менеджер зв'яжеться з вами для підтвердження замовлення." : "Ми отримали ваше повідомлення і скоро зв'яжемось з вами." ?></p>
    <?php $inAccount = ($isOrder && !empty($res['order']['user_id'])) || (isset($repairId) && sessionUser()); ?>
    <?php if ($isOrder && $res['bonus_spent'] > 0): ?>
    <p>Оплачено бонусами: <?= (int)$res['bonus_spent'] ?> грн. До сплати: <b><?= number_format($res['to_pay'], 2, '.', ' ') ?> грн</b>.</p>
    <?php endif; ?>
    <?php if ($inAccount): ?>
    <p><?= $isOrder ? 'Статус замовлення і бонуси — в особистому кабінеті. Бонуси нарахуємо, коли замовлення буде виконано.'
                    : 'Статус заявки можна відстежити в особистому кабінеті.' ?></p>
    <a href="account.html#orders">Мої замовлення</a>
    <?php endif; ?>
    <?php if ($isOrder): ?>
    <script>try { localStorage.removeItem('omega_cart'); } catch (e) {}</script>
    <?php endif; ?>
    <a href="index.html" class="<?= $inAccount ? 'secondary' : '' ?>">На головну</a>
</div>
<?php else: ?>
<div class="card err">
    <div class="icon">!</div>
    <h1>Не вдалося надіслати заявку</h1>
    <p><?= htmlspecialchars($error) ?></p>
    <?php if ($isOrder): ?><a href="javascript:history.back()">Повернутися до оформлення</a><?php endif; ?>
    <a href="index.html" class="<?= $isOrder ? 'secondary' : '' ?>">На головну</a>
</div>
<?php endif; ?>
</body>
</html>
