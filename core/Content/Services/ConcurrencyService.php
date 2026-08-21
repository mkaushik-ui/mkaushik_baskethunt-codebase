<?php
declare(strict_types=1);

namespace SOI\Core\Content\Services;

/**
 * Task T6: Optimistic Concurrency Control Service (Skeleton).
 */
class ConcurrencyService
{
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
        return strtotime($serverCurrentTimestamp) > strtotime($clientExpectedTimestamp);
    }
}
