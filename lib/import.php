<?php
const IMPORT_MAX_ROWS = 20000;
const IMPORT_SAMPLE = 300;

function importSettings(PDO $pdo): array
{
    $raw = $pdo->query("SELECT value FROM site_meta WHERE key = 'import_settings'")->fetchColumn();
    $data = $raw ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

function importSettingsSave(PDO $pdo, array $settings): void
{
    $pdo->prepare("INSERT INTO site_meta (key, value) VALUES ('import_settings', ?)
                   ON CONFLICT (key) DO UPDATE SET value = excluded.value")
        ->execute([json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);
}

function importPrice($value): ?float
{
    if ($value === null || $value === '') return null;
    $value = str_replace([' ', "\u{a0}", ','], ['', '', '.'], trim((string)$value));
    return is_numeric($value) && (float)$value >= 0 ? round((float)$value, 2) : null;
}

function importProducts(PDO $pdo, array $rows, array $opts, bool $dry): array
{
    $opt = fn(string $k) => !empty($opts[$k]);
    $zero = ($opts['zero_stock'] ?? '') === 'absent' ? 'absent' : 'on_order';
    $stats = ['rows' => count($rows), 'created' => 0, 'price' => 0, 'availability' => 0, 'name' => 0, 'unchanged' => 0,
              'absent' => 0, 'categories' => 0, 'new_skipped' => 0, 'skipped' => []];
    $changes = [];
    $skip = function (string $reason) use (&$stats) { $stats['skipped'][$reason] = ($stats['skipped'][$reason] ?? 0) + 1; };
    $note = function (array $change) use (&$changes) { if (count($changes) < IMPORT_SAMPLE) $changes[] = $change; };

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $existing = [];
        foreach ($pdo->query("SELECT id, sku, name, price, old_price, availability FROM products WHERE sku <> ''")->fetchAll() as $p) {
            $existing[$p['sku']] = $p;
        }
        $catIds = array_flip(array_map('intval', $pdo->query('SELECT id FROM categories')->fetchAll(PDO::FETCH_COLUMN)));
        $topByName = [];
        foreach ($pdo->query('SELECT id, name FROM categories WHERE parent_id IS NULL')->fetchAll() as $c) {
            $topByName[mb_strtolower(trim($c['name']))] = (int)$c['id'];
        }
        $brandAttr = $pdo->query("SELECT id FROM spec_attrs WHERE code = 'brand'")->fetchColumn();

        $insert = $pdo->prepare('INSERT INTO products (sku, name, category_id, price, old_price, in_stock, availability, description)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $insertCat = $pdo->prepare('INSERT INTO categories (name, parent_id, sort) VALUES (?, NULL, 0)');
        $putBrand = $pdo->prepare('INSERT OR IGNORE INTO product_specs (product_id, attr_id, value, num) VALUES (?, ?, ?, NULL)');

        $seen = [];
        foreach ($rows as $r) {
            if (!is_array($r)) { $skip('некоректний рядок'); continue; }
            $sku = trim(mb_scrub((string)($r['sku'] ?? ''), 'UTF-8'));
            $name = trim(preg_replace('/\s+/u', ' ', mb_scrub((string)($r['name'] ?? ''), 'UTF-8')));
            if ($sku === '' || mb_strlen($sku) > 50) { $skip('без артикула'); continue; }
            if (isset($seen[$sku])) { $skip('повтор артикула у файлі'); continue; }
            $seen[$sku] = true;

            $price = importPrice($r['price'] ?? null);
            $old = importPrice($r['old_price'] ?? null);
            if ($old !== null && ($price === null || $old <= $price)) $old = null;
            $stock = array_key_exists('stock', $r) && $r['stock'] !== null && $r['stock'] !== '' ? importPrice($r['stock']) ?? 0.0 : null;
            $availability = $stock === null ? null : ($stock > 0 ? 'in_stock' : $zero);

            if (isset($existing[$sku])) {
                $p = $existing[$sku];
                $set = [];
                $args = [];
                if ($opt('update_price') && $price !== null && $price > 0
                    && ((float)$p['price'] !== $price || ($p['old_price'] === null ? null : (float)$p['old_price']) !== $old)) {
                    $set[] = 'price = ?, old_price = ?';
                    array_push($args, $price, $old);
                    $stats['price']++;
                    $note(['type' => 'price', 'sku' => $sku, 'name' => $p['name'], 'old' => (float)$p['price'], 'new' => $price]);
                }
                if ($opt('update_availability') && $availability !== null && $p['availability'] !== $availability) {
                    $set[] = 'availability = ?, in_stock = ?';
                    array_push($args, $availability, $availability === 'in_stock' ? 1 : 0);
                    $stats['availability']++;
                    $note(['type' => 'availability', 'sku' => $sku, 'name' => $p['name'], 'old' => $p['availability'], 'new' => $availability]);
                }
                if ($opt('update_name') && $name !== '' && mb_strlen($name) <= 200 && $p['name'] !== $name) {
                    $set[] = 'name = ?';
                    $args[] = $name;
                    $stats['name']++;
                    $note(['type' => 'name', 'sku' => $sku, 'name' => $p['name'], 'old' => $p['name'], 'new' => $name]);
                }
                if (!$set) { $stats['unchanged']++; continue; }
                $args[] = $p['id'];
                $pdo->prepare('UPDATE products SET ' . implode(', ', $set) . ', updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute($args);
                continue;
            }

            if (!$opt('add_new')) { $stats['new_skipped']++; continue; }
            if ($name === '' || mb_strlen($name) > 200) { $skip('без назви або назва задовга'); continue; }
            if ($price === null || $price <= 0) { $skip('новий товар без ціни'); continue; }
            $catId = (int)($r['category_id'] ?? 0);
            $newCat = trim(mb_scrub((string)($r['category_new'] ?? ''), 'UTF-8'));
            if ($catId && !isset($catIds[$catId])) { $skip('категорію не знайдено'); continue; }
            if (!$catId && $newCat !== '') {
                $key = mb_strtolower($newCat);
                if (!isset($topByName[$key])) {
                    $insertCat->execute([mb_substr($newCat, 0, 120)]);
                    $topByName[$key] = (int)$pdo->lastInsertId();
                    $catIds[$topByName[$key]] = true;
                    $stats['categories']++;
                }
                $catId = $topByName[$key];
            }
            if (!$catId) { $stats['new_skipped']++; continue; }

            $brand = trim(mb_scrub((string)($r['brand'] ?? ''), 'UTF-8'));
            $avail = $availability ?? 'in_stock';
            $insert->execute([$sku, $name, $catId, $price, $old, $avail === 'in_stock' ? 1 : 0, $avail,
                              $brand !== '' ? 'Виробник: ' . $brand . '.' : '']);
            if ($brand !== '' && $brandAttr) $putBrand->execute([(int)$pdo->lastInsertId(), $brandAttr, mb_substr($brand, 0, 120)]);
            $stats['created']++;
            $note(['type' => 'new', 'sku' => $sku, 'name' => $name, 'old' => null, 'new' => $price]);
        }

        if ($opt('absent_missing')) {
            $gone = $pdo->prepare("UPDATE products SET availability = 'absent', in_stock = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            foreach ($existing as $sku => $p) {
                if (isset($seen[$sku]) || $p['availability'] === 'absent') continue;
                $gone->execute([$p['id']]);
                $stats['absent']++;
                $note(['type' => 'absent', 'sku' => $sku, 'name' => $p['name'], 'old' => $p['availability'], 'new' => 'absent']);
            }
        }
        $pdo->exec($dry ? 'ROLLBACK' : 'COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
    return ['stats' => $stats, 'changes' => $changes];
}
