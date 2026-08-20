<?php
/**
 * SOI (School Of Interns) CMS - Accounts Callback
 * Handles the return from accounts.soi.co.in after auto-registration.
 */
define('SOI_ROOT', dirname(__DIR__));
$configFile = SOI_ROOT . '/config/config.php';

if (!file_exists($configFile) || !is_readable($configFile)) {
    die('<strong>Critical Error:</strong> Configuration file (config.php) not found or not readable. Please ensure the installation process completed successfully before attempting to link accounts.');
}

require_once $configFile;
require_once SOI_ROOT . '/core/helpers.php';

spl_autoload_register(function ($class) {
    $file = SOI_ROOT . '/core/' . str_replace(['SOI\\Core\\', '\\'], ['', '/'], $class) . '.php';
    if (file_exists($file)) require_once $file;
});

use SOI\Core\{Database, Accounts, Auth};

Database::connect([
    'host'   => SOI_DB_HOST,
    'name'   => SOI_DB_NAME,
    'user'   => SOI_DB_USER,
    'pass'   => SOI_DB_PASS,
    'port'   => SOI_DB_PORT,
    'prefix' => SOI_DB_PREFIX,
]);
Auth::init();

try {
    $result = Accounts::handleCallback(array_merge($_GET, $_POST));
} catch (\Throwable $e) {
    error_log('[Install Callback] ' . $e->getMessage());
    $result = [
        'success' => false,
        'error'   => 'Could not store Accounts credentials. Ensure the OpenSSL extension is enabled and try again.',
    ];
}

$error = $result['success'] ? '' : ($result['error'] ?? 'Unknown error occurred.');

if ($result['success']) {
    if (!empty($result['warning'])) {
        soi_flash('error', $result['warning']);
    }
    soi_flash('success', 'Your CMS is now connected to SOI Accounts. Sign in to access the admin panel.');
    soi_redirect(SOI_ADMIN_URL . '/login.php?linked=1');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SOI (School Of Interns) CMS — Accounts Integration</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="installer.css">
</head>
<body>
<div class="installer-wrap">

  <div class="installer-brand">
    <div class="brand-logo">🎓</div>
    <h1 class="brand-name">SOI <span>CMS</span></h1>
    <p class="brand-tagline">Accounts Integration</p>
  </div>

  <div class="installer-card">

  <?php if ($error): ?>
    <?php $alreadyLinked = stripos($error, 'already linked') !== false; ?>
    <div class="alert alert-error">⚠️ <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <div class="btn-row">
      <?php if ($alreadyLinked): ?>
      <a href="<?= htmlspecialchars(SOI_ADMIN_URL . '/login.php', ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary">Go to Admin Login →</a>
      <?php else: ?>
      <a href="<?= htmlspecialchars(SOI_ADMIN_URL . '/connect.php?start=1', ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary">Connect SOI Accounts →</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="alert alert-error">⚠️ Unknown error occurred.</div>
  <?php endif; ?>

  </div><!-- .installer-card -->
</div><!-- .installer-wrap -->
</body>
</html>