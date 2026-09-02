<?php
declare(strict_types=1);

namespace SOI\Core\Content\Services;

use SOI\Core\Services\Concurrency\ConcurrencyManager;

/**
 * Optimistic Concurrency Control Service layer.
 * Delegates timestamp conflict checks to ConcurrencyManager.
 */
class ConcurrencyService
{
    private ConcurrencyManager $manager;

    public function __construct(?ConcurrencyManager $manager = null)
    {
        $this->manager = $manager ?? new ConcurrencyManager();
    }

    /**
     * Check if client save has a stale timestamp conflict.
     *
     * @param int $documentId
     * @param string $clientExpectedTimestamp
     * @param string $serverCurrentTimestamp
     * @return bool True if conflict exists (HTTP 409)
     */
    public function hasConflict(int $documentId, string $clientExpectedTimestamp, string $serverCurrentTimestamp): bool
    {
        if ($clientExpectedTimestamp === '') {
            return false;
        }
        $res = $this->manager->validateSaveConcurrency([
            'expected_updated_at' => $clientExpectedTimestamp,
        ], $serverCurrentTimestamp);
        return !empty($res['conflict']);
    }
}
