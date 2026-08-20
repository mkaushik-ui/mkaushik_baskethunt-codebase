<?php
if (!defined('SOI_ROOT')) exit;

if (!defined('FS_CONNECTOR_VERSION')) {
    define('FS_CONNECTOR_VERSION', '1.0.4');
}

if (!defined('FS_CONNECTOR_FILES_URL')) {
    define('FS_CONNECTOR_FILES_URL', 'https://files.soi.co.in');
}

if (!defined('FS_CONNECTOR_SECRET_PREFIX')) {
    define('FS_CONNECTOR_SECRET_PREFIX', 'fscenc:');
}

/**
 * Convert a URL or hostname into the canonical hostname required by Files Service.
 * Returns an empty string when the input cannot be safely normalized.
 */
if (!function_exists('fs_connector_normalize_hostname')) {
    function fs_connector_normalize_hostname(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $raw = trim((string) $value);
        if ($raw === '' || preg_match('/\s/u', $raw)) {
            return '';
        }

        if (str_starts_with($raw, '//')) {
            $url = 'https:' . $raw;
        } elseif (preg_match('#^[a-z][a-z0-9+.-]*://#i', $raw)) {
            $url = $raw;
        } else {
            $url = 'https://' . $raw;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }

        $host = strtolower(rtrim(trim((string) $parts['host']), './'));
        if (
            $host === ''
            || strlen($host) > 253
            || str_contains($host, '://')
            || str_contains($host, '/')
            || str_contains($host, '\\')
            || str_contains($host, ':')
            || str_contains($host, '@')
            || preg_match('/\s/u', $host)
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        ) {
            return '';
        }

        return $host;
    }
}

if (!function_exists('fs_connector_url_matches_hostname')) {
    function fs_connector_url_matches_hostname(string $url, string $hostname): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }

        $expected = fs_connector_normalize_hostname($hostname);
        $actual = fs_connector_normalize_hostname((string) ($parts['host'] ?? ''));

        return $expected !== '' && hash_equals($expected, $actual);
    }
}

if (!function_exists('fs_connector_normalize_permissions')) {
    function fs_connector_normalize_permissions(mixed $permissions): array
    {
        if (!is_array($permissions)) {
            return [];
        }

        $requested = array_fill_keys(array_map('strval', $permissions), true);
        $supported = ['upload', 'download', 'signed_links'];

        return array_values(array_filter(
            $supported,
            static fn(string $permission): bool => isset($requested[$permission])
        ));
    }
}

if (!function_exists('fs_connector_generate_install_id')) {
    function fs_connector_generate_install_id(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}

if (!function_exists('fs_connector_timestamp_format')) {
    function fs_connector_timestamp_format(): string
    {
        return 'unix_epoch_seconds';
    }
}

if (!function_exists('fs_connector_current_timestamp')) {
    function fs_connector_current_timestamp(): string
    {
        return (string) time();
    }
}

if (!function_exists('fs_connector_utc_datetime')) {
    function fs_connector_utc_datetime(int $epoch): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $epoch);
    }
}

if (!function_exists('fs_connector_parse_timestamp_epoch')) {
    function fs_connector_parse_timestamp_epoch(mixed $timestamp): ?int
    {
        if (is_int($timestamp)) {
            return $timestamp >= 0 ? $timestamp : null;
        }

        if (is_float($timestamp)) {
            $epoch = (int) $timestamp;
            return $epoch >= 0 && (float) $epoch === $timestamp ? $epoch : null;
        }

        if (!is_scalar($timestamp)) {
            return null;
        }

        $value = trim((string) $timestamp);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{1,10}$/', $value)) {
            return (int) $value;
        }

        $parsed = strtotime($value);
        return $parsed === false || $parsed < 0 ? null : $parsed;
    }
}

if (!function_exists('fs_connector_detect_timestamp_format')) {
    function fs_connector_detect_timestamp_format(mixed $timestamp): string
    {
        if (is_int($timestamp) || is_float($timestamp)) {
            return fs_connector_timestamp_format();
        }

        $value = is_scalar($timestamp) ? trim((string) $timestamp) : '';
        if ($value === '') {
            return 'missing';
        }

        if (preg_match('/^\d{1,10}$/', $value)) {
            return fs_connector_timestamp_format();
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value)) {
            return 'iso8601_utc_z';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:\d{2}|Z)$/', $value)) {
            return 'iso8601';
        }

        return 'unknown';
    }
}

if (!function_exists('fs_connector_sanitize_app_id')) {
    /**
     * Normalize app id / site slug for Files Service (requested_app_id / X-App-Id).
     */
    function fs_connector_sanitize_app_id(mixed $value, string $fallback = 'cms'): string
    {
        if (function_exists('soi_site_sanitize_slug')) {
            return soi_site_sanitize_slug((string) $value, $fallback);
        }

        $slug = strtolower(trim((string) $value));
        $slug = preg_replace('/[^a-z0-9_-]/', '', $slug) ?? '';
        if ($slug === '' || !preg_match('/^[a-z0-9]/', $slug)) {
            $fb = strtolower(trim($fallback));
            $fb = preg_replace('/[^a-z0-9_-]/', '', $fb) ?? 'cms';
            return $fb !== '' ? substr($fb, 0, 64) : 'cms';
        }

        return substr($slug, 0, 64);
    }
}

if (!function_exists('fs_connector_client_id_prefix')) {
    /** Redacted client id for diagnostics (never full secret material). */
    function fs_connector_client_id_prefix(string $clientId, int $keep = 8): string
    {
        $clientId = trim($clientId);
        if ($clientId === '') {
            return '';
        }
        $keep = max(4, min(16, $keep));
        if (strlen($clientId) <= $keep) {
            return $clientId . '…';
        }

        return substr($clientId, 0, $keep) . '…';
    }
}

