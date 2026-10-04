<?php
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/uploads.php';

function serviceRow(array $s): array
{
    $prices = json_decode($s['prices'] ?: '[]', true);
    return [
        'id'          => (int)$s['id'],
        'parent_id'   => $s['parent_id'] === null ? null : (int)$s['parent_id'],
        'slug'        => $s['slug'],
        'name'        => $s['name'],
        'summary'     => $s['summary'],
        'description' => $s['description'],
        'diag_price'  => $s['diag_price'] === null ? null : (float)$s['diag_price'],
        'price_from'  => $s['price_from'] === null ? null : (float)$s['price_from'],
        'term'        => $s['term'],
        'prices'      => is_array($prices) ? $prices : [],
        'image'       => $s['image'],
        'sort'        => (int)$s['sort'],
        'visible'     => (bool)$s['visible'],
    ];
}

function amount($value): ?float
{
    $value = str_replace([' ', ','], ['', '.'], trim((string)$value));
    if ($value === '') return null;
    return is_numeric($value) && (float)$value >= 0 ? round((float)$value, 2) : NAN;
}

startSession();
requireSameOrigin();
$action = $_GET['action'] ?? '';
$user = currentUser();
$isAdmin = $user && $user['is_admin'];

try {
    $pdo = db();

    switch ($action) {
        case 'list':
            $rows = $pdo->query('SELECT * FROM services' . ($isAdmin ? '' : ' WHERE visible = 1') . ' ORDER BY sort, id')->fetchAll();
            respond(['ok' => true, 'services' => array_map('serviceRow', $rows)]);

        case 'get':
            if (!empty($_GET['slug'])) {
                $st = $pdo->prepare('SELECT * FROM services WHERE slug = ?');
                $st->execute([(string)$_GET['slug']]);
            } else {
                $st = $pdo->prepare('SELECT * FROM services WHERE id = ?');
                $st->execute([(int)($_GET['id'] ?? 0)]);
            }
            $s = $st->fetch();
            if (!$s || (!$s['visible'] && !$isAdmin)) respond(['ok' => false, 'message' => 'Послугу не знайдено.'], 404);

            $st = $pdo->prepare('SELECT * FROM services WHERE parent_id = ?' . ($isAdmin ? '' : ' AND visible = 1') . ' ORDER BY sort, id');
            $st->execute([$s['id']]);
            $children = array_map('serviceRow', $st->fetchAll());

            $path = [];
            $parentId = $s['parent_id'];
            $stParent = $pdo->prepare('SELECT * FROM services WHERE id = ?');
            while ($parentId !== null) {
                $stParent->execute([$parentId]);
                $p = $stParent->fetch();
                if (!$p) break;
                array_unshift($path, serviceRow($p));
                $parentId = $p['parent_id'];
            }
            respond(['ok' => true, 'service' => serviceRow($s), 'children' => $children, 'path' => $path]);

        case 'save':
        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
                respond(['ok' => false, 'message' => 'Метод не дозволено.'], 405);
            }
            requireAdmin();

            if ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                $st = $pdo->prepare('SELECT COUNT(*) FROM services WHERE parent_id = ?');
                $st->execute([$id]);
                if ($st->fetchColumn()) respond(['ok' => false, 'message' => 'Спочатку видаліть або перенесіть підрозділи.'], 422);
                $st = $pdo->prepare('SELECT image FROM services WHERE id = ?');
                $st->execute([$id]);
                $image = $st->fetchColumn();
                if ($image === false) respond(['ok' => false, 'message' => 'Послугу не знайдено.'], 404);
                $pdo->prepare('DELETE FROM services WHERE id = ?')->execute([$id]);
                deleteImageUpload($image);
                respond(['ok' => true]);
            }

            $id        = (int)($_POST['id'] ?? 0);
            $parentId  = (int)($_POST['parent_id'] ?? 0) ?: null;
            $name      = postText('name');
            $summary   = postText('summary');
            $desc      = str_replace(["\r\n", "\r"], "\n", postText('description'));
            $term      = postText('term');
            $diag      = amount($_POST['diag_price'] ?? '');
            $from      = amount($_POST['price_from'] ?? '');

            $errors = [];
            if ($name === '')                 $errors['name'] = 'Вкажіть назву послуги';
            elseif (mb_strlen($name) > 150)   $errors['name'] = 'Назва задовга';
            if (mb_strlen($summary) > 300)    $errors['summary'] = 'Короткий опис задовгий (до 300 символів)';
            if (mb_strlen($desc) > 20000)     $errors['description'] = 'Опис задовгий';
            if (mb_strlen($term) > 120)       $errors['term'] = 'Задовго';
            if ($diag !== null && is_nan($diag)) $errors['diag_price'] = 'Некоректна сума';
            if ($from !== null && is_nan($from)) $errors['price_from'] = 'Некоректна сума';

            $prices = [];
            foreach ((array)json_decode((string)($_POST['prices'] ?? '[]'), true) as $row) {
                $rowName = trim(mb_scrub((string)($row['name'] ?? ''), 'UTF-8'));
                $rowPrice = trim(mb_scrub((string)($row['price'] ?? ''), 'UTF-8'));
                if ($rowName === '' && $rowPrice === '') continue;
                if ($rowName === '' || mb_strlen($rowName) > 200 || mb_strlen($rowPrice) > 60) { $errors['prices'] = 'Перевірте рядки прайсу'; break; }
                $prices[] = ['name' => $rowName, 'price' => $rowPrice];
            }
            if ($parentId !== null) {
                $all = array_column($pdo->query('SELECT id, parent_id FROM services')->fetchAll(), 'parent_id', 'id');
                if (!array_key_exists($parentId, $all)) $errors['parent_id'] = 'Розділ не знайдено';
                elseif ($id) {
                    for ($p = $parentId; $p !== null; $p = $all[$p] === null ? null : (int)$all[$p]) {
                        if ($p === $id) { $errors['parent_id'] = 'Послуга не може бути вкладена сама в себе'; break; }
                    }
                }
            }
            $existing = null;
            if ($id) {
                $st = $pdo->prepare('SELECT * FROM services WHERE id = ?');
                $st->execute([$id]);
                $existing = $st->fetch();
                if (!$existing) respond(['ok' => false, 'message' => 'Послугу не знайдено.'], 404);
            }
            if ($errors) respond(['ok' => false, 'errors' => $errors], 422);

            $image = $existing['image'] ?? null;
            if (hasUpload('image')) {
                $new = saveImageUpload($_FILES['image'], 'services');
                deleteImageUpload($image);
                $image = $new;
            } elseif (!empty($_POST['remove_image'])) {
                deleteImageUpload($image);
                $image = null;
            }

            $values = [$parentId, $name, $summary, $desc, $diag, $from, $term, json_encode($prices, JSON_UNESCAPED_UNICODE),
                       $image, (int)($_POST['sort'] ?? 0), empty($_POST['visible']) ? 0 : 1];
            if ($id) {
                $pdo->prepare('UPDATE services SET parent_id = ?, name = ?, summary = ?, description = ?, diag_price = ?, price_from = ?,
                                      term = ?, prices = ?, image = ?, sort = ?, visible = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
                    ->execute([...$values, $id]);
            } else {
                $pdo->prepare('INSERT INTO services (parent_id, name, summary, description, diag_price, price_from, term, prices, image, sort, visible)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute($values);
                $id = (int)$pdo->lastInsertId();
            }
            respond(['ok' => true, 'id' => $id]);

        default:
            respond(['ok' => false, 'message' => 'Невідома дія.'], 400);
    }
} catch (Throwable $e) {
    error_log('api/services.php: ' . $e->getMessage());
    respond(['ok' => false, 'message' => 'Помилка сервера. Спробуйте пізніше.'], 500);
}
