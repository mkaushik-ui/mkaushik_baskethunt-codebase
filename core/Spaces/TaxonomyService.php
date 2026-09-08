<?php
declare(strict_types=1);

namespace SOI\Core\Spaces;

use SOI\Core\Cache;
use SOI\Core\Database;

/**
 * TaxonomyService
 *
 * Implements section hierarchy builder, recursive navigation tree assembly,
 * drag-and-drop batch reordering, document assignment, and active indicator bubbling.
 */
class TaxonomyService implements TaxonomyServiceInterface
{
    public const CACHE_TREE_PREFIX = 'space:tree:';
    public const CACHE_SECTIONS_PREFIX = 'space:sections:';
    public const CACHE_TTL = 3600;

    private static ?self $instance = null;
    private ?\PDO $pdo = null;

    /**
     * @param \PDO|null $pdo Optional PDO connection for dependency injection / testing.
     */
    public function __construct(?\PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    /**
     * Singleton instance provider.
     */
    public static function instance(?\PDO $pdo = null): self
    {
        if (self::$instance === null || $pdo !== null) {
            self::$instance = new self($pdo);
        }
        return self::$instance;
    }

    /**
     * Get active PDO connection.
     */
    private function getPdo(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        return Database::pdo();
    }

    /**
     * Normalize a slug string.
     */
    public static function normalizeSlug(string $source): string
    {
        $slug = strtolower(trim($source));
        $slug = preg_replace('/[^a-z0-9\-]+/', '-', $slug);
        return trim((string) $slug, '-');
    }

    /**
     * Generate slug from title.
     */
    public static function slugify(string $text): string
    {
        if (function_exists('slugify')) {
            $slug = slugify($text);
            if (is_string($slug) && $slug !== '') {
                return self::normalizeSlug($slug);
            }
        }
        return self::normalizeSlug($text);
    }

    /**
     * Ensure a slug is unique within a space and parent section.
     */
    private function ensureUniqueSlug(int $spaceId, int $parentId, string $baseSlug, ?int $ignoreId = null): string
    {
        $slug = $baseSlug !== '' ? $baseSlug : 'section';
        $candidate = $slug;
        $counter = 1;

        $pdo = $this->getPdo();
        $secTable = SpaceSchema::TABLE_SECTIONS;

        while (true) {
            $sql = "SELECT id FROM {$secTable} WHERE space_id = ? AND parent_id = ? AND slug = ?";
            $params = [$spaceId, $parentId, $candidate];
            if ($ignoreId !== null && $ignoreId > 0) {
                $sql .= " AND id != ?";
                $params[] = $ignoreId;
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            if (!$stmt->fetch()) {
                return $candidate;
            }

            $counter++;
            $candidate = "{$slug}-{$counter}";
        }
    }

    /**
     * Check if candidateId is a descendant of parentId (prevents circular loops).
     */
    public function isDescendantOf(int $candidateChildId, int $parentId, int $spaceId): bool
    {
        if ($candidateChildId <= 0 || $parentId <= 0) {
            return false;
        }
        if ($candidateChildId === $parentId) {
            return true;
        }

        $currentId = $candidateChildId;
        $visited = [];

        while ($currentId > 0) {
            if ($currentId === $parentId) {
                return true;
            }
            if (isset($visited[$currentId])) {
                break;
            }
            $visited[$currentId] = true;

            $sec = $this->getSection($currentId);
            if (!$sec || (int) $sec['space_id'] !== $spaceId) {
                break;
            }
            $currentId = (int) ($sec['parent_id'] ?? 0);
        }

        return false;
    }

    /**
     * Retrieve all descendant section IDs recursively.
     *
     * @return array<int>
     */
    public function getDescendantSectionIds(int $sectionId, int $spaceId): array
    {
        $descendants = [];
        $pdo = $this->getPdo();
        $secTable = SpaceSchema::TABLE_SECTIONS;

        $stmt = $pdo->prepare("SELECT id FROM {$secTable} WHERE space_id = ? AND parent_id = ?");
        $stmt->execute([$spaceId, $sectionId]);
        $children = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($children as $childId) {
            $childId = (int) $childId;
            $descendants[] = $childId;
            $sub = $this->getDescendantSectionIds($childId, $spaceId);
            foreach ($sub as $subId) {
                $descendants[] = $subId;
            }
        }

        return $descendants;
    }

    /**
     * Invalidate caches associated with a space.
     */
    public function purgeSpaceCache(int $spaceId): void
    {
        Cache::delete(self::CACHE_TREE_PREFIX . $spaceId);
        Cache::delete(self::CACHE_SECTIONS_PREFIX . $spaceId);
    }

    /**
     * Create a section under a space.
     *
     * @param array{
     *   space_id: int,
     *   parent_id?: int,
     *   title: string,
     *   slug?: string,
     *   sort_order?: int,
     *   description?: string|null
     * } $data
     * @return int Created section ID
     * @throws \InvalidArgumentException On validation failure
     */
    public function createSection(array $data): int
    {
        SpaceSchema::ensure($this->pdo);

        // 1. Validate space ID
        $spaceId = (int) ($data['space_id'] ?? 0);
        if ($spaceId <= 0) {
            throw new \InvalidArgumentException('Valid space_id is required.');
        }

        $spaceService = KnowledgeSpaceService::instance($this->pdo);
        if ($spaceService->getSpace($spaceId) === null) {
            throw new \InvalidArgumentException("Knowledge space with ID {$spaceId} does not exist.");
        }

        // 2. Validate section title
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('Section title is required.');
        }

        // 3. Validate parent_id
        $parentId = (int) ($data['parent_id'] ?? 0);
        if ($parentId < 0) {
            $parentId = 0;
        }

        if ($parentId > 0) {
            $parentSec = $this->getSection($parentId);
            if ($parentSec === null) {
                throw new \InvalidArgumentException("Parent section with ID {$parentId} does not exist.");
            }
            if ((int) $parentSec['space_id'] !== $spaceId) {
                throw new \InvalidArgumentException("Parent section {$parentId} does not belong to space {$spaceId}.");
            }
        }

        // 4. Generate and validate slug
        $rawSlug = trim((string) ($data['slug'] ?? ''));
        $baseSlug = $rawSlug !== '' ? self::normalizeSlug($rawSlug) : self::slugify($title);
        $slug = $this->ensureUniqueSlug($spaceId, $parentId, $baseSlug);

        // 5. Determine sort order
        $sortOrder = isset($data['sort_order']) ? (int) $data['sort_order'] : null;
        if ($sortOrder === null) {
            $pdo = $this->getPdo();
            $secTable = SpaceSchema::TABLE_SECTIONS;
            $stmt = $pdo->prepare("SELECT MAX(sort_order) FROM {$secTable} WHERE space_id = ? AND parent_id = ?");
            $stmt->execute([$spaceId, $parentId]);
            $maxOrder = $stmt->fetchColumn();
            $sortOrder = ($maxOrder !== false && $maxOrder !== null) ? ((int) $maxOrder + 10) : 10;
        }

        $description = isset($data['description']) ? trim((string) $data['description']) : null;
        $now = date('Y-m-d H:i:s');

        $record = [
            'space_id'    => $spaceId,
            'parent_id'   => $parentId,
            'title'       => $title,
            'slug'        => $slug,
            'sort_order'  => $sortOrder,
            'description' => $description,
            'created_at'  => $now,
            'updated_at'  => $now,
        ];

        $pdo = $this->getPdo();
        $secTable = SpaceSchema::TABLE_SECTIONS;
        $cols = implode(', ', array_keys($record));
        $placeholders = implode(', ', array_fill(0, count($record), '?'));
        $sql = "INSERT INTO {$secTable} ({$cols}) VALUES ({$placeholders})";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($record));
        $sectionId = (int) $pdo->lastInsertId();

        // 6. Invalidate caches
        $this->purgeSpaceCache($spaceId);

        return $sectionId;
    }

