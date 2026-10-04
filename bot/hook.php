<?php
require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/tgbot.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || tgToken() === ''
    || !hash_equals(tgWebhookSecret(), (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''))) {
    http_response_code(404);
    exit;
}

$update = json_decode((string)file_get_contents('php://input'), true);
try {
    if (is_array($update)) tgHandleUpdate(db(), $update);
} catch (Throwable $e) {

    error_log('bot/hook.php: ' . $e->getMessage());
}
http_response_code(200);
echo 'ok';
