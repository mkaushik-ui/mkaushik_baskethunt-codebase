<?php
/**
 * Admin - SOI Central integration settings
 */
$pageTitle = 'SOI Central';
$activeNav = 'soi-central';

if (!defined('SOI_ROOT')) define('SOI_ROOT', dirname(__DIR__));
require_once SOI_ROOT . '/config/config.php';
require_once SOI_ROOT . '/core/helpers.php';
spl_autoload_register(fn($c) => (fn($f) => file_exists($f) && require_once $f)(SOI_ROOT.'/core/'.str_replace(['SOI\\Core\\','\\'],['','/'],$c).'.php'));

use SOI\Core\{Database, Auth, Accounts, SoiCentralAuth};

Database::connect(['host'=>SOI_DB_HOST,'name'=>SOI_DB_NAME,'user'=>SOI_DB_USER,'pass'=>SOI_DB_PASS,'port'=>SOI_DB_PORT,'prefix'=>SOI_DB_PREFIX]);
Auth::init();
SoiCentralAuth::install();
Auth::requireAuth('admin');

function soi_central_opt(string $key, string $default = ''): string {
    return (string) \SOI\Core\Database::getOption('soi_central_' . $key, $default);
}

function soi_central_mask(string $value): string {
    return $value === '' ? '' : '********';
}

