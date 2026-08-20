<?php
/**
 * SOI Source CMS — Web Installer (installable distribution v1.0.0)
 */
$installerHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0])) === 'https'
    || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
$installerScript = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/install/index.php'));
$installerBasePath = str_replace('\\', '/', dirname(dirname($installerScript)));
$installerBasePath = ($installerBasePath === '/' || $installerBasePath === '.' || $installerBasePath === '\\') ? '/' : '/' . trim($installerBasePath, '/') . '/';
session_name('SOI_INSTALL_' . strtoupper(substr(hash('sha256', (string)($_SERVER['HTTP_HOST'] ?? 'localhost') . $installerBasePath), 0, 10)));
session_set_cookie_params([
    'lifetime' => 0,
    'path' => $installerBasePath,
    'secure' => $installerHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-LiteSpeed-Cache-Control: no-cache');

define('SOI_ROOT', dirname(__DIR__));
define('SOI_VERSION', '1.0.3');
define('SOI_INSTALLER_BOOTSTRAP', true);

require_once SOI_ROOT . '/install/install.php';

installer_ensure_lock_if_configured();

if (installer_is_locked()) {
    installer_deny_access();
}

$step = (int) ($_GET['step'] ?? 1);
$step = in_array($step, [1, 2, 3, 4, 5, 6, 7], true) ? $step : 1;
$error = '';
$requirements = installer_check_requirements();
$requiredFail = array_filter($requirements, fn($requirement) => !$requirement['ok'] && $requirement['required']);

// Step guards
if ($step >= 3 && empty($_SESSION['install']['db_verified'])) {
    header('Location: ?step=2');
    exit;
}
if ($step >= 4 && empty($_SESSION['install']['site_identity'])) {
    header('Location: ?step=3');
    exit;
}
if ($step >= 5 && empty($_SESSION['install']['auth'])) {
    header('Location: ?step=4');
    exit;
}
if ($step >= 6 && empty($_SESSION['install']['files_service'])) {
    header('Location: ?step=5');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $step = (int) ($_POST['step'] ?? 1);

    if (!installer_verify_csrf($_POST['_csrf'] ?? null)) {
        $error = 'Your installer session expired. Refresh the page and try again.';
    } elseif ($requiredFail) {
        $step = 1;
        $error = 'Server requirements changed or are incomplete. Fix them before continuing.';
    } else {
        switch ($step) {
            case 2:
                $existingPassword = (string)($_SESSION['install']['db']['pass'] ?? '');
                $submittedPassword = (string)($_POST['db_pass'] ?? '');
                $_SESSION['install']['db'] = [
                    'host'   => trim($_POST['db_host']   ?? 'localhost'),
                    'port'   => (int) ($_POST['db_port'] ?? 3306),
                    'name'   => trim($_POST['db_name']   ?? ''),
                    'user'   => trim($_POST['db_user']   ?? ''),
                    'pass'   => $submittedPassword !== '' ? $submittedPassword : $existingPassword,
                    'prefix' => trim($_POST['db_prefix'] ?? 'soi_'),
                ];
                $test = installer_test_db($_SESSION['install']['db']);
                if ($test['success']) {
                    $_SESSION['install']['db_verified'] = true;
                    header('Location: ?step=3');
                    exit;
                }
                unset($_SESSION['install']['db_verified']);
                $error = $test['error'];
                break;

            case 3:
                $_SESSION['install']['site_identity'] = [
                    'name' => trim((string)($_POST['site_name'] ?? '')),
                    'slug' => trim((string)($_POST['site_slug'] ?? '')),
                    'domain' => trim((string)($_POST['site_domain'] ?? '')),
                    'url' => trim((string)($_POST['site_url'] ?? '')),
                    'canonical_base_url' => trim((string)($_POST['canonical_base_url'] ?? '')),
                    'admin_base_url' => trim((string)($_POST['admin_base_url'] ?? '')),
                    'frontend_base_url' => trim((string)($_POST['frontend_base_url'] ?? '')),
                    'environment' => trim((string)($_POST['environment_label'] ?? 'production')),
                    'email' => trim((string)($_POST['contact_email'] ?? '')),
                    'timezone' => (string)($_POST['timezone'] ?? 'UTC'),
                ];
                // Partial validate URL/name early
                $probe = installer_validate_site_config(array_merge($_SESSION['install']['site_identity'], [
                    'files_service_url' => 'https://files.soi.co.in',
                    'auth_mode' => 'accounts_saml',
                    'connect_accounts_now' => false,
                ]));
                if (!$probe['success']) {
                    $error = $probe['error'];
                    break;
                }
                $_SESSION['install']['site_identity'] = array_merge($_SESSION['install']['site_identity'], [
                    'name' => $probe['site']['name'],
                    'slug' => $probe['site']['slug'],
                    'domain' => $probe['site']['domain'],
                    'url' => $probe['site']['url'],
                    'canonical_base_url' => $probe['site']['canonical_base_url'],
                    'admin_base_url' => $probe['site']['admin_base_url'],
                    'frontend_base_url' => $probe['site']['frontend_base_url'],
                    'environment' => $probe['site']['environment'],
                    'email' => $probe['site']['email'],
                    'timezone' => $probe['site']['timezone'],
                ]);
                header('Location: ?step=4');
                exit;

            case 4:
                $authMode = (string)($_POST['auth_mode'] ?? 'accounts_saml');
                $_SESSION['install']['auth'] = [
                    'auth_mode' => in_array($authMode, ['accounts_saml', 'accounts_pending'], true) ? $authMode : 'accounts_saml',
                    'connect_accounts_now' => !empty($_POST['connect_accounts_now']),
                ];
                header('Location: ?step=5');
                exit;

            case 5:
                $_SESSION['install']['files_service'] = [
                    'files_service_url' => trim((string)($_POST['files_service_url'] ?? 'https://files.soi.co.in')),
                ];
                header('Location: ?step=6');
                exit;

            case 6:
                if (empty($_SESSION['install']['db_verified']) || empty($_SESSION['install']['site_identity'])) {
                    $error = 'Missing installation data. Start over from requirements.';
                    $step = 1;
                    break;
                }
                $identity = $_SESSION['install']['site_identity'];
                $auth = $_SESSION['install']['auth'] ?? ['auth_mode' => 'accounts_saml', 'connect_accounts_now' => true];
                $fs = $_SESSION['install']['files_service'] ?? ['files_service_url' => 'https://files.soi.co.in'];
                $siteResult = installer_validate_site_config(array_merge($identity, $auth, $fs));
                if (!$siteResult['success']) {
                    $error = $siteResult['error'];
                    break;
                }
                $site = $siteResult['site'];
                $db = $_SESSION['install']['db'] ?? null;
                if (!$db) {
                    $error = 'Database config missing. Go back to step 2.';
                    break;
                }

                $result = installer_run($db, $site);
                if ($result['success']) {
                    $adminUrl = rtrim($site['admin_base_url'] ?? ($site['url'] . '/admin'), '/');
                    if (!empty($site['connect_accounts_now']) && ($site['auth_mode'] ?? '') === 'accounts_saml') {
                        $state = installer_generate_state();
                        $installationData = installer_build_installation_data($site);
                        $redirectUri = rtrim($site['url'], '/') . '/install/callback.php';
                        $stateResult = installer_save_accounts_connect_state($db, $state, $redirectUri);
                        unset($_SESSION['install']);
                        if ($stateResult['success']) {
                            header('Location: ' . installer_build_accounts_connect_url($site, $state, $installationData));
                            exit;
                        }
                    }
                    unset($_SESSION['install']);
                    $_SESSION['install_complete'] = [
                        'admin_url' => $adminUrl,
                        'site_url' => $site['url'],
                        'site_name' => $site['name'],
                        'site_domain' => $site['domain'],
                        'site_slug' => $site['slug'],
                        'files_service_url' => $site['files_service_url'],
                    ];
                    header('Location: ?step=7');
                    exit;
                }
                $error = $result['error'];
                break;
        }
    }
}

$stepTitles = [
    1 => 'Requirements',
    2 => 'Database',
    3 => 'Site identity',
    4 => 'Auth',
    5 => 'Files Service',
    6 => 'Install',
    7 => 'Done',
];
$navMax = $step === 7 ? 7 : 6;
$identity = $_SESSION['install']['site_identity'] ?? [];
$auth = $_SESSION['install']['auth'] ?? [];
$fs = $_SESSION['install']['files_service'] ?? [];
$complete = $_SESSION['install_complete'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SOI Source CMS — Installer</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="installer.css">
</head>
<body>
<div class="installer-wrap">

  <div class="installer-brand">
    <div class="brand-logo">◆</div>
    <h1 class="brand-name">SOI <span>Source CMS</span></h1>
    <p class="brand-tagline">Installable distribution v<?= SOI_VERSION ?></p>
  </div>

  <div class="steps-nav" style="flex-wrap:wrap;gap:0.35rem;">
    <?php for ($i = 1; $i <= $navMax; $i++): ?>
    <div class="step-dot <?= $i < $step ? 'done' : ($i === $step ? 'active' : '') ?>">
      <div class="dot-circle"><?= $i < $step ? '✓' : $i ?></div>
      <span><?= esc($stepTitles[$i] ?? '') ?></span>
    </div>
    <?php if ($i < $navMax): ?><div class="step-line <?= $i < $step ? 'done' : '' ?>"></div><?php endif; ?>
    <?php endfor; ?>
  </div>

  <div class="installer-card">

  <?php if ($error): ?>
  <div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($step === 1): ?>
  <h2 class="card-title">System Requirements</h2>
  <p class="card-sub">Confirm this server can run SOI Source CMS before configuring the database.</p>
  <div class="req-list">
    <?php foreach ($requirements as $req): ?>
    <div class="req-item <?= $req['ok'] ? 'req-ok' : 'req-fail' ?>">
      <span class="req-icon"><?= $req['ok'] ? '✅' : '❌' ?></span>
      <div class="req-info">
        <strong><?= esc($req['name']) ?></strong>
        <span><?= esc($req['note']) ?></span>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php if ($requiredFail): ?>
  <div class="alert alert-error mt-2">Please fix required issues before continuing.</div>
  <?php else: ?>
  <div class="btn-row"><a href="?step=2" class="btn btn-primary">Continue to Database →</a></div>
  <?php endif; ?>

  <?php elseif ($step === 2): ?>
  <h2 class="card-title">Database Configuration</h2>
  <p class="card-sub">The MySQL database must already exist. Credentials are tested before continuing.</p>
  <form method="POST">
    <input type="hidden" name="step" value="2">
    <?= installer_csrf_field() ?>
    <div class="form-grid">
      <div class="form-group"><label>Database Host</label><input type="text" name="db_host" value="<?= esc($_SESSION['install']['db']['host'] ?? 'localhost') ?>" required></div>
      <div class="form-group"><label>Port</label><input type="number" name="db_port" value="<?= esc((string)($_SESSION['install']['db']['port'] ?? 3306)) ?>"></div>
      <div class="form-group col-span-2"><label>Database Name</label><input type="text" name="db_name" value="<?= esc($_SESSION['install']['db']['name'] ?? '') ?>" required></div>
      <div class="form-group"><label>Username</label><input type="text" name="db_user" value="<?= esc($_SESSION['install']['db']['user'] ?? '') ?>" required></div>
      <div class="form-group"><label>Password</label><input type="password" name="db_pass" value="" autocomplete="new-password" placeholder="Leave blank to keep previous"></div>
      <div class="form-group col-span-2"><label>Table Prefix</label><input type="text" name="db_prefix" value="<?= esc($_SESSION['install']['db']['prefix'] ?? 'soi_') ?>"><span class="field-hint">Letters, numbers, underscore only.</span></div>
    </div>
    <div class="btn-row">
      <a href="?step=1" class="btn btn-ghost">← Back</a>
      <button type="submit" class="btn btn-primary">Test Connection & Continue →</button>
    </div>
  </form>

  <?php elseif ($step === 3): ?>
  <h2 class="card-title">Site Identity</h2>
  <p class="card-sub">Configure domain packaging fields. These drive Files Service app identity later — no Search Console values are assumed.</p>
  <form method="POST">
    <input type="hidden" name="step" value="3">
    <?= installer_csrf_field() ?>
    <div class="form-grid">
      <div class="form-group col-span-2"><label>Site Name</label><input type="text" name="site_name" value="<?= esc($identity['name'] ?? '') ?>" placeholder="My Organization Website" required></div>
      <div class="form-group"><label>Site Domain</label><input type="text" name="site_domain" value="<?= esc($identity['domain'] ?? '') ?>" placeholder="example.com" required><span class="field-hint">Hostname only — no https:// or path.</span></div>
      <div class="form-group"><label>SiteSlug</label><input type="text" name="site_slug" value="<?= esc($identity['slug'] ?? '') ?>" placeholder="example" pattern="[a-z0-9][a-z0-9_-]{0,63}"><span class="field-hint">Used as Files Service requested_app_id.</span></div>
      <div class="form-group col-span-2"><label>Site URL</label><input type="url" name="site_url" value="<?= esc($identity['url'] ?? installer_detect_site_url()) ?>" required></div>
      <div class="form-group col-span-2"><label>Canonical Base URL</label><input type="url" name="canonical_base_url" value="<?= esc($identity['canonical_base_url'] ?? '') ?>" placeholder="Leave blank to use Site URL"></div>
      <div class="form-group"><label>Admin Base URL</label><input type="url" name="admin_base_url" value="<?= esc($identity['admin_base_url'] ?? '') ?>" placeholder="Leave blank for /admin"></div>
      <div class="form-group"><label>Frontend Base URL</label><input type="url" name="frontend_base_url" value="<?= esc($identity['frontend_base_url'] ?? '') ?>" placeholder="Leave blank to use Site URL"></div>
      <div class="form-group"><label>Environment</label>
        <select name="environment_label">
          <?php foreach (['production','staging','development','local'] as $env): ?>
          <option value="<?= $env ?>" <?= (($identity['environment'] ?? 'production') === $env) ? 'selected' : '' ?>><?= ucfirst($env) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Contact Email</label><input type="email" name="contact_email" value="<?= esc($identity['email'] ?? '') ?>" required></div>
      <div class="form-group col-span-2"><label>Timezone</label>
        <select name="timezone">
          <?php foreach (timezone_identifiers_list() as $tz): ?>
          <option value="<?= $tz ?>" <?= (($identity['timezone'] ?? 'UTC') === $tz) ? 'selected' : '' ?>><?= $tz ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="btn-row">
      <a href="?step=2" class="btn btn-ghost">← Back</a>
      <button type="submit" class="btn btn-primary">Continue to Auth →</button>
    </div>
  </form>

  <?php elseif ($step === 4): ?>
  <h2 class="card-title">Admin / Auth Setup</h2>
  <p class="card-sub">This CMS uses SOI Accounts / SAML for admin access. Local password admin is not the primary model.</p>
  <form method="POST">
    <input type="hidden" name="step" value="4">
    <?= installer_csrf_field() ?>
    <div class="form-group">
      <label>Auth mode</label>
      <select name="auth_mode">
        <option value="accounts_saml" <?= (($auth['auth_mode'] ?? 'accounts_saml') === 'accounts_saml') ? 'selected' : '' ?>>SOI Accounts / SAML (recommended)</option>
        <option value="accounts_pending" <?= (($auth['auth_mode'] ?? '') === 'accounts_pending') ? 'selected' : '' ?>>Mark SAML/Accounts as pending (configure after install)</option>
      </select>
      <span class="field-hint">Admin pages remain protected. Connect Accounts before production use.</span>
    </div>
    <label style="display:flex;gap:0.5rem;align-items:flex-start;margin:1rem 0;">
      <input type="checkbox" name="connect_accounts_now" value="1" <?= !empty($auth['connect_accounts_now']) || !isset($auth['connect_accounts_now']) ? 'checked' : '' ?>>
      <span>Redirect to SOI Accounts connect flow immediately after install (when SAML mode is selected).</span>
    </label>
    <div class="btn-row">
      <a href="?step=3" class="btn btn-ghost">← Back</a>
      <button type="submit" class="btn btn-primary">Continue to Files Service →</button>
    </div>
  </form>

  <?php elseif ($step === 5): ?>
  <h2 class="card-title">Files Service Preparation</h2>
  <p class="card-sub">No existing credentials are bundled. After install, request a new Files Service app connection for this domain.</p>
  <form method="POST">
    <input type="hidden" name="step" value="5">
    <?= installer_csrf_field() ?>
    <div class="form-grid">
      <div class="form-group col-span-2">
        <label>Files Service Base URL</label>
        <input type="url" name="files_service_url" value="<?= esc($fs['files_service_url'] ?? 'https://files.soi.co.in') ?>" required>
      </div>
      <div class="form-group"><label>Derived source_domain</label><input type="text" value="<?= esc($identity['domain'] ?? '') ?>" readonly></div>
      <div class="form-group"><label>Derived requested_app_id</label><input type="text" value="<?= esc($identity['slug'] ?? '') ?>" readonly></div>
      <div class="form-group col-span-2"><label>Derived requested_app_name</label><input type="text" value="<?= esc($identity['name'] ?? '') ?>" readonly></div>
    </div>
    <div class="alert alert-error" style="background:#eff6ff;border-color:#bfdbfe;color:#1e3a8a;">
      Media delivery URLs will use <code>https://files.soi.co.in/media/{file_id}</code> after mapping. Client secrets are never included in this package.
    </div>
    <div class="btn-row">
      <a href="?step=4" class="btn btn-ghost">← Back</a>
      <button type="submit" class="btn btn-primary">Continue to Install →</button>
    </div>
  </form>

  <?php elseif ($step === 6): ?>
  <h2 class="card-title">Review &amp; Install</h2>
  <p class="card-sub">Creates tables, seeds generic starter pages, writes <code>config/config.php</code>, and locks the installer.</p>
  <ul style="line-height:1.7;color:#475569;">
    <li><strong>Site:</strong> <?= esc($identity['name'] ?? '') ?> (<?= esc($identity['domain'] ?? '') ?>)</li>
    <li><strong>Slug:</strong> <?= esc($identity['slug'] ?? '') ?></li>
    <li><strong>URL:</strong> <?= esc($identity['url'] ?? '') ?></li>
    <li><strong>DB:</strong> <?= esc($_SESSION['install']['db']['name'] ?? '') ?> @ <?= esc($_SESSION['install']['db']['host'] ?? '') ?></li>
    <li><strong>Files Service:</strong> <?= esc($fs['files_service_url'] ?? 'https://files.soi.co.in') ?></li>
    <li><strong>Auth:</strong> <?= esc($auth['auth_mode'] ?? 'accounts_saml') ?></li>
  </ul>
  <form method="POST">
    <input type="hidden" name="step" value="6">
    <?= installer_csrf_field() ?>
    <div class="btn-row">
      <a href="?step=5" class="btn btn-ghost">← Back</a>
      <button type="submit" class="btn btn-primary">Install SOI Source CMS →</button>
    </div>
  </form>

  <?php elseif ($step === 7 && is_array($complete)): ?>
  <h2 class="card-title">Installation complete</h2>
  <p class="card-sub">Installer is locked. For security, delete or restrict the <code>/install</code> directory after you finish Accounts connect.</p>
  <div class="req-list">
    <div class="req-item req-ok"><span class="req-icon">✅</span><div class="req-info"><strong>Site</strong><span><?= esc($complete['site_name'] ?? '') ?> · <?= esc($complete['site_domain'] ?? '') ?></span></div></div>
    <div class="req-item req-ok"><span class="req-icon">✅</span><div class="req-info"><strong>Admin URL</strong><span><a href="<?= esc($complete['admin_url'] ?? '#') ?>"><?= esc($complete['admin_url'] ?? '') ?></a></span></div></div>
    <div class="req-item req-ok"><span class="req-icon">✅</span><div class="req-info"><strong>Next: Files Service</strong><span>Connect as a new app from Admin → Files Service Connector (no credentials were preloaded).</span></div></div>
    <div class="req-item req-ok"><span class="req-icon">✅</span><div class="req-info"><strong>Next: CMS Readiness</strong><span>Run Admin → CMS Readiness after login.</span></div></div>
  </div>
  <div class="btn-row">
    <a class="btn btn-primary" href="<?= esc(($complete['admin_url'] ?? '../admin') . '/connect.php') ?>">Open Admin Connect</a>
    <a class="btn btn-ghost" href="<?= esc(($complete['admin_url'] ?? '../admin') . '/cms-readiness.php') ?>">CMS Readiness</a>
  </div>
  <?php else: ?>
  <div class="alert alert-error">Invalid installer step.</div>
  <div class="btn-row"><a href="?step=1" class="btn btn-ghost">← Start over</a></div>
  <?php endif; ?>

  </div>
</div>
</body>
</html>