    /**
     * Update an existing section.
     *
     * @param int $id
     * @param array<string, mixed> $data
     * @return bool
     * @throws \InvalidArgumentException On validation failure or circular reference
     */
    public function updateSection(int $id, array $data): bool
    {
        SpaceSchema::ensure($this->pdo);

        $existing = $this->getSection($id);
        if ($existing === null) {
            throw new \InvalidArgumentException("Section with ID {$id} does not exist.");
        }

        $spaceId = (int) $existing['space_id'];
        $updates = [];
        $params = [];

        // Validate title if given
        if (array_key_exists('title', $data)) {
            $title = trim((string) $data['title']);
            if ($title === '') {
                throw new \InvalidArgumentException('Section title cannot be empty.');
            }
            $updates['title'] = $title;
        }

        // Validate parent_id if given
        if (array_key_exists('parent_id', $data)) {
            $newParentId = (int) $data['parent_id'];
            if ($newParentId < 0) {
                $newParentId = 0;
            }

            if ($newParentId === $id) {
                throw new \InvalidArgumentException('A section cannot be its own parent.');
            }

            if ($newParentId > 0) {
                $parentSec = $this->getSection($newParentId);
                if ($parentSec === null) {
                    throw new \InvalidArgumentException("Parent section {$newParentId} does not exist.");
                }
                if ((int) $parentSec['space_id'] !== $spaceId) {
                    throw new \InvalidArgumentException("Parent section {$newParentId} belongs to a different space.");
                }
                if ($this->isDescendantOf($newParentId, $id, $spaceId)) {
                    throw new \InvalidArgumentException('Cannot set parent to a descendant section (circular hierarchy detected).');
                }
            }
            $updates['parent_id'] = $newParentId;
        }

        $currentParentId = isset($updates['parent_id']) ? (int) $updates['parent_id'] : (int) $existing['parent_id'];

        // Validate slug if given
        if (array_key_exists('slug', $data)) {
            $rawSlug = trim((string) $data['slug']);
            $baseSlug = $rawSlug !== '' ? self::normalizeSlug($rawSlug) : self::slugify($updates['title'] ?? $existing['title']);
            $updates['slug'] = $this->ensureUniqueSlug($spaceId, $currentParentId, $baseSlug, $id);
        }

        // Validate sort_order
        if (array_key_exists('sort_order', $data)) {
            $updates['sort_order'] = (int) $data['sort_order'];
        }

        // Description
        if (array_key_exists('description', $data)) {
            $updates['description'] = $data['description'] !== null ? trim((string) $data['description']) : null;
        }

        if (empty($updates)) {
            return true;
        }

        $updates['updated_at'] = date('Y-m-d H:i:s');

        $setClauses = [];
        foreach ($updates as $col => $val) {
            $setClauses[] = "{$col} = ?";
            $params[] = $val;
        }
        $params[] = $id;

        $pdo = $this->getPdo();
        $secTable = SpaceSchema::TABLE_SECTIONS;
        $sql = "UPDATE {$secTable} SET " . implode(', ', $setClauses) . " WHERE id = ?";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $this->purgeSpaceCache($spaceId);

        return true;
    }

