<?php
/**
 * Admin — Accounts Callback
 * Handles the return from accounts.soi.co.in after linking.
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

use SOI\Core\{Database, Auth, Accounts, SoiCentralAuth};

Database::connect([
    'host'   => SOI_DB_HOST,
    'name'   => SOI_DB_NAME,
    'user'   => SOI_DB_USER,
    'pass'   => SOI_DB_PASS,
    'port'   => SOI_DB_PORT,
    'prefix' => SOI_DB_PREFIX,
]);
Auth::init();
SoiCentralAuth::install();

$siteName = Database::getOption('site_name', 'SOI (School Of Interns) CMS');
$callbackData = array_merge($_GET, $_POST);

try {
    $result = Accounts::handleCallback($callbackData);
} catch (\Throwable $e) {
    error_log('[Accounts Callback] ' . $e->getMessage());
    $result = [
        'success' => false,
        'error'   => 'Could not store Accounts credentials. Ensure the OpenSSL extension is enabled and try again.',
    ];
}

$error = $result['success'] ? '' : ($result['error'] ?? 'Unknown error occurred.');

if ($result['success']) {
    $certCount = 0;
    try {
        $metadataStatus = SoiCentralAuth::getMetadataFetchStatus();
        $certCount = (int) ($metadataStatus['cert_count'] ?? 0);
    } catch (\Throwable $e) {
        error_log('[Accounts Callback] Post-link metadata validation failed: ' . $e->getMessage());
    }

    if (!empty($result['warning'])) {
        soi_flash('error', $result['warning']);
    }

    if (!empty($result['relinked'])) {
        if ($certCount === 0) {
            soi_flash(
                'error',
                'Accounts re-linked but no IdP signing certificates were stored. Open Admin → SOI Central → Fetch Metadata, then sign in.'
            );
        } else {
            soi_flash(
                'success',
                'Accounts credentials and SAML settings were updated (' . $certCount . ' signing certificate(s) stored). Sign in to access the admin panel.'
            );
        }
    } else {
        $message = $certCount === 0
            ? 'Your CMS is connected to SOI Accounts, but no IdP signing certificates were stored yet. Open Admin → SOI Central → Fetch Metadata, then sign in.'
            : 'Your CMS is now connected to SOI Accounts (' . $certCount . ' signing certificate(s) stored). Sign in to access the admin panel.';
        soi_flash($certCount === 0 ? 'error' : 'success', $message);
    }

    soi_redirect(SOI_ADMIN_URL . '/login.php?linked=1');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Accounts Integration — <?= esc($siteName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--brand:#f5941f;--bg:#0d0f18;--surface:#161929;--border:#272b42;--text:#e2e8f0;--text-muted:#6b7280}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1rem;background-image:radial-gradient(ellipse at 50% 0%,rgba(245,148,31,0.1) 0%,transparent 60%)}
.callback-card{width:100%;max-width:440px;background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:2.5rem;box-shadow:0 24px 60px rgba(0,0,0,0.4);text-align:center}
.callback-icon{font-size:2.5rem;margin-bottom:0.75rem}
.callback-card h2{font-size:1.35rem;font-weight:800;margin-bottom:0.5rem}
.callback-card p{color:var(--text-muted);font-size:0.88rem;line-height:1.55;margin-bottom:1.5rem}
.alert-error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:#fca5a5;padding:0.8rem 1rem;border-radius:8px;font-size:0.83rem;margin-bottom:1.25rem;text-align:left}
.btn{display:inline-block;padding:0.8rem 1.2rem;border-radius:9px;font-size:0.9rem;font-weight:700;text-decoration:none;margin:0.25rem}
.btn-primary{background:var(--brand);color:#fff}
.btn-primary:hover{background:#d47a10}
.btn-ghost{background:transparent;color:var(--text-muted);border:1px solid var(--border)}
</style>
</head>
<body>
<div class="callback-card">
  <div class="callback-icon">⚠️</div>
  <h2>Connection Failed</h2>
  <?php $alreadyLinked = stripos($error, 'already linked') !== false; ?>
  <div class="alert-error"><?= esc($error ?: 'Unknown error occurred.') ?></div>
  <?php if ($alreadyLinked): ?>
  <a href="<?= esc(SOI_ADMIN_URL . '/login.php') ?>" class="btn btn-primary">Go to Admin Login →</a>
  <?php else: ?>
  <a href="<?= esc(SOI_ADMIN_URL . '/connect.php?start=1') ?>" class="btn btn-primary">Try Again →</a>
  <?php endif; ?>
</div>
</body>
</html>