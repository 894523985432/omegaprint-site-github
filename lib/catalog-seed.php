<?php
const CATALOG_SEED_VERSION = '4';

function catalogSeed(PDO $pdo): void
{

    if ($pdo->query("SELECT value FROM site_meta WHERE key = 'catalog_seed'")->fetchColumn() === CATALOG_SEED_VERSION) return;
    $seed = require __DIR__ . '/seed-catalog.php';

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        if ($pdo->query("SELECT value FROM site_meta WHERE key = 'catalog_seed'")->fetchColumn() === CATALOG_SEED_VERSION) {
            $pdo->exec('COMMIT');
            return;
        }
        $cats = [];
        foreach ($pdo->query('SELECT id, name FROM categories ORDER BY id')->fetchAll() as $c) {
            $cats[mb_strtolower(trim($c['name']))] ??= (int)$c['id'];
        }

        $drop = $pdo->prepare('DELETE FROM products WHERE sku = ? AND id <= 11');
        foreach ($seed['remove'] ?? [] as $sku) $drop->execute([(string)$sku]);

        $skus = array_flip($pdo->query("SELECT sku FROM products WHERE sku <> ''")->fetchAll(PDO::FETCH_COLUMN));

        $insert = $pdo->prepare('INSERT INTO products (sku, name, category_id, price, old_price, in_stock, availability, description)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($seed['products'] as $p) {
            $catId = $cats[mb_strtolower($p['category'])] ?? null;
            if ($catId === null || isset($skus[$p['sku']])) continue;
            $availability = $p['stock'] ? 'in_stock' : 'on_order';
            $insert->execute([$p['sku'], $p['name'], $catId, $p['price'], $p['old_price'], $p['stock'] ? 1 : 0, $availability,
                              $p['brand'] !== '' ? 'Виробник: ' . $p['brand'] . '.' : '']);
            $skus[$p['sku']] = true;
        }

        $fix = $pdo->prepare("UPDATE products SET availability = 'on_order', in_stock = 0 WHERE sku = ? AND availability = 'in_stock'");
        foreach ($seed['on_order'] ?? [] as $sku) $fix->execute([(string)$sku]);

        $setImage = $pdo->prepare("UPDATE products SET image = ?, updated_at = CURRENT_TIMESTAMP WHERE sku = ? AND (image IS NULL OR image = '')");
        foreach ($seed['images'] as $sku => $file) {
            if (preg_match('/^[a-f0-9]{24}\.jpg$/', $file)) $setImage->execute([$file, (string)$sku]);
        }

        $pdo->prepare("INSERT INTO site_meta (key, value) VALUES ('catalog_seed', ?)
                       ON CONFLICT (key) DO UPDATE SET value = excluded.value")->execute([CATALOG_SEED_VERSION]);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}
