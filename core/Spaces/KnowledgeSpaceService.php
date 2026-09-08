<?php
declare(strict_types=1);

namespace SOI\Core\Spaces;

use SOI\Core\Cache;
use SOI\Core\Database;

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

    private const RESERVED_SLUGS = [
        'admin', 'api', 'assets', 'auth', 'build', 'config', 'core', 'docs',
        'install', 'library', 'login', 'logout', 'media', 'plugins', 'saml',
        'search', 'settings', 'soi-central', 'storage', 'tech', 'themes',
        'updates', 'uploads', 'users'
    ];

    private static ?self $instance = null;
    private ?\PDO $pdo = null;

    /**
     * @param \PDO|null $pdo Optional PDO instance for dependency injection or isolated unit testing.
     */
    public function __construct(?\PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    /**
     * Get or create a singleton instance.
     */
    public static function instance(?\PDO $pdo = null): self
    {
        if (self::$instance === null || $pdo !== null) {
            self::$instance = new self($pdo);
        }
        return self::$instance;
    }

    private function getPdo(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        return Database::pdo();
    }

    private function getTableName(): string
    {
        $pdo = $this->getPdo();
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            return 'soispaces';
        }

        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'soispaces'");
            if ($stmt && $stmt->fetch()) {
                return 'soispaces';
            }
        } catch (\Throwable $e) {}

        return Database::prefix('spaces');
    }

    public static function normalizeSlug(string $source): string
    {
        $slug = strtolower(trim($source));
        $slug = (string) preg_replace('/[^a-z0-9\-]+/', '-', $slug);
        return trim($slug, '-');
    }

    public static function slugify(string $text): string
    {
        if (function_exists('slugify')) {
            $s = slugify($text);
            if (is_string($s) && $s !== '') {
                return self::normalizeSlug($s);
            }
        }
        return self::normalizeSlug($text);
    }

    public function createSpace(array $data): int
    {
        $title = trim((string) ($data['title'] ?? $data['name'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('Title is required for space.');
        }

        $rawType = (string) ($data['type'] ?? SpaceSchema::TYPE_GENERALDOCS);
        $type = SpaceSchema::normalizeType($rawType);
        if (!SpaceSchema::isValidType($type)) {
            $allowed = implode(', ', SpaceSchema::TYPES);
            throw new \InvalidArgumentException("Invalid space type '{$rawType}'. Allowed types: {$allowed}");
        }

        $rawSlug = trim((string) ($data['slug'] ?? ''));
        $slug = $rawSlug !== '' ? self::normalizeSlug($rawSlug) : self::slugify($title);
        if ($slug === '') {
            throw new \InvalidArgumentException('A valid non-empty slug is required.');
        }

        if (!$this->validateSlug($slug)) {
            throw new \InvalidArgumentException("A space with slug '{$slug}' already exists or is reserved.");
        }

        $status = strtolower(trim((string) ($data['status'] ?? SpaceSchema::STATUS_PUBLISHED)));
        if (!SpaceSchema::isValidStatus($status)) {
            $status = SpaceSchema::STATUS_PUBLISHED;
        }

        $visibility = strtolower(trim((string) ($data['visibility'] ?? SpaceSchema::VISIBILITY_PUBLIC)));
        if (!SpaceSchema::isValidVisibility($visibility)) {
            $visibility = SpaceSchema::VISIBILITY_PUBLIC;
        }

        $sortorder = (int) ($data['sortorder'] ?? $data['sort_order'] ?? 0);
        $description = isset($data['description']) && $data['description'] !== null ? trim((string) $data['description']) : null;
        $icon = isset($data['icon']) && $data['icon'] !== null ? trim((string) $data['icon']) : null;

        $audiencePolicy = null;
        if (isset($data['audience_policy'])) {
            $audiencePolicy = is_array($data['audience_policy'])
                ? json_encode($data['audience_policy'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : (string) $data['audience_policy'];
        }

        $settings = null;
        if (isset($data['settings'])) {
            $settings = is_array($data['settings'])
                ? json_encode($data['settings'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : (string) $data['settings'];
        }

        $now = date('Y-m-d H:i:s');

        $record = [
            'title'       => $title,
            'slug'        => $slug,
            'type'        => $type,
            'status'      => $status,
            'visibility'  => $visibility,
            'sortorder'   => $sortorder,
            'description' => $description,
            'icon'        => $icon,
            'audience_policy' => $audiencePolicy,
            'settings'    => $settings,
            'created_at'  => $now,
            'updated_at'  => $now,
        ];

        SpaceSchema::ensure($this->pdo);

        $table = $this->getTableName();
        $cols = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($record)));
        $placeholders = implode(', ', array_fill(0, count($record), '?'));
        $sql = "INSERT INTO `{$table}` ({$cols}) VALUES ({$placeholders})";

        $pdo = $this->getPdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($record));
        $id = (int) $pdo->lastInsertId();

        $record['id'] = $id;

        $this->setCacheForRecord($record);

        return $id;
    }

    public function getSpace(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $cached = Cache::get(self::CACHE_PREFIX . "id:{$id}") ?? Cache::get("space_id_{$id}");
        if ($cached !== null && is_array($cached)) {
            return $cached;
        }

        SpaceSchema::ensure($this->pdo);

        $table = $this->getTableName();
        $sql = "SELECT * FROM `{$table}` WHERE `id` = ? LIMIT 1";
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute([$id]);
        $record = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($record && is_array($record)) {
            $hydrated = $this->hydrateRecord($record);
            $this->setCacheForRecord($hydrated);
            return $hydrated;
        }

        return null;
    }

    public function getSpaceBySlug(string $slug): ?array
    {
        $normalized = self::normalizeSlug($slug);
        if ($normalized === '') {
            return null;
        }

        $cached = Cache::get(self::CACHE_PREFIX . "slug:{$normalized}") ?? Cache::get("space_slug_{$normalized}");
        if ($cached !== null && is_array($cached)) {
            return $cached;
        }

        SpaceSchema::ensure($this->pdo);

        $table = $this->getTableName();
        $sql = "SELECT * FROM `{$table}` WHERE `slug` = ? LIMIT 1";
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute([$normalized]);
        $record = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($record && is_array($record)) {
            $hydrated = $this->hydrateRecord($record);
            $this->setCacheForRecord($hydrated);
            return $hydrated;
        }

        return null;
    }

    public function listSpaces(array $filter = []): array
    {
        SpaceSchema::ensure($this->pdo);

        $table = $this->getTableName();
        $conditions = [];
        $params = [];

        if (!empty($filter['type']) || !empty($filter['types'])) {
            $typeInput = $filter['type'] ?? $filter['types'];
            $types = is_array($typeInput) ? $typeInput : explode(',', (string) $typeInput);
            $normalizedTypes = [];
            foreach ($types as $t) {
                $norm = SpaceSchema::normalizeType((string) $t);
                if ($norm !== '') {
                    $normalizedTypes[] = $norm;
                }
            }
            if (!empty($normalizedTypes)) {
                $placeholders = implode(', ', array_fill(0, count($normalizedTypes), '?'));
                $conditions[] = "`type` IN ({$placeholders})";
                $params = array_merge($params, $normalizedTypes);
            }
        }

        if (!empty($filter['status']) || !empty($filter['statuses'])) {
            $statusInput = $filter['status'] ?? $filter['statuses'];
            $statuses = is_array($statusInput) ? $statusInput : explode(',', (string) $statusInput);
            $cleanStatuses = array_values(array_filter(array_map('strtolower', array_map('trim', $statuses))));
            if (!empty($cleanStatuses)) {
                $placeholders = implode(', ', array_fill(0, count($cleanStatuses), '?'));
                $conditions[] = "`status` IN ({$placeholders})";
                $params = array_merge($params, $cleanStatuses);
            }
        }

        if (!empty($filter['visibility']) || !empty($filter['visibilities'])) {
            $visInput = $filter['visibility'] ?? $filter['visibilities'];
            $visibilities = is_array($visInput) ? $visInput : explode(',', (string) $visInput);
            $cleanVis = array_values(array_filter(array_map('strtolower', array_map('trim', $visibilities))));
            if (!empty($cleanVis)) {
                $placeholders = implode(', ', array_fill(0, count($cleanVis), '?'));
                $conditions[] = "`visibility` IN ({$placeholders})";
                $params = array_merge($params, $cleanVis);
            }
        }

        if (!empty($filter['q']) || !empty($filter['search'])) {
            $query = '%' . trim((string) ($filter['q'] ?? $filter['search'])) . '%';
            $conditions[] = "(`title` LIKE ? OR `slug` LIKE ? OR `description` LIKE ?)";
            $params[] = $query;
            $params[] = $query;
            $params[] = $query;
        }

        $whereClause = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
        
        $sql = "SELECT * FROM `{$table}` {$whereClause} ORDER BY `sortorder` ASC, `title` ASC";

        if (isset($filter['limit']) && (int) $filter['limit'] > 0) {
            $limit = (int) $filter['limit'];
            $offset = max(0, (int) ($filter['offset'] ?? 0));
            $sql .= " LIMIT {$limit} OFFSET {$offset}";
        }

        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (!is_array($results)) {
            return [];
        }

        return array_map(fn($r) => $this->hydrateRecord($r), $results);
    }

    public function getSpaceCount(array $filter = []): int
    {
        return count($this->listSpaces($filter));
    }

    public function updateSpace(int $id, array $data): bool
    {
        if ($id <= 0) {
            return false;
        }

        $existing = $this->getSpace($id);
        if (!$existing) {
            return false;
        }

        $updates = [];

        if (array_key_exists('title', $data) || array_key_exists('name', $data)) {
            $title = trim((string) ($data['title'] ?? $data['name'] ?? ''));
            if ($title === '') {
                throw new \InvalidArgumentException('Title cannot be empty.');
            }
            $updates['title'] = $title;
        }

        if (array_key_exists('type', $data)) {
            $rawType = (string) $data['type'];
            $type = SpaceSchema::normalizeType($rawType);
            if (!SpaceSchema::isValidType($type)) {
                $allowed = implode(', ', SpaceSchema::TYPES);
                throw new \InvalidArgumentException("Invalid space type '{$rawType}'. Allowed types: {$allowed}");
            }
            $updates['type'] = $type;
        }

        $slugUpdated = false;
        $newSlug = '';
        if (array_key_exists('slug', $data)) {
            $rawSlug = trim((string) $data['slug']);
            $newSlug = self::normalizeSlug($rawSlug);
            if ($newSlug === '') {
                throw new \InvalidArgumentException('Slug cannot be empty.');
            }

            if ($newSlug !== (string) $existing['slug']) {
                if (!$this->validateSlug($newSlug, $id)) {
                    throw new \InvalidArgumentException("A space with slug '{$newSlug}' already exists or is reserved.");
                }
                $slugUpdated = true;
                $updates['slug'] = $newSlug;
            }
        }

        if (array_key_exists('status', $data)) {
            $status = strtolower(trim((string) $data['status']));
            if (!SpaceSchema::isValidStatus($status)) {
                $status = SpaceSchema::STATUS_PUBLISHED;
            }
            $updates['status'] = $status;
        }

        if (array_key_exists('visibility', $data)) {
            $visibility = strtolower(trim((string) $data['visibility']));
            if (!SpaceSchema::isValidVisibility($visibility)) {
                $visibility = SpaceSchema::VISIBILITY_PUBLIC;
            }
            $updates['visibility'] = $visibility;
        }

        if (array_key_exists('sortorder', $data) || array_key_exists('sort_order', $data)) {
            $updates['sortorder'] = (int) ($data['sortorder'] ?? $data['sort_order'] ?? 0);
        }

        if (array_key_exists('description', $data)) {
            $updates['description'] = $data['description'] !== null ? trim((string) $data['description']) : null;
        }

        if (array_key_exists('icon', $data)) {
            $updates['icon'] = $data['icon'] !== null ? trim((string) $data['icon']) : null;
        }

        if (array_key_exists('audience_policy', $data)) {
            $updates['audience_policy'] = is_array($data['audience_policy'])
                ? json_encode($data['audience_policy'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : (isset($data['audience_policy']) ? (string) $data['audience_policy'] : null);
        }

        if (array_key_exists('settings', $data)) {
            $updates['settings'] = is_array($data['settings'])
                ? json_encode($data['settings'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : (isset($data['settings']) ? (string) $data['settings'] : null);
        }

        $updates['updated_at'] = date('Y-m-d H:i:s');

        SpaceSchema::ensure($this->pdo);
        $table = $this->getTableName();
        $setClauses = [];
        $params = [];
        foreach ($updates as $col => $val) {
            $setClauses[] = "`{$col}` = ?";
            $params[] = $val;
        }
        $params[] = $id;

        $setSql = implode(', ', $setClauses);
        $sql = "UPDATE `{$table}` SET {$setSql} WHERE `id` = ?";
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($params);

        $this->purgeSpaceCache($id, (string) $existing['slug']);
        if ($slugUpdated && $newSlug !== '') {
            $this->purgeSpaceCache($id, $newSlug);
        }

        $updatedRecord = array_merge($existing, $updates);
        $this->setCacheForRecord($updatedRecord);

        return true;
    }

    public function deleteSpace(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $existing = $this->getSpace($id);
        if (!$existing) {
            return false;
        }

        SpaceSchema::ensure($this->pdo);

        $table = $this->getTableName();
        $sql = "DELETE FROM `{$table}` WHERE `id` = ?";
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute([$id]);

        $this->purgeSpaceCache($id, (string) $existing['slug']);

        return true;
    }

    public function validateSlug(string $slug, ?int $excludeId = null): bool
    {
        $trimmed = trim(strtolower($slug));
        if ($trimmed === '' || strlen($trimmed) > 191) {
            return false;
        }

        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $trimmed)) {
            return false;
        }

        if (in_array($trimmed, self::RESERVED_SLUGS, true)) {
            return false;
        }

        $normalized = $trimmed;

        SpaceSchema::ensure($this->pdo);

        $table = $this->getTableName();
        $sql = "SELECT `id` FROM `{$table}` WHERE `slug` = ?";
        $params = [$normalized];

        if ($excludeId !== null && $excludeId > 0) {
            $sql .= " AND `id` != ?";
            $params[] = $excludeId;
        }

        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return empty($row);
    }

    public function purgeSpaceCache(?int $id = null, ?string $slug = null): void
    {
        if ($id !== null && $id > 0) {
            Cache::delete(self::CACHE_PREFIX . "id:{$id}");
            Cache::delete("space_id_{$id}");
        }

        if ($slug !== null && $slug !== '') {
            $normalized = self::normalizeSlug($slug);
            Cache::delete(self::CACHE_PREFIX . "slug:{$normalized}");
            Cache::delete("space_slug_{$normalized}");
            Cache::purgeUri("/docs/{$normalized}");
            Cache::purgeUri("/library/{$normalized}");
            Cache::purgeUri("/tech/{$normalized}");
        }

        Cache::delete('spaces_list_all');
        Cache::purgeAll();
    }

    private function setCacheForRecord(array $record): void
    {
        $id = (int) ($record['id'] ?? 0);
        $slug = (string) ($record['slug'] ?? '');

        if ($id > 0) {
            Cache::set(self::CACHE_PREFIX . "id:{$id}", $record, self::CACHE_TTL);
            Cache::set("space_id_{$id}", $record, self::CACHE_TTL);
        }

        if ($slug !== '') {
            Cache::set(self::CACHE_PREFIX . "slug:{$slug}", $record, self::CACHE_TTL);
            Cache::set("space_slug_{$slug}", $record, self::CACHE_TTL);
        }
    }

    private function hydrateRecord(array $row): array
    {
        $row['id'] = (int) ($row['id'] ?? 0);
        $row['sortorder'] = (int) ($row['sortorder'] ?? $row['sort_order'] ?? 0);
        $row['sort_order'] = $row['sortorder'];

        if (isset($row['audience_policy']) && is_string($row['audience_policy']) && $row['audience_policy'] !== '') {
            $decoded = json_decode($row['audience_policy'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $row['audience_policy_parsed'] = $decoded;
            }
        }

        if (isset($row['settings']) && is_string($row['settings']) && $row['settings'] !== '') {
            $decoded = json_decode($row['settings'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $row['settings_parsed'] = $decoded;
            }
        }

        return $row;
    }
}
