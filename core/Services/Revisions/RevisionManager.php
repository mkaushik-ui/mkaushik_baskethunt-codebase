<?php
declare(strict_types=1);

namespace SOI\Core\Services\Revisions;

use SOI\Core\Auth;
use SOI\Core\Content\ContentStore;
use SOI\Core\Content\Document;
use SOI\Core\Content\DocumentException;
use SOI\Core\Content\DocumentRenderer;
use SOI\Core\Content\EditorSchema;
use SOI\Core\Database;

final class RevisionManager
{
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
        ContentStore::assertEntityAllowed($entity);
        $revision = self::getRevision($revisionId);
        if (!$revision || (string) $revision['entity'] !== $entity || (int) $revision['document_id'] !== $documentId) {
            throw new DocumentException('Revision not found.');
        }

        $current = ContentStore::find($entity, $documentId);
        if ($current) {
            self::createRevisionSnapshot($entity, $documentId, $current, 'Snapshot prior to restore of revision #' . $revision['revision_num']);
        }

        $table = ContentStore::tableFor($entity);
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

        $reloaded = ContentStore::find($entity, $documentId);
        if (!$reloaded) {
            throw new DocumentException('Could not reload document after restore.');
        }
        return ['id' => $documentId, 'record' => $reloaded];
    }
}
