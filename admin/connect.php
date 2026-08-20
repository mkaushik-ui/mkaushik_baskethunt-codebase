<?php
/**
 * Admin — Accounts Connect
 * Shown when the CMS is not yet linked to accounts.soi.co.in.
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

if (Accounts::isLinked()) {
    soi_redirect(SOI_ADMIN_URL . '/login.php');
}

// Generate state and redirect only when the user starts linking (not on page view).
if (isset($_GET['start'])) {
    header('Location: ' . Accounts::buildConnectUrl());
    exit;
}

$siteName = Database::getOption('site_name', 'SOI (School Of Interns) CMS');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Connect Accounts — <?= esc($siteName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--brand:#f5941f;--bg:#0d0f18;--surface:#161929;--border:#272b42;--text:#e2e8f0;--text-muted:#6b7280}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1rem;background-image:radial-gradient(ellipse at 50% 0%,rgba(245,148,31,0.1) 0%,transparent 60%)}
.connect-card{width:100%;max-width:440px;background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:2.5rem;box-shadow:0 24px 60px rgba(0,0,0,0.4);text-align:center}
.connect-brand-icon{font-size:2.5rem}
.connect-brand h1{font-size:1.5rem;font-weight:800;margin-top:0.5rem}
.connect-brand h1 span{color:var(--brand)}
.connect-brand p{color:var(--text-muted);font-size:0.85rem;margin-top:0.5rem;line-height:1.5}
.connect-message{margin:1.75rem 0 1.5rem;padding:1rem 1.1rem;background:rgba(245,148,31,0.08);border:1px solid rgba(245,148,31,0.2);border-radius:10px;font-size:0.88rem;line-height:1.55;color:var(--text)}
.connect-btn{display:inline-block;width:100%;padding:0.85rem 1rem;background:var(--brand);color:#fff;border:none;border-radius:9px;font-size:0.9rem;font-weight:700;text-decoration:none;transition:all .2s}
.connect-btn:hover{background:#d47a10;transform:translateY(-1px);box-shadow:0 6px 20px rgba(245,148,31,0.3)}
.connect-footer{margin-top:1.5rem;font-size:0.78rem;color:var(--text-muted)}
.connect-footer a{color:var(--brand);text-decoration:none}
</style>
</head>
<body>
<div class="connect-card">
  <div class="connect-brand">
    <div class="connect-brand-icon">🔗</div>
    <h1>SOI <span>Accounts</span></h1>
    <p><?= esc($siteName) ?> · Admin Setup</p>
  </div>

  <div class="connect-message">
    This CMS is not yet linked with <strong>SOI Accounts</strong> (accounts.soi.co.in).
    You must complete the connection before accessing the admin panel.
  </div>

  <a href="<?= esc(SOI_ADMIN_URL . '/connect.php?start=1') ?>" class="connect-btn">Connect with SOI Accounts →</a>

  <div class="connect-footer">
    <a href="<?= esc(SOI_HOME_URL) ?>">← Back to website</a>
  </div>
</div>
</body>
</html>