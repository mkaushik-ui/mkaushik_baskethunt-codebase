<?php
declare(strict_types=1);

namespace SOI\Core;

/**
 * Enterprise REST API Controller
 */
class Api {
    
    private static ?array $currentKey = null;

    public static function handle(string $uri, string $method): void {
        // Remove the 'api/' prefix
        $endpoint = preg_replace('#^api/#', '', $uri);

        // Ensure JSON responses
        header('Content-Type: application/json; charset=UTF-8');

        // CORS headers
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, X-API-Key, Content-Type');

        if ($method === 'OPTIONS') {
            http_response_code(200);
            exit;
        }

        self::ensureTableExists();
        Blog::ensureMigrated();

        if (!self::authenticate()) {
            self::jsonResponse(401, ['error' => 'Unauthorized. Invalid or missing API key.']);
        }

        // Handle routing
        self::route($endpoint, $method);
    }

    private static function authenticate(): bool {
        $headers = getallheaders();
        $apiKey = '';

        if (isset($headers['Authorization'])) {
            if (preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
                $apiKey = $matches[1];
            }
        } elseif (isset($headers['X-API-Key'])) {
            $apiKey = $headers['X-API-Key'];
        }

        if ($apiKey === '') return false;

        $prefix = Database::prefix('api_keys');
        $keyRecord = Database::selectOne("SELECT * FROM `$prefix` WHERE api_key = ? AND status = 1", [$apiKey]);

        if ($keyRecord) {
            self::$currentKey = $keyRecord;
            Database::update('api_keys', ['last_used_at' => date('Y-m-d H:i:s')], 'id = ?', [$keyRecord['id']]);
            return true;
        }

        return false;
    }

    private static function requirePermission(string $required): void {
        if (!self::$currentKey) {
            self::jsonResponse(401, ['error' => 'Unauthorized']);
        }
        $perms = self::$currentKey['permissions'] ?? '';
        if ($perms === 'full') return;
        
        $allowed = array_map('trim', explode(',', $perms));
        if (!in_array($required, $allowed, true)) {
            self::jsonResponse(403, ['error' => "Forbidden. Requires '{$required}' permission."]);
        }
    }

    private static function route(string $endpoint, string $method): void {
        if (str_starts_with($endpoint, 'posts') && !Blog::isEnabled()) {
            self::jsonResponse(404, ['error' => 'Blog module is not enabled.']);
        }

        if ($endpoint === 'posts' && $method === 'GET') {
            self::requirePermission('read');
            $posts = Database::select("SELECT * FROM `" . Database::prefix('posts') . "` ORDER BY id DESC");
            self::jsonResponse(200, ['data' => $posts]);
        }

        if (preg_match('#^posts/(\d+)$#', $endpoint, $matches) && $method === 'GET') {
            self::requirePermission('read');
            $post = Database::selectOne("SELECT * FROM `" . Database::prefix('posts') . "` WHERE id = ?", [(int)$matches[1]]);
            if (!$post) self::jsonResponse(404, ['error' => 'Post not found']);
            self::jsonResponse(200, ['data' => $post]);
        }

        if ($endpoint === 'posts' && $method === 'POST') {
            self::requirePermission('write');
            $data = json_decode(file_get_contents('php://input'), true) ?? [];
            if (empty($data['title'])) self::jsonResponse(400, ['error' => 'Title is required']);
            
            $slug = sanitize_slug($data['title']);
            $id = Database::insert('posts', [
                'author_id' => 1, // System Default
                'title' => $data['title'],
                'slug' => $slug,
                'content' => $data['content'] ?? '',
                'status' => $data['status'] ?? 'draft',
                'created_at' => date('Y-m-d H:i:s')
            ]);
            self::jsonResponse(201, ['message' => 'Post created', 'id' => $id]);
        }

        if (preg_match('#^posts/(\d+)$#', $endpoint, $matches) && $method === 'DELETE') {
            self::requirePermission('write');
            Database::delete('posts', 'id = ?', [(int)$matches[1]]);
            self::jsonResponse(200, ['message' => 'Post deleted']);
        }
        
        if ($endpoint === 'pages' && $method === 'GET') {
            self::requirePermission('read');
            $pages = Database::select("SELECT * FROM `" . Database::prefix('pages') . "` ORDER BY id DESC");
            self::jsonResponse(200, ['data' => $pages]);
        }

        if ($endpoint === 'users' && $method === 'GET') {
            self::requirePermission('read');
            $users = Database::select("SELECT id, username, email, role, display_name, status, created_at FROM `" . Database::prefix('users') . "` ORDER BY id DESC");
            self::jsonResponse(200, ['data' => $users]);
        }

        self::jsonResponse(404, ['error' => 'API endpoint not found or method not allowed']);
    }

    private static function jsonResponse(int $status, array $payload): void {
        http_response_code($status);
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private static function ensureTableExists(): void {
        $prefix = Database::prefix('api_keys');
        $sql = "CREATE TABLE IF NOT EXISTS `$prefix` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `api_key` varchar(255) NOT NULL,
            `permissions` varchar(255) NOT NULL DEFAULT 'read',
            `status` tinyint(1) NOT NULL DEFAULT 1,
            `created_at` datetime NOT NULL,
            `last_used_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `api_key` (`api_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        Database::exec($sql);
    }
}
