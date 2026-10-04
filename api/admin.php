<?php
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/search.php';
require dirname(__DIR__) . '/lib/counters.php';

const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
const IMAGE_TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];

function uploadsDir(): string
{
    $dir = dirname(__DIR__) . '/uploads/products';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        respond(['ok' => false, 'message' => 'Не вдалося створити папку для фото.'], 500);
    }
    return $dir;
}

function saveUploadedImage(array $file): string
{
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || $file['size'] > MAX_IMAGE_BYTES) {
        respond(['ok' => false, 'errors' => ['image' => 'Фото завелике (максимум 5 МБ).']], 422);
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        respond(['ok' => false, 'errors' => ['image' => 'Не вдалося завантажити фото.']], 422);
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !isset(IMAGE_TYPES[$info[2]])) {
        respond(['ok' => false, 'errors' => ['image' => 'Підтримуються лише JPG, PNG, WEBP і GIF.']], 422);
    }
    $name = bin2hex(random_bytes(12)) . '.' . IMAGE_TYPES[$info[2]];
    if (!move_uploaded_file($file['tmp_name'], uploadsDir() . '/' . $name)) {
        respond(['ok' => false, 'message' => 'Не вдалося зберегти фото.'], 500);
    }
    return $name;
}

function deleteImage(?string $name): void
{
    if ($name && preg_match('/^[a-f0-9]{24}\.(jpg|png|webp|gif)$/', $name)) {
        @unlink(dirname(__DIR__) . '/uploads/products/' . $name);
    }
}

function parsePrice($value): ?float
{
    $value = str_replace([' ', ','], ['', '.'], trim((string)$value));
    return $value === '' ? null : (is_numeric($value) ? round((float)$value, 2) : NAN);
}

startSession();
requireSameOrigin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    respond(['ok' => false, 'message' => 'Метод не дозволено.'], 405);
}
requireAdmin();

