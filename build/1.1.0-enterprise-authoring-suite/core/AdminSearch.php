<?php
namespace SOI\Core;

/**
 * Authenticated Admin Center search with a short-lived stateless request token.
 */
class AdminSearch {
    private const TOKEN_VERSION = 1;
    private const TOKEN_TTL = 1800;
    private const TOKEN_CONTEXT = 'soi-admin-search-v1:';

    private const ROLE_LEVELS = [
        'subscriber' => 0,
        'author' => 1,
        'editor' => 2,
        'admin' => 3,
    ];

    public static function issueToken(array $user, ?string $userAgent = null): string {
        $userId = (int)($user['id'] ?? 0);
        $role = (string)($user['role'] ?? '');
        $secret = self::secret();
        if ($userId < 1 || !self::roleAtLeast($role, 'author') || $secret === '') {
            return '';
        }

        $issuedAt = time();
        $payload = [
            'v' => self::TOKEN_VERSION,
            'uid' => $userId,
            'role' => $role,
            'iat' => $issuedAt,
            'exp' => $issuedAt + self::TOKEN_TTL,
            'ua' => self::userAgentHash($userAgent),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return '';
        }

        $body = self::base64UrlEncode($json);
        $signature = hash_hmac('sha256', self::TOKEN_CONTEXT . $body, $secret, true);
        return $body . '.' . self::base64UrlEncode($signature);
    }

