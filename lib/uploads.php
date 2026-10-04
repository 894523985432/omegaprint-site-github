<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

const UPLOAD_MAX_BYTES = 5 * 1024 * 1024;
const UPLOAD_IMAGE_TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];

function saveImageUpload(array $file, string $folder): string
{
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > UPLOAD_MAX_BYTES) {
        respond(['ok' => false, 'errors' => ['image' => 'Фото завелике (максимум 5 МБ).']], 422);
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        respond(['ok' => false, 'errors' => ['image' => 'Не вдалося завантажити фото.']], 422);
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !isset(UPLOAD_IMAGE_TYPES[$info[2]])) {
        respond(['ok' => false, 'errors' => ['image' => 'Підтримуються лише JPG, PNG, WEBP і GIF.']], 422);
    }
    $dir = dirname(__DIR__) . '/uploads/' . $folder;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        respond(['ok' => false, 'message' => 'Не вдалося створити папку для фото.'], 500);
    }
    $name = bin2hex(random_bytes(12)) . '.' . UPLOAD_IMAGE_TYPES[$info[2]];
    if (!move_uploaded_file($file['tmp_name'], "$dir/$name")) {
        respond(['ok' => false, 'message' => 'Не вдалося зберегти фото.'], 500);
    }
    return "uploads/$folder/$name";
}

function deleteImageUpload(?string $path): void
{
    if ($path && preg_match('#^uploads/[a-z]+/[a-f0-9]{24}\.(jpg|png|webp|gif)$#', $path)) {
        @unlink(dirname(__DIR__) . '/' . $path);
    }
}

function hasUpload(string $field): bool
{
    return !empty($_FILES[$field]) && $_FILES[$field]['error'] !== UPLOAD_ERR_NO_FILE;
}
