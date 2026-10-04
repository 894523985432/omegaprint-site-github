<?php
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/import.php';

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
        case 'settings':

            $skus = [];
            foreach ($pdo->query("SELECT sku, category_id FROM products WHERE sku <> ''")->fetchAll() as $p) {
                $skus[$p['sku']] = $p['category_id'] === null ? 0 : (int)$p['category_id'];
            }
            respond(['ok' => true, 'settings' => importSettings($pdo) ?: new stdClass(), 'skus' => $skus ?: new stdClass()]);

        case 'run':
            $body = json_decode(file_get_contents('php://input'), true);
            if (!is_array($body) || !isset($body['rows']) || !is_array($body['rows'])) {
                respond(['ok' => false, 'message' => 'Некоректні дані.'], 400);
            }
            if (count($body['rows']) > IMPORT_MAX_ROWS) {
                respond(['ok' => false, 'message' => 'Забагато рядків за один раз (максимум ' . IMPORT_MAX_ROWS . '). Розділіть файл.'], 422);
            }
            set_time_limit(300);
            $dry = !empty($body['dry']);
            $result = importProducts($pdo, $body['rows'], is_array($body['options'] ?? null) ? $body['options'] : [], $dry);
            if (!$dry && is_array($body['settings'] ?? null)) importSettingsSave($pdo, $body['settings']);
            respond(['ok' => true, 'dry' => $dry] + $result);

        default:
            respond(['ok' => false, 'message' => 'Невідома дія.'], 400);
    }
} catch (Throwable $e) {
    error_log('api/import.php: ' . $e->getMessage());
    respond(['ok' => false, 'message' => 'Помилка сервера. Спробуйте пізніше.'], 500);
}
