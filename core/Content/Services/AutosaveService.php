<?php
declare(strict_types=1);

namespace SOI\Core\Content\Services;

use SOI\Core\Content\Document;

/**
 * Task T6: Server-side Autosave Service (Skeleton).
 */
class AutosaveService
{
    /**
     * Persist autosave draft payload for a page/post.
     *
     * @param int $documentId
     * @param array<string, mixed> $payload
     * @param int $userId
     * @return array{success:bool,updatedAt:string,message?:string}
     */
    public function saveDraft(int $documentId, array $payload, int $userId): array
    {
        $normalized = Document::normalize($payload);
        $encoded = Document::encode($normalized);

        // Developer 6: Save into drafts/revisions storage and return timestamp
        $now = date('c');
        return [
            'success' => true,
            'updatedAt' => $now,
        ];
    }
}
