<?php
declare(strict_types=1);

namespace SOI\Core\Services\Autosave;

use SOI\Core\Content\Services\AutosaveService;

/**
 * Task A4-T05: Manual Save & Shortcut Handler.
 *
 * Coordinates an explicit manual save with the existing autosave service.
 */
final class ManualSaveHandler
{
    private AutosaveService $autosaveService;

    public function __construct(?AutosaveService $autosaveService = null)
    {
        $this->autosaveService = $autosaveService ?? new AutosaveService();
    }

    /**
     * Perform a manual save.
     *
     * The returned state tells the client that the save succeeded
     * and that its local recovery draft can be cleared.
     *
     * @param int $documentId
     * @param array<string, mixed> $payload
     * @param int $userId
     * @return array{
     *     success: bool,
     *     updatedAt: string,
     *     state: string,
     *     clearRecovery: bool,
     *     message?: string
     * }
     */
    public function save(
        int $documentId,
        array $payload,
        int $userId
    ): array {
        $result = $this->autosaveService->saveDraft(
            $documentId,
            $payload,
            $userId
        );

        if (!$result['success']) {
            return [
                'success' => false,
                'updatedAt' => $result['updatedAt'],
                'state' => 'error',
                'clearRecovery' => false,
                'message' => $result['message'] ?? 'Manual save failed.',
            ];
        }

        return [
            'success' => true,
            'updatedAt' => $result['updatedAt'],
            'state' => 'saved',
            'clearRecovery' => true,
        ];
    }
}