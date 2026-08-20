<?php
/**
 * SOI (School Of Interns) CMS - Installer Logic
 */

if (PHP_SAPI !== 'cli' && !defined('SOI_INSTALLER_BOOTSTRAP')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Forbidden';
    exit;
}

function installer_config_path(): string {
    return dirname(__DIR__) . '/config/config.php';
}

function installer_lock_path(): string {
    return dirname(__DIR__) . '/config/install.lock';
}

function installer_is_locked(): bool {
    return is_file(installer_config_path()) || is_file(installer_lock_path());
}

function installer_write_lock_file(): bool {
    $lockPath = installer_lock_path();
    $configDir = dirname($lockPath);

    if (!is_dir($configDir) && !mkdir($configDir, 0755, true) && !is_dir($configDir)) {
        return false;
    }

    $payload = json_encode([
        'locked_at' => gmdate('c'),
        'reason'    => 'installation_complete',
    ], JSON_UNESCAPED_SLASHES);

    if (is_file($lockPath)) {
        return true;
    }

    $tempPath = $lockPath . '.tmp-' . bin2hex(random_bytes(6));
    if (file_put_contents($tempPath, $payload . "\n", LOCK_EX) === false) {
        return false;
    }

    @chmod($tempPath, 0600);
    if (!@rename($tempPath, $lockPath)) {
        @unlink($tempPath);
        return false;
    }

    return true;
}

function installer_request_is_https(): bool {
    $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    if ($forwardedProto === 'https') {
        return true;
    }

    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    return $https !== '' && $https !== 'off' && $https !== '0'
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function installer_detect_site_url(): string {
    $scheme = installer_request_is_https() ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'));
    if (!preg_match('/^[A-Za-z0-9.\-\[\]:]+$/', $host)) {
        $host = 'localhost';
    }

    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/install/index.php'));
    $basePath = str_replace('\\', '/', dirname(dirname($scriptName)));
    $basePath = ($basePath === '/' || $basePath === '.' || $basePath === '\\') ? '' : '/' . trim($basePath, '/');

    return $scheme . '://' . $host . $basePath;
}

function installer_normalize_site_url(mixed $value): array {
    $url = rtrim(trim((string)$value), '/');
    if ($url === '') {
        return ['success' => false, 'error' => 'Site URL is required.'];
    }

    $parts = parse_url($url);
    if (!is_array($parts)
        || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        || empty($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['query'])
        || isset($parts['fragment'])) {
        return ['success' => false, 'error' => 'Site URL must be a complete http:// or https:// URL without credentials, a query string, or a fragment.'];
    }

    $host = strtolower((string)$parts['host']);
    if (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) && !filter_var($host, FILTER_VALIDATE_IP)) {
        return ['success' => false, 'error' => 'Site URL contains an invalid hostname.'];
    }

    $path = isset($parts['path']) ? '/' . trim((string)$parts['path'], '/') : '';
    if ($path === '/' || $path === '/.') {
        $path = '';
    }
    if (str_contains($path, '..')) {
        return ['success' => false, 'error' => 'Site URL path cannot contain parent-directory segments.'];
    }

    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    return [
        'success' => true,
        'url' => strtolower((string)$parts['scheme']) . '://' . $host . $port . $path,
    ];
}

function installer_normalize_hostname(mixed $value): string {
    $raw = trim((string)$value);
    if ($raw === '' || preg_match('/\s/u', $raw)) {
        return '';
    }
    if (str_starts_with($raw, '//')) {
        $raw = 'https:' . $raw;
    } elseif (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $raw)) {
        $raw = 'https://' . $raw;
    }
    $parts = parse_url($raw);
    if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        return '';
    }
    $host = strtolower(rtrim((string)$parts['host'], './'));
    if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
        return '';
    }
    return $host;
}

function installer_sanitize_slug(mixed $value, string $fallback = 'cms'): string {
    $slug = strtolower(trim((string)$value));
    $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-_');
    if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug)) {
        $fallback = preg_replace('/[^a-z0-9_-]+/', '-', strtolower($fallback)) ?? 'cms';
        $fallback = trim($fallback, '-_');
        return $fallback !== '' ? substr($fallback, 0, 64) : 'cms';
    }
    return substr($slug, 0, 64);
}

