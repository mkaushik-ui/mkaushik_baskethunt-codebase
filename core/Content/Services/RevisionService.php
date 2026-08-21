<?php
declare(strict_types=1);

namespace SOI\Core\Content\Services;

use SOI\Core\Content\Document;

/**
 * Task T6: Revision History & Pre-Restore Snapshot Service (Skeleton).
 */
class RevisionService
{
    /**
     * Create durable revision snapshot.
     *
     * @param int $documentId
     * @param array<string, mixed> $documentData
     * @param int $userId
     * @param string $note
     * @return int New revision ID
     */
    public function createSnapshot(int $documentId, array $documentData, int $userId, string $note = ''): int
    {
        // Developer 6: Save snapshot row in revisions table
        return 1;
    }

    /**
     * Restore previous revision with pre-restore safety snapshot.
     *
     * @param int $documentId
     * @param int $targetRevisionId
     * @param int $userId
     * @return array<string, mixed> Restored document structure
     */
    public function restoreRevisionWithSafetySnapshot(int $documentId, int $targetRevisionId, int $userId): array
    {
        // 1. Create safety snapshot of current document state BEFORE restore
        // 2. Fetch and apply targetRevisionId
        return Document::empty();
    }
}
