<?php
/**
 * SOI (School Of Interns) CMS - Entry Point
 * Version: 1.0.6 (Enterprise authoring workspace)
 */

define('SOI_ROOT', __DIR__);
define('SOI_VERSION', '1.0.6');
define('SOI_START', microtime(true));

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