if (!function_exists('fs_connector_installed_identity')) {
    /**
     * Single source of truth for installed CMS + Files Service connector identity.
     * Derived from installer/options (site_name, site_slug, site_domain, URLs).
     * Never hardcodes a product domain or Search Console app id.
     *
     * @return array{
     *   site_name:string,site_slug:string,site_domain:string,canonical_base_url:string,
     *   admin_base_url:string,frontend_base_url:string,environment_label:string,
     *   scheme:string,detected_host:string,uses_host_fallback:bool,
     *   files_service_url:string,source_domain:string,requested_app_id:string,
     *   requested_app_name:string,files_service_app_name:string,files_service_app_slug:string,
     *   credential_app_id:string,connection_status:string
     * }
     */
    function fs_connector_installed_identity(): array
    {
        $base = [];
        if (function_exists('soi_site_identity')) {
            $base = soi_site_identity();
        } else {
            $home = defined('SOI_HOME_URL') ? (string) SOI_HOME_URL : '';
            $domain = fs_connector_normalize_hostname($home);
            $name = defined('SOI_SITE_NAME') ? trim((string) SOI_SITE_NAME) : 'SOI CMS';
            $slug = $domain !== ''
                ? fs_connector_sanitize_app_id(explode('.', $domain)[0] ?? 'cms', 'cms')
                : 'cms';
            $base = [
                'site_name' => $name !== '' ? $name : 'SOI CMS',
                'site_slug' => $slug,
                'site_domain' => $domain,
                'canonical_base_url' => $home !== '' ? rtrim($home, '/') : ($domain !== '' ? 'https://' . $domain : ''),
                'admin_base_url' => defined('SOI_ADMIN_URL') ? rtrim((string) SOI_ADMIN_URL, '/') : '',
                'frontend_base_url' => $home !== '' ? rtrim($home, '/') : '',
                'environment_label' => 'production',
                'scheme' => 'https',
                'detected_host' => '',
                'source_domain' => $domain,
                'files_service_app_name' => $name !== '' ? $name : 'SOI CMS',
                'files_service_app_slug' => $slug,
                'uses_host_fallback' => false,
            ];
        }

        $siteName = trim(strip_tags((string) ($base['site_name'] ?? 'SOI CMS')));
        if ($siteName === '') {
            $siteName = 'SOI CMS';
        }
        $siteSlug = fs_connector_sanitize_app_id((string) ($base['site_slug'] ?? 'cms'), 'cms');
        $siteDomain = fs_connector_normalize_hostname((string) ($base['site_domain'] ?? ''));

        // Prefer explicit connector domain option only when set; otherwise installed site domain.
        $storedDomain = '';
        try {
            $storedDomain = fs_connector_normalize_hostname(fs_connector_option('fs_conn_domain', ''));
        } catch (Throwable $e) {
            $storedDomain = '';
        }
        $sourceDomain = $storedDomain !== '' ? $storedDomain : $siteDomain;

        $appSlugStored = '';
        try {
            $rawAppSlug = trim((string) fs_connector_option('fs_conn_app_slug', ''));
            $appSlugStored = $rawAppSlug !== '' ? fs_connector_sanitize_app_id($rawAppSlug, 'cms') : '';
        } catch (Throwable $e) {
            $appSlugStored = '';
        }
        // Prefer explicit connector slug; otherwise installed site slug / identity.
        if ($appSlugStored !== '') {
            $requestedAppId = $appSlugStored;
        } else {
            $requestedAppId = fs_connector_sanitize_app_id(
                (string) ($base['files_service_app_slug'] ?? $siteSlug),
                $siteSlug !== '' ? $siteSlug : 'cms'
            );
        }

        $appNameStored = '';
        try {
            $appNameStored = trim(strip_tags((string) fs_connector_option('fs_conn_app_display_name', '')));
        } catch (Throwable $e) {
            $appNameStored = '';
        }
        $requestedAppName = $appNameStored !== ''
            ? $appNameStored
            : (string) ($base['files_service_app_name'] ?? $siteName);
        $requestedAppName = substr($requestedAppName !== '' ? $requestedAppName : $siteName, 0, 120);

        $credentialAppId = '';
        try {
            $rawCredApp = trim((string) fs_connector_option('fs_conn_app_id', ''));
            // Empty means unbound — do not coerce to "cms".
            $credentialAppId = $rawCredApp !== '' ? fs_connector_sanitize_app_id($rawCredApp, 'cms') : '';
        } catch (Throwable $e) {
            $credentialAppId = '';
        }

        $filesUrl = FS_CONNECTOR_FILES_URL;
        try {
            $optUrl = rtrim(trim((string) fs_connector_option('fsc_files_url', '')), '/');
            if ($optUrl !== '' && preg_match('#^https://#i', $optUrl)) {
                $filesUrl = $optUrl;
            }
        } catch (Throwable $e) {
            // keep constant
        }

        $status = 'not_connected';
        try {
            $status = (string) fs_connector_option('fsc_status', 'not_connected');
        } catch (Throwable $e) {
            $status = 'not_connected';
        }

        return [
            'site_name' => substr($siteName, 0, 120),
            'site_slug' => $siteSlug,
            'site_domain' => $siteDomain,
            'canonical_base_url' => (string) ($base['canonical_base_url'] ?? ''),
            'admin_base_url' => (string) ($base['admin_base_url'] ?? ''),
            'frontend_base_url' => (string) ($base['frontend_base_url'] ?? ''),
            'environment_label' => (string) ($base['environment_label'] ?? 'production'),
            'scheme' => (string) ($base['scheme'] ?? 'https'),
            'detected_host' => (string) ($base['detected_host'] ?? ''),
            'uses_host_fallback' => !empty($base['uses_host_fallback']),
            'files_service_url' => $filesUrl,
            'source_domain' => $sourceDomain,
            'requested_app_id' => $requestedAppId,
            'requested_app_name' => $requestedAppName,
            'files_service_app_name' => $requestedAppName,
            'files_service_app_slug' => $requestedAppId,
            'credential_app_id' => $credentialAppId,
            'connection_status' => $status,
        ];
    }
}

if (!function_exists('fs_connector_site_identity')) {
    /**
     * Connector-facing site identity (delegates to installed identity helper).
     */
    function fs_connector_site_identity(): array
    {
        return fs_connector_installed_identity();
    }
}

if (!function_exists('fs_connector_build_request_payload')) {
    function fs_connector_build_request_payload(
        string $sourceDomain,
        string $sourceSiteName,
        string $requestedEntitySlug,
        array $requestedPermissions,
        string $installId,
        string $timestamp,
        string $nonce
    ): array {
        $sourceDomain = fs_connector_normalize_hostname($sourceDomain);
        if ($sourceDomain === '') {
            throw new InvalidArgumentException('Invalid source domain.');
        }

        $identity = fs_connector_installed_identity();
        $cmsBaseUrl = '';
        if (function_exists('soi_site_validate_base_url')) {
            $cmsBaseUrl = soi_site_validate_base_url((string) ($identity['canonical_base_url'] ?? ''));
        }
        if ($cmsBaseUrl === '' || fs_connector_normalize_hostname($cmsBaseUrl) !== $sourceDomain) {
            $scheme = in_array((string) ($identity['scheme'] ?? 'https'), ['http', 'https'], true)
                ? (string) $identity['scheme']
                : 'https';
            $cmsBaseUrl = $scheme . '://' . $sourceDomain;
        }

        $adminBase = '';
        if (function_exists('soi_site_validate_base_url')) {
            $adminBase = soi_site_validate_base_url((string) ($identity['admin_base_url'] ?? ''));
        }
        if ($adminBase === '' || fs_connector_normalize_hostname($adminBase) !== $sourceDomain) {
            $adminBase = $cmsBaseUrl . '/admin';
        }

        $callbackUrl = rtrim($adminBase, '/') . '/files-service-connector.php?action=callback';
        $returnUrl = rtrim($adminBase, '/') . '/files-service-connector.php';

        if (
            !fs_connector_url_matches_hostname($callbackUrl, $sourceDomain)
            || !fs_connector_url_matches_hostname($returnUrl, $sourceDomain)
        ) {
            throw new RuntimeException('Connector callback host validation failed.');
        }

        // Always use installed CMS identity for app id (never a product default).
        $appSlug = fs_connector_sanitize_app_id(
            (string) ($identity['requested_app_id'] ?? $identity['site_slug'] ?? 'cms'),
            'cms'
        );
        $sourceSiteName = trim(strip_tags($sourceSiteName));
        if ($sourceSiteName === '') {
            $sourceSiteName = (string) ($identity['requested_app_name'] ?? $identity['site_name'] ?? 'SOI CMS');
        }
        $environment = (string) ($identity['environment_label'] ?? 'production');
        if (!in_array($environment, ['production', 'staging', 'development', 'local'], true)) {
            $environment = 'production';
        }

        return [
            'requested_app_id' => $appSlug,
            'requested_app_name' => substr($sourceSiteName, 0, 120),
            'source_domain' => $sourceDomain,
            'source_site_name' => substr($sourceSiteName, 0, 120),
            'cms_base_url' => $cmsBaseUrl,
            'callback_url' => $callbackUrl,
            'return_url' => $returnUrl,
            'environment' => $environment,
            'requested_entity_slug' => $requestedEntitySlug,
            'requested_permissions' => array_values($requestedPermissions),
            'connector_version' => FS_CONNECTOR_VERSION,
            'install_id' => $installId,
            'timestamp' => $timestamp,
            'nonce' => $nonce,
        ];
    }
}

