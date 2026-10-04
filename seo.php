<?php
require __DIR__ . '/lib/bootstrap.php';

const SEO_SITE = 'OmegaPrint';

const SEO_SUFFIX = ' | OmegaPrint - Сервісний центр';
const SEO_NOINDEX = ['tray', 'placing_an_order', 'account', 'review'];
const SEO_PER_PAGE = 24;

$page = (string)($_GET['__page'] ?? 'index');
if (!preg_match('/^[a-z_]+$/', $page) || in_array($page, ['admin', 'nakladna'], true) || !is_file(__DIR__ . "/$page.html")) {
    http_response_code(404);
    echo 'Not found';
    exit;
}
$html = file_get_contents(__DIR__ . "/$page.html");

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function seoBase(): string
{
    $url = rtrim((string)config('site_url', ''), '/');
    if ($url !== '') return $url;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function seoText(string $s, int $max = 160): string
{
    $s = trim(preg_replace('/\s+/u', ' ', strip_tags($s)));
    if (mb_strlen($s) <= $max) return $s;
    $cut = mb_substr($s, 0, $max);
    $space = mb_strrpos($cut, ' ');
    return rtrim(mb_substr($cut, 0, $space ?: $max), ' ,.;:—-') . '…';
}

function seoMoney(float $n): string { return number_format($n, 2, ',', ' ') . ' грн'; }

function seoFill(string $html, string $openTag, string $closeTag, string $inner): string
{
    $start = strpos($html, $openTag);
    if ($start === false) return $html;
    $from = $start + strlen($openTag);
    $end = strpos($html, $closeTag, $from);
    return $end === false ? $html : substr($html, 0, $from) . $inner . substr($html, $end);
}

function seoHead(string $html, array $m): string
{

    $html = preg_replace('#\s*<link rel="alternate" hreflang="[^"]*" href="[^"]*">#', '', $html);
    $html = preg_replace('#\s*<meta property="og:(title|description|url|image|type)" content="[^"]*">#', '', $html);
    $html = preg_replace('#<meta name="robots" content="[^"]*">\s*#', '', $html);
    if (isset($m['title'])) {
        $html = preg_replace('#<title>.*?</title>#s', '<title>' . h($m['title']) . '</title>', $html, 1);
        $html = preg_replace('#<meta name="title" content="[^"]*">#', '<meta name="title" content="' . h($m['title']) . '">', $html, 1);
    } elseif (preg_match('#<title>(.*?)</title>#s', $html, $mm)) {
        $m['title'] = html_entity_decode(trim($mm[1]), ENT_QUOTES, 'UTF-8');
    }
    if (isset($m['description'])) {
        $html = preg_replace('#<meta name="description" content="[^"]*">#', '<meta name="description" content="' . h($m['description']) . '">', $html, 1);
    } elseif (preg_match('#<meta name="description" content="([^"]*)">#', $html, $mm)) {
        $m['description'] = html_entity_decode($mm[1], ENT_QUOTES, 'UTF-8');
    }
    $tags = [];
    if (!empty($m['noindex'])) $tags[] = '<meta name="robots" content="noindex, follow">';
    if (!empty($m['canonical'])) {
        $tags[] = '<link rel="canonical" href="' . h($m['canonical']) . '">';
        $tags[] = '<meta property="og:url" content="' . h($m['canonical']) . '">';
    }
    $tags[] = '<meta property="og:type" content="' . h($m['type'] ?? 'website') . '">';
    $tags[] = '<meta property="og:site_name" content="' . SEO_SITE . '">';
    if (!empty($m['title'])) $tags[] = '<meta property="og:title" content="' . h($m['title']) . '">';
    if (!empty($m['description'])) $tags[] = '<meta property="og:description" content="' . h($m['description']) . '">';
    if (!empty($m['image'])) $tags[] = '<meta property="og:image" content="' . h($m['image']) . '">';
    foreach ($m['ld'] ?? [] as $ld) {
        $tags[] = '<script type="application/ld+json">' . json_encode(['@context' => 'https://schema.org'] + $ld,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE) . '</script>';
    }

    $tags[] = '<script>document.documentElement.className += " js";</script><style>html.js .seo_pre { display: none; }</style>';
    $pos = stripos($html, '</head>');
    return $pos === false ? $html : substr($html, 0, $pos) . implode("\n", $tags) . "\n" . substr($html, $pos);
}

function seoHoursLabel(string $html): ?string
{
    if (!preg_match('#<ul class="all_time">(.*?)</ul>#su', $html, $m)) return null;
    preg_match_all('#<li>(.*?)</li>#su', $m[1], $rows);
    if (count($rows[1]) !== 7) return null;
    $week = [];
    foreach ($rows[1] as $row) {
        $week[] = preg_match('/(\d{1,2}):(\d{2})\s*[-–—]\s*(\d{1,2}):(\d{2})/u', strip_tags($row), $t)
            ? ['open' => $t[1] * 60 + $t[2], 'close' => $t[3] * 60 + $t[4], 'from' => (int)$t[1] . ':' . $t[2], 'to' => (int)$t[3] . ':' . $t[4]] : null;
    }
    $now = new DateTime('now', new DateTimeZone('Europe/Kyiv'));
    $day = (int)$now->format('N') - 1;
    $minutes = (int)$now->format('G') * 60 + (int)$now->format('i');
    $today = $week[$day];
    if ($today && $minutes >= $today['open'] && $minutes < $today['close']) return 'Сьогодні до ' . $today['to'];
    if ($today && $minutes < $today['open']) return 'Сьогодні з ' . $today['from'] . ' до ' . $today['to'];
    $names = ['пн', 'вт', 'ср', 'чт', 'пт', 'сб', 'нд'];
    for ($i = 1; $i <= 7; $i++) {
        $next = $week[($day + $i) % 7];
        if ($next) return 'Зачинено до ' . ($i === 1 ? 'завтра' : $names[($day + $i) % 7]) . ', ' . $next['from'];
    }
    return null;
}

function seoDivBounds(string $html, string $openTag): ?array
{
    $start = strpos($html, $openTag);
    if ($start === false) return null;
    $depth = 0;
    preg_match_all('#<(/?)div\b#i', $html, $m, PREG_OFFSET_CAPTURE, $start);
    foreach ($m[0] as $i => $tag) {
        $depth += $m[1][$i][0] === '/' ? -1 : 1;
        if ($depth === 0) return [$start, strpos($html, '>', $tag[1]) + 1];
    }
    return null;
}

function seoProductCard(array $p): string
{
    $d = $p['old_price'] && $p['old_price'] > $p['price'] ? (int)round((1 - $p['price'] / $p['old_price']) * 100) : 0;
    $avail = ['in_stock' => ['in_stock', 'fa-check-circle', 'В наявності'], 'on_order' => ['on_order', 'fa-clock', 'Під замовлення'],
              'absent' => ['absent', 'fa-times-circle', 'Відсутній']][$p['availability'] ?? 'in_stock'] ?? ['in_stock', 'fa-check-circle', 'В наявності'];
    $img = $p['image'] ? 'uploads/products/' . $p['image'] : 'icons/no-image.svg';
    $money = fn($n) => number_format((float)$n, 2, ',', ' ') . ' грн.';
    $url = 'product.html?id=' . (int)$p['id'];
    return '<div class="poruct_block' . ($d ? ' has_discount' : '') . '" data-product-id="' . (int)$p['id'] . '">'
        . '<a href="' . $url . '"><div class="img_gallary">' . ($d ? '<div class="discount_l">-' . $d . '%</div>' : '')
        . '<img src="' . h($img) . '" alt="' . h($p['name']) . '" loading="lazy"></div>'
        . '<div class="info_gallary">' . ($p['sku'] !== '' ? '<span>Артикул: ' . h($p['sku']) . '</span>' : '')
        . '<p>' . h($p['name']) . ' <br><span class="stock stock_' . $avail[0] . '" title="' . $avail[2] . '"><i class="fas ' . $avail[1] . '"></i> ' . $avail[2] . '</span></p></div></a>'
        . '<div class="button_broduct">' . ($d ? '<s class="old_price">' . $money($p['old_price']) . '</s>' : '')
        . '<h5 role="none">' . $money($p['price']) . '</h5>'
        . ($p['availability'] === 'absent' ? '<span class="bt_bay bt_absent" aria-disabled="true">Немає</span>'
            : '<input type="button" value="Купити" class="bt_bay js-buy" data-id="' . (int)$p['id'] . '">')
        . '<button type="button" class="call_me_product" data-toggle="modal" data-target="#callMeProductModal" data-name="' . h($p['name']) . '" data-image="' . h($img) . '">Передзвоніть мені</button>'
        . '</div></div>';
}

function seoPopular(PDO $pdo, string $html): string
{
    $b = seoDivBounds($html, '<div class="slick popular_main');
    if (!$b) return $html;
    $items = $pdo->query("SELECT * FROM products WHERE popular = 1 AND availability <> 'absent' ORDER BY id DESC LIMIT 20")->fetchAll();
    if (!$items) {
        $items = $pdo->query("SELECT * FROM products WHERE availability = 'in_stock' AND image IS NOT NULL AND image <> '' ORDER BY created_at DESC, id DESC LIMIT 12")->fetchAll();
    }
    $cards = implode('', array_map('seoProductCard', $items));
    return substr($html, 0, $b[0]) . '<div class="slick popular_main">' . $cards . '</div>' . substr($html, $b[1]);
}

function seoBreadcrumbs(array $items): array
{
    $list = [];
    foreach ($items as $i => [$name, $url]) {
        $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => $url];
    }
    return ['@type' => 'BreadcrumbList', 'itemListElement' => $list];
}

