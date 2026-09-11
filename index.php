<?php
/**
 * SOI (School Of Interns) CMS - Entry Point
 * Version: 1.0.7 (Enterprise authoring workspace)
 */

if (!defined('SOI_ROOT')) {
    define('SOI_ROOT', __DIR__);
}
if (!defined('SOI_VERSION')) {
    define('SOI_VERSION', '1.0.7');
}
if (!defined('SOI_START')) {
    define('SOI_START', microtime(true));
}

// Check if installed
if (!file_exists(SOI_ROOT . '/config/config.php')) {
    // Redirect to web installer
    header('Location: install/index.php');
    exit;
}

// Load configuration
require_once SOI_ROOT . '/config/config.php';

// Load core helpers
require_once SOI_ROOT . '/core/helpers.php';

// Bootstrap and run the application
require_once SOI_ROOT . '/core/App.php';

$app = new \SOI\Core\App();
$app->run();
