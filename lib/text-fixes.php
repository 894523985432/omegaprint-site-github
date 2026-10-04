<?php
const TEXT_FIXES_VERSION = '1';
const TEXT_FIXES_WORDS = [
    'Зворотній' => 'Зворотний', 'зворотній' => 'зворотний',
    'Ріжучих' => 'Різальних', 'ріжучих' => 'різальних', 'Ріжучі' => 'Різальні', 'ріжучі' => 'різальні',
    'Ріжучий' => 'Різальний', 'ріжучий' => 'різальний', 'МФУ' => 'БФП', 'Плоттер' => 'Плотер', 'плоттер' => 'плотер',
];

function textFix(string $s): string
{
    $s = preg_replace(['/(?<=[А-Яа-яЇїІіЄєҐґ])i|i(?=[А-Яа-яЇїІіЄєҐґ])/u', '/(?<=[А-Яа-яЇїІіЄєҐґ])I|I(?=[А-Яа-яЇїІіЄєҐґ])/u'], ['і', 'І'], $s);
    return strtr($s, TEXT_FIXES_WORDS);
}

function textFixes(PDO $pdo): void
{
    if ($pdo->query("SELECT value FROM site_meta WHERE key = 'text_fixes'")->fetchColumn() === TEXT_FIXES_VERSION) return;
    $pdo->exec('BEGIN IMMEDIATE');
    foreach (['services', 'categories'] as $table) {
        $upd = $pdo->prepare("UPDATE $table SET name = ? WHERE id = ?");
        foreach ($pdo->query("SELECT id, name FROM $table")->fetchAll() as $r) {
            $fixed = textFix($r['name']);
            if ($fixed !== $r['name']) $upd->execute([$fixed, $r['id']]);
        }
    }
    $pdo->prepare("INSERT INTO site_meta (key, value) VALUES ('text_fixes', ?) ON CONFLICT (key) DO UPDATE SET value = excluded.value")
        ->execute([TEXT_FIXES_VERSION]);
    $pdo->exec('COMMIT');
}

const ASSET_PATHS_VERSION = '1';

function assetPathFixes(PDO $pdo): void
{
    if ($pdo->query("SELECT value FROM site_meta WHERE key = 'asset_paths'")->fetchColumn() === ASSET_PATHS_VERSION) return;
    $root = dirname(__DIR__) . '/';
    $pdo->exec('BEGIN IMMEDIATE');
    $upd = $pdo->prepare('UPDATE services SET image = ? WHERE id = ?');
    foreach ($pdo->query('SELECT id, image FROM services WHERE image IS NOT NULL')->fetchAll() as $r) {
        if (preg_match('#^(?:about|contacts|delivery|placing_an_order|repair|reviews|sale|services|tray)/([^/]+)$#', $r['image'], $m)
            && is_file($root . 'home/' . $m[1])) {
            $upd->execute(['home/' . $m[1], $r['id']]);
        }
    }
    $pdo->prepare("INSERT INTO site_meta (key, value) VALUES ('asset_paths', ?) ON CONFLICT (key) DO UPDATE SET value = excluded.value")
        ->execute([ASSET_PATHS_VERSION]);
    $pdo->exec('COMMIT');
}
