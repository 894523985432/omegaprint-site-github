<?php
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/search.php';

function placeholderFor(?int $catId): string
{
    static $cats = null;
    if ($cats === null) {
        $cats = [];
        foreach (db()->query('SELECT id, parent_id, name FROM categories')->fetchAll() as $c) $cats[(int)$c['id']] = $c;
    }
    $rules = [
        'blade'      => '/ножиц|аксесуари для різаків/u',
        'shredder'   => '/знищувач|шредер/u',
        'laminator'  => '/ламінатор/u',
        'guillotine' => '/гільйотин/u',
        'cutter'     => '/різак|нарізач|закруглювач|пристосування для нарізання|біговальн|фальц/u',
        'press'      => '/прес/u',
        'plotter'    => '/плоттер|плотер/u',
        'stapler'    => '/степлер|діркопробивач|дротошвей/u',
        'eyelet'     => '/заклепочник/u',
        'binder'     => '/біндер|палітурн|термоклей|перфоратор|брошур|фотокниг/u',
        'ergo'       => '/ергоном|тримач|стійк|лоток|лотків|багатокомпонент|підставк/u',
    ];
    for ($id = $catId, $depth = 0; $id !== null && isset($cats[$id]) && $depth < 6; $depth++) {
        $name = mb_strtolower($cats[$id]['name']);
        foreach ($rules as $type => $re) {
            if (preg_match($re, $name)) return 'icons/ph/' . $type . '.svg';
        }
        $id = $cats[$id]['parent_id'] === null ? null : (int)$cats[$id]['parent_id'];
    }
    return 'icons/ph/generic.svg';
}

function productRow(array $p): array
{
    return [
        'id'          => (int)$p['id'],
        'sku'         => $p['sku'],
        'name'        => $p['name'],
        'category_id' => $p['category_id'] === null ? null : (int)$p['category_id'],
        'price'       => (float)$p['price'],
        'old_price'   => $p['old_price'] === null ? null : (float)$p['old_price'],
        'in_stock'    => (bool)$p['in_stock'],
        'availability'=> $p['availability'] ?? ($p['in_stock'] ? 'in_stock' : 'absent'),
        'popular'     => (bool)$p['popular'],
        'description' => $p['description'],
        'image'       => $p['image'] ? 'uploads/products/' . $p['image'] : null,

        'placeholder' => $p['image'] ? null : placeholderFor($p['category_id'] === null ? null : (int)$p['category_id']),
    ];
}

function categoryWithChildren(PDO $pdo, int $id): array
{
    $all = $pdo->query('SELECT id, parent_id FROM categories')->fetchAll();
    $ids = [$id];
    for ($i = 0; $i < count($ids); $i++) {
        foreach ($all as $c) {
            if ((int)$c['parent_id'] === $ids[$i]) $ids[] = (int)$c['id'];
        }
    }
    return $ids;
}

function viewerIsAdmin(): bool
{
    if (empty($_COOKIE[session_name()])) return false;
    $user = currentUser();
    return $user && $user['is_admin'];
}

function catalogConditions(PDO $pdo): array
{
    $where = [];
    $args = [];
    if (!empty($_GET['cat'])) {
        $ids = categoryWithChildren($pdo, (int)$_GET['cat']);
        $where[] = 'category_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        array_push($args, ...$ids);
    }
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $found = searchProducts($pdo, mb_substr($q, 0, 100));
        if ($found !== null) {

            searchHitsTable($pdo, $found['ids']);
            $where[] = 'id IN (SELECT id FROM search_hits)';
            $GLOBALS['searchFound'] = $found;
        } else {

            $like = '%' . addcslashes(mb_strtolower($q), '%_\\') . '%';
            $where[] = "(ulower(name) LIKE ? ESCAPE '\\' OR ulower(sku) LIKE ? ESCAPE '\\')";
            array_push($args, $like, $like);
        }
    }
    if (isset($_GET['min']) && $_GET['min'] !== '') { $where[] = 'price >= ?'; $args[] = (float)$_GET['min']; }
    if (isset($_GET['max']) && $_GET['max'] !== '') { $where[] = 'price <= ?'; $args[] = (float)$_GET['max']; }
    if (!empty($_GET['stock']))   $where[] = 'in_stock = 1';
    if (!empty($_GET['sale']))    $where[] = 'old_price IS NOT NULL AND old_price > price';
    if (!empty($_GET['popular'])) $where[] = 'popular = 1';
    return [$where, $args];
}