if (!function_exists('fs_connector_domain_readiness_scan')) {
    /**
     * Static readiness scan for multi-domain packaging (no secrets, no content mutation).
     */
    function fs_connector_domain_readiness_scan(): array
    {
        $identity = fs_connector_site_identity();
        $root = defined('SOI_ROOT') ? SOI_ROOT : dirname(__DIR__, 2);
        $targets = [
            'plugins/files-service-connector/FileServiceMediaAdapter.php',
            'plugins/files-service-connector/plugin.php',
            'admin/files-service-connector.php',
            'admin/media.php',
            'admin/settings.php',
            'core/helpers.php',
        ];

        $hits = [];
        // Split needles so this function body does not self-match.
        $legacyDomainNeedle = 'search' . '.soi.co.in';
        $legacyProductNeedle = 'Search' . ' Console';
        foreach ($targets as $rel) {
            $path = $root . '/' . $rel;
            if (!is_file($path)) {
                continue;
            }
            $src = (string) file_get_contents($path);
            $lines = preg_split("/\r\n|\n|\r/", $src) ?: [];
            foreach ($lines as $idx => $lineText) {
                $lineNo = $idx + 1;
                if (str_contains($lineText, $legacyDomainNeedle)) {
                    $hits[] = [
                        'file' => $rel,
                        'line' => $lineNo,
                        'value' => 'legacy-domain-string',
                        'risk' => 'medium',
                        'sample' => $legacyDomainNeedle,
                    ];
                }
                if ((str_contains($rel, 'admin/') || str_contains($rel, 'plugins/'))
                    && (str_contains($lineText, "'" . $legacyProductNeedle . "'")
                        || str_contains($lineText, '"' . $legacyProductNeedle . '"'))
                ) {
                    $hits[] = [
                        'file' => $rel,
                        'line' => $lineNo,
                        'value' => 'legacy-product-name',
                        'risk' => 'low',
                        'sample' => $legacyProductNeedle,
                    ];
                }
            }
        }

        $hardcodedSearch = array_values(array_filter(
            $hits,
            static fn(array $h): bool => ($h['value'] ?? '') === 'legacy-domain-string'
        ));

        $warnings = [];
        if (($identity['site_domain'] ?? '') === '') {
            $warnings[] = 'Site domain is empty. Configure site_domain or site_url before cloning.';
        }
        if (!empty($identity['uses_host_fallback'])) {
            $warnings[] = 'Canonical domain is falling back to request Host header. Set site_domain/site_url for stable identity.';
        }
        if (($identity['source_domain'] ?? '') === '' || ($identity['source_domain'] ?? '') !== ($identity['site_domain'] ?? '')) {
            $storedFs = function_exists('fs_connector_option') ? fs_connector_normalize_hostname(fs_connector_option('fs_conn_domain', '')) : '';
            if ($storedFs !== '' && $storedFs !== ($identity['site_domain'] ?? '')) {
                $warnings[] = 'Files Service source_domain option differs from configured site_domain. Update only when intentionally re-binding.';
            }
        }
        if ($hardcodedSearch !== []) {
            $warnings[] = 'Runtime files still contain legacy sample-domain hardcodes. Review scan hits.';
        } else {
            $warnings[] = 'No runtime legacy sample-domain hardcodes found in scanned connector/media/settings/helpers files.';
        }
        if (function_exists('fs_connector_credentials_configured') && fs_connector_credentials_configured()) {
            $warnings[] = 'Existing Files Service credentials are present and will not be reset by readiness scan.';
        }

        $result = [
            'success' => true,
            'timestamp' => date('Y-m-d H:i:s'),
            'identity' => [
                'site_name' => $identity['site_name'] ?? '',
                'site_slug' => $identity['site_slug'] ?? '',
                'site_domain' => $identity['site_domain'] ?? '',
                'canonical_base_url' => $identity['canonical_base_url'] ?? '',
                'admin_base_url' => $identity['admin_base_url'] ?? '',
                'frontend_base_url' => $identity['frontend_base_url'] ?? '',
                'environment_label' => $identity['environment_label'] ?? '',
                'source_domain' => $identity['source_domain'] ?? '',
                'files_service_app_name' => $identity['files_service_app_name'] ?? '',
                'files_service_app_slug' => $identity['files_service_app_slug'] ?? '',
                'detected_host' => $identity['detected_host'] ?? '',
                'uses_host_fallback' => !empty($identity['uses_host_fallback']),
            ],
            'connector' => [
                'version' => defined('FS_CONNECTOR_VERSION') ? FS_CONNECTOR_VERSION : '',
                'files_service_url' => defined('FS_CONNECTOR_FILES_URL') ? FS_CONNECTOR_FILES_URL : 'https://files.soi.co.in',
                'credentials_configured' => function_exists('fs_connector_credentials_configured') && fs_connector_credentials_configured(),
                'connection_status' => function_exists('fs_connector_option') ? (string) fs_connector_option('fsc_status', 'unknown') : 'unknown',
            ],
            'media_rewrite' => [
                'source_domain_dynamic' => true,
                'canonical_media_pattern' => 'https://files.soi.co.in/media/{file_id}',
                'allowed_patterns' => class_exists('FileServiceMediaAdapter') && method_exists('FileServiceMediaAdapter', 'allowedSourceUrlPatternDescriptions')
                    ? FileServiceMediaAdapter::allowedSourceUrlPatternDescriptions()
                    : [],
            ],
            'hardcode_hits' => $hits,
            'hardcode_search_count' => count($hardcodedSearch),
            'warnings' => $warnings,
            'ready_for_clone' => ($identity['site_domain'] ?? '') !== '' && empty($identity['uses_host_fallback']) && $hardcodedSearch === [],
        ];

        if (function_exists('fs_connector_set_option')) {
            fs_connector_set_option('fs_conn_last_domain_readiness_at', $result['timestamp']);
            $safe = $result;
            // Never store secrets.
            unset($safe['connector']['client_secret']);
            fs_connector_set_option('fs_conn_last_domain_readiness', json_encode($safe, JSON_UNESCAPED_SLASHES) ?: '');
        }

        return $result;
    }
}

if (!function_exists('fs_connector_bool')) {
    function fs_connector_bool(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'yes', 'true', 'on'], true);
    }
}

if (!function_exists('fs_connector_option')) {
    function fs_connector_option(string $key, mixed $default = ''): mixed
    {
        return \SOI\Core\Database::getOption($key, $default);
    }
}

if (!function_exists('fs_connector_set_option')) {
    function fs_connector_set_option(string $key, mixed $value): void
    {
        \SOI\Core\Database::setOption($key, $value);
    }
}

