<?php
function menuNorm(string $s): string
{
    $s = mb_strtolower(html_entity_decode(strip_tags($s), ENT_QUOTES, 'UTF-8'));
    $s = str_replace('i', 'і', $s);
    $s = strtr($s, ['плоттер' => 'плотер', 'ріжуч' => 'різальн', 'мфу' => 'бфп', 'зворотній' => 'зворотний']);
    return trim(preg_replace('/[.\s]+$/u', '', preg_replace('/\s+/u', ' ', $s)));
}

function menuLinkServices(string $html, string $blockStart, string $blockEnd, array $top, array $kids, array $cats): string
{
    $start = strpos($html, $blockStart);
    if ($start === false) return $html;
    $end = strpos($html, $blockEnd, $start + strlen($blockStart));
    if ($end === false) return $html;
    $block = substr($html, $start, $end - $start);
    $section = null;
    $last = null;
    $shopSection = null;
    $block = preg_replace_callback('#<a href="(index|services|repair)\.html"([^>]*)>(.*?)</a>#su', function ($m) use ($top, $kids, $cats, &$section, &$last, &$shopSection) {
        [$all, $page, $attrs, $inner] = $m;
        $name = menuNorm($inner);
        if ($name === 'послуги') return $all;
        $link = fn(string $href) => '<a href="' . $href . '"' . $attrs . '>' . $inner . '</a>';
        if (isset($top[$name])) {
            $section = $last = $top[$name];
            $shopSection = null;
            return $link('service.html?id=' . (int)$section['id']);
        }
        if ($page !== 'index') {

            $section = $last = null;
            $shopSection = menuCategoryFor($name, $cats);
            return $link($shopSection);
        }
        if ($shopSection !== null) {
            $href = menuCategoryFor($name, $cats);
            return $link($href !== 'shop.html' ? $href : $shopSection);
        }
        if (!$section) return $all;
        $child = null;
        foreach ($kids[$section['id']] ?? [] as $k) {
            if (menuNorm($k['name']) === $name) { $child = $k; break; }
        }

        return $link('service.html?id=' . (int)($child ?? $section)['id']);
    }, $block);
    return substr($html, 0, $start) . $block . substr($html, $end);
}

function menuCategoryFor(string $name, array $cats): string
{
    if (isset($cats[$name])) return 'shop.html?cat=' . $cats[$name];
    $best = null;
    foreach ($cats as $cname => $id) {
        if ($cname !== '' && (str_starts_with($name, $cname) || str_starts_with($cname, $name)) && ($best === null || mb_strlen($cname) > mb_strlen($best))) $best = $cname;
    }
    return $best !== null ? 'shop.html?cat=' . $cats[$best] : 'shop.html';
}

