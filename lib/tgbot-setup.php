<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/lib/bootstrap.php';
require_once __DIR__ . '/tgbot.php';

$mode = $argv[1] ?? 'on';
if (tgToken() === '') exit("У config.php немає bot_token\n");
if ($mode === 'status') { print_r(tgApi('getWebhookInfo')); exit; }
if ($mode === 'off') { var_dump(tgApi('deleteWebhook')); exit; }

$base = rtrim((string)config('site_url', ''), '/');
if (!str_starts_with($base, 'https://')) exit("У config.php site_url має бути адресою сайту з https://\n");
tgSchema(db());
$ok = tgApi('setWebhook', ['url' => "$base/bot/hook.php", 'secret_token' => tgWebhookSecret(),
                           'allowed_updates' => ['message'], 'max_connections' => 10]);
echo 'setWebhook: ', var_export($ok, true), "\n";

$client = [['command' => 'start', 'description' => 'Почати / створити нову заявку']];
$staff = array_merge($client, [
    ['command' => 'requests', 'description' => 'Останні 20 заявок усіх клієнтів'],
    ['command' => 'inprogress', 'description' => "Позначити заявку 'в роботі': /inprogress число"],
    ['command' => 'close', 'description' => 'Закрити заявку: /close число'],
    ['command' => 'block', 'description' => 'Заблокувати клієнта: /block @username'],
    ['command' => 'unblock', 'description' => 'Розблокувати клієнта: /unblock @username'],
    ['command' => 'blocked', 'description' => 'Список заблокованих клієнтів'],
]);
tgApi('setMyCommands', ['commands' => $client, 'scope' => ['type' => 'default']]);
foreach (tgStaffIds() as $id) {
    $r = tgApi('setMyCommands', ['commands' => $staff, 'scope' => ['type' => 'chat', 'chat_id' => $id]]);
    echo "commands for $id: ", $r ? 'ok' : 'не вдалось (бот ще не має чату з цим id)', "\n";
}
print_r(tgApi('getWebhookInfo'));
