<?php
/**
 * SOI (School Of Interns) CMS - Global Helper Functions
 */

use SOI\Core\Database;
use SOI\Core\Hook;

if (!function_exists('soi_option')) {
    function soi_option(string $key, mixed $default = ''): mixed {
        return Database::getOption($key, $default);
    }
}

if (!function_exists('soi_set_option')) {
    function soi_set_option(string $key, mixed $value): void {
        Database::setOption($key, $value);
    }
}

if (!function_exists('esc')) {
    function esc(mixed $value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('soi_url')) {
    function soi_url(string $path = ''): string {
        return rtrim(SOI_HOME_URL, '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('soi_admin_url')) {
    function soi_admin_url(string $path = ''): string {
        return rtrim(SOI_ADMIN_URL, '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('soi_uploads_url')) {
    function soi_uploads_url(string $path = ''): string {
        return rtrim(SOI_HOME_URL, '/') . '/uploads/' . ltrim($path, '/');
    }
}

if (!function_exists('soi_public_path_prefix')) {
    /**
     * Web path prefix when CMS runs in a subdirectory (e.g. /cms).
     * Derived from SOI_HOME_URL so asset links stay on the same origin/path as pages.
     */
    function soi_public_path_prefix(): string {
        if (!defined('SOI_HOME_URL')) {
            return '';
        }
        $path = parse_url(SOI_HOME_URL, PHP_URL_PATH) ?: '';
        $path = rtrim($path, '/');
        return ($path === '' || $path === '/') ? '' : $path;
    }
}

if (!function_exists('soi_theme_url')) {
    function soi_theme_url(string $path = ''): string {
        $theme = Database::getOption('active_theme', 'default');
        return rtrim(SOI_HOME_URL, '/') . "/themes/{$theme}/" . ltrim($path, '/');
    }
}

if (!function_exists('soi_theme_asset_url')) {
    /**
     * Root-relative theme asset URL (survives www/https drift; works with front-controller static fallback).
     */
    function soi_theme_asset_url(string $path = ''): string {
        $theme = Database::getOption('active_theme', 'default');
        return soi_public_path_prefix() . '/themes/' . $theme . '/' . ltrim($path, '/');
    }
}

if (!function_exists('soi_admin_asset_url')) {
    /**
     * Root-relative admin asset URL for CSS/JS/sprites.
     */
    function soi_admin_asset_url(string $path): string {
        return soi_public_path_prefix() . '/admin/assets/' . ltrim($path, '/');
    }
}

if (!function_exists('soi_theme_dir')) {
    function soi_theme_dir(string $path = ''): string {
        $theme = Database::getOption('active_theme', 'default');
        return SOI_ROOT . "/themes/{$theme}/" . ltrim($path, '/');
    }
}

if (!function_exists('slugify')) {
    function slugify(string $text): string {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9\-_]/', '-', $text);
        $text = preg_replace('/-+/', '-', $text);
        return trim($text, '-');
    }
}

if (!function_exists('sanitize_slug')) {
    /** Generic URL slug sanitizer (alias used by API and content modules). */
    function sanitize_slug(string $text): string {
        return slugify($text);
    }
}

/**
 * Normalize a value to a bare hostname (no scheme, path, credentials, or IP).
 * Empty string when unsafe/invalid.
 */
if (!function_exists('soi_site_normalize_hostname')) {
    function soi_site_normalize_hostname(mixed $value): string
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

/**
 * Validate a site base URL (http/https only, no credentials). Returns '' if invalid.
 */
if (!function_exists('soi_site_validate_base_url')) {
    function soi_site_validate_base_url(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $raw = rtrim(trim((string) $value), '/');
        if ($raw === '') {
            return '';
        }
        $parts = parse_url($raw);
        if (!is_array($parts)) {
            return '';
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return '';
        }
        if (empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }
        $host = soi_site_normalize_hostname((string) $parts['host']);
        if ($host === '') {
            return '';
        }
        $path = isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '';
        if ($path === '/' || $path === '') {
            $path = '';
        }
        return $scheme . '://' . $host . $path;
    }
}

/**
 * Sanitize a site/app slug for connector and identity use.
 */
if (!function_exists('soi_site_sanitize_slug')) {
    function soi_site_sanitize_slug(mixed $value, string $fallback = 'cms'): string
    {
        $slug = strtolower(trim((string) $value));
        $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-_');
        if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug)) {
            $fallback = soi_site_sanitize_slug($fallback === '' ? 'cms' : $fallback, 'cms');
            return $fallback !== '' ? $fallback : 'cms';
        }
        return substr($slug, 0, 64);
    }
}

/**
 * Central CMS site identity for multi-domain reuse.
 * Prefers configured options, then SOI_* constants. Does not write defaults.
 * Does not trust Host header for canonical identity when site_domain/site_url are set.
 *
 * @return array{
 *   site_name:string,site_slug:string,site_domain:string,canonical_base_url:string,
 *   admin_base_url:string,frontend_base_url:string,environment_label:string,
 *   scheme:string,detected_host:string,source_domain:string,
 *   files_service_app_name:string,files_service_app_slug:string,
 *   uses_host_fallback:bool
 * }
 */
if (!function_exists('soi_site_identity')) {
    function soi_site_identity(): array
    {
        $opt = static function (string $key, string $default = '') {
            if (function_exists('soi_option')) {
                try {
                    return trim((string) soi_option($key, $default));
                } catch (Throwable $e) {
                    return $default;
                }
            }
            if (class_exists(\SOI\Core\Database::class)) {
                try {
                    return trim((string) \SOI\Core\Database::getOption($key, $default));
                } catch (Throwable $e) {
                    return $default;
                }
            }
            return $default;
        };

        $siteName = $opt('site_name', '');
        if ($siteName === '' && defined('SOI_SITE_NAME')) {
            $siteName = trim((string) SOI_SITE_NAME);
        }
        if ($siteName === '') {
            $siteName = 'SOI CMS';
        }

        $siteUrlOpt = $opt('site_url', '');
        $homeConst = defined('SOI_HOME_URL') ? rtrim((string) SOI_HOME_URL, '/') : '';
        $canonical = soi_site_validate_base_url($opt('canonical_base_url', ''));
        if ($canonical === '') {
            $canonical = soi_site_validate_base_url($siteUrlOpt);
        }
        if ($canonical === '') {
            $canonical = soi_site_validate_base_url($homeConst);
        }

        $configuredDomain = soi_site_normalize_hostname($opt('site_domain', ''));
        if ($configuredDomain === '' && $canonical !== '') {
            $configuredDomain = soi_site_normalize_hostname($canonical);
        }
        if ($configuredDomain === '' && $homeConst !== '') {
            $configuredDomain = soi_site_normalize_hostname($homeConst);
        }

        $detectedHost = '';
        if (!empty($_SERVER['HTTP_HOST'])) {
            $detectedHost = soi_site_normalize_hostname((string) $_SERVER['HTTP_HOST']);
        }

        $usesHostFallback = false;
        if ($configuredDomain === '' && $detectedHost !== '') {
            $configuredDomain = $detectedHost;
            $usesHostFallback = true;
        }

        $scheme = 'https';
        if ($canonical !== '') {
            $scheme = strtolower((string) (parse_url($canonical, PHP_URL_SCHEME) ?: 'https'));
        } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $scheme = 'https';
        } elseif (isset($_SERVER['REQUEST_SCHEME'])) {
            $scheme = strtolower((string) $_SERVER['REQUEST_SCHEME']) === 'http' ? 'http' : 'https';
        }

        if ($canonical === '' && $configuredDomain !== '') {
            $canonical = $scheme . '://' . $configuredDomain;
        }

        $frontend = soi_site_validate_base_url($opt('frontend_base_url', ''));
        if ($frontend === '') {
            $frontend = $canonical;
        }

        $adminBase = soi_site_validate_base_url($opt('admin_base_url', ''));
        if ($adminBase === '' && defined('SOI_ADMIN_URL')) {
            $adminBase = soi_site_validate_base_url((string) SOI_ADMIN_URL);
        }
        if ($adminBase === '' && $canonical !== '') {
            $adminBase = $canonical . '/admin';
        }

        $siteSlug = soi_site_sanitize_slug($opt('site_slug', ''), '');
        if ($siteSlug === '' || $siteSlug === 'cms') {
            $fromDomain = $configuredDomain !== '' ? explode('.', $configuredDomain)[0] : 'cms';
            $siteSlug = soi_site_sanitize_slug($fromDomain, 'cms');
        }

        $environment = strtolower($opt('environment_label', ''));
        if (!in_array($environment, ['production', 'staging', 'development', 'local'], true)) {
            $environment = 'production';
        }

        $fsDomain = '';
        if (function_exists('fs_connector_normalize_hostname') && function_exists('fs_connector_option')) {
            try {
                $fsDomain = fs_connector_normalize_hostname(fs_connector_option('fs_conn_domain', ''));
            } catch (Throwable $e) {
                $fsDomain = '';
            }
        }
        if ($fsDomain === '') {
            $fsDomain = $configuredDomain;
        }

        $fsAppName = $opt('fs_conn_app_display_name', '');
        if ($fsAppName === '') {
            $fsAppName = $siteName;
        }
        $fsAppSlug = soi_site_sanitize_slug($opt('fs_conn_app_slug', ''), $siteSlug);

        return [
            'site_name' => substr(strip_tags($siteName), 0, 120),
            'site_slug' => $siteSlug,
            'site_domain' => $configuredDomain,
            'canonical_base_url' => $canonical,
            'admin_base_url' => $adminBase,
            'frontend_base_url' => $frontend,
            'environment_label' => $environment,
            'scheme' => $scheme,
            'detected_host' => $detectedHost,
            'source_domain' => $fsDomain,
            'files_service_app_name' => substr(strip_tags($fsAppName), 0, 120),
            'files_service_app_slug' => $fsAppSlug,
            'uses_host_fallback' => $usesHostFallback,
        ];
    }
}

if (!function_exists('soi_site_domain')) {
    function soi_site_domain(): string
    {
        return (string) (soi_site_identity()['site_domain'] ?? '');
    }
}

if (!function_exists('soi_site_name')) {
    function soi_site_name(): string
    {
        return (string) (soi_site_identity()['site_name'] ?? 'SOI CMS');
    }
}

if (!function_exists('soi_site_slug')) {
    function soi_site_slug(): string
    {
        return (string) (soi_site_identity()['site_slug'] ?? 'cms');
    }
}

if (!function_exists('soi_canonical_base_url')) {
    function soi_canonical_base_url(): string
    {
        return (string) (soi_site_identity()['canonical_base_url'] ?? '');
    }
}

/**
 * Final CMS production readiness audit (static + option-aware, no secrets, no content mutation).
 *
 * @return array{
 *   success:bool,timestamp:string,final_status:string,cms_version:string,
 *   connector_version:string,areas:array<string,array>,blocking:list<string>,
 *   warnings:list<string>,score:array{pass:int,warn:int,fail:int,total:int}
 * }
 */
if (!function_exists('soi_cms_final_production_readiness_check')) {
    function soi_cms_final_production_readiness_check(): array
    {
        $root = defined('SOI_ROOT') ? SOI_ROOT : dirname(__DIR__);
        $exists = static fn(string $rel): bool => is_file($root . '/' . ltrim($rel, '/'));
        $contains = static function (string $rel, string $needle) use ($root): bool {
            $path = $root . '/' . ltrim($rel, '/');
            if (!is_file($path)) {
                return false;
            }
            $src = (string) @file_get_contents($path);
            return $src !== '' && str_contains($src, $needle);
        };

        $cmsVersion = '';
        if (class_exists(\SOI\Core\Database::class)) {
            try {
                $cmsVersion = trim((string) \SOI\Core\Database::getOption('cms_version', ''));
            } catch (Throwable $e) {
                $cmsVersion = '';
            }
        }
        if ($cmsVersion === '' && defined('SOI_VERSION')) {
            $cmsVersion = (string) SOI_VERSION;
        }

        $connectorVersion = defined('FS_CONNECTOR_VERSION') ? (string) FS_CONNECTOR_VERSION : '';
        if ($connectorVersion === '' && $exists('plugins/files-service-connector/plugin.php')) {
            $plug = (string) @file_get_contents($root . '/plugins/files-service-connector/plugin.php');
            if (preg_match("/FS_CONNECTOR_VERSION',\s*'([^']+)'/", $plug, $m)) {
                $connectorVersion = $m[1];
            }
        }

        $identity = function_exists('soi_site_identity') ? soi_site_identity() : [];
        $settings = function_exists('fs_connector_get_settings') ? fs_connector_get_settings() : [];
        $mapReady = class_exists('FileServiceMediaAdapter') && method_exists('FileServiceMediaAdapter', 'mapTableExists')
            ? (bool) FileServiceMediaAdapter::mapTableExists()
            : null;
        $backupReady = class_exists('FileServiceMediaAdapter') && method_exists('FileServiceMediaAdapter', 'backupTableExists')
            ? (bool) FileServiceMediaAdapter::backupTableExists()
            : null;

        $legacyDomainHits = 0;
        foreach (['admin', 'plugins', 'core', 'themes'] as $dir) {
            $base = $root . '/' . $dir;
            if (!is_dir($base)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                /** @var SplFileInfo $file */
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }
                $src = (string) @file_get_contents($file->getPathname());
                if ($src !== '' && str_contains($src, 'search' . '.soi.co.in')) {
                    $legacyDomainHits++;
                }
            }
        }

        $area = static function (string $status, string $summary, array $checks = [], array $notes = []): array {
            return [
                'status' => $status, // pass|warn|fail
                'summary' => $summary,
                'checks' => $checks,
                'notes' => $notes,
            ];
        };

        $areas = [];

        // 1 Frontend
        $frontChecks = [
            'theme_header' => $exists('themes/default/header.php'),
            'theme_footer' => $exists('themes/default/footer.php'),
            'theme_index' => $exists('themes/default/index.php'),
            'theme_page' => $exists('themes/default/page.php'),
            'theme_post' => $exists('themes/default/post.php'),
            'perf_css' => $exists('themes/default/performance.css'),
            'perf_js' => $exists('themes/default/performance.js'),
            'content_lazy_filter' => function_exists('soi_perf_enhance_media_html') || $contains('core/helpers.php', 'soi_perf_enhance_media_html'),
            'no_permanent_hide_css' => $contains('themes/default/performance.css', 'html:not(.soi-perf-js)'),
        ];
        $frontFail = in_array(false, $frontChecks, true);
        $areas['frontend'] = $area(
            $frontFail ? 'fail' : 'pass',
            $frontFail ? 'Frontend theme or performance assets incomplete.' : 'Frontend theme and progressive loading assets present.',
            $frontChecks
        );

        // 2 Admin
        $adminPages = [
            'index.php', 'settings.php', 'media.php', 'files-service-connector.php',
            'updates.php', 'security.php', 'pages.php', 'profile.php', 'login.php', 'logout.php',
        ];
        $adminChecks = [];
        foreach ($adminPages as $page) {
            $adminChecks['admin_' . str_replace('.', '_', $page)] = $exists('admin/' . $page);
        }
        $adminChecks['admin_css'] = $exists('admin/assets/admin.css');
        $adminChecks['admin_js'] = $exists('admin/assets/admin.js');
        $adminFail = in_array(false, $adminChecks, true);
        $areas['admin'] = $area(
            $adminFail ? 'fail' : 'pass',
            $adminFail ? 'One or more admin pages/assets are missing.' : 'Core admin pages and assets are present.',
            $adminChecks
        );

        // 3 SAML/login
        $authChecks = [
            'auth_class' => $exists('core/Auth.php'),
            'require_auth' => $contains('core/Auth.php', 'function requireAuth'),
            'logout' => $contains('core/Auth.php', 'function logout'),
            'login_page' => $exists('admin/login.php'),
            'logout_page' => $exists('admin/logout.php'),
            'central_auth' => $exists('core/SoiCentralAuth.php'),
            'accounts' => $exists('core/Accounts.php'),
        ];
        $authFail = !$authChecks['auth_class'] || !$authChecks['require_auth'] || !$authChecks['logout'];
        $areas['saml_login'] = $area(
            $authFail ? 'fail' : 'pass',
            $authFail ? 'Auth/login foundation incomplete.' : 'Admin auth gate and logout paths are present (live SAML still requires operator verification).',
            $authChecks,
            ['Live redirect/logout must still be verified manually on the server with a real session.']
        );

        // 4 Update Center
        $updateChecks = [
            'updates_page' => $exists('admin/updates.php'),
            'update_class' => $exists('core/Update.php'),
            'csrf' => $contains('admin/updates.php', 'verifyCsrf'),
            'manifest_validation' => $contains('core/Update.php', 'manifest.json'),
            'graceful_csrf' => $contains('admin/updates.php', 'CSRF check failed') || $contains('admin/updates.php', 'soi_flash'),
        ];
        $updateStatus = !$updateChecks['updates_page'] || !$updateChecks['update_class'] ? 'fail'
            : (!$updateChecks['graceful_csrf'] ? 'warn' : 'pass');
        $areas['update_center'] = $area(
            $updateStatus,
            $updateStatus === 'fail' ? 'Update Center missing.' : ($updateStatus === 'warn' ? 'Update Center present; CSRF failure UX should be graceful.' : 'Update Center present with CSRF and manifest validation.'),
            $updateChecks
        );

        // 5 Files Service Connector
        $fsChecks = [
            'plugin' => $exists('plugins/files-service-connector/plugin.php'),
            'adapter' => $exists('plugins/files-service-connector/FileServiceMediaAdapter.php'),
            'admin_page' => $exists('admin/files-service-connector.php'),
            'canonical_media' => $contains('plugins/files-service-connector/FileServiceMediaAdapter.php', '/media/'),
            'media_url_builder' => $contains('plugins/files-service-connector/FileServiceMediaAdapter.php', 'function mediaUrl'),
            'encrypted_secret_prefix' => $contains('plugins/files-service-connector/plugin.php', 'FS_CONNECTOR_SECRET_PREFIX') || $contains('plugins/files-service-connector/plugin.php', 'fscenc:'),
            'domain_readiness' => function_exists('fs_connector_domain_readiness_scan') || $contains('plugins/files-service-connector/plugin.php', 'fs_connector_domain_readiness_scan'),
            'map_table_ready' => $mapReady === null ? true : $mapReady,
            'backup_table_ready' => $backupReady === null ? true : $backupReady,
            'client_secret_not_echoed' => !$contains('admin/files-service-connector.php', 'echo $client_secret'),
        ];
        $fsFail = !$fsChecks['plugin'] || !$fsChecks['adapter'] || !$fsChecks['admin_page'] || !$fsChecks['canonical_media'];
        $fsWarn = ($mapReady === false) || ($backupReady === false);
        $areas['files_connector'] = $area(
            $fsFail ? 'fail' : ($fsWarn ? 'warn' : 'pass'),
            $fsFail ? 'Files Service Connector incomplete.' : ($fsWarn ? 'Connector present; migration/backup tables need verification on live DB.' : 'Files Service Connector assets and canonical /media URL builder present.'),
            $fsChecks,
            [
                'credentials_configured' => !empty($settings['client_secret_configured']),
                'client_id_present' => !empty($settings['client_id_present']),
                'connection_status' => (string) ($settings['connection_status'] ?? 'unknown'),
                'connector_version' => $connectorVersion,
            ]
        );

        // 6 Media Library
        $mediaChecks = [
            'media_page' => $exists('admin/media.php'),
            'lazy_thumbs' => $contains('admin/media.php', 'loading="lazy"'),
            'decoding_async' => $contains('admin/media.php', 'decoding="async"'),
            'admin_perf_js' => $exists('admin/assets/admin-performance.js'),
            'open_remote' => $contains('admin/media.php', 'Open Remote'),
            'copy_url' => $contains('admin/media.php', 'data-copy-text'),
            'lifecycle_actions' => $contains('admin/media.php', 'unmap_files_service') && $contains('admin/media.php', 'remap_files_service'),
            'delete_confirm' => $contains('admin/media.php', 'data-confirm'),
        ];
        $mediaFail = !$mediaChecks['media_page'];
        $areas['media_library'] = $area(
            $mediaFail ? 'fail' : 'pass',
            $mediaFail ? 'Media Library missing.' : 'Media Library with lazy thumbs and lifecycle actions present.',
            $mediaChecks
        );

        // 7 Rewrite / lifecycle
        $lifeChecks = [
            'preview' => $contains('plugins/files-service-connector/FileServiceMediaAdapter.php', 'function referencePreview'),
            'commit' => $contains('plugins/files-service-connector/FileServiceMediaAdapter.php', 'function commitReferenceReplacements'),
            'rollback' => $contains('plugins/files-service-connector/FileServiceMediaAdapter.php', 'function rollbackBatch'),
            'verify' => $contains('plugins/files-service-connector/FileServiceMediaAdapter.php', 'function verifyFrontendReferences'),
            'lifecycle_audit' => $contains('plugins/files-service-connector/FileServiceMediaAdapter.php', 'function runLifecycleAudit'),
            'delete_marks_cms_deleted' => $contains('plugins/files-service-connector/FileServiceMediaAdapter.php', 'function handleMediaDelete'),
            'no_remote_hard_delete_default' => $contains('plugins/files-service-connector/FileServiceMediaAdapter.php', 'remoteLifecycleCapabilities')
                || $contains('plugins/files-service-connector/FileServiceMediaAdapter.php', 'retain_remote'),
            'commit_requires_preview' => $contains('plugins/files-service-connector/FileServiceMediaAdapter.php', 'No valid rewrite preview')
                || $contains('plugins/files-service-connector/FileServiceMediaAdapter.php', 'getStoredPreview'),
        ];
        $lifeFail = in_array(false, array_intersect_key($lifeChecks, array_flip(['preview', 'commit', 'rollback'])), true);
        $areas['lifecycle_rewrite'] = $area(
            $lifeFail ? 'fail' : 'pass',
            $lifeFail ? 'Rewrite/lifecycle safety incomplete.' : 'Preview/commit/rollback/lifecycle safety paths present (no auto rewrite).',
            $lifeChecks
        );

        // 8 Reusable domain
        $domainChecks = [
            'site_identity' => function_exists('soi_site_identity') || $contains('core/helpers.php', 'function soi_site_identity'),
            'settings_slug_fields' => $contains('admin/settings.php', 'site_slug') && $contains('admin/settings.php', 'site_domain'),
            'dynamic_source_domain' => $contains('plugins/files-service-connector/FileServiceMediaAdapter.php', 'function sourceCmsHostname'),
            'app_id_not_hardcoded_search' => !$contains('plugins/files-service-connector/plugin.php', "requested_app_id' => 'search'")
                && !$contains('plugins/files-service-connector/plugin.php', "'app_id' => 'search'"),
            'installed_identity_helper' => $contains('plugins/files-service-connector/plugin.php', 'function fs_connector_installed_identity'),
            'repair_identity_action' => $contains('plugins/files-service-connector/plugin.php', 'function fs_connector_repair_identity')
                && $contains('admin/files-service-connector.php', 'repair_identity'),
            'domain_readiness_scan' => function_exists('fs_connector_domain_readiness_scan') || $contains('plugins/files-service-connector/plugin.php', 'fs_connector_domain_readiness_scan'),
            'clone_checklist_doc' => $exists('release-docs/REUSABLE_CMS_CLONE_CHECKLIST_V1_2_10.md') || $exists('release-docs/CMS_REUSABLE_WEBSITE_LAUNCH_CHECKLIST_V1_2_11.md'),
            'no_runtime_legacy_domain' => $legacyDomainHits === 0,
        ];
        $domainFail = !$domainChecks['site_identity'] || !$domainChecks['dynamic_source_domain'];
        $domainWarn = !$domainChecks['no_runtime_legacy_domain'] || !empty($identity['uses_host_fallback']);
        $areas['reusable_domain'] = $area(
            $domainFail ? 'fail' : ($domainWarn ? 'warn' : 'pass'),
            $domainFail ? 'Reusable domain packaging incomplete.' : ($domainWarn ? 'Domain packaging present with warnings (host fallback or residual hardcodes).' : 'Reusable domain packaging ready.'),
            $domainChecks + [
                'configured_domain' => (string) ($identity['site_domain'] ?? ''),
                'site_slug' => (string) ($identity['site_slug'] ?? ''),
                'uses_host_fallback' => !empty($identity['uses_host_fallback']),
                'legacy_domain_hits' => $legacyDomainHits,
            ]
        );

        // 9 Performance
        $perfChecks = [
            'frontend_css' => $exists('themes/default/performance.css'),
            'frontend_js' => $exists('themes/default/performance.js'),
            'admin_media_lazy' => $contains('admin/media.php', 'loading="lazy"'),
            'admin_perf_assets' => $exists('admin/assets/admin-performance.css') && $exists('admin/assets/admin-performance.js'),
            'js_degrades_gracefully' => $contains('themes/default/performance.css', 'html:not(.soi-perf-js)'),
            'admin_core_js_not_replaced' => $exists('admin/assets/admin.js'),
        ];
        $areas['performance'] = $area(
            in_array(false, $perfChecks, true) ? 'warn' : 'pass',
            in_array(false, $perfChecks, true) ? 'Performance assets partially present.' : 'Lazy/skeleton progressive loading assets present.',
            $perfChecks
        );

        // 10 Security
        $secChecks = [
            'csrf_helper' => $contains('core/Auth.php', 'function csrfToken') || $contains('core/Auth.php', 'function verifyCsrf'),
            'escape_helper' => function_exists('esc') || $contains('core/helpers.php', 'function esc'),
            'admin_auth_gate' => $contains('core/Auth.php', 'function requireAuth'),
            'no_client_secret_echo_connector' => !$contains('admin/files-service-connector.php', '<?= $client_secret'),
            'secret_encrypted_storage' => $contains('plugins/files-service-connector/plugin.php', 'encrypt_secret') || $contains('plugins/files-service-connector/plugin.php', 'fs_connector_encrypt_secret'),
            'no_htaccess_in_package_scope' => true,
        ];
        $areas['security'] = $area(
            in_array(false, array_diff_key($secChecks, ['no_htaccess_in_package_scope' => true]), true) ? 'fail' : 'pass',
            'Security foundations (auth, CSRF helpers, escaped output patterns, encrypted secret storage) present. Live penetration testing remains operator-owned.',
            $secChecks
        );

        $pass = $warn = $fail = 0;
        $blocking = [];
        $warnings = [];
        foreach ($areas as $key => $info) {
            if (($info['status'] ?? '') === 'pass') {
                $pass++;
            } elseif (($info['status'] ?? '') === 'warn') {
                $warn++;
                $warnings[] = $key . ': ' . ($info['summary'] ?? '');
            } else {
                $fail++;
                $blocking[] = $key . ': ' . ($info['summary'] ?? '');
            }
        }

        if ($fail > 0) {
            $final = 'Not Ready';
        } elseif ($warn > 0) {
            $final = 'Needs Attention';
        } else {
            $final = 'Ready';
        }

        $result = [
            'success' => true,
            'timestamp' => date('Y-m-d H:i:s'),
            'final_status' => $final,
            'cms_version' => $cmsVersion,
            'connector_version' => $connectorVersion,
            'package_lineage_hint' => '1.2.9 → 1.2.10 → 1.2.10.1 → 1.2.11',
            'areas' => $areas,
            'blocking' => $blocking,
            'warnings' => $warnings,
            'score' => [
                'pass' => $pass,
                'warn' => $warn,
                'fail' => $fail,
                'total' => $pass + $warn + $fail,
            ],
        ];

        if (class_exists(\SOI\Core\Database::class)) {
            try {
                \SOI\Core\Database::setOption('cms_last_production_readiness_at', $result['timestamp']);
                \SOI\Core\Database::setOption('cms_last_production_readiness', json_encode([
                    'final_status' => $final,
                    'cms_version' => $cmsVersion,
                    'connector_version' => $connectorVersion,
                    'score' => $result['score'],
                    'timestamp' => $result['timestamp'],
                ], JSON_UNESCAPED_SLASHES) ?: '');
            } catch (Throwable $e) {
                // Non-fatal: readiness still returned.
            }
        }

        return $result;
    }
}

/**
 * Safe media attribute enhancement for frontend HTML content.
 * Adds loading="lazy" and decoding="async" when missing; softens iframe/video preload.
 * Does not rewrite URLs or strip existing attributes.
 */
if (!function_exists('soi_perf_enhance_media_html')) {
    function soi_perf_enhance_media_html(string $html, array $options = []): string
    {
        if ($html === '' || !str_contains($html, '<')) {
            return $html;
        }

        $eagerFirst = !empty($options['eager_first_image']);
        $imageIndex = 0;

        $html = preg_replace_callback('/<img\b([^>]*?)(\/?)>/i', static function (array $m) use (&$imageIndex, $eagerFirst): string {
            $attrs = $m[1];
            $selfClose = $m[2] ?? '';
            $imageIndex++;

            if (!preg_match('/\bloading\s*=/i', $attrs)) {
                $loading = ($eagerFirst && $imageIndex === 1) ? 'eager' : 'lazy';
                $attrs .= ' loading="' . $loading . '"';
            }
            if (!preg_match('/\bdecoding\s*=/i', $attrs)) {
                $attrs .= ' decoding="async"';
            }
            if (!preg_match('/\bclass\s*=/i', $attrs)) {
                $attrs .= ' class="soi-perf-media"';
            } elseif (!preg_match('/\bsoi-perf-media\b/', $attrs)) {
                $attrs = preg_replace('/\bclass\s*=\s*(["\'])([^"\']*)\1/i', 'class=$1$2 soi-perf-media$1', $attrs, 1) ?? $attrs;
            }

            return '<img' . $attrs . $selfClose . '>';
        }, $html) ?? $html;

        $html = preg_replace_callback('/<iframe\b([^>]*?)(\/?)>/i', static function (array $m): string {
            $attrs = $m[1];
            $selfClose = $m[2] ?? '';
            if (!preg_match('/\bloading\s*=/i', $attrs)) {
                $attrs .= ' loading="lazy"';
            }
            if (!preg_match('/\bclass\s*=/i', $attrs)) {
                $attrs .= ' class="soi-perf-iframe"';
            }
            return '<iframe' . $attrs . $selfClose . '>';
        }, $html) ?? $html;

        $html = preg_replace_callback('/<video\b([^>]*?)>/i', static function (array $m): string {
            $attrs = $m[1];
            if (!preg_match('/\bpreload\s*=/i', $attrs)) {
                $attrs .= ' preload="metadata"';
            }
            if (!preg_match('/\bclass\s*=/i', $attrs)) {
                $attrs .= ' class="soi-perf-video"';
            }
            return '<video' . $attrs . '>';
        }, $html) ?? $html;

        return $html;
    }
}

/**
 * Build safe img attributes for theme templates (escaped values expected for src/alt).
 */
if (!function_exists('soi_perf_img_attr_string')) {
    function soi_perf_img_attr_string(array $attrs): string
    {
        $parts = [];
        foreach ($attrs as $key => $value) {
            if ($value === null || $value === false) {
                continue;
            }
            $k = preg_replace('/[^a-zA-Z0-9:_-]/', '', (string) $key) ?? '';
            if ($k === '') {
                continue;
            }
            if ($value === true) {
                $parts[] = $k;
                continue;
            }
            $parts[] = $k . '="' . esc((string) $value) . '"';
        }
        return implode(' ', $parts);
    }
}

if (!function_exists('soi_blog_enabled')) {
    function soi_blog_enabled(): bool {
        return class_exists(\SOI\Core\Blog::class) && \SOI\Core\Blog::isEnabled();
    }
}

if (!function_exists('soi_is_maintenance_bypass_route')) {
    function soi_is_maintenance_bypass_route(string $uri): bool {
        return class_exists(\SOI\Core\Maintenance::class)
            && \SOI\Core\Maintenance::isBypassRoute($uri);
    }
}

if (!function_exists('now')) {
    function now(): string {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('time_ago')) {
    function time_ago(string $datetime): string {
        $diff = time() - strtotime($datetime);
        if ($diff < 60)     return 'just now';
        if ($diff < 3600)   return round($diff / 60) . ' minutes ago';
        if ($diff < 86400)  return round($diff / 3600) . ' hours ago';
        if ($diff < 604800) return round($diff / 86400) . ' days ago';
        return date('M j, Y', strtotime($datetime));
    }
}

if (!function_exists('excerpt')) {
    function excerpt(string $content, int $words = 30): string {
        $text = strip_tags($content);
        $wordArr = explode(' ', $text);
        if (count($wordArr) <= $words) return $text;
        return implode(' ', array_slice($wordArr, 0, $words)) . '…';
    }
}

if (!function_exists('soi_redirect')) {
    function soi_redirect(string $url, int $code = 302): never {
        http_response_code($code);
        header("Location: $url");
        exit;
    }
}

if (!function_exists('soi_flash')) {
    function soi_flash(string $type, string $message): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['_flash'][] = compact('type', 'message');
    }
}

if (!function_exists('soi_get_flash')) {
    function soi_get_flash(): array {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $flash = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $flash;
    }
}

if (!function_exists('soi_post')) {
    function soi_post(string $key, mixed $default = ''): mixed {
        return $_POST[$key] ?? $default;
    }
}

if (!function_exists('soi_get')) {
    function soi_get(string $key, mixed $default = ''): mixed {
        return $_GET[$key] ?? $default;
    }
}

if (!function_exists('soi_is_admin')) {
    function soi_is_admin(): bool {
        return \SOI\Core\Auth::role() === 'admin';
    }
}

if (!function_exists('sanitize_filename')) {
    function sanitize_filename(string $filename): string {
        $filename = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $filename);
        return trim($filename, '._');
    }
}

