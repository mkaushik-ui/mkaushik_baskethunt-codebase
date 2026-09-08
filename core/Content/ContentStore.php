<?php
declare(strict_types=1);

namespace SOI\Core\Content;

use SOI\Core\Auth;
use SOI\Core\Blog;
use SOI\Core\Database;

/**
 * Persists structured documents, tracks revision history snapshots,
 * manages reusable components, and handles optimistic concurrency.
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
        $record = Database::selectOne("SELECT * FROM `$table` WHERE id = ? LIMIT 1", [$id]);
        if ($record) {
            if (!isset($record['space_id'])) {
                $record['space_id'] = 0;
            }
            if (!isset($record['section_id'])) {
                $record['section_id'] = 0;
            }
            if (!isset($record['doc_version'])) {
                $record['doc_version'] = 'v1.0';
            }
            if ((int) $record['space_id'] === 0 && class_exists(\SOI\Core\Spaces\SpaceSchema::class)) {
                try {
                    $docTable = \SOI\Core\Spaces\SpaceSchema::TABLE_DOCUMENTS;
                    $assigned = Database::selectOne("SELECT space_id, section_id FROM {$docTable} WHERE document_id = ? AND document_type = ? LIMIT 1", [$id, $entity]);
                    if ($assigned) {
                        $record['space_id'] = (int) $assigned['space_id'];
                        $record['section_id'] = (int) $assigned['section_id'];
                    }
                } catch (\Throwable $e) {
                    // Ignore if table not created yet
                }
            }
        }
        return $record;
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

        $spaceId = isset($fields['space_id']) ? (int) $fields['space_id'] : 0;
        $sectionId = isset($fields['section_id']) ? (int) $fields['section_id'] : 0;
        $docVersion = isset($fields['doc_version']) && trim((string) $fields['doc_version']) !== '' ? trim((string) $fields['doc_version']) : 'v1.0';

        $row = [
            'title' => $title,
            'slug' => $slug,
            'content' => $rendered,
            'body_json' => $bodyJson,
            'editor_format' => EditorSchema::FORMAT_STRUCTURED,
            'schema_version' => EditorSchema::SCHEMA_VERSION,
            'status' => $status,
            'space_id' => $spaceId,
            'section_id' => $sectionId,
            'doc_version' => $docVersion,
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
                $err = new DocumentException('This document was modified in another session. Reload or review before saving.');
                $err->extra = [
                    'conflict' => true,
                    'current_record' => [
                        'title' => $existing['title'],
                        'updated_at' => $existing['updated_at'],
                        'status' => $existing['status'],
                    ],
                ];
                throw $err;
            }
            Database::update($table, $row, 'id = ?', [$id]);
        } else {
            $row['author_id'] = Auth::id();
            $id = Database::insert($table, $row);
        }

        if ($entity === 'post') {
            self::syncPostCategories($id, $fields['categories'] ?? []);
        }

        // Synchronize with soi_space_documents if Space taxonomy is active
        if (class_exists(\SOI\Core\Spaces\TaxonomyService::class)) {
            try {
                if ($spaceId > 0) {
                    \SOI\Core\Spaces\TaxonomyService::instance()->assignDocument($spaceId, $sectionId, $id, $entity);
                } else {
                    \SOI\Core\Spaces\TaxonomyService::instance()->removeDocument(0, $id, $entity);
                }
            } catch (\Throwable $e) {
                // Non-fatal if taxonomy sync is bypassed
            }
        }

        $record = self::find($entity, $id);
        if (!$record) {
            throw new DocumentException('Document could not be reloaded after save.');
        }

        // Record revision snapshot
        self::createRevisionSnapshot($entity, $id, $record);

        return ['id' => $id, 'record' => $record];
    }

    /**
     * Opt-in wrap of existing TinyMCE HTML into a structured document with legacy block.
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

        // Backup snapshot before conversion
        self::createRevisionSnapshot($entity, $id, $record, 'Before legacy conversion');

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

        $spaceId = isset($fields['space_id']) ? (int) $fields['space_id'] : 0;
        $sectionId = isset($fields['section_id']) ? (int) $fields['section_id'] : 0;
        $docVersion = isset($fields['doc_version']) && trim((string) $fields['doc_version']) !== '' ? trim((string) $fields['doc_version']) : 'v1.0';

        $row = [
            'title' => $title,
            'slug' => $slug,
            'content' => Html::sanitizeLegacy($html),
            'status' => $status,
            'space_id' => $spaceId,
            'section_id' => $sectionId,
            'doc_version' => $docVersion,
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

        // Synchronize with soi_space_documents if Space taxonomy is active
        if (class_exists(\SOI\Core\Spaces\TaxonomyService::class)) {
            try {
                if ($spaceId > 0) {
                    \SOI\Core\Spaces\TaxonomyService::instance()->assignDocument($spaceId, $sectionId, $id, $entity);
                } else {
                    \SOI\Core\Spaces\TaxonomyService::instance()->removeDocument(0, $id, $entity);
                }
            } catch (\Throwable $e) {
                // Non-fatal
            }
        }

        $record = self::find($entity, $id);
        if (!$record) {
            throw new DocumentException('Document could not be reloaded after save.');
        }

        self::createRevisionSnapshot($entity, $id, $record);

        return ['id' => $id, 'record' => $record];
    }

    // --- Revision History Subsystem (1.1.0) ---

    public static function createRevisionSnapshot(string $entity, int $documentId, array $record, string $note = ''): void
    {
        if (!Database::tableExists('document_revisions')) {
            return;
        }
        $revTable = Database::prefix('document_revisions');

        $lastRev = Database::selectOne(
            "SELECT MAX(revision_num) as max_rev FROM `$revTable` WHERE entity = ? AND document_id = ?",
            [$entity, $documentId]
        );
        $nextRevNum = ((int) ($lastRev['max_rev'] ?? 0)) + 1;

        $user = Auth::user();
        $userName = $user ? ($user['display_name'] ?? $user['username'] ?? 'Author') : 'Author';
        $userId = Auth::id();

        Database::insert('document_revisions', [
            'entity' => $entity,
            'document_id' => $documentId,
            'revision_num' => $nextRevNum,
            'title' => (string) ($record['title'] ?? ''),
            'slug' => (string) ($record['slug'] ?? ''),
            'status' => (string) ($record['status'] ?? 'draft'),
            'body_json' => $record['body_json'] ?? null,
            'content' => $record['content'] ?? null,
            'user_id' => $userId,
            'user_name' => $userName,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function getRevisions(string $entity, int $documentId): array
    {
        EditorSchema::ensure();
        $table = Database::prefix('document_revisions');
        return Database::select(
            "SELECT id, revision_num, title, slug, status, user_name, created_at,
                    LENGTH(COALESCE(body_json, content, '')) as content_bytes
             FROM `$table`
             WHERE entity = ? AND document_id = ?
             ORDER BY revision_num DESC
             LIMIT 50",
            [$entity, $documentId]
        );
    }

    public static function getRevision(int $revisionId): ?array
    {
        EditorSchema::ensure();
        $table = Database::prefix('document_revisions');
        return Database::selectOne("SELECT * FROM `$table` WHERE id = ? LIMIT 1", [$revisionId]);
    }

    public static function restoreRevision(string $entity, int $documentId, int $revisionId): array
    {
        EditorSchema::ensure();
        self::assertEntityAllowed($entity);
        $revision = self::getRevision($revisionId);
        if (!$revision || (string) $revision['entity'] !== $entity || (int) $revision['document_id'] !== $documentId) {
            throw new DocumentException('Revision not found.');
        }

        $current = self::find($entity, $documentId);
        if ($current) {
            self::createRevisionSnapshot($entity, $documentId, $current, 'Snapshot prior to restore of revision #' . $revision['revision_num']);
        }

        $table = self::tableFor($entity);
        $update = [
            'title' => $revision['title'],
            'status' => $revision['status'],
        ];

        if (!empty($revision['body_json'])) {
            $doc = Document::parse($revision['body_json']);
            $update['body_json'] = Document::encode($doc);
            $update['content'] = DocumentRenderer::render($doc, false);
            $update['editor_format'] = EditorSchema::FORMAT_STRUCTURED;
        } else {
            $update['content'] = (string) ($revision['content'] ?? '');
            $update['editor_format'] = EditorSchema::FORMAT_LEGACY;
        }

        Database::update($table, $update, 'id = ?', [$documentId]);

        $reloaded = self::find($entity, $documentId);
        if (!$reloaded) {
            throw new DocumentException('Failed to reload document after revision restore.');
        }

        return ['id' => $documentId, 'record' => $reloaded, 'restored_revision' => $revision['revision_num']];
    }

    // --- Reusable Blocks Subsystem (1.1.0) ---

    public static function listReusable(): array
    {
        EditorSchema::ensure();
        $table = Database::prefix('kc_reusable_blocks');
        return Database::select("SELECT id, title, category, created_at, updated_at FROM `$table` ORDER BY title ASC");
    }

    public static function getReusable(int $id): ?array
    {
        EditorSchema::ensure();
        $table = Database::prefix('kc_reusable_blocks');
        return Database::selectOne("SELECT * FROM `$table` WHERE id = ? LIMIT 1", [$id]);
    }

    public static function saveReusable(?int $id, string $title, array $content): array
    {
        EditorSchema::ensure();
        $title = trim($title) ?: 'Untitled Reusable Block';
        $doc = Document::normalize($content);
        $encoded = Document::encode($doc);

        $table = Database::prefix('kc_reusable_blocks');
        if ($id && $id > 0) {
            Database::update('kc_reusable_blocks', ['title' => $title, 'content_json' => $encoded], 'id = ?', [$id]);
        } else {
            $id = Database::insert('kc_reusable_blocks', ['title' => $title, 'category' => 'general', 'content_json' => $encoded]);
        }

        return ['id' => $id, 'title' => $title, 'content' => $doc];
    }

    public static function deleteReusable(int $id): bool
    {
        EditorSchema::ensure();
        Database::query("DELETE FROM `" . Database::prefix('kc_reusable_blocks') . "` WHERE id = ?", [$id]);
        return true;
    }

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