$currentTab = $_GET['tab'] ?? 'connection';
$validTabs = ['connection', 'security', 'appearance', 'status'];
if (!in_array($currentTab, $validTabs, true)) $currentTab = 'connection';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['_csrf'] ?? '')) die('CSRF');

    $action = $_POST['_action'] ?? 'save';

    try {
        if ($action === 'save') {
            $accountsLinked = Accounts::isLinked();
            
            if ($currentTab === 'connection') {
                $checkboxes = ['enabled', 'directory_required'];
                foreach ($checkboxes as $field) {
                    Database::setOption('soi_central_' . $field, isset($_POST[$field]) ? '1' : '0');
                }
                $textFields = ['base_url','app_id','client_id','public_embed_key','webhook_secret',
                    'saml_sp_entity_id','saml_idp_entity_id','saml_sso_url','saml_metadata_url','saml_x509_cert'];
                $linkedProtectedFields = ['app_id','client_id','saml_sso_url','saml_metadata_url','saml_x509_cert'];
                foreach ($textFields as $field) {
                    if ($accountsLinked && in_array($field, $linkedProtectedFields, true)) continue;
                    Database::setOption('soi_central_' . $field, trim((string) ($_POST[$field] ?? '')));
                }
                $accountsAppId = trim((string) Database::getOption('accounts_app_id', ''));
                if ($accountsAppId !== '') {
                    Database::setOption('soi_central_app_id', $accountsAppId);
                    Database::setOption('soi_central_saml_sso_url', 'https://accounts.soi.co.in/saml/sso?app=' . rawurlencode($accountsAppId));
                    Database::setOption('soi_central_saml_metadata_url', 'https://accounts.soi.co.in/saml/metadata?app=' . rawurlencode($accountsAppId));
                }
                $postedSecret = trim((string) ($_POST['client_secret'] ?? ''));
                if (!$accountsLinked && $postedSecret !== '' && $postedSecret !== '********') {
                    Database::setOption('soi_central_client_secret', Accounts::encryptSecret($postedSecret));
                }
                Database::setOption('my_account_app_id', $accountsLinked ? $accountsAppId : trim((string) ($_POST['app_id'] ?? '')));
                Database::setOption('my_account_public_key', trim((string) ($_POST['public_embed_key'] ?? '')));
            } 
            elseif ($currentTab === 'security') {
                $checkboxes = ['auto_provision', 'sync_local_role', 'enforce_admin', 'enforce_frontend', 'enforce_kb', 'enforce_browser', 'central_logout', 'live_session_sync', 'session_enforcement', 'session_fail_closed'];
                foreach ($checkboxes as $field) {
                    Database::setOption('soi_central_' . $field, isset($_POST[$field]) ? '1' : '0');
                }
                Database::setOption('soi_central_allow_local_login', '0');
                $textFields = ['cache_ttl','timestamp_skew','session_poll_interval','session_recheck_seconds','admin_permission','editor_permission','author_permission','subscriber_permission','kb_permission'];
                foreach ($textFields as $field) {
                    Database::setOption('soi_central_' . $field, trim((string) ($_POST[$field] ?? '')));
                }
                $pollInterval = max(15, min(300, (int) Database::getOption('soi_central_session_poll_interval', '30')));
                Database::setOption('soi_central_session_poll_interval', (string) $pollInterval);
                $recheckSeconds = max(15, min(300, (int) Database::getOption('soi_central_session_recheck_seconds', '30')));
                Database::setOption('soi_central_session_recheck_seconds', (string) $recheckSeconds);
            } 
            elseif ($currentTab === 'appearance') {
                $textFields = ['widget_variant','widget_size','widget_radius','widget_theme','widget_menu_type','widget_custom_css'];
                foreach ($textFields as $field) {
                    Database::setOption('soi_central_' . $field, trim((string) ($_POST[$field] ?? '')));
                }
            }

            soi_flash('success', 'SOI Central settings saved.');
            soi_redirect(SOI_ADMIN_URL . '/soi-central.php?tab=' . $currentTab);
        }

        if ($action === 'fetch_metadata') {
            Accounts::syncSamlFromStoredCredentials(true);
            $metadata = SoiCentralAuth::ensureIdpMetadata(true, 'admin_manual');
            $certCount = count($metadata['x509_certs'] ?? []);
            soi_flash('success', 'SAML metadata refreshed from Accounts (' . $certCount . ' certificate(s) stored).');
            soi_redirect(SOI_ADMIN_URL . '/soi-central.php?tab=status');
        }

        if ($action === 'sync_credentials') {
            Accounts::syncSamlFromStoredCredentials(true);
            soi_flash('success', 'SOI Central credentials synced from stored Accounts link (app_id and SAML metadata updated).');
            soi_redirect(SOI_ADMIN_URL . '/soi-central.php?tab=status');
        }

        if ($action === 'relink_accounts') {
            error_log('[Accounts] Admin initiated re-link from SOI Central settings.');
            soi_flash('success', 'Redirecting to SOI Accounts to refresh credentials. Complete the flow there, then sign in again.');
            header('Location: ' . Accounts::buildRelinkUrl());
            exit;
        }

        if ($action === 'validate_app_id') {
            $validation = SoiCentralAuth::validateAppIdWithAccounts();
            if ($validation['valid']) {
                soi_flash('success', 'App ID is valid in Accounts (' . $validation['cert_count'] . ' signing certificate(s) found).');
            } else {
                soi_flash('error', 'App ID validation failed: ' . $validation['error']);
            }
            soi_redirect(SOI_ADMIN_URL . '/soi-central.php?tab=status');
        }

        if ($action === 'sync_manifest') {
            SoiCentralAuth::syncManifest();
            soi_flash('success', 'Application manifest synced with SOI Central.');
            soi_redirect(SOI_ADMIN_URL . '/soi-central.php?tab=status');
        }

        if ($action === 'test_directory') {
            $user = Auth::user();
            $permission = soi_central_opt('admin_permission', 'cms.admin');
            $access = SoiCentralAuth::checkAccess((string) ($user['email'] ?? ''), $permission);
            soi_flash($access['allowed'] ? 'success' : 'error', ($access['message'] ?? 'Directory check completed.') . ' Status: ' . ($access['status'] ?? 'unknown'));
            soi_redirect(SOI_ADMIN_URL . '/soi-central.php?tab=status');
        }

        if ($action === 'clear_cache') {
            Database::query("DELETE FROM `" . Database::prefix('central_access_cache') . "`");
            Database::query("DELETE FROM `" . Database::prefix('central_saml_replay') . "` WHERE expires_at < NOW()");
            soi_flash('success', 'SOI Central cache cleared.');
            soi_redirect(SOI_ADMIN_URL . '/soi-central.php?tab=status');
        }
    } catch (Throwable $e) {
        soi_flash('error', $e->getMessage());
        soi_redirect(SOI_ADMIN_URL . '/soi-central.php?tab=' . $currentTab);
    }
}