function menuLinks(PDO $pdo, string $html): string
{
    $top = [];
    $kids = [];
    foreach ($pdo->query('SELECT id, parent_id, name FROM services WHERE visible = 1 ORDER BY sort, id')->fetchAll() as $s) {
        if ($s['parent_id'] === null) $top[menuNorm($s['name'])] = $s;
        else $kids[(int)$s['parent_id']][] = $s;
    }
    $cats = [];
    foreach ($pdo->query('SELECT id, name FROM categories')->fetchAll() as $c) $cats[menuNorm($c['name'])] = (int)$c['id'];

    $html = menuLinkServices($html, '<li class="down"><a href="services.html">Послуги</a>', '<li class="down"><a href="shop.html">', $top, $kids, $cats);
    if (preg_match('#<a href="services.html">Послуги</a>\s*<i class="fas fa-chevron-down"></i>#u', $html, $mm, PREG_OFFSET_CAPTURE)) {
        $html = menuLinkServices($html, $mm[0][0], '<li class="down_nav">', $top, $kids, $cats);
    }

    $html = preg_replace_callback('#<div class="gallary_text">\s*<h5[^>]*>(.*?)</h5>(.*?)<div class="gallery_link">\s*<a\b([^>]*?)\shref="index\.html"#su', function ($m) use ($cats, $top) {
        $title = menuNorm($m[1]);
        $href = 'sale.html';
        if (isset($top[$title])) $href = 'service.html?id=' . (int)$top[$title]['id'];
        elseif (isset($cats[$title])) $href = 'shop.html?cat=' . $cats[$title];
        return str_replace('href="index.html"', 'href="' . $href . '"', $m[0]);
    }, $html);

    $bySku = $pdo->prepare('SELECT id FROM products WHERE sku = ? LIMIT 1');
    $html = preg_replace_callback('#<a\b([^>]*?)\shref="index\.html"([^>]*)>((?:(?!</a>).)*?info_gallary(?:(?!</a>).)*?)</a>#su', function ($m) use ($bySku) {
        $href = 'shop.html';
        if (preg_match('#Артикул:\s*([\w-]+)#u', strip_tags($m[3]), $sku)) {
            $bySku->execute([$sku[1]]);
            $id = $bySku->fetchColumn();
            if ($id) $href = 'product.html?id=' . (int)$id;
        }
        return '<a' . $m[1] . ' href="' . $href . '"' . $m[2] . '>' . $m[3] . '</a>';
    }, $html);

    $html = preg_replace_callback('#<a\b([^>]*?)\shref="index\.html"([^>]*)>(.*?)</a>#su', function ($m) use ($cats, $top) {
        $all = $m[0];
        $attrs = $m[1] . $m[2];
        $inner = $m[3];
        $name = menuNorm($inner);
        if (str_contains($attrs, 'data-toggle') || str_contains($attrs, 'data-dismiss')) return '<a href="#"' . $attrs . '>' . $inner . '</a>';

        if (str_contains($inner, 'img_gallary') || str_contains($inner, 'info_gallary')) return '<a href="shop.html"' . $attrs . '>' . $inner . '</a>';
        if ($name === '' || $name === 'головна') return $all;
        if (isset($cats[$name])) return '<a href="shop.html?cat=' . $cats[$name] . '"' . $attrs . '>' . $inner . '</a>';
        if (str_starts_with($name, 'витратні матеріали')) return '<a href="' . menuCategoryFor($name, $cats) . '"' . $attrs . '>' . $inner . '</a>';
        if (isset($top[$name])) return '<a href="service.html?id=' . (int)$top[$name]['id'] . '"' . $attrs . '>' . $inner . '</a>';
        if ($name === 'детальнше' || $name === 'детальніше') return '<a href="sale.html"' . $attrs . '>' . $inner . '</a>';
        if (in_array($name, ['русскій', 'русский', 'englіsh', 'english', 'deutsch', 'українська'], true)) return '<a role="button" tabindex="0"' . $attrs . '>' . $inner . '</a>';
        if ($name === 'продовжити покупки') return '<a href="shop.html"' . $attrs . '>' . $inner . '</a>';
        return $all;
    }, $html);

    $html = preg_replace_callback('#<a href="(?:services|repair)\.html"([^>]*)>(.*?)</a>#su', function ($m) use ($top) {
        $name = menuNorm($m[2]);
        return isset($top[$name]) ? '<a href="service.html?id=' . (int)$top[$name]['id'] . '"' . $m[1] . '>' . $m[2] . '</a>' : $m[0];
    }, $html);

    $html = preg_replace_callback('#<a\b[^>]*\bdata-toggle="modal"[^>]*>#u', fn($m) => preg_replace('~\shref="[^"#]*"~', ' href="#"', $m[0]), $html);

    $blocks = [['<li class="down"><a href="shop.html">', '<li><a href="delivery.html">']];
    if (preg_match('#<div class="title_down">\s*<a href="shop.html">#u', $html, $mm)) $blocks[] = [$mm[0], '<li class="nav">'];
    foreach ($blocks as [$from, $to]) {
        $start = strpos($html, $from);
        $end = $start === false ? false : strpos($html, $to, $start);
        if ($end !== false) {
            $part = preg_replace('#<a href="index\.html">([^<]*[^\s<][^<]*)</a>#u', '<a href="shop.html">$1</a>', substr($html, $start, $end - $start));
            $html = substr($html, 0, $start) . $part . substr($html, $end);
        }
    }
    return $html;
}