    /**
     * Delete a section and all its descendants, cleaning up document associations.
     *
     * @param int $id
     * @return bool
     */
    public function deleteSection(int $id): bool
    {
        SpaceSchema::ensure($this->pdo);

        $section = $this->getSection($id);
        if ($section === null) {
            return false;
        }

        $spaceId = (int) $section['space_id'];
        $descendants = $this->getDescendantSectionIds($id, $spaceId);
        $allIds = array_merge([$id], $descendants);

        $pdo = $this->getPdo();
        $secTable = SpaceSchema::TABLE_SECTIONS;
        $docTable = SpaceSchema::TABLE_DOCUMENTS;

        $inPlaceholders = implode(', ', array_fill(0, count($allIds), '?'));

        // 1. Remove documents assigned to these sections
        $delDocStmt = $pdo->prepare("DELETE FROM {$docTable} WHERE space_id = ? AND section_id IN ({$inPlaceholders})");
        $delDocStmt->execute(array_merge([$spaceId], $allIds));

        // 2. Delete sections
        $delSecStmt = $pdo->prepare("DELETE FROM {$secTable} WHERE id IN ({$inPlaceholders})");
        $delSecStmt->execute($allIds);

        $this->purgeSpaceCache($spaceId);

        return true;
    }

    /**
     * Get a section by its primary key ID.
     *
     * @param int $id
     * @return array<string, mixed>|null
     */
    public function getSection(int $id): ?array
    {
        SpaceSchema::ensure($this->pdo);

        $pdo = $this->getPdo();
        $secTable = SpaceSchema::TABLE_SECTIONS;
        $stmt = $pdo->prepare("SELECT * FROM {$secTable} WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Get all sections for a given space ID, ordered by parent_id ASC, sort_order ASC, title ASC.
     *
     * @param int $spaceId
     * @return array<int, array<string, mixed>>
     */
    public function getSectionsBySpace(int $spaceId): array
    {
        SpaceSchema::ensure($this->pdo);

        $cacheKey = self::CACHE_SECTIONS_PREFIX . $spaceId;
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $pdo = $this->getPdo();
        $secTable = SpaceSchema::TABLE_SECTIONS;
        $stmt = $pdo->prepare("SELECT * FROM {$secTable} WHERE space_id = ? ORDER BY parent_id ASC, sort_order ASC, title ASC");
        $stmt->execute([$spaceId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $results = is_array($rows) ? $rows : [];
        Cache::set($cacheKey, $results, self::CACHE_TTL);

        return $results;
    }

    /**
     * Batch reorder sections.
     *
     * @param array<int, array{id: int, sort_order: int, parent_id?: int}> $orders
     * @return bool
     */
    public function reorderSections(array $orders): bool
    {
        SpaceSchema::ensure($this->pdo);

        if (empty($orders)) {
            return true;
        }

        $pdo = $this->getPdo();
        $secTable = SpaceSchema::TABLE_SECTIONS;
        $affectedSpaces = [];

        $inTransaction = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $inTransaction = true;
            }

            foreach ($orders as $item) {
                $id = (int) ($item['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }

                $sec = $this->getSection($id);
                if ($sec === null) {
                    continue;
                }

                $spaceId = (int) $sec['space_id'];
                $affectedSpaces[$spaceId] = true;

                $sortOrder = (int) ($item['sort_order'] ?? 0);
                $hasParent = array_key_exists('parent_id', $item);
                $parentId = $hasParent ? (int) $item['parent_id'] : null;

                if ($hasParent && $parentId !== null) {
                    if ($parentId < 0) {
                        $parentId = 0;
                    }
                    if ($parentId === $id) {
                        throw new \InvalidArgumentException("Cannot set section {$id} as its own parent.");
                    }
                    if ($parentId > 0 && $this->isDescendantOf($parentId, $id, $spaceId)) {
                        throw new \InvalidArgumentException("Circular hierarchy detected for section {$id}.");
                    }

                    $stmt = $pdo->prepare("UPDATE {$secTable} SET sort_order = ?, parent_id = ?, updated_at = ? WHERE id = ?");
                    $stmt->execute([$sortOrder, $parentId, date('Y-m-d H:i:s'), $id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE {$secTable} SET sort_order = ?, updated_at = ? WHERE id = ?");
                    $stmt->execute([$sortOrder, date('Y-m-d H:i:s'), $id]);
                }
            }

            if ($inTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($inTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        foreach (array_keys($affectedSpaces) as $sid) {
            $this->purgeSpaceCache((int) $sid);
        }

        return true;
    }

    /**
     * Assign a document (page) to a space and section.
     *
     * @param int $spaceId
     * @param int $sectionId (0 for space root)
     * @param int $documentId
     * @param string $documentType
     * @param int $sortOrder
     * @return bool
     */
    public function assignDocument(int $spaceId, int $sectionId, int $documentId, string $documentType = 'page', int $sortOrder = 0): bool
    {
        SpaceSchema::ensure($this->pdo);

        if ($spaceId <= 0 || $documentId <= 0) {
            throw new \InvalidArgumentException('Valid space_id and document_id are required.');
        }

        if ($sectionId > 0) {
            $sec = $this->getSection($sectionId);
            if ($sec === null || (int) $sec['space_id'] !== $spaceId) {
                throw new \InvalidArgumentException("Section {$sectionId} does not belong to space {$spaceId}.");
            }
        } else {
            $sectionId = 0;
        }

        $documentType = trim($documentType) !== '' ? trim($documentType) : 'page';

        $pdo = $this->getPdo();
        $docTable = SpaceSchema::TABLE_DOCUMENTS;

        // Clean up any other space assignment for this document if moving spaces
        $otherStmt = $pdo->prepare("SELECT DISTINCT space_id FROM {$docTable} WHERE document_id = ? AND document_type = ? AND space_id != ?");
        $otherStmt->execute([$documentId, $documentType, $spaceId]);
        $otherSpaces = $otherStmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        if (!empty($otherSpaces)) {
            $delOther = $pdo->prepare("DELETE FROM {$docTable} WHERE document_id = ? AND document_type = ? AND space_id != ?");
            $delOther->execute([$documentId, $documentType, $spaceId]);
            foreach ($otherSpaces as $otherSid) {
                $this->purgeSpaceCache((int) $otherSid);
            }
        }

        // Check if already assigned to this space
        $checkStmt = $pdo->prepare("SELECT id FROM {$docTable} WHERE space_id = ? AND document_id = ? AND document_type = ?");
        $checkStmt->execute([$spaceId, $documentId, $documentType]);
        $existing = $checkStmt->fetch(\PDO::FETCH_ASSOC);

        if ($existing) {
            $stmt = $pdo->prepare("UPDATE {$docTable} SET section_id = ?, sort_order = ? WHERE id = ?");
            $stmt->execute([$sectionId, $sortOrder, (int) $existing['id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO {$docTable} (space_id, section_id, document_id, document_type, sort_order, created_at) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$spaceId, $sectionId, $documentId, $documentType, $sortOrder, date('Y-m-d H:i:s')]);
        }

        $this->purgeSpaceCache($spaceId);

        return true;
    }

    /**
     * Remove a document assignment from a space.
     *
     * @param int $spaceId (0 to remove from all spaces)
     * @param int $documentId
     * @param string $documentType
     * @return bool
     */
    public function removeDocument(int $spaceId, int $documentId, string $documentType = 'page'): bool
    {
        SpaceSchema::ensure($this->pdo);

        $pdo = $this->getPdo();
        $docTable = SpaceSchema::TABLE_DOCUMENTS;
        $documentType = trim($documentType) !== '' ? trim($documentType) : 'page';

        if ($spaceId > 0) {
            $stmt = $pdo->prepare("DELETE FROM {$docTable} WHERE space_id = ? AND document_id = ? AND document_type = ?");
            $stmt->execute([$spaceId, $documentId, $documentType]);
            $this->purgeSpaceCache($spaceId);
        } else {
            $findStmt = $pdo->prepare("SELECT DISTINCT space_id FROM {$docTable} WHERE document_id = ? AND document_type = ?");
            $findStmt->execute([$documentId, $documentType]);
            $spaceIds = $findStmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];

            $stmt = $pdo->prepare("DELETE FROM {$docTable} WHERE document_id = ? AND document_type = ?");
            $stmt->execute([$documentId, $documentType]);

            foreach ($spaceIds as $sid) {
                $this->purgeSpaceCache((int) $sid);
            }
        }

        return true;
    }

    /**
     * Build the hierarchical navigation tree for a space.
     *
     * Structure: Space -> Section -> Sub-section -> Document.
     *
     * @param int $spaceId
     * @param int|null $activeDocumentId
     * @param string|null $activeSlug
     * @return array<string, mixed>
     */
    public function buildNavigationTree(int $spaceId, ?int $activeDocumentId = null, ?string $activeSlug = null): array
    {
        SpaceSchema::ensure($this->pdo);

        $spaceService = KnowledgeSpaceService::instance($this->pdo);
        $space = $spaceService->getSpace($spaceId);
        if ($space === null) {
            return [];
        }

        $sections = $this->getSectionsBySpace($spaceId);

        // Fetch assigned documents for this space
        $pdo = $this->getPdo();
        $docTable = SpaceSchema::TABLE_DOCUMENTS;

        // Check if soi_pages table exists to enrich with page title/slug/status
        $hasPagesTable = true;
        try {
            $pdo->query("SELECT 1 FROM soi_pages LIMIT 1");
        } catch (\Throwable $e) {
            $hasPagesTable = false;
        }

        if ($hasPagesTable) {
            $sql = "SELECT d.id AS assignment_id, d.space_id, d.section_id, d.document_id, d.document_type, d.sort_order,
                           p.title AS page_title, p.slug AS page_slug, p.status AS page_status
                    FROM {$docTable} d
                    LEFT JOIN soi_pages p ON p.id = d.document_id
                    WHERE d.space_id = ?
                    ORDER BY d.sort_order ASC, d.id ASC";
        } else {
            $sql = "SELECT d.id AS assignment_id, d.space_id, d.section_id, d.document_id, d.document_type, d.sort_order,
                           NULL AS page_title, NULL AS page_slug, 'published' AS page_status
                    FROM {$docTable} d
                    WHERE d.space_id = ?
                    ORDER BY d.sort_order ASC, d.id ASC";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$spaceId]);
        $docRows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        // Format and index documents by section_id
        $docsBySection = [];
        foreach ($docRows as $row) {
            $secId = (int) ($row['section_id'] ?? 0);
            $docId = (int) $row['document_id'];
            $title = !empty($row['page_title']) ? $row['page_title'] : "Document #{$docId}";
            $slug = !empty($row['page_slug']) ? $row['page_slug'] : "doc-{$docId}";

            $isActive = false;
            if ($activeDocumentId !== null && $docId === $activeDocumentId) {
                $isActive = true;
            } elseif ($activeSlug !== null && $activeSlug !== '' && $slug === $activeSlug) {
                $isActive = true;
            }

            $docItem = [
                'assignment_id' => (int) $row['assignment_id'],
                'document_id'   => $docId,
                'document_type' => (string) $row['document_type'],
                'section_id'    => $secId,
                'title'         => $title,
                'slug'          => $slug,
                'status'        => (string) ($row['page_status'] ?? 'published'),
                'sort_order'    => (int) $row['sort_order'],
                'is_active'     => $isActive,
            ];

            if (!isset($docsBySection[$secId])) {
                $docsBySection[$secId] = [];
            }
            $docsBySection[$secId][] = $docItem;
        }

        // Group sections by parent_id
        $sectionsByParent = [];
        foreach ($sections as $section) {
            $parentId = (int) ($section['parent_id'] ?? 0);
            if (!isset($sectionsByParent[$parentId])) {
                $sectionsByParent[$parentId] = [];
            }
            $sectionsByParent[$parentId][] = $section;
        }

        // Recursive tree builder
        $buildSubtree = function (array $sec) use (&$buildSubtree, &$sectionsByParent, &$docsBySection): array {
            $secId = (int) $sec['id'];
            $children = [];
            $hasActiveChild = false;

            // Child sections
            if (isset($sectionsByParent[$secId])) {
                foreach ($sectionsByParent[$secId] as $childSec) {
                    $childNode = $buildSubtree($childSec);
                    if (!empty($childNode['has_active_child'])) {
                        $hasActiveChild = true;
                    }
                    $children[] = $childNode;
                }
            }

            // Documents under this section
            $sectionDocs = $docsBySection[$secId] ?? [];
            foreach ($sectionDocs as $doc) {
                if (!empty($doc['is_active'])) {
                    $hasActiveChild = true;
                }
            }

            $sec['children'] = $children;
            $sec['documents'] = $sectionDocs;
            $sec['has_active_child'] = $hasActiveChild;

            return $sec;
        };

        // Root sections
        $rootSections = [];
        $spaceHasActive = false;

        if (isset($sectionsByParent[0])) {
            foreach ($sectionsByParent[0] as $rootSec) {
                $rootNode = $buildSubtree($rootSec);
                if (!empty($rootNode['has_active_child'])) {
                    $spaceHasActive = true;
                }
                $rootSections[] = $rootNode;
            }
        }

        // Root documents (section_id = 0)
        $rootDocs = $docsBySection[0] ?? [];
        foreach ($rootDocs as $rd) {
            if (!empty($rd['is_active'])) {
                $spaceHasActive = true;
            }
        }

        return [
            'space'            => $space,
            'sections'         => $rootSections,
            'root_documents'   => $rootDocs,
            'has_active_child' => $spaceHasActive,
            'total_sections'   => count($sections),
            'total_documents'  => count($docRows),
        ];
    }
}