$opts = [];
foreach ([
    'enabled','base_url','app_id','client_id','client_secret','public_embed_key','webhook_secret',
    'saml_sp_entity_id','saml_idp_entity_id','saml_sso_url','saml_metadata_url','saml_x509_cert',
    'allow_local_login','auto_provision','sync_local_role','directory_required','enforce_admin',
    'enforce_frontend','enforce_kb','enforce_browser','central_logout','live_session_sync','session_enforcement','session_fail_closed','cache_ttl','timestamp_skew','session_poll_interval','session_recheck_seconds',
    'admin_permission','editor_permission','author_permission','subscriber_permission','kb_permission',
    'widget_variant','widget_size','widget_radius','widget_theme','widget_menu_type','widget_custom_css',
] as $key) {
    $opts[$key] = soi_central_opt($key);
}

$endpoints = [
    'SP metadata' => SoiCentralAuth::spMetadataUrl(),
    'ACS URL' => SoiCentralAuth::acsUrl(),
    'Manifest URL' => SoiCentralAuth::cmsBaseUrl() . '/soi-central/manifest.json',
    'Webhook URL' => SoiCentralAuth::cmsBaseUrl() . '/soi-central/webhook',
    'Health URL' => SoiCentralAuth::cmsBaseUrl() . '/soi-central/health',
    'Session Status URL' => SoiCentralAuth::sessionStatusUrl(),
    'Session Live-Check URL' => rtrim(SoiCentralAuth::cmsBaseUrl(), '/') . '/soi-central/session/live-check',
];

$metadataStatus = SoiCentralAuth::getMetadataFetchStatus();
$credentialSnapshot = Accounts::getCredentialSnapshot();
$appIdConfig = SoiCentralAuth::validateAppIdConfiguration();
$accountsLinked = Accounts::isLinked();
$accountsClientSecret = trim((string) Database::getOption('accounts_client_secret', ''));
$centralClientSecret = trim((string) Database::getOption('soi_central_client_secret', ''));
$secretSyncLabel = match (true) {
    $accountsClientSecret !== '' && $centralClientSecret !== '' => 'Synced (both present)',
    $accountsClientSecret !== '' && $centralClientSecret === '' => 'Drift — accounts secret only (run Sync Credentials)',
    $accountsClientSecret === '' && $centralClientSecret !== '' => 'Drift — soi_central secret only (re-link Accounts)',
    default => 'Missing (not linked)',
};
$relinkedAt = trim((string) Database::getOption('accounts_relinked_at', ''));

require_once __DIR__ . '/partials/header.php';
?>

<style>
.soi-central-grid{display:grid;grid-template-columns:1fr;gap:1.25rem;align-items:start}
.soi-central-actions{display:flex;gap:.6rem;flex-wrap:wrap}
.soi-central-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.78rem;word-break:break-all;color:var(--text-muted)}
.soi-central-status{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.75rem}
.soi-central-status div{border:1px solid var(--border);border-radius:8px;padding:.75rem;background:var(--surface2)}
@media(max-width:980px){.soi-central-status{grid-template-columns:1fr}}
</style>

<div class="settings-tabs">
  <a class="settings-tab <?= $currentTab === 'connection' ? 'active' : '' ?>" href="soi-central.php?tab=connection">Connection</a>
  <a class="settings-tab <?= $currentTab === 'security' ? 'active' : '' ?>" href="soi-central.php?tab=security">Security Policy</a>
  <a class="settings-tab <?= $currentTab === 'appearance' ? 'active' : '' ?>" href="soi-central.php?tab=appearance">Widget Appearance</a>
  <a class="settings-tab <?= $currentTab === 'status' ? 'active' : '' ?>" href="soi-central.php?tab=status">Status & Actions</a>
</div>

