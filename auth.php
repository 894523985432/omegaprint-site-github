<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/antispam.php';

startSession();
requireSameOrigin();

$action = $_GET['action'] ?? '';
if ($action !== 'me' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['ok' => false, 'message' => 'Метод не дозволено.'], 405);
}

try {
    $pdo = db();

    switch ($action) {
        case 'me':
            require __DIR__ . '/lib/counters.php';
            respond(['ok' => true, 'user' => currentUser(), 'counters' => siteCounters($pdo)]);

        case 'register':

            if (antispamBanned($pdo) || !antispamRegistrationAllowed($pdo)) {
                respond(['ok' => false, 'message' => 'З вашої мережі зареєстровано забагато акаунтів, реєстрацію заблоковано. Зателефонуйте нам: +380 (95) 501-19-70.'], 403);
            }
            $in = $_POST['Signup'] ?? [];
            $username = trim(mb_scrub((string)($in['username'] ?? ''), 'UTF-8'));
            $email    = trim((string)($in['email'] ?? ''));
            $phone    = trim((string)($in['phone'] ?? ''));
            $password = (string)($in['password_hash'] ?? '');
            $repeat   = (string)($in['password_repeat'] ?? '');

            $errors = [];
            if ($username === '')                       $errors['username'] = 'Необхідно заповнити це поле';
            elseif (mb_strlen($username) > 32)          $errors['username'] = 'Перевищена допустима довжина';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Некоректний email';
            if (strlen(preg_replace('/\D/', '', $phone)) < 10) $errors['phone'] = 'Вкажіть номер телефону';
            if (strlen($password) < 6)                  $errors['password_hash'] = 'Пароль має містити щонайменше 6 символів';
            if ($password !== $repeat)                  $errors['password_repeat'] = 'Паролі не збігаються';

            if (!isset($errors['email'])) {
                $st = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
                $st->execute([$email]);
                if ($st->fetchColumn()) $errors['email'] = 'Цей email вже зареєстрований';
            }
            if ($errors) respond(['ok' => false, 'errors' => $errors], 422);

            $st = $pdo->prepare('INSERT INTO users (username, email, phone, password_hash, is_admin, reg_who) VALUES (?, ?, ?, ?, ?, ?)');
            $st->execute([$username, $email, $phone, password_hash($password, PASSWORD_DEFAULT), isAdminEmail($email) ? 1 : 0, antispamWho()]);

            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$pdo->lastInsertId();
            respond(['ok' => true, 'user' => currentUser()]);

        case 'login':
            $in = $_POST['Signin'] ?? [];
            $email    = trim((string)($in['email'] ?? ''));
            $password = (string)($in['password_hash'] ?? '');

            $fails = $_SESSION['login_fails'] ?? 0;
            if ($fails >= 5) sleep(min($fails - 4, 5));
            if (antispamBanned($pdo)) {
                respond(['ok' => false, 'errors' => ['password_hash' => 'Доступ з вашої мережі заблоковано. Зателефонуйте нам: +380 (95) 501-19-70.']], 403);
            }

            if (antispamCount($pdo, 'login_fail', 900) >= 20) {
                respond(['ok' => false, 'errors' => ['password_hash' => 'Забагато невдалих спроб. Спробуйте через 15 хвилин.']], 429);
            }

            $st = $pdo->prepare('SELECT * FROM users WHERE email = ?');
            $st->execute([$email]);
            $u = $st->fetch();
            if (!$u || !password_verify($password, $u['password_hash'])) {
                $_SESSION['login_fails'] = $fails + 1;
                antispamHit($pdo, 'login_fail');
                respond(['ok' => false, 'errors' => ['password_hash' => 'Невірний email або пароль']], 401);
            }

            if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
            }

            $isAdmin = isAdminEmail($u['email']) ? 1 : 0;
            if ((int)$u['is_admin'] !== $isAdmin) {
                $pdo->prepare('UPDATE users SET is_admin = ? WHERE id = ?')->execute([$isAdmin, $u['id']]);
            }

            session_regenerate_id(true);
            unset($_SESSION['login_fails']);
            $_SESSION['user_id'] = (int)$u['id'];
            respond(['ok' => true, 'user' => currentUser()]);

        case 'reset_request':

            $done = ['ok' => true, 'message' => 'Якщо цей email зареєстровано, ми надіслали на нього лист із посиланням для нового пароля. Посилання діє 1 годину.'];
            $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['ok' => false, 'errors' => ['email' => 'Некоректний email']], 422);
            if (antispamBanned($pdo) || !antispamLimit($pdo, 'reset', [[3, 3600], [10, 86400]])
                || !antispamLimit($pdo, 'reset_mail', [[3, 86400]], substr(hash('sha256', $email), 0, 24))) {
                respond(['ok' => false, 'message' => 'Забагато запитів. Спробуйте пізніше або зателефонуйте нам: +380 (95) 501-19-70.'], 429);
            }
            $st = $pdo->prepare('SELECT id, username, email FROM users WHERE email = ?');
            $st->execute([$email]);
            $u = $st->fetch();
            if (!$u) respond($done);
            $pdo->exec('CREATE TABLE IF NOT EXISTS password_resets (token_hash TEXT PRIMARY KEY, user_id INTEGER NOT NULL, expires INTEGER NOT NULL)');
            $pdo->prepare('DELETE FROM password_resets WHERE user_id = ? OR expires < ?')->execute([$u['id'], time()]);
            $token = bin2hex(random_bytes(24));
            $pdo->prepare('INSERT INTO password_resets (token_hash, user_id, expires) VALUES (?, ?, ?)')
                ->execute([hash('sha256', $token), $u['id'], time() + 3600]);
            $base = rtrim((string)config('site_url', ''), '/') ?: ((($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? ''));
            $link = $base . '/account.html?reset=' . $token;
            $body = "Вітаємо, {$u['username']}!\n\nХтось (найімовірніше, ви) попросив змінити пароль до облікового запису на сайті OmegaPrint.\n"
                . "Щоб задати новий пароль, відкрийте посилання (діє 1 годину):\n\n$link\n\n"
                . "Якщо ви цього не просили, просто проігноруйте лист - пароль не зміниться.\n\nСервісний центр OmegaPrint\n+380 (95) 501-19-70";
            siteMail($u['email'], 'Відновлення пароля - OmegaPrint', $body);
            respond($done);

        case 'reset_confirm':
            $token = (string)($_POST['token'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $errors = [];
            if (strlen($password) < 6) $errors['password'] = 'Пароль має містити щонайменше 6 символів';
            if ($password !== (string)($_POST['repeat'] ?? '')) $errors['repeat'] = 'Паролі не збігаються';
            if ($errors) respond(['ok' => false, 'errors' => $errors], 422);
            $pdo->exec('CREATE TABLE IF NOT EXISTS password_resets (token_hash TEXT PRIMARY KEY, user_id INTEGER NOT NULL, expires INTEGER NOT NULL)');
            $st = $pdo->prepare('SELECT user_id FROM password_resets WHERE token_hash = ? AND expires >= ?');
            $st->execute([hash('sha256', $token), time()]);
            $userId = $st->fetchColumn();
            if (!$userId) respond(['ok' => false, 'message' => 'Посилання недійсне або вже використане. Попросіть новий лист: «Вхід» → «Забули пароль?».'], 410);
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
            $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$userId]);
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$userId;
            respond(['ok' => true, 'user' => currentUser()]);

        case 'logout':
            $_SESSION = [];
            session_destroy();
            respond(['ok' => true]);

        default:
            respond(['ok' => false, 'message' => 'Невідома дія.'], 400);
    }
} catch (Throwable $e) {
    error_log('auth.php: ' . $e->getMessage());
    respond(['ok' => false, 'message' => 'Помилка сервера. Спробуйте пізніше.'], 500);
}
