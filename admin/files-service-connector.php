<?php
$pageTitle = 'Files Service Connector';
$activeNav = 'files-service-connector';
$pageContentClass = 'page-content--fluid';

if (!defined('SOI_ROOT')) define('SOI_ROOT', dirname(__DIR__));
require_once SOI_ROOT . '/config/config.php';
require_once SOI_ROOT . '/core/helpers.php';
require_once SOI_ROOT . '/plugins/files-service-connector/plugin.php';
spl_autoload_register(fn($c) => (fn($f) => file_exists($f) && require_once $f)(SOI_ROOT.'/core/'.str_replace(['SOI\\Core\\','\\'],['','/'],$c).'.php'));
use SOI\Core\{Database, Auth};
Database::connect(['host'=>SOI_DB_HOST,'name'=>SOI_DB_NAME,'user'=>SOI_DB_USER,'pass'=>SOI_DB_PASS,'port'=>SOI_DB_PORT,'prefix'=>SOI_DB_PREFIX]);
Auth::init();
Auth::requireAuth('admin');

if (!function_exists('safe_get_option')) { function safe_get_option($key, $default = '') { return Database::getOption($key, $default); } }
if (!function_exists('safe_set_option')) { function safe_set_option($key, $value) { Database::setOption($key, $value); } }

if (!function_exists('fs_connector_safe_curl_error')) {
    function fs_connector_safe_curl_error(int $errorNumber): string
    {
        if (in_array($errorNumber, [35, 51, 58, 60, 77, 82, 83, 90], true)) {
            return 'The Files Service TLS certificate could not be verified.';
        }

        return match ($errorNumber) {
            CURLE_OPERATION_TIMEDOUT => 'The Files Service request timed out.',
            CURLE_COULDNT_RESOLVE_HOST => 'The Files Service hostname could not be resolved.',
            CURLE_COULDNT_CONNECT => 'The Files Service could not be reached.',
            default => 'The Files Service request failed before a response was received.',
        };
    }
}

if (!function_exists('fs_connector_apply_curl_security')) {
    function fs_connector_apply_curl_security($handle): void
    {
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($handle, CURLOPT_TIMEOUT, 15);
        curl_setopt($handle, CURLOPT_USERAGENT, 'SOI-CMS-Files-Connector/' . FS_CONNECTOR_VERSION);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }
    }
}

$errorMsg = '';
$lastResponse = '';
$migrationPlan = null;
$migrationResult = null;
$referenceResult = null;
$rollbackResult = null;
$verifyResult = null;
$lifecycleAudit = null;
$lifecycleRepair = null;
$domainReadiness = null;

if (!function_exists('curl_version')) {
    $errorMsg = 'PHP cURL extension is not available.';
}

$filesUrl = FS_CONNECTOR_FILES_URL;
if ((string) safe_get_option('fsc_files_url', '') !== $filesUrl) {
    safe_set_option('fsc_files_url', $filesUrl);
}

// Repair any legacy full-URL value already stored in soi_options.
$storedDomainRaw = (string) safe_get_option('fs_conn_domain', '');
$siteIdentity = function_exists('fs_connector_site_identity') ? fs_connector_site_identity() : (function_exists('soi_site_identity') ? soi_site_identity() : []);
$fallbackDomain = fs_connector_normalize_hostname((string) ($siteIdentity['site_domain'] ?? (defined('SOI_HOME_URL') ? SOI_HOME_URL : '')));
$sourceDomain = fs_connector_normalize_hostname($storedDomainRaw);
if ($sourceDomain === '') {
    $sourceDomain = $fallbackDomain;
}
if ($sourceDomain !== '' && $storedDomainRaw !== $sourceDomain) {
    safe_set_option('fs_conn_domain', $sourceDomain);
}

$installId = trim((string) safe_get_option('fs_conn_install_id', ''));
if (!preg_match('/^[a-f0-9-]{36}$/i', $installId)) {
    try {
        $installId = fs_connector_generate_install_id();
        safe_set_option('fs_conn_install_id', $installId);
    } catch (Throwable $e) {
        $installId = '';
        $errorMsg = 'A secure connector installation identifier could not be generated.';
    }
}

$status = (string) safe_get_option('fsc_status', 'not_connected');
if ($status === 'connected' && !fs_connector_credentials_configured()) {
    $status = 'pending_approval';
    safe_set_option('fsc_status', $status);
}

$lastRequestDiagnostics = [];
$storedDiagnosticsRaw = (string) safe_get_option('fs_conn_last_request_payload', '');
if ($storedDiagnosticsRaw !== '') {
    $storedDiagnostics = json_decode($storedDiagnosticsRaw, true);
    if (is_array($storedDiagnostics)) {
        $lastRequestDiagnostics = fs_connector_request_diagnostics($storedDiagnostics);
        $sanitizedDiagnostics = json_encode($lastRequestDiagnostics, JSON_UNESCAPED_SLASHES);
        if ($sanitizedDiagnostics !== false && $sanitizedDiagnostics !== $storedDiagnosticsRaw) {
            safe_set_option('fs_conn_last_request_payload', $sanitizedDiagnostics);
        }
    }
}

