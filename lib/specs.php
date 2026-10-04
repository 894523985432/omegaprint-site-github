<?php
const SPEC_TYPES = ['enum', 'number', 'bool'];

const SPEC_NONE = '__none';

const SPECS_SEED_VERSION = '7';

const PRODUCT_INFO_DEFAULT = [
    'payment'  => 'Готівкою при самовивозі, накладеним платежем на «Новій пошті», безготівковим рахунком для організацій і ФОП.',
    'warranty' => 'Гарантія виробника. Обмін і повернення товару протягом 14 днів.',
];

function productInfo(PDO $pdo): array
{
    $raw = $pdo->query("SELECT value FROM site_meta WHERE key = 'product_info'")->fetchColumn();
    $saved = $raw ? json_decode($raw, true) : null;
    return is_array($saved) ? array_merge(PRODUCT_INFO_DEFAULT, array_intersect_key($saved, PRODUCT_INFO_DEFAULT)) : PRODUCT_INFO_DEFAULT;
}

function productWarrantyMonths(PDO $pdo, int $productId): ?int
{
    $st = $pdo->prepare("SELECT s.num FROM product_specs s JOIN spec_attrs a ON a.id = s.attr_id WHERE s.product_id = ? AND a.code = 'warranty'");
    $st->execute([$productId]);
    $months = $st->fetchColumn();
    return $months === false || $months === null ? null : (int)$months;
}

