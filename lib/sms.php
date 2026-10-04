<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

function siteUrl(): string
{
    $url = trim((string)config('site_url', ''));
    if ($url !== '') return rtrim($url, '/');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function smsPhone(string $phone): ?string
{
    $digits = preg_replace('/\D/', '', $phone);
    if (strlen($digits) < 9) return null;
    return '380' . substr($digits, -9);
}

function smsText(string $link): string
{
    return "Дякуємо, що обрали Омега-Принт! Будемо вдячні за відгук: $link";
}

function sendSms(PDO $pdo, string $phone, string $text, ?string $orderType = null, ?int $orderId = null): array
{
    $to = smsPhone($phone);
    $token = trim((string)config('sms_token', ''));
    $sender = trim((string)config('sms_sender', ''));
    $response = '';

    if (!$to) {
        $status = 'bad_phone';
    } elseif ($token === '' || $sender === '' || getenv('SITE_TELEGRAM_OFF')) {

        $status = 'not_configured';
    } else {
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nAuthorization: Bearer $token\r\n",
            'content'       => json_encode(['recipients' => [$to], 'sms' => ['sender' => $sender, 'text' => $text]], JSON_UNESCAPED_UNICODE),
            'timeout'       => 15,
            'ignore_errors' => true,
        ]]);
        $response = (string)@file_get_contents('https://api.turbosms.ua/message/send.json', false, $ctx);
        $data = json_decode($response, true);

        $code = (int)($data['response_code'] ?? 0);
        $status = $response !== '' && $code >= 800 && $code <= 802 ? 'sent' : 'failed';
        if ($response === '') $response = 'Немає відповіді від TurboSMS';
    }

    $pdo->prepare('INSERT INTO sms_log (phone, text, status, response, order_type, order_id) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$to ?: $phone, $text, $status, mb_substr($response, 0, 1000), $orderType, $orderId]);
    return ['status' => $status, 'text' => $text, 'phone' => $to ?: $phone];
}

function reviewToken(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $token = '';
    for ($i = 0; $i < 10; $i++) $token .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return $token;
}

function requestReview(PDO $pdo, string $type, int $orderId, bool $force = false): ?array
{
    $table = $type === 'shop' ? 'shop_orders' : 'repair_orders';
    $phoneCol = $type === 'shop' ? 'phone' : 'client_phone';
    $st = $pdo->prepare("SELECT id, $phoneCol AS phone, review_token, review_sms_at FROM $table WHERE id = ?");
    $st->execute([$orderId]);
    $order = $st->fetch();
    if (!$order || ($order['review_sms_at'] && !$force)) return null;

    $token = $order['review_token'];
    if (!$token) {
        do {
            $token = reviewToken();
            $exists = $pdo->prepare("SELECT 1 FROM shop_orders WHERE review_token = ? UNION SELECT 1 FROM repair_orders WHERE review_token = ?");
            $exists->execute([$token, $token]);
        } while ($exists->fetchColumn());
        $pdo->prepare("UPDATE $table SET review_token = ? WHERE id = ?")->execute([$token, $orderId]);
    }
    $result = sendSms($pdo, (string)$order['phone'], smsText(siteUrl() . '/r/' . $token), $type, $orderId);
    $result['link'] = siteUrl() . '/r/' . $token;
    if (in_array($result['status'], ['sent', 'not_configured'], true)) {
        $pdo->prepare("UPDATE $table SET review_sms_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$orderId]);
    }
    return $result;
}
