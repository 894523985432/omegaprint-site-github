<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$GLOBALS['config'] = require dirname(__DIR__) . '/config.php';
$dbPath = (string)($GLOBALS['config']['db_path'] ?? '');
$logDir = dirname($dbPath) . '/logs';
$interval = max(2, (int)($argv[1] ?? 5));
$history = max(1, (int)($argv[2] ?? 60)) * 60;
$started = time();
$offsets = [];
$first = true;

function dirBytes(string $dir): int
{
    $out = @shell_exec('du -sk ' . escapeshellarg($dir) . ' 2>/dev/null');
    return $out ? (int)$out * 1024 : 0;
}

while (true) {
    $now = time();
    $lines = [];

    foreach ([gmdate('Y-m-d', $now - 86400), gmdate('Y-m-d', $now)] as $day) {
        $file = "$logDir/access-$day.log";
        if (!is_file($file)) continue;
        $size = filesize($file);
        $from = $offsets[$file] ?? ($first ? max(0, $size - 2000000) : $size);
        if ($size > $from && ($fh = fopen($file, 'r'))) {
            fseek($fh, $from);
            if ($from > 0 && !isset($offsets[$file])) fgets($fh);
            while (($line = fgets($fh)) !== false) {
                $row = json_decode($line, true);
                if (is_array($row) && (!$first || $row['t'] >= $now - $history)) $lines[] = $row;
            }
            $size = ftell($fh);
            fclose($fh);
        }
        $offsets[$file] = $size;
    }

    $stats = ['now' => $now, 'log' => is_dir($logDir), 'lines' => $lines];
    try {
        $pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA query_only = ON');

        $today = (new DateTime('today', new DateTimeZone('Europe/Kyiv')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $one = fn(string $sql, array $a = []) => (function () use ($pdo, $sql, $a) { $s = $pdo->prepare($sql); $s->execute($a); return $s->fetchColumn(); })();
        $stats['db'] = [
            'orders_today'  => (int)$one('SELECT COUNT(*) FROM shop_orders WHERE created_at >= ?', [$today]),
            'orders_sum'    => (float)$one("SELECT COALESCE(SUM(total), 0) FROM shop_orders WHERE created_at >= ? AND status <> 'cancelled'", [$today]),
            'orders_new'    => (int)$one("SELECT COUNT(*) FROM shop_orders WHERE status = 'new'"),
            'repairs_today' => (int)$one('SELECT COUNT(*) FROM repair_orders WHERE created_at >= ?', [$today]),
            'repairs_open'  => (int)$one('SELECT COUNT(*) FROM repair_orders WHERE closed_at IS NULL'),
            'users'         => (int)$one('SELECT COUNT(*) FROM users'),
            'products'      => (int)$one('SELECT COUNT(*) FROM products'),
            'last_order'    => $one('SELECT MAX(created_at) FROM shop_orders') ?: null,
        ];
        $pdo = null;
    } catch (Throwable $e) {
        $stats['db_error'] = $e->getMessage();
    }
    $stats['db_bytes'] = is_file($dbPath) ? filesize($dbPath) : 0;
    $load = @file_get_contents('/proc/loadavg');
    $stats['load'] = $load ? array_map('floatval', array_slice(explode(' ', $load), 0, 3)) : null;
    $stats['procs'] = (int)@shell_exec('ps -u "$(id -un)" -o comm= 2>/dev/null | grep -c -E "lsphp|php"');

    if ($first || $now % 300 < $interval) {
        $disk = ['site' => dirBytes(dirname(__DIR__)), 'data' => dirBytes(dirname($dbPath))];
    }
    $stats['disk'] = $disk ?? null;
    $errLog = dirname(__DIR__, 2) . '/logs/php.error.log';
    $stats['php_errors'] = is_file($errLog) ? ['bytes' => filesize($errLog), 'mtime' => filemtime($errLog),
        'last' => trim((string)@shell_exec('tail -n 1 ' . escapeshellarg($errLog) . ' | cut -c1-300'))] : null;

    $first = false;

    $written = @fwrite(STDOUT, json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
    if (!$written || $now - $started > 43200) break;
    fflush(STDOUT);
    sleep($interval);
}
