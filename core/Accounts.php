<?php
namespace SOI\Core;

/**
 * Accounts - Integration service for accounts.soi.co.in
 */
class Accounts {
    private const CONNECT_BASE_URL    = 'https://accounts.soi.co.in/install/connect';
    private const CONNECT_EXCHANGE_URL = 'https://accounts.soi.co.in/install/connect/exchange';
    private const AUTHORIZE_URL       = 'https://accounts.soi.co.in/oauth/authorize';
    private const TOKEN_URL           = 'https://accounts.soi.co.in/oauth/token';
    private const USERINFO_URL        = 'https://accounts.soi.co.in/oauth/userinfo';
    private const INSTALLER_CLIENT_ID = 'global_cms_installer';
    private const STATE_OPTION          = 'accounts_connect_state';
    private const STATE_SESSION_KEY     = 'soi_accounts_connect_state';
    private const CONNECT_STATE_TTL     = 7200; // 2 hours
    private const OAUTH_STATE_OPTION    = 'accounts_oauth_state';
    private const ENCRYPT_PREFIX      = 'soienc:';
    private const ENCRYPTED_OPTION_KEYS = [
        'accounts_client_secret',
        'accounts_api_key',
    ];

    public static function isLinked(): bool {
        $clientId = trim((string) Database::getOption('accounts_client_id', ''));
        $clientSecret = trim((string) Database::getOption('accounts_client_secret', ''));

        return $clientId !== '' && $clientSecret !== '';
    }

    public static function getStoredCredentials(): ?array {
        if (!self::isLinked()) {
            return null;
        }

        self::migrateStoredSecretsIfNeeded();

        return [
            'client_id'     => (string) Database::getOption('accounts_client_id', ''),
            'client_secret' => self::decryptSecret((string) Database::getOption('accounts_client_secret', '')),
            'api_key'       => self::decryptSecret((string) Database::getOption('accounts_api_key', '')),
            'app_id'        => (string) Database::getOption('accounts_app_id', ''),
        ];
    }

    public static function generateState(): string {
        return bin2hex(random_bytes(32));
    }

