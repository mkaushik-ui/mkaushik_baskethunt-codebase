<?php
declare(strict_types=1);

namespace SOI\Core\Spaces;

require_once __DIR__ . '/KnowledgeSpaceServiceInterface.php';
require_once __DIR__ . '/Audience/AudiencePolicySchema.php';
require_once __DIR__ . '/Audience/AudiencePolicyServiceInterface.php';
require_once __DIR__ . '/Audience/AudienceSubjectContext.php';
require_once __DIR__ . '/Audience/AudiencePolicyService.php';

use SOI\Core\Cache;
use SOI\Core\Database;
use SOI\Core\Spaces\Audience\AudiencePolicyService;
use SOI\Core\Spaces\Audience\AudiencePolicyServiceInterface;
use SOI\Core\Spaces\Audience\AudienceSubjectContext;

/**
 * KnowledgeSpaceService
 *
 * Core domain service for managing Knowledge Spaces with full CRUD,
 * caching engine, unique slug validation, and enum type enforcement.
 */
class KnowledgeSpaceService implements KnowledgeSpaceServiceInterface
{
    public const CACHE_PREFIX = 'space:';
    public const CACHE_TTL = 3600;

    private static ?self $instance = null;
    private ?\PDO $pdo = null;
    private ?AudiencePolicyServiceInterface $audiencePolicyService = null;

    /**
     * @param \PDO|null $pdo Optional PDO instance for dependency injection or testing.
     * @param AudiencePolicyServiceInterface|null $audiencePolicyService Optional policy service.
     */
    public function __construct(?\PDO $pdo = null, ?AudiencePolicyServiceInterface $audiencePolicyService = null)
    {
        $this->pdo = $pdo;
        $this->audiencePolicyService = $audiencePolicyService;
    }

    /**
     * Get or set the AudiencePolicyService instance.
     */
    public function getAudiencePolicyService(): AudiencePolicyServiceInterface
    {
        if ($this->audiencePolicyService === null) {
            $this->audiencePolicyService = AudiencePolicyService::instance();
        }
        return $this->audiencePolicyService;
    }

    public function setAudiencePolicyService(AudiencePolicyServiceInterface $service): void
    {
        $this->audiencePolicyService = $service;
    }

    /**
     * Get or create a default singleton instance.
     */
    public static function instance(?\PDO $pdo = null): self
    {
        if (self::$instance === null || $pdo !== null) {
            self::$instance = new self($pdo);
        }
        return self::$instance;
    }

    /**
     * Helper to get active PDO connection.
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
        $slug = trim((string) $slug, '-');
        return $slug;
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
     * Create a new space record.
     * Validates required title, unique slug, and enum type (generaldocs, libraries, tech).
     *
     * @param array<string, mixed> $data
     * @return int Inserted space ID.
     * @throws \InvalidArgumentException If validation fails or duplicate slug detected.
     */
    public function createSpace(array $data): int
    {
        // 1. Validate required title
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('Title is required for space.');
        }

        // 2. Validate enum type
        $rawType = (string) ($data['type'] ?? '');
        if (!SpaceSchema::isValidType($rawType)) {
            $allowed = implode(', ', SpaceSchema::TYPES);
            throw new \InvalidArgumentException("Invalid space type '{$rawType}'. Allowed types: {$allowed}");
        }
        $type = SpaceSchema::normalizeType($rawType);

        // 3. Determine and validate slug
        $rawSlug = trim((string) ($data['slug'] ?? ''));
        $slug = $rawSlug !== '' ? self::normalizeSlug($rawSlug) : self::slugify($title);
        if ($slug === '') {
            throw new \InvalidArgumentException('A valid non-empty slug is required.');
        }

        // 4. Validate slug uniqueness
        if ($this->slugExists($slug)) {
            throw new \InvalidArgumentException("A space with slug '{$slug}' already exists.");
        }