if (!function_exists('fs_connector_delivery_modes')) {
    function fs_connector_delivery_modes(): array
    {
        return ['local', 'hybrid', 'files_service'];
    }
}

if (!function_exists('fs_connector_normalize_delivery_mode')) {
    function fs_connector_normalize_delivery_mode(mixed $value): string
    {
        $mode = strtolower(trim((string) $value));
        return in_array($mode, fs_connector_delivery_modes(), true) ? $mode : 'hybrid';
    }
}

if (!function_exists('fs_connector_get_settings')) {
    function fs_connector_get_settings(): array
    {
        $batchSize = (int) fs_connector_option('fs_conn_migration_batch_size', '5');
        $batchSize = max(1, min(50, $batchSize));

        $previewToken = trim((string) fs_connector_option('fs_conn_rewrite_preview_token', ''));
        $lastRewriteBatch = trim((string) fs_connector_option('fs_conn_last_rewrite_batch', ''));

        return [
            'connector_enabled' => fs_connector_bool(fs_connector_option('fs_conn_connector_enabled', '1')),
            'connection_status' => (string) fs_connector_option('fsc_status', 'not_connected'),
            'files_service_url' => FS_CONNECTOR_FILES_URL,
            'client_id_present' => trim((string) fs_connector_option('fs_conn_client_id', '')) !== '',
            'client_secret_configured' => trim((string) fs_connector_option('fs_conn_client_secret_enc', '')) !== '',
            'media_offload_enabled' => fs_connector_bool(fs_connector_option('fs_conn_media_offload_enabled', '0')),
            'offload_new_uploads_enabled' => fs_connector_bool(fs_connector_option('fs_conn_offload_new_uploads_enabled', '0')),
            'migration_enabled' => fs_connector_bool(fs_connector_option('fs_conn_migration_enabled', '0')),
            'delivery_mode' => fs_connector_normalize_delivery_mode(fs_connector_option('fs_conn_delivery_mode', 'hybrid')),
            'keep_local_copy' => fs_connector_bool(fs_connector_option('fs_conn_keep_local_copy', '1')),
            'migration_batch_size' => $batchSize,
            'rewrite_content_urls_enabled' => fs_connector_bool(fs_connector_option('fs_conn_rewrite_content_urls_enabled', '0')),
            'rollback_available' => $lastRewriteBatch !== '',
            'last_rewrite_batch' => $lastRewriteBatch,
            'preview_token' => $previewToken,
            'preview_available' => $previewToken !== '',
            'preview_created_at' => (string) fs_connector_option('fs_conn_rewrite_preview_at', ''),
            'last_rewrite_commit_at' => (string) fs_connector_option('fs_conn_last_rewrite_commit_at', ''),
            'last_rewrite_commit_count' => (int) fs_connector_option('fs_conn_last_rewrite_commit_count', '0'),
            'last_frontend_verify_at' => (string) fs_connector_option('fs_conn_last_frontend_verify_at', ''),
            'last_rollback_at' => (string) fs_connector_option('fs_conn_last_rollback_at', ''),
            'last_migration_run' => (string) fs_connector_option('fs_conn_last_migration_run', ''),
            'last_migration_result' => (string) fs_connector_option('fs_conn_last_migration_result', ''),
            'remote_lifecycle_mode' => fs_connector_normalize_remote_lifecycle_mode(fs_connector_option('fs_conn_remote_lifecycle_mode', 'retain_remote')),
            'last_lifecycle_audit_at' => (string) fs_connector_option('fs_conn_last_lifecycle_audit_at', ''),
            'last_lifecycle_audit' => (string) fs_connector_option('fs_conn_last_lifecycle_audit', ''),
        ];
    }
}

if (!function_exists('fs_connector_normalize_remote_lifecycle_mode')) {
    function fs_connector_normalize_remote_lifecycle_mode(mixed $value): string
    {
        $mode = strtolower(trim((string) $value));
        // Only retain_remote is supported until Files Service exposes archive/delete APIs.
        $allowed = ['retain_remote', 'archive_remote_if_supported', 'delete_remote_if_supported'];
        if (!in_array($mode, $allowed, true)) {
            return 'retain_remote';
        }
        if ($mode !== 'retain_remote') {
            return 'retain_remote';
        }
        return $mode;
    }
}

if (!function_exists('fs_connector_save_settings')) {
    function fs_connector_save_settings(array $input): void
    {
        $deliveryMode = fs_connector_normalize_delivery_mode($input['delivery_mode'] ?? 'hybrid');
        $batchSize = max(1, min(50, (int) ($input['migration_batch_size'] ?? 5)));

        fs_connector_set_option('fs_conn_connector_enabled', !empty($input['connector_enabled']) ? '1' : '0');
        fs_connector_set_option('fsc_files_url', FS_CONNECTOR_FILES_URL);
        fs_connector_set_option('fs_conn_media_offload_enabled', !empty($input['media_offload_enabled']) ? '1' : '0');
        fs_connector_set_option('fs_conn_offload_new_uploads_enabled', !empty($input['offload_new_uploads_enabled']) ? '1' : '0');
        fs_connector_set_option('fs_conn_migration_enabled', !empty($input['migration_enabled']) ? '1' : '0');
        fs_connector_set_option('fs_conn_delivery_mode', $deliveryMode);
        fs_connector_set_option('fs_conn_keep_local_copy', '1');
        fs_connector_set_option('fs_conn_migration_batch_size', (string) $batchSize);
        fs_connector_set_option('fs_conn_rewrite_content_urls_enabled', !empty($input['rewrite_content_urls_enabled']) ? '1' : '0');
        // Remote hard-delete/archive modes are not enabled: discovery has no delete API.
        fs_connector_set_option('fs_conn_remote_lifecycle_mode', 'retain_remote');
    }
}