    public static function saveConnectState(string $state, ?string $redirectUri = null, bool $relink = false): void {
        $payload = json_encode([
            'state'        => $state,
            'redirect_uri' => $redirectUri ?? '',
            'created_at'   => time(),
            'relink'       => $relink,
        ], JSON_THROW_ON_ERROR);

        Database::setOption(self::STATE_OPTION, $payload);

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::STATE_SESSION_KEY] = $state;
        }
    }

    public static function buildConnectUrl(?string $redirectUri = null, bool $relink = false): string {
        $state = self::generateState();

        $siteUrl = rtrim((string) Database::getOption('site_url', SOI_HOME_URL), '/');
        $redirectUri = $redirectUri ?? (SOI_ADMIN_URL . '/accounts-callback.php');

        self::saveConnectState($state, $redirectUri, $relink);

        $installationData = base64_encode(json_encode([
            'site_name'   => Database::getOption('site_name', ''),
            'site_url'    => $siteUrl,
            'admin_email' => Database::getOption('admin_email', ''),
            'cms_version' => defined('SOI_VERSION') ? SOI_VERSION : '1.0.3',
        ], JSON_THROW_ON_ERROR));

        $params = [
            'client_id'         => self::INSTALLER_CLIENT_ID,
            'redirect_uri'      => $redirectUri,
            'state'             => $state,
            'installation_data' => $installationData,
        ];

        return self::CONNECT_BASE_URL . '?' . http_build_query($params);
    }

    /**
     * Start an admin re-link flow — overwrites stored credentials and SAML settings.
     */
    public static function buildRelinkUrl(?string $redirectUri = null): string {
        return self::buildConnectUrl($redirectUri ?? (SOI_ADMIN_URL . '/accounts-callback.php'), true);
    }

    /**
     * Snapshot of stored Accounts / SOI Central credential fields for diagnostics.
     *
     * @return array{
     *   accounts_app_id: string,
     *   soi_central_app_id: string,
     *   client_id: string,
     *   linked: bool,
     *   app_id_mismatch: bool,
     *   client_id_used_as_app_id: bool,
     *   mismatch_detail: string
     * }
     */
    public static function getCredentialSnapshot(): array {
        $accountsAppId = trim((string) Database::getOption('accounts_app_id', ''));
        $centralAppId  = trim((string) Database::getOption('soi_central_app_id', ''));
        $clientId      = trim((string) Database::getOption('accounts_client_id', ''));

        $mismatch = $accountsAppId !== ''
            && $centralAppId !== ''
            && $accountsAppId !== $centralAppId;

        $clientIdAsApp = $centralAppId !== ''
            && $clientId !== ''
            && $centralAppId === $clientId
            && $accountsAppId !== ''
            && $accountsAppId !== $clientId;

        $detail = 'none';
        if ($mismatch) {
            $detail = 'accounts_app_id=' . $accountsAppId . ' vs soi_central_app_id=' . $centralAppId;
        } elseif ($clientIdAsApp) {
            $detail = 'soi_central_app_id matches OAuth client_id instead of accounts_app_id';
        }

        return [
            'accounts_app_id'           => $accountsAppId,
            'soi_central_app_id'        => $centralAppId,
            'client_id'                 => $clientId,
            'linked'                    => self::isLinked(),
            'app_id_mismatch'           => $mismatch || $clientIdAsApp,
            'client_id_used_as_app_id'  => $clientIdAsApp,
            'mismatch_detail'           => $detail,
        ];
    }

    /**
     * Compare Accounts link fields with mirrored SOI Central credential options.
     *
     * @return array{
     *   in_sync: bool,
     *   app_id_match: bool,
     *   client_id_match: bool,
     *   secret_match: bool,
     *   mismatches: list<string>
     * }
     */
    public static function verifyCredentialsInSync(): array {
        $accountsAppId    = trim((string) Database::getOption('accounts_app_id', ''));
        $centralAppId     = trim((string) Database::getOption('soi_central_app_id', ''));
        $accountsClientId = trim((string) Database::getOption('accounts_client_id', ''));
        $centralClientId  = trim((string) Database::getOption('soi_central_client_id', ''));
        $accountsSecret   = (string) Database::getOption('accounts_client_secret', '');
        $centralSecret    = (string) Database::getOption('soi_central_client_secret', '');

        $appIdMatch = $accountsAppId === $centralAppId
            || ($accountsAppId === '' && $centralAppId === '');
        $clientIdMatch = $accountsClientId === $centralClientId;
        $secretMatch = self::secretHashPrefix($accountsSecret) === self::secretHashPrefix($centralSecret)
            && self::secretHashPrefix($accountsSecret) !== '';

        $mismatches = [];
        if (!$appIdMatch) {
            $mismatches[] = 'app_id: accounts=' . ($accountsAppId ?: 'empty') . ' central=' . ($centralAppId ?: 'empty');
        }
        if (!$clientIdMatch) {
            $mismatches[] = 'client_id: accounts=' . ($accountsClientId ?: 'empty') . ' central=' . ($centralClientId ?: 'empty');
        }
        if (!$secretMatch) {
            $mismatches[] = 'client_secret hash prefix mismatch';
        }

        return [
            'in_sync'          => $mismatches === [],
            'app_id_match'     => $appIdMatch,
            'client_id_match'  => $clientIdMatch,
            'secret_match'     => $secretMatch,
            'mismatches'       => $mismatches,
        ];
    }

    /**
     * Re-sync SOI Central SAML settings from stored Accounts credentials (fixes drift).
     */
    public static function syncSamlFromStoredCredentials(
        bool $forceMetadataRefresh = false,
        string $metadataReason = 'accounts_sync'
    ): void {
        $creds = self::getStoredCredentials();
        if (!$creds) {
            return;
        }

        $syncCheck = self::verifyCredentialsInSync();
        if (!$syncCheck['in_sync']) {
            error_log('[Accounts] Credential drift detected — syncing SAML from stored credentials: '
                . implode('; ', $syncCheck['mismatches']));
        }

        $snapshot = self::getCredentialSnapshot();
        if ($snapshot['app_id_mismatch']) {
            error_log('[Accounts] App ID mismatch detected — syncing SAML from stored credentials: ' . $snapshot['mismatch_detail']);
        }

        $previousAppId = trim((string) Database::getOption('soi_central_app_id', ''));
        $newAppId = ($creds['app_id'] ?? '') !== '' ? (string) $creds['app_id'] : (string) $creds['client_id'];
        $appIdChanged = $previousAppId !== '' && $newAppId !== '' && $previousAppId !== $newAppId;

        if ($appIdChanged) {
            self::clearStaleSamlCertificates('app_id_change previous=' . $previousAppId . ' new=' . $newAppId);
            $forceMetadataRefresh = true;
            if ($metadataReason === 'accounts_sync') {
                $metadataReason = 'accounts_relink';
            }
        }

        self::configureSamlIntegration(
            $creds['client_id'],
            $creds['client_secret'],
            $creds['app_id'] ?? '',
            $creds['api_key'] ?? '',
            $forceMetadataRefresh,
            false,
            $metadataReason
        );

        if (class_exists(SoiCentralAuth::class)) {
            SoiCentralAuth::repairCanonicalSamlEndpoints();
        }
    }

    /**
     * Validate and store credentials returned from accounts.soi.co.in.
     *
     * @param  array<string, mixed> $data  Expected keys: state, client_id, client_secret, api_key, app_id
     * @return array{success: bool, error?: string}
     */
    public static function handleCallback(array $data): array {
        if (!empty($data['error'])) {
            $description = trim((string) ($data['error_description'] ?? $data['error']));
            return [
                'success' => false,
                'error'   => $description !== '' ? $description : 'Accounts connection was cancelled or denied.',
            ];
        }

        $savedConnect = self::getSavedConnectState();
        $isRelink     = !empty($savedConnect['relink']);

        if (self::isLinked() && !$isRelink) {
            return [
                'success' => false,
                'error'   => 'This CMS is already linked to SOI Accounts. Use Admin → SOI Central → Re-link Accounts to update credentials.',
            ];
        }

        $receivedState = self::normalizeState((string) ($data['state'] ?? ''));
        $credentials   = self::normalizeLinkCredentials($data);
        $clientId      = $credentials['client_id'];
        $clientSecret  = $credentials['client_secret'];
        $apiKey        = $credentials['api_key'];
        $appId         = $credentials['app_id'];

        if ($receivedState === '') {
            return [
                'success' => false,
                'error'   => 'Missing state token from Accounts. Please start the connection again from the connect page.',
            ];
        }

        $connectionToken = trim((string) ($data['connection_token'] ?? ''));
        $stateValid      = self::validateConnectState($receivedState);

        if (!$stateValid && $connectionToken !== '') {
            $exchange = self::exchangeConnectionToken($connectionToken, $receivedState);
            if ($exchange['success']) {
                $merged = self::normalizeLinkCredentials(array_merge($data, $exchange));
                $clientId     = $merged['client_id'];
                $clientSecret = $merged['client_secret'];
                $apiKey       = $merged['api_key'];
                $appId        = $merged['app_id'];
                $stateValid   = true;
            }
        }

        if (!$stateValid) {
            $saved = self::getSavedConnectState();
            if ($saved['created_at'] > 0 && (time() - $saved['created_at']) > self::CONNECT_STATE_TTL) {
                return [
                    'success' => false,
                    'error'   => 'Connect session expired. Please try linking again.',
                ];
            }

            return [
                'success' => false,
                'error'   => 'Invalid or expired state token. Security validation failed. Please try reconnecting.',
            ];
        }

        if (!self::callbackMatchesSavedRedirectUri()) {
            return [
                'success' => false,
                'error'   => 'Callback URL mismatch. Start linking again from the connect page.',
            ];
        }

        if ($clientId === '' || $clientSecret === '') {
            return [
                'success' => false,
                'error'   => 'Missing necessary credentials from the central accounts system. Connection failed.',
            ];
        }

        if ($appId === '') {
            error_log('[Accounts] WARNING: Connect callback missing app_id/app_slug — SAML metadata will use OAuth client_id and signature verification may fail.');
        }

        self::applyLinkedCredentials($clientId, $clientSecret, $apiKey, $appId, $isRelink);

        self::clearConnectState();

        $result = ['success' => true, 'relinked' => $isRelink];
        if ($appId === '') {
            $result['warning'] = 'Accounts did not return app_id. Register the application slug in Accounts and re-link, or SAML login may fail.';
        }

        return $result;
    }

    /**
     * Normalize credential fields from Accounts install/connect callback or token exchange.
     * Accepts field aliases Accounts may send during API evolution.
     *
     * @param  array<string, mixed> $data
     * @return array{client_id: string, client_secret: string, api_key: string, app_id: string}
     */
    public static function normalizeLinkCredentials(array $data): array {
        $appId = trim((string) (
            $data['app_id']
            ?? $data['app_slug']
            ?? $data['slug']
            ?? $data['application_id']
            ?? ''
        ));

        return [
            'client_id'     => trim((string) ($data['client_id'] ?? '')),
            'client_secret' => trim((string) ($data['client_secret'] ?? '')),
            'api_key'       => trim((string) (
                $data['api_key']
                ?? $data['public_key']
                ?? $data['embed_key']
                ?? $data['public_embed_key']
                ?? ''
            )),
            'app_id'        => $appId,
        ];
    }

    /**
     * Integration alignment snapshot for Accounts ↔ CMS verification (health checks, support).
     *
     * @return array<string, mixed>
     */
    public static function getAlignmentStatus(): array {
        $sync = self::verifyCredentialsInSync();
        $snapshot = self::getCredentialSnapshot();
        $accountsAppId = trim((string) Database::getOption('accounts_app_id', ''));

        return [
            'linked'                    => self::isLinked(),
            'accounts_app_id'           => $accountsAppId,
            'canonical_app_id'          => class_exists(SoiCentralAuth::class) ? SoiCentralAuth::appId() : $accountsAppId,
            'credentials_in_sync'       => $sync['in_sync'],
            'credential_mismatches'     => $sync['mismatches'],
            'app_id_mismatch'           => $snapshot['app_id_mismatch'],
            'saml_metadata_url'         => $accountsAppId !== ''
                ? 'https://accounts.soi.co.in/saml/metadata?app=' . rawurlencode($accountsAppId)
                : '',
            'saml_sso_url'              => $accountsAppId !== ''
                ? 'https://accounts.soi.co.in/saml/sso?app=' . rawurlencode($accountsAppId)
                : '',
            'connect_callback_fields'   => ['state', 'client_id', 'client_secret', 'api_key', 'app_id', 'connection_token'],
            'connect_field_aliases'     => [
                'app_id'  => ['app_slug', 'slug', 'application_id'],
                'api_key' => ['public_key', 'embed_key', 'public_embed_key'],
            ],
            'directory_hmac_headers'    => ['X-SOI-Client-ID', 'X-SOI-Timestamp', 'X-SOI-Signature'],
            'directory_signature_base'  => 'METHOD + "\\n" + PATH + "\\n" + TIMESTAMP + "\\n" + RAW_JSON_BODY',
            'directory_signature_algo'  => 'sha256 HMAC with client_secret',
            'canonical_sp_entity_id'    => class_exists(SoiCentralAuth::class) ? SoiCentralAuth::spMetadataUrl() : '',
            'canonical_acs_url'           => class_exists(SoiCentralAuth::class) ? SoiCentralAuth::acsUrl() : '',
            'canonical_saml_login_url'    => class_exists(SoiCentralAuth::class) ? SoiCentralAuth::samlLoginUrl() : '',
            'legacy_saml_paths_supported' => class_exists(SoiCentralAuth::class) ? SoiCentralAuth::legacySamlPaths() : [],
            'admin_permission_slug'     => 'cms.admin',
        ];
    }

    /**
     * Persist Accounts credentials and configure SAML integration.
     */
    public static function applyLinkedCredentials(
        string $clientId,
        string $clientSecret,
        string $apiKey,
        string $appId,
        bool $isRelink = false
    ): void {
        $previousSnapshot = self::getCredentialSnapshot();

        if ($isRelink) {
            self::clearRelinkState();

            $secretHashPrefix = substr(hash('sha256', $clientSecret), 0, 8);
            error_log('[Accounts] Re-link credential snapshot: ' . json_encode([
                'previous'           => $previousSnapshot,
                'new_app_id'         => $appId,
                'new_client_id'      => $clientId,
                'secret_hash_prefix' => $secretHashPrefix,
            ], JSON_UNESCAPED_SLASHES));
        }

        self::storeCredentialOptions([
            'accounts_client_id'     => $clientId,
            'accounts_client_secret' => $clientSecret,
            'accounts_api_key'       => $apiKey,
            'accounts_app_id'        => $appId,
        ]);

        if ($isRelink) {
            Database::setOption('accounts_relinked_at', date('Y-m-d H:i:s'));
            error_log('[Accounts] Re-link applied: previous_app_id=' . ($previousSnapshot['accounts_app_id'] ?: 'none')
                . ' new_app_id=' . $appId);
        } else {
            Database::setOption('accounts_linked_at', date('Y-m-d H:i:s'));
        }

        self::configureSamlIntegration($clientId, $clientSecret, $appId, $apiKey, true, $isRelink);
    }

    /**
     * Bridge OAuth link credentials into SOI Central SAML + Directory API settings.
     */
    public static function configureSamlIntegration(
        string $clientId,
        string $clientSecret,
        string $appId = '',
        string $apiKey = '',
        bool $forceMetadataRefresh = true,
        bool $isRelink = false,
        string $metadataReason = 'accounts_link'
    ): void {
        if ($clientId === '' || $clientSecret === '') {
            return;
        }

        $baseUrl = rtrim((string) Database::getOption('site_url', SOI_HOME_URL), '/');
        $centralAppId = $appId !== '' ? $appId : $clientId;
        $encryptedSecret = self::encryptSecret($clientSecret);

        if ($isRelink) {
            $metadataReason = 'accounts_relink';
        }

        $options = [
            'soi_central_enabled'              => '1',
            'soi_central_login_mode'           => 'saml',
            'soi_central_base_url'             => 'https://accounts.soi.co.in',
            'soi_central_app_id'               => $centralAppId,
            'soi_central_client_id'            => $clientId,
            'accounts_client_id'               => $clientId,
            'accounts_client_secret'           => $encryptedSecret,
            'soi_central_client_secret'        => $encryptedSecret,
            'soi_central_saml_sp_entity_id'    => class_exists(SoiCentralAuth::class)
                ? SoiCentralAuth::spMetadataUrl()
                : $baseUrl . '/saml/metadata',
            'soi_central_saml_sso_url'         => 'https://accounts.soi.co.in/saml/sso?app=' . rawurlencode($centralAppId),
            'soi_central_saml_metadata_url'    => 'https://accounts.soi.co.in/saml/metadata?app=' . rawurlencode($centralAppId),
            'soi_central_enforce_admin'        => '1',
            'soi_central_allow_local_login'    => '0',
        ];

        if ($apiKey !== '') {
            $options['soi_central_public_embed_key'] = $apiKey;
            $options['accounts_api_key'] = self::encryptSecret($apiKey);
        }

        if ($appId !== '') {
            $options['accounts_app_id'] = $appId;
        }

        Database::setOptions($options);

        if (class_exists(SoiCentralAuth::class)) {
            $linkLabel = $isRelink ? 're-link' : 'link';
            $metadataRetryReason = $isRelink ? 'accounts_relink_retry' : 'accounts_link_retry';

            try {
                SoiCentralAuth::install();
                SoiCentralAuth::repairCanonicalSamlEndpoints();
                $metadata = SoiCentralAuth::ensureIdpMetadata($forceMetadataRefresh, $metadataReason);
                $certCount = count($metadata['x509_certs'] ?? []);
                error_log('[Accounts] SAML metadata auto-fetched on ' . $linkLabel . ': reason=' . $metadataReason . ' certs=' . $certCount);
                if ($certCount === 0) {
                    error_log('[Accounts] WARNING: Accounts ' . $linkLabel . ' completed but no IdP signing certificates were stored.');
                }

                if ($isRelink) {
                    try {
                        SoiCentralAuth::syncManifest();
                        error_log('[Accounts] Manifest synced after re-link.');
                    } catch (\Throwable $manifestError) {
                        error_log('[Accounts] Manifest sync failed after re-link: ' . $manifestError->getMessage());
                    }
                }
            } catch (\Throwable $e) {
                error_log('[Accounts] SAML metadata auto-fetch failed on ' . $linkLabel . ': ' . $e->getMessage());
                try {
                    SoiCentralAuth::ensureIdpMetadata(false, $metadataRetryReason);
                    error_log('[Accounts] SAML metadata stale-check retry after ' . $linkLabel . ' failure succeeded.');
                } catch (\Throwable $retryError) {
                    error_log('[Accounts] SAML metadata retry after ' . $linkLabel . ' failure also failed: ' . $retryError->getMessage());
                }
            }
        }
    }

    /**
     * @return array{success: bool, client_id?: string, client_secret?: string, api_key?: string, app_id?: string, error?: string}
     */
    private static function exchangeConnectionToken(string $token, string $state): array {
        $response = self::httpRequest('POST', self::CONNECT_EXCHANGE_URL, [
            'connection_token' => $token,
            'state'            => $state,
        ], ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json']);

        if (!$response['success']) {
            return ['success' => false, 'error' => $response['error'] ?? 'Connection token exchange failed.'];
        }

        $data = json_decode($response['body'] ?? '', true);
        if (!is_array($data) || empty($data['success'])) {
            $message = is_array($data) ? (string) ($data['error'] ?? 'Invalid exchange response.') : 'Invalid exchange response.';
            return ['success' => false, 'error' => $message];
        }

        return array_merge(
            ['success' => true],
            self::normalizeLinkCredentials(is_array($data) ? $data : [])
        );
    }

    public static function buildAuthorizeUrl(?string $redirectUri = null): string {
        $creds = self::getStoredCredentials();
        if (!$creds) {
            throw new \RuntimeException('Accounts is not linked.');
        }

        $state = self::generateState();
        Database::setOption(self::OAUTH_STATE_OPTION, $state);

        $redirectUri = $redirectUri ?? (SOI_ADMIN_URL . '/auth-callback.php');

        $params = [
            'client_id'     => $creds['client_id'],
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'state'         => $state,
            'scope'         => 'openid profile email',
        ];

        return self::AUTHORIZE_URL . '?' . http_build_query($params);
    }

    /**
     * @return array{success: bool, access_token?: string, error?: string}
     */
    public static function exchangeCode(string $code, ?string $redirectUri = null): array {
        $creds = self::getStoredCredentials();
        if (!$creds) {
            return ['success' => false, 'error' => 'Accounts is not linked.'];
        }

        $redirectUri = $redirectUri ?? (SOI_ADMIN_URL . '/auth-callback.php');

        $response = self::httpRequest('POST', self::TOKEN_URL, [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'client_id'     => $creds['client_id'],
            'client_secret' => $creds['client_secret'],
        ], ['Content-Type: application/x-www-form-urlencoded']);

        if (!$response['success']) {
            return ['success' => false, 'error' => $response['error'] ?? 'Token exchange failed.'];
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || empty($data['access_token'])) {
            $message = is_array($data) ? ($data['error_description'] ?? $data['error'] ?? 'Invalid token response.') : 'Invalid token response.';
            return ['success' => false, 'error' => (string) $message];
        }

        return ['success' => true, 'access_token' => (string) $data['access_token']];
    }

    /**
     * @return array{success: bool, user?: array<string, mixed>, error?: string}
     */
    public static function getUserInfo(string $accessToken): array {
        $response = self::httpRequest('GET', self::USERINFO_URL, null, [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ]);

        if (!$response['success']) {
            return ['success' => false, 'error' => $response['error'] ?? 'Failed to fetch user info.'];
        }

        $user = json_decode($response['body'], true);
        if (!is_array($user)) {
            return ['success' => false, 'error' => 'Invalid user info response.'];
        }

        return ['success' => true, 'user' => $user];
    }

    public static function validateOAuthState(string $receivedState): bool {
        $savedState    = (string) Database::getOption(self::OAUTH_STATE_OPTION, '');
        $receivedState = trim($receivedState);

        if ($receivedState === '' || $savedState === '') {
            return false;
        }

        $valid = hash_equals($savedState, $receivedState);
        if ($valid) {
            Database::delete('options', 'option_key = ?', [self::OAUTH_STATE_OPTION]);
        }

        return $valid;
    }

    public static function extractUserId(array $userInfo): string {
        foreach (['sub', 'id', 'user_id', 'accounts_user_id'] as $key) {
            if (!empty($userInfo[$key])) {
                return (string) $userInfo[$key];
            }
        }

        return '';
    }

    /**
     * @return array{success: bool, error?: string}
     */
    public static function handleAuthCallback(array $query): array {
        if (!empty($query['error'])) {
            $description = trim((string) ($query['error_description'] ?? $query['error']));
            return ['success' => false, 'error' => $description !== '' ? $description : 'Authorization was denied.'];
        }

        $code  = trim((string) ($query['code'] ?? ''));
        $state = trim((string) ($query['state'] ?? ''));

        if ($code === '') {
            return ['success' => false, 'error' => 'Missing authorization code from Accounts.'];
        }

        if (!self::validateOAuthState($state)) {
            return ['success' => false, 'error' => 'Invalid or expired login state. Please try again.'];
        }

        $tokenResult = self::exchangeCode($code);
        if (!$tokenResult['success']) {
            return ['success' => false, 'error' => $tokenResult['error'] ?? 'Token exchange failed.'];
        }

        $userResult = self::getUserInfo($tokenResult['access_token']);
        if (!$userResult['success']) {
            return ['success' => false, 'error' => $userResult['error'] ?? 'Failed to load user profile.'];
        }

        $accountsUserId = self::extractUserId($userResult['user']);
        if ($accountsUserId === '') {
            return ['success' => false, 'error' => 'Accounts did not return a valid user identifier.'];
        }

        if (!Auth::loginFromAccounts($accountsUserId, $userResult['user'])) {
            return ['success' => false, 'error' => 'Your account is not permitted to access this CMS.'];
        }

        return ['success' => true];
    }

    /**
     * @param  array<string, string|int>|null $body
     * @param  list<string> $headers
     * @return array{success: bool, body?: string, error?: string}
     */
    private static function httpRequest(string $method, string $url, ?array $body = null, array $headers = []): array {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CUSTOMREQUEST  => strtoupper($method),
                CURLOPT_HTTPHEADER     => $headers,
            ]);

            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
            }

            $responseBody = curl_exec($ch);
            $statusCode   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError    = curl_error($ch);
            curl_close($ch);

            if ($responseBody === false) {
                return ['success' => false, 'error' => $curlError ?: 'HTTP request failed.'];
            }

            if ($statusCode < 200 || $statusCode >= 300) {
                return ['success' => false, 'error' => 'Accounts API returned HTTP ' . $statusCode . '.'];
            }

            return ['success' => true, 'body' => $responseBody];
        }

        $headerLines = implode("\r\n", $headers);
        $content     = $body !== null ? http_build_query($body) : '';
        if ($body !== null && !in_array('Content-Type: application/x-www-form-urlencoded', $headers, true)) {
            $headerLines .= ($headerLines !== '' ? "\r\n" : '') . 'Content-Type: application/x-www-form-urlencoded';
        }

        $context = stream_context_create([
            'http' => [
                'method'  => strtoupper($method),
                'header'  => $headerLines,
                'content' => $content,
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        if ($responseBody === false) {
            return ['success' => false, 'error' => 'HTTP request failed.'];
        }

        return ['success' => true, 'body' => $responseBody];
    }

    /**
     * Encrypt and persist Accounts credential options.
     *
     * @param array<string, string> $credentials
     */
    public static function storeCredentialOptions(array $credentials): void {
        foreach ($credentials as $key => $value) {
            if (in_array($key, self::ENCRYPTED_OPTION_KEYS, true)) {
                $value = self::encryptSecret($value);
            }
            Database::setOption($key, $value);
        }
    }

    /**
     * One-time migration helper for sites with plaintext stored secrets.
     */
    public static function migrateStoredSecretsIfNeeded(): void {
        foreach (self::ENCRYPTED_OPTION_KEYS as $key) {
            $raw = (string) Database::getOption($key, '');
            if ($raw !== '' && !str_starts_with($raw, self::ENCRYPT_PREFIX)) {
                Database::setOption($key, self::encryptSecret($raw));
            }
        }
    }

    public static function encryptSecret(string $value): string {
        if ($value === '') {
            return '';
        }

        if (!function_exists('openssl_encrypt')) {
            throw new \RuntimeException('OpenSSL is required to encrypt Accounts credentials.');
        }

        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($value, 'aes-256-gcm', self::encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Failed to encrypt Accounts credential.');
        }

        return self::ENCRYPT_PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public static function decryptSecret(string $value): string {
        if ($value === '') {
            return '';
        }

        if (!str_starts_with($value, self::ENCRYPT_PREFIX)) {
            return $value;
        }

        if (!function_exists('openssl_decrypt')) {
            throw new \RuntimeException('OpenSSL is required to decrypt Accounts credentials.');
        }

        $payload = base64_decode(substr($value, strlen(self::ENCRYPT_PREFIX)), true);
        if ($payload === false || strlen($payload) < 28) {
            throw new \RuntimeException('Invalid encrypted Accounts credential payload.');
        }

        $iv     = substr($payload, 0, 12);
        $tag    = substr($payload, 12, 16);
        $cipher = substr($payload, 28);

        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new \RuntimeException('Failed to decrypt Accounts credential.');
        }

        return $plain;
    }

    private static function encryptionKey(): string {
        if (!defined('SOI_SECRET_KEY') || SOI_SECRET_KEY === '') {
            throw new \RuntimeException('SOI_SECRET_KEY is not configured.');
        }

        return hash('sha256', (string) SOI_SECRET_KEY, true);
    }

    /**
     * Short hash prefix for comparing encrypted/plain secrets without logging values.
     */
    private static function secretHashPrefix(string $storedValue): string {
        if ($storedValue === '') {
            return '';
        }

        try {
            $plain = str_starts_with($storedValue, self::ENCRYPT_PREFIX)
                ? self::decryptSecret($storedValue)
                : $storedValue;
        } catch (\Throwable) {
            $plain = $storedValue;
        }

        return substr(hash('sha256', $plain), 0, 16);
    }

    /**
     * Reset SAML caches and stale metadata state before applying re-linked credentials.
     */
    private static function clearRelinkState(): void {
        try {
            Database::query("DELETE FROM `" . Database::prefix('central_saml_replay') . "`");
        } catch (\Throwable $e) {
            error_log('[Accounts] Failed to clear SAML replay cache on re-link: ' . $e->getMessage());
        }

        try {
            Database::query("DELETE FROM `" . Database::prefix('central_access_cache') . "`");
        } catch (\Throwable $e) {
            error_log('[Accounts] Failed to clear central access cache on re-link: ' . $e->getMessage());
        }

        self::clearStaleSamlCertificates('re-link state reset');
        error_log('[Accounts] Re-link state cleared (SAML replay/access caches and stale metadata reset).');
    }

    private static function clearStaleSamlCertificates(string $context): void {
        if (class_exists(SoiCentralAuth::class)) {
            SoiCentralAuth::clearIdpCertificates();
        } else {
            Database::setOptions([
                'soi_central_saml_x509_cert'              => '',
                'soi_central_saml_x509_certs'             => '',
                'saml_x509_cert'                          => '',
                'soi_central_saml_metadata_fetched_at'    => '',
                'soi_central_saml_metadata_fetch_status'  => '',
                'soi_central_saml_metadata_fetch_error'   => '',
                'soi_central_saml_metadata_fetch_reason'  => '',
            ]);
        }

        if (str_starts_with($context, 'relink')) {
            error_log('[Accounts] Cleared stale SAML certificates for re-link (' . $context . ')');
            return;
        }

        error_log('[Accounts] Cleared stale SAML certificates (' . $context . ')');
    }

    private static function normalizeState(string $state): string {
        return trim(urldecode($state));
    }

    /**
     * @return array{state: string, created_at: int, redirect_uri: string, relink: bool}
     */
    private static function getSavedConnectState(): array {
        $raw = (string) Database::getOption(self::STATE_OPTION, '');
        if ($raw === '') {
            return ['state' => '', 'created_at' => 0, 'redirect_uri' => '', 'relink' => false];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded) && !empty($decoded['state'])) {
            return [
                'state'        => (string) $decoded['state'],
                'created_at'   => (int) ($decoded['created_at'] ?? 0),
                'redirect_uri' => (string) ($decoded['redirect_uri'] ?? ''),
                'relink'       => !empty($decoded['relink']),
            ];
        }

        // Legacy plain-string state from older installer saves
        return ['state' => $raw, 'created_at' => 0, 'redirect_uri' => '', 'relink' => false];
    }

    private static function validateConnectState(string $receivedState): bool {
        $received = self::normalizeState($receivedState);
        if ($received === '') {
            return false;
        }

        $saved      = self::getSavedConnectState();
        $savedState = self::normalizeState($saved['state']);

        if ($savedState !== '') {
            $expired = $saved['created_at'] > 0
                && (time() - $saved['created_at']) > self::CONNECT_STATE_TTL;

            if (!$expired && hash_equals($savedState, $received)) {
                return true;
            }

            // Legacy installs stored a plain string without created_at — still accept if it matches.
            if ($saved['created_at'] === 0 && hash_equals($savedState, $received)) {
                return true;
            }
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $sessionState = self::normalizeState((string) ($_SESSION[self::STATE_SESSION_KEY] ?? ''));
            if ($sessionState !== '' && hash_equals($sessionState, $received)) {
                return true;
            }
        }

        return false;
    }

    private static function clearConnectState(): void {
        Database::delete('options', 'option_key = ?', [self::STATE_OPTION]);

        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION[self::STATE_SESSION_KEY]);
        }
    }

    private static function callbackMatchesSavedRedirectUri(): bool {
        $saved = self::getSavedConnectState();
        $expected = trim((string) ($saved['redirect_uri'] ?? ''));
        if ($expected === '') {
            return true;
        }

        $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        $scheme = $forwardedProto === 'https'
            || (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
            ? 'https'
            : 'http';
        $forwardedHost = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''))[0]);
        $host = $forwardedHost !== '' ? $forwardedHost : (string)($_SERVER['HTTP_HOST'] ?? '');
        $path   = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
        if ($host === '' || $path === '') {
            return true;
        }

        $actual = rtrim($scheme . '://' . $host . $path, '/');
        $expectedNormalized = rtrim($expected, '/');

        return hash_equals($expectedNormalized, $actual);
    }
}
