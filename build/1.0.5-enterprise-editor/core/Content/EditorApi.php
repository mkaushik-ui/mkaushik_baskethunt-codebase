<?php
declare(strict_types=1);

namespace SOI\Core\Content;

use SOI\Core\Auth;
use SOI\Core\Cache;
use SOI\Core\Database;

/**
 * Authenticated JSON API for the structured editor (save/preview/media).
 */
final class EditorApi
{
    public static function handle(): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');

        Auth::init();
        if (!Auth::check()) {
            self::respond(401, ['ok' => false, 'error' => 'Authentication required.']);
        }
        Auth::requireAuth('author');
        EditorSchema::ensure();

        $payload = self::payload();
        $csrf = (string) ($payload['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!Auth::verifyCsrf($csrf)) {
            self::respond(403, ['ok' => false, 'error' => 'CSRF check failed.']);
        }

        $action = (string) ($payload['_action'] ?? $_GET['action'] ?? '');
        try {
            match ($action) {
                'save', 'autosave' => self::save($payload, $action === 'autosave'),
                'preview' => self::preview($payload),
                'media_list' => self::mediaList($payload),
                default => self::respond(400, ['ok' => false, 'error' => 'Unknown editor action.']),
            };
        } catch (DocumentException $e) {
            self::respond(422, ['ok' => false, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            self::respond(500, ['ok' => false, 'error' => 'The editor could not complete that request.']);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function save(array $payload, bool $autosave): void
    {
        $entity = (string) ($payload['entity'] ?? '');
        $id = (int) ($payload['id'] ?? 0);
        ContentStore::assertEntityAllowed($entity);

        $mode = (string) ($payload['mode'] ?? 'structured');
        $fields = [
            'title' => (string) ($payload['title'] ?? ''),
            'slug' => (string) ($payload['slug'] ?? ''),
            'status' => (string) ($payload['status'] ?? 'draft'),
            'meta_title' => (string) ($payload['meta_title'] ?? ''),
            'meta_desc' => (string) ($payload['meta_desc'] ?? ''),
            'excerpt' => (string) ($payload['excerpt'] ?? ''),
            'categories' => $payload['categories'] ?? [],
            'expected_updated_at' => (string) ($payload['expected_updated_at'] ?? ''),
        ];

        if ($autosave && $id > 0) {
            $existing = ContentStore::find($entity, $id);
            if ($existing && $fields['title'] === '') {
                $fields['title'] = (string) ($existing['title'] ?? '');
            }
            if ($existing && $fields['status'] === 'draft' && isset($existing['status'])) {
                $fields['status'] = (string) $existing['status'];
            }
        }

        if ($mode === 'legacy') {
            $result = ContentStore::saveLegacy($entity, $id, $fields, (string) ($payload['legacy_html'] ?? ''));
        } else {
            $document = Document::parse($payload['document'] ?? null);
            $result = ContentStore::save($entity, $id, $fields, $document);
        }

        if (class_exists(Cache::class)) {
            Cache::purgeAll();
        }

        $record = $result['record'];
        self::respond(200, [
            'ok' => true,
            'autosave' => $autosave,
            'id' => $result['id'],
            'slug' => $record['slug'] ?? '',
            'status' => $record['status'] ?? 'draft',
            'updated_at' => $record['updated_at'] ?? '',
            'editor_format' => $record['editor_format'] ?? EditorSchema::FORMAT_STRUCTURED,
            'view_url' => self::viewUrl((string) ($record['slug'] ?? '')),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function preview(array $payload): void
    {
        $entity = (string) ($payload['entity'] ?? 'page');
        ContentStore::assertEntityAllowed($entity);
        $document = Document::parse($payload['document'] ?? null);
        $errors = Document::validate($document);
        if ($errors !== []) {
            throw new DocumentException(implode(' ', $errors));
        }
        $html = DocumentRenderer::render($document, true);
        $headings = DocumentRenderer::extractHeadings($document);
        self::respond(200, [
            'ok' => true,
            'html' => $html,
            'headings' => $headings,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function mediaList(array $payload): void
    {
        $q = trim((string) ($payload['q'] ?? ''));
        $kind = (string) ($payload['kind'] ?? 'all');
        $params = [];
        $where = '1=1';
        if ($q !== '') {
            $where .= ' AND (original_name LIKE ? OR filename LIKE ? OR alt_text LIKE ?)';
            $like = '%' . $q . '%';
            $params = [$like, $like, $like];
        }
        if ($kind === 'image') {
            $where .= " AND mime_type LIKE 'image/%'";
        }
        $rows = Database::select(
            "SELECT id, filename, original_name, mime_type, url, alt_text, file_size, created_at
             FROM `" . Database::prefix('media') . "`
             WHERE $where
             ORDER BY id DESC
             LIMIT 40",
            $params
        );
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'name' => (string) ($row['original_name'] ?: $row['filename']),
                'mime' => (string) ($row['mime_type'] ?? ''),
                'url' => AssetResolver::publicUrl((string) ($row['url'] ?? '')),
                'alt' => (string) ($row['alt_text'] ?? ''),
                'size' => (int) ($row['file_size'] ?? 0),
            ];
        }
        self::respond(200, ['ok' => true, 'items' => $items]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(): array
    {
        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
        if (str_contains(strtolower($contentType), 'application/json')) {
            $raw = file_get_contents('php://input') ?: '';
            if (strlen($raw) > Document::MAX_BYTES + 100000) {
                throw new DocumentException('Request payload is too large.');
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new DocumentException('Invalid JSON payload.');
            }
            return $decoded;
        }
        return $_POST;
    }

    private static function viewUrl(string $slug): string
    {
        if ($slug === '' || !defined('SOI_HOME_URL')) {
            return '';
        }
        return rtrim((string) SOI_HOME_URL, '/') . '/' . ltrim($slug, '/');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function respond(int $status, array $payload): never
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