if (!function_exists('fs_connector_record_migration_result')) {
    function fs_connector_record_migration_result(string $type, array $result): void
    {
        $safe = [
            'type' => preg_replace('/[^a-z0-9_-]/i', '_', $type),
            'success' => (bool) ($result['success'] ?? false),
            'processed' => (int) ($result['processed'] ?? ($result['candidate_count'] ?? 0)),
            'uploaded' => (int) ($result['uploaded'] ?? 0),
            'failed' => (int) ($result['failed'] ?? 0),
            'changed' => (int) ($result['changed'] ?? 0),
            'replacement_count' => (int) ($result['replacement_count'] ?? 0),
            'restored' => (int) ($result['restored'] ?? 0),
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        if (!empty($result['error'])) {
            $safe['error'] = substr(fs_connector_safe_remote_response((string) $result['error']), 0, 500);
        }
        if (!empty($result['preview_token'])) {
            $safe['preview_token'] = substr(preg_replace('/[^a-zA-Z0-9._-]/', '', (string) $result['preview_token']) ?? '', 0, 80);
        }
        if (!empty($result['batch_token'])) {
            $safe['batch_token'] = substr(preg_replace('/[^a-zA-Z0-9._-]/', '', (string) $result['batch_token']) ?? '', 0, 80);
        }

        fs_connector_set_option('fs_conn_last_migration_run', $safe['timestamp']);
        fs_connector_set_option('fs_conn_last_migration_result', json_encode($safe, JSON_UNESCAPED_SLASHES));
        // Only persist commit batch tokens as the rollback pointer. Never treat preview tokens as commits.
        if ($type === 'rewrite_commit' && !empty($result['batch_token']) && !empty($result['committed'])) {
            fs_connector_set_option('fs_conn_last_rewrite_batch', (string) $result['batch_token']);
        }
    }
}

if (!function_exists('fs_connector_encryption_ready')) {
    function fs_connector_encryption_ready(): bool
    {
        return defined('SOI_SECRET_KEY')
            && trim((string) SOI_SECRET_KEY) !== ''
            && function_exists('openssl_encrypt')
            && function_exists('openssl_decrypt')
            && in_array('aes-256-gcm', array_map('strtolower', openssl_get_cipher_methods()), true);
    }
}

if (!function_exists('fs_connector_encryption_status')) {
    function fs_connector_encryption_status(): array
    {
        return [
            'ready' => fs_connector_encryption_ready(),
            'key_present' => defined('SOI_SECRET_KEY') && trim((string) SOI_SECRET_KEY) !== '',
            'openssl_present' => function_exists('openssl_encrypt') && function_exists('openssl_decrypt'),
            'cipher' => 'aes-256-gcm',
        ];
    }
}

if (!function_exists('fs_connector_encryption_key')) {
    function fs_connector_encryption_key(): string
    {
        if (!fs_connector_encryption_ready()) {
            throw new RuntimeException('Files Service connector encryption is not ready.');
        }

        return hash('sha256', (string) SOI_SECRET_KEY, true);
    }
}

if (!function_exists('fs_connector_encrypt_secret')) {
    function fs_connector_encrypt_secret(string $secret): string
    {
        if ($secret === '') {
            return '';
        }

        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($secret, 'aes-256-gcm', fs_connector_encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false || strlen($tag) !== 16) {
            throw new RuntimeException('Files Service client secret could not be encrypted.');
        }

        return FS_CONNECTOR_SECRET_PREFIX . base64_encode($iv . $tag . $cipher);
    }
}

if (!function_exists('fs_connector_decrypt_secret')) {
    function fs_connector_decrypt_secret(string $encrypted): string
    {
        if ($encrypted === '') {
            return '';
        }

        if (!str_starts_with($encrypted, FS_CONNECTOR_SECRET_PREFIX)) {
            throw new RuntimeException('Files Service client secret is not encrypted.');
        }

        $payload = base64_decode(substr($encrypted, strlen(FS_CONNECTOR_SECRET_PREFIX)), true);
        if ($payload === false || strlen($payload) < 29) {
            throw new RuntimeException('Files Service encrypted secret payload is invalid.');
        }

        $iv = substr($payload, 0, 12);
        $tag = substr($payload, 12, 16);
        $cipher = substr($payload, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', fs_connector_encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new RuntimeException('Files Service client secret could not be decrypted.');
        }

        return $plain;
    }
}

if (!function_exists('fs_connector_credentials_registry')) {
    /**
     * Local credential metadata only (no secrets). Used to prefer active over superseded/revoked.
     *
     * @return list<array{client_id:string,app_id:string,status:string,updated_at:string}>
     */
    function fs_connector_credentials_registry(): array
    {
        $raw = trim((string) fs_connector_option('fs_conn_credentials_registry', ''));
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cid = trim((string) ($row['client_id'] ?? ''));
            if ($cid === '' || !preg_match('/^[A-Za-z0-9._:-]{6,191}$/', $cid)) {
                continue;
            }
            $status = strtolower(trim((string) ($row['status'] ?? 'unknown')));
            if (!in_array($status, ['active', 'superseded', 'revoked', 'unknown'], true)) {
                $status = 'unknown';
            }
            $out[] = [
                'client_id' => $cid,
                'app_id' => fs_connector_sanitize_app_id((string) ($row['app_id'] ?? ''), 'cms'),
                'status' => $status,
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }

        return $out;
    }
}

if (!function_exists('fs_connector_credentials_registry_save')) {
    /**
     * @param list<array{client_id:string,app_id:string,status:string,updated_at:string}> $rows
     */
    function fs_connector_credentials_registry_save(array $rows): void
    {
        $safe = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cid = trim((string) ($row['client_id'] ?? ''));
            if ($cid === '') {
                continue;
            }
            // Never persist secrets in registry.
            $safe[] = [
                'client_id' => $cid,
                'app_id' => fs_connector_sanitize_app_id((string) ($row['app_id'] ?? ''), 'cms'),
                'status' => (string) ($row['status'] ?? 'unknown'),
                'updated_at' => (string) ($row['updated_at'] ?? date('Y-m-d H:i:s')),
            ];
        }
        // Cap history to avoid unbounded option growth.
        if (count($safe) > 20) {
            $safe = array_slice($safe, -20);
        }
        fs_connector_set_option('fs_conn_credentials_registry', json_encode($safe, JSON_UNESCAPED_SLASHES) ?: '[]');
    }
}

if (!function_exists('fs_connector_resolve_upload_app_id')) {
    /**
     * App id bound to the active credential for upload/HMAC.
     * Preference: credential-bound fs_conn_app_id → installed requested_app_id.
     * Never falls back to a product-hardcoded value (e.g. "search").
     */
    function fs_connector_resolve_upload_app_id(): string
    {
        $rawBound = trim((string) fs_connector_option('fs_conn_app_id', ''));
        if ($rawBound !== '') {
            return fs_connector_sanitize_app_id($rawBound, 'cms');
        }

        $identity = fs_connector_installed_identity();
        return fs_connector_sanitize_app_id(
            (string) ($identity['requested_app_id'] ?? $identity['site_slug'] ?? 'cms'),
            'cms'
        );
    }
}