function installer_validate_site_config(array $site): array {
    $name = trim((string)($site['name'] ?? ''));
    $email = trim((string)($site['email'] ?? ''));
    $timezone = trim((string)($site['timezone'] ?? 'UTC'));
    $urlResult = installer_normalize_site_url($site['url'] ?? '');

    if ($name === '' || strlen($name) > 200) {
        return ['success' => false, 'error' => 'Site name is required and must be 200 characters or fewer.'];
    }
    if (!$urlResult['success']) {
        return $urlResult;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191) {
        return ['success' => false, 'error' => 'Enter a valid site contact email address.'];
    }
    if (!in_array($timezone, timezone_identifiers_list(), true)) {
        return ['success' => false, 'error' => 'Select a valid timezone.'];
    }

    $domain = installer_normalize_hostname($site['domain'] ?? '');
    if ($domain === '') {
        $domain = installer_normalize_hostname($urlResult['url']);
    }
    if ($domain === '') {
        return ['success' => false, 'error' => 'Site domain is required as a hostname only (example: example.com).'];
    }

    $slugSource = trim((string)($site['slug'] ?? ''));
    if ($slugSource === '') {
        $slugSource = explode('.', $domain)[0] ?? 'cms';
    }
    $slug = installer_sanitize_slug($slugSource, 'cms');

    $canonical = $urlResult['url'];
    if (!empty($site['canonical_base_url'])) {
        $canonResult = installer_normalize_site_url($site['canonical_base_url']);
        if (!$canonResult['success']) {
            return ['success' => false, 'error' => 'Canonical base URL is invalid.'];
        }
        $canonical = $canonResult['url'];
    }

    $adminBase = rtrim($canonical, '/') . '/admin';
    if (!empty($site['admin_base_url'])) {
        $adminResult = installer_normalize_site_url($site['admin_base_url']);
        if (!$adminResult['success']) {
            return ['success' => false, 'error' => 'Admin base URL is invalid.'];
        }
        $adminBase = $adminResult['url'];
    }

    $frontendBase = $canonical;
    if (!empty($site['frontend_base_url'])) {
        $frontResult = installer_normalize_site_url($site['frontend_base_url']);
        if (!$frontResult['success']) {
            return ['success' => false, 'error' => 'Frontend base URL is invalid.'];
        }
        $frontendBase = $frontResult['url'];
    }

    $environment = strtolower(trim((string)($site['environment'] ?? 'production')));
    if (!in_array($environment, ['production', 'staging', 'development', 'local'], true)) {
        $environment = 'production';
    }

    $filesServiceUrl = rtrim(trim((string)($site['files_service_url'] ?? 'https://files.soi.co.in')), '/');
    $fsUrlResult = installer_normalize_site_url($filesServiceUrl);
    if (!$fsUrlResult['success'] || !str_starts_with(strtolower($fsUrlResult['url']), 'https://')) {
        return ['success' => false, 'error' => 'Files Service URL must be a valid https:// URL.'];
    }

    $authMode = strtolower(trim((string)($site['auth_mode'] ?? 'accounts_saml')));
    if (!in_array($authMode, ['accounts_saml', 'accounts_pending'], true)) {
        $authMode = 'accounts_saml';
    }

    $connectAccountsNow = !empty($site['connect_accounts_now']);

    return [
        'success' => true,
        'site' => [
            'name' => $name,
            'slug' => $slug,
            'domain' => $domain,
            'url' => $urlResult['url'],
            'canonical_base_url' => $canonical,
            'admin_base_url' => $adminBase,
            'frontend_base_url' => $frontendBase,
            'email' => $email,
            'timezone' => $timezone,
            'environment' => $environment,
            'files_service_url' => $fsUrlResult['url'],
            'auth_mode' => $authMode,
            'connect_accounts_now' => $connectAccountsNow,
        ],
    ];
}

function installer_validate_db_config(array $config): ?string {
    $dbNameError = installer_validate_db_name($config['name'] ?? '');
    if ($dbNameError) {
        return $dbNameError;
    }
    if (trim((string)($config['host'] ?? '')) === '') {
        return 'Database host is required.';
    }
    if (trim((string)($config['user'] ?? '')) === '') {
        return 'Database username is required.';
    }
    $port = (int)($config['port'] ?? 3306);
    if ($port < 1 || $port > 65535) {
        return 'Database port must be between 1 and 65535.';
    }
    $prefix = trim((string)($config['prefix'] ?? 'soi_'));
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,41}$/', $prefix)) {
        return 'Database table prefix must start with a letter or underscore and contain no more than 42 letters, numbers, or underscores.';
    }

    return null;
}

function installer_csrf_token(): string {
    if (empty($_SESSION['installer_csrf'])) {
        $_SESSION['installer_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['installer_csrf'];
}

function installer_verify_csrf(mixed $token): bool {
    return is_string($token)
        && isset($_SESSION['installer_csrf'])
        && hash_equals((string)$_SESSION['installer_csrf'], $token);
}

function installer_csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . esc(installer_csrf_token()) . '">';
}

function installer_path_is_inside(string $path, string $root): bool {
    $path = rtrim(str_replace('\\', '/', $path), '/');
    $root = rtrim(str_replace('\\', '/', $root), '/');
    return $path === $root || str_starts_with($path, $root . '/');
}

function installer_private_storage_root(): string {
    $cmsRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
    $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '';
    $base = $documentRoot !== '' && installer_path_is_inside($cmsRoot, $documentRoot)
        ? dirname($documentRoot)
        : dirname($cmsRoot);

    $host = (string)(parse_url(installer_detect_site_url(), PHP_URL_HOST) ?: basename($cmsRoot));
    $siteKey = trim((string)preg_replace('/[^a-z0-9.-]+/i', '-', strtolower($host)), '-.');
    if ($siteKey === '') {
        $siteKey = 'soi-cms';
    }
    $siteKey .= '-' . substr(hash('sha256', $cmsRoot), 0, 8);

    return rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'soi-private-storage' . DIRECTORY_SEPARATOR . $siteKey;
}

function installer_private_media_root(): string {
    return installer_private_storage_root() . '/media';
}

function installer_prepare_private_media_storage(): array {
    $path = installer_private_media_root();
    if (!is_dir($path) && !@mkdir($path, 0755, true) && !is_dir($path)) {
        return ['success' => false, 'path' => $path];
    }

    if (!is_dir($path) || !is_writable($path)) {
        return ['success' => false, 'path' => $path];
    }

    $probePath = $path . '/.soi-write-probe-' . bin2hex(random_bytes(6));
    $probeData = random_bytes(24);
    $written = @file_put_contents($probePath, $probeData, LOCK_EX);
    $verified = $written === strlen($probeData)
        && @file_get_contents($probePath) === $probeData;
    @unlink($probePath);

    return ['success' => $verified, 'path' => $path];
}

function installer_acquire_execution_lock() {
    $lockPath = dirname(installer_config_path()) . '/.installing.lock';
    $handle = @fopen($lockPath, 'c');
    if ($handle === false) {
        return false;
    }
    @chmod($lockPath, 0600);
    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return false;
    }

    return $handle;
}

