<?php
/**
 * SOI Source CMS — sample configuration (clean installable distribution v1.0.3)
 *
 * The web installer generates config/config.php automatically.
 * Do not commit real credentials. This sample is documentation only.
 */

// Database
define('SOI_DB_HOST',   'localhost');
define('SOI_DB_PORT',   3306);
define('SOI_DB_NAME',   'your_database');
define('SOI_DB_USER',   'your_user');
define('SOI_DB_PASS',   'your_password');
define('SOI_DB_PREFIX', 'soi_');

// App version
if (!defined('SOI_VERSION')) {
    define('SOI_VERSION', '1.0.3');
}

// URLs (no trailing slash)
define('SOI_HOME_URL',  'https://example.com');
define('SOI_ADMIN_URL', 'https://example.com/admin');

// Timezone
date_default_timezone_set('UTC');

// Security key — generated uniquely per install by the web installer
define('SOI_SECRET_KEY', 'replace-with-installer-generated-secret');

// Debug mode (false in production)
define('SOI_DEBUG', false);

// Paths (SOI_ROOT is defined by index.php before this file is loaded)
define('SOI_PRIVATE_STORAGE_PATH', dirname(SOI_ROOT) . '/soi-private-storage/example');
define('SOI_UPLOADS_DIR', SOI_ROOT . '/uploads');
define('SOI_PLUGINS_DIR', SOI_ROOT . '/plugins');
define('SOI_THEMES_DIR',  SOI_ROOT . '/themes');
define('SOI_UPDATES_DIR', SOI_ROOT . '/updates');
