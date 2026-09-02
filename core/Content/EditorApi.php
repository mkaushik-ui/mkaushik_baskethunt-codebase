<?php
declare(strict_types=1);

namespace SOI\Core\Content;

use SOI\Core\Auth;
use SOI\Core\Cache;
use SOI\Core\Database;

/**
 * Authenticated JSON API for the structured editor (1.1.0).
 * Handles save, autosave, preview, media, revisions, templates, patterns, and reusable blocks.
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
                'history' => self::history($payload),
                'get_revision' => self::getRevision($payload),
                'restore_revision' => self::restoreRevision($payload),
                'templates_list' => self::templatesList($payload),
                'patterns_list' => self::patternsList($payload),
                'reusable_list' => self::reusableList($payload),
                'reusable_get' => self::reusableGet($payload),
                'reusable_save' => self::reusableSave($payload),
                'reusable_delete' => self::reusableDelete($payload),
                'convert_legacy' => self::convertLegacy($payload),
                default => self::respond(400, ['ok' => false, 'error' => 'Unknown editor action.']),
            };
        } catch (DocumentException $e) {
            $extra = isset($e->extra) && is_array($e->extra) ? $e->extra : [];
            self::respond(422, array_merge(['ok' => false, 'error' => $e->getMessage()], $extra));
        } catch (\Throwable $e) {
            self::respond(500, ['ok' => false, 'error' => 'The editor could not complete that request: ' . $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function save(array $payload, bool $autosave): void
    {
        $response = ContentService::saveDocument($payload, $autosave);
        self::respond(200, $response);
    }

    private static function preview(array $payload): void
    {
        $response = ContentService::previewDocument($payload);
        self::respond(200, $response);
    }

    private static function history(array $payload): void
    {
        $entity = (string) ($payload['entity'] ?? 'page');
        $id = (int) ($payload['id'] ?? 0);
        ContentStore::assertEntityAllowed($entity);
        if ($id <= 0) {
            self::respond(200, ['ok' => true, 'revisions' => []]);
        }
        $revisions = ContentStore::getRevisions($entity, $id);
        self::respond(200, ['ok' => true, 'revisions' => $revisions]);
    }

    private static function getRevision(array $payload): void
    {
        $revId = (int) ($payload['revision_id'] ?? 0);
        $rev = ContentStore::getRevision($revId);
        if (!$rev) {
            throw new DocumentException('Revision not found.');
        }
        $editorJsData = null;
        if (!empty($rev['body_json'])) {
            $doc = Document::parse($rev['body_json']);
            $editorJsData = Document::toEditorJs($doc);
        }
        self::respond(200, [
            'ok' => true,
            'revision' => $rev,
            'editorJsData' => $editorJsData,
        ]);
    }

    private static function restoreRevision(array $payload): void
    {
        $entity = (string) ($payload['entity'] ?? 'page');
        $id = (int) ($payload['id'] ?? 0);
        $revId = (int) ($payload['revision_id'] ?? 0);
        $res = ContentStore::restoreRevision($entity, $id, $revId);
        $record = $res['record'];
        $editorJsData = null;
        if (!empty($record['body_json'])) {
            $doc = Document::parse($record['body_json']);
            $editorJsData = Document::toEditorJs($doc);
        }
        self::respond(200, [
            'ok' => true,
            'id' => $id,
            'title' => $record['title'] ?? '',
            'status' => $record['status'] ?? 'draft',
            'updated_at' => $record['updated_at'] ?? '',
            'editor_format' => $record['editor_format'] ?? '',
            'editorJsData' => $editorJsData,
            'legacy_html' => $record['content'] ?? '',
        ]);
    }

    private static function templatesList(array $payload): void
    {
        self::respond(200, [
            'ok' => true,
            'templates' => Templates::all(),
        ]);
    }

    private static function patternsList(array $payload): void
    {
        self::respond(200, [
            'ok' => true,
            'patterns' => Patterns::all(),
        ]);
    }

    private static function reusableList(array $payload): void
    {
        self::respond(200, [
            'ok' => true,
            'reusable' => ContentStore::listReusable(),
        ]);
    }

    private static function reusableGet(array $payload): void
    {
        $id = (int) ($payload['id'] ?? 0);
        $item = ContentStore::getReusable($id);
        if (!$item) {
            throw new DocumentException('Reusable block not found.');
        }
        $doc = Document::parse($item['content_json']);
        self::respond(200, [
            'ok' => true,
            'id' => $item['id'],
            'title' => $item['title'],
            'content' => $doc,
            'editorJsData' => Document::toEditorJs($doc),
        ]);
    }

    private static function reusableSave(array $payload): void
    {
        $id = isset($payload['id']) ? (int) $payload['id'] : null;
        $title = (string) ($payload['title'] ?? '');
        $document = Document::parse($payload['document'] ?? null);
        $res = ContentStore::saveReusable($id, $title, $document);
        self::respond(200, [
            'ok' => true,
            'id' => $res['id'],
            'title' => $res['title'],
        ]);
    }

    private static function reusableDelete(array $payload): void
    {
        $id = (int) ($payload['id'] ?? 0);
        ContentStore::deleteReusable($id);
        self::respond(200, ['ok' => true]);
    }

    private static function convertLegacy(array $payload): void
    {
        $entity = (string) ($payload['entity'] ?? 'page');
        $id = (int) ($payload['id'] ?? 0);
        $updated = ContentStore::convertLegacyToStructured($entity, $id);
        $doc = Document::parse($updated['body_json'] ?? null);
        self::respond(200, [
            'ok' => true,
            'id' => $id,
            'editor_format' => EditorSchema::FORMAT_STRUCTURED,
            'editorJsData' => Document::toEditorJs($doc),
            'updated_at' => $updated['updated_at'] ?? '',
        ]);
    }

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
        $table = Database::prefix('media');
        $rows = Database::select(
            "SELECT id, filename, original_name, mime_type, url, alt_text, file_size, created_at
             FROM `$table`
             WHERE $where
             ORDER BY id DESC
             LIMIT 40",
            $params
        );
        $items = [];
        foreach ($rows as $row) {
            $url = (string) ($row['url'] ?? '');
            if ($url === '' && !empty($row['filename'])) {
                $url = rtrim(SOI_HOME_URL, '/') . '/uploads/' . ltrim((string) $row['filename'], '/');
            }
            $items[] = [
                'id' => (int) $row['id'],
                'url' => $url,
                'title' => $row['original_name'] ?: $row['filename'],
                'alt' => (string) ($row['alt_text'] ?? ''),
                'mime' => (string) ($row['mime_type'] ?? ''),
                'size' => (int) ($row['file_size'] ?? 0),
            ];
        }
        self::respond(200, ['ok' => true, 'items' => $items]);
    }

    private static function payload(): array
    {
        $raw = (string) file_get_contents('php://input');
        if (trim($raw) === '') {
            return $_POST;
        }
        $json = json_decode($raw, true);
        return is_array($json) ? array_merge($_POST, $json) : $_POST;
    }

    private static function respond(int $status, array $data): void
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    private static function viewUrl(string $slug): string
    {
        if ($slug === '') return '';
        return rtrim(SOI_HOME_URL, '/') . '/' . ltrim($slug, '/');
    }
}
