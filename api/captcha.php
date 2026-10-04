<?php
require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/antispam.php';

startSession();
$action = $_GET['action'] ?? '';

if ($action === 'need') {
    respond(['ok' => true, 'need' => antispamOrderNeedsCaptcha(db()), 'banned' => antispamBanned(db())]);
}

if ($action === 'image') {
    $code = '';
    for ($i = 0; $i < 5; $i++) $code .= random_int(0, 9);
    $_SESSION['captcha'] = ['hash' => hash('sha256', $code), 'expires' => time() + 600];
    header('Cache-Control: no-store');

    if (!function_exists('imagecreatetruecolor')) {

        header('Content-Type: image/svg+xml');
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="160" height="56"><rect width="160" height="56" fill="#eef2f7"/>'
            . '<text x="80" y="38" font-size="28" font-family="monospace" text-anchor="middle" fill="#1e293b">' . $code . '</text></svg>';
        exit;
    }

    $small = imagecreatetruecolor(80, 28);
    imagefill($small, 0, 0, imagecolorallocate($small, 238, 242, 247));
    for ($i = 0; $i < 5; $i++) {
        $color = imagecolorallocate($small, random_int(10, 70), random_int(30, 90), random_int(90, 160));
        imagestring($small, 5, 6 + $i * 14 + random_int(-2, 2), random_int(2, 11), $code[$i], $color);
    }
    $img = imagecreatetruecolor(160, 56);
    imagecopyresampled($img, $small, 0, 0, 0, 0, 160, 56, 80, 28);
    for ($i = 0; $i < 6; $i++) {
        $c = imagecolorallocatealpha($img, random_int(60, 160), random_int(60, 160), random_int(60, 160), 60);
        imageline($img, random_int(0, 40), random_int(0, 56), random_int(120, 160), random_int(0, 56), $c);
    }
    for ($i = 0; $i < 250; $i++) {
        imagesetpixel($img, random_int(0, 159), random_int(0, 55), imagecolorallocate($img, random_int(100, 220), random_int(100, 220), random_int(100, 220)));
    }
    header('Content-Type: image/png');
    imagepng($img);
    exit;
}

respond(['ok' => false, 'message' => 'Невідома дія.'], 400);
