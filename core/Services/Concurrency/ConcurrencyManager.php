<?php
declare(strict_types=1);

namespace SOI\Core\Services\Concurrency;

use SOI\Core\Content\Html;

/**
 * Task A4-T03: Optimistic Concurrency Control Service.
 * Prevents edit collision by comparing expected_updated_at against database updated_at.
 * Stale save requests display #kc-conflict-modal dialog.
 *
 * Acceptance Criteria: Mismatched expected_updated_at returns { ok: false, conflict: true } and opens conflict resolution modal.
 */
class ConcurrencyManager
{
    /**
     * Validate payload expected_updated_at against database updated_at.
     *
     * @param array<string, mixed> $payload
     * @param string $currentDbUpdatedAt
     * @param string $remoteAuthor
     * @return array<string, mixed>
     */
    public function validateSaveConcurrency(array $payload, string $currentDbUpdatedAt, string $remoteAuthor = 'Colleague'): array
    {
        $expectedUpdatedAt = Html::plainText((string) ($payload['expected_updated_at'] ?? ''));
        $forceOverwrite = !empty($payload['force_overwrite']);
        $newTimestamp = date('c');

        // Force overwrite override bypasses conflict check
        if ($forceOverwrite) {
            return [
                'ok' => true,
                'conflict' => false,
                'overwritten' => true,
                'updated_at' => $newTimestamp,
            ];
        }

        if ($expectedUpdatedAt === '') {
            return [
                'ok' => false,
                'conflict' => true,
                'error' => 'Missing expected_updated_at timestamp.',
                'expected_updated_at' => null,
                'remote_updated_at' => $currentDbUpdatedAt,
                'remote_author' => Html::plainText($remoteAuthor),
                'modal_id' => '#kc-conflict-modal',
            ];
        }

        $expectedTime = strtotime($expectedUpdatedAt);
        $dbTime = strtotime($currentDbUpdatedAt);

        // Mismatched or stale timestamp detected
        if ($expectedTime !== false && $dbTime !== false && $expectedTime < $dbTime) {
            return [
                'ok' => false,
                'conflict' => true,
                'error' => 'Edit collision detected. The document has been modified by another user.',
                'expected_updated_at' => $expectedUpdatedAt,
                'remote_updated_at' => $currentDbUpdatedAt,
                'remote_author' => Html::plainText($remoteAuthor),
                'modal_id' => '#kc-conflict-modal',
            ];
        }

        return [
            'ok' => true,
            'conflict' => false,
            'updated_at' => $newTimestamp,
        ];
    }
}
