<?php
declare(strict_types=1);

namespace SOI\Core\Content;

use SOI\Core\Auth;
use SOI\Core\Blog;
use SOI\Core\Database;

/**
 * Persists structured documents for pages and posts without assuming
 * a future Library / Docs / Tech destination.
 */
final class ContentStore
{
    public const ENTITIES = ['page' => 'pages', 'post' => 'posts'];

    public static function tableFor(string $entity): string
    {
        if (!isset(self::ENTITIES[$entity])) {
            throw new DocumentException('Unknown document entity.');
        }
        return self::ENTITIES[$entity];
    }

    public static function assertEntityAllowed(string $entity): void
    {
        if ($entity === 'post') {
            if (class_exists(Blog::class)) {
                Blog::ensureMigrated();
                if (!Blog::isEnabled()) {
                    throw new DocumentException('Blog features are disabled.');
                }
            }
        }
        self::tableFor($entity);
    }

    public static function find(string $entity, int $id): ?array
    {
        $table = Database::prefix(self::tableFor($entity));
        return Database::selectOne("SELECT * FROM `$table` WHERE id = ? LIMIT 1", [$id]);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{id:int,record:array<string,mixed>}
     */
    public static function save(string $entity, int $id, array $fields, array $document): array
    {
        EditorSchema::ensure();
        self::assertEntityAllowed($entity);

        $errors = Document::validate($document);
        if ($errors !== []) {
            throw new DocumentException(implode(' ', $errors));
        }

        $bodyJson = Document::encode($document);
        $rendered = DocumentRenderer::render($document, false);

        $status = (string) ($fields['status'] ?? 'draft');
        if (!in_array($status, ['published', 'draft', 'private'], true)) {
            $status = 'draft';
        }

        $title = trim((string) ($fields['title'] ?? ''));
        if ($title === '') {
            throw new DocumentException('Title is required.');
        }

        $slugSource = trim((string) ($fields['slug'] ?? '')) ?: $title;
        $slug = function_exists('slugify') ? slugify($slugSource) : preg_replace('/[^a-z0-9\-]/', '-', strtolower($slugSource));
        $slug = is_string($slug) && $slug !== '' ? $slug : 'document';

        $row = [
            'title' => $title,
            'slug' => $slug,
            'content' => $rendered,
            'body_json' => $bodyJson,
            'editor_format' => EditorSchema::FORMAT_STRUCTURED,
            'schema_version' => EditorSchema::SCHEMA_VERSION,
            'status' => $status,
            'meta_title' => trim((string) ($fields['meta_title'] ?? '')),
            'meta_desc' => trim((string) ($fields['meta_desc'] ?? '')),
        ];

        if ($entity === 'post') {
            $row['excerpt'] = trim((string) ($fields['excerpt'] ?? ''));
        }

        $table = self::tableFor($entity);
        if ($id > 0) {
            $existing = self::find($entity, $id);
            if (!$existing) {
                throw new DocumentException('Document not found.');
            }
            $expected = (string) ($fields['expected_updated_at'] ?? '');
            if ($expected !== '' && isset($existing['updated_at']) && (string) $existing['updated_at'] !== $expected) {
                throw new DocumentException('This document was modified in another session. Reload before saving.');
            }
            Database::update($table, $row, 'id = ?', [$id]);
        } else {
            $row['author_id'] = Auth::id();
            $id = Database::insert($table, $row);
        }

        if ($entity === 'post') {
            self::syncPostCategories($id, $fields['categories'] ?? []);
        }

        $record = self::find($entity, $id);
        if (!$record) {
            throw new DocumentException('Document could not be reloaded after save.');
        }

        return ['id' => $id, 'record' => $record];
    }

    /**
     * Opt-in wrap of existing TinyMCE HTML. Does not parse HTML into blocks.
     */
    public static function convertLegacyToStructured(string $entity, int $id): array
    {
        EditorSchema::ensure();
        self::assertEntityAllowed($entity);
        $record = self::find($entity, $id);
        if (!$record) {
            throw new DocumentException('Document not found.');
        }
        if (EditorSchema::isStructured($record)) {
            return $record;
        }
        $html = (string) ($record['content'] ?? '');
        $blocks = [];
        if (trim($html) !== '') {
            $blocks[] = [
                'id' => Document::newId(),
                'type' => 'legacy',
                'data' => ['html' => $html],
            ];
        }
        $document = Document::normalize([
            'schemaVersion' => Document::SCHEMA_VERSION,
            'blocks' => $blocks,
        ]);
        Database::update(self::tableFor($entity), [
            'body_json' => Document::encode($document),
            'editor_format' => EditorSchema::FORMAT_STRUCTURED,
            'schema_version' => EditorSchema::SCHEMA_VERSION,
        ], 'id = ?', [$id]);
        $updated = self::find($entity, $id);
        if (!$updated) {
            throw new DocumentException('Document could not be reloaded after conversion.');
        }
        return $updated;
    }

    /**
     * Keep a legacy HTML document editable without converting it.
     *
     * @param array<string, mixed> $fields
     * @return array{id:int,record:array<string,mixed>}
     */
    public static function saveLegacy(string $entity, int $id, array $fields, string $html): array
    {
        EditorSchema::ensure();
        self::assertEntityAllowed($entity);

        $title = trim((string) ($fields['title'] ?? ''));
        if ($title === '') {
            throw new DocumentException('Title is required.');
        }
        $slugSource = trim((string) ($fields['slug'] ?? '')) ?: $title;
        $slug = function_exists('slugify') ? slugify($slugSource) : 'document';
        $status = (string) ($fields['status'] ?? 'draft');
        if (!in_array($status, ['published', 'draft', 'private'], true)) {
            $status = 'draft';
        }

        $row = [
            'title' => $title,
            'slug' => $slug,
            'content' => Html::sanitizeLegacy($html),
            'status' => $status,
            'meta_title' => trim((string) ($fields['meta_title'] ?? '')),
            'meta_desc' => trim((string) ($fields['meta_desc'] ?? '')),
            'editor_format' => EditorSchema::FORMAT_LEGACY,
        ];
        if ($entity === 'post') {
            $row['excerpt'] = trim((string) ($fields['excerpt'] ?? ''));
        }

        $table = self::tableFor($entity);
        if ($id > 0) {
            if (!self::find($entity, $id)) {
                throw new DocumentException('Document not found.');
            }
            Database::update($table, $row, 'id = ?', [$id]);
        } else {
            $row['author_id'] = Auth::id();
            $id = Database::insert($table, $row);
        }
        if ($entity === 'post') {
            self::syncPostCategories($id, $fields['categories'] ?? []);
        }
        $record = self::find($entity, $id);
        if (!$record) {
            throw new DocumentException('Document could not be reloaded after save.');
        }
        return ['id' => $id, 'record' => $record];
    }

    /**
     * @param mixed $categoryIds
     */
    private static function syncPostCategories(int $postId, mixed $categoryIds): void
    {
        if (!Database::tableExists('post_categories')) {
            return;
        }
        Database::query("DELETE FROM `" . Database::prefix('post_categories') . "` WHERE post_id = ?", [$postId]);
        if (!is_array($categoryIds)) {
            return;
        }
        foreach ($categoryIds as $catId) {
            $catId = (int) $catId;
            if ($catId > 0) {
                Database::query(
                    "INSERT IGNORE INTO `" . Database::prefix('post_categories') . "` (post_id, category_id) VALUES (?,?)",
                    [$postId, $catId]
                );
            }
        }
    }
}