        // 5. Default and sanitize remaining fields
        $status = (string) ($data['status'] ?? SpaceSchema::STATUS_PUBLISHED);
        $visibility = (string) ($data['visibility'] ?? SpaceSchema::VISIBILITY_PUBLIC);
        $sortorder = (int) ($data['sortorder'] ?? 0);
        $description = isset($data['description']) ? (string) $data['description'] : null;
        $icon = isset($data['icon']) ? (string) $data['icon'] : null;
        $now = date('Y-m-d H:i:s');

        // 5b. Validate and normalize audience policy (WD-03)
        $policyService = $this->getAudiencePolicyService();
        $rawPolicy = $data['audience_policy'] ?? null;
        if ($rawPolicy !== null && $rawPolicy !== '') {
            $decodedForValidation = is_string($rawPolicy) ? json_decode($rawPolicy, true) : $rawPolicy;
            if (is_array($decodedForValidation) && !$policyService->validatePolicySchema($decodedForValidation)) {
                throw new \InvalidArgumentException('Invalid audience policy schema.');
            }
            $normalizedPolicy = $policyService->normalizePolicy($rawPolicy, $visibility);
        } else {
            $normalizedPolicy = $policyService->getDefaultPolicy($visibility);
        }
        $audiencePolicyJson = json_encode($normalizedPolicy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $record = [
            'title'           => $title,
            'slug'            => $slug,
            'type'            => $type,
            'status'          => $status,
            'visibility'      => $visibility,
            'sortorder'       => $sortorder,
            'description'     => $description,
            'icon'            => $icon,
            'audience_policy' => $audiencePolicyJson,
            'created_at'      => $now,
            'updated_at'      => $now,
        ];

        // 6. Insert into soispaces table
        $table = SpaceSchema::TABLE;
        $cols = implode(', ', array_keys($record));
        $placeholders = implode(', ', array_fill(0, count($record), '?'));
        $sql = "INSERT INTO `{$table}` ({$cols}) VALUES ({$placeholders})";

        $pdo = $this->getPdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($record));
        $id = (int) $pdo->lastInsertId();

        $record['id'] = $id;

        // 7. Warm cache for both ID and Slug
        $this->setCacheForRecord($record);

