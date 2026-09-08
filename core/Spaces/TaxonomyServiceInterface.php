<?php
declare(strict_types=1);

namespace SOI\Core\Spaces;

/**
 * Interface TaxonomyServiceInterface
 *
 * Defines contracts for section hierarchy, navigation tree assembly, and reordering.
 */
interface TaxonomyServiceInterface
{
    public function getSection(int $id): ?array;
    public function getSectionsBySpace(int $spaceId): array;
    public function createSection(array $data): int;
    public function updateSection(int $id, array $data): bool;
    public function deleteSection(int $id): bool;
    public function reorderSections(array $orders): bool;
    public function buildNavigationTree(int $spaceId, ?int $activeDocId = null, string $activeDocType = 'page'): array;
    public function purgeSpaceCache(int $spaceId): void;
}
