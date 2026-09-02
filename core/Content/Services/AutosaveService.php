<?php
declare(strict_types=1);

namespace SOI\Core\Content\Services;

use SOI\Core\Content\ContentService;

/**
 * Server-side Autosave Service layer.
 * Delegates draft save operations to the canonical ContentService.
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
        $payload['id'] = $documentId;
        $res = ContentService::saveDocument($payload, true);
        return [
            'success' => (bool) ($res['ok'] ?? false),
            'updatedAt' => (string) ($res['updated_at'] ?? date('c')),
        ];
    }
}
