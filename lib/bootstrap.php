<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

$GLOBALS['config'] = require dirname(__DIR__) . '/config.php';

function config(string $key, $default = null)
{
    return $GLOBALS['config'][$key] ?? $default;
}

require_once __DIR__ . '/access-log.php';
accessLogStart();

function startSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',

        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
    ]);
    session_start();
}

function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function postText(string $key): string
{
    return trim(mb_scrub((string)($_POST[$key] ?? ''), 'UTF-8'));
}

function requireSameOrigin(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') return;
    $port = parse_url($origin, PHP_URL_PORT);
    $originHost = parse_url($origin, PHP_URL_HOST) . ($port ? ':' . $port : '');
    if (strcasecmp($originHost, $_SERVER['HTTP_HOST'] ?? '') !== 0) {
        respond(['ok' => false, 'message' => 'Запит з іншого сайту відхилено.'], 403);
    }
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;

    $path = config('db_path', dirname(__DIR__, 2) . '/data/site.sqlite');
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        respond(['ok' => false, 'message' => 'Не вдалося створити папку для бази даних.'], 500);
    }
    $isNew = !is_file($path);

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->sqliteCreateFunction('ulower', fn($s) => mb_strtolower((string)$s), 1);
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        username      TEXT NOT NULL,
        email         TEXT NOT NULL UNIQUE COLLATE NOCASE,
        phone         TEXT NOT NULL,
        password_hash TEXT NOT NULL,
        is_admin      INTEGER NOT NULL DEFAULT 0,
        created_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS categories (
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        parent_id INTEGER REFERENCES categories(id) ON DELETE RESTRICT,
        name      TEXT NOT NULL,
        sort      INTEGER NOT NULL DEFAULT 0
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS products (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        sku         TEXT NOT NULL DEFAULT \'\',
        name        TEXT NOT NULL,
        category_id INTEGER REFERENCES categories(id) ON DELETE SET NULL,
        price       REAL NOT NULL DEFAULT 0,
        old_price   REAL,
        in_stock    INTEGER NOT NULL DEFAULT 1,
        popular     INTEGER NOT NULL DEFAULT 0,
        description TEXT NOT NULL DEFAULT \'\',
        image       TEXT,
        created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS products_category ON products(category_id)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS repair_statuses (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        name       TEXT NOT NULL,
        color      TEXT NOT NULL DEFAULT \'#6b7280\',
        sort       INTEGER NOT NULL DEFAULT 0,
        is_default INTEGER NOT NULL DEFAULT 0,
        is_closed  INTEGER NOT NULL DEFAULT 0
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS repair_orders (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        status_id     INTEGER REFERENCES repair_statuses(id) ON DELETE RESTRICT,
        client_name   TEXT NOT NULL DEFAULT \'\',
        client_phone  TEXT NOT NULL DEFAULT \'\',
        device_type   TEXT NOT NULL DEFAULT \'\',
        device_brand  TEXT NOT NULL DEFAULT \'\',
        device_model  TEXT NOT NULL DEFAULT \'\',
        serial        TEXT NOT NULL DEFAULT \'\',
        malfunction   TEXT NOT NULL DEFAULT \'\',
        complectation TEXT NOT NULL DEFAULT \'\',
        appearance    TEXT NOT NULL DEFAULT \'\',
        estimate      REAL,
        prepayment    REAL NOT NULL DEFAULT 0,
        due_date      TEXT,
        source        TEXT NOT NULL DEFAULT \'admin\',
        created_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        closed_at     TEXT
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS repair_orders_status ON repair_orders(status_id)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS repair_items (
        id       INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL REFERENCES repair_orders(id) ON DELETE CASCADE,
        kind     TEXT NOT NULL DEFAULT \'work\',
        name     TEXT NOT NULL,
        qty      REAL NOT NULL DEFAULT 1,
        price    REAL NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS repair_items_order ON repair_items(order_id)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS repair_comments (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id   INTEGER NOT NULL REFERENCES repair_orders(id) ON DELETE CASCADE,
        user_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        kind       TEXT NOT NULL DEFAULT \'comment\',
        text       TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS repair_comments_order ON repair_comments(order_id)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS repair_dicts (
        id    INTEGER PRIMARY KEY AUTOINCREMENT,
        kind  TEXT NOT NULL,
        value TEXT NOT NULL COLLATE NOCASE,
        price REAL,
        UNIQUE (kind, value)
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS services (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        parent_id   INTEGER REFERENCES services(id) ON DELETE RESTRICT,
        slug        TEXT,
        name        TEXT NOT NULL,
        summary     TEXT NOT NULL DEFAULT \'\',
        description TEXT NOT NULL DEFAULT \'\',
        diag_price  REAL,
        price_from  REAL,
        term        TEXT NOT NULL DEFAULT \'\',
        prices      TEXT NOT NULL DEFAULT \'[]\',
        image       TEXT,
        sort        INTEGER NOT NULL DEFAULT 0,
        visible     INTEGER NOT NULL DEFAULT 1,
        updated_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS site_content (
        key        TEXT PRIMARY KEY,
        type       TEXT NOT NULL DEFAULT \'html\',
        value      TEXT NOT NULL,
        updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS clients (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        phone_key  TEXT UNIQUE,
        phone      TEXT NOT NULL DEFAULT \'\',
        email      TEXT NOT NULL DEFAULT \'\',
        name       TEXT NOT NULL DEFAULT \'\',
        company    TEXT NOT NULL DEFAULT \'\',
        notes      TEXT NOT NULL DEFAULT \'\',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS clients_email ON clients(email)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS shop_orders (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        client_id     INTEGER REFERENCES clients(id) ON DELETE SET NULL,
        status        TEXT NOT NULL DEFAULT \'new\',
        last_name     TEXT NOT NULL DEFAULT \'\',
        first_name    TEXT NOT NULL DEFAULT \'\',
        middle_name   TEXT NOT NULL DEFAULT \'\',
        phone         TEXT NOT NULL DEFAULT \'\',
        email         TEXT NOT NULL DEFAULT \'\',
        delivery      TEXT NOT NULL DEFAULT \'pickup\',
        np_city       TEXT NOT NULL DEFAULT \'\',
        np_city_ref   TEXT NOT NULL DEFAULT \'\',
        np_warehouse  TEXT NOT NULL DEFAULT \'\',
        np_warehouse_ref TEXT NOT NULL DEFAULT \'\',
        address       TEXT NOT NULL DEFAULT \'\',
        payment       TEXT NOT NULL DEFAULT \'cash\',
        comment       TEXT NOT NULL DEFAULT \'\',
        call_back     INTEGER NOT NULL DEFAULT 0,
        total         REAL NOT NULL DEFAULT 0,
        review_token  TEXT UNIQUE,
        review_sms_at TEXT,
        created_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        done_at       TEXT
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS shop_order_items (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id   INTEGER NOT NULL REFERENCES shop_orders(id) ON DELETE CASCADE,
        product_id INTEGER,
        sku        TEXT NOT NULL DEFAULT \'\',
        name       TEXT NOT NULL,
        qty        INTEGER NOT NULL DEFAULT 1,
        price      REAL NOT NULL DEFAULT 0
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS reviews (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        order_type TEXT,
        order_id   INTEGER,
        client_id  INTEGER REFERENCES clients(id) ON DELETE SET NULL,
        name       TEXT NOT NULL DEFAULT \'\',
        stars      INTEGER NOT NULL DEFAULT 5,
        text       TEXT NOT NULL DEFAULT \'\',
        pros       TEXT NOT NULL DEFAULT \'\',
        cons       TEXT NOT NULL DEFAULT \'\',
        status     TEXT NOT NULL DEFAULT \'new\',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS sms_log (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        phone      TEXT NOT NULL,
        text       TEXT NOT NULL,
        status     TEXT NOT NULL,
        response   TEXT NOT NULL DEFAULT \'\',
        order_type TEXT,
        order_id   INTEGER,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    addColumn($pdo, 'repair_statuses', 'request_review', 'INTEGER NOT NULL DEFAULT 0');
    addColumn($pdo, 'repair_orders', 'client_id', 'INTEGER REFERENCES clients(id) ON DELETE SET NULL');
    addColumn($pdo, 'repair_orders', 'review_token', 'TEXT');
    addColumn($pdo, 'repair_orders', 'review_sms_at', 'TEXT');

    addColumn($pdo, 'users', 'is_admin', 'INTEGER NOT NULL DEFAULT 0');

    if (addColumn($pdo, 'products', 'availability', "TEXT NOT NULL DEFAULT 'in_stock'")) {
        $pdo->exec("UPDATE products SET availability = CASE WHEN in_stock = 1 THEN 'in_stock' ELSE 'absent' END");
    }
    addColumn($pdo, 'repair_orders', 'client_company', "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'repair_orders', 'edrpou', "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'repair_orders', 'messenger', "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'repair_items', 'code', "TEXT NOT NULL DEFAULT ''");

    if ($isNew || !$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn()) {
        require_once __DIR__ . '/seed.php';
        seedShop($pdo);
    }
    if (!$pdo->query('SELECT COUNT(*) FROM repair_dicts')->fetchColumn()) {
        require_once __DIR__ . '/seed.php';
        seedRepairDicts($pdo);
    }

    $pdo->exec('CREATE TABLE IF NOT EXISTS site_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
    if (!$pdo->query("SELECT 1 FROM site_meta WHERE key = 'repair_clients_linked'")->fetchColumn()) {
        require_once __DIR__ . '/clients.php';
        foreach ($pdo->query('SELECT id, client_phone, client_name, client_company FROM repair_orders WHERE client_id IS NULL AND client_phone <> \'\'')->fetchAll() as $o) {
            $cid = clientUpsert($pdo, $o['client_phone'], '', $o['client_name'], $o['client_company']);
            if ($cid) $pdo->prepare('UPDATE repair_orders SET client_id = ? WHERE id = ?')->execute([$cid, $o['id']]);
        }
        $pdo->exec("INSERT OR REPLACE INTO site_meta (key, value) VALUES ('repair_clients_linked', '1')");
    }
    if (!$pdo->query('SELECT COUNT(*) FROM services')->fetchColumn()) {
        require_once __DIR__ . '/seed-services.php';
        seedServices($pdo);
    }

    require_once __DIR__ . '/specs.php';
    specsSchema($pdo);

    require_once __DIR__ . '/bonus.php';
    bonusSchema($pdo);

    require_once __DIR__ . '/tgbot.php';
    tgSchema($pdo);

    require_once __DIR__ . '/catalog-seed.php';
    catalogSeed($pdo);
    specsSeed($pdo);
    specsCountryByBrand($pdo);
    require_once __DIR__ . '/service-images.php';
    serviceImages($pdo);
    require_once __DIR__ . '/text-fixes.php';
    textFixes($pdo);
    assetPathFixes($pdo);
    return $pdo;
}

function addColumn(PDO $pdo, string $table, string $column, string $definition): bool
{
    $cols = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
    if (in_array($column, $cols, true)) return false;
    $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    return true;
}

function siteMail(string $to, string $subject, string $body): bool
{
    $host = parse_url((string)config('site_url', ''), PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (getenv('SITE_TELEGRAM_OFF')) {
        return (bool)@file_put_contents(sys_get_temp_dir() . '/omega-mail-test.log', json_encode([$to, $subject, $body], JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
    }
    $headers = "From: OmegaPrint <noreply@$host>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";
    $ok = @mail($to, mb_encode_mimeheader($subject, 'UTF-8', 'B'), $body, $headers, '-fnoreply@' . $host);
    if (!$ok) error_log("siteMail: не вдалося надіслати лист на $to");
    return $ok;
}

function isAdminEmail(string $email): bool
{
    $admins = array_map('mb_strtolower', config('admin_emails', []));
    return in_array(mb_strtolower(trim($email)), $admins, true);
}

function publicUser(array $u): array
{
    return [
        'id'       => (int)$u['id'],
        'username' => $u['username'],
        'email'    => $u['email'],
        'phone'    => $u['phone'],
        'is_admin' => (bool)$u['is_admin'],
    ];
}

function currentUser(): ?array
{
    startSession();
    if (empty($_SESSION['user_id'])) return null;
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$_SESSION['user_id']]);
    $u = $st->fetch();
    return $u ? publicUser($u) : null;
}

function requireAdmin(): array
{
    $user = currentUser();
    if (!$user) respond(['ok' => false, 'message' => 'Увійдіть в обліковий запис адміністратора.'], 401);
    if (!$user['is_admin']) respond(['ok' => false, 'message' => 'Недостатньо прав.'], 403);
    return $user;
}