function specsSchema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS spec_attrs (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        category_id INTEGER REFERENCES categories(id) ON DELETE CASCADE,
        code        TEXT UNIQUE,
        name        TEXT NOT NULL,
        unit        TEXT NOT NULL DEFAULT \'\',
        type        TEXT NOT NULL DEFAULT \'enum\',
        help        TEXT NOT NULL DEFAULT \'\',
        filterable  INTEGER NOT NULL DEFAULT 1,
        sort        INTEGER NOT NULL DEFAULT 0
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS product_specs (
        product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
        attr_id    INTEGER NOT NULL REFERENCES spec_attrs(id) ON DELETE CASCADE,
        value      TEXT NOT NULL,
        num        REAL,
        PRIMARY KEY (product_id, attr_id)
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS product_specs_attr ON product_specs (attr_id, value)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS site_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
}

function specCategoryPath(PDO $pdo, ?int $catId): array
{
    $parents = array_column($pdo->query('SELECT id, parent_id FROM categories')->fetchAll(), 'parent_id', 'id');
    $path = [];
    while ($catId !== null && array_key_exists($catId, $parents) && !in_array($catId, $path, true)) {
        $path[] = $catId;
        $catId = $parents[$catId] === null ? null : (int)$parents[$catId];
    }
    return $path;
}

function specAttrRow(array $a): array
{
    return [
        'id'          => (int)$a['id'],
        'category_id' => $a['category_id'] === null ? null : (int)$a['category_id'],
        'name'        => $a['name'],
        'unit'        => $a['unit'],
        'type'        => $a['type'],
        'help'        => $a['help'],
        'filterable'  => (bool)$a['filterable'],
        'sort'        => (int)$a['sort'],
    ];
}

function specAttrsFor(PDO $pdo, ?int $catId): array
{
    $path = specCategoryPath($pdo, $catId);
    $sql = 'SELECT * FROM spec_attrs WHERE category_id IS NULL';
    if ($path) $sql .= ' OR category_id IN (' . implode(',', $path) . ')';
    $rows = $pdo->query($sql)->fetchAll();

    $depth = array_flip(array_reverse($path));
    usort($rows, function ($a, $b) use ($depth) {
        $da = $a['category_id'] === null ? -1 : $depth[(int)$a['category_id']];
        $db = $b['category_id'] === null ? -1 : $depth[(int)$b['category_id']];
        return [$da, (int)$a['sort'], (int)$a['id']] <=> [$db, (int)$b['sort'], (int)$b['id']];
    });
    return array_map('specAttrRow', $rows);
}

function specNumber($value): ?float
{
    $value = str_replace([' ', "\u{a0}", ','], ['', '', '.'], trim((string)$value));
    return is_numeric($value) ? (float)$value : null;
}

function specFormatNumber(float $n): string
{
    return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
}

function specNormalize(string $type, $raw): ?array
{
    $raw = trim(mb_scrub((string)$raw, 'UTF-8'));
    if ($raw === '') return null;
    if ($type === 'number') {
        $n = specNumber($raw);
        return $n === null ? null : [specFormatNumber($n), $n];
    }
    if ($type === 'bool') {
        if (in_array(mb_strtolower($raw), ['1', 'так', 'yes', 'true', '+'], true)) return ['1', 1.0];
        if (in_array(mb_strtolower($raw), ['0', 'ні', 'no', 'false', '-'], true)) return ['0', 0.0];
        return null;
    }
    return [mb_substr($raw, 0, 120), null];
}

function productSpecs(PDO $pdo, int $productId, ?int $catId, bool $withMissing): array
{
    $st = $pdo->prepare('SELECT attr_id, value FROM product_specs WHERE product_id = ?');
    $st->execute([$productId]);
    $values = array_column($st->fetchAll(), 'value', 'attr_id');
    $out = [];
    foreach (specAttrsFor($pdo, $catId) as $a) {
        $value = $values[$a['id']] ?? null;
        if ($value === null && !$withMissing) continue;
        $out[] = $a + ['value' => $value];
    }
    return $out;
}

function productSpecsSave(PDO $pdo, int $productId, ?int $catId, array $values): void
{
    $attrs = array_column(specAttrsFor($pdo, $catId), null, 'id');

    $keep = $attrs ? implode(',', array_keys($attrs)) : '0';
    $pdo->prepare("DELETE FROM product_specs WHERE product_id = ? AND attr_id NOT IN ($keep)")->execute([$productId]);
    $put = $pdo->prepare('INSERT INTO product_specs (product_id, attr_id, value, num) VALUES (?, ?, ?, ?)
                          ON CONFLICT (product_id, attr_id) DO UPDATE SET value = excluded.value, num = excluded.num');
    $del = $pdo->prepare('DELETE FROM product_specs WHERE product_id = ? AND attr_id = ?');
    foreach ($values as $attrId => $raw) {
        $attrId = (int)$attrId;
        if (!isset($attrs[$attrId])) continue;
        $norm = specNormalize($attrs[$attrId]['type'], $raw);
        if ($norm === null) $del->execute([$productId, $attrId]);
        else $put->execute([$productId, $attrId, $norm[0], $norm[1]]);
    }
}

function specFilterConditions(PDO $pdo, array $query, bool $isAdmin): array
{
    $ids = [];
    foreach ($query as $key => $value) {
        if (preg_match('/^f(\d+)$/', (string)$key, $m) && is_string($value) && $value !== '') $ids[(int)$m[1]] = $value;
    }
    if (!$ids) return [];
    $types = array_column($pdo->query('SELECT id, type FROM spec_attrs WHERE id IN (' . implode(',', array_keys($ids)) . ')')->fetchAll(), 'type', 'id');
    $out = [];
    foreach ($ids as $id => $value) {
        if (!isset($types[$id])) continue;
        $parts = array_values(array_filter(explode('|', $value), fn($v) => $v !== ''));
        $or = [];
        $args = [];
        if (in_array(SPEC_NONE, $parts, true) && $isAdmin) {
            $or[] = 'products.id NOT IN (SELECT product_id FROM product_specs WHERE attr_id = ?)';
            $args[] = $id;
        }
        $parts = array_values(array_diff($parts, [SPEC_NONE]));
        if ($types[$id] === 'number') {
            if ($parts && preg_match('/^(.*)\.\.(.*)$/', $parts[0], $m)) {
                $min = specNumber($m[1]);
                $max = specNumber($m[2]);
                if ($min !== null || $max !== null) {
                    $sql = 'products.id IN (SELECT product_id FROM product_specs WHERE attr_id = ?';
                    $args[] = $id;
                    if ($min !== null) { $sql .= ' AND num >= ?'; $args[] = $min; }
                    if ($max !== null) { $sql .= ' AND num <= ?'; $args[] = $max; }
                    $or[] = $sql . ')';
                }
            }
        } elseif ($parts) {
            $or[] = 'products.id IN (SELECT product_id FROM product_specs WHERE attr_id = ? AND value IN ('
                . implode(',', array_fill(0, count($parts), '?')) . '))';
            array_push($args, $id, ...$parts);
        }
        if ($or) $out[$id] = ['(' . implode(' OR ', $or) . ')', $args];
    }
    return $out;
}

function specFacets(PDO $pdo, ?int $catId, array $where, array $args, bool $missing): array
{
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $st = $pdo->prepare("SELECT COUNT(*) FROM products $whereSql");
    $st->execute($args);
    $total = (int)$st->fetchColumn();
    $out = [];
    foreach (specAttrsFor($pdo, $catId) as $a) {
        if (!$a['filterable']) continue;
        $in = "product_id IN (SELECT id FROM products $whereSql)";
        if ($a['type'] === 'number') {
            $st = $pdo->prepare("SELECT COUNT(*), MIN(num), MAX(num) FROM product_specs WHERE attr_id = ? AND $in");
            $st->execute([$a['id'], ...$args]);
            [$count, $min, $max] = $st->fetch(PDO::FETCH_NUM);
            $a += ['count' => (int)$count, 'min' => $min === null ? null : (float)$min, 'max' => $max === null ? null : (float)$max];
        } else {
            $st = $pdo->prepare("SELECT value, COUNT(*) AS n FROM product_specs WHERE attr_id = ? AND $in GROUP BY value");
            $st->execute([$a['id'], ...$args]);
            $values = array_map(fn($r) => ['value' => $r['value'], 'count' => (int)$r['n']], $st->fetchAll());

            usort($values, fn($x, $y) => strnatcasecmp($x['value'], $y['value']));
            $a += ['count' => array_sum(array_column($values, 'count')), 'values' => $values];
        }
        if ($missing) $a['missing'] = $total - $a['count'];

        if (!$a['count'] && !$missing) continue;

        if ($a['type'] !== 'number' && count($a['values']) < 2 && !$missing) continue;
        if ($a['type'] === 'number' && $a['min'] === $a['max'] && !$missing) continue;
        $out[] = $a;
    }
    return $out;
}

function specsSeed(PDO $pdo): void
{

    if ($pdo->query("SELECT value FROM site_meta WHERE key = 'specs_seed'")->fetchColumn() === SPECS_SEED_VERSION) return;
    $seed = require __DIR__ . '/seed-specs.php';

    $pdo->exec('BEGIN IMMEDIATE');
    if ($pdo->query("SELECT value FROM site_meta WHERE key = 'specs_seed'")->fetchColumn() === SPECS_SEED_VERSION) {
        $pdo->exec('COMMIT');
        return;
    }
    $cats = [];
    foreach ($pdo->query('SELECT id, name FROM categories')->fetchAll() as $c) $cats[mb_strtolower(trim($c['name']))] = (int)$c['id'];
    $attrByCode = array_column($pdo->query('SELECT code, id, type FROM spec_attrs WHERE code IS NOT NULL')->fetchAll(), null, 'code');
    $ins = $pdo->prepare('INSERT INTO spec_attrs (category_id, code, name, unit, type, help, filterable, sort) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($seed['attrs'] as $i => $a) {
        if (isset($attrByCode[$a['code']])) continue;
        $catId = null;
        if ($a['category'] !== null) {
            $catId = $cats[mb_strtolower($a['category'])] ?? null;
            if ($catId === null) continue;
        }
        $ins->execute([$catId, $a['code'], $a['name'], $a['unit'] ?? '', $a['type'], $a['help'] ?? '', ($a['filterable'] ?? true) ? 1 : 0, ($i + 1) * 10]);
        $attrByCode[$a['code']] = ['id' => (int)$pdo->lastInsertId(), 'type' => $a['type']];
    }

    $bySku = [];
    foreach ($pdo->query("SELECT id, sku FROM products WHERE sku <> ''")->fetchAll() as $p) $bySku[$p['sku']] = (int)$p['id'];
    $put = $pdo->prepare('INSERT OR IGNORE INTO product_specs (product_id, attr_id, value, num) VALUES (?, ?, ?, ?)');
    foreach ($seed['values'] as $sku => $values) {
        if (!isset($bySku[$sku])) continue;
        foreach ($values as $code => $raw) {
            if (!isset($attrByCode[$code])) continue;
            $norm = specNormalize($attrByCode[$code]['type'], $raw);
            if ($norm) $put->execute([$bySku[$sku], $attrByCode[$code]['id'], $norm[0], $norm[1]]);
        }
    }
    $pdo->prepare("INSERT INTO site_meta (key, value) VALUES ('specs_seed', ?)
                   ON CONFLICT (key) DO UPDATE SET value = excluded.value")->execute([SPECS_SEED_VERSION]);
    $pdo->exec('COMMIT');
}

const COUNTRY_BY_BRAND = [
    'Kobra' => 'Італія',
    'Agent' => 'Китай', 'YIBO' => 'Китай', 'WH' => 'Китай', 'JLS' => 'Китай', 'JINPEX' => 'Китай', 'PINGDA' => 'Китай',
    'RONGDA' => 'Китай', 'SkyCut' => 'Китай', 'LiDi' => 'Китай', 'SENWEI' => 'Китай', 'Boway' => 'Китай', 'YIDE' => 'Китай',
    'BindTec' => 'Китай',
];
const COUNTRY_BY_BRAND_VERSION = '1';

function specsCountryByBrand(PDO $pdo): void
{
    if ($pdo->query("SELECT value FROM site_meta WHERE key = 'country_by_brand'")->fetchColumn() === COUNTRY_BY_BRAND_VERSION) return;
    $ids = array_column($pdo->query("SELECT code, id FROM spec_attrs WHERE code IN ('brand', 'country')")->fetchAll(), 'id', 'code');
    $pdo->exec('BEGIN IMMEDIATE');
    if (isset($ids['brand'], $ids['country'])) {
        $put = $pdo->prepare('INSERT OR IGNORE INTO product_specs (product_id, attr_id, value, num)
                              SELECT product_id, ?, ?, NULL FROM product_specs WHERE attr_id = ? AND ulower(value) = ?');
        foreach (COUNTRY_BY_BRAND as $brand => $country) {
            $put->execute([$ids['country'], $country, $ids['brand'], mb_strtolower($brand)]);
        }
    }
    $pdo->prepare("INSERT INTO site_meta (key, value) VALUES ('country_by_brand', ?)
                   ON CONFLICT (key) DO UPDATE SET value = excluded.value")->execute([COUNTRY_BY_BRAND_VERSION]);
    $pdo->exec('COMMIT');
}
