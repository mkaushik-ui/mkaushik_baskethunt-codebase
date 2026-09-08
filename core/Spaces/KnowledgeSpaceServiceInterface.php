<?php
declare(strict_types=1);

namespace SOI\Core\Spaces;

/**
 * KnowledgeSpaceServiceInterface
 *
 * Formal contract for Knowledge Space domain entity operations across the SOI Knowledge Center.
 */
interface KnowledgeSpaceServiceInterface
{
    /**
     * Create a new space record.
     * Validates required title, unique slug, and enum type (generaldocs, libraries, tech).
     *
     * @param array<string, mixed> $data
     * @return int The inserted space ID.
     * @throws \InvalidArgumentException If validation fails or duplicate slug detected.
     */
    public function createSpace(array $data): int;

    /**
     * Fetch a space record by primary key ID with caching.
     *
     * @param int $id
     * @return array<string, mixed>|null Space record associative array or null if not found.
     */
    public function getSpace(int $id): ?array;

    /**
     * Fetch a space record by slug with caching.
     *
     * @param string $slug
     * @return array<string, mixed>|null Space record associative array or null if not found.
     */
    public function getSpaceBySlug(string $slug): ?array;

    /**
     * Query spaces with optional filtering (type, status, visibility),
     * ordered by sortorder ASC, title ASC.
     *
     * @param array<string, mixed> $filter Optional filters (type, status, visibility, q, limit, offset).
     * @return array<int, array<string, mixed>> List of matching space records.
     */
    public function listSpaces(array $filter = []): array;

    /**
     * Count matching spaces with optional filtering.
     *
     * @param array<string, mixed> $filter
     * @return int Total number of matching spaces.
     */
    public function getSpaceCount(array $filter = []): int;

    /**
     * Update an existing space record by ID and purge related cache keys.
     *
     * @param int $id
     * @param array<string, mixed> $data
     * @return bool True if record was updated, false if not found.
     * @throws \InvalidArgumentException If validation fails or duplicate slug detected.
     */
    public function updateSpace(int $id, array $data): bool;

    /**
     * Delete a space record by ID and purge related cache keys.
     *
     * @param int $id
     * @return bool True if deleted, false if not found.
     */
    public function deleteSpace(int $id): bool;

    /**
     * Validate whether a space slug is valid, non-reserved, and uniquely available.
     *
     * @param string $slug
     * @param int|null $excludeId
     * @return bool True if slug is valid and available.
     */
    public function validateSlug(string $slug, ?int $excludeId = null): bool;

    /**
     * Invalidate and purge cached entries for space entity/lists.
     *
     * @param int|null $id
     * @param string|null $slug
     * @return void
     */
    public function purgeSpaceCache(?int $id = null, ?string $slug = null): void;
}
