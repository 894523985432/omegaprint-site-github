<?php
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');

$forbidden = '#^/(lib/|baza/|narkadna/|config\.php$|router\.php$|Dockerfile$|\.)|/\.|^/uploads/.*\.(php|phtml|phar)$#i';
if (preg_match($forbidden, $path)) {
    http_response_code(404);
    echo 'Not found';
    return true;
}

if (preg_match('#^/r/([a-z0-9]{6,20})$#', $path, $m)) {
    header('Location: /review.html?t=' . $m[1], true, 302);
    return true;
}

if ($path === '/sitemap.xml') {
    require __DIR__ . '/sitemap.php';
    return true;
}
if (preg_match('#^/(?:([a-z_]+)\.html)?$#', $path, $m) && !in_array($m[1] ?? 'index', ['admin', 'nakladna'], true)
    && is_file(__DIR__ . '/' . ($m[1] ?? 'index') . '.html')) {
    $_GET['__page'] = $m[1] ?? 'index';
    ini_set('zlib.output_compression', '1');
    require __DIR__ . '/seo.php';
    return true;
}

$types = [
    'html' => 'text/html; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'js' => 'application/javascript; charset=utf-8',
    'json' => 'application/json; charset=utf-8', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'ico' => 'image/x-icon',
    'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'eot' => 'application/vnd.ms-fontobject',
];
$ext = strtolower(pathinfo($path === '/' ? 'index.html' : $path, PATHINFO_EXTENSION));

if (isset($types[$ext])) {
    $real = realpath(__DIR__ . ($path === '/' ? '/index.html' : $path));

    $roots = array_filter([realpath(__DIR__), realpath(__DIR__ . '/uploads')]);
    $inside = false;
    foreach ($roots as $root) {
        if ($real && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) $inside = true;
    }
    if (!$inside || !is_file($real)) return false;

    $type = $types[$ext];
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';

    if (in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
        header('Vary: Accept');
        if (str_contains($accept, 'image/webp') && is_file($real . '.webp')) {
            $real .= '.webp';
            $type = 'image/webp';
        }
    }

    $etag = '"' . dechex(filemtime($real)) . '-' . dechex(filesize($real)) . '"';
    header('Content-Type: ' . $type);
    header('ETag: ' . $etag);
    if ($ext === 'html') {

        header('Cache-Control: no-cache');
    } elseif (isset($_GET['v']) || str_starts_with($path, '/uploads/')) {

        header('Cache-Control: public, max-age=31536000, immutable');
    } elseif (in_array($ext, ['css', 'js', 'json'], true)) {

        header('Cache-Control: public, max-age=86400');
    } else {
        header('Cache-Control: public, max-age=2592000');
    }
    if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        return true;
    }

    $text = in_array($ext, ['html', 'css', 'js', 'json', 'svg'], true);
    if ($text && str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') && function_exists('gzencode')) {

        $cache = sys_get_temp_dir() . '/omega-gz-' . md5($real . $etag) . '.gz';
        $body = is_file($cache) ? file_get_contents($cache) : false;
        if ($body === false) {
            $body = gzencode(file_get_contents($real), 6);
            @file_put_contents($cache, $body, LOCK_EX);
        }
        header('Content-Encoding: gzip');
        header('Vary: Accept-Encoding');
        header('Content-Length: ' . strlen($body));
        echo $body;
        return true;
    }

    header('Content-Length: ' . filesize($real));
    readfile($real);
    return true;
}

if ($ext === 'php') {
    ini_set('zlib.output_compression', '1');
}

return false;
