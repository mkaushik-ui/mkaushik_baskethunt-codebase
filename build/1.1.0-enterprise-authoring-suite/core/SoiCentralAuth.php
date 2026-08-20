<?php
namespace SOI\Core;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

/**
 * SOI Central account, SAML, Directory API, embed, manifest, and webhook bridge.
 */
class SoiCentralAuth {
    public const VERSION = '4.2.1-central-login-logout-enforcement';
    private const DEFAULT_BASE_URL = 'https://accounts.soi.co.in';
    private const SESSION_USER_KEY = 'soi_user';
    private const SESSION_BINDING_KEY = 'soi_central_binding';
    private const SAML_REQUEST_KEY = 'soi_central_saml_request_id';
    private const SAML_RETURN_KEY = 'soi_central_saml_return';
    private const SAML_METADATA_TTL_SECONDS = 3600;
    private const METADATA_STATUS_OPTION = 'soi_central_saml_metadata_fetch_status';
    private const METADATA_ERROR_OPTION = 'soi_central_saml_metadata_fetch_error';
    private const METADATA_REASON_OPTION = 'soi_central_saml_metadata_fetch_reason';
    private static bool $installed = false;
    private static bool $bootstrapped = false;
    private static ?string $acsRawBodyCache = null;

    public static function bootstrap(): void {
        if (self::$bootstrapped) return;
        self::$bootstrapped = true;
        self::install();

        if (function_exists('add_action')) {
            add_action('soi_nav_items', function (): void {
                if (self::shouldRenderEmbed()) {
                    echo '<span class="nav-link soi-central-nav-embed">';
                    self::renderEmbed('soi-my-account-nav', self::currentUrl());
                    echo '</span>';
                }
            }, 95);
            add_action('soi_head', [self::class, 'renderSyncScript']);
        }
    }

    public static function install(): void {
        if (self::$installed) return;
        self::$installed = true;

        try {
            self::setDefaultOption('soi_central_enabled', '1');
            self::setDefaultOption('soi_central_base_url', self::DEFAULT_BASE_URL);
            self::setDefaultOption('soi_central_login_mode', 'saml');
            self::setDefaultOption('soi_central_allow_local_login', '0');
            self::setDefaultOption('soi_central_auto_provision', '1');
            self::setDefaultOption('soi_central_sync_local_role', '1');
            self::setDefaultOption('soi_central_directory_required', '0');
            self::setDefaultOption('soi_central_enforce_admin', '1');
            self::setDefaultOption('soi_central_enforce_frontend', '1');
            self::setDefaultOption('soi_central_enforce_kb', '1');
            self::setDefaultOption('soi_central_enforce_browser', '0');
            self::setDefaultOption('soi_central_central_logout', '1');
            self::setDefaultOption('soi_central_live_session_sync', '1');
            self::setDefaultOption('soi_central_session_poll_interval', '30');
            self::setDefaultOption('soi_central_session_recheck_seconds', '30');
            self::setDefaultOption('soi_central_session_enforcement', '1');
            self::setDefaultOption('soi_central_session_fail_closed', '1');
            self::setDefaultOption('soi_central_cache_ttl', '300');
            self::setDefaultOption('soi_central_timestamp_skew', '300');
            self::setDefaultOption('soi_central_admin_permission', 'cms.admin');
            self::setDefaultOption('soi_central_editor_permission', 'cms.content.publish');
            self::setDefaultOption('soi_central_author_permission', 'cms.content.edit');
            self::setDefaultOption('soi_central_subscriber_permission', '');
            self::setDefaultOption('soi_central_kb_permission', 'kb.content.manage');

            $legacyAppId = (string) self::dbOption('my_account_app_id', '');
            $legacyKey = (string) self::dbOption('my_account_public_key', '');
            if ($legacyAppId !== '' && self::dbOption('soi_central_app_id', '') === '') {
                Database::setOption('soi_central_app_id', $legacyAppId);
            }
            if ($legacyKey !== '' && self::dbOption('soi_central_public_embed_key', '') === '') {
                Database::setOption('soi_central_public_embed_key', $legacyKey);
            }
            foreach ([
                'saml_sp_entity_id' => 'soi_central_saml_sp_entity_id',
                'saml_idp_entity_id' => 'soi_central_saml_idp_entity_id',
                'saml_sso_url' => 'soi_central_saml_sso_url',
                'saml_x509_cert' => 'soi_central_saml_x509_cert',
            ] as $legacy => $central) {
                $legacyValue = (string) self::dbOption($legacy, '');
                if ($legacyValue !== '' && self::dbOption($central, '') === '') {
                    Database::setOption($central, $legacyValue);
                }
            }
            if ((string) self::dbOption('saml_enforce_frontend', '0') === '1') {
                Database::setOption('soi_central_enforce_frontend', '1');
            }
            Database::setOption('soi_central_enabled', '1');
            Database::setOption('soi_central_allow_local_login', '0');
            Database::setOption('soi_central_directory_required', '0');
            Database::setOption('soi_central_enforce_admin', '1');
            Database::setOption('soi_central_enforce_frontend', '1');
            Database::setOption('soi_central_enforce_kb', '1');
            Database::setOption('saml_enforce_frontend', '0');
            if (Database::tableExists('plugins')) {
                Database::update('plugins', ['active' => 0], 'slug = ?', ['saml-auth']);
            }

            self::ensureTables();
            self::ensureUserColumns();
        } catch (\Throwable $e) {
            error_log('[SOI Central] install skipped: ' . $e->getMessage());
            try {
                self::ensureTables();
            } catch (\Throwable $tableError) {
                error_log('[SOI Central] table repair skipped: ' . $tableError->getMessage());
            }
        }
    }

    public static function handleRoute(string $uri, string $method): bool {
        $uri = trim($uri, '/');
        $routes = [
            'soi-central/saml/login',
            'soi-central/saml/acs',
            'soi-central/saml/metadata',
            'soi-central/saml/logout',
            'soi-central/saml/slo',
            'soi-central/logout',
            'soi-central/denied',
            'soi-central/manifest.json',
            'soi-central/webhook',
            'soi-central/health',
            'soi-central/session/status',
            'soi-central/session/live-check',
            'soi-central/session/sync.js',
            'saml/login',
            'saml/acs',
            'saml/metadata',
            'saml/logout',
            'saml/slo',
        ];

        if (!in_array($uri, $routes, true)) {
            return false;
        }

        self::logSaml('Route matched via front controller', ['uri' => $uri, 'method' => $method]);
        self::install();

        if ($uri === 'soi-central/saml/login' || $uri === 'saml/login') {
            $returnTo = $_GET['return'] ?? $_GET['redirect'] ?? $_SESSION[self::SAML_RETURN_KEY] ?? SOI_ADMIN_URL . '/';
            self::dispatchSamlLogin((string) $returnTo);
        }

        if ($uri === 'soi-central/saml/acs' || $uri === 'saml/acs') {
            self::processSamlAcsRequest();
        }

        if ($uri === 'soi-central/saml/metadata' || $uri === 'saml/metadata') {
            self::dispatchSamlMetadata();
        }

        if (
            $uri === 'soi-central/logout'
            || $uri === 'soi-central/saml/logout'
            || $uri === 'soi-central/saml/slo'
            || $uri === 'saml/logout'
            || $uri === 'saml/slo'
        ) {
            $returnTo = trim((string) ($_GET['return'] ?? ''));
            self::redirectToCentralLogout($returnTo !== '' ? $returnTo : self::cmsLoginReturnUrl());
        }

        if ($uri === 'soi-central/denied') {
            self::renderAccessDenied($_SESSION['soi_central_last_denial'] ?? []);
        }

        if ($uri === 'soi-central/manifest.json') {
            self::renderManifest();
        }

        if ($uri === 'soi-central/webhook') {
            self::handleWebhook($method);
        }

        if ($uri === 'soi-central/health') {
            self::dispatchHealth();
        }

        if ($uri === 'soi-central/session/status') {
            self::dispatchSessionStatus();
        }

        if ($uri === 'soi-central/session/live-check') {
            self::dispatchSessionLiveCheck();
        }

        if ($uri === 'soi-central/session/sync.js') {
            self::dispatchSessionSyncJs();
        }

        return true;
    }

