<?php
const ACCESS_LOG_DAYS = 14;

function accessLogDir(): string
{
    return dirname((string)config('db_path', dirname(__DIR__, 2) . '/data/site.sqlite')) . '/logs';
}

function accessLogStart(): void
{
    if (PHP_SAPI === 'cli' || !is_dir(accessLogDir())) return;
    $started = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
    register_shutdown_function(function () use ($started) {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $day = gmdate('Y-m-d');
        $ref = parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST) ?: '';
        if ($ref === ($_SERVER['HTTP_HOST'] ?? '')) $ref = '';
        $line = json_encode([
            't'  => time(),
            'ms' => (int)round((microtime(true) - $started) * 1000),
            'm'  => $_SERVER['REQUEST_METHOD'] ?? '',
            'p'  => mb_substr($uri, 0, 200),
            's'  => http_response_code() ?: 200,
            'v'  => substr(sha1($ip . '|' . $ua . '|' . $day . '|' . __DIR__), 0, 10),
            'b'  => preg_match('/bot|crawl|spider|slurp|lighthouse|headless|curl|python|wget|monitor|preview|scan/i', $ua) || $ua === '' ? 1 : 0,
            'k'  => str_contains($uri, '.php') ? 'api' : 'page',
            'r'  => mb_substr($ref, 0, 60),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        @file_put_contents(accessLogDir() . "/access-$day.log", $line . "\n", FILE_APPEND | LOCK_EX);

        if (mt_rand(1, 500) === 1) {
            foreach (glob(accessLogDir() . '/access-*.log') ?: [] as $f) {
                if (filemtime($f) < time() - ACCESS_LOG_DAYS * 86400) @unlink($f);
            }
        }
    });
}
