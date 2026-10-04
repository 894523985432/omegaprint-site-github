<?php
const SERVICE_IMAGES_VERSION = '1';

const SERVICE_IMAGES = [
    'office' => [
        'Біндери (брошурувальники)'            => 'services/svc-binder.jpg',
        'Інженерні системи (цифрові)'          => 'icons/svc/wideformat.svg',
        'Копіювальна техніка'                  => 'icons/svc/copier.svg',
        'Ламінатори'                           => 'services/svc-laminator.jpg',
        'Принтери і БФП (лазерні, струменеві)' => 'home/cacf2194d373a6e1a165f053ec8c016a.png',
        'Проектори'                            => 'icons/svc/projector.svg',
        'Різка для паперу'                     => 'services/svc-cutter.jpg',
        'Сканери'                              => 'icons/svc/scanner.svg',
        'Знищувачі документів (шредери)'       => 'services/svc-shredder.jpg',
        'Факси'                                => 'icons/svc/fax.svg',
    ],
    'polygraph' => [
        'Ламінатори'                     => 'services/svc-laminator-pro.jpg',
        'Плотери'                       => 'services/svc-plotter.jpg',
        'Принтери (лазерні, струменеві)' => 'home/cacf2194d373a6e1a165f053ec8c016a.png',
        'Різаки й гільйотини'            => 'services/svc-guillotine.jpg',
        'Термоклейові машини'            => 'services/svc-thermobinder.jpg',
    ],
    'textile' => [
        'Вакуумні термопреси'              => 'icons/svc/vacuumpress.svg',
        'Планшетні принтери'               => 'icons/svc/flatbed.svg',
        'Різальні плотери'                  => 'services/svc-cutplotter.jpg',
        'Сублімаційні принтери (плотери)' => 'icons/svc/wideformat.svg',
        'Текстильні плотери'              => 'icons/svc/textile.svg',
        'Широкоформатні термопреси'        => 'icons/svc/heatpress.svg',
    ],
    'advertising' => [
        'Вакуумні термопреси'       => 'icons/svc/vacuumpress.svg',
        'Різальні плотери'           => 'services/svc-cutplotter2.jpg',
        'Термопреси (чашкові, кепкові, тарілкові, текстильні)' => 'icons/svc/mugpress.svg',
        'Широкоформатні термопреси' => 'icons/svc/heatpress.svg',
    ],
];

function serviceImages(PDO $pdo): void
{
    if ($pdo->query("SELECT value FROM site_meta WHERE key = 'service_images'")->fetchColumn() === SERVICE_IMAGES_VERSION) return;
    $pdo->exec('BEGIN IMMEDIATE');
    $set = $pdo->prepare("UPDATE services SET image = ? WHERE id = ? AND (image IS NULL OR image = '')");
    $rows = $pdo->query("SELECT c.id, c.name, p.slug FROM services c JOIN services p ON p.id = c.parent_id WHERE p.slug IS NOT NULL")->fetchAll();
    foreach ($rows as $r) {
        $image = SERVICE_IMAGES[$r['slug']][trim($r['name'])] ?? null;
        if ($image) $set->execute([$image, $r['id']]);
    }
    $pdo->prepare("INSERT INTO site_meta (key, value) VALUES ('service_images', ?)
                   ON CONFLICT (key) DO UPDATE SET value = excluded.value")->execute([SERVICE_IMAGES_VERSION]);
    $pdo->exec('COMMIT');
}