<div class="soi-central-grid">
  <?php if (in_array($currentTab, ['connection', 'security', 'appearance'])): ?>
  <form method="POST" action="soi-central.php?tab=<?= esc($currentTab) ?>" style="display:flex;flex-direction:column;gap:1.25rem;">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="_action" value="save">
    
    <?php if ($currentTab === 'connection'): ?>
    <div class="card">
      <div class="card-header"><h3 class="card-title">Connection</h3></div>
      <div class="card-body">
        <div style="display:flex;flex-direction:column;gap:.8rem;margin-bottom:1rem;">
          <label class="toggle-wrap">
            <span class="toggle-switch"><input type="checkbox" name="enabled" value="1" <?= $opts['enabled']==='1'?'checked':'' ?>><span class="toggle-slider"></span></span>
            <span class="toggle-label">Enable SOI Central login, Directory checks, embed, manifest, and webhooks</span>
          </label>
          <label class="toggle-wrap">
            <span class="toggle-switch"><input type="checkbox" name="directory_required" value="1" <?= $opts['directory_required']==='1'?'checked':'' ?>><span class="toggle-slider"></span></span>
            <span class="toggle-label">Require signed SOI Directory authorization for protected access</span>
          </label>
          <input type="hidden" name="allow_local_login" value="0">
        </div>

        <div class="form-row">
          <div class="form-group full">
            <label class="form-label">SOI Central Base URL</label>
            <input class="form-input" type="url" name="base_url" value="<?= esc($opts['base_url'] ?: 'https://accounts.soi.co.in') ?>" required>
          </div>
          <div class="form-group">
            <label class="form-label">App ID / Slug<?= $accountsLinked ? ' <span style="font-weight:400;color:var(--text-muted);">(managed by Accounts link)</span>' : '' ?></label>
            <input class="form-input" type="text" name="app_id" value="<?= esc($opts['app_id']) ?>" placeholder="knowledge-center"<?= $accountsLinked ? ' readonly' : '' ?>>
          </div>
          <div class="form-group">
            <label class="form-label">Client ID<?= $accountsLinked ? ' <span style="font-weight:400;color:var(--text-muted);">(managed by Accounts link)</span>' : '' ?></label>
            <input class="form-input" type="text" name="client_id" value="<?= esc($opts['client_id']) ?>" autocomplete="off"<?= $accountsLinked ? ' readonly' : '' ?>>
          </div>
          <div class="form-group">
            <label class="form-label">Client Secret</label>
            <input class="form-input" type="password" name="client_secret" value="<?= esc(soi_central_mask($opts['client_secret'])) ?>" autocomplete="new-password">
          </div>
          <div class="form-group">
            <label class="form-label">Public Embed Key</label>
            <input class="form-input" type="text" name="public_embed_key" value="<?= esc($opts['public_embed_key']) ?>" autocomplete="off">
          </div>
          <div class="form-group full">
            <label class="form-label">Webhook Signing Secret</label>
            <input class="form-input" type="password" name="webhook_secret" value="<?= esc($opts['webhook_secret']) ?>" autocomplete="new-password">
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">SAML Service Provider</h3></div>
      <div class="card-body">
        <div class="form-row">
          <div class="form-group full">
            <label class="form-label">SP Entity ID</label>
            <input class="form-input" name="saml_sp_entity_id" value="<?= esc($opts['saml_sp_entity_id'] ?: SoiCentralAuth::spMetadataUrl()) ?>">
          </div>
          <div class="form-group full">
            <label class="form-label">IdP Metadata URL<?= $accountsLinked ? ' <span style="font-weight:400;color:var(--text-muted);">(managed by Accounts link)</span>' : '' ?></label>
            <input class="form-input" name="saml_metadata_url" value="<?= esc($opts['saml_metadata_url'] ?: ($opts['base_url'] ?: 'https://accounts.soi.co.in') . '/saml/metadata?app=' . rawurlencode($opts['app_id'])) ?>"<?= $accountsLinked ? ' readonly' : '' ?>>
          </div>
          <div class="form-group full">
            <label class="form-label">IdP Entity ID</label>
            <input class="form-input" name="saml_idp_entity_id" value="<?= esc($opts['saml_idp_entity_id']) ?>">
          </div>
          <div class="form-group full">
            <label class="form-label">IdP SSO URL<?= $accountsLinked ? ' <span style="font-weight:400;color:var(--text-muted);">(managed by Accounts link)</span>' : '' ?></label>
            <input class="form-input" name="saml_sso_url" value="<?= esc($opts['saml_sso_url'] ?: ($opts['base_url'] ?: 'https://accounts.soi.co.in') . '/saml/sso?app=' . rawurlencode($opts['app_id'])) ?>"<?= $accountsLinked ? ' readonly' : '' ?>>
          </div>
          <div class="form-group full">
            <label class="form-label">IdP X.509 Certificate<?= $accountsLinked ? ' <span style="font-weight:400;color:var(--text-muted);">(managed by Accounts link)</span>' : '' ?></label>
            <textarea class="form-textarea" name="saml_x509_cert" rows="8" spellcheck="false"<?= $accountsLinked ? ' readonly' : '' ?>><?= esc($opts['saml_x509_cert']) ?></textarea>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($currentTab === 'security'): ?>
    <div class="card">
      <div class="card-header"><h3 class="card-title">Protection Policy</h3></div>
      <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.8rem;margin-bottom:1rem;">
          <?php foreach ([
              'enforce_admin' => 'Protect CMS admin',
              'enforce_frontend' => 'Protect public frontend',
              'enforce_kb' => 'Protect Knowledge Base admin',
              'enforce_browser' => 'Ask SOI Central to enforce browser policy',
              'auto_provision' => 'Auto-provision local users after SAML',
              'sync_local_role' => 'Sync local CMS role from SOI roles',
              'central_logout' => 'Route logout through SOI Central',
              'live_session_sync' => 'Live Accounts session sync (auto-logout all tabs)',
              'session_enforcement' => 'Server-side Accounts session guard (Phase 2/3)',
              'session_fail_closed' => 'Fail closed when Accounts validation is unavailable',
          ] as $field => $label): ?>
          <label class="toggle-wrap">
            <span class="toggle-switch"><input type="checkbox" name="<?= esc($field) ?>" value="1" <?= $opts[$field]==='1'?'checked':'' ?>><span class="toggle-slider"></span></span>
            <span class="toggle-label"><?= esc($label) ?></span>
          </label>
          <?php endforeach; ?>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Admin Permission</label>
            <input class="form-input" name="admin_permission" value="<?= esc($opts['admin_permission'] ?: 'cms.admin') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Editor Permission</label>
            <input class="form-input" name="editor_permission" value="<?= esc($opts['editor_permission'] ?: 'cms.content.publish') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Author Permission</label>
            <input class="form-input" name="author_permission" value="<?= esc($opts['author_permission'] ?: 'cms.content.edit') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Subscriber Permission</label>
            <input class="form-input" name="subscriber_permission" value="<?= esc($opts['subscriber_permission']) ?>">
          </div>
          <div class="form-group">
            <label class="form-label">KB Permission</label>
            <input class="form-input" name="kb_permission" value="<?= esc($opts['kb_permission'] ?: 'kb.content.manage') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Cache TTL Seconds</label>
            <input class="form-input" type="number" min="30" name="cache_ttl" value="<?= esc($opts['cache_ttl'] ?: '300') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Timestamp Skew Seconds</label>
            <input class="form-input" type="number" min="60" name="timestamp_skew" value="<?= esc($opts['timestamp_skew'] ?: '300') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Live session poll interval (seconds)</label>
            <input class="form-input" type="number" min="15" max="300" step="15" name="session_poll_interval" value="<?= esc($opts['session_poll_interval'] ?: '30') ?>">
            <p class="form-hint" style="margin-top:0.35rem;font-size:0.8rem;color:var(--text-muted);">Open tabs poll accounts.soi.co.in every 15–300s and when you return to a tab. Logout anywhere signs out all CMS tabs.</p>
          </div>
          <div class="form-group">
            <label class="form-label">Server session recheck interval (seconds)</label>
            <input class="form-input" type="number" min="15" max="300" step="15" name="session_recheck_seconds" value="<?= esc($opts['session_recheck_seconds'] ?: '30') ?>">
            <p class="form-hint" style="margin-top:0.35rem;font-size:0.8rem;color:var(--text-muted);">Protected CMS requests revalidate the Accounts session every 15–300s (default 30s).</p>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($currentTab === 'appearance'): ?>
    <div class="card">
      <div class="card-header"><h3 class="card-title">Widget Appearance</h3></div>
      <div class="card-body">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Variant</label>
            <select class="form-input" name="widget_variant">
              <option value="dropdown" <?= $opts['widget_variant'] === 'dropdown' ? 'selected' : '' ?>>Dropdown (Default)</option>
              <option value="compact" <?= $opts['widget_variant'] === 'compact' ? 'selected' : '' ?>>Compact Pill</option>
              <option value="settings_card" <?= $opts['widget_variant'] === 'settings_card' ? 'selected' : '' ?>>Settings Card</option>
              <option value="full" <?= $opts['widget_variant'] === 'full' ? 'selected' : '' ?>>Full Profile</option>
              <option value="avatar_only" <?= $opts['widget_variant'] === 'avatar_only' ? 'selected' : '' ?>>Avatar Only</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Size</label>
            <select class="form-input" name="widget_size">
              <option value="default" <?= $opts['widget_size'] === 'default' ? 'selected' : '' ?>>Default (36px)</option>
              <option value="small" <?= $opts['widget_size'] === 'small' ? 'selected' : '' ?>>Small (28px)</option>
              <option value="compact" <?= $opts['widget_size'] === 'compact' ? 'selected' : '' ?>>Compact (32px)</option>
              <option value="large" <?= $opts['widget_size'] === 'large' ? 'selected' : '' ?>>Large (48px)</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Shape / Radius</label>
            <select class="form-input" name="widget_radius">
              <option value="full" <?= $opts['widget_radius'] === 'full' || $opts['widget_radius'] === '' ? 'selected' : '' ?>>Circle (Full)</option>
              <option value="pill" <?= $opts['widget_radius'] === 'pill' ? 'selected' : '' ?>>Pill</option>
              <option value="square" <?= $opts['widget_radius'] === 'square' ? 'selected' : '' ?>>Square</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Theme</label>
            <select class="form-input" name="widget_theme">
              <option value="light" <?= $opts['widget_theme'] === 'light' || $opts['widget_theme'] === '' ? 'selected' : '' ?>>Light</option>
              <option value="dark" <?= $opts['widget_theme'] === 'dark' ? 'selected' : '' ?>>Dark</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Menu Type</label>
            <select class="form-input" name="widget_menu_type">
              <option value="standard" <?= $opts['widget_menu_type'] === 'standard' || $opts['widget_menu_type'] === '' ? 'selected' : '' ?>>Standard</option>
              <option value="scrollable" <?= $opts['widget_menu_type'] === 'scrollable' ? 'selected' : '' ?>>Scrollable</option>
              <option value="partial" <?= $opts['widget_menu_type'] === 'partial' ? 'selected' : '' ?>>Partial (Compact)</option>
            </select>
          </div>
        </div>
        <div class="form-group full" style="margin-top: 1rem;">
          <label class="form-label">Custom SSO Widget CSS <span style="font-weight:400;color:var(--text-muted);">(Injects into admin header to override default Accounts widget styling)</span></label>
          <textarea class="form-textarea" name="widget_custom_css" rows="6" spellcheck="false" placeholder="#soi-my-account-widget {&#10;  transform: scale(0.85);&#10;  transform-origin: center right;&#10;}"><?= esc($opts['widget_custom_css']) ?></textarea>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <div class="btn-row" style="border:none;padding-top:0;margin-top:0;">
      <button class="btn btn-primary" type="submit">Save <?= esc(ucfirst($currentTab)) ?> Settings</button>
    </div>
  </form>
  <?php endif; ?>

  <?php if ($currentTab === 'status'): ?>
  <div style="display:flex;flex-direction:column;gap:1.25rem;">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Readiness</h3></div>
      <div class="card-body">
        <div class="soi-central-status">
          <div><strong>Enabled</strong><br><span class="soi-central-code"><?= $opts['enabled']==='1'?'Yes':'No' ?></span></div>
          <div><strong>App ID (canonical)</strong><br><span class="soi-central-code"><?= esc($appIdConfig['canonical_app_id'] !== '' ? $appIdConfig['canonical_app_id'] : 'Missing') ?></span></div>
          <div><strong>accounts_app_id</strong><br><span class="soi-central-code"><?= esc($credentialSnapshot['accounts_app_id'] !== '' ? $credentialSnapshot['accounts_app_id'] : 'Missing') ?></span></div>
          <div><strong>Client Secret</strong><br><span class="soi-central-code"><?= $opts['client_secret']!==''?'Present':'Missing' ?></span></div>
          <div><strong>Secret Sync</strong><br><span class="soi-central-code"><?= esc($secretSyncLabel) ?></span></div>
          <div><strong>Last Re-link</strong><br><span class="soi-central-code"><?= esc($relinkedAt !== '' ? $relinkedAt : 'Never') ?></span></div>
          <div><strong>SAML Cert</strong><br><span class="soi-central-code"><?= $opts['saml_x509_cert']!==''?'Present':'Missing' ?></span></div>
          <div><strong>Metadata URL</strong><br><span class="soi-central-code"><?= esc(($metadataStatus['metadata_url'] ?? '') !== '' ? $metadataStatus['metadata_url'] : 'Missing') ?></span></div>
          <div><strong>Metadata Auto-Refresh</strong><br><span class="soi-central-code"><?= ($metadataStatus['stale'] ?? true) ? 'Stale — will refresh on next SAML login' : 'Fresh' ?></span></div>
          <div><strong>Last Metadata Fetch</strong><br><span class="soi-central-code"><?= esc($metadataStatus['fetched_at'] ?? 'Never') ?></span></div>
          <div><strong>Stored Certificates</strong><br><span class="soi-central-code"><?= (int) ($metadataStatus['cert_count'] ?? 0) ?></span></div>
        </div>
        <?php if ($credentialSnapshot['app_id_mismatch']): ?>
        <p style="margin-top:.75rem;font-size:.82rem;color:#b45309;">App ID mismatch: <?= esc($credentialSnapshot['mismatch_detail']) ?>. Use <strong>Sync Credentials</strong> or <strong>Re-link Accounts</strong>.</p>
        <?php endif; ?>
        <?php if (($metadataStatus['error'] ?? '') !== ''): ?>
        <p style="margin-top:.75rem;font-size:.82rem;color:#b45309;">Last auto-fetch error: <?= esc($metadataStatus['error']) ?></p>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Actions</h3></div>
      <div class="card-body">
        <p style="font-size:.82rem;color:var(--text-muted);margin:0 0 .75rem;">Metadata is fetched automatically when linking Accounts and before SAML login. Use Fetch Metadata only for manual recovery.<?= $accountsLinked ? ' Re-link Accounts refreshes OAuth credentials and SAML settings from SOI Accounts.' : '' ?></p>
        <div class="soi-central-actions">
          <?php foreach ([
              'sync_credentials' => 'Sync Credentials',
              'validate_app_id' => 'Validate App ID',
              'fetch_metadata' => 'Fetch Metadata',
              'relink_accounts' => 'Re-link Accounts',
              'sync_manifest' => 'Sync Manifest',
              'test_directory' => 'Test Directory',
              'clear_cache' => 'Clear Cache',
          ] as $action => $label): ?>
          <form method="POST">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="_action" value="<?= esc($action) ?>">
            <button class="btn btn-ghost btn-sm" type="submit"><?= esc($label) ?></button>
          </form>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Application Endpoints</h3></div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:.75rem;">
        <?php foreach ($endpoints as $label => $url): ?>
        <div>
          <strong style="font-size:.8rem;"><?= esc($label) ?></strong>
          <div class="soi-central-code"><?= esc($url) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">SOI Admin Center Checklist</h3></div>
      <div class="card-body" style="font-size:.82rem;color:var(--text-muted);line-height:1.7;">
        <p>In SOI Admin Center, create or edit this registered app, then set the base URL, client ID, client secret, SAML SP entity ID, ACS URL, allowed redirect URLs, allowed embed origins, and webhook URL shown above.</p>
        <p>Use Directory mode <strong>access_check</strong> or <strong>full_managed</strong> when server-side authorization should be enforced.</p>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>