function installer_release_execution_lock($handle): void {
    if (is_resource($handle)) {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}

function installer_public_error(string $context, Throwable $error, string $message): array {
    $reference = strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));
    error_log('[Installer][' . $reference . '][' . $context . '] ' . $error::class . ': ' . $error->getMessage());
    return ['success' => false, 'error' => $message . ' Reference: ' . $reference];
}

function installer_write_config_file(string $configContent): array {
    $configFile = installer_config_path();
    $configDir = dirname($configFile);
    if (!is_dir($configDir) && !@mkdir($configDir, 0755, true) && !is_dir($configDir)) {
        return ['success' => false, 'error' => 'Could not create config/. Check directory permissions.'];
    }
    if (is_file($configFile)) {
        return ['success' => false, 'error' => 'config/config.php already exists. Installation was stopped to protect the existing site.'];
    }

    $tempPath = $configFile . '.tmp-' . bin2hex(random_bytes(6));
    if (file_put_contents($tempPath, $configContent, LOCK_EX) === false) {
        return ['success' => false, 'error' => 'Could not write a temporary config file. Check config/ permissions.'];
    }
    @chmod($tempPath, 0600);
    clearstatcache(true, $tempPath);
    $tempMode = @fileperms($tempPath);
    if ($tempMode === false || ($tempMode & 0077) !== 0 || !is_writable($tempPath)) {
        @unlink($tempPath);
        return ['success' => false, 'error' => 'Could not secure the generated config file to owner-only access (0600).'];
    }
    if (!@rename($tempPath, $configFile)) {
        @unlink($tempPath);
        return ['success' => false, 'error' => 'Could not finalize config/config.php. Check config/ permissions.'];
    }

    clearstatcache(true, $configFile);
    if (!is_file($configFile) || filesize($configFile) < 100) {
        @unlink($configFile);
        return ['success' => false, 'error' => 'The generated config file could not be verified.'];
    }

    return ['success' => true];
}

/**
 * Backfill install.lock on sites that already have config.php from a prior release.
 */
function installer_ensure_lock_if_configured(): void {
    if (is_file(installer_config_path()) && !is_file(installer_lock_path())) {
        installer_write_lock_file();
    }
}

function installer_deny_access(): void {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Installer disabled</title>';
    echo '<style>body{font-family:Inter,Arial,sans-serif;background:#f8fafc;color:#0f172a;margin:0;display:grid;place-items:center;min-height:100vh;padding:24px}.box{max-width:520px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:28px;box-shadow:0 18px 50px rgba(15,23,42,.08)}h1{margin:0 0 12px;font-size:22px}p{line-height:1.6;color:#475569;margin:0 0 12px}a{color:#2563eb;text-decoration:none;font-weight:600}</style></head><body><main class="box">';
    echo '<h1>Installer disabled</h1>';
    echo '<p>This CMS is already installed. The web installer cannot be run again for security reasons.</p>';
    echo '<p>If you need to link SOI Accounts, use <a href="../admin/connect.php">Admin → Connect</a>. To manage the site, go to <a href="../admin/login.php">Admin Login</a>.</p>';
    echo '</main></body></html>';
    exit;
}