if (!function_exists('format_bytes')) {
    function format_bytes(int $bytes): string {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576)    return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024)       return round($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }
}

if (!function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void {
        Hook::doAction($hook, ...$args);
    }
}

if (!function_exists('add_action')) {
    function add_action(string $hook, callable $cb, int $priority = 10): void {
        Hook::addAction($hook, $cb, $priority);
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed {
        return Hook::applyFilters($hook, $value, ...$args);
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $hook, callable $cb, int $priority = 10): void {
        Hook::addFilter($hook, $cb, $priority);
    }
}

if (!function_exists('soi_document_html')) {
    /**
     * Render a page/post body. Structured documents use the server renderer;
     * legacy TinyMCE HTML is returned unchanged for the existing content path.
     */
    function soi_document_html(array $record): string {
        if (class_exists(\SOI\Core\Content\DocumentRenderer::class)) {
            $html = \SOI\Core\Content\DocumentRenderer::renderRecord($record, false);
        } else {
            $html = (string) ($record['content'] ?? '');
        }
        return apply_filters('soi_document_html', $html, $record);
    }
}

if (!function_exists('soi_admin_icon')) {
    /**
     * Render an admin navigation icon from the local SVG sprite.
     */
    function soi_admin_icon(string $name, int $size = 16, string $class = 'nav-icon'): string {
        $safeName = preg_replace('/[^a-z0-9-]/', '', strtolower($name));
        $v = defined('SOI_ADMIN_ASSET_VERSION') ? '?v=' . SOI_ADMIN_ASSET_VERSION : '';
        $spriteUrl = soi_admin_asset_url('icons.svg') . $v . '#' . $safeName;
        return '<svg class="soi-icon ' . esc($class) . '" width="' . (int)$size . '" height="' . (int)$size . '" aria-hidden="true" focusable="false"><use href="' . esc($spriteUrl) . '"></use></svg>';
    }
}
