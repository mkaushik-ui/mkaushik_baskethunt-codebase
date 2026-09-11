<?php
declare(strict_types=1);

namespace SOI\Core\Spaces;

use SOI\Core\Database;

/**
 * Schema and DDL definitions for Knowledge Spaces (`soispaces`).
 */
final class SpaceSchema
{
    public const TABLE = 'soispaces';
    public const TABLE_NAME = 'spaces';
    public const TABLE_ALIAS = 'soi_spaces';
    public const TABLE_SECTIONS = 'soi_space_sections';
    public const TABLE_DOCUMENTS = 'soi_space_documents';
    public const TABLE_TECH_VERSIONS = 'soi_space_tech_versions';

    public const DEFAULT_AUDIENCE_POLICY = '{"visibility":"public","departments":[],"teams":[],"groups":[],"allowed_users":[],"space_owners":[],"inheritance":"inherit"}';

    public const TYPE_GENERALDOCS = 'generaldocs';
    public const TYPE_GENERAL_DOCS = 'general_docs'; // Alias for backward compatibility
    public const TYPE_LIBRARIES = 'libraries';
    public const TYPE_TECH = 'tech';

    public const TYPES = [
        self::TYPE_GENERALDOCS,
        self::TYPE_LIBRARIES,
        self::TYPE_TECH,
    ];
    public const VALID_TYPES = self::TYPES;

    public const DEFAULT_SPACE_GENERAL_DOCS = 'general-docs';
    public const DEFAULT_SPACE_HR_LIBRARY = 'hr-library';
    public const DEFAULT_SPACE_IT_LIBRARY = 'it-library';
    public const DEFAULT_SPACE_ACCOUNTS_DIR = 'accounts-directory';
    public const DEFAULT_SPACE_HRMS = 'hrms';