function installer_check_requirements(): array {
    $reqs = [];

    // PHP Version
    $reqs[] = [
        'name'     => 'PHP Version ≥ 8.0',
        'ok'       => version_compare(PHP_VERSION, '8.0', '>='),
        'note'     => 'Current: ' . PHP_VERSION,
        'required' => true,
    ];

    // PDO Extension
    $reqs[] = [
        'name'     => 'PDO Extension',
        'ok'       => extension_loaded('pdo'),
        'note'     => extension_loaded('pdo') ? 'Installed' : 'Required for database access',
        'required' => true,
    ];

    // PDO MySQL
    $reqs[] = [
        'name'     => 'PDO MySQL Driver',
        'ok'       => extension_loaded('pdo_mysql'),
        'note'     => extension_loaded('pdo_mysql') ? 'Installed' : 'Required for MySQL connection',
        'required' => true,
    ];

    // ZipArchive
    $reqs[] = [
        'name'     => 'ZipArchive Extension',
        'ok'       => extension_loaded('zip'),
        'note'     => extension_loaded('zip') ? 'Installed' : 'Recommended for update packages; web installer can continue',
        'required' => false,
    ];

    // fileinfo
    $reqs[] = [
        'name'     => 'Fileinfo Extension',
        'ok'       => extension_loaded('fileinfo'),
        'note'     => extension_loaded('fileinfo') ? 'Installed' : 'Required for file type detection',
        'required' => true,
    ];

    // GD / Imagick for images
    $reqs[] = [
        'name'     => 'GD Library',
        'ok'       => extension_loaded('gd'),
        'note'     => extension_loaded('gd') ? 'Installed' : 'Recommended for image processing',
        'required' => false,
    ];

    // mbstring
    $reqs[] = [
        'name'     => 'Multibyte String (mbstring)',
        'ok'       => extension_loaded('mbstring'),
        'note'     => extension_loaded('mbstring') ? 'Installed' : 'Recommended for Unicode support',
        'required' => false,
    ];

    // OpenSSL — required for encrypted Accounts credential storage
    $reqs[] = [
        'name'     => 'OpenSSL Extension',
        'ok'       => extension_loaded('openssl'),
        'note'     => extension_loaded('openssl') ? 'Installed' : 'Required for secure Accounts and Files Service credential storage',
        'required' => true,
    ];

    $reqs[] = [
        'name'     => 'JSON Extension',
        'ok'       => extension_loaded('json') || function_exists('json_encode'),
        'note'     => (extension_loaded('json') || function_exists('json_encode')) ? 'Installed' : 'Required for API and options encoding',
        'required' => true,
    ];

    $reqs[] = [
        'name'     => 'Sodium Extension (optional)',
        'ok'       => extension_loaded('sodium'),
        'note'     => extension_loaded('sodium') ? 'Installed' : 'Optional crypto helper (OpenSSL AES-GCM is primary)',
        'required' => false,
    ];

    $reqs[] = [
        'name'     => 'cURL Extension',
        'ok'       => extension_loaded('curl'),
        'note'     => extension_loaded('curl') ? 'Installed' : 'Required for SOI Accounts, SAML metadata, and Files Service',
        'required' => true,
    ];

    $reqs[] = [
        'name'     => 'DOM/XML Extension',
        'ok'       => extension_loaded('dom'),
        'note'     => extension_loaded('dom') ? 'Installed' : 'Required for SAML responses and metadata',
        'required' => true,
    ];

    $_SESSION['installer_session_probe'] = 'ok';
    $sessionOk = session_status() === PHP_SESSION_ACTIVE
        && ($_SESSION['installer_session_probe'] ?? '') === 'ok';
    unset($_SESSION['installer_session_probe']);
    $reqs[] = [
        'name'     => 'PHP Session Storage',
        'ok'       => $sessionOk,
        'note'     => $sessionOk ? 'Session started and writable' : 'PHP session storage is unavailable or not writable',
        'required' => true,
    ];

    $rootDir = dirname(__DIR__);

    // Write permission: config/
    $configDir = $rootDir . '/config';
    if (!is_dir($configDir)) @mkdir($configDir, 0755, true);
    $reqs[] = [
        'name'     => 'config/ Directory Writable',
        'ok'       => is_writable($configDir),
        'note'     => is_writable($configDir) ? 'Writable' : 'Run: chmod 755 config/',
        'required' => true,
    ];

    // Write permission: uploads/
    $uploadsDir = $rootDir . '/uploads';
    if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0755, true);
    $reqs[] = [
        'name'     => 'uploads/ Directory Writable',
        'ok'       => is_writable($uploadsDir),
        'note'     => is_writable($uploadsDir) ? 'Writable (legacy compatibility)' : 'Recommended for legacy compatibility; new uploads use private storage',
        'required' => false,
    ];

    // Write permission: updates/
    $updatesDir = $rootDir . '/updates';
    if (!is_dir($updatesDir)) @mkdir($updatesDir, 0755, true);
    $reqs[] = [
        'name'     => 'updates/ Directory Writable',
        'ok'       => is_writable($updatesDir),
        'note'     => is_writable($updatesDir) ? 'Writable' : 'Run: chmod 755 updates/',
        'required' => true,
    ];

    $privateStorage = installer_prepare_private_media_storage();
    $reqs[] = [
        'name'     => 'Private Media Storage Writable',
        'ok'       => $privateStorage['success'],
        'note'     => $privateStorage['success'] ? $privateStorage['path'] : 'Cannot create or write: ' . $privateStorage['path'],
        'required' => true,
    ];

    $assetFiles = [
        $rootDir . '/install/installer.css',
        $rootDir . '/admin/assets/admin.css',
        $rootDir . '/admin/assets/admin.js',
        $rootDir . '/admin/assets/icons.svg',
        $rootDir . '/themes/default/style.css',
    ];
    $assetsOk = true;
    foreach ($assetFiles as $assetFile) {
        $mode = @fileperms($assetFile);
        if (!is_file($assetFile) || !is_readable($assetFile) || filesize($assetFile) === 0 || $mode === false || ($mode & 0044) === 0) {
            $assetsOk = false;
            break;
        }
    }
    $reqs[] = [
        'name'     => 'Static CSS/JS Assets Readable',
        'ok'       => $assetsOk,
        'note'     => $assetsOk ? 'Packaged with portable web-server permissions' : 'Admin/theme assets must be readable (recommended file mode: 0644)',
        'required' => true,
    ];

    // mod_rewrite (Apache only)
    $reqs[] = [
        'name'     => 'Apache mod_rewrite',
        'ok'       => function_exists('apache_get_modules') ? in_array('mod_rewrite', apache_get_modules()) : true,
        'note'     => 'Required for clean URLs (assumed enabled)',
        'required' => false,
    ];

    return $reqs;
}