try {
    $pdo = db();
    $action = $_GET['action'] ?? '';

    switch ($action) {
        case 'product_save':
            $id       = (int)($_POST['id'] ?? 0);
            $name     = postText('name');
            $sku      = postText('sku');
            $catId    = (int)($_POST['category_id'] ?? 0) ?: null;
            $price    = parsePrice($_POST['price'] ?? '');
            $oldPrice = parsePrice($_POST['old_price'] ?? '');
            $desc     = postText('description');
            $availability = in_array($_POST['availability'] ?? '', ['in_stock', 'on_order', 'absent'], true)
                ? $_POST['availability'] : (empty($_POST['in_stock']) ? 'absent' : 'in_stock');

            $errors = [];
            if ($name === '')                                  $errors['name'] = 'Вкажіть назву товару';
            elseif (mb_strlen($name) > 200)                    $errors['name'] = 'Назва задовга';
            if (mb_strlen($sku) > 50)                          $errors['sku'] = 'Артикул задовгий';
            if ($price === null || is_nan($price) || $price < 0) $errors['price'] = 'Вкажіть ціну';
            if ($oldPrice !== null && (is_nan($oldPrice) || $oldPrice < 0)) $errors['old_price'] = 'Некоректна ціна';
            elseif ($oldPrice !== null && !isset($errors['price']) && $oldPrice <= $price) $errors['old_price'] = 'Стара ціна має бути більшою за нову';
            if ($catId !== null) {
                $st = $pdo->prepare('SELECT 1 FROM categories WHERE id = ?');
                $st->execute([$catId]);
                if (!$st->fetchColumn()) $errors['category_id'] = 'Категорію не знайдено';
            }
            $existing = null;
            if ($id) {
                $st = $pdo->prepare('SELECT * FROM products WHERE id = ?');
                $st->execute([$id]);
                $existing = $st->fetch();
                if (!$existing) respond(['ok' => false, 'message' => 'Товар не знайдено.'], 404);
            }
            if ($errors) respond(['ok' => false, 'errors' => $errors], 422);

            $image = $existing['image'] ?? null;
            if (!empty($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
                $newImage = saveUploadedImage($_FILES['image']);
                deleteImage($image);
                $image = $newImage;
            } elseif (!empty($_POST['remove_image'])) {
                deleteImage($image);
                $image = null;
            }

            $fields = [$sku, $name, $catId, $price, $oldPrice, $availability === 'in_stock' ? 1 : 0, $availability,
                       empty($_POST['popular']) ? 0 : 1, $desc, $image];
            if ($id) {
                $pdo->prepare('UPDATE products SET sku = ?, name = ?, category_id = ?, price = ?, old_price = ?, in_stock = ?, availability = ?,
                                      popular = ?, description = ?, image = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
                    ->execute([...$fields, $id]);
            } else {
                $pdo->prepare('INSERT INTO products (sku, name, category_id, price, old_price, in_stock, availability, popular, description, image)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute($fields);
                $id = (int)$pdo->lastInsertId();
            }

            if (isset($_POST['spec']) && is_array($_POST['spec'])) {
                productSpecsSave($pdo, $id, $catId, $_POST['spec']);
            }
            respond(['ok' => true, 'id' => $id]);

        case 'product_specs':

            $catId = (int)($_POST['category_id'] ?? 0) ?: null;
            $specs = productSpecs($pdo, (int)($_POST['id'] ?? 0), $catId, true);

            $st = $pdo->prepare('SELECT DISTINCT value FROM product_specs WHERE attr_id = ? ORDER BY value');
            foreach ($specs as &$spec) {
                $spec['options'] = [];
                if ($spec['type'] === 'enum') {
                    $st->execute([$spec['id']]);
                    $spec['options'] = $st->fetchAll(PDO::FETCH_COLUMN);
                    natcasesort($spec['options']);
                    $spec['options'] = array_values($spec['options']);
                }
            }
            unset($spec);
            respond(['ok' => true, 'specs' => $specs]);

        case 'spec_list':

            $rows = $pdo->query('SELECT a.*, (SELECT COUNT(*) FROM product_specs s WHERE s.attr_id = a.id) AS filled
                                 FROM spec_attrs a ORDER BY a.category_id IS NOT NULL, a.category_id, a.sort, a.id')->fetchAll();
            respond(['ok' => true, 'attrs' => array_map(fn($a) => specAttrRow($a) + ['filled' => (int)$a['filled']], $rows)]);

        case 'spec_save':
            $id    = (int)($_POST['id'] ?? 0);
            $name  = postText('name');
            $unit  = postText('unit');
            $help  = postText('help');
            $type  = (string)($_POST['type'] ?? '');
            $catId = (int)($_POST['category_id'] ?? 0) ?: null;

            $errors = [];
            if ($name === '')                 $errors['name'] = 'Вкажіть назву характеристики';
            elseif (mb_strlen($name) > 80)    $errors['name'] = 'Назва задовга';
            if (mb_strlen($unit) > 20)        $errors['unit'] = 'Одиниця виміру задовга';
            if (mb_strlen($help) > 1000)      $errors['help'] = 'Пояснення задовге (до 1000 символів)';
            if (!in_array($type, SPEC_TYPES, true)) $errors['type'] = 'Оберіть тип';
            if ($catId !== null) {
                $st = $pdo->prepare('SELECT 1 FROM categories WHERE id = ?');
                $st->execute([$catId]);
                if (!$st->fetchColumn()) $errors['category_id'] = 'Категорію не знайдено';
            }
            $existing = null;
            if ($id) {
                $st = $pdo->prepare('SELECT * FROM spec_attrs WHERE id = ?');
                $st->execute([$id]);
                $existing = $st->fetch();
                if (!$existing) respond(['ok' => false, 'message' => 'Характеристику не знайдено.'], 404);

                if (!isset($errors['type']) && $existing['type'] !== $type) {
                    $st = $pdo->prepare('SELECT COUNT(*) FROM product_specs WHERE attr_id = ?');
                    $st->execute([$id]);
                    if ($st->fetchColumn()) $errors['type'] = 'Тип не можна змінити: характеристика вже заповнена в товарах';
                }
            }
            if ($errors) respond(['ok' => false, 'errors' => $errors], 422);

            $fields = [$catId, $name, $unit, $type, $help, empty($_POST['filterable']) ? 0 : 1, (int)($_POST['sort'] ?? 0)];
            if ($id) {
                $pdo->prepare('UPDATE spec_attrs SET category_id = ?, name = ?, unit = ?, type = ?, help = ?, filterable = ?, sort = ? WHERE id = ?')
                    ->execute([...$fields, $id]);
            } else {
                $pdo->prepare('INSERT INTO spec_attrs (category_id, name, unit, type, help, filterable, sort) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute($fields);
                $id = (int)$pdo->lastInsertId();
            }
            respond(['ok' => true, 'id' => $id]);

        case 'spec_delete':

            $st = $pdo->prepare('DELETE FROM spec_attrs WHERE id = ?');
            $st->execute([(int)($_POST['id'] ?? 0)]);
            if (!$st->rowCount()) respond(['ok' => false, 'message' => 'Характеристику не знайдено.'], 404);
            respond(['ok' => true]);

        case 'product_info':
            respond(['ok' => true, 'info' => productInfo($pdo)]);

        case 'product_info_save':
            $info = ['payment' => postText('payment'), 'warranty' => postText('warranty')];
            foreach ($info as $text) {
                if (mb_strlen($text) > 400) respond(['ok' => false, 'message' => 'Текст задовгий (до 400 символів).'], 422);
            }
            $pdo->prepare("INSERT INTO site_meta (key, value) VALUES ('product_info', ?)
                           ON CONFLICT (key) DO UPDATE SET value = excluded.value")
                ->execute([json_encode($info, JSON_UNESCAPED_UNICODE)]);
            respond(['ok' => true, 'info' => productInfo($pdo)]);

        case 'counters':
            respond(['ok' => true, 'base' => countersBase($pdo), 'shown' => siteCounters($pdo)]);

        case 'counters_save':
            $repaired = (int)($_POST['repaired'] ?? -1);
            $refilled = (int)($_POST['refilled'] ?? -1);
            if ($repaired < 0 || $refilled < 0 || $repaired > 100000000 || $refilled > 100000000) {
                respond(['ok' => false, 'message' => 'Вкажіть невід\'ємні числа.'], 422);
            }
            countersSave($pdo, $repaired, $refilled);
            respond(['ok' => true, 'base' => countersBase($pdo), 'shown' => siteCounters($pdo)]);

        case 'search_synonyms':
            respond(['ok' => true, 'text' => searchSynonyms($pdo), 'fulltext' => searchEnsure($pdo)]);

        case 'search_synonyms_save':
            $text = trim(str_replace("\r", '', mb_scrub((string)($_POST['text'] ?? ''), 'UTF-8')));
            if (mb_strlen($text) > 20000) respond(['ok' => false, 'message' => 'Список задовгий.'], 422);
            if ($text === '') {
                $pdo->exec("DELETE FROM site_meta WHERE key = 'search_synonyms'");
            } else {
                $pdo->prepare("INSERT INTO site_meta (key, value) VALUES ('search_synonyms', ?)
                               ON CONFLICT (key) DO UPDATE SET value = excluded.value")->execute([$text]);
            }
            respond(['ok' => true, 'text' => searchSynonyms($pdo)]);

        case 'product_delete':
            $st = $pdo->prepare('SELECT image FROM products WHERE id = ?');
            $st->execute([(int)($_POST['id'] ?? 0)]);
            $row = $st->fetch();
            if (!$row) respond(['ok' => false, 'message' => 'Товар не знайдено.'], 404);
            $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([(int)$_POST['id']]);
            deleteImage($row['image']);
            respond(['ok' => true]);

        case 'category_save':
            $id       = (int)($_POST['id'] ?? 0);
            $name     = postText('name');
            $parentId = (int)($_POST['parent_id'] ?? 0) ?: null;
            $sort     = (int)($_POST['sort'] ?? 0);

            $errors = [];
            if ($name === '')               $errors['name'] = 'Вкажіть назву категорії';
            elseif (mb_strlen($name) > 120) $errors['name'] = 'Назва задовга';
            if ($parentId !== null) {

                $cats = array_column($pdo->query('SELECT id, parent_id FROM categories')->fetchAll(), 'parent_id', 'id');
                if (!array_key_exists($parentId, $cats)) {
                    $errors['parent_id'] = 'Категорію не знайдено';
                } elseif ($id) {
                    for ($p = $parentId; $p !== null; $p = $cats[$p] === null ? null : (int)$cats[$p]) {
                        if ($p === $id) { $errors['parent_id'] = 'Категорія не може бути вкладена сама в себе'; break; }
                    }
                }
            }
            if ($errors) respond(['ok' => false, 'errors' => $errors], 422);

            if ($id) {
                $st = $pdo->prepare('UPDATE categories SET name = ?, parent_id = ?, sort = ? WHERE id = ?');
                $st->execute([$name, $parentId, $sort, $id]);
                if (!$st->rowCount()) {
                    $check = $pdo->prepare('SELECT 1 FROM categories WHERE id = ?');
                    $check->execute([$id]);
                    if (!$check->fetchColumn()) respond(['ok' => false, 'message' => 'Категорію не знайдено.'], 404);
                }
            } else {
                $pdo->prepare('INSERT INTO categories (name, parent_id, sort) VALUES (?, ?, ?)')->execute([$name, $parentId, $sort]);
                $id = (int)$pdo->lastInsertId();
            }
            respond(['ok' => true, 'id' => $id]);

        case 'category_delete':
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE parent_id = ?');
            $st->execute([$id]);
            if ($st->fetchColumn()) {
                respond(['ok' => false, 'message' => 'Спочатку видаліть або перенесіть підкатегорії.'], 422);
            }

            $st = $pdo->prepare('DELETE FROM categories WHERE id = ?');
            $st->execute([$id]);
            if (!$st->rowCount()) respond(['ok' => false, 'message' => 'Категорію не знайдено.'], 404);
            respond(['ok' => true]);

        default:
            respond(['ok' => false, 'message' => 'Невідома дія.'], 400);
    }
} catch (Throwable $e) {
    error_log('api/admin.php: ' . $e->getMessage());
    respond(['ok' => false, 'message' => 'Помилка сервера. Спробуйте пізніше.'], 500);
}