    public const STATUS_PUBLISHED = 'published';
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_PUBLISHED,
        self::STATUS_DRAFT,
        self::STATUS_ARCHIVED,
    ];
    public const VALID_STATUSES = [
        'published',
        'active',
        'draft',
        'archived',
    ];

    public const VISIBILITY_PUBLIC = 'public';
    public const VISIBILITY_AUTHENTICATED = 'authenticated';
    public const VISIBILITY_RESTRICTED = 'restricted';
    public const VISIBILITY_INTERNAL = 'internal';
    public const VISIBILITY_PRIVATE = 'private';

    public const VISIBILITIES = [
        self::VISIBILITY_PUBLIC,
        self::VISIBILITY_INTERNAL,
        self::VISIBILITY_PRIVATE,
    ];
    public const VALID_VISIBILITIES = [
        'public',
        'authenticated',
        'restricted',
        'internal',
        'private',
    ];

    public const RESERVED_SLUGS = [
        'admin',
        'api',
        'install',
        'themes',
        'assets',
        'static',
        'dashboard',
        'settings',
        'auth',
        'login',
        'logout',
    ];

    private static bool $ensured = false;

    /**
     * Check if a status string is valid.
     */
    public static function isValidStatus(string $status): bool
    {
        $normalized = strtolower(trim($status));
        return in_array($normalized, self::VALID_STATUSES, true);
    }

    /**
     * Check if a visibility string is valid.
     */
    public static function isValidVisibility(string $visibility): bool
    {
        $normalized = strtolower(trim($visibility));
        return in_array($normalized, self::VALID_VISIBILITIES, true);
    }

    /**
     * Normalize a space type string, handling aliases like 'general_docs', 'docs' -> 'generaldocs', 'library' -> 'libraries', 'technical' -> 'tech'.
     */
    public static function normalizeType(string $type): string
    {
        $normalized = strtolower(trim($type));
        return match ($normalized) {
            'general_docs', 'docs', 'generaldocs' => self::TYPE_GENERALDOCS,
            'library', 'libraries'                => self::TYPE_LIBRARIES,
            'technical', 'tech'                   => self::TYPE_TECH,
            default                               => $normalized,
        };
    }

    /**
     * Check if a type is a valid space enum type.
     */
    public static function isValidType(string $type): bool
    {
        $normalized = self::normalizeType($type);
        return in_array($normalized, self::TYPES, true);
    }

    /**
     * Get canonical definitions for standard initial spaces (KS-05).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getInitialSpaces(): array
    {
        return [
            [
                'slug'        => self::DEFAULT_SPACE_GENERAL_DOCS,
                'title'       => 'General Docs',
                'type'        => self::TYPE_GENERALDOCS,
                'status'      => self::STATUS_PUBLISHED,
                'visibility'  => self::VISIBILITY_PUBLIC,
                'sortorder'   => 10,
                'description' => 'General organizational documentation and company knowledge base.',
                'icon'        => '📖',
                'audience_policy' => self::DEFAULT_AUDIENCE_POLICY,
            ],
            [
                'slug'        => self::DEFAULT_SPACE_HR_LIBRARY,
                'title'       => 'HR Library',
                'type'        => self::TYPE_LIBRARIES,
                'status'      => self::STATUS_PUBLISHED,
                'visibility'  => self::VISIBILITY_PUBLIC,
                'sortorder'   => 20,
                'description' => 'Human resources policies, employment benefits, and onboarding guides.',
                'icon'        => '👥',
                'audience_policy' => self::DEFAULT_AUDIENCE_POLICY,
            ],
            [
                'slug'        => self::DEFAULT_SPACE_IT_LIBRARY,
                'title'       => 'IT Library',
                'type'        => self::TYPE_LIBRARIES,
                'status'      => self::STATUS_PUBLISHED,
                'visibility'  => self::VISIBILITY_PUBLIC,
                'sortorder'   => 30,
                'description' => 'Information technology documentation, system access guides, and IT support.',
                'icon'        => '💻',
                'audience_policy' => self::DEFAULT_AUDIENCE_POLICY,
            ],
            [
                'slug'        => self::DEFAULT_SPACE_ACCOUNTS_DIR,
                'title'       => 'Accounts Directory',
                'type'        => self::TYPE_TECH,
                'status'      => self::STATUS_PUBLISHED,
                'visibility'  => self::VISIBILITY_PUBLIC,
                'sortorder'   => 40,
                'description' => 'Financial systems, invoice workflows, and accounting procedures.',
                'icon'        => '📊',
                'audience_policy' => self::DEFAULT_AUDIENCE_POLICY,
            ],
            [
                'slug'        => self::DEFAULT_SPACE_HRMS,
                'title'       => 'HRMS',
                'type'        => self::TYPE_TECH,
                'status'      => self::STATUS_PUBLISHED,
                'visibility'  => self::VISIBILITY_PUBLIC,
                'sortorder'   => 50,
                'description' => 'Human Resource Management System specifications, schemas, and APIs.',
                'icon'        => '⚙️',
                'audience_policy' => self::DEFAULT_AUDIENCE_POLICY,
            ],
        ];
    }

    /**
     * Return schema column names.
     *
     * @return array<string, string>
     */
    public static function getColumns(): array
    {
        return [
            'id'          => 'int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'title'       => 'varchar(255) NOT NULL',
            'slug'        => 'varchar(191) NOT NULL UNIQUE',
            'type'        => "enum('generaldocs','libraries','tech') NOT NULL DEFAULT 'generaldocs'",
            'status'      => "varchar(32) NOT NULL DEFAULT 'published'",
            'visibility'  => "varchar(32) NOT NULL DEFAULT 'public'",
            'sortorder'   => 'int(11) NOT NULL DEFAULT 0',
            'description' => 'text DEFAULT NULL',
            'icon'        => 'varchar(100) DEFAULT NULL',
            'audience_policy' => 'text DEFAULT NULL',
            'created_at'  => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at'  => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ];
    }

    /**
     * Ensure the `soispaces` table and required indexes exist.
     * Supports both MySQL and SQLite test environments.
     */
    public static function ensure(?\PDO $pdo = null): void
    {
        if (self::$ensured && $pdo === null) {
            return;
        }

        $activePdo = $pdo ?? Database::pdo();
        $driver = (string) $activePdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $table = self::TABLE;
        $aliasTable = self::TABLE_ALIAS;

        if ($driver === 'sqlite') {
            // Table: soispaces
            $activePdo->exec("CREATE TABLE IF NOT EXISTS \"{$table}\" (
                \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,
                \"title\" TEXT NOT NULL,
                \"slug\" TEXT NOT NULL UNIQUE,
                \"type\" TEXT NOT NULL DEFAULT 'generaldocs',
                \"status\" TEXT NOT NULL DEFAULT 'published',
                \"visibility\" TEXT NOT NULL DEFAULT 'public',
                \"sortorder\" INTEGER NOT NULL DEFAULT 0,
                \"description\" TEXT DEFAULT NULL,
                \"icon\" TEXT DEFAULT NULL,
                \"audience_policy\" TEXT DEFAULT NULL,
                \"created_at\" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                \"updated_at\" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");

            // Table: soi_spaces (Alias)
            $activePdo->exec("CREATE TABLE IF NOT EXISTS \"{$aliasTable}\" (
                \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,
                \"title\" TEXT NOT NULL,
                \"slug\" TEXT NOT NULL UNIQUE,
                \"type\" TEXT NOT NULL DEFAULT 'generaldocs',
                \"status\" TEXT NOT NULL DEFAULT 'published',
                \"visibility\" TEXT NOT NULL DEFAULT 'public',
                \"sortorder\" INTEGER NOT NULL DEFAULT 0,
                \"description\" TEXT DEFAULT NULL,
                \"icon\" TEXT DEFAULT NULL,
                \"audience_policy\" TEXT DEFAULT NULL,
                \"created_at\" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                \"updated_at\" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");

            // Dynamic Migration Check: Ensure audience_policy column exists on existing tables
            self::ensureColumnExists($activePdo, $table, 'audience_policy', 'TEXT DEFAULT NULL', 'sqlite');
            self::ensureColumnExists($activePdo, $aliasTable, 'audience_policy', 'TEXT DEFAULT NULL', 'sqlite');

            // Indexes on soispaces
            $activePdo->exec("CREATE INDEX IF NOT EXISTS \"idx_soispaces_type\" ON \"{$table}\" (\"type\")");
            $activePdo->exec("CREATE INDEX IF NOT EXISTS \"idx_soispaces_status\" ON \"{$table}\" (\"status\")");
            $activePdo->exec("CREATE INDEX IF NOT EXISTS \"idx_soispaces_visibility\" ON \"{$table}\" (\"visibility\")");
            $activePdo->exec("CREATE INDEX IF NOT EXISTS \"idx_soispaces_vis_status\" ON \"{$table}\" (\"visibility\", \"status\")");
            $activePdo->exec("CREATE INDEX IF NOT EXISTS \"idx_soispaces_sort_title\" ON \"{$table}\" (\"sortorder\", \"title\")");

            // Indexes on soi_spaces
            $activePdo->exec("CREATE INDEX IF NOT EXISTS \"idx_soi_spaces_type\" ON \"{$aliasTable}\" (\"type\")");
            $activePdo->exec("CREATE INDEX IF NOT EXISTS \"idx_soi_spaces_status\" ON \"{$aliasTable}\" (\"status\")");
            $activePdo->exec("CREATE INDEX IF NOT EXISTS \"idx_soi_spaces_visibility\" ON \"{$aliasTable}\" (\"visibility\")");
            $activePdo->exec("CREATE INDEX IF NOT EXISTS \"idx_soi_spaces_vis_status\" ON \"{$aliasTable}\" (\"visibility\", \"status\")");

            // Table: soi_space_sections
            $secTable = self::TABLE_SECTIONS;
            $activePdo->exec("CREATE TABLE IF NOT EXISTS \"{$secTable}\" (
                \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,
                \"space_id\" INTEGER NOT NULL,
                \"parent_id\" INTEGER NOT NULL DEFAULT 0,
                \"title\" TEXT NOT NULL,
                \"slug\" TEXT NOT NULL,
                \"sort_order\" INTEGER NOT NULL DEFAULT 0,
                \"description\" TEXT DEFAULT NULL,
                \"created_at\" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                \"updated_at\" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            $activePdo->exec("CREATE INDEX IF NOT EXISTS \"idx_space_sections_space_parent\" ON \"{$secTable}\" (\"space_id\", \"parent_id\")");
            $activePdo->exec("CREATE INDEX IF NOT EXISTS \"idx_space_sections_slug\" ON \"{$secTable}\" (\"slug\")");
            $activePdo->exec("CREATE INDEX IF NOT EXISTS \"idx_space_sections_sort\" ON \"{$secTable}\" (\"space_id\", \"sort_order\")");

            // Table: soi_space_documents
            $docTable = self::TABLE_DOCUMENTS;
            $activePdo->exec("CREATE TABLE IF NOT EXISTS \"{$docTable}\" (
                \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,
                \"space_id\" INTEGER NOT NULL,
                \"section_id\" INTEGER NOT NULL DEFAULT 0,
                \"document_id\" INTEGER NOT NULL,
                \"document_type\" TEXT NOT NULL DEFAULT 'page',
                \"sort_order\" INTEGER NOT NULL DEFAULT 0,
                \"created_at\" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            $activePdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS \"uniq_space_doc\" ON \"{$docTable}\" (\"space_id\", \"document_id\", \"document_type\")");
            $activePdo->exec("CREATE INDEX IF NOT EXISTS \"idx_space_sec_doc\" ON \"{$docTable}\" (\"space_id\", \"section_id\", \"sort_order\")");

            // Table: soi_space_tech_versions
            $verTable = self::TABLE_TECH_VERSIONS;
            $activePdo->exec("CREATE TABLE IF NOT EXISTS \"{$verTable}\" (
                \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,
                \"space_id\" INTEGER NOT NULL,
                \"version_tag\" TEXT NOT NULL DEFAULT '',
                \"version_name\" TEXT NOT NULL DEFAULT '',
                \"version_slug\" TEXT NOT NULL DEFAULT '',
                \"is_latest\" INTEGER NOT NULL DEFAULT 0,
                \"is_deprecated\" INTEGER NOT NULL DEFAULT 0,
                \"release_date\" TEXT DEFAULT NULL,
                \"sort_order\" INTEGER NOT NULL DEFAULT 0,
                \"created_at\" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            $activePdo->exec("CREATE INDEX IF NOT EXISTS \"idx_tech_versions_space\" ON \"{$verTable}\" (\"space_id\", \"sort_order\")");
        } else {
            // Table: soispaces
            $activePdo->exec("CREATE TABLE IF NOT EXISTS `{$table}` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `title` varchar(255) NOT NULL,
                `slug` varchar(191) NOT NULL,
                `type` enum('generaldocs','libraries','tech') NOT NULL DEFAULT 'generaldocs',
                `status` varchar(32) NOT NULL DEFAULT 'published',
                `visibility` varchar(32) NOT NULL DEFAULT 'public',
                `sortorder` int(11) NOT NULL DEFAULT 0,
                `description` text DEFAULT NULL,
                `icon` varchar(100) DEFAULT NULL,
                `audience_policy` longtext DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_slug` (`slug`),
                KEY `idx_type` (`type`),
                KEY `idx_status` (`status`),
                KEY `idx_visibility` (`visibility`),
                KEY `idx_vis_status` (`visibility`, `status`),
                KEY `idx_sort_title` (`sortorder`, `title`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // Table: soi_spaces (Alias)
            $activePdo->exec("CREATE TABLE IF NOT EXISTS `{$aliasTable}` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `title` varchar(255) NOT NULL,
                `slug` varchar(191) NOT NULL,
                `type` enum('generaldocs','libraries','tech') NOT NULL DEFAULT 'generaldocs',
                `status` varchar(32) NOT NULL DEFAULT 'published',
                `visibility` varchar(32) NOT NULL DEFAULT 'public',
                `sortorder` int(11) NOT NULL DEFAULT 0,
                `description` text DEFAULT NULL,
                `icon` varchar(100) DEFAULT NULL,
                `audience_policy` longtext DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_slug` (`slug`),
                KEY `idx_type` (`type`),
                KEY `idx_status` (`status`),
                KEY `idx_visibility` (`visibility`),
                KEY `idx_vis_status` (`visibility`, `status`),
                KEY `idx_sort_title` (`sortorder`, `title`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // Dynamic Migration Check: Ensure audience_policy column exists on existing tables
            self::ensureColumnExists($activePdo, $table, 'audience_policy', 'longtext DEFAULT NULL', 'mysql');
            self::ensureColumnExists($activePdo, $aliasTable, 'audience_policy', 'longtext DEFAULT NULL', 'mysql');

            // Table: soi_space_sections
            $secTable = self::TABLE_SECTIONS;
            $activePdo->exec("CREATE TABLE IF NOT EXISTS `{$secTable}` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `space_id` int(11) NOT NULL,
                `parent_id` int(11) NOT NULL DEFAULT 0,
                `title` varchar(255) NOT NULL,
                `slug` varchar(191) NOT NULL,
                `sort_order` int(11) NOT NULL DEFAULT 0,
                `description` text DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_space_parent` (`space_id`, `parent_id`),
                KEY `idx_slug` (`slug`),
                KEY `idx_space_sort` (`space_id`, `sort_order`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // Table: soi_space_documents
            $docTable = self::TABLE_DOCUMENTS;
            $activePdo->exec("CREATE TABLE IF NOT EXISTS `{$docTable}` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `space_id` int(11) NOT NULL,
                `section_id` int(11) NOT NULL DEFAULT 0,
                `document_id` int(11) NOT NULL,
                `document_type` varchar(32) NOT NULL DEFAULT 'page',
                `sort_order` int(11) NOT NULL DEFAULT 0,
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_space_doc` (`space_id`, `document_id`, `document_type`),
                KEY `idx_space_sec_doc` (`space_id`, `section_id`, `sort_order`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // Table: soi_space_tech_versions
            $verTable = self::TABLE_TECH_VERSIONS;
            $activePdo->exec("CREATE TABLE IF NOT EXISTS `{$verTable}` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `space_id` int(11) NOT NULL,
                `version_tag` varchar(64) NOT NULL DEFAULT '',
                `version_name` varchar(64) NOT NULL DEFAULT '',
                `version_slug` varchar(64) NOT NULL DEFAULT '',
                `is_latest` tinyint(1) NOT NULL DEFAULT 0,
                `is_deprecated` tinyint(1) NOT NULL DEFAULT 0,
                `release_date` date DEFAULT NULL,
                `sort_order` int(11) NOT NULL DEFAULT 0,
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_tech_versions_space` (`space_id`, `sort_order`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        // Ensure space context & audience policy columns on soi_pages & soi_posts
        self::ensureColumnExists($activePdo, 'soi_pages', 'space_id', 'int(11) NOT NULL DEFAULT 0', $driver);
        self::ensureColumnExists($activePdo, 'soi_pages', 'section_id', 'int(11) NOT NULL DEFAULT 0', $driver);
        self::ensureColumnExists($activePdo, 'soi_pages', 'doc_version', "varchar(32) NOT NULL DEFAULT 'v1.0'", $driver);
        self::ensureColumnExists($activePdo, 'soi_pages', 'audience_policy', 'longtext DEFAULT NULL', $driver);

        self::ensureColumnExists($activePdo, 'soi_posts', 'space_id', 'int(11) NOT NULL DEFAULT 0', $driver);
        self::ensureColumnExists($activePdo, 'soi_posts', 'section_id', 'int(11) NOT NULL DEFAULT 0', $driver);
        self::ensureColumnExists($activePdo, 'soi_posts', 'doc_version', "varchar(32) NOT NULL DEFAULT 'v1.0'", $driver);
        self::ensureColumnExists($activePdo, 'soi_posts', 'audience_policy', 'longtext DEFAULT NULL', $driver);

        if ($pdo === null) {
            self::$ensured = true;
        }
    }

    /**
     * Check if a column exists on a table, and add it via ALTER TABLE if missing.
     */
    private static function ensureColumnExists(\PDO $pdo, string $table, string $column, string $colDef, string $driver): void
    {
        try {
            if ($driver === 'sqlite') {
                $stmt = $pdo->query("PRAGMA table_info(\"{$table}\")");
                if ($stmt) {
                    $cols = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                    $colNames = array_column($cols, 'name');
                    if (!empty($colNames) && !in_array($column, $colNames, true)) {
                        $pdo->exec("ALTER TABLE \"{$table}\" ADD COLUMN \"{$column}\" {$colDef}");
                    }
                }
            } else {
                $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
                if ($stmt && $stmt->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$colDef}");
                }
            }
        } catch (\Throwable) {
            // Silently ignore if table doesn't exist yet
        }
    }

    /**
     * Ensure legacy content tables (soi_pages, soi_posts, soi_categories, soi_post_categories)
     * exist with space context columns (space_id, section_id, doc_version) for migration.
     */
    public static function ensureDocumentTables(?\PDO $pdo = null): void
    {
        $activePdo = $pdo ?? Database::pdo();
        $driver = (string) $activePdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $activePdo->exec("CREATE TABLE IF NOT EXISTS \"soi_pages\" (
                \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,
                \"title\" TEXT NOT NULL,
                \"slug\" TEXT NOT NULL UNIQUE,
                \"content\" TEXT DEFAULT NULL,
                \"body_json\" TEXT DEFAULT NULL,
                \"editor_format\" TEXT NOT NULL DEFAULT 'legacy',
                \"schema_version\" INTEGER NOT NULL DEFAULT 0,
                \"status\" TEXT NOT NULL DEFAULT 'draft',
                \"sort_order\" INTEGER NOT NULL DEFAULT 0,
                \"space_id\" INTEGER DEFAULT NULL,
                \"section_id\" INTEGER DEFAULT 0,
                \"doc_version\" TEXT DEFAULT 'v1.0',
                \"created_at\" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                \"updated_at\" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");

            $activePdo->exec("CREATE TABLE IF NOT EXISTS \"soi_posts\" (
                \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,
                \"title\" TEXT NOT NULL,
                \"slug\" TEXT NOT NULL UNIQUE,
                \"content\" TEXT DEFAULT NULL,
                \"body_json\" TEXT DEFAULT NULL,
                \"editor_format\" TEXT NOT NULL DEFAULT 'legacy',
                \"schema_version\" INTEGER NOT NULL DEFAULT 0,
                \"status\" TEXT NOT NULL DEFAULT 'draft',
                \"space_id\" INTEGER DEFAULT NULL,
                \"section_id\" INTEGER DEFAULT 0,
                \"doc_version\" TEXT DEFAULT 'v1.0',
                \"created_at\" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                \"updated_at\" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");

            $activePdo->exec("CREATE TABLE IF NOT EXISTS \"soi_categories\" (
                \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,
                \"name\" TEXT NOT NULL,
                \"slug\" TEXT NOT NULL UNIQUE,
                \"description\" TEXT DEFAULT NULL,
                \"parent_id\" INTEGER NOT NULL DEFAULT 0
            )");

            $activePdo->exec("CREATE TABLE IF NOT EXISTS \"soi_post_categories\" (
                \"post_id\" INTEGER NOT NULL,
                \"category_id\" INTEGER NOT NULL,
                PRIMARY KEY (\"post_id\", \"category_id\")
            )");
        }
    }
}