if (!function_exists('fs_connector_save_credentials')) {
    function fs_connector_save_credentials(string $clientId, string $clientSecret, ?string $appId = null): array
    {
        $clientId = trim($clientId);
        $clientSecret = trim($clientSecret);

        if (!preg_match('/^[A-Za-z0-9._:-]{6,191}$/', $clientId)) {
            return ['success' => false, 'error' => 'Client ID format is invalid.'];
        }

        if ($clientSecret === '') {
            return ['success' => false, 'error' => 'Client Secret is required for the first secure save.'];
        }

        if (!fs_connector_encryption_ready()) {
            return ['success' => false, 'error' => 'Encryption is not ready. Configure SOI_SECRET_KEY and OpenSSL before saving Files Service credentials.'];
        }

        $identity = fs_connector_installed_identity();
        $resolvedAppId = fs_connector_sanitize_app_id(
            $appId !== null && trim($appId) !== ''
                ? $appId
                : (string) ($identity['requested_app_id'] ?? $identity['site_slug'] ?? 'cms'),
            'cms'
        );

        try {
            $encrypted = fs_connector_encrypt_secret($clientSecret);
            $verified = fs_connector_decrypt_secret($encrypted);
            if (!hash_equals($clientSecret, $verified)) {
                return ['success' => false, 'error' => 'Encrypted credential verification failed.'];
            }

            $previousClientId = trim((string) fs_connector_option('fs_conn_client_id', ''));
            $rawPreviousAppId = trim((string) fs_connector_option('fs_conn_app_id', ''));
            $previousAppId = $rawPreviousAppId !== '' ? fs_connector_sanitize_app_id($rawPreviousAppId, 'cms') : '';
            $registry = fs_connector_credentials_registry();
            $now = date('Y-m-d H:i:s');

            // Supersede prior active entries when a new credential is saved.
            foreach ($registry as &$entry) {
                if (($entry['status'] ?? '') === 'active') {
                    $entry['status'] = 'superseded';
                    $entry['updated_at'] = $now;
                }
            }
            unset($entry);

            if ($previousClientId !== '' && $previousClientId !== $clientId) {
                $foundPrev = false;
                foreach ($registry as &$entry) {
                    if (hash_equals((string) $entry['client_id'], $previousClientId)) {
                        $entry['status'] = 'superseded';
                        $entry['app_id'] = $previousAppId !== '' ? $previousAppId : $entry['app_id'];
                        $entry['updated_at'] = $now;
                        $foundPrev = true;
                    }
                }
                unset($entry);
                if (!$foundPrev) {
                    $registry[] = [
                        'client_id' => $previousClientId,
                        'app_id' => $previousAppId !== '' ? $previousAppId : $resolvedAppId,
                        'status' => 'superseded',
                        'updated_at' => $now,
                    ];
                }
            }

            $foundActive = false;
            foreach ($registry as &$entry) {
                if (hash_equals((string) $entry['client_id'], $clientId)) {
                    $entry['status'] = 'active';
                    $entry['app_id'] = $resolvedAppId;
                    $entry['updated_at'] = $now;
                    $foundActive = true;
                }
            }
            unset($entry);
            if (!$foundActive) {
                $registry[] = [
                    'client_id' => $clientId,
                    'app_id' => $resolvedAppId,
                    'status' => 'active',
                    'updated_at' => $now,
                ];
            }

            $activeCount = 0;
            foreach ($registry as $entry) {
                if (($entry['status'] ?? '') === 'active') {
                    $activeCount++;
                }
            }

            fs_connector_credentials_registry_save($registry);
            fs_connector_set_option('fs_conn_client_id', $clientId);
            fs_connector_set_option('fs_conn_client_secret_enc', $encrypted);
            fs_connector_set_option('fs_conn_app_id', $resolvedAppId);
            // Keep connector identity options aligned with installed site at save time.
            fs_connector_set_option('fs_conn_app_slug', (string) ($identity['requested_app_id'] ?? $resolvedAppId));
            if ((string) ($identity['source_domain'] ?? '') !== '') {
                fs_connector_set_option('fs_conn_domain', (string) $identity['source_domain']);
            }
            if ((string) ($identity['requested_app_name'] ?? '') !== '') {
                fs_connector_set_option('fs_conn_app_display_name', (string) $identity['requested_app_name']);
            }
            fs_connector_set_option('fs_conn_credentials_verified_at', $now);
            fs_connector_set_option('fsc_status', 'connected');

            $warning = '';
            if ($activeCount > 1) {
                $warning = 'Multiple active credential entries were found in local registry; only the newly saved Client ID is used for uploads.';
            }
            if (
                $previousAppId !== ''
                && $previousAppId !== $resolvedAppId
                && $previousClientId !== ''
                && $previousClientId === $clientId
            ) {
                $warning = trim($warning . ' Credential app_id was updated to match installed site identity (' . $resolvedAppId . ').');
            }

            return [
                'success' => true,
                'error' => '',
                'app_id' => $resolvedAppId,
                'warning' => $warning,
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'Files Service credentials could not be saved securely.'];
        }
    }
}

if (!function_exists('fs_connector_credentials_configured')) {
    function fs_connector_credentials_configured(): bool
    {
        return trim((string) fs_connector_option('fs_conn_client_id', '')) !== ''
            && trim((string) fs_connector_option('fs_conn_client_secret_enc', '')) !== '';
    }
}

if (!function_exists('fs_connector_get_credentials')) {
    /**
     * Active Files Service credential for this CMS installation.
     * app_id is always the identity bound at save / installed slug — never a hardcoded product id.
     *
     * @return array{app_id:string,client_id:string,client_secret:string,source_domain:string}
     */
    function fs_connector_get_credentials(): array
    {
        $clientId = trim((string) fs_connector_option('fs_conn_client_id', ''));
        $encryptedSecret = trim((string) fs_connector_option('fs_conn_client_secret_enc', ''));
        if ($clientId === '' || $encryptedSecret === '') {
            throw new RuntimeException('Files Service credentials are not configured.');
        }

        // Prefer the single active option pair; registry only informs diagnostics / conflicts.
        $registry = fs_connector_credentials_registry();
        $activeMatches = 0;
        $revokedActiveConflict = false;
        foreach ($registry as $entry) {
            if (($entry['status'] ?? '') === 'active') {
                $activeMatches++;
                if (!hash_equals((string) $entry['client_id'], $clientId)) {
                    // Another registry entry claims active; still use stored active options only.
                }
            }
            if (($entry['status'] ?? '') === 'revoked' && hash_equals((string) $entry['client_id'], $clientId)) {
                $revokedActiveConflict = true;
            }
        }
        if ($revokedActiveConflict) {
            throw new RuntimeException('Active Client ID is marked revoked in local registry. Reconnect with approved credentials.');
        }

        $identity = fs_connector_installed_identity();
        $appId = fs_connector_resolve_upload_app_id();
        $sourceDomain = fs_connector_normalize_hostname((string) ($identity['source_domain'] ?? ''));
        if ($sourceDomain === '') {
            $sourceDomain = fs_connector_normalize_hostname((string) ($identity['site_domain'] ?? ''));
        }

        return [
            'app_id' => $appId,
            'client_id' => $clientId,
            'client_secret' => fs_connector_decrypt_secret($encryptedSecret),
            'source_domain' => $sourceDomain,
            'active_registry_count' => $activeMatches,
        ];
    }
}