    public static function validateToken(string $token, ?string $userAgent = null): ?array {
        $secret = self::secret();
        $parts = explode('.', trim($token));
        if ($secret === '' || count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        [$body, $encodedSignature] = $parts;
        $signature = self::base64UrlDecode($encodedSignature);
        if ($signature === null) {
            return null;
        }
        $expected = hash_hmac('sha256', self::TOKEN_CONTEXT . $body, $secret, true);
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $json = self::base64UrlDecode($body);
        $payload = $json === null ? null : json_decode($json, true);
        if (!is_array($payload)) {
            return null;
        }

        $now = time();
        $issuedAt = (int)($payload['iat'] ?? 0);
        $expiresAt = (int)($payload['exp'] ?? 0);
        $userId = (int)($payload['uid'] ?? 0);
        $role = (string)($payload['role'] ?? '');
        if ((int)($payload['v'] ?? 0) !== self::TOKEN_VERSION
            || $userId < 1
            || !self::roleAtLeast($role, 'author')
            || $issuedAt > $now + 60
            || $expiresAt < $now
            || $expiresAt - $issuedAt > self::TOKEN_TTL) {
            return null;
        }

        if (!hash_equals((string)($payload['ua'] ?? ''), self::userAgentHash($userAgent))) {
            return null;
        }

        return $payload;
    }

    public static function roleAtLeast(string $role, string $minimum): bool {
        return (self::ROLE_LEVELS[$role] ?? -1) >= (self::ROLE_LEVELS[$minimum] ?? PHP_INT_MAX);
    }

    /**
     * @return array{results: array<string, array<int, array<string, mixed>>>, count: int, degraded: bool}
     */
    public static function search(string $query, string $role): array {
        Blog::ensureMigrated();
        $pattern = '%' . $query . '%';
        $results = [];
        $availableProviders = 0;
        $successfulProviders = 0;
        $failedProviders = [];

        $runProvider = static function (string $label, string $table, callable $provider) use (
            &$results,
            &$availableProviders,
            &$successfulProviders,
            &$failedProviders
        ): void {
            if (!Database::tableExists($table)) {
                $failedProviders[] = $label;
                error_log('[Admin Search] Skipped unavailable provider: ' . $label);
                return;
            }

            $availableProviders++;
            try {
                $items = $provider();
                $successfulProviders++;
                if (is_array($items) && $items !== []) {
                    $results[$label] = $items;
                }
            } catch (\Throwable $error) {
                $failedProviders[] = $label;
                error_log('[Admin Search] Provider ' . $label . ' failed: ' . $error->getMessage());
            }
        };

        $runProvider('Pages', 'pages', static function () use ($pattern): array {
            $rows = Database::select(
                "SELECT id, title, slug, status FROM `" . Database::prefix('pages') . "`
                 WHERE title LIKE ? OR slug LIKE ? ORDER BY title ASC LIMIT 8",
                [$pattern, $pattern]
            );
            return array_map(static fn(array $row): array => [
                'id' => (int)$row['id'],
                'title' => (string)$row['title'],
                'meta' => '/' . $row['slug'] . ' (' . $row['status'] . ')',
                'url' => self::adminUrl('pages.php?action=edit&id=' . (int)$row['id']),
            ], $rows);
        });

        if (Blog::isEnabled()) {
            $runProvider('Posts', 'posts', static function () use ($pattern): array {
                $rows = Database::select(
                    "SELECT id, title, slug, status FROM `" . Database::prefix('posts') . "`
                     WHERE title LIKE ? OR slug LIKE ? ORDER BY title ASC LIMIT 8",
                    [$pattern, $pattern]
                );
                return array_map(static fn(array $row): array => [
                    'id' => (int)$row['id'],
                    'title' => (string)$row['title'],
                    'meta' => '/' . $row['slug'] . ' (' . $row['status'] . ')',
                    'url' => self::adminUrl('posts.php?action=edit&id=' . (int)$row['id']),
                ], $rows);
            });
        }

        $runProvider('Media', 'media', static function () use ($pattern): array {
            $rows = Database::select(
                "SELECT id, filename, original_name, mime_type FROM `" . Database::prefix('media') . "`
                 WHERE original_name LIKE ? OR filename LIKE ? OR alt_text LIKE ?
                 ORDER BY created_at DESC LIMIT 6",
                [$pattern, $pattern, $pattern]
            );
            return array_map(static fn(array $row): array => [
                'id' => (int)$row['id'],
                'title' => (string)($row['original_name'] ?: $row['filename']),
                'meta' => (string)($row['mime_type'] ?: 'Media file'),
                'url' => self::adminUrl('media.php'),
            ], $rows);
        });

        if (self::roleAtLeast($role, 'editor')) {
            $runProvider('Menus', 'menus', static function () use ($pattern): array {
                $rows = Database::select(
                    "SELECT id, name, location FROM `" . Database::prefix('menus') . "`
                     WHERE name LIKE ? ORDER BY name ASC LIMIT 5",
                    [$pattern]
                );
                return array_map(static fn(array $row): array => [
                    'id' => (int)$row['id'],
                    'title' => (string)$row['name'],
                    'meta' => !empty($row['location']) ? 'Location: ' . $row['location'] : 'No location',
                    'url' => self::adminUrl('menus.php?menu_id=' . (int)$row['id']),
                ], $rows);
            });
        }

        if (self::roleAtLeast($role, 'admin')) {
            $runProvider('Users', 'users', static function () use ($pattern): array {
                $rows = Database::select(
                    "SELECT id, username, display_name, email, role FROM `" . Database::prefix('users') . "`
                     WHERE username LIKE ? OR display_name LIKE ? OR email LIKE ?
                     ORDER BY display_name ASC, username ASC LIMIT 6",
                    [$pattern, $pattern, $pattern]
                );
                return array_map(static fn(array $row): array => [
                    'id' => (int)$row['id'],
                    'title' => (string)($row['display_name'] ?: $row['username']),
                    'meta' => $row['email'] . ' - ' . $row['role'],
                    'url' => self::adminUrl('users.php?action=edit&id=' . (int)$row['id']),
                ], $rows);
            });
        }

        $destinations = self::destinationResults($query, $role);
        if ($destinations !== []) {
            $results['Admin Center'] = $destinations;
        }

        if ($availableProviders > 0 && $successfulProviders === 0) {
            throw new \RuntimeException('All available search providers failed.');
        }

        return [
            'results' => $results,
            'count' => array_sum(array_map('count', $results)),
            'degraded' => $failedProviders !== [],
        ];
    }

    private static function destinationResults(string $query, string $role): array {
        $destinations = [
            ['Dashboard', 'Overview, home, activity', '', 'author'],
            ['Pages', 'Content, website pages', 'pages.php', 'author'],
        ];
        if (Blog::isEnabled()) {
            $destinations[] = ['Posts', 'Content, articles, drafts', 'posts.php', 'author'];
        }
        $destinations = array_merge($destinations, [
            ['Media Library', 'Uploads, images, files', 'media.php', 'author'],
            ['Menus', 'Navigation, links', 'menus.php', 'editor'],
            ['Users & Roles', 'Team, access, accounts', 'users.php', 'admin'],
            ['Appearance', 'Theme, branding, design', 'appearance.php', 'admin'],
            ['Plugins', 'Extensions, integrations', 'plugins.php', 'admin'],
            ['Updates', 'CMS updates, packages', 'updates.php', 'admin'],
            ['Security', 'Protection, hotlink, server rules', 'security.php', 'admin'],
            ['API Keys', 'Developer access, tokens', 'api-keys.php', 'admin'],
            ['General Settings', 'Site name, URL, configuration', 'settings.php', 'admin'],
            ['SMTP / Email', 'Mail, delivery, notifications', 'smtp.php', 'admin'],
            ['My Profile', 'Account, display name, profile', 'profile.php', 'author'],
        ]);

        $needle = strtolower($query);
        $matches = [];
        foreach ($destinations as [$title, $keywords, $path, $minimumRole]) {
            if (!self::roleAtLeast($role, $minimumRole)) {
                continue;
            }
            if (!str_contains(strtolower($title . ' ' . $keywords), $needle)) {
                continue;
            }
            $matches[] = [
                'id' => 'destination-' . count($matches),
                'title' => $title,
                'meta' => $keywords,
                'url' => self::adminUrl($path),
            ];
            if (count($matches) >= 5) {
                break;
            }
        }
        return $matches;
    }

    private static function adminUrl(string $path): string {
        $base = defined('SOI_ADMIN_URL') ? (string)parse_url((string)SOI_ADMIN_URL, PHP_URL_PATH) : '/admin';
        $base = '/' . trim($base !== '' ? $base : '/admin', '/');
        return rtrim($base, '/') . ($path !== '' ? '/' . ltrim($path, '/') : '/');
    }

    private static function secret(): string {
        return defined('SOI_SECRET_KEY') ? trim((string)SOI_SECRET_KEY) : '';
    }

    private static function userAgentHash(?string $userAgent): string {
        $value = $userAgent ?? (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        return hash('sha256', substr($value, 0, 500));
    }

    private static function base64UrlEncode(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string {
        if ($value === '' || preg_match('/[^A-Za-z0-9_-]/', $value)) {
            return null;
        }
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
        return is_string($decoded) ? $decoded : null;
    }
}