function seoCategories(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query('SELECT id, parent_id, name FROM categories ORDER BY sort, name')->fetchAll() as $c) $out[(int)$c['id']] = $c;
    return $out;
}

function seoCategoryPath(array $cats, ?int $id): array
{
    $path = [];
    for ($depth = 0; $id !== null && isset($cats[$id]) && $depth < 8; $depth++) {
        array_unshift($path, $cats[$id]);
        $id = $cats[$id]['parent_id'] === null ? null : (int)$cats[$id]['parent_id'];
    }
    return $path;
}

function seoCategoryTree(array $cats, int $id): array
{
    $ids = [$id];
    for ($i = 0; $i < count($ids); $i++) {
        foreach ($cats as $c) {
            if ($c['parent_id'] !== null && (int)$c['parent_id'] === $ids[$i]) $ids[] = (int)$c['id'];
        }
    }
    return $ids;
}

const SEO_AVAILABILITY = [
    'in_stock' => ['В наявності', 'https://schema.org/InStock'],
    'on_order' => ['Під замовлення', 'https://schema.org/BackOrder'],
    'absent'   => ['Відсутній', 'https://schema.org/OutOfStock'],
];

$base = seoBase();
$meta = ['canonical' => $base . '/' . ($page === 'index' ? '' : "$page.html"), 'noindex' => in_array($page, SEO_NOINDEX, true)];