    /** Physical endpoint: /soi-central/saml/login */
    public static function dispatchSamlLogin(string $returnTo = ''): void {
        self::install();
        self::logSaml('SAML login dispatch', ['return' => $returnTo, 'via' => basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'front-controller'))]);
        self::beginSamlLogin($returnTo);
    }

    /**
     * Unified ACS entry — collects SAMLResponse from POST body, php://input, or GET fallback.
     */
    public static function processSamlAcsRequest(): void {
        self::install();
        $context = self::buildAcsRequestLogContext();
        self::logSaml('ACS request received', $context);

        if (($context['likely_post_get_redirect'] ?? 'no') === 'yes') {
            self::logSaml('ACS likely POST-to-GET redirect detected', [
                'request_method'      => $context['request_method'],
                'content_length'      => $context['content_length'],
                'referer'             => $context['referer'],
                'saml_in_get'         => $context['saml_in_get'],
                'saml_in_post'        => $context['saml_in_post'],
                'saml_in_raw_body'    => $context['saml_in_raw_body'],
            ]);
        }

        if (($context['acs_url_mismatch'] ?? 'no') === 'yes') {
            self::logSaml('ACS URL mismatch vs configured endpoint', [
                'acs_url_configured'      => $context['acs_url_configured'],
                'request_uri'             => $context['request_uri'],
                'acs_url_mismatch_detail' => $context['acs_url_mismatch_detail'],
            ]);
        }

        $payload = self::collectSamlAcsPayload();

        if ($payload['saml_response'] === '') {
            $method = (string) ($context['request_method'] ?? 'GET');
            $hasGetSaml = ($context['saml_in_get'] ?? 'no') === 'yes';
            $title = 'Invalid SAML response';
            $recovery = [];

            if ($method === 'GET' && !$hasGetSaml) {
                $title = 'SAML POST body lost (GET received)';
                $recovery = self::buildAcsTrailingSlashRecoveryHints($context);
            } elseif (($context['likely_post_get_redirect'] ?? 'no') === 'yes') {
                $title = 'SAML POST converted to GET';
                $recovery = self::buildAcsTrailingSlashRecoveryHints($context);
            }

            self::dispatchSamlAcsError(
                $title,
                self::describeMissingAcsPayload(),
                400,
                $recovery
            );
        }

        self::dispatchSamlAcs($payload['saml_response'], $payload['relay_state']);
    }

    /**
     * @return array{saml_response: string, relay_state: string, source: string}
     */
    public static function collectSamlAcsPayload(): array {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');

        self::logSaml('ACS payload collection', array_merge(self::buildAcsRequestLogContext(), [
            'content_type_header' => $contentType !== '' ? $contentType : 'none',
        ]));

        if (!empty($_POST['SAMLResponse'])) {
            return self::normalizeAcsPayload(
                (string) $_POST['SAMLResponse'],
                (string) ($_POST['RelayState'] ?? ''),
                'post_form'
            );
        }

        if ($method === 'POST') {
            $raw = self::peekAcsRawBody();
            if ($raw !== '') {
                self::logSaml('ACS raw body present', [
                    'bytes'            => strlen($raw),
                    'saml_in_raw_body' => self::rawBodyHasSamlResponse($raw) ? 'yes' : 'no',
                ]);

                $parsed = [];
                parse_str($raw, $parsed);
                if (!empty($parsed['SAMLResponse'])) {
                    return self::normalizeAcsPayload(
                        (string) $parsed['SAMLResponse'],
                        (string) ($parsed['RelayState'] ?? ''),
                        'php_input_form'
                    );
                }

                if (str_contains($contentType, 'json')) {
                    $json = json_decode($raw, true);
                    if (is_array($json) && !empty($json['SAMLResponse'])) {
                        return self::normalizeAcsPayload(
                            (string) $json['SAMLResponse'],
                            (string) ($json['RelayState'] ?? ''),
                            'php_input_json'
                        );
                    }
                }
            }
        }

        if (!empty($_GET['SAMLResponse'])) {
            self::logSaml('ACS using GET SAMLResponse fallback (redirect binding)');
            return self::normalizeAcsPayload(
                (string) $_GET['SAMLResponse'],
                (string) ($_GET['RelayState'] ?? ''),
                'get_redirect'
            );
        }

        return ['saml_response' => '', 'relay_state' => '', 'source' => 'none'];
    }

    /**
     * @return array{saml_response: string, relay_state: string, source: string}
     */
    private static function normalizeAcsPayload(string $samlResponse, string $relayState, string $source): array {
        $samlResponse = trim($samlResponse);
        $relayState   = trim($relayState);

        if (str_contains($samlResponse, '%')) {
            $decoded = rawurldecode($samlResponse);
            if ($decoded !== '') {
                $samlResponse = trim($decoded);
            }
        }

        $samlResponse = preg_replace('/\s+/', '', $samlResponse) ?? $samlResponse;

        self::logSaml('ACS payload normalized', [
            'source'        => $source,
            'response_len'  => strlen($samlResponse),
            'relay_present' => $relayState !== '' ? 'yes' : 'no',
        ]);

        return [
            'saml_response' => $samlResponse,
            'relay_state'   => $relayState,
            'source'        => $source,
        ];
    }

    private static function describeMissingAcsPayload(): string {
        $context = self::buildAcsRequestLogContext();
        self::logSaml('ACS payload missing', $context);

        $method = (string) ($context['request_method'] ?? 'GET');
        $length = (string) ($context['content_length'] ?? '0');
        $postMax = (string) ini_get('post_max_size');

        $hints = [
            'SOI Central did not deliver a SAMLResponse to this ACS endpoint.',
            'HTTP method: ' . $method . '.',
            'SAMLResponse in POST: ' . ($context['saml_in_post'] ?? 'no') . ', GET: ' . ($context['saml_in_get'] ?? 'no') . ', raw body: ' . ($context['saml_in_raw_body'] ?? 'n/a') . '.',
        ];

        if (($context['likely_post_get_redirect'] ?? 'no') === 'yes') {
            $hints[] = 'Likely POST-to-GET redirect: GET arrived with Content-Length remnant (' . $length . ') or Referer from accounts.soi.co.in — the SAML POST body was probably dropped by a server redirect.';
        } elseif ($method !== 'POST') {
            $hints[] = 'Expected an HTTP POST from accounts.soi.co.in after login — a GET usually means the POST body was lost (often a trailing-slash redirect).';
        } else {
            $hints[] = 'POST was received but SAMLResponse was empty — check PHP post_max_size (' . $postMax . ') vs Content-Length (' . $length . ').';
        }

        $configuredAcs = (string) ($context['acs_url_configured'] ?? '');
        if ($configuredAcs === '' || $configuredAcs === 'unknown') {
            $configuredAcs = defined('SOI_HOME_URL') ? self::acsUrl() : 'unknown';
        }

        if (($context['acs_url_mismatch'] ?? 'no') === 'yes') {
            $hints[] = 'ACS URL mismatch detected (' . ($context['acs_url_mismatch_detail'] ?? 'unknown') . ') — configured ' . $configuredAcs . ' vs request ' . ($context['request_uri'] ?? '') . '.';
        } else {
            $hints[] = 'Confirm ACS URL is exactly ' . $configuredAcs . ' (no trailing slash mismatch).';
        }

        $hints[] = 'Register this exact ACS URL in SOI Admin Center (no trailing slash): ' . $configuredAcs . '.';
        $hints[] = 'Ensure root .htaccess and soi-central/saml/acs/.htaccess are deployed with DirectorySlash Off and internal rewrites (no 301/302 on ACS).';
        $hints[] = 'Check server error_log for [SOI Central SAML] ACS request received and ACS payload collection entries.';

        return implode(' ', $hints);
    }

    /**
     * @param array<string, mixed> $context
     * @return list<string>
     */
    private static function buildAcsTrailingSlashRecoveryHints(array $context): array {
        $canonical = self::acsUrl();
        $requestUri = (string) ($context['request_uri'] ?? '');
        $hasTrailingSlash = ($context['request_path_trailing_slash'] ?? 'no') === 'yes';

        $hints = [
            'Canonical ACS URL (use in Accounts): ' . $canonical,
            'Do not register ' . $canonical . '/ — a trailing slash can trigger POST→GET redirects.',
        ];

        if ($hasTrailingSlash || ($context['acs_url_mismatch_detail'] ?? '') !== 'none') {
            $hints[] = 'This request hit a slash-variant path (' . $requestUri . '). Deploy updated .htaccess files so both /acs and /acs/ internally rewrite to index.php without redirect.';
        }

        $hints[] = 'After deploy, verify with: curl -X POST -d "SAMLResponse=test" ' . $canonical . ' -v (must not return Location: header).';

        return $hints;
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildAcsRequestLogContext(): array {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
        $contentLength = (string) ($_SERVER['CONTENT_LENGTH'] ?? $_SERVER['HTTP_CONTENT_LENGTH'] ?? '');
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        $postHasSaml = !empty($_POST['SAMLResponse']);
        $getHasSaml = !empty($_GET['SAMLResponse']);

        $rawBodyHasSaml = 'n/a';
        $rawBodyBytes = 0;
        if ($method === 'POST') {
            $raw = self::peekAcsRawBody();
            $rawBodyBytes = strlen($raw);
            $rawBodyHasSaml = self::rawBodyHasSamlResponse($raw) ? 'yes' : 'no';
        }

        $urlMismatch = self::describeAcsUrlMismatch($requestUri);
        $requestPath = (string) (parse_url($requestUri, PHP_URL_PATH) ?? $requestUri);

        return [
            'request_method'           => $method,
            'request_uri'              => $requestUri,
            'request_path'             => $requestPath,
            'request_path_trailing_slash' => str_ends_with($requestPath, '/') ? 'yes' : 'no',
            'canonical_acs_url'        => defined('SOI_HOME_URL') ? self::acsUrl() : 'unknown',
            'script_name'              => $scriptName,
            'https'                    => $https ? 'yes' : 'no',
            'content_type'             => $contentType !== '' ? $contentType : 'none',
            'content_length'           => $contentLength !== '' ? $contentLength : 'none',
            'referer'                  => $referer !== '' ? $referer : 'none',
            'saml_in_post'             => $postHasSaml ? 'yes' : 'no',
            'saml_in_get'              => $getHasSaml ? 'yes' : 'no',
            'saml_in_raw_body'         => $rawBodyHasSaml,
            'raw_body_bytes'           => $method === 'POST' ? $rawBodyBytes : 'n/a',
            'post_keys'                => array_keys($_POST),
            'acs_url_configured'       => defined('SOI_HOME_URL') ? self::acsUrl() : 'unknown',
            'acs_url_mismatch'         => $urlMismatch['mismatch'] ? 'yes' : 'no',
            'acs_url_mismatch_detail'  => $urlMismatch['detail'],
            'likely_post_get_redirect' => self::detectLikelyPostGetRedirect($method, $contentLength, $referer, $postHasSaml, $getHasSaml) ? 'yes' : 'no',
        ];
    }

    private static function peekAcsRawBody(): string {
        if (self::$acsRawBodyCache !== null) {
            return self::$acsRawBodyCache;
        }

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            self::$acsRawBodyCache = '';
            return '';
        }

        $raw = file_get_contents('php://input');
        self::$acsRawBodyCache = is_string($raw) ? $raw : '';
        return self::$acsRawBodyCache;
    }

    private static function rawBodyHasSamlResponse(string $raw): bool {
        if ($raw === '') {
            return false;
        }

        if (str_contains($raw, 'SAMLResponse=') || str_contains($raw, 'SAMLResponse%')) {
            return true;
        }

        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
        if (str_contains($contentType, 'json')) {
            $json = json_decode($raw, true);
            return is_array($json) && !empty($json['SAMLResponse']);
        }

        return false;
    }

    private static function detectLikelyPostGetRedirect(
        string $method,
        string $contentLength,
        string $referer,
        bool $postHasSaml,
        bool $getHasSaml
    ): bool {
        if ($method !== 'GET' || $postHasSaml || $getHasSaml) {
            return false;
        }

        $hasContentLengthRemnant = $contentLength !== '' && $contentLength !== 'none' && (int) $contentLength > 0;
        $refererFromIdp = str_contains(strtolower($referer), 'accounts.soi.co.in');

        return $hasContentLengthRemnant || $refererFromIdp;
    }

    /**
     * @return array{mismatch: bool, detail: string}
     */
    private static function describeAcsUrlMismatch(string $requestUri): array {
        if (!defined('SOI_HOME_URL')) {
            return ['mismatch' => false, 'detail' => 'unavailable'];
        }

        $configured = self::acsUrl();
        $configuredPath = (string) (parse_url($configured, PHP_URL_PATH) ?? '');
        $requestPath = (string) (parse_url($requestUri, PHP_URL_PATH) ?? $requestUri);

        $configuredNorm = rtrim($configuredPath, '/');
        $requestNorm = rtrim($requestPath, '/');

        if ($configuredNorm === $requestNorm) {
            if ($configuredPath !== $requestPath) {
                return [
                    'mismatch' => true,
                    'detail'   => 'trailing_slash: configured=' . $configuredPath . ' request=' . $requestPath,
                ];
            }

            return ['mismatch' => false, 'detail' => 'none'];
        }

        $accepted = array_map(
            static fn(string $url): string => rtrim((string) (parse_url($url, PHP_URL_PATH) ?? $url), '/'),
            self::acceptedAcsUrls()
        );
        if (in_array($requestNorm, $accepted, true)) {
            if ($requestPath !== $requestNorm) {
                return [
                    'mismatch' => true,
                    'detail'   => 'trailing_slash: configured=' . $configuredPath . ' request=' . $requestPath,
                ];
            }

            return [
                'mismatch' => false,
                'detail'   => $requestNorm === $configuredNorm ? 'none' : 'accepted_variant',
            ];
        }

        return [
            'mismatch' => true,
            'detail'   => 'path_mismatch: configured=' . $configuredPath . ' request=' . $requestPath,
        ];
    }

    /** Physical endpoint: /soi-central/saml/acs */
    public static function dispatchSamlAcs(string $samlResponse, string $relayState = ''): void {
        self::install();
        self::logSaml('SAML ACS dispatch', [
            'relay_state'  => $relayState !== '' ? 'present' : 'empty',
            'response_len' => strlen($samlResponse),
        ]);
        self::handleSamlAcs($samlResponse, $relayState);
    }

    /**
     * @param list<string> $recoveryHints
     */
    public static function dispatchSamlAcsError(string $title, string $message, int $code, array $recoveryHints = []): void {
        self::logSaml('SAML ACS error', [
            'title'          => $title,
            'code'           => $code,
            'detail'         => $message,
            'recovery_hints' => $recoveryHints,
            'canonical_acs'  => self::acsUrl(),
        ]);
        http_response_code($code);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>' . self::html($title) . '</title>';
        echo '<style>body{font-family:Inter,Arial,sans-serif;background:#fff7ed;color:#431407;margin:0;display:grid;place-items:center;min-height:100vh;padding:24px}.box{max-width:720px;background:#fff;border:1px solid #fed7aa;border-radius:12px;padding:28px}.hint{font-size:.9rem;line-height:1.55;color:#7c2d12}.recovery{margin-top:1rem;padding:.85rem 1rem;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;font-size:.85rem;line-height:1.55;color:#78350f}.recovery code{font-family:ui-monospace,Menlo,monospace;font-size:.8rem;word-break:break-all}.btn{display:inline-block;margin-top:12px;margin-right:8px;padding:10px 16px;background:#ea580c;color:#fff;text-decoration:none;border-radius:8px;font-weight:600}.btn-secondary{background:#475569}</style></head><body><main class="box">';
        echo '<h1>' . self::html($title) . '</h1><p class="hint">' . self::html($message) . '</p>';
        if ($recoveryHints !== []) {
            echo '<div class="recovery"><strong>Recovery steps</strong><ul style="margin:.5rem 0 0 1.1rem;padding:0">';
            foreach ($recoveryHints as $hint) {
                echo '<li>' . self::html($hint) . '</li>';
            }
            echo '</ul><p style="margin-top:.75rem">Canonical ACS URL: <code>' . self::html(self::acsUrl()) . '</code></p></div>';
        }
        echo '<a class="btn" href="' . self::html(SOI_ADMIN_URL . '/login.php?saml=unavailable') . '">OAuth Sign-In</a>';
        echo '<a class="btn btn-secondary" href="' . self::html(SOI_ADMIN_URL . '/soi-central.php') . '">SOI Central Settings</a>';
        echo '</main></body></html>';
        exit;
    }

    /** Physical endpoint: /soi-central/saml/metadata */
    public static function dispatchSamlMetadata(): void {
        self::install();
        self::logSaml('SAML metadata dispatch');
        self::renderSpMetadata();
    }

    /** Physical endpoint: /soi-central/health */
    public static function dispatchHealth(): void {
        self::install();
        self::renderHealth();
    }

    public static function enforceFrontendRoute(string $uri): void {
        if (!self::isEnabled()) return;
        if (self::isBypassedRoute($uri)) return;

        if (!Auth::check()) {
            self::redirectToLogin(self::currentUrl());
        }

        self::enforceCentralSessionGuard(true);
        self::requireAccessForSession('subscriber');
    }

    public static function shouldProtectAdmin(): bool {
        return self::isEnabled()
            && self::option('enforce_admin', '1') === '1'
            && self::isSamlConfiguredForLogin()
            && !self::isCurrentScript(['login.php', 'logout.php']);
    }

    public static function shouldRedirectAdminLogin(): bool {
        if (!self::isEnabled() || self::option('enforce_admin', '1') !== '1') return false;
        if (!self::isSamlConfiguredForLogin()) return false;
        if (isset($_GET['local']) && self::localFallbackAllowed()) return false;
        return true;
    }

    public static function localFallbackAllowed(): bool {
        return false;
    }

    public static function redirectToLogin(string $returnTo = ''): void {
        if (session_status() === PHP_SESSION_NONE) Auth::init();
        $returnTo = self::safeReturnUrl($returnTo ?: self::currentUrl());
        $_SESSION[self::SAML_RETURN_KEY] = $returnTo;

        if (self::isSamlConfiguredForLogin()) {
            header('Location: ' . self::samlLoginUrl($returnTo));
            exit;
        }

        self::renderError('SOI Central SAML is not configured', 'SAML login must be configured before this protected route can redirect to SOI Central.', 503);
    }

    public static function beginSamlLogin(string $returnTo = ''): void {
        if (!self::isSamlConfiguredForLogin()) {
            self::logSaml('SAML login blocked — configuration incomplete', [
                'sso_url' => self::ssoUrl(),
                'sp_entity_id' => self::spEntityId(),
                'app_id' => self::appId(),
            ]);
            self::renderSamlUnavailable(
                'SOI Central SAML is not configured',
                'SAML SSO is not ready yet. Use OAuth sign-in below, or open Admin → SOI Central to fetch SAML metadata after linking Accounts.',
                SOI_ADMIN_URL . '/login.php?saml=unavailable'
            );
        }

        if (session_status() === PHP_SESSION_NONE) Auth::init();
        $returnTo = self::safeReturnUrl($returnTo ?: SOI_ADMIN_URL . '/');
        $requestId = '_' . bin2hex(random_bytes(20));
        $_SESSION[self::SAML_REQUEST_KEY] = $requestId;
        $_SESSION[self::SAML_RETURN_KEY] = $returnTo;

        try {
            self::ensureIdpMetadata(false, 'saml_login');
        } catch (\Throwable $metadataError) {
            self::logSaml('Metadata refresh before SAML login failed', ['error' => $metadataError->getMessage()]);
            if (self::getStoredIdpCertificates() === []) {
                self::renderSamlUnavailable(
                    'SAML signing certificate not available',
                    'The CMS could not fetch IdP signing certificates from Accounts automatically. Use OAuth sign-in below, then open Admin → SOI Central to verify the App ID and metadata URL.',
                    SOI_ADMIN_URL . '/login.php?saml=unavailable'
                );
            }
        }

        $issueInstant = gmdate('Y-m-d\TH:i:s\Z');
        $ssoUrl = self::ssoUrl();
        $acsUrl = self::acsUrl();
        $issuer = self::spEntityId();

        if ($ssoUrl === '' || $issuer === '') {
            self::logSaml('SAML login blocked — missing SSO URL or SP entity ID', compact('ssoUrl', 'issuer'));
            self::renderSamlUnavailable(
                'SAML login is not ready',
                'The SSO URL or Service Provider entity ID is missing. Try OAuth sign-in or re-link Accounts from the connect page.',
                SOI_ADMIN_URL . '/login.php?saml=unavailable'
            );
        }

        self::logSaml('Redirecting to IdP', ['sso_url' => $ssoUrl, 'return' => $returnTo]);

        $request = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<samlp:AuthnRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"'
            . ' ID="' . self::xml($requestId) . '" Version="2.0" IssueInstant="' . self::xml($issueInstant) . '"'
            . ' Destination="' . self::xml($ssoUrl) . '" AssertionConsumerServiceURL="' . self::xml($acsUrl) . '"'
            . ' ProtocolBinding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST">'
            . '<saml:Issuer>' . self::xml($issuer) . '</saml:Issuer>'
            . '<samlp:NameIDPolicy Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress" AllowCreate="true"/>'
            . '</samlp:AuthnRequest>';

        $redirect = $ssoUrl
            . (str_contains($ssoUrl, '?') ? '&' : '?')
            . 'SAMLRequest=' . rawurlencode(base64_encode(gzdeflate($request)))
            . '&RelayState=' . rawurlencode($returnTo);

        header('Location: ' . $redirect);
        exit;
    }

    public static function handleSamlAcs(string $samlResponseBase64, string $relayState = ''): void {
        try {
            if (session_status() === PHP_SESSION_NONE) {
                Auth::init();
            }

            self::logSaml('ACS request received', [
                'method'          => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
                'relay_state'     => $relayState !== '' ? 'present' : 'empty',
                'app_id'          => self::appId(),
                'cert_stored'     => self::option('saml_x509_cert', '') !== '' ? 'yes' : 'no',
                'metadata_stale'  => self::isMetadataStale() ? 'yes' : 'no',
                'metadata_fetched_at' => self::metadataFetchedAt() !== null
                    ? date('Y-m-d H:i:s', self::metadataFetchedAt())
                    : 'never',
            ]);

            try {
                self::ensureIdpMetadata(false, 'saml_acs');
            } catch (\Throwable $metadataError) {
                self::logSaml('Metadata refresh before ACS failed', ['error' => $metadataError->getMessage()]);
            }

            $profile = self::validateSamlResponse($samlResponseBase64);
            $email = $profile['email'] ?? '';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('The SAML response did not include a valid email address.');
            }

            if (self::isSessionEnforcementEnabled() && self::accountsIssuedSessionHash($profile) === '') {
                throw new RuntimeException(
                    'The SAML response did not include a centrally verifiable Accounts session binding (account_session_id_hash).'
                );
            }

            $access = self::hasApiCredentials() ? self::checkAccess($email, '') : self::directoryUnavailableAccess();

            if (empty($access['allowed']) && ($access['status'] ?? '') !== 'display_only') {
                $_SESSION['soi_central_last_denial'] = $access;
                self::renderAccessDenied($access);
            }

            $user = self::provisionOrUpdateLocalUser($profile, $access);
            self::writeUserSession($user, $profile, $access);

            $introspection = self::introspectCentralSession(true);
            if (empty($introspection['authenticated'])) {
                $reason = (string) ($introspection['reason'] ?? 'INTROSPECTION_UNAVAILABLE');
                if (self::isSessionFailClosed() || self::isSessionEnforcementEnabled()) {
                    self::invalidateCentralSession($reason, true);
                }
            }

            $returnTo = self::safeAcsReturnUrl($relayState, (string) ($_SESSION[self::SAML_RETURN_KEY] ?? ''));
            unset($_SESSION[self::SAML_REQUEST_KEY], $_SESSION[self::SAML_RETURN_KEY]);
            header('Location: ' . $returnTo);
            exit;
        } catch (\Throwable $e) {
            self::logSaml('ACS failed', [
                'error'   => $e->getMessage(),
                'app_id'  => self::appId(),
                'sso_url' => self::ssoUrl(),
            ]);
            self::renderSamlLoginError($e);
        }
    }

    public static function requireAccessForSession(string $minRole = 'subscriber', string $permission = ''): void {
        if (!self::isEnabled()) return;
        if (self::isCurrentScript(['soi-central.php'])) return;
        if (!Auth::check()) return;
        if (!self::hasCentralSession()) {
            Auth::logout();
            self::redirectToLogin(self::currentUrl());
        }

        self::enforceCentralSessionGuard(true);
        if (!self::hasApiCredentials()) {
            if (self::isSessionEnforcementEnabled() && self::isSessionFailClosed()) {
                self::invalidateCentralSession('INTROSPECTION_UNAVAILABLE', true);
            }
            $access = self::directoryUnavailableAccess();
            $_SESSION['soi_access'] = $access;
            if (isset($_SESSION[self::SESSION_USER_KEY]) && is_array($_SESSION[self::SESSION_USER_KEY])) {
                $_SESSION[self::SESSION_USER_KEY]['soi_central_roles'] = [];
                $_SESSION[self::SESSION_USER_KEY]['soi_central_permissions'] = [];
                $_SESSION[self::SESSION_USER_KEY]['soi_central_access_version'] = '';
            }
            return;
        }

        $user = Auth::user();
        $email = (string) ($user['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return;

        // Reuse access cache when introspection is still fresh to avoid duplicate Directory calls.
        if (self::isIntrospectionFresh() && is_array($_SESSION['soi_access'] ?? null)) {
            $access = $_SESSION['soi_access'];
        } else {
            $access = self::checkAccess($email, '', $_SESSION[self::SESSION_USER_KEY]['soi_central_user_id'] ?? null, true);
        }

        if (empty($access['allowed']) && ($access['status'] ?? '') !== 'display_only') {
            $_SESSION['soi_central_last_denial'] = $access;
            if (in_array($access['status'] ?? '', ['revoked', 'account_blocked', 'app_disabled', 'browser_blocked'], true)) {
                Auth::logout();
            }
            self::renderAccessDenied($access);
        }

        $_SESSION['soi_access'] = $access;
        $_SESSION[self::SESSION_USER_KEY]['soi_central_roles'] = self::pluckSlugs($access['roles'] ?? []);
        $_SESSION[self::SESSION_USER_KEY]['soi_central_permissions'] = self::pluckSlugs($access['permissions'] ?? []);
        $_SESSION[self::SESSION_USER_KEY]['soi_central_access_version'] = $access['access_version'] ?? '';
    }

    public static function checkAccess(string $email, string $permission = '', mixed $soiUserId = null, bool $bypassCache = false): array {
        self::install();
        if (!self::hasApiCredentials()) {
            return self::directoryUnavailableAccess();
        }

        $cacheKey = self::cacheKey($email, $permission, $soiUserId);
        if (!$bypassCache) {
            $cached = self::readAccessCache($cacheKey);
            if ($cached) {
                return $cached;
            }
        }

        $payload = [
            'app_id' => self::appId(),
        ];
        if ($permission !== '') $payload['permission'] = $permission;
        if ($soiUserId) $payload['user_id'] = $soiUserId;
        else $payload['email'] = $email;
        if (self::option('enforce_browser', '0') === '1') $payload['enforce_browser'] = true;

        try {
            $data = self::signedRequest('POST', '/api/directory/v1/authz/check', $payload);
            $access = $data['access'] ?? [];
            if (!$access) {
                $access = [
                    'allowed' => !empty($data['allowed']),
                    'status' => !empty($data['allowed']) ? 'active' : 'denied',
                    'message' => $data['message'] ?? '',
                ];
            }
            $access['allowed'] = (bool) (($access['allowed'] ?? $data['allowed'] ?? false) || (($access['status'] ?? '') === 'display_only'));
            self::writeAccessCache($cacheKey, $email, $permission, $access);
            return $access;
        } catch (\Throwable $e) {
            error_log('[SoiCentralAuth] Directory API check failed: ' . $e->getMessage());
            return self::directoryUnavailableAccess();
        }
    }

    public static function repairSessionRoleForDirectoryFallback(): void {
        if (!self::isEnabled() || self::hasApiCredentials()) return;
        if (empty($_SESSION[self::SESSION_USER_KEY]) || !is_array($_SESSION[self::SESSION_USER_KEY])) return;

        $sessionUser = $_SESSION[self::SESSION_USER_KEY];
        if (empty($sessionUser['soi_central_authenticated']) && empty($sessionUser['soi_central_user_id']) && empty($sessionUser['soi_central_profile'])) {
            return;
        }

        $access = $_SESSION['soi_access'] ?? self::directoryUnavailableAccess();
        if (($access['status'] ?? '') !== 'directory_not_configured') return;

        $_SESSION[self::SESSION_USER_KEY]['role'] = 'admin';
        if (!empty($sessionUser['id'])) {
            try {
                Database::update('users', ['role' => 'admin'], 'id = ?', [(int) $sessionUser['id']]);
            } catch (\Throwable $e) {
                error_log('[SOI Central] session role repair skipped: ' . $e->getMessage());
            }
        }
    }

    public static function syncManifest(): array {
        $manifest = self::manifest();
        return self::signedRequest(
            'POST',
            '/api/directory/v1/apps/' . rawurlencode(self::appId()) . '/manifest/sync',
            ['manifest_json' => json_encode($manifest, JSON_UNESCAPED_SLASHES)]
        );
    }

    public static function fetchMetadata(): array {
        $url = self::metadataUrl();
        self::logSaml('Fetching IdP metadata', ['url' => $url, 'app_id' => self::appId()]);

        $xml = self::httpGet($url);
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw new RuntimeException('Metadata contained a DOCTYPE and was rejected.');
        }
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        if (!$dom->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('Metadata XML could not be parsed.');
        }
        libxml_clear_errors();

        $xp = new DOMXPath($dom);
        $xp->registerNamespace('md', 'urn:oasis:names:tc:SAML:2.0:metadata');
        $xp->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

        $entityId = $dom->documentElement instanceof DOMElement ? $dom->documentElement->getAttribute('entityID') : '';
        $ssoNode = $xp->query('//md:IDPSSODescriptor/md:SingleSignOnService[@Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect"]/@Location')->item(0)
            ?: $xp->query('//md:IDPSSODescriptor/md:SingleSignOnService[@Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST"]/@Location')->item(0)
            ?: $xp->query('//md:IDPSSODescriptor/md:SingleSignOnService/@Location')->item(0);
        $certs = self::parseMetadataCertificates($xp);

        return [
            'entity_id'   => trim($entityId),
            'sso_url'     => $ssoNode ? trim($ssoNode->nodeValue) : '',
            'x509_cert'   => $certs[0] ?? '',
            'x509_certs'  => $certs,
        ];
    }

    /**
     * Ensure IdP metadata and signing certificates are current (auto-refresh entry point).
     *
     * @return array{entity_id: string, sso_url: string, x509_cert: string, x509_certs: list<string>}
     */
    public static function ensureIdpMetadata(bool $force = false, string $reason = 'unspecified'): array {
        $stored = self::getStoredIdpCertificates();
        $replaceCertsOnly = $reason === 'accounts_relink';

        if ($reason === 'accounts_relink' || $reason === 'post_link') {
            $force = true;
        }

        $shouldRefresh = $force
            || $stored === []
            || self::isMetadataStale();

        $appConfig = self::validateAppIdConfiguration();

        self::logSaml('Ensure IdP metadata', [
            'reason'              => $reason,
            'force'               => $force ? 'yes' : 'no',
            'replace_certs_only'  => $replaceCertsOnly ? 'yes' : 'no',
            'should_refresh'      => $shouldRefresh ? 'yes' : 'no',
            'stored_cert_count'   => count($stored),
            'metadata_stale'      => self::isMetadataStale() ? 'yes' : 'no',
            'metadata_fetched_at' => self::metadataFetchedAt() !== null
                ? gmdate('Y-m-d H:i:s', self::metadataFetchedAt())
                : 'never',
            'canonical_app_id'    => $appConfig['canonical_app_id'],
            'accounts_app_id'     => $appConfig['accounts_app_id'],
            'soi_central_app_id'  => $appConfig['soi_central_app_id'],
            'app_id_mismatch'     => $appConfig['mismatch'] ? 'yes' : 'no',
            'app_id_detail'       => $appConfig['detail'],
        ]);

        if ($appConfig['mismatch'] && $appConfig['accounts_app_id'] !== '') {
            self::repairAppIdDrift($appConfig['accounts_app_id']);
            $appConfig = self::validateAppIdConfiguration();
            self::logSaml('Repaired app_id drift from accounts_app_id', [
                'canonical_app_id' => $appConfig['canonical_app_id'],
                'detail'           => $appConfig['detail'],
            ]);
        }

        if (!$shouldRefresh) {
            return [
                'entity_id'  => (string) self::option('saml_idp_entity_id', ''),
                'sso_url'    => (string) self::option('saml_sso_url', ''),
                'x509_cert'  => $stored[0],
                'x509_certs' => $stored,
            ];
        }

        try {
            $metadata = self::refreshIdpMetadata(true, $reason, $replaceCertsOnly);
            self::recordMetadataFetchStatus('ok', null, $reason);
            return $metadata;
        } catch (\Throwable $e) {
            self::recordMetadataFetchStatus('error', $e->getMessage(), $reason);
            throw $e;
        }
    }

    /**
     * @return array{status: string, error: string, reason: string, fetched_at: ?string, cert_count: int, stale: bool, metadata_url: string}
     */
    public static function getMetadataFetchStatus(): array {
        $stored = self::getStoredIdpCertificates();
        $fetchedAt = self::metadataFetchedAt();

        return [
            'status'       => (string) self::dbOption(self::METADATA_STATUS_OPTION, 'unknown'),
            'error'        => (string) self::dbOption(self::METADATA_ERROR_OPTION, ''),
            'reason'       => (string) self::dbOption(self::METADATA_REASON_OPTION, ''),
            'fetched_at'   => $fetchedAt !== null ? gmdate('Y-m-d H:i:s', $fetchedAt) : null,
            'cert_count'   => count($stored),
            'stale'        => self::isMetadataStale(),
            'metadata_url' => self::metadataUrl(),
        ];
    }

    private static function recordMetadataFetchStatus(string $status, ?string $error, string $reason): void {
        Database::setOption(self::METADATA_STATUS_OPTION, $status);
        Database::setOption(self::METADATA_ERROR_OPTION, $error ?? '');
        Database::setOption(self::METADATA_REASON_OPTION, $reason);
    }

    /**
     * Whether stored IdP metadata should be refreshed from Accounts.
     */
    public static function isMetadataStale(): bool {
        $fetchedAt = self::metadataFetchedAt();
        if ($fetchedAt === null) {
            return true;
        }

        return (time() - $fetchedAt) > self::metadataRefreshTtl();
    }

    /**
     * Unix timestamp when SAML metadata was last persisted, or null if never fetched.
     */
    public static function metadataFetchedAt(): ?int {
        $raw = trim((string) self::dbOption('soi_central_saml_metadata_fetched_at', ''));
        if ($raw === '') {
            return null;
        }

        $timestamp = strtotime($raw);
        return $timestamp === false ? null : $timestamp;
    }

    /**
     * Refresh IdP metadata from Accounts and persist SAML settings.
     *
     * @return array{entity_id: string, sso_url: string, x509_cert: string, x509_certs: list<string>}
     */
    public static function refreshIdpMetadata(bool $force = false, string $reason = 'manual', bool $replaceCertsOnly = false): array {
        if (!$force && self::option('saml_x509_cert', '') !== '') {
            $stored = self::getStoredIdpCertificates();
            if ($stored !== [] && !self::isMetadataStale()) {
                return [
                    'entity_id'  => (string) self::option('saml_idp_entity_id', ''),
                    'sso_url'    => (string) self::option('saml_sso_url', ''),
                    'x509_cert'  => $stored[0],
                    'x509_certs' => $stored,
                ];
            }
        }

        $wasStale = self::isMetadataStale();
        self::logSaml('Fetching IdP metadata', [
            'reason'             => $reason,
            'forced'             => $force ? 'yes' : 'no',
            'replace_certs_only' => $replaceCertsOnly ? 'yes' : 'no',
            'was_stale'          => $wasStale ? 'yes' : 'no',
            'app_id'             => self::appId(),
            'url'                => self::metadataUrl(),
        ]);

        $metadata = self::fetchMetadata();
        self::persistIdpMetadata($metadata, $replaceCertsOnly);
        $merged = self::getStoredIdpCertificates();
        self::logSaml('IdP metadata refreshed', [
            'reason'           => $reason,
            'entity_id'        => $metadata['entity_id'] ?? '',
            'cert_count'       => count($merged),
            'cert_fingerprints'=> self::certFingerprints($merged),
            'fetched_at'       => date('Y-m-d H:i:s'),
            'forced'           => $force ? 'yes' : 'no',
            'was_stale'        => $wasStale ? 'yes' : 'no',
        ]);

        return $metadata;
    }

    /**
     * Clear all stored IdP signing certificates and metadata fetch status (e.g. on Accounts re-link).
     */
    public static function clearIdpCertificates(): void {
        Database::setOption('soi_central_saml_x509_cert', '');
        Database::setOption('soi_central_saml_x509_certs', '');
        Database::setOption('saml_x509_cert', '');
        Database::setOption('soi_central_saml_metadata_fetched_at', '');
        Database::setOption(self::METADATA_STATUS_OPTION, '');
        Database::setOption(self::METADATA_ERROR_OPTION, '');
        Database::setOption(self::METADATA_REASON_OPTION, '');
    }

    public static function persistIdpMetadata(array $metadata, bool $replaceOnly = false): void {
        if (!empty($metadata['entity_id'])) {
            Database::setOption('soi_central_saml_idp_entity_id', (string) $metadata['entity_id']);
        }
        if (!empty($metadata['sso_url'])) {
            Database::setOption('soi_central_saml_sso_url', (string) $metadata['sso_url']);
        }

        $certs = $metadata['x509_certs'] ?? [];
        if ($certs === [] && !empty($metadata['x509_cert'])) {
            $certs = [(string) $metadata['x509_cert']];
        }

        if ($certs !== []) {
            self::persistIdpCertificates(self::mergeIdpCertificates($certs, $replaceOnly), true);
        }
    }

    public static function cmsLoginReturnUrl(): string {
        return rtrim(SOI_ADMIN_URL, '/') . '/login.php?sso_sync=1';
    }

    public static function logoutUrl(string $returnTo = ''): string {
        $returnTo = self::safeReturnUrl($returnTo ?: self::cmsLoginReturnUrl());
        if (!self::isEnabled() || self::appId() === '') {
            return $returnTo;
        }

        return rtrim(self::baseUrl(), '/') . '/logout?return_to=' . rawurlencode($returnTo);
    }

    public static function redirectToCentralLogout(string $returnTo = ''): void {
        $returnTo = $returnTo !== '' ? $returnTo : self::cmsLoginReturnUrl();
        $url = self::logoutUrl($returnTo);
        self::clearSessionBinding();
        Auth::logout();
        header('Location: ' . $url);
        exit;
    }

    public static function shouldUseCentralLogout(): bool {
        return self::isEnabled() && self::appId() !== '' && self::isCentralLogoutEnabled();
    }

    public static function renderEmbed(string $containerId = 'soi-my-account', string $returnUrl = ''): void {
        $appId = self::appId();
        $base = rtrim(self::baseUrl(), '/');
        $returnUrl = $returnUrl !== '' ? self::safeReturnUrl($returnUrl) : self::currentUrl();
        $fallbackUrl = $base . '/login?return_to=' . rawurlencode($returnUrl);

        echo '<div id="' . self::html($containerId) . '" class="soi-my-account-host" data-hide-on-logout="true" style="display:inline-flex;align-items:center;min-height:38px;">';
        echo '<a class="soi-my-account-fallback" href="' . self::html($fallbackUrl) . '" style="display:inline-flex;align-items:center;justify-content:center;height:38px;padding:0 15px;border-radius:999px;background:#ffffff;color:#202124;border:1px solid rgba(15,23,42,.12);box-shadow:0 2px 8px rgba(15,23,42,.12);font-size:13px;font-weight:700;line-height:1;text-decoration:none;white-space:nowrap;">My Account</a>';
        echo '</div>';

        if ($appId === '') return;

        $attrs = [
            'src' => $base . '/embed/my-account.js',
            'data-app-id' => $appId,
            'defer' => 'defer',
        ];
        $attrs['data-return-url'] = $returnUrl;
        if (self::embedKey() !== '') $attrs['data-embed-key'] = self::embedKey();
        if ($containerId !== 'soi-my-account') $attrs['data-container'] = '#' . $containerId;

        echo '<script';
        foreach ($attrs as $key => $value) {
            if ($key === 'defer') {
                echo ' defer';
            } else {
                echo ' ' . self::html($key) . '="' . self::html($value) . '"';
            }
        }
        echo '></script>';
        echo '<script>(function(){var c=document.getElementById(' . json_encode($containerId) . ');if(!c)return;var clean=function(){var f=c.querySelector(".soi-my-account-fallback");if(f&&c.children.length>1)f.remove();};new MutationObserver(clean).observe(c,{childList:true});setTimeout(clean,800);setTimeout(clean,2000);})();</script>';
    }

    public static function isLiveSessionSyncEnabled(): bool {
        return self::isEnabled()
            && self::option('live_session_sync', '1') === '1'
            && self::appId() !== '';
    }

    public static function getSessionPollIntervalSeconds(): int {
        $seconds = (int) self::option('session_poll_interval', '30');
        if ($seconds < 15) {
            $seconds = 15;
        }
        if ($seconds > 300) {
            $seconds = 300;
        }
        return $seconds;
    }

    public static function getSessionRecheckSeconds(): int {
        $seconds = (int) self::option('session_recheck_seconds', '30');
        if ($seconds < 15) {
            $seconds = 15;
        }
        if ($seconds > 300) {
            $seconds = 300;
        }
        return $seconds;
    }

    public static function isSessionEnforcementEnabled(): bool {
        return self::isEnabled() && self::option('session_enforcement', '1') === '1';
    }

    public static function isSessionFailClosed(): bool {
        return self::option('session_fail_closed', '1') === '1';
    }

    public static function isCentralLogoutEnabled(): bool {
        return self::option('central_logout', '1') === '1';
    }

    /** @return array<string, mixed>|null */
    public static function getSessionBinding(): ?array {
        $binding = $_SESSION[self::SESSION_BINDING_KEY] ?? null;
        return is_array($binding) ? $binding : null;
    }

    public static function clearSessionBinding(): void {
        unset($_SESSION[self::SESSION_BINDING_KEY]);
    }

    /**
     * Phase 3 — validate bound Accounts session on protected requests (default every 30s).
     */
    public static function enforceCentralSessionGuard(bool $redirectOnFailure = true): bool {
        if (!self::isSessionEnforcementEnabled() || !Auth::check() || !self::hasCentralSession()) {
            return true;
        }

        self::ensureSessionBinding();
        $evaluation = self::evaluateCentralSession(false);
        if (!empty($evaluation['authenticated'])) {
            return true;
        }

        if ($redirectOnFailure) {
            self::invalidateCentralSession((string) ($evaluation['reason'] ?? 'CENTRAL_SESSION_INVALID'), true);
        }

        return false;
    }

    /**
     * @return array{authenticated: bool, should_logout: bool, reason: ?string}
     */
    public static function evaluateCentralSession(bool $forceIntrospection = false): array {
        if (!Auth::check()) {
            return ['authenticated' => false, 'should_logout' => true, 'reason' => 'cms_session_missing'];
        }

        if (!self::isSessionEnforcementEnabled() || !self::hasCentralSession()) {
            return ['authenticated' => true, 'should_logout' => false, 'reason' => null];
        }

        self::ensureSessionBinding();
        $binding = self::getSessionBinding();
        if (!$binding) {
            return ['authenticated' => false, 'should_logout' => true, 'reason' => 'central_binding_missing'];
        }

        $expiresAt = (int) ($binding['local_session_expires_at'] ?? 0);
        if ($expiresAt > 0 && time() >= $expiresAt) {
            return ['authenticated' => false, 'should_logout' => true, 'reason' => 'LOCAL_SESSION_EXPIRED'];
        }

        if (!$forceIntrospection && self::isIntrospectionFresh()) {
            return ['authenticated' => true, 'should_logout' => false, 'reason' => null];
        }

        $result = self::introspectCentralSession($forceIntrospection);
        if (!empty($result['authenticated'])) {
            return ['authenticated' => true, 'should_logout' => false, 'reason' => null];
        }

        return [
            'authenticated' => false,
            'should_logout' => true,
            'reason' => (string) ($result['reason'] ?? 'CENTRAL_SESSION_INVALID'),
        ];
    }

    public static function invalidateCentralSession(string $reason, bool $redirect = true): void {
        self::logSaml('Central session invalidated', ['reason' => $reason]);
        $accessDenied = in_array($reason, [
            'APP_ACCESS_REVOKED',
            'USER_DISABLED',
            'app_disabled',
            'account_blocked',
        ], true);
        $denial = $accessDenied ? [
            'allowed' => false,
            'status' => $reason,
            'message' => 'Your access through SOI Accounts is no longer valid.',
        ] : null;

        self::clearSessionBinding();
        Auth::logout();

        if (!$redirect) {
            return;
        }

        if ($denial !== null) {
            Auth::init();
            $_SESSION['soi_central_last_denial'] = $denial;
            header('Location: ' . rtrim(self::cmsBaseUrl(), '/') . '/soi-central/denied');
            exit;
        }

        header('Location: ' . self::cmsLoginReturnUrl());
        exit;
    }

    /** @return list<string> */
    public static function centralSessionInvalidReasons(): array {
        return [
            'SESSION_REVOKED',
            'SESSION_EXPIRED',
            'USER_DISABLED',
            'APP_ACCESS_REVOKED',
            'TOKEN_INVALID',
            'SESSION_NOT_FOUND',
            'INTROSPECTION_UNAVAILABLE',
            'ACCOUNTS_BINDING_REQUIRED',
            'central_binding_missing',
            'cms_session_missing',
            'LOCAL_SESSION_EXPIRED',
        ];
    }

    /**
     * Phase 2 — bind CMS session to Accounts-issued opaque reference (never raw Accounts session IDs).
     *
     * @return array<string, mixed>
     */
    private static function bindCentralSession(array $profile, array $access, bool $migration = false): array {
        $now = time();
        $accountUserId = trim((string) ($profile['soi_user_id'] ?? $profile['user_id'] ?? ''));
        if ($accountUserId === '') {
            $accountUserId = trim((string) (Auth::user()['soi_central_user_id'] ?? ''));
        }

        $accountsHash = self::accountsIssuedSessionHash($profile);
        if ($accountsHash !== '') {
            $sessionHash = $accountsHash;
            $bindingSource = 'accounts_saml';
        } elseif ($migration) {
            $sessionHash = self::deriveAccountSessionHash($profile, true);
            $bindingSource = 'legacy_fallback';
        } elseif (self::isSessionEnforcementEnabled()) {
            throw new RuntimeException(
                'Accounts-issued account_session_id_hash is required when session enforcement is enabled.'
            );
        } else {
            $sessionHash = self::deriveAccountSessionHash($profile, false);
            $bindingSource = 'legacy_fallback';
        }

        $expiresAt = self::parseSamlExpiresAt($profile, $now);

        $binding = [
            'account_user_id'           => $accountUserId,
            'account_session_id_hash'   => $sessionHash,
            'session_version'           => self::extractVersionField($profile, 'session_version', self::extractVersionField($access, 'session_version', 1)),
            'roles_version'             => self::extractVersionField($profile, 'roles_version', self::extractVersionField($access, 'roles_version', 0)),
            'permissions_version'       => self::extractVersionField($profile, 'permissions_version', self::extractVersionField($access, 'permissions_version', 0)),
            'access_version'            => self::profileAccessVersion($profile, $access),
            'binding_source'            => $bindingSource,
            'last_introspection_at'     => $now,
            'local_session_created_at'  => $now,
            'local_session_expires_at'  => $expiresAt,
        ];

        $_SESSION[self::SESSION_BINDING_KEY] = $binding;
        return $binding;
    }

    private static function ensureSessionBinding(): void {
        if (self::getSessionBinding() !== null || !self::hasCentralSession()) {
            return;
        }

        $user = Auth::user() ?? [];
        $profile = is_array($user['soi_central_profile'] ?? null) ? $user['soi_central_profile'] : [];
        if ($profile === [] && !empty($user['soi_central_user_id'])) {
            $profile['soi_user_id'] = $user['soi_central_user_id'];
            $profile['email'] = $user['email'] ?? '';
        }

        if (self::isSessionEnforcementEnabled() && self::accountsIssuedSessionHash($profile) === '') {
            return;
        }

        $access = is_array($_SESSION['soi_access'] ?? null) ? $_SESSION['soi_access'] : [];
        self::bindCentralSession($profile, $access, true);
    }

    private static function accountsIssuedSessionHash(array $profile): string {
        $candidates = [
            $profile['account_session_id_hash'] ?? '',
        ];
        $attributes = $profile['attributes'] ?? null;
        if (is_array($attributes)) {
            $candidates[] = $attributes['account_session_id_hash'] ?? '';
        }

        foreach ($candidates as $candidate) {
            $hash = strtolower(trim((string) $candidate));
            if ($hash !== '' && preg_match('/^[a-f0-9]{64}$/', $hash)) {
                return $hash;
            }
        }

        return '';
    }

    /** @param array<string, mixed> $binding */
    private static function isAccountsIssuedBinding(array $binding): bool {
        return ($binding['binding_source'] ?? '') === 'accounts_saml';
    }

    private static function profileAccessVersion(array $profile, array $access): string {
        $fromSaml = trim((string) ($profile['access_version'] ?? ''));
        if ($fromSaml !== '') {
            return $fromSaml;
        }

        return (string) ($access['access_version'] ?? $profile['app_access_version'] ?? '');
    }

    private static function parseSamlExpiresAt(array $profile, int $fallbackBase): int {
        $raw = trim((string) ($profile['expires_at'] ?? ''));
        if ($raw !== '') {
            $parsed = strtotime($raw);
            if ($parsed !== false && $parsed > $fallbackBase) {
                return $parsed;
            }
        }

        return $fallbackBase + max(3600, self::getSessionRecheckSeconds() * 120);
    }

    /**
     * Legacy fallback only — not centrally verifiable.
     * Used only when session enforcement is off or for pre-1.3.1 migration paths.
     */
    private static function deriveAccountSessionHash(array $profile, bool $migration = false): string {
        $parts = [
            self::appId(),
            trim((string) ($profile['soi_user_id'] ?? '')),
            trim((string) ($profile['email'] ?? '')),
        ];

        if (!$migration && !empty($profile['raw_assertion_id'])) {
            $parts[] = (string) $profile['raw_assertion_id'];
        } elseif (!$migration && !empty($profile['raw_response_id'])) {
            $parts[] = (string) $profile['raw_response_id'];
        } else {
            $parts[] = 'migration';
            $parts[] = (string) session_id();
        }

        $parts[] = (string) time();
        return hash('sha256', implode('|', $parts));
    }

    private static function extractVersionField(array $source, string $key, int $default = 0): int {
        if (!array_key_exists($key, $source)) {
            return $default;
        }
        $value = $source[$key];
        if (is_numeric($value)) {
            return (int) $value;
        }
        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            return (int) $value;
        }
        return $default;
    }

    private static function isIntrospectionFresh(): bool {
        $binding = self::getSessionBinding();
        if (!$binding) {
            return false;
        }
        $last = (int) ($binding['last_introspection_at'] ?? 0);
        return $last > 0 && (time() - $last) < self::getSessionRecheckSeconds();
    }

    /**
     * Server-side central session introspection via Accounts /api/sso/session/status.
     * Session state is not inferred from Directory authz/check.
     *
     * @return array{authenticated: bool, reason?: string, unavailable?: bool}
     */
    public static function introspectCentralSession(bool $force = false): array {
        if (!Auth::check() || !self::hasCentralSession()) {
            return ['authenticated' => false, 'reason' => 'cms_session_missing'];
        }

        self::ensureSessionBinding();
        $binding = self::getSessionBinding();
        if (!$binding) {
            return ['authenticated' => false, 'reason' => 'central_binding_missing'];
        }

        if (self::isSessionEnforcementEnabled() && !self::isAccountsIssuedBinding($binding)) {
            return ['authenticated' => false, 'reason' => 'ACCOUNTS_BINDING_REQUIRED'];
        }

        if (!$force && self::isIntrospectionFresh()) {
            return ['authenticated' => true];
        }

        if (trim((string) ($binding['account_session_id_hash'] ?? '')) === '') {
            return self::resolveIntrospectionFailure('central_binding_missing');
        }

        if (!self::hasApiCredentials()) {
            return self::resolveIntrospectionFailure('INTROSPECTION_UNAVAILABLE', true);
        }

        $introspection = self::requestAccountsSessionIntrospection($binding);
        if ($introspection === null) {
            return self::resolveIntrospectionFailure('INTROSPECTION_UNAVAILABLE', true);
        }

        return self::applyIntrospectionPayload($introspection, $binding);
    }

    /**
     * @return array{authenticated: bool, reason?: string, unavailable?: bool}
     */
    private static function resolveIntrospectionFailure(string $reason, bool $unavailable = false): array {
        if (self::isSessionFailClosed()) {
            return [
                'authenticated' => false,
                'reason' => $reason,
                'unavailable' => $unavailable,
            ];
        }

        self::touchIntrospectionTimestamp();
        return ['authenticated' => true];
    }

    /** @param array<string, mixed> $binding */
    private static function requestAccountsSessionIntrospection(array $binding): ?array {
        $hash = trim((string) ($binding['account_session_id_hash'] ?? ''));
        $userId = trim((string) ($binding['account_user_id'] ?? ''));
        if ($hash === '' || $userId === '') {
            return null;
        }

        $query = http_build_query([
            'app_id' => self::appId(),
            'session_binding' => $hash,
            'user_id' => $userId,
        ]);
        $path = '/api/sso/session/status?' . $query;

        try {
            $data = self::signedRequest('GET', $path);
            $payload = $data['session'] ?? $data;
            return is_array($payload) ? $payload : null;
        } catch (\Throwable $e) {
            error_log('[SoiCentralAuth] Accounts session introspection failed: ' . $e->getMessage());
            return null;
        }
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $binding */
    private static function applyIntrospectionPayload(array $payload, array $binding): array {
        $authenticated = !empty($payload['authenticated']) && empty($payload['revoked']);
        if (!$authenticated) {
            $reason = strtoupper(trim((string) ($payload['reason'] ?? 'SESSION_REVOKED')));
            if ($reason === '') {
                $reason = 'SESSION_REVOKED';
            }

            return [
                'authenticated' => false,
                'reason' => $reason,
            ];
        }

        $updated = $binding;
        if (!empty($payload['account_session_id_hash'])) {
            $updated['account_session_id_hash'] = (string) $payload['account_session_id_hash'];
        }
        foreach (['session_version', 'roles_version', 'permissions_version'] as $field) {
            if (array_key_exists($field, $payload)) {
                $updated[$field] = self::extractVersionField($payload, $field, (int) ($binding[$field] ?? 0));
            }
        }
        if (array_key_exists('access_version', $payload)) {
            $updated['access_version'] = trim((string) $payload['access_version']) !== ''
                ? (string) $payload['access_version']
                : (string) ($binding['access_version'] ?? '');
        }
        $updated['last_introspection_at'] = time();
        $_SESSION[self::SESSION_BINDING_KEY] = $updated;

        if (self::bindingVersionsChanged($binding, $updated)) {
            $user = Auth::user() ?? [];
            $email = trim((string) ($user['email'] ?? ''));
            $soiUserId = (string) ($updated['account_user_id'] ?? '');
            if ($email !== '' || $soiUserId !== '') {
                $access = self::checkAccess($email, '', $soiUserId !== '' ? $soiUserId : null, true);
                self::refreshPermissionsFromAccess($access, is_array($user['soi_central_profile'] ?? null) ? $user['soi_central_profile'] : []);
            }
        }

        return ['authenticated' => true];
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    private static function bindingVersionsChanged(array $before, array $after): bool {
        foreach (['roles_version', 'permissions_version'] as $field) {
            if ((int) ($after[$field] ?? 0) > (int) ($before[$field] ?? 0)) {
                return true;
            }
        }

        $beforeAccess = trim((string) ($before['access_version'] ?? ''));
        $afterAccess = trim((string) ($after['access_version'] ?? ''));
        return $afterAccess !== '' && $afterAccess !== $beforeAccess;
    }

    private static function touchIntrospectionTimestamp(?array $access = null): void {
        $binding = self::getSessionBinding();
        if (!$binding) {
            return;
        }
        $binding['last_introspection_at'] = time();
        if (is_array($access)) {
            foreach (['session_version', 'roles_version', 'permissions_version', 'access_version'] as $field) {
                if (array_key_exists($field, $access)) {
                    $binding[$field] = self::extractVersionField($access, $field, (int) ($binding[$field] ?? 0));
                }
            }
        }
        $_SESSION[self::SESSION_BINDING_KEY] = $binding;
    }

    private static function mapAccessStatusToReason(string $status): string {
        return match ($status) {
            'account_blocked' => 'USER_DISABLED',
            'app_disabled' => 'APP_ACCESS_REVOKED',
            'revoked', 'browser_blocked' => 'SESSION_REVOKED',
            'denied' => 'APP_ACCESS_REVOKED',
            default => strtoupper($status !== '' ? $status : 'SESSION_REVOKED'),
        };
    }

    private static function refreshPermissionsFromAccess(array $access, array $profile = []): void {
        if (empty($_SESSION[self::SESSION_USER_KEY]) || !is_array($_SESSION[self::SESSION_USER_KEY])) {
            return;
        }

        $_SESSION['soi_access'] = $access;
        $_SESSION[self::SESSION_USER_KEY]['soi_central_roles'] = self::pluckSlugs($access['roles'] ?? []);
        $_SESSION[self::SESSION_USER_KEY]['soi_central_permissions'] = self::pluckSlugs($access['permissions'] ?? []);
        $_SESSION[self::SESSION_USER_KEY]['soi_central_access_version'] = (string) ($access['access_version'] ?? '');

        $binding = self::getSessionBinding();
        if ($binding) {
            foreach (['session_version', 'roles_version', 'permissions_version'] as $field) {
                if (array_key_exists($field, $access)) {
                    $binding[$field] = self::extractVersionField($access, $field, (int) ($binding[$field] ?? 0));
                }
            }
            if (array_key_exists('access_version', $access)) {
                $binding['access_version'] = (string) ($access['access_version'] ?? $binding['access_version'] ?? '');
            }
            $binding['last_introspection_at'] = time();
            $_SESSION[self::SESSION_BINDING_KEY] = $binding;
        }

        if (self::option('sync_local_role', '1') === '1') {
            $role = self::roleFromAccess($access, $profile);
            $_SESSION[self::SESSION_USER_KEY]['role'] = $role;
            $userId = (int) ($_SESSION[self::SESSION_USER_KEY]['id'] ?? 0);
            if ($userId > 0) {
                try {
                    Database::update('users', ['role' => $role], 'id = ?', [$userId]);
                } catch (\Throwable $e) {
                    error_log('[SOI Central] permission refresh role sync skipped: ' . $e->getMessage());
                }
            }
        }
    }

    public static function cmsLogoutUrl(): string {
        return rtrim(SOI_ADMIN_URL, '/') . '/logout.php';
    }

    public static function sessionStatusUrl(): string {
        return rtrim(self::cmsBaseUrl(), '/') . '/soi-central/session/status';
    }

    public static function accountsEmbedMeUrl(): string {
        $base = rtrim(self::baseUrl(), '/');
        $returnUrl = self::currentUrl();
        return $base . '/api/embed/me?app_id=' . rawurlencode(self::appId()) . '&return_url=' . rawurlencode($returnUrl);
    }

    /** Physical endpoint: /soi-central/session/live-check */
    public static function dispatchSessionLiveCheck(): void {
        self::install();
        self::renderSessionLiveCheck();
    }

    /** Physical endpoint: /soi-central/session/status (kept for backward compatibility) */
    public static function dispatchSessionStatus(): void {
        self::install();
        self::renderSessionLiveCheck();
    }

    /** Physical endpoint: /soi-central/session/sync.js (OpenLiteSpeed-safe; no root .htaccess required) */
    public static function dispatchSessionSyncJs(): void {
        self::install();
        self::renderSessionSyncJs();
    }

    private static function sessionSyncJsPath(): string {
        return SOI_ROOT . '/assets/soi-session-sync.js';
    }

    private static function renderSessionSyncJs(): void {
        $path = self::sessionSyncJsPath();
        if (!is_file($path)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo '/* SOI session sync asset missing */';
            exit;
        }

        $version = defined('SOI_ADMIN_ASSET_VERSION') ? SOI_ADMIN_ASSET_VERSION : '1.1.0';
        header('Content-Type: application/javascript; charset=UTF-8');
        header('Cache-Control: public, max-age=3600');
        header('ETag: "' . md5_file($path) . '"');
        readfile($path);
        exit;
    }

    private static function renderSessionLiveCheck(): void {
        Auth::init();
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');

        $evaluation = self::evaluateCentralSession(true);
        $binding = self::getSessionBinding();

        if (!empty($evaluation['should_logout'])) {
            http_response_code(401);
        }

        echo json_encode([
            'authenticated'        => !empty($evaluation['authenticated']),
            'should_logout'        => !empty($evaluation['should_logout']),
            'reason'               => $evaluation['reason'] ?? null,
            'app_id'               => self::appId(),
            'roles_version'        => (int) ($binding['roles_version'] ?? 0),
            'permissions_version'  => (int) ($binding['permissions_version'] ?? 0),
            'last_introspection_at'=> (int) ($binding['last_introspection_at'] ?? 0),
            'ts'                   => time(),
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function renderSyncScript(): void {
        if (!Auth::check() || !self::isLiveSessionSyncEnabled()) {
            return;
        }

        $assetPath = function_exists('soi_public_path_prefix')
            ? soi_public_path_prefix() . '/soi-central/session/sync.js'
            : '/soi-central/session/sync.js';
        $version = defined('SOI_ADMIN_ASSET_VERSION') ? SOI_ADMIN_ASSET_VERSION : '1.1.1';

        $base = rtrim(self::baseUrl(), '/');

        $config = [
            'enabled'           => true,
            'pollIntervalMs'    => self::getSessionPollIntervalSeconds() * 1000,
            'accountsMeUrl'     => $base . '/api/session/status',
            'accountsEventsUrl' => $base . '/api/session/events',
            'cmsLiveCheckUrl'   => rtrim(self::cmsBaseUrl(), '/') . '/soi-central/session/live-check',
            'cmsLogoutUrl'      => self::cmsLogoutUrl(),
        ];

        echo '<script>window.SOI_SESSION_SYNC = ' . json_encode($config, JSON_UNESCAPED_SLASHES) . ';</script>' . "\n";
        echo '<script src="' . self::html($assetPath) . '?v=' . self::html($version) . '" defer></script>' . "\n";
    }

    public static function isEnabled(): bool {
        return self::option('enabled', '1') === '1';
    }

    public static function shouldRenderEmbed(): bool {
        return self::appId() !== ''
            || self::embedKey() !== ''
            || self::isEnabled()
            || self::dbOption('my_account_app_id', '') !== ''
            || self::dbOption('my_account_public_key', '') !== '';
    }

    public static function isSamlConfigured(): bool {
        return self::isSamlConfiguredForLogin() && trim((string) self::option('saml_x509_cert', '')) !== '';
    }

    public static function isSamlConfiguredForLogin(): bool {
        return self::ssoUrl() !== '' && self::spEntityId() !== '' && self::acsUrl() !== '';
    }

    public static function appId(): string {
        $fromEnv = self::env('SOI_APP_ID');
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        $accountsAppId = trim((string) self::dbOption('accounts_app_id', ''));
        if ($accountsAppId !== '') {
            return $accountsAppId;
        }

        return (string) self::option('app_id', self::dbOption('my_account_app_id', ''));
    }

    /**
     * @return array{
     *   canonical_app_id: string,
     *   accounts_app_id: string,
     *   soi_central_app_id: string,
     *   client_id: string,
     *   mismatch: bool,
     *   detail: string,
     *   metadata_url: string
     * }
     */
    public static function validateAppIdConfiguration(): array {
        $canonical = self::appId();
        $accountsAppId = trim((string) self::dbOption('accounts_app_id', ''));
        $centralAppId = trim((string) self::option('app_id', ''));
        $clientId = trim((string) self::option('client_id', self::dbOption('accounts_client_id', '')));

        $mismatch = false;
        $detail = 'ok';

        if ($accountsAppId !== '' && $centralAppId !== '' && $accountsAppId !== $centralAppId) {
            $mismatch = true;
            $detail = 'soi_central_app_id (' . $centralAppId . ') does not match accounts_app_id (' . $accountsAppId . ')';
        } elseif ($accountsAppId !== '' && $centralAppId === $clientId && $clientId !== '' && $accountsAppId !== $clientId) {
            $mismatch = true;
            $detail = 'soi_central_app_id is set to OAuth client_id (' . $clientId . ') instead of accounts_app_id (' . $accountsAppId . ')';
        } elseif ($canonical === '' && $clientId !== '') {
            $mismatch = true;
            $detail = 'No accounts_app_id stored — SAML metadata may use wrong OAuth client_id';
        }

        return [
            'canonical_app_id'   => $canonical,
            'accounts_app_id'    => $accountsAppId,
            'soi_central_app_id' => $centralAppId,
            'client_id'          => $clientId,
            'mismatch'           => $mismatch,
            'detail'             => $detail,
            'metadata_url'       => self::metadataUrl(),
        ];
    }

    /**
     * Probe whether Accounts metadata is reachable for the configured app_id.
     *
     * @return array{valid: bool, error: string, cert_count: int}
     */
    public static function repairAppIdDrift(string $accountsAppId): void {
        $accountsAppId = trim($accountsAppId);
        if ($accountsAppId === '') {
            return;
        }

        Database::setOption('soi_central_app_id', $accountsAppId);
        Database::setOption(
            'soi_central_saml_sso_url',
            'https://accounts.soi.co.in/saml/sso?app=' . rawurlencode($accountsAppId)
        );
        Database::setOption(
            'soi_central_saml_metadata_url',
            'https://accounts.soi.co.in/saml/metadata?app=' . rawurlencode($accountsAppId)
        );
    }

    public static function validateAppIdWithAccounts(): array {
        $config = self::validateAppIdConfiguration();
        if ($config['canonical_app_id'] === '') {
            return ['valid' => false, 'error' => 'App ID is not configured.', 'cert_count' => 0];
        }

        if ($config['mismatch']) {
            self::logSaml('App ID configuration mismatch', $config);
        }

        try {
            $metadata = self::fetchMetadata();
            $certCount = count($metadata['x509_certs'] ?? []);
            if ($certCount === 0) {
                return [
                    'valid'      => false,
                    'error'      => 'Metadata fetched but no signing certificates found for app=' . $config['canonical_app_id'],
                    'cert_count' => 0,
                ];
            }

            return ['valid' => true, 'error' => '', 'cert_count' => $certCount];
        } catch (\Throwable $e) {
            return [
                'valid'      => false,
                'error'      => $e->getMessage(),
                'cert_count' => 0,
            ];
        }
    }

    public static function clientId(): string {
        return (string) (self::env('SOI_APP_CLIENT_ID') ?: self::option('client_id', ''));
    }

    public static function clientSecret(): string {
        $stored = trim((string) self::dbOption('soi_central_client_secret', ''));
        if ($stored !== '') {
            try {
                return Accounts::decryptSecret($stored);
            } catch (\Throwable) {
                return $stored;
            }
        }

        $accountsStored = trim((string) self::dbOption('accounts_client_secret', ''));
        if ($accountsStored !== '') {
            try {
                return Accounts::decryptSecret($accountsStored);
            } catch (\Throwable) {
                return $accountsStored;
            }
        }

        return self::env('SOI_APP_CLIENT_SECRET');
    }

    public static function baseUrl(): string {
        return rtrim((string) (self::env('SOI_CENTRAL_BASE_URL') ?: self::option('base_url', self::DEFAULT_BASE_URL)), '/');
    }

    public static function metadataUrl(): string {
        $configured = (string) (self::env('SOI_SAML_METADATA_URL') ?: self::option('saml_metadata_url', ''));
        if ($configured !== '') return self::withAppParam($configured);
        return self::appId() !== '' ? self::withAppParam(self::baseUrl() . '/saml/metadata') : '';
    }

    public static function ssoUrl(): string {
        $configured = (string) (self::env('SOI_SAML_SSO_URL') ?: self::option('saml_sso_url', self::dbOption('saml_sso_url', '')));
        if ($configured !== '') return self::withAppParam($configured);
        return self::appId() !== '' ? self::withAppParam(self::baseUrl() . '/saml/sso') : '';
    }

    /**
     * Canonical CMS public base URL (no trailing slash).
     * Prefers site_url from DB (set during install/Accounts link) over SOI_HOME_URL constant.
     */
    public static function cmsBaseUrl(): string {
        $configured = trim((string) self::dbOption('site_url', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return defined('SOI_HOME_URL') ? rtrim((string) SOI_HOME_URL, '/') : '';
    }

    /** Canonical SP metadata path (Accounts pre-live Section 9). */
    public static function spMetadataPath(): string {
        return '/saml/metadata';
    }

    public static function spMetadataUrl(): string {
        return self::cmsBaseUrl() . self::spMetadataPath();
    }

    public static function spEntityId(): string {
        return (string) self::option('saml_sp_entity_id', self::dbOption('saml_sp_entity_id', self::spMetadataUrl()));
    }

    /** Canonical SAML login path. */
    public static function samlLoginPath(): string {
        return '/saml/login';
    }

    public static function samlLoginUrl(string $returnTo = ''): string {
        $url = self::cmsBaseUrl() . self::samlLoginPath();
        if ($returnTo !== '') {
            $url .= '?return=' . rawurlencode($returnTo);
        }

        return $url;
    }

    /** Canonical ACS path — always without trailing slash. */
    public static function acsPath(): string {
        return '/saml/acs';
    }

    /**
     * Legacy ACS/metadata paths kept for backward-compatible routing only.
     *
     * @return list<string>
     */
    public static function legacySamlPaths(): array {
        return [
            '/soi-central/saml/acs',
            '/soi-central/saml/metadata',
            '/soi-central/saml/login',
        ];
    }

    /**
     * Normalize any ACS URL/path to the canonical form (no trailing slash).
     */
    public static function normalizeAcsUrl(string $urlOrPath): string {
        $urlOrPath = trim($urlOrPath);
        if ($urlOrPath === '') {
            return self::acsUrl();
        }

        if (str_starts_with($urlOrPath, '/')) {
            $path = rtrim($urlOrPath, '/');
            if ($path === '/soi-central/saml/acs') {
                $path = self::acsPath();
            }

            return self::cmsBaseUrl() . $path;
        }

        if (str_starts_with($urlOrPath, 'http://') || str_starts_with($urlOrPath, 'https://')) {
            $parts = parse_url($urlOrPath);
            $scheme = $parts['scheme'] ?? 'https';
            $host = $parts['host'] ?? '';
            $path = rtrim((string) ($parts['path'] ?? ''), '/');
            if ($path === '/soi-central/saml/acs') {
                $path = self::acsPath();
            }

            return $scheme . '://' . $host . $path;
        }

        return self::normalizeAcsUrl(self::cmsBaseUrl() . '/' . trim($urlOrPath, '/'));
    }

    /** Canonical ACS URL — always without trailing slash. */
    public static function acsUrl(): string {
        return self::normalizeAcsUrl(self::cmsBaseUrl() . self::acsPath());
    }

    /**
     * Align stored SP endpoints with Accounts pre-live canonical paths (/saml/*).
     */
    public static function repairCanonicalSamlEndpoints(): void {
        $base = self::cmsBaseUrl();
        if ($base === '') {
            return;
        }

        $canonicalSp = $base . self::spMetadataPath();
        $canonicalAcs = self::acsUrl();
        $updates = [];

        foreach (['soi_central_saml_sp_entity_id', 'saml_sp_entity_id'] as $key) {
            $value = trim((string) self::dbOption($key, ''));
            if ($value === '' || str_contains($value, '/soi-central/saml/')) {
                $updates[$key] = $canonicalSp;
            }
        }

        foreach (['soi_central_saml_acs_url'] as $key) {
            $value = trim((string) self::dbOption($key, ''));
            if ($value === '' || str_contains($value, '/soi-central/saml/')) {
                $updates[$key] = $canonicalAcs;
            }
        }

        if ($updates !== []) {
            Database::setOptions($updates);
            self::logSaml('Repaired canonical SAML endpoints', [
                'sp_entity_id' => $canonicalSp,
                'acs_url'      => $canonicalAcs,
            ]);
        }
    }

    public static function currentUrl(): string {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? parse_url(SOI_HOME_URL, PHP_URL_HOST);
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        return $scheme . '://' . $host . $uri;
    }

    public static function manifest(): array {
        $siteName = (string) self::dbOption('site_name', 'SOI CMS');
        $slug = self::appId() ?: strtolower(preg_replace('/[^a-z0-9]+/i', '-', $siteName));
        $manifest = [
            'version' => '1.0.0',
            'app' => [
                'name' => $siteName,
                'slug' => trim($slug, '-'),
                'base_url' => self::cmsBaseUrl(),
                'metadata_url' => self::spMetadataUrl(),
                'acs_url' => self::acsUrl(),
            ],
            'roles' => [
                ['name' => 'Administrator', 'slug' => 'administrator', 'description' => 'Can manage the full CMS, settings, users, plugins, updates, and SOI Central integration.'],
                ['name' => 'Editor', 'slug' => 'editor', 'description' => 'Can publish and manage CMS content.'],
                ['name' => 'Author', 'slug' => 'author', 'description' => 'Can create and edit assigned CMS content.'],
                ['name' => 'Subscriber', 'slug' => 'subscriber', 'description' => 'Can access protected CMS areas assigned to basic users.'],
            ],
            'permissions' => [
                ['name' => 'Full CMS Administration', 'slug' => 'cms.admin', 'category' => 'cms', 'description' => 'Can access all administrative capabilities.'],
                ['name' => 'Manage Settings', 'slug' => 'cms.settings.manage', 'category' => 'cms', 'description' => 'Can edit site and integration settings.'],
                ['name' => 'Manage Users', 'slug' => 'cms.users.manage', 'category' => 'cms', 'description' => 'Can create, edit, and deactivate local users.'],
                ['name' => 'Create Content', 'slug' => 'cms.content.create', 'category' => 'content', 'description' => 'Can create CMS content.'],
                ['name' => 'Edit Content', 'slug' => 'cms.content.edit', 'category' => 'content', 'description' => 'Can edit CMS content.'],
                ['name' => 'Publish Content', 'slug' => 'cms.content.publish', 'category' => 'content', 'description' => 'Can publish CMS content.'],
                ['name' => 'Manage Media', 'slug' => 'cms.media.manage', 'category' => 'content', 'description' => 'Can upload and manage media.'],
                ['name' => 'Manage Plugins', 'slug' => 'cms.plugins.manage', 'category' => 'platform', 'description' => 'Can activate and manage plugins.'],
                ['name' => 'Install Updates', 'slug' => 'cms.updates.manage', 'category' => 'platform', 'description' => 'Can install update packages.'],
                ['name' => 'Manage Knowledge Base', 'slug' => 'kb.content.manage', 'category' => 'knowledge', 'description' => 'Can manage knowledge base content.'],
                ['name' => 'Administer Knowledge Base', 'slug' => 'kb.admin', 'category' => 'knowledge', 'description' => 'Can administer knowledge base settings and users.'],
                ['name' => 'Manage Search', 'slug' => 'search.manage', 'category' => 'search', 'description' => 'Can manage search console sources and settings.'],
            ],
        ];

        return class_exists(Hook::class) ? Hook::applyFilters('soi_central_manifest', $manifest) : $manifest;
    }
    private static function validateSamlResponse(string $base64): array {
        $xml = self::decodeSamlResponsePayload($base64);
        if (trim($xml) === '') {
            throw new RuntimeException('SAMLResponse is empty or could not be decoded.');
        }
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new RuntimeException('SAMLResponse contains prohibited XML declarations.');
        }

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        libxml_use_internal_errors(true);
        if (!$dom->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('SAMLResponse XML could not be parsed.');
        }
        libxml_clear_errors();

        $xp = self::samlXPath($dom);
        $response = $dom->documentElement;
        if (!$response instanceof DOMElement || $response->localName !== 'Response') {
            throw new RuntimeException('SAMLResponse root element is not a SAML Response.');
        }

        self::assertSuccessStatus($xp);
        self::assertDestination($response);

        $assertions = $xp->query('/samlp:Response/saml:Assertion');
        if (!$assertions || $assertions->length !== 1) {
            throw new RuntimeException('SAMLResponse must contain exactly one assertion.');
        }
        $assertion = $assertions->item(0);
        if (!$assertion instanceof DOMElement) throw new RuntimeException('SAML assertion is invalid.');

        $signedElement = self::signedAssertion($xp) ?: (self::hasDirectSignature($response, $xp) ? $response : null);
        if (!$signedElement) {
            throw new RuntimeException('SAMLResponse is not signed by SOI Central.');
        }
        self::validateXmlSignature($signedElement, $xp);

        self::assertIssuer($xp);
        self::assertConditions($assertion, $xp);
        self::assertSubjectConfirmation($assertion, $xp);
        self::assertNotReplayed($response, $assertion);

        $profile = self::extractProfile($assertion, $xp);
        $profile['raw_assertion_id'] = $assertion->getAttribute('ID');
        $profile['raw_response_id'] = $response->getAttribute('ID');
        return $profile;
    }

    /**
     * Structured diagnostics for SAML signature verification failures.
     *
     * @param array{
     *   keyinfo_certs?: list<string>,
     *   candidate_certs?: list<string>,
     *   last_openssl_error?: string,
     *   empty_certs?: bool,
     * } $context
     * @return array{
     *   metadata_url: string,
     *   stored_cert_count: int,
     *   keyinfo_cert_count: int,
     *   attempted_cert_count: int,
     *   cert_fingerprints: list<string>,
     *   last_openssl_error: string,
     *   metadata_status: array{status: string, error: string, reason: string, fetched_at: ?string, cert_count: int, stale: bool, metadata_url: string},
     *   app_config: array{
     *     canonical_app_id: string,
     *     accounts_app_id: string,
     *     soi_central_app_id: string,
     *     client_id: string,
     *     mismatch: bool,
     *     detail: string,
     *     metadata_url: string
     *   },
     *   app_id_mismatch: bool,
     *   relink_guidance: string,
     *   message: string,
     *   hint: string,
     * }
     */
    private static function buildSignatureFailureDiagnostics(array $context = []): array {
        $metaStatus = self::getMetadataFetchStatus();
        $appConfig = self::validateAppIdConfiguration();
        $storedCount = count(self::getStoredIdpCertificates());
        $keyInfoCerts = $context['keyinfo_certs'] ?? [];
        $candidateCerts = $context['candidate_certs'] ?? [];
        $keyinfoCount = count($keyInfoCerts);
        $attemptedCount = count($candidateCerts);
        $fingerprints = self::certFingerprints($candidateCerts);
        $lastError = trim((string) ($context['last_openssl_error'] ?? ''));
        $metadataUrl = $appConfig['metadata_url'] !== '' ? $appConfig['metadata_url'] : (string) ($metaStatus['metadata_url'] ?? '');
        $emptyCerts = !empty($context['empty_certs']);

        $message = $emptyCerts
            ? 'SOI Central X.509 signing certificate is not available for verification.'
            : 'Application request signature could not be verified. The CMS automatically refreshed IdP metadata from Accounts and tried all available signing certificates, but verification still failed.';

        $message .= ' Metadata URL: ' . ($metadataUrl !== '' ? $metadataUrl : '(not configured)') . '.';
        $message .= ' Stored certificates: ' . $storedCount . '; KeyInfo certificates in response: ' . $keyinfoCount . '.';

        if ($attemptedCount > 0) {
            $message .= ' Attempted ' . $attemptedCount . ' trusted certificate(s) with fingerprints: ' . implode(', ', $fingerprints) . '.';
        } elseif ($keyinfoCount > 0 && $storedCount === 0) {
            $message .= ' KeyInfo contained ' . $keyinfoCount . ' certificate(s) but none matched stored or freshly fetched IdP metadata.';
        } elseif ($emptyCerts) {
            $message .= ' The CMS attempted automatic metadata refresh but no trusted signing certificate could be resolved.';
        }

        if ($lastError !== '') {
            $message .= ' Last OpenSSL verify error: ' . $lastError . '.';
        }

        if ($appConfig['mismatch']) {
            $message .= ' App ID mismatch detected: ' . $appConfig['detail']
                . '. Re-link Accounts from Admin → SOI Central to update credentials and app_id.';
        } else {
            $message .= ' Stored app_id=' . ($appConfig['canonical_app_id'] !== '' ? $appConfig['canonical_app_id'] : '(not configured)')
                . '. If Accounts has a newer application record, re-link from Admin → SOI Central.';
        }

        if (($metaStatus['error'] ?? '') !== '') {
            $message .= ' Last metadata fetch error: ' . $metaStatus['error'] . '.';
        }

        $relinkGuidance = 'Open Admin → SOI Central ('
            . SOI_ADMIN_URL . '/soi-central.php'
            . ') and use Re-link Accounts to refresh app_id, client credentials, and SAML signing certificates. '
            . 'Sync Credentials or Fetch Metadata can recover drift without a full OAuth round-trip.';

        $fetchedAt = $metaStatus['fetched_at'] ?? 'never';
        $hint = 'IdP metadata URL: ' . ($metadataUrl !== '' ? $metadataUrl : '(not configured)')
            . '. Last metadata fetch: ' . $fetchedAt
            . ' (' . ($metaStatus['cert_count'] ?? 0) . ' stored certificate(s), status=' . ($metaStatus['status'] ?? 'unknown') . ').'
            . ' Stored certs: ' . $storedCount . '; KeyInfo certs in SAML response: ' . $keyinfoCount . '.';

        if ($attemptedCount > 0) {
            $hint .= ' Fingerprints attempted: ' . implode(', ', $fingerprints) . '.';
        }

        if ($lastError !== '') {
            $hint .= ' Last OpenSSL verify error: ' . $lastError . '.';
        }

        if ($appConfig['mismatch']) {
            $hint .= ' App ID mismatch: ' . $appConfig['detail'] . '.';
        } else {
            $hint .= ' Current app_id=' . ($appConfig['canonical_app_id'] !== '' ? $appConfig['canonical_app_id'] : '(not configured)') . '.';
        }

        if (($metaStatus['error'] ?? '') !== '') {
            $hint .= ' Last metadata fetch error: ' . $metaStatus['error'] . '.';
        }

        $hint .= ' ' . $relinkGuidance;

        return [
            'metadata_url'          => $metadataUrl,
            'stored_cert_count'     => $storedCount,
            'keyinfo_cert_count'    => $keyinfoCount,
            'attempted_cert_count'  => $attemptedCount,
            'cert_fingerprints'     => $fingerprints,
            'last_openssl_error'    => $lastError,
            'metadata_status'       => $metaStatus,
            'app_config'            => $appConfig,
            'app_id_mismatch'       => $appConfig['mismatch'],
            'relink_guidance'       => $relinkGuidance,
            'message'               => $message,
            'hint'                  => $hint,
        ];
    }

    private static function validateXmlSignature(DOMElement $signedElement, DOMXPath $xp): void {
        $signature = self::directSignature($signedElement, $xp);
        if (!$signature) {
            throw new RuntimeException('Signed SAML element did not contain a direct Signature.');
        }

        $signedInfo = $xp->query('./ds:SignedInfo', $signature)->item(0);
        $signatureValue = $xp->query('./ds:SignatureValue', $signature)->item(0);
        $signatureMethod = $xp->query('./ds:SignedInfo/ds:SignatureMethod/@Algorithm', $signature)->item(0);
        if (!$signedInfo instanceof DOMNode || !$signatureValue || !$signatureMethod) {
            throw new RuntimeException('SAML Signature is missing required fields.');
        }

        self::validateReferenceDigest($signedElement, $signature, $xp);

        $canonicalMethod = $xp->query('./ds:SignedInfo/ds:CanonicalizationMethod', $signature)->item(0);
        $canonicalAlgorithm = $canonicalMethod instanceof DOMElement ? $canonicalMethod->getAttribute('Algorithm') : '';
        $canonicalSignedInfo = self::canonicalize($signedInfo, $canonicalAlgorithm, self::inclusiveNamespaces($canonicalMethod));
        $algo = self::opensslAlgo($signatureMethod->nodeValue);
        $signatureBytes = base64_decode(preg_replace('/\s+/', '', $signatureValue->nodeValue), true);
        if ($signatureBytes === false) {
            throw new RuntimeException('SAML SignatureValue is not valid base64.');
        }

        $keyInfoCerts = self::extractSignatureCertificates($signature, $xp);
        $candidateCerts = self::resolveIdpVerificationCertificates($signature, $xp);
        if ($candidateCerts === []) {
            $diagnostics = self::buildSignatureFailureDiagnostics([
                'keyinfo_certs'   => $keyInfoCerts,
                'candidate_certs' => [],
                'empty_certs'     => true,
            ]);
            throw new RuntimeException($diagnostics['message']);
        }

        $lastOpenSslError = '';
        $verified = self::attemptSignatureVerification(
            $candidateCerts,
            $canonicalSignedInfo,
            $signatureBytes,
            $algo,
            (string) $signatureMethod->nodeValue,
            'initial',
            $lastOpenSslError
        );
        if ($verified) {
            return;
        }

        self::logSaml('SAML signature verify failed — forcing metadata refresh and retry', [
            'cert_count' => count($candidateCerts),
            'fingerprints' => self::certFingerprints($candidateCerts),
        ]);

        $retryCerts = $candidateCerts;
        try {
            self::refreshIdpMetadata(true, 'signature_verify_retry');
            self::recordMetadataFetchStatus('ok', null, 'signature_verify_retry');
            $retryCerts = self::resolveIdpVerificationCertificates($signature, $xp);
            if (self::attemptSignatureVerification(
                $retryCerts,
                $canonicalSignedInfo,
                $signatureBytes,
                $algo,
                (string) $signatureMethod->nodeValue,
                'after_metadata_refresh',
                $lastOpenSslError
            )) {
                return;
            }
        } catch (\Throwable $e) {
            self::recordMetadataFetchStatus('error', $e->getMessage(), 'signature_verify_retry');
            self::logSaml('Metadata refresh during signature retry failed', ['error' => $e->getMessage()]);
        }

        $diagnostics = self::buildSignatureFailureDiagnostics([
            'keyinfo_certs'      => $keyInfoCerts,
            'candidate_certs'    => $retryCerts,
            'last_openssl_error' => $lastOpenSslError,
        ]);
        throw new RuntimeException($diagnostics['message']);
    }

    /**
     * @param list<string> $candidateCerts
     */
    private static function attemptSignatureVerification(
        array $candidateCerts,
        string $canonicalSignedInfo,
        string $signatureBytes,
        int $algo,
        string $algorithmUri,
        string $phase,
        string &$lastOpenSslError = ''
    ): bool {
        self::logSaml('SAML signature verify phase', [
            'phase'        => $phase,
            'cert_count'   => count($candidateCerts),
            'fingerprints' => self::certFingerprints($candidateCerts),
        ]);

        foreach ($candidateCerts as $index => $cert) {
            $ok = openssl_verify($canonicalSignedInfo, $signatureBytes, $cert, $algo);
            if ($ok === 1) {
                self::logSaml('SAML signature verified', [
                    'phase'      => $phase,
                    'cert_index' => $index,
                    'fingerprint'=> self::certFingerprint($cert),
                    'algorithm'  => $algorithmUri,
                ]);
                if ($index > 0 || self::option('saml_x509_cert', '') === '') {
                    self::persistIdpCertificates(self::mergeIdpCertificates([$cert]), false);
                }
                return true;
            }

            if ($ok === -1) {
                $lastOpenSslError = (string) openssl_error_string();
            }

            self::logSaml('SAML signature verify attempt failed', [
                'phase'      => $phase,
                'cert_index' => $index,
                'fingerprint'=> self::certFingerprint($cert),
                'result'     => $ok,
                'openssl'    => $lastOpenSslError,
            ]);
        }

        return false;
    }
    private static function validateReferenceDigest(DOMElement $signedElement, DOMElement $signature, DOMXPath $xp): void {
        $reference = $xp->query('./ds:SignedInfo/ds:Reference', $signature)->item(0);
        if (!$reference instanceof DOMElement) throw new RuntimeException('SAML signature Reference is missing.');
        $uri = $reference->getAttribute('URI');
        $elementId = $signedElement->getAttribute('ID') ?: $signedElement->getAttribute('Id') ?: $signedElement->getAttribute('id');

        if ($uri === '' || $uri === '#') {
            // Enveloped signature — empty URI references the containing signed element.
            self::logSaml('SAML digest using enveloped empty URI reference');
        } elseif (str_starts_with($uri, '#')) {
            $refId = substr($uri, 1);
            if ($refId !== '' && $elementId !== '' && $elementId !== $refId) {
                throw new RuntimeException('SAML signature Reference does not match the signed element.');
            }
        } else {
            throw new RuntimeException('SAML signature Reference must use a local ID or enveloped signature.');
        }

        $transformAlgorithm = '';
        $inclusivePrefixes = [];
        $removeEnvelopedSignature = false;
        foreach ($xp->query('./ds:Transforms/ds:Transform', $reference) as $transform) {
            if (!$transform instanceof DOMElement) continue;
            $algorithm = $transform->getAttribute('Algorithm');
            if (str_contains($algorithm, 'enveloped-signature')) {
                $removeEnvelopedSignature = true;
                continue;
            }
            if (str_contains($algorithm, 'c14n')) {
                $transformAlgorithm = $algorithm;
                $inclusivePrefixes = self::inclusiveNamespaces($transform);
                continue;
            }
            throw new RuntimeException('Unsupported SAML signature transform.');
        }

        $canonical = '';
        if ($removeEnvelopedSignature) {
            $parent = $signature->parentNode;
            $nextSibling = $signature->nextSibling;
            if (!$parent) throw new RuntimeException('SAML signature cannot be detached for digest validation.');
            $detachedSignature = $parent->removeChild($signature);
            try {
                $canonical = self::canonicalize($signedElement, $transformAlgorithm, $inclusivePrefixes);
            } finally {
                if ($nextSibling && $nextSibling->parentNode === $parent) {
                    $parent->insertBefore($detachedSignature, $nextSibling);
                } else {
                    $parent->appendChild($detachedSignature);
                }
            }
        } else {
            $canonical = self::canonicalize($signedElement, $transformAlgorithm, $inclusivePrefixes);
        }

        $digestMethod = $xp->query('./ds:DigestMethod/@Algorithm', $reference)->item(0);
        $digestValue = $xp->query('./ds:DigestValue', $reference)->item(0);
        if (!$digestMethod || !$digestValue) throw new RuntimeException('SAML signature digest is missing.');

        $computed = base64_encode(hash(self::digestAlgo($digestMethod->nodeValue), $canonical, true));
        $expected = preg_replace('/\s+/', '', $digestValue->nodeValue);
        if (!hash_equals($expected, $computed)) {
            $soiCentralComputed = self::soiCentralCompatibleDigest($signedElement, self::digestAlgo($digestMethod->nodeValue));
            if (!hash_equals($expected, $soiCentralComputed)) {
                throw new RuntimeException('SAML signature digest verification failed.');
            }
        }
    }

    private static function assertSuccessStatus(DOMXPath $xp): void {
        $status = $xp->query('/samlp:Response/samlp:Status/samlp:StatusCode/@Value')->item(0);
        if ($status && str_ends_with($status->nodeValue, ':Success')) {
            return;
        }

        $statusMessage = $xp->query('/samlp:Response/samlp:Status/samlp:StatusMessage')->item(0);
        $detail = $statusMessage ? trim($statusMessage->nodeValue) : '';
        $code = $status ? trim($status->nodeValue) : 'unknown';

        self::logSaml('SAML response status failure', ['code' => $code, 'message' => $detail]);

        if ($detail !== '') {
            throw new RuntimeException($detail);
        }

        throw new RuntimeException('SOI Central did not return a successful SAML status (' . $code . ').');
    }

    private static function assertDestination(DOMElement $response): void {
        $destination = $response->getAttribute('Destination');
        if ($destination !== '' && !self::sameUrlAny($destination, self::acceptedAcsUrls())) {
            throw new RuntimeException('SAMLResponse Destination does not match this application ACS URL.');
        }
    }

    private static function assertIssuer(DOMXPath $xp): void {
        $expected = trim((string) self::option('saml_idp_entity_id', self::dbOption('saml_idp_entity_id', '')));
        if ($expected === '') return;
        $issuer = $xp->query('/samlp:Response/saml:Issuer')->item(0) ?: $xp->query('/samlp:Response/saml:Assertion/saml:Issuer')->item(0);
        if (!$issuer || trim($issuer->nodeValue) !== $expected) {
            throw new RuntimeException('SAML issuer does not match the configured SOI Central IdP entity ID.');
        }
    }

    private static function assertConditions(DOMElement $assertion, DOMXPath $xp): void {
        $skew = (int) self::option('timestamp_skew', '300');
        $now = time();
        $conditions = $xp->query('./saml:Conditions', $assertion)->item(0);
        if ($conditions instanceof DOMElement) {
            $notBefore = $conditions->getAttribute('NotBefore');
            $notOnOrAfter = $conditions->getAttribute('NotOnOrAfter');
            if ($notBefore && $now + $skew < strtotime($notBefore)) {
                throw new RuntimeException('SAML assertion is not valid yet.');
            }
            if ($notOnOrAfter && $now - $skew >= strtotime($notOnOrAfter)) {
                throw new RuntimeException('SAML assertion has expired.');
            }
        }

        $audiences = [];
        foreach ($xp->query('./saml:Conditions/saml:AudienceRestriction/saml:Audience', $assertion) as $audience) {
            $audiences[] = trim($audience->nodeValue);
        }
        if ($audiences && !self::audienceMatches($audiences)) {
            throw new RuntimeException('SAML assertion audience does not include this application.');
        }
    }

    private static function assertSubjectConfirmation(DOMElement $assertion, DOMXPath $xp): void {
        $skew = (int) self::option('timestamp_skew', '300');
        $data = $xp->query('./saml:Subject/saml:SubjectConfirmation/saml:SubjectConfirmationData', $assertion)->item(0);
        if (!$data instanceof DOMElement) throw new RuntimeException('SAML SubjectConfirmationData is missing.');

        $recipient = $data->getAttribute('Recipient');
        if ($recipient !== '' && !self::sameUrlAny($recipient, self::acceptedAcsUrls())) {
            throw new RuntimeException('SAML Subject recipient does not match this application ACS URL.');
        }

        $notOnOrAfter = $data->getAttribute('NotOnOrAfter');
        if ($notOnOrAfter && time() - $skew >= strtotime($notOnOrAfter)) {
            throw new RuntimeException('SAML SubjectConfirmationData has expired.');
        }

        $expectedRequest = $_SESSION[self::SAML_REQUEST_KEY] ?? '';
        $inResponseTo = $data->getAttribute('InResponseTo');
        if ($expectedRequest !== '' && $inResponseTo !== '' && !hash_equals($expectedRequest, $inResponseTo)) {
            throw new RuntimeException('SAML InResponseTo did not match the pending login request.');
        }
    }

    private static function assertNotReplayed(DOMElement $response, DOMElement $assertion): void {
        $ids = array_filter([$response->getAttribute('ID'), $assertion->getAttribute('ID')]);
        self::ensureReplayTable();
        $table = Database::prefix('central_saml_replay');
        Database::query("DELETE FROM `$table` WHERE expires_at < NOW()");
        $expiresAt = date('Y-m-d H:i:s', time() + 3600);
        foreach ($ids as $id) {
            $existing = Database::selectOne("SELECT id FROM `$table` WHERE saml_id = ?", [$id]);
            if ($existing) throw new RuntimeException('SAML response replay was detected.');
            Database::insert('central_saml_replay', ['saml_id' => $id, 'expires_at' => $expiresAt, 'created_at' => date('Y-m-d H:i:s')]);
        }
    }
    private static function extractProfile(DOMElement $assertion, DOMXPath $xp): array {
        $nameId = $xp->query('./saml:Subject/saml:NameID', $assertion)->item(0);
        $attributes = [];
        foreach ($xp->query('./saml:AttributeStatement/saml:Attribute', $assertion) as $attribute) {
            if (!$attribute instanceof DOMElement) continue;
            $name = $attribute->getAttribute('Name') ?: $attribute->getAttribute('FriendlyName');
            if ($name === '') continue;
            $values = [];
            foreach ($xp->query('./saml:AttributeValue', $attribute) as $value) {
                $values[] = trim($value->textContent);
            }
            $attributes[$name] = count($values) <= 1 ? ($values[0] ?? '') : $values;
        }

        $email = self::firstAttribute($attributes, ['email', 'mail', 'Email', 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress']);
        if ($email === '' && $nameId) $email = trim($nameId->textContent);

        return [
            'soi_user_id' => self::firstAttribute($attributes, ['user_id', 'id', 'uid', 'soiUserId']),
            'email' => $email,
            'username' => self::firstAttribute($attributes, ['username', 'userName', 'preferred_username']),
            'display_name' => self::firstAttribute($attributes, ['displayName', 'name', 'cn']),
            'first_name' => self::firstAttribute($attributes, ['firstName', 'givenName']),
            'last_name' => self::firstAttribute($attributes, ['lastName', 'sn', 'surname']),
            'department' => self::firstAttribute($attributes, ['department']),
            'team' => self::firstAttribute($attributes, ['team']),
            'designation' => self::firstAttribute($attributes, ['designation']),
            'app_access_status' => self::firstAttribute($attributes, ['appAccessStatus']),
            'app_access_version' => self::firstAttribute($attributes, ['appAccessVersion']),
            'app_roles' => self::splitAttribute(self::firstAttribute($attributes, ['appRoles', 'roles'])),
            'app_permissions' => self::splitAttribute(self::firstAttribute($attributes, ['appPermissions', 'permissions'])),
            'account_session_id_hash' => self::firstAttribute($attributes, ['account_session_id_hash']),
            'session_version' => self::firstAttribute($attributes, ['session_version']),
            'roles_version' => self::firstAttribute($attributes, ['roles_version']),
            'permissions_version' => self::firstAttribute($attributes, ['permissions_version']),
            'access_version' => self::firstAttribute($attributes, ['access_version']),
            'expires_at' => self::firstAttribute($attributes, ['expires_at']),
            'attributes' => $attributes,
        ];
    }

    private static function provisionOrUpdateLocalUser(array $profile, array $access): array {
        self::ensureUserColumns();
        $email = strtolower(trim((string) $profile['email']));
        $username = trim((string) ($profile['username'] ?: strtok($email, '@')));
        $username = self::safeUsername($username);
        $displayName = trim((string) ($profile['display_name'] ?: trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? '')) ?: $username));
        $role = self::roleFromAccess($access, $profile);
        $usersTable = Database::prefix('users');

        $user = Database::selectOne("SELECT * FROM `$usersTable` WHERE email = ?", [$email]);
        if (($access['status'] ?? '') === 'directory_not_configured' && empty($profile['app_roles']) && empty($profile['app_permissions'])) {
            $role = 'admin';
        }
        if (!$user) {
            if (self::option('auto_provision', '1') !== '1') {
                throw new RuntimeException('No local CMS user exists for this SOI Central account.');
            }
            $username = self::uniqueUsername($username, $email);
            Database::insert('users', [
                'username' => $username,
                'email' => $email,
                'password' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                'role' => $role,
                'display_name' => $displayName,
                'status' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $user = Database::selectOne("SELECT * FROM `$usersTable` WHERE email = ?", [$email]);
        }

        if (!$user || (int) $user['status'] !== 1) {
            throw new RuntimeException('The local CMS user is disabled.');
        }

        $update = [
            'display_name' => $displayName,
            'last_login' => date('Y-m-d H:i:s'),
            'soi_central_user_id' => (string) ($profile['soi_user_id'] ?? ''),
            'soi_central_access_version' => (string) ($access['access_version'] ?? $profile['app_access_version'] ?? ''),
            'soi_central_last_sync' => date('Y-m-d H:i:s'),
        ];
        if (self::option('sync_local_role', '1') === '1') $update['role'] = $role;
        Database::update('users', $update, 'id = ?', [$user['id']]);

        return Database::selectOne("SELECT * FROM `$usersTable` WHERE id = ?", [$user['id']]) ?: $user;
    }

    private static function writeUserSession(array $user, array $profile, array $access): void {
        $sessionProfile = $profile;
        unset($sessionProfile['raw_assertion_id'], $sessionProfile['raw_response_id']);

        $_SESSION[self::SESSION_USER_KEY] = [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role'],
            'display_name' => $user['display_name'],
            'soi_central_authenticated' => 1,
            'soi_central_user_id' => $profile['soi_user_id'] ?? '',
            'soi_central_profile' => $sessionProfile,
            'soi_central_roles' => self::pluckSlugs($access['roles'] ?? []),
            'soi_central_permissions' => self::pluckSlugs($access['permissions'] ?? []),
            'soi_central_access_version' => $access['access_version'] ?? '',
        ];
        $_SESSION['soi_access'] = $access;
        self::bindCentralSession($profile, $access);
        session_regenerate_id(true);
    }

    private static function signedRequest(string $method, string $path, array $payload = []): array {
        $method = strtoupper($method);
        $rawBody = $method === 'GET' ? '' : json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($rawBody === false) throw new RuntimeException('Could not encode SOI Directory request JSON.');
        $timestamp = (string) time();
        $base = $method . "\n" . $path . "\n" . $timestamp . "\n" . $rawBody;
        $signature = hash_hmac('sha256', $base, self::clientSecret());
        $headers = [
            'Content-Type: application/json',
            'X-SOI-Client-ID: ' . self::clientId(),
            'X-SOI-Timestamp: ' . $timestamp,
            'X-SOI-Signature: sha256=' . $signature,
        ];

        $url = self::baseUrl() . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($method !== 'GET') curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) throw new RuntimeException('SOI Directory request failed: ' . $error);
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) $data = ['success' => false, 'message' => trim((string) $raw)];
        if ($status < 200 || $status >= 300 || empty($data['success'])) {
            $message = (string) ($data['message'] ?? ('SOI Directory returned HTTP ' . $status));
            if ($status === 401 || stripos($message, 'signature') !== false) {
                $message = 'Directory API request signature could not be verified. '
                    . 'Re-link Accounts from Admin → SOI Central to refresh client_secret, '
                    . 'or confirm Accounts v2.35.3+ duplicate-app HMAC fix is applied. '
                    . 'Original: ' . $message;
            }
            throw new RuntimeException($message);
        }
        return $data;
    }

    private static function handleWebhook(string $method): void {
        if ($method !== 'POST') {
            http_response_code(405);
            echo 'Method Not Allowed';
            exit;
        }
        $secret = trim((string) self::option('webhook_secret', ''));
        if ($secret === '') {
            http_response_code(503);
            echo 'Webhook secret is not configured.';
            exit;
        }

        $raw = file_get_contents('php://input') ?: '';
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $timestamp = $headers['X-SOI-Timestamp'] ?? $headers['x-soi-timestamp'] ?? '';
        $signature = $headers['X-SOI-Signature'] ?? $headers['x-soi-signature'] ?? '';
        $event = $headers['X-SOI-Event'] ?? $headers['x-soi-event'] ?? '';
        $skew = (int) self::option('timestamp_skew', '300');

        if (!ctype_digit((string) $timestamp) || abs(time() - (int) $timestamp) > $skew) {
            http_response_code(401);
            echo 'Invalid webhook timestamp.';
            exit;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $raw, $secret);
        if (!hash_equals($expected, $signature)) {
            http_response_code(401);
            echo 'Invalid webhook signature.';
            exit;
        }

        $payload = json_decode($raw, true) ?: [];
        $eventId = hash('sha256', $timestamp . '.' . $raw);
        $existing = Database::selectOne("SELECT id FROM `" . Database::prefix('central_webhook_events') . "` WHERE event_id = ?", [$eventId]);
        if (!$existing) {
            Database::insert('central_webhook_events', [
                'event_id' => $eventId,
                'event_name' => (string) ($payload['event'] ?? $event),
                'access_version' => (string) ($payload['access_version'] ?? ''),
                'payload_json' => $raw,
                'received_at' => date('Y-m-d H:i:s'),
            ]);
        }
        if (str_starts_with((string) ($payload['event'] ?? $event), 'access.')) {
            Database::query("DELETE FROM `" . Database::prefix('central_access_cache') . "`");
        }

        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }

    private static function renderSpMetadata(): void {
        $entity = self::spEntityId();
        $acs = self::acsUrl();
        header('Content-Type: application/samlmetadata+xml; charset=UTF-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="' . self::xml($entity) . '">';
        echo '<md:SPSSODescriptor AuthnRequestsSigned="false" WantAssertionsSigned="true" protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">';
        echo '<md:NameIDFormat>urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress</md:NameIDFormat>';
        echo '<md:AssertionConsumerService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST" Location="' . self::xml($acs) . '" index="1" isDefault="true"/>';
        echo '</md:SPSSODescriptor></md:EntityDescriptor>';
        exit;
    }

    private static function renderManifest(): void {
        header('Content-Type: application/json');
        echo json_encode(self::manifest(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private static function renderHealth(): void {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'integration_version' => self::VERSION,
            'mode' => 'forced_saml_global_auth',
            'enabled' => self::isEnabled(),
            'app_id_present' => self::appId() !== '',
            'client_id_present' => self::clientId() !== '',
            'client_secret_present' => self::clientSecret() !== '',
            'directory_required' => self::option('directory_required', '0') === '1',
            'directory_checks_active' => self::hasApiCredentials(),
            'saml_login_ready' => self::isSamlConfiguredForLogin(),
            'saml_validation_ready' => self::isSamlConfigured(),
            'replay_table_ready' => self::tableReady('central_saml_replay'),
            'access_cache_table_ready' => self::tableReady('central_access_cache'),
            'webhook_table_ready' => self::tableReady('central_webhook_events'),
            'sp_entity_id' => self::spEntityId(),
            'accepted_audience_values' => self::acceptedAudienceValues(),
            'accepted_acs_urls' => self::acceptedAcsUrls(),
            'metadata_url' => self::spMetadataUrl(),
            'acs_url' => self::acsUrl(),
            'acs_path' => self::acsPath(),
            'sp_metadata_path' => self::spMetadataPath(),
            'saml_login_path' => self::samlLoginPath(),
            'legacy_saml_paths_supported' => self::legacySamlPaths(),
            'acs_url_has_trailing_slash' => false,
            'acs_post_preservation' => 'Physical /saml/acs (extensionless file) + saml/.htaccess DirectorySlash Off and internal rewrite to acs.php; never external 301/302 on ACS',
            'manifest_url' => self::cmsBaseUrl() . '/soi-central/manifest.json',
            'webhook_url' => self::cmsBaseUrl() . '/soi-central/webhook',
            'idp_metadata_url' => self::metadataUrl(),
            'accounts_alignment' => class_exists(Accounts::class) ? Accounts::getAlignmentStatus() : null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
    private static function renderAccessDenied(array $access): void {
        http_response_code(403);
        $message = $access['message'] ?? 'Your SOI Central account does not currently have access to this application. Please request access or contact IT support.';
        $requestUrl = $access['request_access_url'] ?? '';
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Access denied</title>';
        echo '<style>body{font-family:Inter,Arial,sans-serif;background:#f8fafc;color:#0f172a;margin:0;display:grid;place-items:center;min-height:100vh;padding:24px}.box{max-width:560px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:28px;box-shadow:0 24px 80px rgba(15,23,42,.12)}h1{margin:0 0 12px;font-size:24px}p{line-height:1.6;color:#475569}.btn{display:inline-flex;margin-top:12px;padding:10px 14px;background:#0f172a;color:#fff;text-decoration:none;border-radius:8px;font-weight:700}</style></head><body><main class="box">';
        echo '<h1>Access denied by SOI Central</h1><p>' . self::html($message) . '</p>';
        if ($requestUrl !== '') echo '<a class="btn" href="' . self::html($requestUrl) . '">Request access</a>';
        echo '</main></body></html>';
        exit;
    }

    private static function renderError(string $title, string $message, int $code): void {
        http_response_code($code);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>' . self::html($title) . '</title>';
        echo '<style>body{font-family:Inter,Arial,sans-serif;background:#fff7ed;color:#431407;margin:0;display:grid;place-items:center;min-height:100vh;padding:24px}.box{max-width:620px;background:#fff;border:1px solid #fed7aa;border-radius:12px;padding:28px;box-shadow:0 18px 60px rgba(154,52,18,.12)}h1{margin:0 0 12px;font-size:24px}p{line-height:1.6;margin:0 0 16px}a.btn{display:inline-block;margin-top:8px;padding:10px 16px;background:#ea580c;color:#fff;text-decoration:none;border-radius:8px;font-weight:600}</style></head><body><main class="box">';
        echo '<h1>' . self::html($title) . '</h1><p>' . self::html($message) . '</p>';
        echo '<a class="btn" href="' . self::html(SOI_ADMIN_URL . '/login.php') . '">Back to Admin Login</a>';
        echo '<a class="btn" href="' . self::html(SOI_ADMIN_URL . '/connect.php?start=1') . '" style="margin-left:8px;background:#444">Re-link Accounts</a>';
        echo '</main></body></html>';
        exit;
    }

    private static function renderSamlUnavailable(string $title, string $message, string $fallbackUrl): void {
        self::logSaml('Rendering SAML unavailable page', ['fallback' => $fallbackUrl]);
        http_response_code(503);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>' . self::html($title) . '</title>';
        echo '<style>body{font-family:Inter,Arial,sans-serif;background:#f8fafc;color:#0f172a;margin:0;display:grid;place-items:center;min-height:100vh;padding:24px}.box{max-width:640px;background:#fff;border:1px solid #cbd5e1;border-radius:12px;padding:28px;box-shadow:0 18px 60px rgba(15,23,42,.08)}h1{margin:0 0 12px;font-size:24px}p{line-height:1.6;margin:0 0 16px}a.btn{display:inline-block;margin-top:8px;padding:10px 16px;background:#2563eb;color:#fff;text-decoration:none;border-radius:8px;font-weight:600}</style></head><body><main class="box">';
        echo '<h1>' . self::html($title) . '</h1><p>' . self::html($message) . '</p>';
        echo '<a class="btn" href="' . self::html($fallbackUrl) . '">Continue with OAuth Sign-In</a>';
        echo '<a class="btn" href="' . self::html(SOI_ADMIN_URL . '/soi-central.php') . '" style="margin-left:8px;background:#475569">Open SOI Central Settings</a>';
        echo '</main></body></html>';
        exit;
    }

    private static function logSaml(string $message, array $context = []): void {
        $suffix = $context ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
        error_log('[SOI Central SAML] ' . $message . $suffix);
    }

    private static function ensureTables(): void {
        self::ensureAccessCacheTable();
        self::ensureWebhookEventsTable();
        self::ensureReplayTable();
    }

    private static function ensureAccessCacheTable(): void {
        Database::exec("CREATE TABLE IF NOT EXISTS `" . Database::prefix('central_access_cache') . "` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `cache_key` VARCHAR(191) NOT NULL,
            `email` VARCHAR(191) NULL,
            `permission` VARCHAR(191) NULL,
            `allowed` TINYINT(1) NOT NULL DEFAULT 0,
            `status` VARCHAR(100) NULL,
            `payload_json` LONGTEXT NULL,
            `access_version` VARCHAR(191) NULL,
            `stale_at` DATETIME NULL,
            `expires_at` DATETIME NULL,
            `updated_at` DATETIME NOT NULL,
            UNIQUE KEY `cache_key` (`cache_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private static function ensureWebhookEventsTable(): void {
        Database::exec("CREATE TABLE IF NOT EXISTS `" . Database::prefix('central_webhook_events') . "` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `event_id` VARCHAR(191) NOT NULL,
            `event_name` VARCHAR(191) NULL,
            `access_version` VARCHAR(191) NULL,
            `payload_json` LONGTEXT NULL,
            `received_at` DATETIME NOT NULL,
            UNIQUE KEY `event_id` (`event_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private static function ensureReplayTable(): void {
        Database::exec("CREATE TABLE IF NOT EXISTS `" . Database::prefix('central_saml_replay') . "` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `saml_id` VARCHAR(191) NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `created_at` DATETIME NOT NULL,
            UNIQUE KEY `saml_id` (`saml_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private static function tableReady(string $table): bool {
        try {
            return Database::tableExists($table);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function ensureUserColumns(): void {
        self::ensureColumn('users', 'soi_central_user_id', 'VARCHAR(191) NULL');
        self::ensureColumn('users', 'soi_central_access_version', 'VARCHAR(191) NULL');
        self::ensureColumn('users', 'soi_central_last_sync', 'DATETIME NULL');
    }

    private static function ensureColumn(string $table, string $column, string $definition): void {
        $prefixed = Database::prefix($table);
        $row = Database::selectOne(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1",
            [$prefixed, $column]
        );
        if (!$row) Database::exec("ALTER TABLE `$prefixed` ADD COLUMN `$column` $definition");
    }

    private static function readAccessCache(string $cacheKey): ?array {
        $sessionKey = 'soi_access_cache_' . $cacheKey;
        if (!empty($_SESSION[$sessionKey]) && ($_SESSION[$sessionKey]['expires'] ?? 0) > time()) {
            return $_SESSION[$sessionKey]['access'];
        }
        $row = Database::selectOne("SELECT * FROM `" . Database::prefix('central_access_cache') . "` WHERE cache_key = ? AND expires_at > NOW() AND (stale_at IS NULL OR stale_at > NOW())", [$cacheKey]);
        if (!$row) return null;
        $access = json_decode((string) $row['payload_json'], true);
        return is_array($access) ? $access : null;
    }

    private static function writeAccessCache(string $cacheKey, string $email, string $permission, array $access): void {
        $ttl = max(30, (int) self::option('cache_ttl', '300'));
        $staleAt = !empty($access['stale_after']) ? date('Y-m-d H:i:s', strtotime($access['stale_after'])) : date('Y-m-d H:i:s', time() + $ttl);
        $expiresAt = date('Y-m-d H:i:s', time() + $ttl);
        $_SESSION['soi_access_cache_' . $cacheKey] = ['expires' => time() + $ttl, 'access' => $access];

        $row = Database::selectOne("SELECT id FROM `" . Database::prefix('central_access_cache') . "` WHERE cache_key = ?", [$cacheKey]);
        $data = [
            'cache_key' => $cacheKey,
            'email' => $email,
            'permission' => $permission,
            'allowed' => !empty($access['allowed']) ? 1 : 0,
            'status' => (string) ($access['status'] ?? ''),
            'payload_json' => json_encode($access, JSON_UNESCAPED_SLASHES),
            'access_version' => (string) ($access['access_version'] ?? ''),
            'stale_at' => $staleAt,
            'expires_at' => $expiresAt,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($row) Database::update('central_access_cache', $data, 'cache_key = ?', [$cacheKey]);
        else Database::insert('central_access_cache', $data);
    }

    private static function cacheKey(string $email, string $permission, mixed $soiUserId): string {
        return hash('sha256', self::appId() . '|' . strtolower($email) . '|' . (string) $soiUserId . '|' . $permission);
    }

    private static function directoryUnavailableAccess(): array {
        return [
            'allowed' => true,
            'status' => 'directory_not_configured',
            'message' => 'SOI Directory API credentials are not configured; SAML authentication is being used as the access boundary.',
        ];
    }

    private static function hasApiCredentials(): bool {
        return self::appId() !== '' && self::clientId() !== '' && self::clientSecret() !== '';
    }

    private static function hasCentralSession(): bool {
        $user = Auth::user();
        if (!$user) return false;
        return !empty($user['soi_central_authenticated'])
            || !empty($user['soi_central_user_id'])
            || !empty($user['soi_central_profile']);
    }

    private static function permissionForRole(string $role): string {
        return match ($role) {
            'admin' => (string) self::option('admin_permission', 'cms.admin'),
            'editor', 'manager' => (string) self::option('editor_permission', 'cms.content.publish'),
            'author' => (string) self::option('author_permission', 'cms.content.edit'),
            default => (string) self::option('subscriber_permission', ''),
        };
    }

    private static function roleFromAccess(array $access, array $profile): string {
        $roles = array_merge(self::pluckSlugs($access['roles'] ?? []), array_map('strtolower', $profile['app_roles'] ?? []));
        $permissions = array_merge(self::pluckSlugs($access['permissions'] ?? []), array_map('strtolower', $profile['app_permissions'] ?? []));
        if (array_intersect($roles, ['admin', 'administrator']) || in_array('cms.admin', $permissions, true)) return 'admin';
        if (array_intersect($roles, ['editor', 'manager']) || in_array('cms.content.publish', $permissions, true)) return 'editor';
        if (array_intersect($roles, ['author', 'staff', 'reviewer']) || in_array('cms.content.edit', $permissions, true)) return 'author';
        return 'subscriber';
    }

    public static function pluckSlugs(array $items): array {
        $slugs = [];
        foreach ($items as $item) {
            if (is_array($item)) $slugs[] = strtolower((string) ($item['slug'] ?? $item['name'] ?? ''));
            else $slugs[] = strtolower((string) $item);
        }
        return array_values(array_filter(array_unique($slugs)));
    }
    private static function option(string $key, mixed $default = ''): mixed {
        return self::dbOption('soi_central_' . $key, $default);
    }

    private static function dbOption(string $key, mixed $default = ''): mixed {
        try {
            return Database::getOption($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    private static function setDefaultOption(string $key, string $value): void {
        if (self::dbOption($key, null) === null) Database::setOption($key, $value);
    }

    private static function env(string $key): string {
        $value = getenv($key);
        return $value === false ? '' : (string) $value;
    }

    private static function withAppParam(string $url): string {
        $appId = self::appId();
        if ($url === '' || $appId === '') return $url;
        if (preg_match('/(?:^|[?&])app=[^&]+/', $url)) return $url;
        $separator = str_contains($url, '?')
            ? (str_ends_with($url, '?') || str_ends_with($url, '&') ? '' : '&')
            : '?';
        return $url . $separator . 'app=' . rawurlencode($appId);
    }

    private static function embedKey(): string {
        return (string) self::option('public_embed_key', self::dbOption('my_account_public_key', ''));
    }

    private static function httpGet(string $url): string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Could not fetch metadata: ' . ($error ?: 'HTTP ' . $status));
        }
        return (string) $raw;
    }

    private static function samlXPath(DOMDocument $dom): DOMXPath {
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('samlp', 'urn:oasis:names:tc:SAML:2.0:protocol');
        $xp->registerNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');
        $xp->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        return $xp;
    }

    private static function signedAssertion(DOMXPath $xp): ?DOMElement {
        $nodes = $xp->query('/samlp:Response/saml:Assertion[ds:Signature]');
        if ($nodes && $nodes->length === 1 && $nodes->item(0) instanceof DOMElement) return $nodes->item(0);
        if ($nodes && $nodes->length > 1) throw new RuntimeException('Multiple signed assertions were found.');
        return null;
    }

    private static function hasDirectSignature(DOMElement $element, DOMXPath $xp): bool {
        return (bool) self::directSignature($element, $xp);
    }

    private static function directSignature(DOMElement $element, DOMXPath $xp): ?DOMElement {
        $node = $xp->query('./ds:Signature', $element)->item(0);
        return $node instanceof DOMElement ? $node : null;
    }

    private static function canonicalize(DOMNode $node, string $algorithm, array $inclusivePrefixes = []): string {
        $exclusive = $algorithm === '' || str_contains($algorithm, 'xml-exc-c14n');
        $withComments = str_contains($algorithm, 'WithComments');
        $canonical = $node->C14N($exclusive, $withComments, null, $exclusive ? $inclusivePrefixes : null);
        if ($canonical === false) throw new RuntimeException('XML canonicalization failed.');
        return $canonical;
    }

    private static function soiCentralCompatibleDigest(DOMElement $signedElement, string $digestAlgo): string {
        $clone = $signedElement->cloneNode(true);
        if (!$clone instanceof DOMElement) throw new RuntimeException('Could not clone SAML assertion for digest validation.');
        $signatures = [];
        foreach ($clone->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#', 'Signature') as $signature) {
            $signatures[] = $signature;
        }
        foreach ($signatures as $signature) {
            if ($signature instanceof DOMNode && $signature->parentNode) {
                $signature->parentNode->removeChild($signature);
            }
        }
        $canonical = $clone->C14N(true, false);
        if ($canonical === false) throw new RuntimeException('SOI Central compatible XML canonicalization failed.');
        return base64_encode(hash($digestAlgo, $canonical, true));
    }

    private static function inclusiveNamespaces(?DOMNode $node): array {
        if (!$node instanceof DOMNode) return [];
        $xp = new DOMXPath($node instanceof DOMDocument ? $node : $node->ownerDocument);
        $query = './/*[local-name()="InclusiveNamespaces" and namespace-uri()="http://www.w3.org/2001/10/xml-exc-c14n#"]/@PrefixList';
        $prefixList = $xp->query($query, $node)->item(0);
        if (!$prefixList) return [];
        $prefixes = preg_split('/\s+/', trim($prefixList->nodeValue)) ?: [];
        return array_values(array_filter($prefixes, static fn($prefix) => $prefix !== ''));
    }

    private static function opensslAlgo(string $algorithm): int|string {
        return match (true) {
            str_contains($algorithm, 'sha512') => OPENSSL_ALGO_SHA512,
            str_contains($algorithm, 'sha384') => 'sha384',
            str_contains($algorithm, 'sha256') => OPENSSL_ALGO_SHA256,
            str_contains($algorithm, 'sha1') => OPENSSL_ALGO_SHA1,
            default => throw new RuntimeException('Unsupported SAML signature algorithm.'),
        };
    }

    private static function digestAlgo(string $algorithm): string {
        return match (true) {
            str_contains($algorithm, 'sha512') => 'sha512',
            str_contains($algorithm, 'sha384') => 'sha384',
            str_contains($algorithm, 'sha256') => 'sha256',
            str_contains($algorithm, 'sha1') => 'sha1',
            default => throw new RuntimeException('Unsupported SAML digest algorithm.'),
        };
    }

    private static function decodeSamlResponsePayload(string $payload): string {
        $payload = trim($payload);
        if ($payload === '') {
            return '';
        }

        if (str_starts_with($payload, '<')) {
            self::logSaml('SAML response provided as raw XML');
            return $payload;
        }

        $decoded = base64_decode($payload, true);
        if ($decoded === false || $decoded === '') {
            $decoded = base64_decode($payload, false);
        }

        if ($decoded === false || $decoded === '') {
            self::logSaml('SAML response base64 decode failed');
            return '';
        }

        if (str_starts_with(trim($decoded), '<')) {
            return $decoded;
        }

        $inflated = @gzinflate($decoded);
        if (is_string($inflated) && str_starts_with(trim($inflated), '<')) {
            self::logSaml('SAML response decoded via gzinflate');
            return $inflated;
        }

        $gunzipped = @gzdecode($decoded);
        if (is_string($gunzipped) && str_starts_with(trim($gunzipped), '<')) {
            self::logSaml('SAML response decoded via gzdecode');
            return $gunzipped;
        }

        return $decoded;
    }

    private static function normalizeCert(string $cert): string {
        $cert = trim(str_replace(["\r", "\n", '-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----'], '', $cert));
        if ($cert === '') return '';
        $cert = chunk_split(preg_replace('/\s+/', '', $cert), 64, "\n");
        return "-----BEGIN CERTIFICATE-----\n" . trim($cert) . "\n-----END CERTIFICATE-----\n";
    }

    /**
     * @return list<string> PEM-encoded certificates
     */
    private static function parseMetadataCertificates(DOMXPath $xp): array {
        $certs = [];
        $queries = [
            '//md:IDPSSODescriptor/md:KeyDescriptor[@use="signing"]//ds:X509Certificate',
            '//md:IDPSSODescriptor/md:KeyDescriptor[not(@use)]//ds:X509Certificate',
            '//md:IDPSSODescriptor//ds:X509Certificate',
        ];

        foreach ($queries as $query) {
            $nodes = $xp->query($query);
            if (!$nodes) {
                continue;
            }
            foreach ($nodes as $node) {
                $cert = self::normalizeCert($node->nodeValue);
                if ($cert !== '' && !in_array($cert, $certs, true)) {
                    $certs[] = $cert;
                }
            }
            if ($certs !== []) {
                break;
            }
        }

        return $certs;
    }

    private static function metadataRefreshTtl(): int {
        $configured = (int) self::dbOption('soi_central_saml_metadata_ttl', '0');
        return $configured > 0 ? $configured : self::SAML_METADATA_TTL_SECONDS;
    }

    private static function certFingerprint(string $cert): string {
        $der = @openssl_x509_read($cert);
        if ($der === false) {
            return 'invalid';
        }

        $parsed = openssl_x509_parse($der);
        if (!is_array($parsed) || empty($parsed['hash'])) {
            return 'unknown';
        }

        return (string) $parsed['hash'];
    }

    /**
     * @param list<string> $certs
     * @return list<string>
     */
    private static function certFingerprints(array $certs): array {
        $fingerprints = [];
        foreach ($certs as $cert) {
            $fingerprints[] = self::certFingerprint($cert);
        }

        return $fingerprints;
    }

    /**
     * Merge incoming certificates with any already stored for rotation overlap.
     *
     * @param list<string> $incoming
     * @return list<string>
     */
    private static function mergeIdpCertificates(array $incoming, bool $replaceOnly = false): array {
        $merged = [];

        foreach ($incoming as $cert) {
            $cert = self::normalizeCert((string) $cert);
            if ($cert !== '' && !in_array($cert, $merged, true)) {
                $merged[] = $cert;
            }
        }

        if (!$replaceOnly) {
            foreach (self::getStoredIdpCertificates() as $cert) {
                if (!in_array($cert, $merged, true)) {
                    $merged[] = $cert;
                }
            }
        }

        return $merged;
    }

    /**
     * @param list<string> $certs
     */
    private static function persistIdpCertificates(array $certs, bool $touchFetchedAt): void {
        $certs = array_values(array_filter(array_map(
            static fn ($cert) => self::normalizeCert((string) $cert),
            $certs
        )));

        if ($certs === []) {
            return;
        }

        Database::setOption('soi_central_saml_x509_cert', $certs[0]);
        Database::setOption('soi_central_saml_x509_certs', json_encode($certs, JSON_UNESCAPED_SLASHES));

        if ($touchFetchedAt) {
            Database::setOption('soi_central_saml_metadata_fetched_at', date('Y-m-d H:i:s'));
        }
    }

    /**
     * @return list<string>
     */
    private static function getStoredIdpCertificates(): array {
        $certs = [];
        $primary = self::normalizeCert((string) self::dbOption('soi_central_saml_x509_cert', ''));
        if ($primary !== '') {
            $certs[] = $primary;
        }

        $rawList = (string) self::dbOption('soi_central_saml_x509_certs', '');
        if ($rawList !== '') {
            $decoded = json_decode($rawList, true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    $cert = self::normalizeCert((string) $item);
                    if ($cert !== '' && !in_array($cert, $certs, true)) {
                        $certs[] = $cert;
                    }
                }
            }
        }

        return $certs;
    }

    /**
     * @return list<string> PEM-encoded certificates, highest priority first
     */
    private static function resolveIdpVerificationCertificates(DOMElement $signature, DOMXPath $xp): array {
        $keyInfoCerts = self::extractSignatureCertificates($signature, $xp);
        $trustedCerts = [];

        foreach (self::getStoredIdpCertificates() as $cert) {
            if (!in_array($cert, $trustedCerts, true)) {
                $trustedCerts[] = $cert;
            }
        }

        try {
            $metadata = self::fetchMetadata();
            self::persistIdpMetadata($metadata);
            self::recordMetadataFetchStatus('ok', null, 'signature_verify_inline');
            foreach (($metadata['x509_certs'] ?? []) as $cert) {
                $cert = self::normalizeCert((string) $cert);
                if ($cert !== '' && !in_array($cert, $trustedCerts, true)) {
                    $trustedCerts[] = $cert;
                }
            }
        } catch (\Throwable $e) {
            self::recordMetadataFetchStatus('error', $e->getMessage(), 'signature_verify_inline');
            self::logSaml('Could not fetch fresh metadata during signature verify', ['error' => $e->getMessage()]);
        }

        $certs = self::prioritizeTrustedCertificates($trustedCerts, $keyInfoCerts);

        self::logSaml('Resolved verification certificates', [
            'count'             => count($certs),
            'trusted_count'     => count($trustedCerts),
            'keyinfo_count'     => count($keyInfoCerts),
            'keyinfo_trusted'   => count($certs) > 0 && $keyInfoCerts !== [] && in_array($certs[0], $keyInfoCerts, true) ? 'yes' : 'no',
            'fingerprints'      => self::certFingerprints($certs),
        ]);

        if ($certs === []) {
            $metaStatus = self::getMetadataFetchStatus();
            self::logSaml('No trusted verification certificates resolved', [
                'stored_count'  => count(self::getStoredIdpCertificates()),
                'keyinfo_count' => count($keyInfoCerts),
                'metadata_url'  => self::metadataUrl(),
                'metadata_error'=> $metaStatus['error'] ?? '',
                'app_id'        => self::appId(),
            ]);
        }

        return $certs;
    }

    /**
     * Use embedded KeyInfo certificates only as ordering hints when they match
     * trusted stored or freshly fetched IdP metadata certificates.
     *
     * @param list<string> $trustedCerts
     * @param list<string> $keyInfoCerts
     * @return list<string>
     */
    private static function prioritizeTrustedCertificates(array $trustedCerts, array $keyInfoCerts): array {
        $ordered = [];

        foreach ($keyInfoCerts as $keyInfoCert) {
            if (in_array($keyInfoCert, $trustedCerts, true) && !in_array($keyInfoCert, $ordered, true)) {
                $ordered[] = $keyInfoCert;
            }
        }

        foreach ($trustedCerts as $trustedCert) {
            if (!in_array($trustedCert, $ordered, true)) {
                $ordered[] = $trustedCert;
            }
        }

        if ($keyInfoCerts !== [] && $ordered === []) {
            if ($trustedCerts === []) {
                self::logSaml('Using SAML KeyInfo certificate(s) as fallback — no trusted stored metadata certificates', [
                    'keyinfo_count' => count($keyInfoCerts),
                    'fingerprints'  => self::certFingerprints($keyInfoCerts),
                ]);
                return $keyInfoCerts;
            }

            self::logSaml('Ignoring untrusted SAML KeyInfo certificate(s)', [
                'keyinfo_count' => count($keyInfoCerts),
                'fingerprints'  => self::certFingerprints($keyInfoCerts),
            ]);
        }

        return $ordered;
    }

    /**
     * @return list<string>
     */
    private static function extractSignatureCertificates(DOMElement $signature, DOMXPath $xp): array {
        $certs = [];
        foreach ($xp->query('.//ds:X509Certificate', $signature) as $node) {
            $cert = self::normalizeCert($node->nodeValue);
            if ($cert !== '' && !in_array($cert, $certs, true)) {
                $certs[] = $cert;
            }
        }

        return $certs;
    }

    private static function renderSamlLoginError(\Throwable $e): void {
        $message = $e->getMessage();
        $lowerMessage = strtolower($message);
        $isSignatureFailure = str_contains($lowerMessage, 'signature')
            || str_contains($lowerMessage, 'certificate')
            || str_contains($lowerMessage, 'x.509')
            || str_contains($lowerMessage, 'app id');
        $isIdpStatus = !str_contains($lowerMessage, 'signature')
            && !str_contains($lowerMessage, 'digest')
            && !str_contains($lowerMessage, 'certificate');

        $diagnostics = $isSignatureFailure ? self::buildSignatureFailureDiagnostics() : null;
        $hint = 'Try OAuth sign-in from the admin login page. The CMS refreshes IdP metadata automatically during Accounts linking and SAML login.';
        if ($diagnostics !== null) {
            $hint = $diagnostics['hint'];
        }

        http_response_code(401);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>SOI Central login failed</title>';
        echo '<style>body{font-family:Inter,Arial,sans-serif;background:#fff7ed;color:#431407;margin:0;display:grid;place-items:center;min-height:100vh;padding:24px}.box{max-width:680px;background:#fff;border:1px solid #fed7aa;border-radius:12px;padding:28px;box-shadow:0 18px 60px rgba(154,52,18,.12)}h1{margin:0 0 12px;font-size:24px}p{line-height:1.6;margin:0 0 14px}.hint{color:#7c2d12;font-size:.92rem}.diag{margin-top:12px;padding:12px 14px;border-radius:8px;background:#fff7ed;border:1px solid #fdba74;font-size:.84rem;line-height:1.55;color:#7c2d12}.diag strong{display:block;margin-bottom:6px}.btn{display:inline-block;margin-top:8px;margin-right:8px;padding:10px 16px;background:#ea580c;color:#fff;text-decoration:none;border-radius:8px;font-weight:600}.btn-secondary{background:#475569}</style></head><body><main class="box">';
        echo '<h1>SOI Central login failed</h1>';
        echo '<p>' . self::html($message) . '</p>';
        echo '<p class="hint">' . self::html($hint) . '</p>';
        if ($diagnostics !== null) {
            echo '<div class="diag"><strong>Certificate diagnostics</strong>';
            echo 'Metadata URL: ' . self::html($diagnostics['metadata_url'] !== '' ? $diagnostics['metadata_url'] : '(not configured)') . '<br>';
            echo 'Stored certificates: ' . self::html((string) $diagnostics['stored_cert_count'])
                . '; KeyInfo in response: ' . self::html((string) $diagnostics['keyinfo_cert_count']) . '<br>';
            echo 'Metadata fetch: ' . self::html((string) ($diagnostics['metadata_status']['fetched_at'] ?? 'never'))
                . ' (' . self::html((string) ($diagnostics['metadata_status']['cert_count'] ?? 0)) . ' stored, status='
                . self::html((string) ($diagnostics['metadata_status']['status'] ?? 'unknown')) . ')<br>';
            if ($diagnostics['last_openssl_error'] !== '') {
                echo 'Last OpenSSL verify error: ' . self::html($diagnostics['last_openssl_error']) . '<br>';
            }
            if ($diagnostics['app_id_mismatch']) {
                echo 'App ID mismatch: ' . self::html($diagnostics['app_config']['detail']) . '<br>';
            }
            echo self::html($diagnostics['relink_guidance']);
            echo '</div>';
        }
        echo '<a class="btn" href="' . self::html(SOI_ADMIN_URL . '/login.php?saml=unavailable') . '">OAuth Sign-In</a>';
        echo '<a class="btn btn-secondary" href="' . self::html(SOI_ADMIN_URL . '/soi-central.php') . '">SOI Central Settings</a>';
        if ($isIdpStatus || $isSignatureFailure) {
            echo '<a class="btn btn-secondary" href="' . self::html(SOI_ADMIN_URL . '/soi-central.php') . '">Re-link Accounts</a>';
        }
        echo '</main></body></html>';
        exit;
    }

    private static function firstAttribute(array $attributes, array $names): string {
        foreach ($names as $name) {
            if (!array_key_exists($name, $attributes)) continue;
            $value = $attributes[$name];
            if (is_array($value)) return trim((string) ($value[0] ?? ''));
            return trim((string) $value);
        }
        return '';
    }

    private static function splitAttribute(string $value): array {
        if ($value === '') return [];
        $decoded = json_decode($value, true);
        if (is_array($decoded)) return array_map('strval', $decoded);
        return array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $value) ?: [])));
    }

    private static function safeUsername(string $username): string {
        $username = strtolower(preg_replace('/[^a-zA-Z0-9_.-]/', '-', $username));
        $username = trim(preg_replace('/-+/', '-', $username), '-_.');
        return $username !== '' ? substr($username, 0, 80) : 'soi-user';
    }

    private static function uniqueUsername(string $base, string $email): string {
        $table = Database::prefix('users');
        $candidate = $base;
        $i = 2;
        while (Database::selectOne("SELECT id FROM `$table` WHERE username = ? AND email <> ?", [$candidate, $email])) {
            $candidate = substr($base, 0, 70) . '-' . $i++;
        }
        return $candidate;
    }

    private static function isBypassedRoute(string $uri): bool {
        $uri = trim($uri, '/');
        return $uri === ''
            ? false
            : (str_starts_with($uri, 'soi-central/')
                || str_starts_with($uri, 'saml/')
                || str_starts_with($uri, 'admin')
                || str_starts_with($uri, 'install')
                || str_starts_with($uri, 'themes/')
                || str_starts_with($uri, 'assets/'));
    }

    private static function isCurrentScript(array $scripts): bool {
        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        return in_array($script, $scripts, true);
    }

    private static function audienceMatches(array $audiences): bool {
        $accepted = self::acceptedAudienceValues();
        foreach ($audiences as $audience) {
            if (in_array(self::normalizeUrl((string) $audience), $accepted, true)) {
                return true;
            }
        }
        return false;
    }

    private static function acceptedAudienceValues(): array {
        $base = self::cmsBaseUrl();

        return self::uniqueNormalizedUrls([
            self::spEntityId(),
            $base,
            $base . '/saml/metadata',
            self::spMetadataUrl(),
            $base . '/saml/acs',
            self::acsUrl(),
        ]);
    }

    private static function acceptedAcsUrls(): array {
        $base = self::cmsBaseUrl();

        return self::uniqueNormalizedUrls([
            self::acsUrl(),
            $base . '/saml/acs',
            $base . '/soi-central/saml/acs',
        ]);
    }

    private static function uniqueNormalizedUrls(array $urls): array {
        $normalized = [];
        foreach ($urls as $url) {
            $url = self::normalizeUrl((string) $url);
            if ($url !== '') $normalized[] = $url;
        }
        return array_values(array_unique($normalized));
    }


    private static function adminDashboardUrl(): string {
        return rtrim(SOI_ADMIN_URL, '/') . '/index.php';
    }

    private static function safeAcsReturnUrl(string $relayState, string $storedReturn): string {
        $candidate = trim($relayState) !== '' ? $relayState : $storedReturn;
        return self::safeReturnUrl($candidate, self::adminDashboardUrl());
    }

    private static function safeReturnUrl(string $url, string $fallback = ''): string {
        $fallback = $fallback !== '' ? $fallback : SOI_HOME_URL;
        if ($url === '') return $fallback;
        if (str_starts_with($url, '//')) return $fallback;
        if (str_starts_with($url, '/')) return rtrim(SOI_HOME_URL, '/') . '/' . ltrim($url, '/');
        $targetHost = parse_url($url, PHP_URL_HOST);
        $allowedHosts = array_filter([
            parse_url(SOI_HOME_URL, PHP_URL_HOST),
            parse_url(SOI_ADMIN_URL, PHP_URL_HOST),
        ]);
        foreach ($allowedHosts as $allowedHost) {
            if ($targetHost && strcasecmp($targetHost, (string) $allowedHost) === 0) {
                return $url;
            }
        }

        return $fallback;
    }

    private static function sameUrl(string $a, string $b): bool {
        return self::normalizeUrl($a) === self::normalizeUrl($b);
    }

    private static function sameUrlAny(string $url, array $accepted): bool {
        return in_array(self::normalizeUrl($url), $accepted, true);
    }

    private static function normalizeUrl(string $url): string {
        return rtrim(trim($url), '/');
    }

    private static function html(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function xml(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