        return $id;
    }

    /**
     * Fetch a space record by primary key ID with caching.
     *
     * @param int $id
     * @return array<string, mixed>|null
     */
    public function getSpace(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $cacheKey = self::CACHE_PREFIX . "id:{$id}";
        $cached = Cache::get($cacheKey);
        if ($cached !== null && is_array($cached)) {
            return $cached;
        }

        $table = SpaceSchema::TABLE;
        $sql = "SELECT * FROM `{$table}` WHERE id = ? LIMIT 1";
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute([$id]);
        $record = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($record && is_array($record)) {
            $this->setCacheForRecord($record);
            return $record;
        }

        return null;
    }

    /**
     * Fetch a space record by slug with caching.
     *
     * @param string $slug
     * @return array<string, mixed>|null
     */
    public function getSpaceBySlug(string $slug): ?array
    {
        $normalized = self::normalizeSlug($slug);
        if ($normalized === '') {
            return null;
        }

        $cacheKey = self::CACHE_PREFIX . "slug:{$normalized}";
        $cached = Cache::get($cacheKey);
        if ($cached !== null && is_array($cached)) {
            return $cached;
        }

        $table = SpaceSchema::TABLE;
        $sql = "SELECT * FROM `{$table}` WHERE slug = ? LIMIT 1";
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute([$normalized]);
        $record = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($record && is_array($record)) {
            $this->setCacheForRecord($record);
            return $record;
        }

        return null;
    }

    /**
     * Query spaces with optional filtering (type, status, visibility),
     * ordered by sortorder ASC, title ASC.
     *
     * @param array<string, mixed> $filter Optional filters (type, status, visibility).
     * @return array<int, array<string, mixed>> Matching spaces.
     */
    public function listSpaces(array $filter = []): array
    {
        $table = SpaceSchema::TABLE;
        $conditions = [];
        $params = [];

        if (!empty($filter['q'])) {
            $conditions[] = '(`title` LIKE ? OR `slug` LIKE ? OR `description` LIKE ?)';
            $searchTerm = '%' . trim((string) $filter['q']) . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        if (!empty($filter['type'])) {
            $conditions[] = '`type` = ?';
            $params[] = SpaceSchema::normalizeType((string) $filter['type']);
        }

        if (!empty($filter['status'])) {
            $conditions[] = '`status` = ?';
            $params[] = (string) $filter['status'];
        }

        if (!empty($filter['visibility'])) {
            $conditions[] = '`visibility` = ?';
            $params[] = (string) $filter['visibility'];
        }

        $whereClause = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $sql = "SELECT * FROM `{$table}` {$whereClause} ORDER BY sortorder ASC, title ASC";

        if (!empty($filter['limit'])) {
            $limit = (int) $filter['limit'];
            $offset = (int) ($filter['offset'] ?? 0);
            $sql .= " LIMIT {$limit} OFFSET {$offset}";
        }

        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return is_array($results) ? $results : [];
    }

    /**
     * Count matching spaces with optional filtering.
     *
     * @param array<string, mixed> $filter
     * @return int Total number of matching spaces.
     */
    public function getSpaceCount(array $filter = []): int
    {
        $table = SpaceSchema::TABLE;
        $conditions = [];
        $params = [];

        if (!empty($filter['q'])) {
            $conditions[] = '(`title` LIKE ? OR `slug` LIKE ? OR `description` LIKE ?)';
            $searchTerm = '%' . trim((string) $filter['q']) . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        if (!empty($filter['type'])) {
            $conditions[] = '`type` = ?';
            $params[] = SpaceSchema::normalizeType((string) $filter['type']);
        }

        if (!empty($filter['status'])) {
            $conditions[] = '`status` = ?';
            $params[] = (string) $filter['status'];
        }

        if (!empty($filter['visibility'])) {
            $conditions[] = '`visibility` = ?';
            $params[] = (string) $filter['visibility'];
        }

        $whereClause = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $sql = "SELECT COUNT(*) FROM `{$table}` {$whereClause}";

        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Query spaces filtered by subject access policy and optional criteria (type, status, visibility),
     * tailored to the requesting viewer.
     *
     * @param AudienceSubjectContext $subject Requesting user/guest context.
     * @param array<string, mixed> $filter Optional filters (type, status, visibility).
     * @return array<int, array<string, mixed>> List of matching space records accessible to the subject.
     */
    public function listSpacesForSubject(AudienceSubjectContext $subject, array $filter = []): array
    {
        $spaces = $this->listSpaces($filter);
        return $this->getAudiencePolicyService()->filterAccessibleSpaces($subject, $spaces);
    }

    /**
     * Update an existing space record by ID and purge related cache keys.
     *
     * @param int $id
     * @param array<string, mixed> $data
     * @return bool True if updated, false if not found.
     * @throws \InvalidArgumentException If validation fails or duplicate slug detected.
     */
    public function updateSpace(int $id, array $data): bool
    {
        if ($id <= 0) {
            return false;
        }

        $existing = $this->findRawById($id);
        if (!$existing) {
            return false;
        }

        $updates = [];

        // 1. Validate title if provided
        if (array_key_exists('title', $data)) {
            $title = trim((string) $data['title']);
            if ($title === '') {
                throw new \InvalidArgumentException('Title cannot be empty.');
            }
            $updates['title'] = $title;
        }

        // 2. Validate enum type if provided
        if (array_key_exists('type', $data)) {
            $rawType = (string) $data['type'];
            if (!SpaceSchema::isValidType($rawType)) {
                $allowed = implode(', ', SpaceSchema::TYPES);
                throw new \InvalidArgumentException("Invalid space type '{$rawType}'. Allowed types: {$allowed}");
            }
            $updates['type'] = SpaceSchema::normalizeType($rawType);
        }

        // 3. Validate slug if provided
        $slugUpdated = false;
        $newSlug = '';
        if (array_key_exists('slug', $data)) {
            $rawSlug = trim((string) $data['slug']);
            $newSlug = self::normalizeSlug($rawSlug);
            if ($newSlug === '') {
                throw new \InvalidArgumentException('Slug cannot be empty.');
            }

            if ($newSlug !== (string) $existing['slug']) {
                if ($this->slugExists($newSlug, $id)) {
                    throw new \InvalidArgumentException("A space with slug '{$newSlug}' already exists.");
                }
                $slugUpdated = true;
                $updates['slug'] = $newSlug;
            }
        }

        // 4. Update status and visibility if provided
        if (array_key_exists('status', $data)) {
            $updates['status'] = (string) $data['status'];
        }

        if (array_key_exists('visibility', $data)) {
            $updates['visibility'] = (string) $data['visibility'];
        }

        if (array_key_exists('sortorder', $data)) {
            $updates['sortorder'] = (int) $data['sortorder'];
        }

        if (array_key_exists('description', $data)) {
            $updates['description'] = $data['description'] !== null ? (string) $data['description'] : null;
        }

        if (array_key_exists('icon', $data)) {
            $updates['icon'] = $data['icon'] !== null ? (string) $data['icon'] : null;
        }

        // 4b. Validate and normalize audience policy if provided (WD-03)
        if (array_key_exists('audience_policy', $data)) {
            $policyService = $this->getAudiencePolicyService();
            $rawPolicy = $data['audience_policy'];
            $effectiveVis = (string) ($updates['visibility'] ?? $existing['visibility'] ?? SpaceSchema::VISIBILITY_PUBLIC);
            if ($rawPolicy !== null && $rawPolicy !== '') {
                $decodedForValidation = is_string($rawPolicy) ? json_decode($rawPolicy, true) : $rawPolicy;
                if (is_array($decodedForValidation) && !$policyService->validatePolicySchema($decodedForValidation)) {
                    throw new \InvalidArgumentException('Invalid audience policy schema.');
                }
                $normalizedPolicy = $policyService->normalizePolicy($rawPolicy, $effectiveVis);
            } else {
                $normalizedPolicy = $policyService->getDefaultPolicy($effectiveVis);
            }
            $updates['audience_policy'] = json_encode($normalizedPolicy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } elseif (array_key_exists('visibility', $updates) && !empty($existing['audience_policy'])) {
            // Keep existing policy visibility synchronized with space visibility update
            $policyService = $this->getAudiencePolicyService();
            $normalizedPolicy = $policyService->normalizePolicy($existing['audience_policy'], $updates['visibility']);
            $normalizedPolicy['visibility'] = $updates['visibility'];
            $updates['audience_policy'] = json_encode($normalizedPolicy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $updates['updated_at'] = date('Y-m-d H:i:s');

        // 5. Run DB update
        $table = SpaceSchema::TABLE;
        $setClauses = [];
        $params = [];
        foreach ($updates as $col => $val) {
            $setClauses[] = "`{$col}` = ?";
            $params[] = $val;
        }
        $params[] = $id;

        $setSql = implode(', ', $setClauses);
        $sql = "UPDATE `{$table}` SET {$setSql} WHERE id = ?";
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($params);

        // 6. Purge related cache keys
        $this->purgeCacheForSpace($id, (string) $existing['slug']);
        if ($slugUpdated && $newSlug !== '') {
            Cache::delete(self::CACHE_PREFIX . "slug:{$newSlug}");
            Cache::purge(self::CACHE_PREFIX . "slug:{$newSlug}");
        }

        return true;
    }

    /**
     * Delete a space record by ID and purge related cache keys.
     *
     * @param int $id
     * @return bool True if deleted, false if not found.
     */
    public function deleteSpace(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $existing = $this->findRawById($id);
        if (!$existing) {
            return false;
        }

        $table = SpaceSchema::TABLE;
        $sql = "DELETE FROM `{$table}` WHERE id = ?";
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute([$id]);

        // Purge related cache keys
        $this->purgeCacheForSpace($id, (string) $existing['slug']);

        return true;
    }

    /**
     * Check if a slug is already taken (optionally ignoring a specific ID).
     */
    private function slugExists(string $slug, int $excludeId = 0): bool
    {
        $table = SpaceSchema::TABLE;
        if ($excludeId > 0) {
            $sql = "SELECT id FROM `{$table}` WHERE slug = ? AND id != ? LIMIT 1";
            $stmt = $this->getPdo()->prepare($sql);
            $stmt->execute([$slug, $excludeId]);
        } else {
            $sql = "SELECT id FROM `{$table}` WHERE slug = ? LIMIT 1";
            $stmt = $this->getPdo()->prepare($sql);
            $stmt->execute([$slug]);
        }
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Raw DB fetch by ID without cache lookup.
     */
    private function findRawById(int $id): ?array
    {
        $table = SpaceSchema::TABLE;
        $sql = "SELECT * FROM `{$table}` WHERE id = ? LIMIT 1";
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * Warm cache for both ID and Slug keys.
     */
    private function setCacheForRecord(array $record): void
    {
        if (isset($record['id'])) {
            Cache::set(self::CACHE_PREFIX . "id:{$record['id']}", $record, self::CACHE_TTL);
        }
        if (!empty($record['slug'])) {
            Cache::set(self::CACHE_PREFIX . "slug:{$record['slug']}", $record, self::CACHE_TTL);
        }
    }

    /**
     * Validate whether a space slug is valid, non-reserved, and uniquely available.
     *
     * @param string $slug
     * @param int|null $excludeId
     * @return bool True if slug is valid and available.
     */
    public function validateSlug(string $slug, ?int $excludeId = null): bool
    {
        $trimmed = trim($slug);
        if ($trimmed === '') {
            return false;
        }

        // Slug format check: lowercase alphanumeric and hyphens only
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $trimmed)) {
            return false;
        }

        // Reserved words check
        if (in_array(strtolower($trimmed), SpaceSchema::RESERVED_SLUGS, true)) {
            return false;
        }

        // Uniqueness check
        return !$this->slugExists($trimmed, $excludeId ?? 0);
    }

    /**
     * Invalidate both ID and Slug cache keys.
     */
    public function purgeSpaceCache(?int $id = null, ?string $slug = null): void
    {
        if ($id !== null && $id > 0) {
            Cache::delete(self::CACHE_PREFIX . "id:{$id}");
            Cache::delete(self::CACHE_PREFIX . "{$id}");
            Cache::purge(self::CACHE_PREFIX . "id:{$id}");
        }

        if ($slug !== null && trim($slug) !== '') {
            $s = trim($slug);
            Cache::delete(self::CACHE_PREFIX . "slug:{$s}");
            Cache::delete(self::CACHE_PREFIX . "{$s}");
            Cache::purge(self::CACHE_PREFIX . "slug:{$s}");
        }

        Cache::delete('spaces:list');
        Cache::delete('spaces:all');
    }

    private function purgeCacheForSpace(int $id, string $slug): void
    {
        $this->purgeSpaceCache($id, $slug);
    }

    /*
     * -------------------------------------------------------------
     * Static convenience facades for direct CMS-wide access
     * -------------------------------------------------------------
     */

    public static function create(array $data): int
    {
        return self::instance()->createSpace($data);
    }

    public static function get(int $id): ?array
    {
        return self::instance()->getSpace($id);
    }

    public static function getBySlug(string $slug): ?array
    {
        return self::instance()->getSpaceBySlug($slug);
    }

    public static function list(array $filter = []): array
    {
        return self::instance()->listSpaces($filter);
    }

    public static function update(int $id, array $data): bool
    {
        return self::instance()->updateSpace($id, $data);
    }

    public static function delete(int $id): bool
    {
        return self::instance()->deleteSpace($id);
    }
}