$action = $_POST['_action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf((string) ($_POST['_csrf'] ?? ''))) {
        http_response_code(403);
        die('CSRF check failed.');
    }

    if ($action === 'test_discovery') {
        $filesUrlInput = rtrim(trim((string) ($_POST['files_url'] ?? '')), '/');
        if (!hash_equals(FS_CONNECTOR_FILES_URL, $filesUrlInput)) {
            $errorMsg = 'Files Service URL must remain ' . FS_CONNECTOR_FILES_URL . '.';
        } elseif (!function_exists('curl_init')) {
            $errorMsg = 'PHP cURL extension is not available.';
        } else {
            safe_set_option('fsc_files_url', FS_CONNECTOR_FILES_URL);
            $filesUrl = FS_CONNECTOR_FILES_URL;

            $ch = curl_init($filesUrl . '/api/connect/discovery');
            fs_connector_apply_curl_security($ch);
            $resp = curl_exec($ch);
            $curlErrorNumber = curl_errno($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($resp === false) {
                $errorMsg = fs_connector_safe_curl_error($curlErrorNumber);
            } else {
                $safeResponse = fs_connector_safe_remote_response($resp);
                $lastResponse = "HTTP Code: {$httpCode}\nResponse:\n{$safeResponse}";
                $decoded = json_decode($resp, true);

                if ($httpCode === 200 && is_array($decoded) && ($decoded['success'] ?? false) === true) {
                    $status = 'discovery_verified';
                    safe_set_option('fsc_status', $status);
                    soi_flash('success', 'Discovery verified successfully.');
                } else {
                    $errorMsg = "Discovery failed. HTTP Code: {$httpCode}.\nResponse:\n{$safeResponse}";
                }
            }
        }
    } elseif ($action === 'request_connection') {
        if (!in_array($status, ['discovery_verified'], true)) {
            $errorMsg = 'Test Discovery must succeed before requesting a connection.';
        } elseif (!function_exists('curl_init')) {
            $errorMsg = 'PHP cURL extension is not available.';
        } else {
            $normalizedDomain = fs_connector_normalize_hostname($_POST['domain'] ?? $sourceDomain);
            if ($normalizedDomain === '') {
                $errorMsg = 'Invalid source_domain. Must be a valid hostname without protocol.';
            } else {
                $sourceDomain = $normalizedDomain;
                safe_set_option('fs_conn_domain', $sourceDomain);

                $defaultSiteName = (string) ($siteIdentity['files_service_app_name'] ?? $siteIdentity['site_name'] ?? 'SOI CMS');
                $sourceSiteName = trim(strip_tags((string) ($_POST['site_name'] ?? $defaultSiteName)));
                $sourceSiteName = substr($sourceSiteName !== '' ? $sourceSiteName : $defaultSiteName, 0, 120);
                $entitySlug = strtolower(trim((string) ($_POST['entity_slug'] ?? 'soi')));
                $permissions = fs_connector_normalize_permissions($_POST['permissions'] ?? []);

                if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $entitySlug)) {
                    $errorMsg = 'Requested entity slug is invalid.';
                } elseif ($permissions === []) {
                    $errorMsg = 'Select at least one supported permission.';
                } elseif ($installId === '') {
                    $errorMsg = 'Connector install ID is unavailable. Reload the page and try again.';
                } else {
                    try {
                        $nonce = bin2hex(random_bytes(16));
                        $timestamp = fs_connector_current_timestamp();
                        $sourceCmsServerEpoch = fs_connector_parse_timestamp_epoch($timestamp) ?? time();
                        $requestPayload = fs_connector_build_request_payload(
                            $sourceDomain,
                            $sourceSiteName,
                            $entitySlug,
                            $permissions,
                            $installId,
                            $timestamp,
                            $nonce
                        );

                        $lastRequestDiagnostics = fs_connector_request_diagnostics($requestPayload, [
                            'source_cms_server_epoch' => $sourceCmsServerEpoch,
                            'timestamp_format_used' => fs_connector_timestamp_format(),
                        ]);
                        $diagnosticsJson = json_encode($lastRequestDiagnostics, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                        safe_set_option('fs_conn_last_request_payload', $diagnosticsJson);
                        $postData = json_encode($requestPayload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    } catch (Throwable $e) {
                        $postData = '';
                        $errorMsg = 'The connection request payload could not be prepared safely.';
                    }

                    if ($errorMsg === '') {
                        $ch = curl_init($filesUrl . '/api/apps/connect/request');
                        fs_connector_apply_curl_security($ch);
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
                        curl_setopt($ch, CURLOPT_HEADER, true);
                        $rawResp = curl_exec($ch);
                        $curlErrorNumber = curl_errno($ch);
                        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                        curl_close($ch);

                        if ($rawResp === false) {
                            $errorMsg = fs_connector_safe_curl_error($curlErrorNumber);
                            $lastRequestDiagnostics['files_service_response'] = 'cURL error: ' . $errorMsg;
                            $diagnosticsJson = json_encode($lastRequestDiagnostics, JSON_UNESCAPED_SLASHES);
                            if ($diagnosticsJson !== false) {
                                safe_set_option('fs_conn_last_request_payload', $diagnosticsJson);
                            }
                        } else {
                            $responseHeaders = $headerSize > 0 ? substr($rawResp, 0, $headerSize) : '';
                            $resp = $headerSize > 0 ? substr($rawResp, $headerSize) : $rawResp;
                            $safeResponse = fs_connector_safe_remote_response($resp);
                            $lastResponse = "HTTP Code: {$httpCode}\nResponse:\n{$safeResponse}";
                            $lastRequestDiagnostics['files_service_response'] = $lastResponse;

                            $filesServiceDate = fs_connector_get_http_header($responseHeaders, 'date');
                            $filesServiceEpoch = $filesServiceDate !== '' ? strtotime($filesServiceDate) : false;
                            if ($filesServiceEpoch !== false) {
                                $lastRequestDiagnostics['files_service_utc'] = fs_connector_utc_datetime((int) $filesServiceEpoch);
                                $sentEpoch = fs_connector_parse_timestamp_epoch($requestPayload['timestamp'] ?? '');
                                if ($sentEpoch !== null) {
                                    $lastRequestDiagnostics['files_service_timestamp_skew_seconds'] = (string) ((int) $filesServiceEpoch - $sentEpoch);
                                }
                            }

                            $diagnosticsJson = json_encode($lastRequestDiagnostics, JSON_UNESCAPED_SLASHES);
                            if ($diagnosticsJson !== false) {
                                safe_set_option('fs_conn_last_request_payload', $diagnosticsJson);
                            }

                            $decoded = json_decode($resp, true);
                            $requestSucceeded = in_array($httpCode, [200, 201, 202], true)
                                && is_array($decoded)
                                && ($decoded['success'] ?? false) === true;

                            if ($requestSucceeded) {
                                $status = 'pending_approval';
                                safe_set_option('fsc_status', $status);
                                soi_flash('success', 'Connection requested successfully. Pending Files Service approval.');
                            } elseif ($httpCode === 400) {
                                $timestampHint = stripos($safeResponse, 'timestamp') !== false
                                    ? "\nCheck timestamp. It must be fresh source-CMS server Unix epoch seconds within the Files Service clock-skew window."
                                    : '';
                                $errorMsg = "Connection request failed. HTTP Code: 400.\nResponse:\n{$safeResponse}\nCheck source_domain. It must be hostname only, for example " . ($sourceDomain !== '' ? $sourceDomain : 'example.yourdomain.tld') . ".{$timestampHint}";
                            } else {
                                $errorMsg = "Connection request failed. HTTP Code: {$httpCode}.\nResponse:\n{$safeResponse}";
                            }
                        }
                    }
                }
            }
        }
    } elseif ($action === 'save_credentials') {
        $identityForSave = function_exists('fs_connector_installed_identity')
            ? fs_connector_installed_identity()
            : $siteIdentity;
        $credentialResult = fs_connector_save_credentials(
            (string) ($_POST['client_id'] ?? ''),
            (string) ($_POST['client_secret'] ?? ''),
            (string) ($identityForSave['requested_app_id'] ?? $identityForSave['site_slug'] ?? '')
        );
        if ($credentialResult['success'] ?? false) {
            $status = 'connected';
            $msg = 'Files Service credentials saved encrypted and bound to app_id "'
                . (string) ($credentialResult['app_id'] ?? '')
                . '". Raw secret was not stored.';
            if (!empty($credentialResult['warning'])) {
                $msg .= ' Warning: ' . (string) $credentialResult['warning'];
            }
            soi_flash('success', $msg);
        } else {
            $errorMsg = (string) ($credentialResult['error'] ?? 'Credentials could not be saved.');
        }
    } elseif ($action === 'repair_identity') {
        if (!function_exists('fs_connector_repair_identity')) {
            $errorMsg = 'Repair Files Service Identity is not available in this build.';
        } else {
            $repairResult = fs_connector_repair_identity();
            $siteIdentity = function_exists('fs_connector_installed_identity')
                ? fs_connector_installed_identity()
                : $siteIdentity;
            $sourceDomain = fs_connector_normalize_hostname((string) ($siteIdentity['source_domain'] ?? $sourceDomain));
            $msg = (string) ($repairResult['message'] ?? 'Identity repaired.');
            if (!empty($repairResult['warnings']) && is_array($repairResult['warnings'])) {
                $msg .= ' ' . implode(' ', array_map('strval', $repairResult['warnings']));
            }
            if (!empty($repairResult['warnings'])) {
                soi_flash('warning', $msg);
            } else {
                soi_flash('success', $msg);
            }
        }
    } elseif ($action === 'save_offload_settings') {
        fs_connector_save_settings($_POST);
        soi_flash('success', 'Media offload settings saved. Local copies remain enabled.');
    } elseif ($action === 'migration_dry_run') {
        $migrationPlan = FileServiceMediaAdapter::collectMediaPlan(250);
        fs_connector_record_migration_result('dry_run', [
            'success' => true,
            'processed' => (int) ($migrationPlan['stats']['total'] ?? 0),
            'changed' => 0,
        ]);
    } elseif ($action === 'migration_batch') {
        $migrationResult = FileServiceMediaAdapter::migrateBatch((int) ($_POST['batch_size'] ?? fs_connector_get_settings()['migration_batch_size']));
        fs_connector_record_migration_result('upload_map_batch', $migrationResult);
    } elseif ($action === 'reference_preview') {
        FileServiceMediaAdapter::stabilizeExistingMappings();
        $referenceResult = FileServiceMediaAdapter::referencePreview(100);
        fs_connector_record_migration_result('rewrite_preview', $referenceResult);
        if (!($referenceResult['success'] ?? false)) {
            $errorMsg = (string) ($referenceResult['error'] ?? 'Reference preview failed.');
        } elseif (!empty($referenceResult['has_unsafe'])) {
            $errorMsg = 'Preview completed but contains unsafe candidates. Commit is blocked until only safe replacements remain.';
        }
    } elseif ($action === 'reference_commit') {
        $settingsForCommit = fs_connector_get_settings();
        if (empty($settingsForCommit['rewrite_content_urls_enabled'])) {
            $errorMsg = 'Content URL rewrite is disabled. Enable “Rewrite content URLs after preview” in Media Offload Settings before committing replacements.';
        } else {
            $previewStatus = FileServiceMediaAdapter::previewStatus();
            if (empty($previewStatus['exists'])) {
                $errorMsg = 'Commit blocked: no valid rewrite preview exists. Run Step 3 (Preview Reference Replacements) first.';
            } elseif (!empty($previewStatus['stale'])) {
                $errorMsg = 'Commit blocked: preview is stale. ' . (string) ($previewStatus['stale_reason'] ?? 'Re-run preview.');
            } elseif (!empty($previewStatus['has_unsafe'])) {
                $errorMsg = 'Commit blocked: stored preview contains unsafe replacements.';
            } else {
                FileServiceMediaAdapter::stabilizeExistingMappings();
                $referenceResult = FileServiceMediaAdapter::commitReferenceReplacements(100);
                fs_connector_record_migration_result('rewrite_commit', $referenceResult);
                if (!($referenceResult['success'] ?? false)) {
                    $errorMsg = (string) ($referenceResult['error'] ?? 'Commit failed.');
                } elseif (($referenceResult['success'] ?? false) && (int) ($referenceResult['changed'] ?? 0) > 0 && class_exists(\SOI\Core\Cache::class)) {
                    \SOI\Core\Cache::purgeAll();
                }
            }
        }
    } elseif ($action === 'frontend_verify') {
        $verifyResult = FileServiceMediaAdapter::verifyFrontendReferences();
        fs_connector_record_migration_result('frontend_verify', [
            'success' => (bool) ($verifyResult['success'] ?? false),
            'processed' => (int) ($verifyResult['total_media_references'] ?? 0),
            'changed' => 0,
            'replacement_count' => (int) ($verifyResult['files_service_media_urls'] ?? 0),
        ]);
    } elseif ($action === 'lifecycle_audit') {
        $lifecycleAudit = FileServiceMediaAdapter::runLifecycleAudit(500);
        fs_connector_record_migration_result('lifecycle_audit', [
            'success' => (bool) ($lifecycleAudit['success'] ?? false),
            'processed' => (int) ($lifecycleAudit['local_media_total'] ?? 0),
            'failed' => (int) ($lifecycleAudit['failed_sync'] ?? 0),
            'changed' => (int) ($lifecycleAudit['orphaned_mapping'] ?? 0),
        ]);
    } elseif ($action === 'lifecycle_repair_urls') {
        $lifecycleRepair = FileServiceMediaAdapter::repairNonCanonicalMediaUrls();
        fs_connector_record_migration_result('lifecycle_repair_urls', $lifecycleRepair);
        if (!($lifecycleRepair['success'] ?? false)) {
            $errorMsg = (string) ($lifecycleRepair['error'] ?? 'Repair failed.');
        }
    } elseif ($action === 'domain_readiness_scan') {
        $domainReadiness = fs_connector_domain_readiness_scan();
        fs_connector_record_migration_result('domain_readiness_scan', [
            'success' => (bool) ($domainReadiness['success'] ?? false),
            'processed' => (int) ($domainReadiness['hardcode_search_count'] ?? 0),
            'changed' => 0,
        ]);
    } elseif ($action === 'reference_rollback') {
        $rollbackResult = FileServiceMediaAdapter::rollbackBatch((string) ($_POST['batch_token'] ?? ''));
        fs_connector_record_migration_result('rewrite_rollback', $rollbackResult);
        if (!($rollbackResult['success'] ?? false)) {
            $errorMsg = (string) ($rollbackResult['error'] ?? 'Rollback failed.');
        } elseif (($rollbackResult['success'] ?? false) && class_exists(\SOI\Core\Cache::class)) {
            \SOI\Core\Cache::purgeAll();
        }
    } elseif ($action === 'reset') {
        $status = 'not_connected';
        safe_set_option('fsc_status', $status);
        safe_set_option('fsc_files_url', FS_CONNECTOR_FILES_URL);
        soi_flash('success', 'Connection reset. Saved encrypted credentials were not deleted.');
        soi_redirect(SOI_ADMIN_URL . '/files-service-connector.php');
    }
}