try {
    $pdo = db();

    if ($page === 'index') {
        $meta['title'] = 'Ремонт оргтехніки та заправка картриджів у Києві' . SEO_SUFFIX;
        $meta['ld'][] = [
            '@type' => 'LocalBusiness', 'name' => 'OmegaPrint — сервісний центр', 'url' => $base . '/', 'image' => $base . '/logo.svg',
            'telephone' => ['+380955011970', '+380503520540', '+380445457348', '+380970123318'],
            'address' => ['@type' => 'PostalAddress', 'addressCountry' => 'UA', 'addressLocality' => 'Київ',
                          'streetAddress' => 'вул. Леоніда Первомайського, 9, офіс 12'],
            'openingHoursSpecification' => [
                ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday'], 'opens' => '09:00', 'closes' => '17:30'],
                ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Friday'], 'opens' => '09:00', 'closes' => '17:00'],
            ],
        ];
        $meta['ld'][] = ['@type' => 'WebSite', 'name' => SEO_SITE, 'url' => $base . '/',
            'potentialAction' => ['@type' => 'SearchAction', 'target' => $base . '/shop.html?q={query}', 'query-input' => 'required name=query']];
    }

    if ($page === 'product') {
        require_once __DIR__ . '/lib/specs.php';
        $st = $pdo->prepare('SELECT * FROM products WHERE id = ?');
        $st->execute([(int)($_GET['id'] ?? 0)]);
        $p = $st->fetch();
        if (!$p) {
            http_response_code(404);
            $meta = ['title' => 'Товар не знайдено' . SEO_SUFFIX, 'noindex' => true];
        } else {
            $id = (int)$p['id'];
            $catId = $p['category_id'] === null ? null : (int)$p['category_id'];
            $path = seoCategoryPath(seoCategories($pdo), $catId);
            $avail = SEO_AVAILABILITY[$p['availability'] ?? ($p['in_stock'] ? 'in_stock' : 'absent')] ?? SEO_AVAILABILITY['in_stock'];
            $image = $p['image'] ? $base . '/uploads/products/' . $p['image'] : null;
            $specs = array_values(array_filter(productSpecs($pdo, $id, $catId, false), fn($s) => $s['value'] !== null && $s['value'] !== ''));
            $brand = '';
            foreach ($specs as $s) if ($s['name'] === 'Виробник') $brand = $s['value'];
            $url = $base . '/product.html?id=' . $id;
            $about = trim((string)$p['description']) !== '' ? seoText($p['description'], 110) . ' ' : '';
            $meta = [
                'title' => $p['name'] . ' — купити в Києві, ціна' . SEO_SUFFIX,
                'description' => seoText($p['name'] . ': ' . seoMoney((float)$p['price']) . ', ' . mb_strtolower($avail[0]) . '. ' . $about
                    . 'Доставка по Україні «Новою поштою», гарантія, сервісний центр у Києві.', 200),
                'canonical' => $url, 'type' => 'product', 'image' => $image,
            ];
            $product = ['@type' => 'Product', 'name' => $p['name'], 'sku' => $p['sku'], 'url' => $url,
                'offers' => ['@type' => 'Offer', 'url' => $url, 'priceCurrency' => 'UAH', 'price' => number_format((float)$p['price'], 2, '.', ''),
                             'availability' => $avail[1], 'itemCondition' => 'https://schema.org/NewCondition',
                             'seller' => ['@type' => 'Organization', 'name' => SEO_SITE]]];
            if ($image) $product['image'] = $image;
            if ($brand !== '') $product['brand'] = ['@type' => 'Brand', 'name' => $brand];
            if (trim((string)$p['description']) !== '') $product['description'] = seoText($p['description'], 1000);
            if ($path) $product['category'] = implode(' > ', array_column($path, 'name'));
            $meta['ld'][] = $product;
            $crumbs = [['Головна', $base . '/'], ['Інтернет-магазин', $base . '/shop.html']];
            foreach ($path as $c) $crumbs[] = [$c['name'], $base . '/shop.html?cat=' . $c['id']];
            $crumbs[] = [$p['name'], $url];
            $meta['ld'][] = seoBreadcrumbs($crumbs);

            $body = '<h1 class="title">' . h($p['name']) . '</h1>';
            if ($p['image']) $body .= '<img src="uploads/products/' . h($p['image']) . '" alt="' . h($p['name']) . '" width="400">';
            $body .= '<p>Артикул: ' . h((string)$p['sku']) . '</p><p><strong>' . h(seoMoney((float)$p['price'])) . '</strong> — ' . h($avail[0]) . '</p>';
            if ($path) {
                $body .= '<p>Категорія: ' . implode(' / ', array_map(fn($c) => '<a href="shop.html?cat=' . (int)$c['id'] . '">' . h($c['name']) . '</a>', $path)) . '</p>';
            }
            if ($specs) {
                $body .= '<h2>Характеристики</h2><table>';
                foreach ($specs as $s) {
                    $value = $s['type'] === 'bool' ? (in_array($s['value'], ['1', 'Так', 'так'], true) ? 'Так' : 'Ні') : $s['value'] . ($s['unit'] !== '' ? ' ' . $s['unit'] : '');
                    $body .= '<tr><th>' . h($s['name']) . '</th><td>' . h($value) . '</td></tr>';
                }
                $body .= '</table>';
            }
            if (trim((string)$p['description']) !== '') {
                $body .= '<h2>Опис</h2>';
                foreach (preg_split('/\n{2,}/', trim($p['description'])) as $para) $body .= '<p>' . nl2br(h(trim($para))) . '</p>';
            }
            $html = seoFill($html, '<div id="productView" aria-live="polite">', '</div>', '<div class="seo_pre">' . $body . '</div><p class="text-gray">Завантаження…</p>');
        }
    }

    if ($page === 'shop') {
        $cats = seoCategories($pdo);
        $catId = (int)($_GET['cat'] ?? 0);
        $pageNo = max(1, (int)($_GET['page'] ?? 1));
        $filtered = false;
        foreach ($_GET as $k => $v) {
            if ($v !== '' && (in_array($k, ['q', 'min', 'max', 'stock', 'sale', 'sort', 'per'], true) || preg_match('/^f\d+$/', (string)$k))) $filtered = true;
        }
        $cat = $cats[$catId] ?? null;
        $where = "availability <> 'absent'";
        $args = [];
        if ($cat) {
            $ids = seoCategoryTree($cats, $catId);
            $where .= ' AND category_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $args = $ids;
        }
        $st = $pdo->prepare("SELECT COUNT(*) FROM products WHERE $where");
        $st->execute($args);
        $total = (int)$st->fetchColumn();
        $pages = max(1, (int)ceil($total / SEO_PER_PAGE));
        $pageNo = min($pageNo, $pages);
        $link = fn(int $n) => 'shop.html' . ($cat || $n > 1 ? '?' . http_build_query(array_filter(['cat' => $cat ? $catId : null, 'page' => $n > 1 ? $n : null])) : '');

        $name = $cat ? $cat['name'] : 'Інтернет-магазин';
        if (!$cat && $pageNo === 1) {
            $meta['title'] = 'Інтернет-магазин: шредери, ламінатори, різаки, біндери' . SEO_SUFFIX;
        }
        $meta['canonical'] = $base . '/' . $link($pageNo);

        $meta['noindex'] = $filtered || $total === 0;
        if ($cat) {
            $suffix = $pageNo > 1 ? ' — сторінка ' . $pageNo : '';
            $meta['title'] = $name . ' — купити в Києві, ціни' . $suffix . SEO_SUFFIX;
            $meta['description'] = seoText($name . ' в інтернет-магазині OmegaPrint: ' . $total . ' товарів, ціни, характеристики, фото. '
                . 'Доставка по Україні «Новою поштою», гарантія, власний сервісний центр у Києві.' . $suffix, 200);
            $crumbs = [['Головна', $base . '/'], ['Інтернет-магазин', $base . '/shop.html']];
            foreach (seoCategoryPath($cats, $catId) as $c) $crumbs[] = [$c['name'], $base . '/shop.html?cat=' . $c['id']];
            $meta['ld'][] = seoBreadcrumbs($crumbs);
            $html = seoFill($html, '<h1 class="title" id="catalogTitle">', '</h1>', h($name));
        }

        if (!$filtered) {
            $st = $pdo->prepare("SELECT id, name, price, image FROM products WHERE $where
                                 ORDER BY (availability = 'in_stock') DESC, popular DESC, id LIMIT " . SEO_PER_PAGE . ' OFFSET ' . (($pageNo - 1) * SEO_PER_PAGE));
            $st->execute($args);
            $body = '';

            $has = $pdo->prepare("SELECT 1 FROM products WHERE availability <> 'absent' AND category_id = ? LIMIT 1");
            $filled = function (int $id) use ($cats, $has): bool {
                foreach (seoCategoryTree($cats, $id) as $cid) {
                    $has->execute([$cid]);
                    if ($has->fetchColumn()) return true;
                }
                return false;
            };
            $children = array_filter($cats, fn($c) => ($cat ? (int)$c['parent_id'] === $catId : $c['parent_id'] === null) && $filled((int)$c['id']));
            if ($children) {
                $body .= '<ul class="col-12">';
                foreach ($children as $c) $body .= '<li><a href="shop.html?cat=' . (int)$c['id'] . '">' . h($c['name']) . '</a></li>';
                $body .= '</ul>';
            }
            $body .= '<ul class="col-12">';
            foreach ($st->fetchAll() as $p) {
                $body .= '<li><a href="product.html?id=' . (int)$p['id'] . '">' . h($p['name']) . '</a> — ' . h(seoMoney((float)$p['price'])) . '</li>';
            }
            $body .= '</ul>';
            $html = seoFill($html, '<div class="row" id="catalogGrid">', '</div>', '<div class="seo_pre col-12">' . $body . '</div>');
            if ($pages > 1) {
                $pager = '';
                for ($n = 1; $n <= $pages; $n++) $pager .= $n === $pageNo ? "<span>$n</span> " : '<a href="' . h($link($n)) . '">' . $n . '</a> ';
                $html = seoFill($html, '<nav class="catalog_pager" id="catalogPager" aria-label="Сторінки каталогу">', '</nav>', '<span class="seo_pre">' . $pager . '</span>');
            }
        }
    }

    if ($page === 'services') {
        $meta['title'] = 'Послуги сервісного центру: ремонт оргтехніки й поліграфічного обладнання' . SEO_SUFFIX;
    }

    if ($page === 'service') {
        $id = (int)($_GET['id'] ?? 0);
        $slug = (string)($_GET['slug'] ?? '');
        $st = $pdo->prepare('SELECT * FROM services WHERE visible = 1 AND (id = ? OR (? <> \'\' AND slug = ?))');
        $st->execute([$id, $slug, $slug]);
        $s = $st->fetch();
        if (!$s) {
            http_response_code(404);
            $meta = ['title' => 'Послугу не знайдено' . SEO_SUFFIX, 'noindex' => true];
        } else {
            $parent = null;
            if ($s['parent_id'] !== null) {
                $st = $pdo->prepare('SELECT id, name, image FROM services WHERE id = ?');
                $st->execute([$s['parent_id']]);
                $parent = $st->fetch() ?: null;
            }
            $url = $base . '/service.html?id=' . (int)$s['id'];
            $name = trim($s['name']);
            $full = $parent ? $name . ' — ' . mb_strtolower(mb_substr(trim($parent['name']), 0, 1)) . mb_substr(trim($parent['name']), 1) : $name;
            $text = trim($s['summary']) !== '' ? $s['summary'] : $s['description'];
            $image = $s['image'] ?: ($parent['image'] ?? null);
            $meta = [
                'title' => $full . ' у Києві' . SEO_SUFFIX,
                'description' => seoText($full . '. ' . $text . ' Сервісний центр OmegaPrint, Київ: діагностика, ремонт, гарантія на роботи.', 200),
                'canonical' => $url, 'image' => $image ? $base . '/' . $image : null,
            ];
            $meta['ld'][] = ['@type' => 'Service', 'name' => $full, 'url' => $url, 'areaServed' => 'Київ',
                'description' => seoText($s['description'] ?: $text, 600),
                'provider' => ['@type' => 'LocalBusiness', 'name' => 'OmegaPrint — сервісний центр', 'url' => $base . '/',
                               'address' => 'Київ, вул. Леоніда Первомайського, 9, офіс 12', 'telephone' => '+380955011970']];
            $crumbs = [['Головна', $base . '/'], ['Послуги', $base . '/services.html']];
            if ($parent) $crumbs[] = [trim($parent['name']), $base . '/service.html?id=' . (int)$parent['id']];
            $crumbs[] = [$name, $url];
            $meta['ld'][] = seoBreadcrumbs($crumbs);

            $body = '<h1 class="title">' . h($name) . '</h1>';
            if (trim($s['summary']) !== '') $body .= '<p>' . h($s['summary']) . '</p>';
            foreach (preg_split('/\n{2,}/', trim((string)$s['description'])) as $para) {
                if (trim($para) !== '') $body .= '<p>' . nl2br(h(trim($para))) . '</p>';
            }
            $prices = json_decode((string)$s['prices'], true) ?: [];
            if ($prices) {
                $body .= '<h2>Ціни</h2><table>';
                foreach ($prices as $row) $body .= '<tr><td>' . h((string)($row['name'] ?? '')) . '</td><td>' . h((string)($row['price'] ?? '')) . '</td></tr>';
                $body .= '</table>';
            }
            $st = $pdo->prepare('SELECT id, name FROM services WHERE parent_id = ? AND visible = 1 ORDER BY sort, id');
            $st->execute([(int)$s['id']]);
            $children = $st->fetchAll();
            if ($children) {
                $body .= '<h2>Що ремонтуємо</h2><ul>';
                foreach ($children as $c) $body .= '<li><a href="service.html?id=' . (int)$c['id'] . '">' . h(trim($c['name'])) . '</a></li>';
                $body .= '</ul>';
            }
            $html = seoFill($html, '<div id="serviceView" aria-live="polite">', '</div>', '<div class="seo_pre">' . $body . '</div><p class="text-gray">Завантаження…</p>');
        }
    }
} catch (Throwable $e) {

    error_log('seo.php: ' . $e->getMessage());
}

try {
    require_once __DIR__ . '/lib/menu-links.php';
    $html = menuLinks(db(), $html);
} catch (Throwable $e) {
    error_log('seo.php menu: ' . $e->getMessage());
}

try {
    $html = seoPopular(db(), $html);
} catch (Throwable $e) {
    error_log('seo.php popular: ' . $e->getMessage());
}

if (str_contains($html, 'data-rd-stat=')) {
    try {
        require_once __DIR__ . '/lib/counters.php';
        $pdo = db();
        $stats = siteCounters($pdo) + [
            'years' => (int)(new DateTime('now', new DateTimeZone('Europe/Kyiv')))->format('Y') - 2003,
            'products' => (int)$pdo->query("SELECT COUNT(*) FROM products WHERE availability <> 'absent'")->fetchColumn(),
        ];
        $html = preg_replace_callback('#(<dd data-rd-stat="([a-z]+)" data-rd-count=")\d+("(?: data-rd-suffix="([^"]*)")?>)[^<]*#u', function ($m) use ($stats) {
            if (!isset($stats[$m[2]])) return $m[0];
            $n = (int)$stats[$m[2]];
            return $m[1] . $n . $m[3] . number_format($n, 0, '', ' ') . ($m[4] ?? '');
        }, $html);
    } catch (Throwable $e) {
        error_log('seo.php stats: ' . $e->getMessage());
    }
    $label = seoHoursLabel($html);
    if ($label !== null) $html = str_replace('<span data-rd-hours>Графік роботи</span>', '<span data-rd-hours>' . h($label) . '</span>', $html);
}

$html = preg_replace_callback('#(<span id="time"[^>]*>)[^<]*(</span>)#u', function ($m) use ($html) {
    $label = seoHoursLabel($html);
    return $label === null ? $m[0] : $m[1] . h($label) . $m[2];
}, $html, 1);

$html = preg_replace('#<div id="([a-z_]+)" class="content#', '<div id="$1" role="main" class="content', $html, 1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
echo seoHead($html, $meta);
