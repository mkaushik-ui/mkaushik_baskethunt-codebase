<?php
declare(strict_types=1);

namespace SOI\Core\Spaces;

require_once __DIR__ . '/SpaceDocumentServiceInterface.php';

use SOI\Core\Cache;
use SOI\Core\Database;

/**
 * Service for Legacy Document Compatibility, Initial Space Seeding, and Migration (KS-05).
 */
class SpaceDocumentService implements SpaceDocumentServiceInterface
{
    private ?\PDO $pdo;
    private ?KnowledgeSpaceService $spaceService;

    public function __construct(?\PDO $pdo = null, ?KnowledgeSpaceService $spaceService = null)
    {
        $this->pdo = $pdo;
        $this->spaceService = $spaceService;
    }

    private function getPdo(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        return Database::pdo();
    }

    /**
     * Seed canonical initial standard spaces if table is empty or missing them.
     *
     * Standard Canonical Initial Spaces:
     * - `general-docs`       (Type: `generaldocs`, Title: `General Docs`)
     * - `hr-library`         (Type: `libraries`,   Title: `HR Library`)
     * - `it-library`         (Type: `libraries`,   Title: `IT Library`)
     * - `accounts-directory` (Type: `tech`,        Title: `Accounts Directory`)
     * - `hrms`               (Type: `tech`,        Title: `HRMS`)
     *
     * @param bool $force If true, forces insertion of any missing canonical spaces even if table is not empty.
     * @return array<int, array<string, mixed>> List of seeded or existing canonical spaces.
     */
    public function seedInitialSpaces(bool $force = false): array
    {
        SpaceSchema::ensure($this->pdo);

        $pdo = $this->getPdo();
        $table = SpaceSchema::TABLE;

        $countStmt = $pdo->query("SELECT COUNT(*) FROM `{$table}`");
        $totalSpaces = (int) ($countStmt ? $countStmt->fetchColumn() : 0);

        $results = [];
        $initialSpaces = SpaceSchema::getInitialSpaces();

        // If table is empty or force is requested, ensure canonical spaces exist
        if ($totalSpaces === 0 || $force) {
            $now = date('Y-m-d H:i:s');
            $findStmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE `slug` = ? LIMIT 1");
            $insertStmt = $pdo->prepare("INSERT INTO `{$table}` (
                `title`, `slug`, `type`, `status`, `visibility`, `sortorder`, `description`, `icon`, `created_at`, `updated_at`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            foreach ($initialSpaces as $spec) {
                $findStmt->execute([$spec['slug']]);
                $existing = $findStmt->fetch(\PDO::FETCH_ASSOC);

                if ($existing) {
                    $results[] = $existing;
                } else {
                    $normType = SpaceSchema::normalizeType((string) $spec['type']);
                    $insertStmt->execute([
                        $spec['title'],
                        $spec['slug'],
                        $normType,
                        $spec['status'] ?? SpaceSchema::STATUS_PUBLISHED,
                        $spec['visibility'] ?? SpaceSchema::VISIBILITY_PUBLIC,
                        (int) ($spec['sortorder'] ?? 0),
                        $spec['description'] ?? null,
                        $spec['icon'] ?? null,
                        $now,
                        $now,
                    ]);

                    $newId = (int) $pdo->lastInsertId();
                    $findStmt->execute([$spec['slug']]);
                    $newRecord = $findStmt->fetch(\PDO::FETCH_ASSOC);
                    $results[] = is_array($newRecord) ? $newRecord : array_merge($spec, ['id' => $newId]);

                    // Warm cache
                    Cache::set("space:{$newId}", $newRecord, 3600);
                    Cache::set("space:{$spec['slug']}", $newRecord, 3600);
                }
            }
        } else {
            // Table has spaces; fetch existing canonical spaces
            $findStmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE `slug` = ? LIMIT 1");
            foreach ($initialSpaces as $spec) {
                $findStmt->execute([$spec['slug']]);
                $row = $findStmt->fetch(\PDO::FETCH_ASSOC);
                if ($row) {
                    $results[] = $row;
                }
            }
        }

        return $results;
    }

    /**
     * Migrate unassigned legacy pages and posts to canonical spaces.
     *
     * - Unassigned pages (`space_id IS NULL OR space_id = 0`) are assigned to `general-docs`.
     * - Unassigned posts (`space_id IS NULL OR space_id = 0`) are assigned to their respective category libraries or `general-docs`.
     * - Non-destructive: Existing titles, slugs, and body content are preserved strictly.
     * - Synchronizes entries into `soi_space_documents`.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed> Detailed migration report.
     */
    public function migrateLegacyDocuments(array $options = []): array
    {
        SpaceSchema::ensure($this->pdo);
        SpaceSchema::ensureDocumentTables($this->pdo);

        $pdo = $this->getPdo();

        // 1. Ensure canonical spaces are seeded
        $this->seedInitialSpaces(true);

        // 2. Fetch canonical space map (slug => id)
        $tableSpaces = SpaceSchema::TABLE;
        $spacesStmt = $pdo->query("SELECT `id`, `slug`, `title`, `type` FROM `{$tableSpaces}`");
        $spaces = $spacesStmt ? $spacesStmt->fetchAll(\PDO::FETCH_ASSOC) : [];

        $spaceMap = [];
        $spaceMeta = [];
        foreach ($spaces as $s) {
            $spaceMap[$s['slug']] = (int) $s['id'];
            $spaceMeta[(int) $s['id']] = $s;
        }

        $generalDocsId = $spaceMap[SpaceSchema::DEFAULT_SPACE_GENERAL_DOCS] ?? 0;
        if ($generalDocsId <= 0) {
            throw new \RuntimeException("Canonical space '" . SpaceSchema::DEFAULT_SPACE_GENERAL_DOCS . "' could not be found.");
        }

        $now = date('Y-m-d H:i:s');
        $migratedPages = [];
        $migratedPosts = [];
        $affectedSpaceIds = [$generalDocsId];

        // 3. Migrate Unassigned Pages (soi_pages)
        $pagesStmt = $pdo->query("SELECT `id`, `title`, `slug` FROM `soi_pages` WHERE `space_id` IS NULL OR `space_id` = 0");
        $unassignedPages = $pagesStmt ? $pagesStmt->fetchAll(\PDO::FETCH_ASSOC) : [];

        $updatePageStmt = $pdo->prepare("UPDATE `soi_pages` SET 
            `space_id` = ?, 
            `section_id` = COALESCE(`section_id`, 0),
            `doc_version` = COALESCE(`doc_version`, 'v1.0')
            WHERE `id` = ?");

        $docTable = SpaceSchema::TABLE_DOCUMENTS;
        $checkDocStmt = $pdo->prepare("SELECT `id` FROM `{$docTable}` WHERE `space_id` = ? AND `document_id` = ? AND `document_type` = 'page'");
        $insertDocStmt = $pdo->prepare("INSERT INTO `{$docTable}` (
            `space_id`, `section_id`, `document_id`, `document_type`, `sort_order`, `created_at`
        ) VALUES (?, 0, ?, 'page', ?, ?)");

        foreach ($unassignedPages as $page) {
            $pageId = (int) $page['id'];
            $sortOrder = (int) ($page['sort_order'] ?? 0);

            // Update page record safely (preserves title, slug, content, body_json)
            $updatePageStmt->execute([$generalDocsId, $pageId]);

            // Sync into soi_space_documents
            $checkDocStmt->execute([$generalDocsId, $pageId]);
            if (!$checkDocStmt->fetch()) {
                $insertDocStmt->execute([$generalDocsId, $pageId, $sortOrder, $now]);
            }

            $migratedPages[] = [
                'id'         => $pageId,
                'title'      => (string) $page['title'],
                'slug'       => (string) $page['slug'],
                'space_id'   => $generalDocsId,
                'space_slug' => SpaceSchema::DEFAULT_SPACE_GENERAL_DOCS,
            ];
        }

        // 4. Migrate Unassigned Posts (soi_posts)
        $postsStmt = $pdo->query("SELECT `id`, `title`, `slug` FROM `soi_posts` WHERE `space_id` IS NULL OR `space_id` = 0");
        $unassignedPosts = $postsStmt ? $postsStmt->fetchAll(\PDO::FETCH_ASSOC) : [];

        $updatePostStmt = $pdo->prepare("UPDATE `soi_posts` SET 
            `space_id` = ?, 
            `section_id` = COALESCE(`section_id`, 0),
            `doc_version` = COALESCE(`doc_version`, 'v1.0')
            WHERE `id` = ?");

        $checkPostDocStmt = $pdo->prepare("SELECT `id` FROM `{$docTable}` WHERE `space_id` = ? AND `document_id` = ? AND `document_type` = 'post'");
        $insertPostDocStmt = $pdo->prepare("INSERT INTO `{$docTable}` (
            `space_id`, `section_id`, `document_id`, `document_type`, `sort_order`, `created_at`
        ) VALUES (?, 0, ?, 'post', 0, ?)");

        foreach ($unassignedPosts as $post) {
            $postId = (int) $post['id'];
            $matchedInfo = [];
            $targetSpaceId = $this->resolvePostSpace($postId, $spaceMap, $generalDocsId, $matchedInfo);

            $affectedSpaceIds[] = $targetSpaceId;

            // Update post record safely (preserves title, slug, content, body_json)
            $updatePostStmt->execute([$targetSpaceId, $postId]);

            // Sync into soi_space_documents
            $checkPostDocStmt->execute([$targetSpaceId, $postId]);
            if (!$checkPostDocStmt->fetch()) {
                $insertPostDocStmt->execute([$targetSpaceId, $postId, $now]);
            }

            $migratedPosts[] = [
                'id'               => $postId,
                'title'            => (string) $post['title'],
                'slug'             => (string) $post['slug'],
                'space_id'         => $targetSpaceId,
                'space_slug'       => $matchedInfo['space_slug'] ?? SpaceSchema::DEFAULT_SPACE_GENERAL_DOCS,
                'matched_category' => $matchedInfo['category_name'] ?? null,
            ];
        }

        // 5. Invalidate caches for all affected spaces
        $uniqueSpaceIds = array_unique($affectedSpaceIds);
        foreach ($uniqueSpaceIds as $sid) {
            $spaceRecord = $spaceMeta[$sid] ?? null;
            Cache::delete("space:{$sid}");
            if ($spaceRecord && !empty($spaceRecord['slug'])) {
                Cache::delete("space:{$spaceRecord['slug']}");
            }
            Cache::delete("taxonomy:tree:{$sid}:0");
            Cache::delete("taxonomy:tree:{$sid}:1");
        }

        return [
            'ok'              => true,
            'pages_migrated'  => count($migratedPages),
            'posts_migrated'  => count($migratedPosts),
            'total_migrated'  => count($migratedPages) + count($migratedPosts),
            'canonical_space' => SpaceSchema::DEFAULT_SPACE_GENERAL_DOCS,
            'pages'           => $migratedPages,
            'posts'           => $migratedPosts,
        ];
    }

    /**
     * Resolve target space for a post based on its assigned categories.
     *
     * Category mappings:
     * - HR / Human Resources / Policy / Benefits / Employee -> hr-library
     * - IT / Tech / Infrastructure / Hardware / Support     -> it-library
     * - Accounts / Finance / Billing / Invoicing            -> accounts-directory
     * - HRMS / Payroll / Core HR                            -> hrms
     * - Fallback                                            -> general-docs
     *
     * @param int $postId
     * @param array<string, int> $spaceMap
     * @param int $fallbackSpaceId
     * @param array<string, mixed> &$matchedInfo
     * @return int Resolved Space ID
     */
    public function resolvePostSpace(int $postId, array $spaceMap, int $fallbackSpaceId, array &$matchedInfo = []): int
    {
        $pdo = $this->getPdo();

        // Check if soi_post_categories and soi_categories exist
        try {
            $catStmt = $pdo->prepare("SELECT c.`id`, c.`name`, c.`slug` 
                FROM `soi_categories` c 
                INNER JOIN `soi_post_categories` pc ON pc.`category_id` = c.`id` 
                WHERE pc.`post_id` = ?");
            $catStmt->execute([$postId]);
            $categories = $catStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $categories = [];
        }

        foreach ($categories as $cat) {
            $name = strtolower(trim((string) ($cat['name'] ?? '')));
            $slug = strtolower(trim((string) ($cat['slug'] ?? '')));

            // 1. HRMS (check before HR to avoid substring collision)
            if (
                str_contains($slug, 'hrms') || str_contains($name, 'hrms') ||
                str_contains($slug, 'payroll') || str_contains($name, 'payroll') ||
                str_contains($slug, 'core-hr') || str_contains($name, 'core hr')
            ) {
                if (isset($spaceMap[SpaceSchema::DEFAULT_SPACE_HRMS])) {
                    $matchedInfo = [
                        'category_name' => (string) $cat['name'],
                        'space_slug'    => SpaceSchema::DEFAULT_SPACE_HRMS,
                    ];
                    return $spaceMap[SpaceSchema::DEFAULT_SPACE_HRMS];
                }
            }

            // 2. HR Library (exclude 'hrms')
            if (
                !str_contains($slug, 'hrms') && !str_contains($name, 'hrms') &&
                (
                    preg_match('/\bhr\b/i', $name) || preg_match('/\bhr\b/i', $slug) ||
                    str_contains($slug, 'human-resource') || str_contains($name, 'human resource') ||
                    str_contains($slug, 'benefit') || str_contains($name, 'benefit') ||
                    str_contains($slug, 'policy') || str_contains($name, 'policy') ||
                    str_contains($slug, 'employee') || str_contains($name, 'employee') ||
                    str_contains($slug, 'perk') || str_contains($name, 'perk') ||
                    str_contains($slug, 'leave') || str_contains($name, 'leave')
                )
            ) {
                if (isset($spaceMap[SpaceSchema::DEFAULT_SPACE_HR_LIBRARY])) {
                    $matchedInfo = [
                        'category_name' => (string) $cat['name'],
                        'space_slug'    => SpaceSchema::DEFAULT_SPACE_HR_LIBRARY,
                    ];
                    return $spaceMap[SpaceSchema::DEFAULT_SPACE_HR_LIBRARY];
                }
            }

            // 3. IT Library (use \bit\b or word boundary so words like 'benefits' or 'security' do not match 'it')
            if (
                preg_match('/\bit\b/i', $name) || preg_match('/\bit\b/i', $slug) ||
                str_contains($slug, 'tech') || str_contains($name, 'tech') ||
                str_contains($slug, 'hardware') || str_contains($name, 'hardware') ||
                str_contains($slug, 'software') || str_contains($name, 'software') ||
                str_contains($slug, 'infra') || str_contains($name, 'infra') ||
                str_contains($slug, 'support') || str_contains($name, 'support') ||
                str_contains($slug, 'network') || str_contains($name, 'network') ||
                str_contains($slug, 'vpn') || str_contains($name, 'vpn')
            ) {
                if (isset($spaceMap[SpaceSchema::DEFAULT_SPACE_IT_LIBRARY])) {
                    $matchedInfo = [
                        'category_name' => (string) $cat['name'],
                        'space_slug'    => SpaceSchema::DEFAULT_SPACE_IT_LIBRARY,
                    ];
                    return $spaceMap[SpaceSchema::DEFAULT_SPACE_IT_LIBRARY];
                }
            }

            // 4. Accounts Directory
            if (
                str_contains($slug, 'account') || str_contains($name, 'account') ||
                str_contains($slug, 'finance') || str_contains($name, 'finance') ||
                str_contains($slug, 'billing') || str_contains($name, 'billing') ||
                str_contains($slug, 'invoice') || str_contains($name, 'invoice') ||
                str_contains($slug, 'expense') || str_contains($name, 'expense')
            ) {
                if (isset($spaceMap[SpaceSchema::DEFAULT_SPACE_ACCOUNTS_DIR])) {
                    $matchedInfo = [
                        'category_name' => (string) $cat['name'],
                        'space_slug'    => SpaceSchema::DEFAULT_SPACE_ACCOUNTS_DIR,
                    ];
                    return $spaceMap[SpaceSchema::DEFAULT_SPACE_ACCOUNTS_DIR];
                }
            }

            // Direct space slug match
            if (isset($spaceMap[$slug])) {
                $matchedInfo = [
                    'category_name' => (string) $cat['name'],
                    'space_slug'    => $slug,
                ];
                return $spaceMap[$slug];
            }
        }

        // Fallback: General Docs
        $matchedInfo = [
            'category_name' => null,
            'space_slug'    => SpaceSchema::DEFAULT_SPACE_GENERAL_DOCS,
        ];

        return $fallbackSpaceId;
    }

    /**
     * Get counts of unassigned pages and posts.
     *
     * @return array{pages: int, posts: int, total: int}
     */
    public function getUnassignedCounts(): array
    {
        SpaceSchema::ensure($this->pdo);
        SpaceSchema::ensureDocumentTables($this->pdo);

        $pdo = $this->getPdo();

        $pageCount = 0;
        $postCount = 0;

        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM `soi_pages` WHERE `space_id` IS NULL OR `space_id` = 0");
            $pageCount = (int) ($stmt ? $stmt->fetchColumn() : 0);
        } catch (\Throwable $e) {
            $pageCount = 0;
        }

        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM `soi_posts` WHERE `space_id` IS NULL OR `space_id` = 0");
            $postCount = (int) ($stmt ? $stmt->fetchColumn() : 0);
        } catch (\Throwable $e) {
            $postCount = 0;
        }

        return [
            'pages' => $pageCount,
            'posts' => $postCount,
            'total' => $pageCount + $postCount,
        ];
    }

    /**
     * Assign a document (page or post) to a space and section.
     *
     * @param string $documentType 'page' or 'post'
     * @param int $documentId
     * @param int $spaceId
     * @param int $sectionId
     * @return bool
     */
    public function assignDocument(string $documentType, int $documentId, int $spaceId, int $sectionId = 0): bool
    {
        SpaceSchema::ensure($this->pdo);
        SpaceSchema::ensureDocumentTables($this->pdo);

        if ($documentId <= 0 || $spaceId <= 0) {
            throw new \InvalidArgumentException('Valid documentId and spaceId are required.');
        }

        $documentType = strtolower(trim($documentType)) === 'post' ? 'post' : 'page';
        $table = $documentType === 'post' ? 'soi_posts' : 'soi_pages';

        $pdo = $this->getPdo();

        // 1. Update the document record
        $stmt = $pdo->prepare("UPDATE `{$table}` SET `space_id` = ?, `section_id` = ? WHERE `id` = ?");
        $stmt->execute([$spaceId, $sectionId, $documentId]);

        // 2. Sync soi_space_documents
        $docTable = SpaceSchema::TABLE_DOCUMENTS;
        $checkStmt = $pdo->prepare("SELECT `id` FROM `{$docTable}` WHERE `document_id` = ? AND `document_type` = ?");
        $checkStmt->execute([$documentId, $documentType]);
        $existing = $checkStmt->fetch(\PDO::FETCH_ASSOC);

        if ($existing) {
            $upDoc = $pdo->prepare("UPDATE `{$docTable}` SET `space_id` = ?, `section_id` = ? WHERE `id` = ?");
            $upDoc->execute([$spaceId, $sectionId, (int) $existing['id']]);
        } else {
            $inDoc = $pdo->prepare("INSERT INTO `{$docTable}` (`space_id`, `section_id`, `document_id`, `document_type`, `sort_order`, `created_at`) VALUES (?, ?, ?, ?, 0, ?)");
            $inDoc->execute([$spaceId, $sectionId, $documentId, $documentType, date('Y-m-d H:i:s')]);
        }

        Cache::delete("space:{$spaceId}");
        Cache::delete("taxonomy:tree:{$spaceId}:0");

        return true;
    }

    /**
     * Unassign a document from its space.
     *
     * @param string $documentType 'page' or 'post'
     * @param int $documentId
     * @return bool
     */
    public function unassignDocument(string $documentType, int $documentId): bool
    {
        SpaceSchema::ensure($this->pdo);
        SpaceSchema::ensureDocumentTables($this->pdo);

        if ($documentId <= 0) {
            return false;
        }

        $documentType = strtolower(trim($documentType)) === 'post' ? 'post' : 'page';
        $table = $documentType === 'post' ? 'soi_posts' : 'soi_pages';

        $pdo = $this->getPdo();

        // Find old space
        $getStmt = $pdo->prepare("SELECT `space_id` FROM `{$table}` WHERE `id` = ?");
        $getStmt->execute([$documentId]);
        $oldSpaceId = (int) ($getStmt->fetchColumn() ?: 0);

        // Update document
        $stmt = $pdo->prepare("UPDATE `{$table}` SET `space_id` = 0, `section_id` = 0 WHERE `id` = ?");
        $stmt->execute([$documentId]);

        // Remove from soi_space_documents
        $docTable = SpaceSchema::TABLE_DOCUMENTS;
        $delStmt = $pdo->prepare("DELETE FROM `{$docTable}` WHERE `document_id` = ? AND `document_type` = ?");
        $delStmt->execute([$documentId, $documentType]);

        if ($oldSpaceId > 0) {
            Cache::delete("space:{$oldSpaceId}");
            Cache::delete("taxonomy:tree:{$oldSpaceId}:0");
        }

        return true;
    }

    /**
     * Retrieve the space assignment and context for a document.
     *
     * @param string $documentType 'page' or 'post'
     * @param int $documentId
     * @return array<string, mixed>|null
     */
    public function getDocumentSpace(string $documentType, int $documentId): ?array
    {
        SpaceSchema::ensure($this->pdo);
        SpaceSchema::ensureDocumentTables($this->pdo);

        if ($documentId <= 0) {
            return null;
        }

        $documentType = strtolower(trim($documentType)) === 'post' ? 'post' : 'page';
        $table = $documentType === 'post' ? 'soi_posts' : 'soi_pages';

        $pdo = $this->getPdo();
        $stmt = $pdo->prepare("SELECT `space_id`, `section_id`, `doc_version` FROM `{$table}` WHERE `id` = ?");
        $stmt->execute([$documentId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row || (int) ($row['space_id'] ?? 0) <= 0) {
            return null;
        }

        $spaceId = (int) $row['space_id'];
        $spaceTable = SpaceSchema::TABLE;
        $sStmt = $pdo->prepare("SELECT * FROM `{$spaceTable}` WHERE `id` = ?");
        $sStmt->execute([$spaceId]);
        $space = $sStmt->fetch(\PDO::FETCH_ASSOC);

        if (!$space) {
            return null;
        }

        return [
            'space_id'    => $spaceId,
            'space_title' => (string) $space['title'],
            'space_slug'  => (string) $space['slug'],
            'space_type'  => (string) $space['type'],
            'section_id'  => (int) ($row['section_id'] ?? 0),
            'doc_version' => (string) ($row['doc_version'] ?? 'v1.0'),
        ];
    }

    public function getDocumentSpaceContext(string $documentType, int $documentId): ?array
    {
        return $this->getDocumentSpace($documentType, $documentId);
    }

    /**
     * Fetch all release versions defined for a technical product (space).
     *
     * @param int $spaceId
     * @return array<int, array<string, mixed>>
     */
    public function getSpaceVersions(int $spaceId): array
    {
        if ($spaceId <= 0) {
            return [];
        }

        $pdo = $this->getPdo();
        $table = SpaceSchema::TABLE_TECH_VERSIONS;

        try {
            $stmt = $pdo->prepare("
                SELECT
                    `version_tag`,
                    `version_name`,
                    `is_latest`,
                    `is_deprecated`,
                    `release_date`
                FROM `{$table}`
                WHERE `space_id` = ?
                ORDER BY `release_date` DESC, `id` DESC
            ");
            $stmt->execute([$spaceId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    // --- Static Public Reader Shell Resolvers (KS-06) ---

    public static function findSpace(string $type, string $slug = '', ?\PDO $pdo = null): ?array
    {
        SpaceSchema::ensure($pdo);
        $activePdo = $pdo ?? (Database::isConnected() ? Database::pdo() : null);
        if ($activePdo === null) return null;

        $type = SpaceSchema::normalizeType($type);
        $slug = strtolower(trim($slug));
        $table = SpaceSchema::TABLE;

        if ($type === 'generaldocs' || $type === 'docs' || $slug === 'docs') {
            $stmt = $activePdo->prepare("SELECT * FROM `{$table}` WHERE `type` = 'generaldocs' OR `slug` = 'general-docs' OR `slug` = 'docs' ORDER BY `sortorder` ASC, `id` ASC LIMIT 1");
            $stmt->execute();
            return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        }

        $stmt = $activePdo->prepare("SELECT * FROM `{$table}` WHERE `slug` = ? LIMIT 1");
        $stmt->execute([$slug]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public static function findSectionBySlug(int $spaceId, string $sectionSlug, ?\PDO $pdo = null): ?array
    {
        SpaceSchema::ensure($pdo);
        $activePdo = $pdo ?? (Database::isConnected() ? Database::pdo() : null);
        if ($activePdo === null) return null;

        $secTable = SpaceSchema::TABLE_SECTIONS;
        $stmt = $activePdo->prepare("SELECT * FROM `{$secTable}` WHERE `space_id` = ? AND `slug` = ? LIMIT 1");
        $stmt->execute([$spaceId, strtolower(trim($sectionSlug))]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public static function findDocumentBySlugAndSpace(string $slug, int $spaceId, ?string $sectionSlug = null, ?\PDO $pdo = null): ?array
    {
        SpaceSchema::ensure($pdo);
        $activePdo = $pdo ?? (Database::isConnected() ? Database::pdo() : null);
        if ($activePdo === null) return null;

        $slug = strtolower(trim($slug));
        $stmt = $activePdo->prepare("SELECT * FROM `soi_pages` WHERE `slug` = ? AND `space_id` = ? AND `status` = 'published' LIMIT 1");
        $stmt->execute([$slug, $spaceId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row) return $row;

        $stmt = $activePdo->prepare("SELECT * FROM `soi_posts` WHERE `slug` = ? AND `space_id` = ? AND `status` = 'published' LIMIT 1");
        $stmt->execute([$slug, $spaceId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public static function findLandingDocument(int $spaceId, ?\PDO $pdo = null): ?array
    {
        SpaceSchema::ensure($pdo);
        $activePdo = $pdo ?? (Database::isConnected() ? Database::pdo() : null);
        if ($activePdo === null) return null;

        $stmt = $activePdo->prepare("SELECT * FROM `soi_pages` WHERE `space_id` = ? AND `status` = 'published' ORDER BY `id` ASC LIMIT 1");
        $stmt->execute([$spaceId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row) return $row;

        $stmt = $activePdo->prepare("SELECT * FROM `soi_posts` WHERE `space_id` = ? AND `status` = 'published' ORDER BY `id` ASC LIMIT 1");
        $stmt->execute([$spaceId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public static function buildBreadcrumbs(array $space, ?array $section = null, ?array $document = null): array
    {
        $breadcrumbs = [];
        $spaceSlug = (string)($space['slug'] ?? '');
        $spaceTitle = (string)($space['title'] ?? ucfirst($spaceSlug));
        $spaceType = (string)($space['type'] ?? 'generaldocs');

        $spaceUrl = ($spaceType === 'tech') ? '/tech/' . $spaceSlug : (($spaceType === 'libraries') ? '/library/' . $spaceSlug : '/docs/' . $spaceSlug);
        $breadcrumbs[] = [
            'label' => $spaceTitle,
            'url' => ($document !== null) ? $spaceUrl : null
        ];

        if ($section !== null && !empty($section['title'])) {
            $breadcrumbs[] = [
                'label' => (string)$section['title'],
                'url' => null
            ];
        }

        if ($document !== null && !empty($document['title'])) {
            $breadcrumbs[] = [
                'label' => (string)$document['title'],
                'url' => null
            ];
        }

        return $breadcrumbs;
    }
}
