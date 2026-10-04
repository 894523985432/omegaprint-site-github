<?php
require __DIR__ . '/lib/bootstrap.php';

$base = rtrim((string)config('site_url', ''), '/');
if ($base === '') {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

$urls = [];
$add = function (string $path, ?string $lastmod = null) use (&$urls, $base) {
    $urls[] = '<url><loc>' . htmlspecialchars($base . '/' . $path, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>'
        . ($lastmod ? '<lastmod>' . substr($lastmod, 0, 10) . '</lastmod>' : '') . '</url>';
};

foreach (['', 'shop.html', 'services.html', 'about.html', 'delivery.html', 'sale.html', 'reviews.html', 'contacts.html'] as $p) $add($p);

try {
    $pdo = db();
    $cats = $pdo->query("SELECT c.id FROM categories c
                         WHERE EXISTS (SELECT 1 FROM products p WHERE p.category_id = c.id AND p.availability <> 'absent')
                            OR EXISTS (SELECT 1 FROM categories k JOIN products p ON p.category_id = k.id WHERE k.parent_id = c.id AND p.availability <> 'absent')
                         ORDER BY c.id")->fetchAll();
    foreach ($cats as $c) $add('shop.html?cat=' . (int)$c['id']);
    foreach ($pdo->query("SELECT id, updated_at FROM products WHERE availability <> 'absent' ORDER BY id") as $p) {
        $add('product.html?id=' . (int)$p['id'], $p['updated_at']);
    }
    foreach ($pdo->query('SELECT id, updated_at FROM services WHERE visible = 1 ORDER BY id') as $s) {
        $add('service.html?id=' . (int)$s['id'], $s['updated_at']);
    }
} catch (Throwable $e) {
    error_log('sitemap.php: ' . $e->getMessage());
}

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n" . implode("\n", $urls) . "\n</urlset>\n";