if (!function_exists('fs_connector_repair_identity')) {
    /**
     * Recompute connector identity options from installed site identity.
     * Does not delete credentials, mappings, or media. Does not rotate secrets.
     *
     * @return array{success:bool,message:string,warnings:list<string>,before:array,after:array}
     */
    function fs_connector_repair_identity(): array
    {
        $before = fs_connector_identity_diagnostics();

        // Fresh site options first (bypass stale connector option preference for recompute).
        $siteName = '';
        $siteSlug = '';
        $siteDomain = '';
        $canonical = '';
        if (function_exists('soi_option')) {
            try {
                $siteName = trim(strip_tags((string) soi_option('site_name', '')));
                $siteSlug = fs_connector_sanitize_app_id(soi_option('site_slug', ''), '');
                $siteDomain = fs_connector_normalize_hostname(soi_option('site_domain', ''));
                if (function_exists('soi_site_validate_base_url')) {
                    $canonical = soi_site_validate_base_url((string) soi_option('canonical_base_url', ''));
                    if ($canonical === '') {
                        $canonical = soi_site_validate_base_url((string) soi_option('site_url', ''));
                    }
                }
            } catch (Throwable $e) {
                // fall through
            }
        }
        if ($siteDomain === '' && $canonical !== '') {
            $siteDomain = fs_connector_normalize_hostname($canonical);
        }
        if ($siteDomain === '' && defined('SOI_HOME_URL')) {
            $siteDomain = fs_connector_normalize_hostname(SOI_HOME_URL);
        }
        if ($siteSlug === '' || $siteSlug === 'cms') {
            $fromDomain = $siteDomain !== '' ? explode('.', $siteDomain)[0] : 'cms';
            $siteSlug = fs_connector_sanitize_app_id($fromDomain, 'cms');
        }
        if ($siteName === '') {
            $siteName = defined('SOI_SITE_NAME') ? trim((string) SOI_SITE_NAME) : 'SOI CMS';
            if ($siteName === '') {
                $siteName = 'SOI CMS';
            }
        }

        fs_connector_set_option('fs_conn_domain', $siteDomain);
        fs_connector_set_option('fs_conn_app_slug', $siteSlug);
        fs_connector_set_option('fs_conn_app_display_name', substr($siteName, 0, 120));
        // Keep files URL on the platform Files Service host (not a CMS domain).
        fs_connector_set_option('fsc_files_url', FS_CONNECTOR_FILES_URL);

        $warnings = [];
        $rawCredentialAppId = trim((string) fs_connector_option('fs_conn_app_id', ''));
        $credentialAppId = $rawCredentialAppId !== '' ? fs_connector_sanitize_app_id($rawCredentialAppId, 'cms') : '';
        if ($credentialAppId !== '' && $credentialAppId !== $siteSlug) {
            $warnings[] = 'Existing credential is bound to app_id "' . $credentialAppId
                . '" but installed site identity is "' . $siteSlug
                . '". Reconnect this CMS to Files Service if uploads still fail with app ID mismatch.';
        }
        if (fs_connector_credentials_configured() && $credentialAppId === '') {
            // Legacy installs: bind active credential app_id to repaired identity without rotating secret.
            fs_connector_set_option('fs_conn_app_id', $siteSlug);
            $warnings[] = 'Legacy credential had no stored app_id; bound local credential app_id to "'
                . $siteSlug . '". If Files Service issued credentials under a different app id, reconnect.';
        }

        $after = fs_connector_identity_diagnostics();

        return [
            'success' => true,
            'message' => 'Files Service connector identity was recomputed from the installed site identity. Credentials and media were not deleted.',
            'warnings' => $warnings,
            'before' => $before,
            'after' => $after,
        ];
    }
}

if (!function_exists('fs_connector_identity_diagnostics')) {
    /**
     * Redacted identity diagnostic for admin UI. Never includes secrets or encrypted blobs.
     *
     * @return array<string,mixed>
     */
    function fs_connector_identity_diagnostics(): array
    {
        $identity = fs_connector_installed_identity();
        $clientId = trim((string) fs_connector_option('fs_conn_client_id', ''));
        $registry = fs_connector_credentials_registry();
        $activeCount = 0;
        $revokedCount = 0;
        $supersededCount = 0;
        foreach ($registry as $entry) {
            $st = (string) ($entry['status'] ?? '');
            if ($st === 'active') {
                $activeCount++;
            } elseif ($st === 'revoked') {
                $revokedCount++;
            } elseif ($st === 'superseded') {
                $supersededCount++;
            }
        }
        if ($activeCount === 0 && $clientId !== '') {
            $activeCount = 1;
        }

        $lastUploadRaw = trim((string) fs_connector_option('fs_conn_last_upload_identity', ''));
        $lastUpload = $lastUploadRaw !== '' ? json_decode($lastUploadRaw, true) : null;
        if (!is_array($lastUpload)) {
            $lastUpload = [];
        }

        return [
            'installed' => [
                'site_name' => (string) ($identity['site_name'] ?? ''),
                'site_slug' => (string) ($identity['site_slug'] ?? ''),
                'site_domain' => (string) ($identity['site_domain'] ?? ''),
                'canonical_url' => (string) ($identity['canonical_base_url'] ?? ''),
            ],
            'files_service' => [
                'source_domain' => (string) ($identity['source_domain'] ?? ''),
                'requested_app_id' => (string) ($identity['requested_app_id'] ?? ''),
                'requested_app_name' => (string) ($identity['requested_app_name'] ?? ''),
                'connection_status' => (string) ($identity['connection_status'] ?? 'not_connected'),
                'client_id_prefix' => fs_connector_client_id_prefix($clientId),
                'credential_app_id' => (string) ($identity['credential_app_id'] ?? ''),
                'active_credential_count' => $activeCount,
                'revoked_credential_count' => $revokedCount,
                'superseded_credential_count' => $supersededCount,
                'files_service_url' => (string) ($identity['files_service_url'] ?? FS_CONNECTOR_FILES_URL),
            ],
            'upload' => [
                'upload_endpoint' => rtrim((string) ($identity['files_service_url'] ?? FS_CONNECTOR_FILES_URL), '/') . '/api/files/upload',
                'source_domain_sent' => (string) ($lastUpload['source_domain'] ?? $identity['source_domain'] ?? ''),
                'app_id_sent' => (string) ($lastUpload['app_id'] ?? fs_connector_resolve_upload_app_id()),
                'requested_app_id' => (string) ($identity['requested_app_id'] ?? ''),
                'client_id_prefix_sent' => (string) ($lastUpload['client_id_prefix'] ?? fs_connector_client_id_prefix($clientId)),
                'last_upload_status' => (string) fs_connector_option('fs_conn_last_upload_status', ''),
                'last_upload_error' => (string) fs_connector_option('fs_conn_last_upload_error', ''),
                'last_upload_at' => (string) fs_connector_option('fs_conn_last_upload_at', ''),
            ],
            'identity_match' => [
                'source_domain_matches_site' => fs_connector_normalize_hostname((string) ($identity['source_domain'] ?? ''))
                    === fs_connector_normalize_hostname((string) ($identity['site_domain'] ?? '')),
                'app_id_matches_slug' => fs_connector_sanitize_app_id((string) ($identity['requested_app_id'] ?? ''), 'cms')
                    === fs_connector_sanitize_app_id((string) ($identity['site_slug'] ?? ''), 'cms'),
                'credential_app_matches_requested' => ($identity['credential_app_id'] ?? '') === ''
                    || fs_connector_sanitize_app_id((string) $identity['credential_app_id'], 'cms')
                        === fs_connector_sanitize_app_id((string) ($identity['requested_app_id'] ?? ''), 'cms'),
            ],
        ];
    }
}

if (!function_exists('fs_connector_record_upload_attempt')) {
    /**
     * Persist redacted last-upload identity for diagnostics (no secrets).
     */
    function fs_connector_record_upload_attempt(array $meta): void
    {
        $safe = [
            'source_domain' => fs_connector_normalize_hostname((string) ($meta['source_domain'] ?? '')),
            'app_id' => fs_connector_sanitize_app_id((string) ($meta['app_id'] ?? ''), 'cms'),
            'client_id_prefix' => fs_connector_client_id_prefix((string) ($meta['client_id'] ?? '')),
            'http_code' => (int) ($meta['http_code'] ?? 0),
            'success' => !empty($meta['success']),
            'at' => date('Y-m-d H:i:s'),
        ];
        fs_connector_set_option('fs_conn_last_upload_identity', json_encode($safe, JSON_UNESCAPED_SLASHES) ?: '{}');
        fs_connector_set_option('fs_conn_last_upload_status', !empty($meta['success']) ? 'success' : 'failed');
        fs_connector_set_option('fs_conn_last_upload_at', $safe['at']);
        $error = substr((string) ($meta['error'] ?? ''), 0, 500);
        // Strip any accidental secret-like substrings from stored error text.
        $error = preg_replace('/((?:client[_-]?secret|password|token)\s*[:=]\s*)\S+/i', '$1[redacted]', $error) ?? $error;
        fs_connector_set_option('fs_conn_last_upload_error', $error);
    }
}

