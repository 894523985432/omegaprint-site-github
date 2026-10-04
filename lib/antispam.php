<?php
const ANTISPAM_KEEP = 86400;

function antispamSchema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS rate_hits (
        bucket TEXT NOT NULL,
        who    TEXT NOT NULL,
        ts     INTEGER NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS rate_hits_lookup ON rate_hits (bucket, who, ts)');
}

function antispamWho(): string
{
    return substr(hash('sha256', 'omega-rate|' . ($_SERVER['REMOTE_ADDR'] ?? 'cli') . '|' . __DIR__), 0, 24);
}

function antispamCount(PDO $pdo, string $bucket, int $seconds, ?string $who = null): int
{
    antispamSchema($pdo);
    $st = $pdo->prepare('SELECT COUNT(*) FROM rate_hits WHERE bucket = ? AND who = ? AND ts > ?');
    $st->execute([$bucket, $who ?? antispamWho(), time() - $seconds]);
    return (int)$st->fetchColumn();
}

function antispamHit(PDO $pdo, string $bucket, ?string $who = null): void
{
    antispamSchema($pdo);
    $now = time();
    $pdo->prepare('INSERT INTO rate_hits (bucket, who, ts) VALUES (?, ?, ?)')->execute([$bucket, $who ?? antispamWho(), $now]);
    if (mt_rand(1, 50) === 1) $pdo->prepare('DELETE FROM rate_hits WHERE ts < ?')->execute([$now - ANTISPAM_KEEP]);
}

function antispamLimit(PDO $pdo, string $bucket, array $limits, ?string $who = null): bool
{
    foreach ($limits as [$count, $seconds]) {
        if (antispamCount($pdo, $bucket, $seconds, $who) >= $count) return false;
    }
    antispamHit($pdo, $bucket, $who);
    return true;
}

function antispamFormCheck(array $post): ?string
{
    if (trim((string)($post['contact_url'] ?? '')) !== '') return 'bot';
    if (($post['_human'] ?? '') !== '1') return 'nojs';
    $elapsed = (int)($post['_elapsed'] ?? 0);
    if ($elapsed > 0 && $elapsed < 2500) return 'bot';
    return null;
}

const ANTISPAM_ORDER_CAPTCHA_SEC = 1800;

function antispamOrderNeedsCaptcha(PDO $pdo): bool
{
    if (antispamCount($pdo, 'order', ANTISPAM_ORDER_CAPTCHA_SEC) > 0) return true;
    return session_status() === PHP_SESSION_ACTIVE && ($_SESSION['last_order_at'] ?? 0) > time() - ANTISPAM_ORDER_CAPTCHA_SEC;
}

function antispamOrderPlaced(PDO $pdo): void
{
    antispamHit($pdo, 'order');
    if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['last_order_at'] = time();
}

function antispamCaptchaValid(string $answer): bool
{
    $c = $_SESSION['captcha'] ?? null;
    unset($_SESSION['captcha']);
    $answer = preg_replace('/\D/', '', $answer);
    return $c && $c['expires'] >= time() && $answer !== '' && hash_equals($c['hash'], hash('sha256', $answer));
}

const ANTISPAM_ACCOUNTS_PER_IP = 10;

function antispamBanSchema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS ip_bans (
        who        TEXT PRIMARY KEY,
        reason     TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    addColumn($pdo, 'users', 'reg_who', "TEXT NOT NULL DEFAULT ''");
}

function antispamBanned(PDO $pdo, ?string $who = null): bool
{
    antispamBanSchema($pdo);
    $st = $pdo->prepare('SELECT 1 FROM ip_bans WHERE who = ?');
    $st->execute([$who ?? antispamWho()]);
    return (bool)$st->fetchColumn();
}

function antispamRegistrationAllowed(PDO $pdo): bool
{
    antispamBanSchema($pdo);
    $who = antispamWho();
    $st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE reg_who = ?');
    $st->execute([$who]);
    if ((int)$st->fetchColumn() < ANTISPAM_ACCOUNTS_PER_IP) return true;
    $pdo->prepare("INSERT OR IGNORE INTO ip_bans (who, reason) VALUES (?, ?)")
        ->execute([$who, 'Спроба зареєструвати понад ' . ANTISPAM_ACCOUNTS_PER_IP . ' акаунтів']);
    return false;
}
