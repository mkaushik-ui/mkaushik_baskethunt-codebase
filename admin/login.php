<?php
/**
 * Admin — Login (SAML-primary via SOI Central; OAuth fallback during setup)
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

Database::connect(['host'=>SOI_DB_HOST,'name'=>SOI_DB_NAME,'user'=>SOI_DB_USER,'pass'=>SOI_DB_PASS,'port'=>SOI_DB_PORT,'prefix'=>SOI_DB_PREFIX]);
Auth::init();
SoiCentralAuth::install();

if (!Accounts::isLinked() && (!defined('SOI_ALLOW_LOCAL_LOGIN') || !SOI_ALLOW_LOCAL_LOGIN)) {
    soi_redirect(SOI_ADMIN_URL . '/connect.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if ($username !== '' && Auth::loginLocal($username, $password)) {
        soi_redirect(SOI_ADMIN_URL . '/spaces.php');
    } else {
        $error = 'Invalid credentials.';
    }
}

if (Auth::check()) {
    soi_redirect(SOI_ADMIN_URL . '/spaces.php');
}

$error = $error ?? trim((string) ($_GET['error'] ?? ''));
$flashes = soi_get_flash();
$justLinked = isset($_GET['linked']);
$samlUnavailable = isset($_GET['saml']) && $_GET['saml'] === 'unavailable';
$siteName = Database::getOption('site_name', 'SOI (School Of Interns) CMS');
$returnTo = SOI_ADMIN_URL . '/';
$samlReady = SoiCentralAuth::isSamlConfiguredForLogin();
$samlLoginUrl = SoiCentralAuth::samlLoginUrl($returnTo);
$authorizeUrl = SOI_ADMIN_URL . '/connect.php';
try {
    $authorizeUrl = Accounts::buildAuthorizeUrl();
} catch (\Throwable $e) {
    if ($error === '') {
        $error = 'SAML is not configured yet. Configure SOI Central or use OAuth fallback.';
    }
}

if ($samlUnavailable) {
    $error = $error ?: 'SAML SSO is not ready yet. Use OAuth sign-in below or open Admin → SOI Central to fetch SAML metadata.';
    error_log('[Login] SAML unavailable fallback shown (linked=' . ($justLinked ? 'yes' : 'no') . ')');
}

// Skip auto-redirect after live session sync logout to avoid login loops.
$ssoSyncReturn = isset($_GET['sso_sync']);

// Auto-redirect logic
if (!$samlUnavailable && !$ssoSyncReturn) {
    if ($samlReady) {
        if ($flashes) {
            if (session_status() === PHP_SESSION_NONE) session_start();
            $_SESSION['_flash'] = array_merge($_SESSION['_flash'] ?? [], $flashes);
        }
        error_log('[Login] Auto-redirecting to SAML login: ' . $samlLoginUrl);
        SoiCentralAuth::redirectToLogin($returnTo);
    } else {
        $creds = Accounts::getStoredCredentials();
        if ($creds) {
            try {
                Accounts::syncSamlFromStoredCredentials($justLinked, $justLinked ? 'post_link' : 'accounts_sync');
                if ($justLinked) {
                    SoiCentralAuth::ensureIdpMetadata(true, 'post_link');
                }
                if (SoiCentralAuth::isSamlConfiguredForLogin()) {
                    if ($flashes) {
                        if (session_status() === PHP_SESSION_NONE) session_start();
                        $_SESSION['_flash'] = array_merge($_SESSION['_flash'] ?? [], $flashes);
                    }
                    error_log('[Login] Auto-redirecting to SAML login after bridge: ' . $samlLoginUrl);
                    SoiCentralAuth::redirectToLogin($returnTo);
                } else {
                    error_log('[Login] SAML bridge succeeded but SAML not configured. Auto-redirecting to OAuth.');
                    soi_redirect($authorizeUrl);
                }
            } catch (\Throwable $e) {
                error_log('[Login] SAML auto-config failed: ' . $e->getMessage() . '. Falling back to OAuth.');
                soi_redirect($authorizeUrl);
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign in — <?= esc($siteName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--brand:#2563eb;--bg:#0f172a;--surface:#111827;--border:#263244;--text:#e5e7eb;--text-muted:#9ca3af}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.5rem;background-image:radial-gradient(ellipse at 50% 0%,rgba(37,99,235,.15) 0%,transparent 60%)}
.login-card{width:100%;max-width:440px;background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:2.5rem;box-shadow:0 24px 80px rgba(0,0,0,.35)}
.mark{width:52px;height:52px;border-radius:14px;background:var(--brand);color:#fff;display:grid;place-items:center;font-weight:800;font-size:18px;margin:0 auto 1.25rem}
h1{font-size:1.45rem;font-weight:800;text-align:center;margin-bottom:.35rem}
.subtitle{text-align:center;color:var(--text-muted);font-size:.85rem;margin-bottom:1.5rem;line-height:1.5}
.alert-error{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.35);color:#fecaca;padding:.85rem 1rem;border-radius:10px;font-size:.84rem;margin-bottom:1.25rem}
.alert-success{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.35);color:#bbf7d0;padding:.85rem 1rem;border-radius:10px;font-size:.84rem;margin-bottom:1.25rem}
.meta{background:#0b1220;border:1px solid #233047;border-radius:10px;padding:1rem;font-size:.8rem;color:#cbd5e1;line-height:1.6;margin-bottom:1.25rem}
.meta code{color:#93c5fd;word-break:break-all;font-size:.78rem}
.btn{display:flex;align-items:center;justify-content:center;width:100%;min-height:46px;padding:.85rem 1rem;background:var(--brand);color:#fff;border:none;border-radius:10px;font-size:.92rem;font-weight:700;text-decoration:none;transition:transform .15s,box-shadow .15s}
.btn:hover{transform:translateY(-1px);box-shadow:0 8px 24px rgba(37,99,235,.35)}
.btn-secondary{background:transparent;border:1px solid var(--border);color:var(--text-muted);margin-top:.65rem;font-weight:600}
.footer{text-align:center;margin-top:1.25rem;font-size:.78rem;color:var(--text-muted)}
.footer a{color:#93c5fd;text-decoration:none}
</style>
</head>
<body>
<div class="login-card">
  <div class="mark">SOI</div>
  <h1>Enterprise Sign-In</h1>
  <p class="subtitle"><?= esc($siteName) ?> · Secured by SOI Accounts</p>

  <?php foreach ($flashes as $flash): ?>
  <div class="alert-<?= esc($flash['type'] === 'success' ? 'success' : 'error') ?>"><?= esc($flash['message']) ?></div>
  <?php endforeach; ?>

  <?php if ($error): ?>
  <div class="alert-error"><?= esc($error) ?></div>
  <?php endif; ?>

  <?php if ($justLinked || !empty($flashes)): ?>
  <div class="meta">
    <strong>Connection complete</strong><br>
    Your CMS is linked with SOI Accounts. Sign in below to open the admin panel.
  </div>
  <?php elseif (!$samlReady || $samlUnavailable): ?>
  <div class="meta">
    <strong>SAML setup pending</strong><br>
    SAML SSO will activate once metadata is fetched. You can sign in with OAuth now, or register these endpoints in SOI Admin Center:<br>
    SP metadata: <code><?= esc(SoiCentralAuth::spMetadataUrl()) ?></code><br>
    ACS URL: <code><?= esc(SoiCentralAuth::acsUrl()) ?></code><br>
    Login URL: <code><?= esc($samlLoginUrl) ?></code>
  </div>
  <?php endif; ?>

  <?php if (defined('SOI_ALLOW_LOCAL_LOGIN') && SOI_ALLOW_LOCAL_LOGIN): ?>
  <form method="POST" action="login.php" style="margin-bottom:1.25rem;">
    <div style="margin-bottom:1rem;text-align:left;">
      <label style="display:block;font-size:0.82rem;font-weight:600;margin-bottom:0.35rem;color:#cbd5e1;">Username or Email</label>
      <input type="text" name="username" value="admin" required style="width:100%;padding:0.75rem 1rem;background:#0b1220;border:1px solid var(--border);border-radius:8px;color:#fff;font-size:0.9rem;">
    </div>
    <div style="margin-bottom:1.25rem;text-align:left;">
      <label style="display:block;font-size:0.82rem;font-weight:600;margin-bottom:0.35rem;color:#cbd5e1;">Password</label>
      <input type="password" name="password" value="admin123" required style="width:100%;padding:0.75rem 1rem;background:#0b1220;border:1px solid var(--border);border-radius:8px;color:#fff;font-size:0.9rem;">
    </div>
    <button type="submit" class="btn">Sign In to Dashboard</button>
  </form>
  <?php endif; ?>

  <?php if ($samlReady): ?>
  <a href="<?= esc($samlLoginUrl) ?>" class="btn btn-secondary">Continue with SOI Accounts (SAML)</a>
  <?php elseif (Accounts::isLinked()): ?>
  <a href="<?= esc($authorizeUrl) ?>" class="btn btn-secondary">Sign in with SOI Accounts (OAuth)</a>
  <?php endif; ?>

  <div class="footer">
    <a href="<?= esc(SOI_HOME_URL) ?>">← Back to website</a>
  </div>
</div>
</body>
</html>