if (!function_exists('fs_connector_friendly_upload_error')) {
    function fs_connector_friendly_upload_error(int $httpCode, string $safeResponse): string
    {
        $blob = strtolower($safeResponse);
        if (
            $httpCode === 401
            || str_contains($blob, 'invalid client id')
            || str_contains($blob, 'app id mismatch')
            || str_contains($blob, 'app_id mismatch')
        ) {
            return 'Files Service rejected the upload because the credential does not match the app identity sent by this CMS. '
                . 'Run Repair Files Service Identity, then reconnect this CMS to Files Service if required.';
        }

        return 'Files Service upload failed. HTTP ' . $httpCode . '. ' . $safeResponse;
    }
}

/**
 * Produce the only payload representation that may be persisted or rendered.
 * Install IDs and nonces are represented by presence flags, never raw values.
 */
if (!function_exists('fs_connector_request_diagnostics')) {
    function fs_connector_request_diagnostics(array $payload, array $context = []): array
    {
        $installPresent = !empty($payload['install_id'])
            || strtolower((string) ($payload['install_id_present'] ?? '')) === 'yes';
        $noncePresent = !empty($payload['nonce'])
            || strtolower((string) ($payload['nonce_present'] ?? '')) === 'yes';
        $sourceDomain = fs_connector_normalize_hostname($payload['source_domain'] ?? '');
        $cmsBaseUrl = $sourceDomain !== '' ? 'https://' . $sourceDomain : '';
        $timestamp = (string) ($payload['timestamp'] ?? '');
        $timestampEpoch = fs_connector_parse_timestamp_epoch($timestamp);
        $serverEpoch = null;
        if (isset($context['source_cms_server_epoch']) && is_numeric($context['source_cms_server_epoch'])) {
            $serverEpoch = (int) $context['source_cms_server_epoch'];
        } elseif (isset($payload['source_cms_server_epoch']) && is_numeric($payload['source_cms_server_epoch'])) {
            $serverEpoch = (int) $payload['source_cms_server_epoch'];
        }

        $sourceServerUtc = (string) ($payload['current_source_cms_server_utc'] ?? '');
        if ($sourceServerUtc === '' && $serverEpoch !== null) {
            $sourceServerUtc = fs_connector_utc_datetime($serverEpoch);
        }

        $timestampFormat = (string) (
            $context['timestamp_format_used']
            ?? $payload['timestamp_format_used']
            ?? fs_connector_detect_timestamp_format($timestamp)
        );

        $timestampAge = $payload['timestamp_age_seconds'] ?? null;
        if ($timestampAge === null && $serverEpoch !== null && $timestampEpoch !== null) {
            $timestampAge = $serverEpoch - $timestampEpoch;
        }

        $diagnostics = [
            'source_domain' => $sourceDomain,
            'cms_base_url' => $cmsBaseUrl,
            'callback_url' => $cmsBaseUrl !== '' ? $cmsBaseUrl . '/admin/files-service-connector.php?action=callback' : '',
            'return_url' => $cmsBaseUrl !== '' ? $cmsBaseUrl . '/admin/files-service-connector.php' : '',
            'requested_app_id' => (string) ($payload['requested_app_id'] ?? (
                function_exists('fs_connector_installed_identity')
                    ? (string) (fs_connector_installed_identity()['requested_app_id'] ?? 'cms')
                    : 'cms'
            )),
            'requested_entity_slug' => (string) ($payload['requested_entity_slug'] ?? ''),
            'requested_permissions' => fs_connector_normalize_permissions($payload['requested_permissions'] ?? []),
            'connector_version' => (string) ($payload['connector_version'] ?? FS_CONNECTOR_VERSION),
            'install_id_present' => $installPresent ? 'yes' : 'no',
            'timestamp' => $timestamp,
            'current_source_cms_server_utc' => $sourceServerUtc,
            'timestamp_format_used' => $timestampFormat,
            'timestamp_age_seconds' => $timestampAge === null ? '' : (string) $timestampAge,
            'nonce_present' => $noncePresent ? 'yes' : 'no',
        ];

        foreach (['files_service_utc', 'files_service_timestamp_skew_seconds', 'files_service_response'] as $optionalKey) {
            if (array_key_exists($optionalKey, $payload)) {
                $diagnostics[$optionalKey] = is_scalar($payload[$optionalKey]) ? (string) $payload[$optionalKey] : '';
            }
        }

        return $diagnostics;
    }
}

if (!function_exists('fs_connector_get_http_header')) {
    function fs_connector_get_http_header(string $headers, string $name): string
    {
        if ($headers === '' || $name === '') {
            return '';
        }

        preg_match_all('/^' . preg_quote($name, '/') . ':\s*(.+)$/mi', $headers, $matches);
        if (empty($matches[1])) {
            return '';
        }

        $value = trim((string) end($matches[1]));
        return preg_replace('/[\r\n]+/', ' ', $value) ?? '';
    }
}

if (!function_exists('fs_connector_redact_remote_value')) {
    function fs_connector_redact_remote_value(mixed $value, string $key = ''): mixed
    {
        if ($key !== '' && preg_match('/(?:secret|password|token|authorization|api[_-]?key|nonce|install[_-]?id)/i', $key)) {
            return '[redacted]';
        }

        if (!is_array($value)) {
            if (!is_string($value)) {
                return $value;
            }

            $value = preg_replace(
                '/((?:client[_-]?secret|password|access[_-]?token|authorization|api[_-]?key|nonce)\s*[:=]\s*)[^\s,;&]+/i',
                '$1[redacted]',
                $value
            ) ?? '';

            return preg_replace('#(?:/[A-Za-z0-9._-]+){2,}/[A-Za-z0-9._-]+\.(?:php|log|sql)(?::\d+)?#', '[server-path]', $value) ?? '';
        }

        $safe = [];
        foreach ($value as $childKey => $childValue) {
            $safe[$childKey] = fs_connector_redact_remote_value($childValue, (string) $childKey);
        }

        return $safe;
    }
}

if (!function_exists('fs_connector_safe_remote_response')) {
    function fs_connector_safe_remote_response(mixed $response): string
    {
        $response = is_string($response) ? $response : '';
        if ($response === '') {
            return '(empty response)';
        }

        $decoded = json_decode($response, true);
        if (is_array($decoded)) {
            $encoded = json_encode(
                fs_connector_redact_remote_value($decoded),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            );
            return $encoded !== false ? $encoded : '(unreadable JSON response)';
        }

        $safe = strip_tags($response);
        $safe = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $safe) ?? '';
        $safe = preg_replace(
            '/((?:client[_-]?secret|password|access[_-]?token|authorization|api[_-]?key|nonce)\s*[:=]\s*)[^\s,;&]+/i',
            '$1[redacted]',
            $safe
        ) ?? '';
        $safe = preg_replace('#(?:/[A-Za-z0-9._-]+){2,}/[A-Za-z0-9._-]+\.(?:php|log|sql)(?::\d+)?#', '[server-path]', $safe) ?? '';

        return trim($safe) !== '' ? trim($safe) : '(empty response)';
    }
}

require_once __DIR__ . '/FileServiceMediaAdapter.php';
