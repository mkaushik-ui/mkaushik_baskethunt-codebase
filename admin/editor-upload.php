<?php
/**
 * Editor media upload — reuses private media storage.
 * Returns Editor.js-compatible JSON plus a CMS assetId.
 */
if (!defined('SOI_ROOT')) {
    define('SOI_ROOT', dirname(__DIR__));
}

require_once SOI_ROOT . '/config/config.php';
require_once SOI_ROOT . '/core/helpers.php';

spl_autoload_register(static function (string $class): void {
    $file = SOI_ROOT . '/core/' . str_replace(['SOI\\Core\\', '\\'], ['', '/'], $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use SOI\Core\Auth;
use SOI\Core\Content\AssetResolver;
use SOI\Core\Database;
use SOI\Core\MediaStorage;

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

Database::connect([
    'host' => SOI_DB_HOST,
    'name' => SOI_DB_NAME,
    'user' => SOI_DB_USER,
    'pass' => SOI_DB_PASS,
    'port' => SOI_DB_PORT,
    'prefix' => SOI_DB_PREFIX,
]);
Auth::init();
Auth::requireAuth('author');

$csrf = (string) ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!Auth::verifyCsrf($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => 0, 'ok' => false, 'error' => 'CSRF check failed.']);
    exit;
}

$allowedMimes = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'application/pdf', 'text/plain',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
$kind = (string) ($_POST['kind'] ?? 'image');

$file = $_FILES['file'] ?? $_FILES['image'] ?? null;
if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => 0, 'ok' => false, 'error' => 'Choose a file to upload.']);
    exit;
}

$mime = mime_content_type($file['tmp_name']);
if ($mime === false || !in_array($mime, $allowedMimes, true)) {
    http_response_code(400);
    echo json_encode(['success' => 0, 'ok' => false, 'error' => 'File type is not allowed.']);
    exit;
}
if ($kind === 'image' && !str_starts_with((string) $mime, 'image/')) {
    http_response_code(400);
    echo json_encode(['success' => 0, 'ok' => false, 'error' => 'Only image files can be inserted as images.']);
    exit;
}
if ((int) ($file['size'] ?? 0) > 15 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['success' => 0, 'ok' => false, 'error' => 'File is too large.']);
    exit;
}
if (!class_exists(MediaStorage::class) || !MediaStorage::ensurePrivateMediaRoot()) {
    http_response_code(500);
    echo json_encode(['success' => 0, 'ok' => false, 'error' => 'Media storage is not available.']);
    exit;
}

$hash = substr(hash('sha256', uniqid('', true) . random_bytes(16)), 0, 16);
try {
    $destPath = MediaStorage::buildPrivateMediaPath($hash, date('Y'), date('m'));
    if (!MediaStorage::moveUploadedFileToPrivateStorage($file['tmp_name'], $destPath)) {
        throw new RuntimeException('Could not store the uploaded file.');
    }
    $url = rtrim(SOI_HOME_URL, '/') . '/media/view/' . $hash;
    $mediaId = Database::insert('media', [
        'filename' => $hash,
        'original_name' => $file['name'],
        'mime_type' => $mime,
        'file_size' => $file['size'],
        'path' => $destPath,
        'url' => $url,
        'alt_text' => '',
        'uploaded_by' => Auth::id(),
    ]);
} catch (Throwable $e) {
    if (!empty($destPath) && is_file($destPath)) {
        @unlink($destPath);
    }
    http_response_code(500);
    echo json_encode(['success' => 0, 'ok' => false, 'error' => 'Upload failed.']);
    exit;
}

$safeUrl = AssetResolver::publicUrl($url);
echo json_encode([
    'success' => 1,
    'ok' => true,
    'file' => [
        'url' => $safeUrl,
        'assetId' => $mediaId,
        'name' => $file['name'],
        'mime' => $mime,
    ],
], JSON_UNESCAPED_SLASHES);
exit;