$stabilizeResult = FileServiceMediaAdapter::stabilizeExistingMappings();
$settings = fs_connector_get_settings();
$encryptionStatus = fs_connector_encryption_status();
$readiness = FileServiceMediaAdapter::readiness();
$latestBackupBatch = FileServiceMediaAdapter::latestBackupBatch();
$backupInfo = FileServiceMediaAdapter::latestBackupBatchInfo();
$previewStatus = FileServiceMediaAdapter::previewStatus();
$workflow = FileServiceMediaAdapter::workflowStepStatus();
$mapTableExists = FileServiceMediaAdapter::mapTableExists();
$backupTableExists = FileServiceMediaAdapter::backupTableExists();
$commitEnabled = !empty($settings['rewrite_content_urls_enabled'])
    && !empty($previewStatus['exists'])
    && empty($previewStatus['stale'])
    && empty($previewStatus['has_unsafe'])
    && (int) ($previewStatus['replacement_count'] ?? 0) >= 0
    && $backupTableExists;
$remoteLifecycle = FileServiceMediaAdapter::remoteLifecycleCapabilities();
$lastLifecycleAuditRaw = (string) ($settings['last_lifecycle_audit'] ?? '');
$lastLifecycleAudit = $lastLifecycleAuditRaw !== '' ? json_decode($lastLifecycleAuditRaw, true) : null;
if (!is_array($lastLifecycleAudit)) {
    $lastLifecycleAudit = [];
}
$identityDiagnostics = function_exists('fs_connector_identity_diagnostics')
    ? fs_connector_identity_diagnostics()
    : [];
$siteIdentity = function_exists('fs_connector_installed_identity')
    ? fs_connector_installed_identity()
    : $siteIdentity;
$multiActiveWarning = ((int) ($identityDiagnostics['files_service']['active_credential_count'] ?? 0) > 1);

require_once __DIR__ . '/partials/header.php';
?>

