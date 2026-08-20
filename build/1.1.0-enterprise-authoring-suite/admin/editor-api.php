<?php
/**
 * Structured editor JSON API (save, preview, media list).
 */
if (!defined('SOI_ROOT')) {
    define('SOI_ROOT', dirname(__DIR__));
}
if (!defined('SOI_JSON_REQUEST')) {
    define('SOI_JSON_REQUEST', true);
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
use SOI\Core\Content\EditorApi;
use SOI\Core\Database;

Database::connect([
    'host' => SOI_DB_HOST,
    'name' => SOI_DB_NAME,
    'user' => SOI_DB_USER,
    'pass' => SOI_DB_PASS,
    'port' => SOI_DB_PORT,
    'prefix' => SOI_DB_PREFIX,
]);
Auth::init();
EditorApi::handle();