try {
    $pdo = db();
    $action = $_GET['action'] ?? '';

    switch ($action) {
        case 'categories':
            $rows = $pdo->query('SELECT c.id, c.parent_id, c.name, c.sort,
                                        (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS own_count
                                 FROM categories c ORDER BY c.sort, c.name')->fetchAll();
            $cats = array_map(fn($c) => [
                'id'        => (int)$c['id'],
                'parent_id' => $c['parent_id'] === null ? null : (int)$c['parent_id'],
                'name'      => $c['name'],
                'sort'      => (int)$c['sort'],
                'count'     => (int)$c['own_count'],
            ], $rows);

            $byId = array_column($cats, null, 'id');
            foreach ($cats as $c) {
                $parent = $c['parent_id'];
                while ($parent !== null && isset($byId[$parent])) {
                    $byId[$parent]['count'] += $c['count'];
                    $parent = $byId[$parent]['parent_id'];
                }
            }
            respond(['ok' => true, 'categories' => array_values($byId)]);

        case 'products':
            [$where, $args] = catalogConditions($pdo);
            if (!empty($_GET['ids'])) {
                $ids = array_values(array_filter(array_map('intval', explode(',', (string)$_GET['ids']))));
                if (!$ids) respond(['ok' => true, 'items' => [], 'total' => 0, 'page' => 1, 'pages' => 1]);
                $where[] = 'id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                array_push($args, ...$ids);
            }
            foreach (specFilterConditions($pdo, $_GET, viewerIsAdmin()) as [$sql, $specArgs]) {
                $where[] = $sql;
                array_push($args, ...$specArgs);
            }
            $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            $orders = [
                'new'        => 'id DESC',
                'price_asc'  => 'price ASC',
                'price_desc' => 'price DESC',
                'name'       => 'name COLLATE NOCASE ASC',
            ];

            $order = $orders[$_GET['sort'] ?? '']
                ?? (isset($GLOBALS['searchFound'])

                    ? ($GLOBALS['searchFound']['note'] === 'partial' ? '' : "CASE availability WHEN 'in_stock' THEN 0 WHEN 'on_order' THEN 1 ELSE 2 END, ")
                        . '(SELECT pos FROM search_hits h WHERE h.id = products.id)'
                    : "CASE availability WHEN 'in_stock' THEN 0 WHEN 'on_order' THEN 1 ELSE 2 END, id DESC");

            $per  = max(1, min(100, (int)($_GET['per'] ?? 24)));
            $page = max(1, (int)($_GET['page'] ?? 1));

            $st = $pdo->prepare("SELECT COUNT(*), MIN(price), MAX(price) FROM products $whereSql");
            $st->execute($args);
            [$total, $minPrice, $maxPrice] = $st->fetch(PDO::FETCH_NUM);
            $pages = max(1, (int)ceil($total / $per));
            $page = min($page, $pages);

            $st = $pdo->prepare("SELECT * FROM products $whereSql ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per));
            $st->execute($args);
            respond([
                'ok'        => true,
                'items'     => array_map('productRow', $st->fetchAll()),
                'total'     => (int)$total,
                'page'      => $page,
                'pages'     => $pages,
                'price_min' => $minPrice === null ? 0 : (float)$minPrice,
                'price_max' => $maxPrice === null ? 0 : (float)$maxPrice,

                'search_note'  => $GLOBALS['searchFound']['note'] ?? '',
                'search_query' => $GLOBALS['searchFound']['query'] ?? '',
            ]);

        case 'suggest':
            $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
            if (mb_strlen($q) < 2) respond(['ok' => true, 'items' => [], 'categories' => [], 'total' => 0]);
            $found = searchProducts($pdo, $q);
            $items = [];
            if ($found === null) {
                $like = '%' . addcslashes(mb_strtolower($q), '%_\\') . '%';
                $st = $pdo->prepare("SELECT * FROM products WHERE ulower(name) LIKE ? ESCAPE '\\' OR ulower(sku) LIKE ? ESCAPE '\\' LIMIT 6");
                $st->execute([$like, $like]);
                $items = $st->fetchAll();
                $total = count($items);
            } else {
                $total = count($found['ids']);
                $top = array_slice($found['ids'], 0, 6);
                if ($top) {
                    $rows = array_column($pdo->query('SELECT * FROM products WHERE id IN (' . implode(',', $top) . ')')->fetchAll(), null, 'id');
                    foreach ($top as $id) if (isset($rows[$id])) $items[] = $rows[$id];
                }
            }
            respond([
                'ok'         => true,
                'items'      => array_map('productRow', $items),
                'categories' => searchCategories($pdo, $found['query'] ?? $q, 3),
                'total'      => $total,
                'note'       => $found['note'] ?? '',
                'query'      => $found['query'] ?? $q,
            ]);

        case 'filters':
            [$where, $args] = catalogConditions($pdo);
            $catId = empty($_GET['cat']) ? null : (int)$_GET['cat'];
            $admin = viewerIsAdmin();
            respond(['ok' => true, 'admin' => $admin, 'filters' => specFacets($pdo, $catId, $where, $args, $admin)]);

        case 'product':
            $st = $pdo->prepare('SELECT * FROM products WHERE id = ?');
            $st->execute([(int)($_GET['id'] ?? 0)]);
            $p = $st->fetch();
            if (!$p) respond(['ok' => false, 'message' => 'Товар не знайдено.'], 404);

            $path = [];
            $catId = $p['category_id'];
            $stCat = $pdo->prepare('SELECT id, parent_id, name FROM categories WHERE id = ?');
            while ($catId !== null) {
                $stCat->execute([$catId]);
                $c = $stCat->fetch();
                if (!$c) break;
                array_unshift($path, ['id' => (int)$c['id'], 'name' => $c['name']]);
                $catId = $c['parent_id'];
            }
            $admin = viewerIsAdmin();
            $catId = $p['category_id'] === null ? null : (int)$p['category_id'];
            respond(['ok' => true, 'product' => productRow($p), 'path' => $path, 'admin' => $admin,
                     'specs' => productSpecs($pdo, (int)$p['id'], $catId, $admin),
                     'info' => productInfo($pdo) + ['warranty_months' => productWarrantyMonths($pdo, (int)$p['id'])]]);

        default:
            respond(['ok' => false, 'message' => 'Невідома дія.'], 400);
    }
} catch (Throwable $e) {
    error_log('api/shop.php: ' . $e->getMessage());
    respond(['ok' => false, 'message' => 'Помилка сервера. Спробуйте пізніше.'], 500);
}