<div style="display:flex;flex-direction:column;gap:1.5rem;">

    <?php if ($errorMsg): ?>
    <div class="card" style="border-left:4px solid #dc3545; background:#fff5f5; color:#9f1239;">
        <div class="card-body" style="white-space:pre-wrap;"><strong>Error:</strong> <?= esc($errorMsg) ?></div>
    </div>
    <?php endif; ?>

    <?php if ($multiActiveWarning): ?>
    <div class="card" style="border-left:4px solid #d97706; background:#fffbeb; color:#92400e;">
        <div class="card-body">
            <strong>Warning:</strong> Multiple active Files Service credentials are recorded locally.
            Uploads use only the currently saved Client ID. Revoked entries are ignored.
            Use explicit reconnect/reset if identity still mismatches.
        </div>
    </div>
    <?php endif; ?>

    <?php if ($lastResponse): ?>
    <div class="card" style="border-left:4px solid #0d6efd; background:#f0f7ff;">
        <div class="card-body">
            <strong>Last Safe Response</strong>
            <pre style="white-space:pre-wrap;overflow-wrap:anywhere;margin:0.75rem 0 0;"><?= esc($lastResponse) ?></pre>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><h3 class="card-title">Connector Status</h3></div>
        <div class="card-body">
            <div class="dashboard-grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;">
                <div><div class="text-muted">Connection Status</div><strong><?= esc(ucwords(str_replace('_', ' ', $status))) ?></strong></div>
                <div><div class="text-muted">Connector Version</div><strong><?= esc(FS_CONNECTOR_VERSION) ?></strong></div>
                <div><div class="text-muted">Client ID</div><strong><?= $settings['client_id_present'] ? 'Present' : 'Missing' ?></strong></div>
                <div><div class="text-muted">Client Secret</div><strong><?= $settings['client_secret_configured'] ? 'Encrypted' : 'Not configured' ?></strong></div>
                <div><div class="text-muted">Upload App ID</div><strong><?= esc((string) ($identityDiagnostics['upload']['app_id_sent'] ?? ($siteIdentity['requested_app_id'] ?? '—'))) ?></strong></div>
                <div><div class="text-muted">Media Map Table</div><strong><?= $mapTableExists ? 'Ready' : 'Missing' ?></strong></div>
                <div><div class="text-muted">Encryption</div><strong><?= $encryptionStatus['ready'] ? 'Ready' : 'Not ready' ?></strong></div>
                <div><div class="text-muted">Canonical Media URL</div><strong>files.soi.co.in/media/{file_id}</strong></div>
                <div><div class="text-muted">Rollback</div><strong><?= $settings['rollback_available'] ? 'Available' : 'No batch yet' ?></strong></div>
            </div>
            <?php if (($stabilizeResult['updated'] ?? 0) > 0): ?>
            <p class="text-muted" style="margin:1rem 0 0;">Mapping stabilization updated <?= (int) $stabilizeResult['updated'] ?> existing row(s) to canonical media URLs.</p>
            <?php endif; ?>
            <?php if (!$readiness['ready']): ?>
            <p class="text-muted" style="margin:1rem 0 0;">Offload readiness: <?= esc(implode(', ', $readiness['reasons'])) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card" style="border-left:4px solid #4f46e5;">
        <div class="card-header"><h3 class="card-title">Files Service Identity Diagnostics (v1.0.4)</h3></div>
        <div class="card-body">
            <p class="text-muted" style="margin-top:0;">Redacted identity only. Client secret and encrypted credentials are never shown.</p>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1.25rem;">
                <div>
                    <h4 style="margin:0 0 0.5rem;">Installed CMS identity</h4>
                    <ul style="margin:0;padding-left:1.1rem;line-height:1.6;">
                        <li>Site name: <strong><?= esc((string) ($identityDiagnostics['installed']['site_name'] ?? '—')) ?></strong></li>
                        <li>Site slug: <strong><?= esc((string) ($identityDiagnostics['installed']['site_slug'] ?? '—')) ?></strong></li>
                        <li>Site domain: <strong><?= esc((string) ($identityDiagnostics['installed']['site_domain'] ?? '—')) ?></strong></li>
                        <li>Canonical URL: <strong style="overflow-wrap:anywhere;"><?= esc((string) ($identityDiagnostics['installed']['canonical_url'] ?? '—')) ?></strong></li>
                    </ul>
                </div>
                <div>
                    <h4 style="margin:0 0 0.5rem;">Files Service identity</h4>
                    <ul style="margin:0;padding-left:1.1rem;line-height:1.6;">
                        <li>source_domain: <strong><?= esc((string) ($identityDiagnostics['files_service']['source_domain'] ?? '—')) ?></strong></li>
                        <li>requested_app_id: <strong><?= esc((string) ($identityDiagnostics['files_service']['requested_app_id'] ?? '—')) ?></strong></li>
                        <li>requested_app_name: <strong><?= esc((string) ($identityDiagnostics['files_service']['requested_app_name'] ?? '—')) ?></strong></li>
                        <li>connection status: <strong><?= esc((string) ($identityDiagnostics['files_service']['connection_status'] ?? $status)) ?></strong></li>
                        <li>client_id prefix: <strong><?= esc((string) ($identityDiagnostics['files_service']['client_id_prefix'] ?? '—')) ?></strong></li>
                        <li>credential app_id: <strong><?= esc((string) ($identityDiagnostics['files_service']['credential_app_id'] ?? '—')) ?></strong></li>
                        <li>active credentials: <strong><?= (int) ($identityDiagnostics['files_service']['active_credential_count'] ?? 0) ?></strong></li>
                        <li>revoked credentials: <strong><?= (int) ($identityDiagnostics['files_service']['revoked_credential_count'] ?? 0) ?></strong></li>
                    </ul>
                </div>
                <div>
                    <h4 style="margin:0 0 0.5rem;">Upload identity</h4>
                    <ul style="margin:0;padding-left:1.1rem;line-height:1.6;">
                        <li>endpoint: <strong style="overflow-wrap:anywhere;"><?= esc((string) ($identityDiagnostics['upload']['upload_endpoint'] ?? '—')) ?></strong></li>
                        <li>source_domain sent: <strong><?= esc((string) ($identityDiagnostics['upload']['source_domain_sent'] ?? '—')) ?></strong></li>
                        <li>app_id / source_app_id: <strong><?= esc((string) ($identityDiagnostics['upload']['app_id_sent'] ?? '—')) ?></strong></li>
                        <li>requested_app_id: <strong><?= esc((string) ($identityDiagnostics['upload']['requested_app_id'] ?? '—')) ?></strong></li>
                        <li>client_id prefix sent: <strong><?= esc((string) ($identityDiagnostics['upload']['client_id_prefix_sent'] ?? '—')) ?></strong></li>
                        <li>last upload status: <strong><?= esc((string) ($identityDiagnostics['upload']['last_upload_status'] ?? '—') ?: '—') ?></strong></li>
                        <li>last upload error: <strong style="overflow-wrap:anywhere;"><?= esc((string) ($identityDiagnostics['upload']['last_upload_error'] ?? '—') ?: '—') ?></strong></li>
                    </ul>
                </div>
            </div>
            <?php if (empty($identityDiagnostics['identity_match']['credential_app_matches_requested'])): ?>
            <p style="margin:1rem 0 0;color:#9f1239;">
                Credential app_id does not match installed requested_app_id. Run repair, then reconnect if uploads still return app ID mismatch.
            </p>
            <?php endif; ?>
            <form method="POST" style="margin-top:1rem;">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="_action" value="repair_identity">
                <button type="submit" class="btn btn-primary">Repair Files Service Identity</button>
                <small class="text-muted" style="margin-left:0.75rem;">Recomputes source_domain / requested_app_id / requested_app_name from installed site identity. Does not delete credentials, mappings, or media.</small>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3 class="card-title">Files Service Configuration</h3></div>
        <div class="card-body">
            <form method="POST">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="_action" value="test_discovery">
                <div class="form-group" style="margin-bottom:1rem;">
                    <label class="form-label">Files Service URL</label>
                    <input class="form-input" type="url" name="files_url" value="<?= esc($filesUrl) ?>" readonly required>
                    <small class="text-muted">Fixed for this connector phase. Do not point this at the source CMS.</small>
                </div>
                <button type="submit" class="btn btn-primary">Test Discovery</button>
            </form>

            <?php if ($status === 'discovery_verified'): ?>
            <hr style="border:none;border-top:1px solid var(--border);margin:1.25rem 0;">
            <form method="POST">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="_action" value="request_connection">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;">
                    <div class="form-group">
                        <label class="form-label">Source Site Name</label>
                        <input class="form-input" type="text" name="site_name" value="<?= esc((string) ($siteIdentity['files_service_app_name'] ?? $siteIdentity['site_name'] ?? 'SOI CMS')) ?>">
                        <small class="text-muted">Derived from site name. Each CMS domain should use its own app display name.</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Source Domain</label>
                        <input class="form-input" type="text" name="domain" value="<?= esc($sourceDomain) ?>" required>
                        <small class="text-muted">Hostname only (no https://). Example: <?= esc($sourceDomain !== '' ? $sourceDomain : 'your-site.example') ?></small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Requested App Slug</label>
                        <input class="form-input" type="text" name="app_slug_display" value="<?= esc((string) ($siteIdentity['files_service_app_slug'] ?? $siteIdentity['site_slug'] ?? 'cms')) ?>" readonly>
                        <small class="text-muted">Sent as requested_app_id from site slug. Configure site_slug under Settings. Do not reuse another site’s Client Secret.</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Requested Entity Slug</label>
                        <input class="form-input" type="text" name="entity_slug" value="soi" required>
                    </div>
                </div>
                <div class="form-group" style="margin:1rem 0;">
                    <label class="form-label">Requested Permissions</label>
                    <div style="display:flex;gap:1rem;flex-wrap:wrap;margin-top:0.5rem;">
                        <label><input type="checkbox" name="permissions[]" value="upload" checked> Upload</label>
                        <label><input type="checkbox" name="permissions[]" value="download" checked> Download</label>
                        <label><input type="checkbox" name="permissions[]" value="signed_links" checked> Signed links</label>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Request Connection</button>
            </form>
            <?php elseif ($status === 'pending_approval'): ?>
            <p class="text-muted" style="margin-top:1rem;">Connection request is pending approval. Do not mark Connected until the approved Client ID and Client Secret are saved below.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3 class="card-title">Credential Setup</h3></div>
        <div class="card-body">
            <?php if (!$encryptionStatus['ready']): ?>
            <div class="alert alert-warning">
                Credential save is blocked until connector encryption is ready. Required: server-side <code>SOI_SECRET_KEY</code> and OpenSSL AES-256-GCM support.
            </div>
            <?php endif; ?>
            <form method="POST" autocomplete="off">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="_action" value="save_credentials">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem;">
                    <div class="form-group">
                        <label class="form-label">Client ID</label>
                        <input class="form-input" type="text" name="client_id" value="<?= esc((string) safe_get_option('fs_conn_client_id', '')) ?>" autocomplete="off" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Client Secret</label>
                        <input class="form-input" type="password" name="client_secret" value="" autocomplete="new-password" <?= $encryptionStatus['ready'] ? 'required' : 'disabled' ?>>
                        <small class="text-muted">Secret is encrypted before DB storage and never displayed after save.</small>
                    </div>
                </div>
                <p class="text-muted">Credentials are bound to app_id <code><?= esc((string) ($siteIdentity['requested_app_id'] ?? $siteIdentity['site_slug'] ?? 'cms')) ?></code> and source_domain <code><?= esc((string) ($siteIdentity['source_domain'] ?? $sourceDomain)) ?></code> from this installation. Do not paste credentials issued for another site or product.</p>
                <button type="submit" class="btn btn-primary" <?= $encryptionStatus['ready'] ? '' : 'disabled' ?>>Save Encrypted Credentials</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3 class="card-title">Media Offload Settings</h3></div>
        <div class="card-body">
            <form method="POST">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="_action" value="save_offload_settings">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem;">
                    <label class="form-switch-wrap"><input type="checkbox" name="connector_enabled" value="1" <?= $settings['connector_enabled'] ? 'checked' : '' ?>><span class="form-switch-label">Connector enabled</span></label>
                    <label class="form-switch-wrap"><input type="checkbox" name="media_offload_enabled" value="1" <?= $settings['media_offload_enabled'] ? 'checked' : '' ?>><span class="form-switch-label">Media offload enabled</span></label>
                    <label class="form-switch-wrap"><input type="checkbox" name="offload_new_uploads_enabled" value="1" <?= $settings['offload_new_uploads_enabled'] ? 'checked' : '' ?>><span class="form-switch-label">Offload new Media Center uploads</span></label>
                    <label class="form-switch-wrap"><input type="checkbox" name="migration_enabled" value="1" <?= $settings['migration_enabled'] ? 'checked' : '' ?>><span class="form-switch-label">Enable migration tooling</span></label>
                    <label class="form-switch-wrap"><input type="checkbox" name="rewrite_content_urls_enabled" value="1" <?= $settings['rewrite_content_urls_enabled'] ? 'checked' : '' ?>><span class="form-switch-label">Rewrite content URLs after preview</span></label>
                    <label class="form-switch-wrap"><input type="checkbox" name="keep_local_copy" value="1" checked disabled><span class="form-switch-label">Keep local copy (forced in this phase)</span></label>
                    <div class="form-group">
                        <label class="form-label">Delivery Mode</label>
                        <select class="form-select" name="delivery_mode">
                            <?php foreach (fs_connector_delivery_modes() as $mode): ?>
                            <option value="<?= esc($mode) ?>" <?= $settings['delivery_mode'] === $mode ? 'selected' : '' ?>><?= esc(str_replace('_', ' ', ucwords($mode, '_'))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Migration Batch Size</label>
                        <input class="form-input" type="number" min="1" max="50" name="migration_batch_size" value="<?= esc((string) $settings['migration_batch_size']) ?>">
                    </div>
                </div>
                <p class="text-muted">Hybrid mode keeps local originals available and only uses Files Service URLs after a confirmed upload/mapping.</p>
                <div class="form-group" style="margin:1rem 0;">
                    <label class="form-label">Remote lifecycle behavior</label>
                    <select class="form-select" name="remote_lifecycle_mode" disabled>
                        <option value="retain_remote" selected>Retain remote (default — only supported mode)</option>
                        <option value="archive_remote_if_supported" disabled>Archive remote if supported (unavailable)</option>
                        <option value="delete_remote_if_supported" disabled>Delete remote if supported (dangerous — unavailable)</option>
                    </select>
                    <small class="text-muted"><?= esc((string) ($remoteLifecycle['note'] ?? 'Remote hard-delete is disabled.')) ?></small>
                </div>
                <p class="text-muted">Last migration run: <?= esc($settings['last_migration_run'] ?: 'Never') ?><?= $settings['last_migration_result'] !== '' ? ' · Last result saved' : '' ?></p>
                <button type="submit" class="btn btn-primary">Save Settings</button>
            </form>
        </div>
    </div>

    <div class="card" style="border-left:4px solid #0f766e;">
        <div class="card-header"><h3 class="card-title">CMS Production Readiness (v1.2.11)</h3></div>
        <div class="card-body">
            <p class="text-muted" style="margin-top:0;">Final go/no-go audit for using this CMS as a base for other websites. Full report:</p>
            <p>
                <a class="btn btn-primary" href="<?= esc(SOI_ADMIN_URL . '/cms-readiness.php') ?>">Open CMS Production Readiness</a>
                <span class="text-muted" style="margin-left:0.75rem;">Last: <?= esc((string) safe_get_option('cms_last_production_readiness_at', 'Never')) ?></span>
            </p>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3 class="card-title">Reusable CMS / Domain Packaging Readiness (v1.2.10)</h3></div>
        <div class="card-body">
            <p class="text-muted" style="margin-top:0;">Verify this CMS can be reused on other domains without sample-site hardcoding. Does not reset credentials, rewrite content, or delete media.</p>
            <div class="dashboard-grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:0.75rem;margin-bottom:1rem;">
                <div><div class="text-muted">Detected host</div><strong><?= esc((string) ($siteIdentity['detected_host'] ?? '—')) ?></strong></div>
                <div><div class="text-muted">Configured domain</div><strong><?= esc((string) ($siteIdentity['site_domain'] ?? '—')) ?></strong></div>
                <div><div class="text-muted">Site name</div><strong><?= esc((string) ($siteIdentity['site_name'] ?? '—')) ?></strong></div>
                <div><div class="text-muted">Site slug / app id</div><strong><?= esc((string) ($siteIdentity['files_service_app_slug'] ?? $siteIdentity['site_slug'] ?? '—')) ?></strong></div>
                <div><div class="text-muted">FS source domain</div><strong><?= esc((string) ($siteIdentity['source_domain'] ?? '—')) ?></strong></div>
                <div><div class="text-muted">Canonical base URL</div><strong style="overflow-wrap:anywhere;"><?= esc((string) ($siteIdentity['canonical_base_url'] ?? '—')) ?></strong></div>
                <div><div class="text-muted">Environment</div><strong><?= esc((string) ($siteIdentity['environment_label'] ?? 'production')) ?></strong></div>
                <div><div class="text-muted">Host fallback</div><strong><?= !empty($siteIdentity['uses_host_fallback']) ? 'Yes (configure site_url)' : 'No' ?></strong></div>
            </div>
            <form method="POST" style="margin-bottom:1rem;"><?= Auth::csrfField() ?><input type="hidden" name="_action" value="domain_readiness_scan"><button class="btn btn-primary" type="submit">Run Readiness Scan</button></form>
            <p class="text-muted">Configure site_name, site_domain, site_slug, and site_url under <a href="<?= esc(SOI_ADMIN_URL . '/settings.php') ?>">General Settings</a>. Files Service media delivery host remains external: <code>files.soi.co.in</code>.</p>
            <?php if ($domainReadiness): ?>
            <h4>Readiness scan result</h4>
            <p class="page-toolbar-meta">
                Ready for clone: <strong><?= !empty($domainReadiness['ready_for_clone']) ? 'Yes' : 'Not yet' ?></strong>
                · Hardcode hits: <?= (int) ($domainReadiness['hardcode_search_count'] ?? 0) ?>
                · Credentials preserved: <?= !empty($domainReadiness['connector']['credentials_configured']) ? 'Yes (not reset)' : 'Not configured' ?>
            </p>
            <ul style="margin:0 0 1rem;padding-left:1.25rem;">
                <?php foreach (($domainReadiness['warnings'] ?? []) as $w): ?>
                <li><?= esc((string) $w) ?></li>
                <?php endforeach; ?>
            </ul>
            <?php if (!empty($domainReadiness['media_rewrite']['allowed_patterns'])): ?>
            <p class="text-muted">Dynamic rewrite source patterns:</p>
            <ul style="margin:0 0 1rem;padding-left:1.25rem;">
                <?php foreach ($domainReadiness['media_rewrite']['allowed_patterns'] as $pat): ?>
                <li><code><?= esc((string) $pat) ?></code></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <?php if (!empty($domainReadiness['hardcode_hits'])): ?>
            <div class="table-wrap">
                <table class="table--dense">
                    <thead><tr><th>File</th><th>Line</th><th>Value</th><th>Risk</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($domainReadiness['hardcode_hits'], 0, 40) as $hit): ?>
                        <tr>
                            <td><?= esc((string) ($hit['file'] ?? '')) ?></td>
                            <td><?= (int) ($hit['line'] ?? 0) ?></td>
                            <td><?= esc((string) ($hit['value'] ?? '')) ?></td>
                            <td><?= esc((string) ($hit['risk'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3 class="card-title">File Lifecycle Sync (v1.2.9+)</h3></div>
        <div class="card-body">
            <p class="text-muted" style="margin-top:0;">Audit local media vs Files Service mappings. Lifecycle actions never hard-delete remote Files Service files and never rewrite content automatically.</p>
            <div class="dashboard-grid" style="grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:0.75rem;margin-bottom:1rem;">
                <div><div class="text-muted">Remote mapped</div><strong><?= (int) ($lastLifecycleAudit['remote_mapped'] ?? ($lifecycleAudit['remote_mapped'] ?? 0)) ?></strong></div>
                <div><div class="text-muted">Local only</div><strong><?= (int) ($lastLifecycleAudit['local_only'] ?? ($lifecycleAudit['local_only'] ?? 0)) ?></strong></div>
                <div><div class="text-muted">Failed sync</div><strong><?= (int) ($lastLifecycleAudit['failed_sync'] ?? ($lifecycleAudit['failed_sync'] ?? 0)) ?></strong></div>
                <div><div class="text-muted">Orphaned maps</div><strong><?= (int) ($lastLifecycleAudit['orphaned_mapping'] ?? ($lifecycleAudit['orphaned_mapping'] ?? 0)) ?></strong></div>
                <div><div class="text-muted">CMS deleted maps</div><strong><?= (int) ($lastLifecycleAudit['cms_deleted'] ?? ($lifecycleAudit['cms_deleted'] ?? 0)) ?></strong></div>
                <div><div class="text-muted">Duplicate active</div><strong><?= (int) ($lastLifecycleAudit['duplicate_active'] ?? ($lifecycleAudit['duplicate_active'] ?? 0)) ?></strong></div>
                <div><div class="text-muted">Non-canonical URL</div><strong><?= (int) ($lastLifecycleAudit['non_canonical_media_url'] ?? ($lifecycleAudit['non_canonical_media_url'] ?? 0)) ?></strong></div>
                <div><div class="text-muted">Legacy /files/view</div><strong><?= (int) ($lastLifecycleAudit['legacy_files_view'] ?? ($lifecycleAudit['legacy_files_view'] ?? 0)) ?></strong></div>
            </div>
            <p class="text-muted">Last audit: <?= esc((string) ($settings['last_lifecycle_audit_at'] ?: 'Never')) ?></p>
            <div style="display:flex;gap:0.75rem;flex-wrap:wrap;margin-bottom:1rem;">
                <form method="POST"><?= Auth::csrfField() ?><input type="hidden" name="_action" value="lifecycle_audit"><button class="btn btn-primary" type="submit">Run Lifecycle Audit</button></form>
                <form method="POST"><?= Auth::csrfField() ?><input type="hidden" name="_action" value="lifecycle_repair_urls"><button class="btn btn-secondary" type="submit" data-confirm="Repair stored mapping media_url values to canonical https://files.soi.co.in/media/{file_id}? Content will not be rewritten.">Repair Non-Canonical URLs</button></form>
                <form method="POST"><?= Auth::csrfField() ?><input type="hidden" name="_action" value="frontend_verify"><button class="btn btn-secondary" type="submit">Verify Frontend References</button></form>
                <a class="btn btn-ghost" href="<?= esc(SOI_ADMIN_URL . '/media.php') ?>">Open Media Library</a>
            </div>
            <div class="alert alert-warning">
                Remote cleanup unsupported by current Files Service API. Mode: <strong>retain_remote</strong>. Archive/delete remote options stay disabled until Files Service exposes those endpoints.
            </div>
            <?php if ($lifecycleRepair): ?>
            <h4>Repair result</h4>
            <p class="page-toolbar-meta"><?= esc((string) ($lifecycleRepair['message'] ?? '')) ?> · Updated <?= (int) ($lifecycleRepair['updated'] ?? 0) ?></p>
            <?php endif; ?>
            <?php if ($lifecycleAudit): ?>
            <h4>Audit result</h4>
            <ul style="margin:0 0 1rem;padding-left:1.25rem;">
                <?php foreach (($lifecycleAudit['recommendations'] ?? []) as $rec): ?>
                <li><?= esc((string) $rec) ?></li>
                <?php endforeach; ?>
            </ul>
            <?php if (!empty($lifecycleAudit['items'])): ?>
            <div class="table-wrap">
                <table class="table--dense">
                    <thead><tr><th>Type</th><th>Media/Map</th><th>Status</th><th>Recommended action</th><th>Detail</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($lifecycleAudit['items'], 0, 50) as $item): ?>
                        <tr>
                            <td><?= esc((string) ($item['type'] ?? '')) ?></td>
                            <td><?= esc((string) ($item['media_id'] ?? $item['map_id'] ?? '')) ?> <?= esc((string) ($item['name'] ?? '')) ?></td>
                            <td><?= esc((string) ($item['status'] ?? '')) ?></td>
                            <td><?= esc((string) ($item['action'] ?? '')) ?></td>
                            <td style="overflow-wrap:anywhere;"><?= esc(substr((string) ($item['error'] ?? $item['file_id'] ?? ''), 0, 160)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <details class="help-disclosure help-disclosure--inline" style="margin-top:1rem;">
                <summary>Export / report summary (JSON)</summary>
                <pre class="migration-log" style="white-space:pre-wrap;"><?= esc(json_encode([
                    'timestamp' => $lifecycleAudit['timestamp'] ?? date('Y-m-d H:i:s'),
                    'local_media_total' => $lifecycleAudit['local_media_total'] ?? 0,
                    'local_only' => $lifecycleAudit['local_only'] ?? 0,
                    'remote_mapped' => $lifecycleAudit['remote_mapped'] ?? 0,
                    'failed_sync' => $lifecycleAudit['failed_sync'] ?? 0,
                    'orphaned_mapping' => $lifecycleAudit['orphaned_mapping'] ?? 0,
                    'duplicate_active' => $lifecycleAudit['duplicate_active'] ?? 0,
                    'cms_deleted' => $lifecycleAudit['cms_deleted'] ?? 0,
                    'non_canonical_media_url' => $lifecycleAudit['non_canonical_media_url'] ?? 0,
                    'legacy_files_view' => $lifecycleAudit['legacy_files_view'] ?? 0,
                    'content_refs_without_mapping' => $lifecycleAudit['content_refs_without_mapping'] ?? 0,
                    'remote_cleanup' => 'unsupported',
                    'recommendations' => $lifecycleAudit['recommendations'] ?? [],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}') ?></pre>
            </details>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3 class="card-title">Media Rewrite Workflow (v1.2.8.3+)</h3></div>
        <div class="card-body">
            <p class="text-muted" style="margin-top:0;">Safety-first rewrite workflow for this CMS domain (<?= esc((string) ($siteIdentity['site_domain'] ?? 'configured domain')) ?>). Nothing is rewritten until Step 4 is explicitly committed against a valid preview. Local files and Files Service mappings are never deleted by rewrite/rollback.</p>

            <?php if (!$mapTableExists || !$backupTableExists): ?>
            <div class="alert alert-warning">Migration database tables are not available yet. Install the base 1.2.8 package through Update Center so mapping/backup tables exist.</div>
            <?php endif; ?>

            <div class="table-wrap" style="margin-bottom:1.25rem;">
                <table class="table--dense">
                    <thead>
                        <tr>
                            <th>Step</th>
                            <th>Action</th>
                            <th>Status</th>
                            <th>Last run</th>
                            <th>Count / result</th>
                            <th>Warning</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $stepRows = [
                            ['key' => 'dry_run', 'label' => 'Step 1: Dry Run Media Mapping', 'danger' => false],
                            ['key' => 'upload_map', 'label' => 'Step 2: Upload + Map Media', 'danger' => false],
                            ['key' => 'preview', 'label' => 'Step 3: Preview Reference Replacements', 'danger' => false],
                            ['key' => 'commit', 'label' => 'Step 4: Commit Previewed Replacement Rules', 'danger' => true],
                            ['key' => 'verify', 'label' => 'Step 5: Verify Frontend References', 'danger' => false],
                            ['key' => 'rollback', 'label' => 'Step 6: Rollback Latest Batch', 'danger' => true],
                        ];
                        foreach ($stepRows as $stepRow):
                            $step = $workflow[$stepRow['key']] ?? [];
                        ?>
                        <tr>
                            <td><strong><?= esc($stepRow['label']) ?></strong></td>
                            <td class="text-muted"><?= esc((string) ($step['status'] ?? 'idle')) ?></td>
                            <td><?= esc(ucwords(str_replace('_', ' ', (string) ($step['status'] ?? 'idle')))) ?></td>
                            <td><?= esc((string) (($step['last_run'] ?? '') !== '' ? $step['last_run'] : '—')) ?></td>
                            <td><?= $step['count'] === null ? '—' : (int) $step['count'] ?></td>
                            <td><?= !empty($step['dangerous']) || $stepRow['danger'] ? '<span style="color:#b45309;font-weight:600;">Dangerous</span>' : '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0.75rem;margin-bottom:1.25rem;">
                <div class="card" style="margin:0;border:1px solid var(--border);">
                    <div class="card-body">
                        <strong>Step 1 · Dry Run</strong>
                        <p class="text-muted" style="margin:0.5rem 0;">Map readiness only. No uploads. No rewrites.</p>
                        <form method="POST"><?= Auth::csrfField() ?><input type="hidden" name="_action" value="migration_dry_run"><button class="btn btn-secondary" type="submit">Dry Run Media Mapping</button></form>
                    </div>
                </div>
                <div class="card" style="margin:0;border:1px solid var(--border);">
                    <div class="card-body">
                        <strong>Step 2 · Upload + Map</strong>
                        <p class="text-muted" style="margin:0.5rem 0;">Upload next batch to Files Service and store mappings. Keeps local copies.</p>
                        <form method="POST"><?= Auth::csrfField() ?><input type="hidden" name="_action" value="migration_batch"><input type="hidden" name="batch_size" value="<?= esc((string) $settings['migration_batch_size']) ?>"><button class="btn btn-primary" type="submit" <?= $readiness['ready'] && $settings['migration_enabled'] ? '' : 'disabled' ?>>Upload + Map Media</button></form>
                    </div>
                </div>
                <div class="card" style="margin:0;border:1px solid var(--border);">
                    <div class="card-body">
                        <strong>Step 3 · Preview</strong>
                        <p class="text-muted" style="margin:0.5rem 0;">Dry-run replacement candidates with skip reasons. Does not change content.</p>
                        <form method="POST"><?= Auth::csrfField() ?><input type="hidden" name="_action" value="reference_preview"><button class="btn btn-secondary" type="submit">Preview Reference Replacements</button></form>
                        <p class="text-muted" style="margin:0.5rem 0 0;font-size:0.85rem;">
                            Preview: <?= !empty($previewStatus['exists']) ? esc((string) $previewStatus['preview_token']) : 'none' ?>
                            · <?= !empty($previewStatus['exists']) ? ((int) $previewStatus['replacement_count'] . ' safe replacement(s)') : 'run preview first' ?>
                            <?= !empty($previewStatus['stale']) ? ' · STALE' : '' ?>
                        </p>
                    </div>
                </div>
                <div class="card" style="margin:0;border:1px solid #f59e0b;background:#fffbeb;">
                    <div class="card-body">
                        <strong style="color:#92400e;">Step 4 · Commit (DANGEROUS)</strong>
                        <p class="text-muted" style="margin:0.5rem 0;">Applies only the latest valid preview batch. Creates rollback backup first. Never runs automatically.</p>
                        <form method="POST"><?= Auth::csrfField() ?><input type="hidden" name="_action" value="reference_commit"><button class="btn btn-warning" type="submit" <?= $commitEnabled ? '' : 'disabled' ?> data-confirm="DANGEROUS: Commit only the latest valid previewed replacement rules? A rollback backup will be created first. Local files will not be deleted.">Commit Previewed Replacement Rules</button></form>
                        <?php if (!$commitEnabled): ?>
                        <p class="text-muted" style="margin:0.5rem 0 0;font-size:0.85rem;">
                            Commit blocked until:
                            <?= empty($settings['rewrite_content_urls_enabled']) ? ' rewrite setting enabled;' : '' ?>
                            <?= empty($previewStatus['exists']) ? ' valid preview exists;' : '' ?>
                            <?= !empty($previewStatus['stale']) ? ' preview is not stale;' : '' ?>
                            <?= !empty($previewStatus['has_unsafe']) ? ' preview has no unsafe rows;' : '' ?>
                            <?= !$backupTableExists ? ' backup table exists;' : '' ?>
                        </p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card" style="margin:0;border:1px solid var(--border);">
                    <div class="card-body">
                        <strong>Step 5 · Verify Frontend</strong>
                        <p class="text-muted" style="margin:0.5rem 0;">Scan pages/posts content for /media/{file_id} vs local/legacy references. Read-only.</p>
                        <form method="POST"><?= Auth::csrfField() ?><input type="hidden" name="_action" value="frontend_verify"><button class="btn btn-secondary" type="submit">Verify Frontend References</button></form>
                    </div>
                </div>
                <div class="card" style="margin:0;border:1px solid #dc3545;background:#fff5f5;">
                    <div class="card-body">
                        <strong style="color:#9f1239;">Step 6 · Rollback (DANGEROUS)</strong>
                        <p class="text-muted" style="margin:0.5rem 0;">Restores only the latest committed batch. Does not delete local files or Files Service mappings.</p>
                        <?php if (!empty($backupInfo['rollback_available']) && $latestBackupBatch !== ''): ?>
                        <div class="text-muted" style="font-size:0.85rem;margin-bottom:0.5rem;">
                            <div>Batch: <code><?= esc((string) $backupInfo['batch_token']) ?></code></div>
                            <div>Replacements: <?= (int) $backupInfo['replacement_count'] ?> · Fields: <?= (int) $backupInfo['field_count'] ?></div>
                            <div>Tables: <?= esc(implode(', ', $backupInfo['tables_affected'] ?: ['—'])) ?></div>
                            <div>Created: <?= esc((string) ($backupInfo['created_at'] ?: '—')) ?></div>
                            <div>Availability: <?= esc((string) $backupInfo['rollback_status']) ?></div>
                        </div>
                        <form method="POST"><?= Auth::csrfField() ?><input type="hidden" name="_action" value="reference_rollback"><input type="hidden" name="batch_token" value="<?= esc($latestBackupBatch) ?>"><button class="btn btn-danger" type="submit" data-confirm="DANGEROUS: Rollback only the latest committed replacement batch? Local files and Files Service mappings will not be deleted.">Rollback Latest Batch</button></form>
                        <?php else: ?>
                        <p class="text-muted" style="margin:0;">No committed rollback batch available yet.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($migrationPlan): ?>
            <h4>Step 1 Result · Dry-Run Summary</h4>
            <p class="page-toolbar-meta">
                Total <?= (int) $migrationPlan['stats']['total'] ?> · Ready <?= (int) $migrationPlan['stats']['mappable'] ?> · Mapped <?= (int) $migrationPlan['stats']['mapped'] ?> · Missing <?= (int) $migrationPlan['stats']['missing'] ?> · Size <?= esc(format_bytes((int) $migrationPlan['stats']['size_bytes'])) ?>
                · Content refs <?= (int) $migrationPlan['stats']['content_references_found'] ?> · Replaceable <?= (int) $migrationPlan['stats']['references_replaceable'] ?> · Unmapped <?= (int) $migrationPlan['stats']['references_unmapped'] ?>
            </p>
            <div class="table-wrap">
                <table class="table--dense">
                    <thead><tr><th>Name</th><th>Status</th><th>Storage</th><th>Size</th><th>File ID</th><th>Local URL</th><th>Media URL</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($migrationPlan['items'], 0, 50) as $item): ?>
                        <tr>
                            <td><?= esc($item['name']) ?></td>
                            <td><?= esc($item['status']) ?></td>
                            <td><?= esc($item['storage']) ?></td>
                            <td><?= esc(format_bytes((int) $item['size_bytes'])) ?></td>
                            <td style="overflow-wrap:anywhere;"><?= esc($item['file_id']) ?></td>
                            <td style="overflow-wrap:anywhere;"><?= esc($item['local_url']) ?></td>
                            <td style="overflow-wrap:anywhere;"><?= esc($item['media_url']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if ($migrationResult): ?>
            <h4>Step 2 Result · Upload + Map Batch</h4>
            <p class="page-toolbar-meta">Processed <?= (int) ($migrationResult['processed'] ?? 0) ?> · Uploaded <?= (int) ($migrationResult['uploaded'] ?? 0) ?> · Failed <?= (int) ($migrationResult['failed'] ?? 0) ?></p>
            <?php if (!empty($migrationResult['error'])): ?><div class="alert alert-warning"><?= esc($migrationResult['error']) ?></div><?php endif; ?>
            <pre class="migration-log"><?= esc(implode("\n", $migrationResult['logs'] ?? [])) ?></pre>
            <?php endif; ?>

            <?php if ($referenceResult): ?>
            <h4><?= !empty($referenceResult['committed']) ? 'Step 4 Result · Reference Replacement Commit' : 'Step 3 Result · Reference Replacement Preview' ?></h4>
            <?php if (!empty($referenceResult['error'])): ?><div class="alert alert-warning"><?= esc($referenceResult['error']) ?></div><?php endif; ?>
            <?php if (!empty($referenceResult['message'])): ?><p class="page-toolbar-meta"><?= esc((string) $referenceResult['message']) ?></p><?php endif; ?>
            <p class="page-toolbar-meta">
                Candidates <?= (int) ($referenceResult['candidate_count'] ?? count($referenceResult['items'] ?? [])) ?>
                · Safe <?= (int) ($referenceResult['safe_count'] ?? 0) ?>
                · Skipped <?= (int) ($referenceResult['skipped_count'] ?? 0) ?>
                · Replacements <?= (int) ($referenceResult['replacement_count'] ?? 0) ?>
                · Changed fields <?= (int) ($referenceResult['changed'] ?? 0) ?>
                <?php if (!empty($referenceResult['preview_token'])): ?> · Preview <?= esc((string) $referenceResult['preview_token']) ?><?php endif; ?>
                <?php if (!empty($referenceResult['batch_token'])): ?> · Batch <?= esc((string) $referenceResult['batch_token']) ?><?php endif; ?>
            </p>
            <?php if (!empty($referenceResult['tables_affected'])): ?>
            <p class="page-toolbar-meta">Tables: <?= esc(implode(', ', $referenceResult['tables_affected'])) ?> · Columns: <?= esc(implode(', ', $referenceResult['columns_affected'] ?? [])) ?> · Records: <?= esc(implode(', ', array_slice($referenceResult['record_ids_affected'] ?? [], 0, 30))) ?></p>
            <?php endif; ?>
            <div class="table-wrap">
                <table class="table--dense">
                    <thead>
                        <tr>
                            <th>Table</th>
                            <th>Record ID</th>
                            <th>Column</th>
                            <th>Count</th>
                            <th>Old URL</th>
                            <th>New URL</th>
                            <th>Mapping source</th>
                            <th>Safe</th>
                            <th>Skip</th>
                            <th>Skip reason</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (($referenceResult['items'] ?? []) as $item): ?>
                        <?php
                            $willSkip = !empty($item['will_skip']) || empty($item['safe']);
                            $rowStyle = $willSkip ? 'background:#fff7ed;' : 'background:#f0fdf4;';
                        ?>
                        <tr style="<?= $rowStyle ?>">
                            <td><?= esc((string) ($item['table'] ?? '')) ?></td>
                            <td><?= (int) ($item['row_id'] ?? 0) ?></td>
                            <td><?= esc((string) ($item['column'] ?? '')) ?></td>
                            <td><?= (int) ($item['replacement_count'] ?? 0) ?></td>
                            <td style="overflow-wrap:anywhere;max-width:16rem;"><?= esc((string) ($item['from'] ?? '')) ?></td>
                            <td style="overflow-wrap:anywhere;max-width:16rem;"><?= esc((string) ($item['to'] ?? '')) ?></td>
                            <td style="overflow-wrap:anywhere;"><?= esc((string) ($item['mapping_source'] ?? '')) ?></td>
                            <td><?= !empty($item['safe']) && empty($item['will_skip']) ? 'Yes' : 'No' ?></td>
                            <td><?= $willSkip ? 'Yes' : 'No' ?></td>
                            <td><?= esc((string) ($item['skip_reason'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if ($verifyResult): ?>
            <h4>Step 5 Result · Frontend Verification</h4>
            <p class="page-toolbar-meta"><?= esc((string) ($verifyResult['message'] ?? '')) ?></p>
            <div class="dashboard-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:0.75rem;margin:0.75rem 0 1rem;">
                <div><div class="text-muted">Media refs scanned</div><strong><?= (int) ($verifyResult['total_media_references'] ?? 0) ?></strong></div>
                <div><div class="text-muted">Files Service /media</div><strong><?= (int) ($verifyResult['files_service_media_urls'] ?? 0) ?></strong></div>
                <div><div class="text-muted">Local fallback</div><strong><?= (int) ($verifyResult['local_fallback_urls'] ?? 0) ?></strong></div>
                <div><div class="text-muted">Broken-looking</div><strong><?= (int) ($verifyResult['broken_looking_references'] ?? 0) ?></strong></div>
                <div><div class="text-muted">External skipped</div><strong><?= (int) ($verifyResult['external_urls_skipped'] ?? 0) ?></strong></div>
                <div><div class="text-muted">Mixed local/remote</div><strong><?= (int) ($verifyResult['mixed_local_remote_records'] ?? 0) ?></strong></div>
                <div><div class="text-muted">Legacy /files/view</div><strong><?= (int) ($verifyResult['legacy_files_view_urls'] ?? 0) ?></strong></div>
                <div><div class="text-muted">All /media format OK</div><strong><?= !empty($verifyResult['canonical_media_format_ok']) ? 'Yes' : 'No' ?></strong></div>
            </div>
            <?php if (!empty($verifyResult['samples'])): ?>
            <div class="table-wrap">
                <table class="table--dense">
                    <thead><tr><th>Table</th><th>Record</th><th>Column</th><th>Class</th><th>Reference</th></tr></thead>
                    <tbody>
                    <?php foreach ($verifyResult['samples'] as $sample): ?>
                        <tr>
                            <td><?= esc((string) ($sample['table'] ?? '')) ?></td>
                            <td><?= (int) ($sample['row_id'] ?? 0) ?></td>
                            <td><?= esc((string) ($sample['column'] ?? '')) ?></td>
                            <td><?= esc((string) ($sample['class'] ?? '')) ?></td>
                            <td style="overflow-wrap:anywhere;"><?= esc((string) ($sample['ref'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <?php if ($rollbackResult): ?>
            <h4>Step 6 Result · Rollback</h4>
            <?php if (!empty($rollbackResult['error'])): ?><div class="alert alert-warning"><?= esc($rollbackResult['error']) ?></div><?php endif; ?>
            <?php if (!empty($rollbackResult['message'])): ?><p class="page-toolbar-meta"><?= esc((string) $rollbackResult['message']) ?></p><?php endif; ?>
            <p class="page-toolbar-meta">Restored <?= (int) ($rollbackResult['restored'] ?? 0) ?> · Failed <?= (int) ($rollbackResult['failed'] ?? 0) ?><?= !empty($rollbackResult['batch_token']) ? ' · Batch ' . esc((string) $rollbackResult['batch_token']) : '' ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($lastRequestDiagnostics): ?>
    <div class="card">
        <div class="card-header"><h3 class="card-title">Last Safe Response / Diagnostics</h3></div>
        <div class="card-body">
            <dl style="display:grid;grid-template-columns:minmax(12rem,auto) 1fr;gap:0.5rem 1rem;margin:0;">
                <?php foreach ($lastRequestDiagnostics as $diagnosticKey => $diagnosticValue): ?>
                    <dt><?= esc($diagnosticKey) ?></dt>
                    <dd style="margin:0;overflow-wrap:anywhere;white-space:pre-wrap;"><?= esc(is_array($diagnosticValue) ? implode(', ', $diagnosticValue) : $diagnosticValue) ?></dd>
                <?php endforeach; ?>
            </dl>
            <p class="text-muted" style="margin:1rem 0 0;"><small>Install ID, nonce, and credential secrets are never displayed in diagnostics.</small></p>
        </div>
    </div>
    <?php endif; ?>

    <div class="card" style="border-left:4px solid #f59e0b;">
        <div class="card-header"><h3 class="card-title">Safety Warnings</h3></div>
        <div class="card-body">
            <ul style="margin:0;padding-left:1.25rem;">
                <li>Pending Approval is not Connected. Connected appears only after encrypted credentials are saved and locally verified.</li>
                <li>Local media files are never deleted by this phase.</li>
                <li>Reference replacement only uses rows with confirmed Files Service mappings and stores rollback backups before commit.</li>
                <li>Commit requires a non-stale preview batch, rewrite setting enabled, and zero unsafe candidates. URL rewrite is never automatic.</li>
                <li>Rollback restores only the latest committed batch and never deletes local files or Files Service mappings.</li>
                <li>Lifecycle delete/unmap marks audit state only. Remote Files Service hard-delete is disabled (API unsupported).</li>
                <li>Do not paste Client Secret into tickets, URLs, browser storage, or logs.</li>
            </ul>
            <?php if ($status !== 'not_connected'): ?>
            <form method="POST" style="margin-top:1rem;">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="_action" value="reset">
                <button type="submit" class="btn btn-danger btn-sm">Reset Connection Status</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
