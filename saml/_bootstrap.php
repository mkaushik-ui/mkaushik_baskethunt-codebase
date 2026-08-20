<?php
/**
 * Shared bootstrap for physical /saml/* routes.
 * Works without root rewrite (LiteSpeed/Apache/Nginx).
 */
declare(strict_types=1);

if (!defined('SOI_ROOT')) {
    define('SOI_ROOT', dirname(__DIR__));
}

if (!file_exists(SOI_ROOT . '/config/config.php')) {
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>CMS not installed</title></head><body>';
    echo '<h1>CMS not installed</h1><p>Complete the web installer first: <a href="/install/">/install/</a></p>';
    echo '</body></html>';
    exit;
}

require_once SOI_ROOT . '/config/config.php';
require_once SOI_ROOT . '/core/helpers.php';

spl_autoload_register(static function (string $class): void {
    $file = SOI_ROOT . '/core/' . str_replace(['SOI\\Core\\', '\\'], ['', '/'], $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use SOI\Core\{Database, Auth, SoiCentralAuth};

Database::connect([
    'host'   => SOI_DB_HOST,
    'name'   => SOI_DB_NAME,
    'user'   => SOI_DB_USER,
    'pass'   => SOI_DB_PASS,
    'port'   => SOI_DB_PORT,
    'prefix' => SOI_DB_PREFIX,
]);
Auth::init();
SoiCentralAuth::bootstrap();
