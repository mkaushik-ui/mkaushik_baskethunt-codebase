<?php
declare(strict_types=1);

namespace SOI\Core\Spaces;

/**
 * Contract for Legacy Document Compatibility and Initial Space Seeding (KS-05).
 */
interface SpaceDocumentServiceInterface
{
    /**
     * Seed canonical initial standard spaces if table is empty or missing them.
     *
     * @param bool $force If true, forces insertion of any missing canonical spaces even if table is not empty.
     * @return array<int, array<string, mixed>> List of seeded or existing canonical spaces.
     */
    public function seedInitialSpaces(bool $force = false): array;

    /**
     * Migrate unassigned legacy pages and posts to canonical knowledge spaces.
     *
     * Unassigned pages (space_id IS NULL OR space_id = 0) are mapped to 'general-docs'.
     * Unassigned posts are mapped to their respective category libraries or fallback to 'general-docs'.
     * Slugs, titles, and body content are preserved strictly intact and non-destructively.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed> Detailed migration report.
     */
    public function migrateLegacyDocuments(array $options = []): array;

    /**
     * Get counts of unassigned pages and posts.
     *
     * @return array{pages: int, posts: int, total: int}
     */
    public function getUnassignedCounts(): array;

    /**
     * Assign a document (page or post) to a space and section.
     *
     * @param string $documentType 'page' or 'post'
     * @param int $documentId
     * @param int $spaceId
     * @param int $sectionId
     * @return bool
     */
    public function assignDocument(string $documentType, int $documentId, int $spaceId, int $sectionId = 0): bool;

    /**
     * Unassign a document from its space.
     *
     * @param string $documentType 'page' or 'post'
     * @param int $documentId
     * @return bool
     */
    public function unassignDocument(string $documentType, int $documentId): bool;

    /**
     * Retrieve the space assignment and context for a document.
     *
     * @param string $documentType 'page' or 'post'
     * @param int $documentId
     * @return array<string, mixed>|null
     */
    public function getDocumentSpace(string $documentType, int $documentId): ?array;
}
