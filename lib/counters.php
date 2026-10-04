<?php
const COUNTERS_DEFAULT = ['repaired' => 3659, 'refilled' => 48565, 'since' => ''];

function countersBase(PDO $pdo): array
{
    $raw = $pdo->query("SELECT value FROM site_meta WHERE key = 'counters'")->fetchColumn();
    $saved = $raw ? json_decode($raw, true) : null;
    return (is_array($saved) ? $saved : []) + COUNTERS_DEFAULT;
}

function siteCounters(PDO $pdo): array
{
    $base = countersBase($pdo);
    $st = $pdo->prepare("SELECT o.id,
                                SUM(CASE WHEN ulower(i.name) LIKE 'заправ%' THEN i.qty ELSE 0 END) AS refills
                         FROM repair_orders o JOIN repair_items i ON i.order_id = o.id AND i.kind = 'work'
                         WHERE o.closed_at IS NOT NULL AND o.closed_at > ?
                         GROUP BY o.id");
    $st->execute([(string)$base['since']]);
    $repaired = (int)$base['repaired'];
    $refilled = (int)$base['refilled'];
    foreach ($st->fetchAll() as $row) {
        if ($row['refills'] > 0) $refilled += (int)round($row['refills']);
        else $repaired++;
    }
    return ['repaired' => $repaired, 'refilled' => $refilled];
}

function countersSave(PDO $pdo, int $repaired, int $refilled): void
{
    $pdo->prepare("INSERT INTO site_meta (key, value) VALUES ('counters', ?)
                   ON CONFLICT (key) DO UPDATE SET value = excluded.value")
        ->execute([json_encode(['repaired' => $repaired, 'refilled' => $refilled, 'since' => gmdate('Y-m-d H:i:s')])]);
}