function installer_test_db(array $config): array {
    $configError = installer_validate_db_config($config);
    if ($configError) {
        return ['success' => false, 'error' => $configError];
    }

    try {
        $dsn = installer_mysql_dsn($config, false);
        $pdo = new PDO($dsn, $config['user'], $config['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // Verify the privileges the installer and later schema convergence require.
        $pdo->exec('USE ' . installer_quote_identifier($config['name']));
        $probeTable = installer_sanitize_prefix((string)($config['prefix'] ?? 'soi_')) . 'install_probe_' . bin2hex(random_bytes(4));
        $quotedProbe = installer_quote_identifier($probeTable);
        try {
            $pdo->exec("CREATE TABLE {$quotedProbe} (`id` int NOT NULL AUTO_INCREMENT, `value` varchar(32) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("ALTER TABLE {$quotedProbe} ADD COLUMN `checked_at` datetime NULL");
            $pdo->exec("INSERT INTO {$quotedProbe} (`value`, `checked_at`) VALUES ('ok', NOW())");
            $pdo->exec("UPDATE {$quotedProbe} SET `value` = 'verified' WHERE `id` = 1");
            $pdo->query("SELECT `value` FROM {$quotedProbe} WHERE `id` = 1")->fetchColumn();
            $pdo->exec("DELETE FROM {$quotedProbe} WHERE `id` = 1");
        } finally {
            $pdo->exec("DROP TABLE IF EXISTS {$quotedProbe}");
        }
        return ['success' => true];
    } catch (PDOException $e) {
        return installer_public_error('database-test', $e, 'Database verification failed. Confirm the database name, credentials, and CREATE/ALTER/INSERT/UPDATE/DELETE/DROP privileges.');
    }
}

function installer_run(array $db, array $site): array {
    $dbError = installer_validate_db_config($db);
    if ($dbError) {
        return ['success' => false, 'error' => $dbError];
    }
    $siteResult = installer_validate_site_config($site);
    if (!$siteResult['success']) {
        return $siteResult;
    }
    $site = $siteResult['site'];

    $privateStorage = installer_prepare_private_media_storage();
    if (!$privateStorage['success']) {
        return ['success' => false, 'error' => 'Private media storage is not writable: ' . $privateStorage['path']];
    }

    $executionLock = installer_acquire_execution_lock();
    if ($executionLock === false) {
        return ['success' => false, 'error' => 'Another installation request is already running. Wait a moment, then try again once.'];
    }
    if (installer_is_locked()) {
        installer_release_execution_lock($executionLock);
        return ['success' => false, 'error' => 'This CMS was installed by another request. Reload the site to continue.'];
    }

    $pdo = null;

    try {
        $prefix = installer_sanitize_prefix((string)($db['prefix'] ?? 'soi_'));
        $p = $prefix; // shorthand

        // Connect
        $dsn = installer_mysql_dsn($db, true);
        $pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

        // Create tables
        $schema = installer_get_schema($p);
        foreach (array_filter(array_map('trim', explode(';', $schema)), fn($s) => !empty($s)) as $sql) {
            $pdo->exec($sql);
        }

        $pdo->beginTransaction();

        // Insert default options (generic CMS identity only — never Search Console credentials)
        $defaultOptions = [
            'site_name'            => $site['name'],
            'site_slug'            => $site['slug'] ?? 'cms',
            'site_domain'          => $site['domain'] ?? '',
            'site_url'             => $site['url'],
            'canonical_base_url'   => $site['canonical_base_url'] ?? $site['url'],
            'admin_base_url'       => $site['admin_base_url'] ?? (rtrim($site['url'], '/') . '/admin'),
            'frontend_base_url'    => $site['frontend_base_url'] ?? $site['url'],
            'environment_label'    => $site['environment'] ?? 'production',
            'site_tagline'         => 'Powered by SOI Source CMS',
            'admin_email'          => $site['email'],
            'timezone'             => $site['timezone'],
            'date_format'          => 'F j, Y',
            'time_format'          => 'H:i',
            'active_theme'         => 'default',
            'posts_per_page'       => '10',
            'maintenance_mode'     => '0',
            'cms_version'          => '1.0.4',
            'blog_enabled'         => '0',
            'comments_enabled'     => '0',
            'registration_open'    => '0',
            'home_page'            => '',
            'blog_page'            => '',
            'smtp_host'            => '',
            'smtp_port'            => '587',
            'smtp_user'            => '',
            'smtp_pass'            => '',
            'smtp_encryption'      => 'tls',
            'smtp_from_name'       => $site['name'],
            'smtp_from_email'      => $site['email'],
            'update_log'           => '[]',
            // Files Service identity from installed site only — never seed credentials or Search Console values.
            'fs_conn_domain'       => $site['domain'] ?? '',
            'fs_conn_app_display_name' => $site['name'],
            'fs_conn_app_slug'     => $site['slug'] ?? 'cms',
            'fs_conn_app_id'       => '',
            'fs_conn_client_id'    => '',
            'fs_conn_client_secret_enc' => '',
            'fs_conn_credentials_registry' => '[]',
            'fsc_files_url'        => $site['files_service_url'] ?? 'https://files.soi.co.in',
            'fsc_status'           => 'not_connected',
            'fs_conn_connector_enabled' => '1',
            'fs_conn_delivery_mode' => 'hybrid',
            'fs_conn_keep_local_copy' => '1',
            'fs_conn_rewrite_content_urls_enabled' => '0',
            'fs_conn_media_offload_enabled' => '0',
            'fs_conn_offload_new_uploads_enabled' => '0',
            'fs_conn_migration_enabled' => '0',
            'fs_conn_remote_lifecycle_mode' => 'retain_remote',
            'auth_bootstrap_mode'  => $site['auth_mode'] ?? 'accounts_saml',
        ];

        $stmt = $pdo->prepare("INSERT IGNORE INTO `{$p}options` (option_key, option_value) VALUES (?, ?)");
        $ownedOptionStmt = $pdo->prepare("INSERT INTO `{$p}options` (option_key, option_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)");
        $ownedOptions = [
            'site_name', 'site_slug', 'site_domain', 'site_url', 'canonical_base_url', 'admin_base_url',
            'frontend_base_url', 'environment_label', 'admin_email', 'timezone', 'cms_version',
            'smtp_from_name', 'smtp_from_email', 'fs_conn_domain', 'fs_conn_app_display_name',
            'fs_conn_app_slug', 'fs_conn_app_id', 'fs_conn_client_id', 'fs_conn_client_secret_enc',
            'fs_conn_credentials_registry', 'fsc_files_url', 'fsc_status', 'auth_bootstrap_mode',
        ];
        foreach ($defaultOptions as $key => $value) {
            ($ownedOptions !== [] && in_array($key, $ownedOptions, true) ? $ownedOptionStmt : $stmt)->execute([$key, $value]);
        }

        // Create default pages (no local admin user — author_id set after first Accounts login)
        $pageStmt = $pdo->prepare("INSERT INTO `{$p}pages` (title, slug, content, status, author_id, created_at, updated_at) VALUES (?, ?, ?, 'published', NULL, NOW(), NOW()) ON DUPLICATE KEY UPDATE title = VALUES(title), content = VALUES(content), status = 'published', updated_at = NOW()");
        $pageStmt->execute(['Home', 'home', '<h2>Welcome to ' . htmlspecialchars($site['name']) . '!</h2><p>This is your homepage. Edit it from the admin panel to get started.</p>']);
        $pageStmt->execute(['About', 'about', '<h2>About Us</h2><p>Tell visitors about yourself or your organization here.</p>']);
        $pageStmt->execute(['Contact', 'contact', '<h2>Contact Us</h2><p>Get in touch with us. We\'d love to hear from you.</p>']);

        $homePageId = (int)($pdo->query("SELECT id FROM `{$p}pages` WHERE slug = 'home' LIMIT 1")->fetchColumn() ?: 0);
        if ($homePageId > 0) {
            $ownedOptionStmt->execute(['home_page', (string)$homePageId]);
        }

        $blogEnabled = ($defaultOptions['blog_enabled'] ?? '0') === '1';
        if ($blogEnabled) {
            $pdo->prepare("INSERT INTO `{$p}categories` (name, slug, description) VALUES ('Uncategorized', 'uncategorized', 'Default category') ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)")
                ->execute();

            $pdo->prepare("INSERT INTO `{$p}posts` (title, slug, content, excerpt, status, author_id, created_at, updated_at) VALUES (?, ?, ?, ?, 'published', NULL, NOW(), NOW()) ON DUPLICATE KEY UPDATE title = VALUES(title), content = VALUES(content), excerpt = VALUES(excerpt), status = 'published', updated_at = NOW()")
                ->execute([
                    'Welcome to SOI (School Of Interns) CMS!',
                    'welcome-to-soi-cms',
                    '<p>Congratulations! You have successfully installed <strong>SOI (School Of Interns) CMS</strong>. This is your first blog post.</p><p>Connect your site to <a href="' . $site['url'] . '/admin/connect.php">SOI Accounts</a> to access the admin panel and start creating content.</p>',
                    'Welcome to SOI (School Of Interns) CMS! Connect SOI Accounts to access the admin panel and get started.',
                ]);

            $catLookup = $pdo->query("SELECT id FROM `{$p}categories` WHERE slug = 'uncategorized' LIMIT 1");
            $postLookup = $pdo->query("SELECT id FROM `{$p}posts` WHERE slug = 'welcome-to-soi-cms' LIMIT 1");
            $categoryId = (int)($catLookup->fetchColumn() ?: 0);
            $postId = (int)($postLookup->fetchColumn() ?: 0);
            if ($categoryId && $postId) {
                $pdo->prepare("INSERT IGNORE INTO `{$p}post_categories` (post_id, category_id) VALUES (?, ?)")
                    ->execute([$postId, $categoryId]);
            }
        }

        // Create default menu
        $menuLookup = $pdo->query("SELECT id FROM `{$p}menus` WHERE location = 'primary' ORDER BY id ASC LIMIT 1");
        $menuId = (int)($menuLookup->fetchColumn() ?: 0);
        if (!$menuId) {
            $pdo->prepare("INSERT INTO `{$p}menus` (name, location) VALUES ('Primary Menu', 'primary')")->execute();
            $menuId = (int)$pdo->lastInsertId();
        }

        $menuItems = [
            ['title' => 'Home',    'url' => $site['url'] . '/',        'type' => 'custom', 'sort' => 1],
            ['title' => 'About',   'url' => $site['url'] . '/about',   'type' => 'custom', 'sort' => 2],
            ['title' => 'Contact', 'url' => $site['url'] . '/contact', 'type' => 'custom', 'sort' => 3],
        ];
        if ($blogEnabled) {
            array_splice($menuItems, 1, 0, [['title' => 'Blog', 'url' => $site['url'] . '/blog', 'type' => 'custom', 'sort' => 2]]);
            foreach ($menuItems as $i => &$item) {
                $item['sort'] = $i + 1;
            }
            unset($item);
        }
        $itemExistsStmt = $pdo->prepare("SELECT id FROM `{$p}menu_items` WHERE menu_id = ? AND title = ? LIMIT 1");
        $itemStmt = $pdo->prepare("INSERT INTO `{$p}menu_items` (menu_id, parent_id, title, url, type, sort_order) VALUES (?, 0, ?, ?, ?, ?)");
        $itemUpdateStmt = $pdo->prepare("UPDATE `{$p}menu_items` SET url = ?, type = ?, sort_order = ? WHERE id = ?");
        foreach ($menuItems as $item) {
            $itemExistsStmt->execute([$menuId, $item['title']]);
            $existingItemId = (int)($itemExistsStmt->fetchColumn() ?: 0);
            if ($existingItemId) {
                $itemUpdateStmt->execute([$item['url'], $item['type'], $item['sort'], $existingItemId]);
            } else {
                $itemStmt->execute([$menuId, $item['title'], $item['url'], $item['type'], $item['sort']]);
            }
        }

        $pdo->commit();

        // Write config file
        $configContent = installer_generate_config($db, $site, $prefix);
        $configWrite = installer_write_config_file($configContent);
        if (!$configWrite['success']) {
            return $configWrite;
        }

        // config.php itself locks the installer. A lock-file failure must not turn a
        // completed installation into a misleading failed/retry-poisoned state.
        if (!installer_write_lock_file()) {
            error_log('[Installer] config.php was written, but config/install.lock could not be created.');
        }

        return ['success' => true];

    } catch (PDOException $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return installer_public_error('database-install', $e, 'Database installation failed. No configuration file was activated.');
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return installer_public_error('installation', $e, 'Installation could not be completed. No configuration file was activated.');
    } finally {
        installer_release_execution_lock($executionLock);
    }
}

function installer_generate_config(array $db, array $site, string $prefix): string {
    $dbHost   = installer_php_literal($db['host']);
    $dbPort   = (int) $db['port'];
    $dbName   = installer_php_literal($db['name']);
    $dbUser   = installer_php_literal($db['user']);
    $dbPass   = installer_php_literal($db['pass']);
    $dbPrefix = installer_php_literal($prefix);
    $siteUrl       = installer_php_literal(rtrim($site['url'], '/'));
    $adminUrl      = installer_php_literal(rtrim($site['url'], '/') . '/admin');
    $tz            = installer_php_literal($site['timezone']);
    $privateStoragePath = installer_php_literal(installer_private_storage_root());
    $secret   = installer_php_literal(bin2hex(random_bytes(32)));

    return "<?php
/**
 * SOI (School Of Interns) CMS Configuration
 * Generated by Web Installer on " . date('Y-m-d H:i:s') . "
 * DO NOT EDIT MANUALLY unless you know what you are doing.
 */

// Database
define('SOI_DB_HOST',   $dbHost);
define('SOI_DB_PORT',   $dbPort);
define('SOI_DB_NAME',   $dbName);
define('SOI_DB_USER',   $dbUser);
define('SOI_DB_PASS',   $dbPass);
define('SOI_DB_PREFIX', $dbPrefix);

// App version (installable distribution)
if (!defined('SOI_VERSION')) {
    define('SOI_VERSION', '1.0.4');
}

// URLs
define('SOI_HOME_URL',         $siteUrl);
define('SOI_ADMIN_URL',        $adminUrl);

// Timezone
date_default_timezone_set($tz);

// Security key (used for token generation)
define('SOI_SECRET_KEY', $secret);

// Debug mode (set to true only in development)
define('SOI_DEBUG', false);

// Paths
define('SOI_PRIVATE_STORAGE_PATH', $privateStoragePath);
define('SOI_UPLOADS_DIR', SOI_ROOT . '/uploads');
define('SOI_PLUGINS_DIR', SOI_ROOT . '/plugins');
define('SOI_THEMES_DIR',  SOI_ROOT . '/themes');
define('SOI_UPDATES_DIR', SOI_ROOT . '/updates');
";
}

function installer_get_schema(string $p): string {
    return "
CREATE TABLE IF NOT EXISTS `{$p}options` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `option_key` varchar(191) NOT NULL,
    `option_value` longtext DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `option_key` (`option_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{$p}users` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `accounts_user_id` varchar(191) DEFAULT NULL,
    `username` varchar(100) NOT NULL,
    `email` varchar(191) NOT NULL,
    `password` varchar(255) DEFAULT NULL,
    `role` enum('admin','editor','author','subscriber') NOT NULL DEFAULT 'subscriber',
    `display_name` varchar(200) DEFAULT NULL,
    `bio` text DEFAULT NULL,
    `avatar` varchar(500) DEFAULT NULL,
    `status` tinyint(1) NOT NULL DEFAULT 1,
    `last_login` datetime DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `username` (`username`),
    UNIQUE KEY `email` (`email`),
    UNIQUE KEY `accounts_user_id` (`accounts_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{$p}pages` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `title` varchar(500) NOT NULL,
    `slug` varchar(191) NOT NULL,
    `content` longtext DEFAULT NULL,
    `meta_title` varchar(500) DEFAULT NULL,
    `meta_desc` varchar(1000) DEFAULT NULL,
    `status` enum('published','draft','private') NOT NULL DEFAULT 'draft',
    `author_id` int(11) DEFAULT NULL,
    `sort_order` int(11) NOT NULL DEFAULT 0,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{$p}posts` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `title` varchar(500) NOT NULL,
    `slug` varchar(191) NOT NULL,
    `content` longtext DEFAULT NULL,
    `excerpt` text DEFAULT NULL,
    `featured_image` varchar(500) DEFAULT NULL,
    `meta_title` varchar(500) DEFAULT NULL,
    `meta_desc` varchar(1000) DEFAULT NULL,
    `status` enum('published','draft','private') NOT NULL DEFAULT 'draft',
    `author_id` int(11) DEFAULT NULL,
    `comment_status` enum('open','closed') NOT NULL DEFAULT 'open',
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{$p}categories` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(200) NOT NULL,
    `slug` varchar(191) NOT NULL,
    `description` text DEFAULT NULL,
    `parent_id` int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{$p}post_categories` (
    `post_id` int(11) NOT NULL,
    `category_id` int(11) NOT NULL,
    PRIMARY KEY (`post_id`, `category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{$p}tags` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(200) NOT NULL,
    `slug` varchar(191) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{$p}post_tags` (
    `post_id` int(11) NOT NULL,
    `tag_id` int(11) NOT NULL,
    PRIMARY KEY (`post_id`, `tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{$p}media` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `filename` varchar(500) NOT NULL,
    `original_name` varchar(500) DEFAULT NULL,
    `mime_type` varchar(100) DEFAULT NULL,
    `file_size` bigint(20) DEFAULT NULL,
    `path` varchar(1000) DEFAULT NULL,
    `url` varchar(1000) DEFAULT NULL,
    `alt_text` varchar(500) DEFAULT NULL,
    `uploaded_by` int(11) DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{$p}menus` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(200) NOT NULL,
    `location` varchar(100) DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{$p}menu_items` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `menu_id` int(11) NOT NULL,
    `parent_id` int(11) NOT NULL DEFAULT 0,
    `title` varchar(200) NOT NULL,
    `url` varchar(1000) DEFAULT NULL,
    `type` enum('page','post','custom') NOT NULL DEFAULT 'custom',
    `object_id` int(11) NOT NULL DEFAULT 0,
    `sort_order` int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{$p}plugins` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `slug` varchar(191) NOT NULL,
    `name` varchar(200) NOT NULL,
    `version` varchar(50) DEFAULT NULL,
    `active` tinyint(1) NOT NULL DEFAULT 0,
    `installed_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{$p}comments` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `post_id` int(11) NOT NULL,
    `parent_id` int(11) NOT NULL DEFAULT 0,
    `author_name` varchar(200) NOT NULL,
    `author_email` varchar(191) NOT NULL,
    `content` text NOT NULL,
    `status` enum('pending','approved','spam','trash') NOT NULL DEFAULT 'pending',
    `ip_address` varchar(45) DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{$p}files_service_media_map` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `local_path` varchar(1000) DEFAULT NULL,
    `local_url` varchar(1000) DEFAULT NULL,
    `file_id` varchar(191) DEFAULT NULL,
    `remote_url` varchar(1000) DEFAULT NULL,
    `media_url` varchar(1000) DEFAULT NULL,
    `download_url` varchar(1000) DEFAULT NULL,
    `mime_type` varchar(100) DEFAULT NULL,
    `size_bytes` bigint(20) DEFAULT 0,
    `sha256` char(64) DEFAULT NULL,
    `source_table` varchar(64) DEFAULT NULL,
    `source_column` varchar(64) DEFAULT NULL,
    `source_record_id` int(11) DEFAULT NULL,
    `migration_status` varchar(50) NOT NULL DEFAULT 'pending',
    `last_error` text DEFAULT NULL,
    `previous_file_id` varchar(191) DEFAULT NULL,
    `previous_media_url` varchar(1000) DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_file_id` (`file_id`),
    KEY `idx_sha256` (`sha256`),
    KEY `idx_status` (`migration_status`),
    KEY `idx_source_ref` (`source_table`, `source_column`, `source_record_id`),
    KEY `idx_local_path` (`local_path`(191)),
    KEY `idx_local_url` (`local_url`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{$p}files_service_content_backup` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `table_name` varchar(64) NOT NULL,
    `row_id` int(11) NOT NULL,
    `column_name` varchar(64) NOT NULL,
    `original_value_hash` char(64) NOT NULL,
    `original_value` longtext NOT NULL,
    `replacement_count` int(11) NOT NULL DEFAULT 0,
    `batch_token` varchar(80) NOT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_batch_token` (`batch_token`),
    KEY `idx_content_ref` (`table_name`, `row_id`, `column_name`),
    KEY `idx_original_hash` (`original_value_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";
}

function esc(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function installer_validate_db_name(mixed $name): ?string {
    $name = trim((string)$name);
    if ($name === '') {
        return 'Database name is required.';
    }

    if (!preg_match('/^[A-Za-z0-9_.\\-$]+$/', $name)) {
        return 'Database name may only contain letters, numbers, underscores, dashes, dots, and dollar signs.';
    }

    return null;
}

function installer_mysql_dsn(array $config, bool $includeDb): string {
    $host = trim((string)($config['host'] ?? 'localhost')) ?: 'localhost';
    $port = (int)($config['port'] ?? 3306);

    $dsn = "mysql:host={$host};charset=utf8mb4";
    if ($includeDb) {
        $dsn .= ';dbname=' . (string)$config['name'];
    }
    if ($port > 0) {
        $dsn .= ";port={$port}";
    }

    return $dsn;
}

function installer_quote_identifier(string $identifier): string {
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function installer_sanitize_prefix(string $prefix): string {
    $prefix = preg_replace('/[^a-zA-Z0-9_]/', '_', trim($prefix)) ?: 'soi_';
    if (trim($prefix, '_') === '') {
        return 'soi_';
    }
    if (!preg_match('/^[A-Za-z_]/', $prefix)) {
        $prefix = 'soi_' . $prefix;
    }

    return $prefix;
}

function installer_php_literal(mixed $value): string {
    return var_export((string)$value, true);
}

/**
 * Generate a random state token for accounts connect
 */
function installer_generate_state(): string {
    return bin2hex(random_bytes(32));
}

/**
 * Build base64 encoded JSON data for accounts connect
 * Contains non-sensitive site info.
 */
function installer_build_installation_data(array $site): string {
    $data = [
        'site_name'   => $site['name'] ?? '',
        'site_url'    => $site['url'] ?? '',
        'admin_email' => $site['email'] ?? '',
        'cms_version' => defined('SOI_VERSION') ? SOI_VERSION : '1.0.4',
        'site_slug'   => $site['slug'] ?? '',
        'site_domain' => $site['domain'] ?? '',
    ];
    return base64_encode(json_encode($data));
}

/**
 * Build the redirect URL to accounts.soi.co.in
 */
function installer_build_accounts_connect_url(array $site, string $state, string $installationData): string {
    $baseUrl = 'https://accounts.soi.co.in/install/connect';
    $params = [
        'client_id'         => 'global_cms_installer',
        'redirect_uri'      => rtrim($site['url'] ?? '', '/') . '/install/callback.php',
        'state'             => $state,
        'installation_data' => $installationData,
    ];
    return $baseUrl . '?' . http_build_query($params);
}

/**
 * Save the state token to the database options table.
 *
 * @return array{success: bool, error?: string}
 */
function installer_save_accounts_connect_state(array $db, string $state, string $redirectUri = ''): array {
    try {
        $dsn = installer_mysql_dsn($db, true);
        $pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $prefix = installer_sanitize_prefix((string)($db['prefix'] ?? 'soi_'));

        $payload = json_encode([
            'state'        => $state,
            'redirect_uri' => $redirectUri,
            'created_at'   => time(),
        ], JSON_THROW_ON_ERROR);

        $stmt = $pdo->prepare("INSERT INTO `{$prefix}options` (option_key, option_value) VALUES ('accounts_connect_state', ?) ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)");
        $stmt->execute([$payload]);

        return ['success' => true];
    } catch (PDOException $e) {
        error_log('[Installer] Failed to save accounts_connect_state: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Could not save Accounts connect state. Please try again.'];
    } catch (\Throwable $e) {
        error_log('[Installer] Failed to save accounts_connect_state: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Could not save Accounts connect state. Please try again.'];
    }
}
