<?php
/**
 * Admin — OAuth Callback
 * Handles the return from accounts.soi.co.in after SSO authorization.
 */
if (!defined('SOI_ROOT')) define('SOI_ROOT', dirname(__DIR__));
if (!file_exists(SOI_ROOT . '/config/config.php')) {
    header('Location: ../install/index.php');
    exit;
}
require_once SOI_ROOT . '/config/config.php';
require_once SOI_ROOT . '/core/helpers.php';

spl_autoload_register(function ($class) {
    $file = SOI_ROOT . '/core/' . str_replace(['SOI\\Core\\', '\\'], ['', '/'], $class) . '.php';
    if (file_exists($file)) require_once $file;
});

use SOI\Core\{Database, Auth, Accounts};

Database::connect([
    'host'   => SOI_DB_HOST,
    'name'   => SOI_DB_NAME,
    'user'   => SOI_DB_USER,
    'pass'   => SOI_DB_PASS,
    'port'   => SOI_DB_PORT,
    'prefix' => SOI_DB_PREFIX,
]);
Auth::init();

if (!Accounts::isLinked()) {
    soi_redirect(SOI_ADMIN_URL . '/connect.php');
}

$result = Accounts::handleAuthCallback($_GET);

if ($result['success']) {
    soi_redirect(SOI_ADMIN_URL . '/index.php');
}

$error = $result['error'] ?? 'Login failed. Please try again.';
soi_redirect(SOI_ADMIN_URL . '/login.php?error=' . urlencode($error));