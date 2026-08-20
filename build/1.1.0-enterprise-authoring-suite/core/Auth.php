<?php
namespace SOI\Core;

/**
 * Auth - Session-based authentication with CSRF and Accounts SSO
 */
class Auth {
    private const SESSION_KEY = 'soi_user';
    private const CSRF_KEY    = 'soi_csrf';

    /** Admin routes accessible before Accounts is linked */
    private const ACCOUNTS_WHITELIST = [
        'connect.php',
        'accounts-callback.php',
        'auth-callback.php',
        'login.php',
        'logout.php',
        'soi-central.php',
    ];

    public static function init(): void {
        if (session_status() === PHP_SESSION_NONE) {
            $homeUrl = defined('SOI_HOME_URL') ? (string)SOI_HOME_URL : '/';
            $cookiePath = (string)(parse_url($homeUrl, PHP_URL_PATH) ?: '/');
            $cookiePath = '/' . trim($cookiePath, '/');
            if ($cookiePath !== '/') $cookiePath .= '/';
            $secure = strtolower((string)(parse_url($homeUrl, PHP_URL_SCHEME) ?: '')) === 'https';

            ini_set('session.cookie_httponly', '1');
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            session_name('SOI_' . strtoupper(substr(hash('sha256', $homeUrl), 0, 12)));
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => $cookiePath,
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
        if (empty($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        }
    }

    public static function loginFromAccounts(string $accountsUserId, array $userInfo): bool {
        self::ensureUsersSchema();

        $accountsUserId = trim($accountsUserId);
        if ($accountsUserId === '') {
            return false;
        }

        $table = Database::prefix('users');
        $user  = Database::selectOne("SELECT * FROM `$table` WHERE accounts_user_id = ?", [$accountsUserId]);

        $email       = trim((string) ($userInfo['email'] ?? ''));
        $displayName = trim((string) ($userInfo['name'] ?? $userInfo['display_name'] ?? $email));
        if ($displayName === '') {
            $displayName = 'SOI User';
        }

        if ($user) {
            if (!(int) ($user['status'] ?? 0)) {
                return false;
            }

            $updates = [];
            if ($email !== '' && $email !== ($user['email'] ?? '')) {
                $updates['email'] = $email;
            }
            if ($displayName !== ($user['display_name'] ?? '')) {
                $updates['display_name'] = $displayName;
            }
            if ($updates) {
                Database::update('users', $updates, 'id = ?', [(int) $user['id']]);
                $user = array_merge($user, $updates);
            }
        } else {
            $role = Database::count('users') === 0 ? 'admin' : 'author';

            if ($email === '') {
                $email = $accountsUserId . '@accounts.soi.co.in';
            }

            $userId = Database::insert('users', [
                'accounts_user_id' => $accountsUserId,
                'username'         => self::uniqueUsername($accountsUserId, $userInfo),
                'email'            => $email,
                'password'         => null,
                'role'             => $role,
                'display_name'     => $displayName,
                'status'           => 1,
            ]);

            $user = Database::selectOne("SELECT * FROM `$table` WHERE id = ?", [$userId]);
            if (!$user) {
                return false;
            }
        }

        $_SESSION[self::SESSION_KEY] = [
            'id'               => (int) $user['id'],
            'accounts_user_id' => $accountsUserId,
            'username'         => $user['username'],
            'email'            => $user['email'],
            'role'             => $user['role'],
            'display_name'     => $user['display_name'] ?? $displayName,
        ];
        session_regenerate_id(true);

        Database::query("UPDATE `$table` SET last_login = NOW() WHERE id = ?", [(int) $user['id']]);

        return true;
    }

    public static function logout(): void {
        if (class_exists(SoiCentralAuth::class)) {
            SoiCentralAuth::clearSessionBinding();
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
    }

    public static function check(): bool {
        return !empty($_SESSION[self::SESSION_KEY]);
    }

    public static function user(): ?array {
        return $_SESSION[self::SESSION_KEY] ?? null;
    }

    public static function id(): ?int {
        return isset($_SESSION[self::SESSION_KEY]['id'])
            ? (int) $_SESSION[self::SESSION_KEY]['id']
            : null;
    }

    public static function role(): string {
        return $_SESSION[self::SESSION_KEY]['role'] ?? '';
    }

    public static function requireAccountsLinked(): void {
        $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if (in_array($script, self::ACCOUNTS_WHITELIST, true)) {
            return;
        }

        if (!Accounts::isLinked()) {
            header('Location: ' . SOI_ADMIN_URL . '/connect.php');
            exit;
        }
    }

    public static function requireAuth(string $minRole = 'subscriber'): void {
        self::requireAccountsLinked();

        if (!self::check()) {
            if (class_exists(SoiCentralAuth::class) && SoiCentralAuth::shouldRedirectAdminLogin()) {
                SoiCentralAuth::redirectToLogin(SoiCentralAuth::currentUrl());
            }
            header('Location: ' . SOI_ADMIN_URL . '/login.php');
            exit;
        }

        if (class_exists(SoiCentralAuth::class)) {
            SoiCentralAuth::requireAccessForSession($minRole);
            SoiCentralAuth::repairSessionRoleForDirectoryFallback();
        }

        $roles = ['subscriber' => 0, 'author' => 1, 'editor' => 2, 'admin' => 3];
        $userLevel  = $roles[self::role()] ?? 0;
        $minLevel   = $roles[$minRole] ?? 0;
        if ($userLevel < $minLevel) {
            http_response_code(403);
            die('Access denied.');
        }
    }

    public static function csrfToken(): string {
        return $_SESSION[self::CSRF_KEY] ?? '';
    }

    public static function verifyCsrf(string $token): bool {
        return hash_equals($_SESSION[self::CSRF_KEY] ?? '', $token);
    }

    public static function csrfField(): string {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::csrfToken()) . '">';
    }

    private static function ensureUsersSchema(): void {
        $table = Database::prefix('users');

        $column = Database::selectOne(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = 'accounts_user_id'",
            [SOI_DB_NAME, $table]
        );

        if (!$column) {
            Database::exec("ALTER TABLE `$table`
                ADD COLUMN `accounts_user_id` varchar(191) DEFAULT NULL AFTER `id`,
                ADD UNIQUE KEY `accounts_user_id` (`accounts_user_id`)");
        }
    }

    private static function uniqueUsername(string $accountsUserId, array $userInfo): string {
        $base = trim((string) ($userInfo['preferred_username'] ?? $userInfo['username'] ?? ''));
        if ($base === '') {
            $email = trim((string) ($userInfo['email'] ?? ''));
            if ($email !== '' && str_contains($email, '@')) {
                $base = (string) strstr($email, '@', true);
            }
        }
        if ($base === '') {
            $base = 'user_' . preg_replace('/[^a-zA-Z0-9_]/', '', $accountsUserId);
        }

        $base = substr(preg_replace('/[^a-zA-Z0-9_]/', '', $base) ?: 'user', 0, 80);
        $table = Database::prefix('users');
        $candidate = $base;
        $suffix = 1;

        while (Database::selectOne("SELECT id FROM `$table` WHERE username = ?", [$candidate])) {
            $candidate = $base . '_' . $suffix;
            $suffix++;
        }

        return $candidate;
    }
}
