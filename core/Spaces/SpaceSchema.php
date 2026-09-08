<?php
declare(strict_types=1);

namespace SOI\Core\Spaces;

use SOI\Core\Database;

/**
 * SpaceSchema
 *
 * Schema and DDL definitions for Knowledge Spaces (`soispaces` / `soi_spaces`).
 */
final class SpaceSchema
{
    public const TABLE = 'soispaces';
    public const TABLE_NAME = 'spaces';
    public const TABLE_SPACES = 'soi_spaces';
    public const TABLE_SECTIONS = 'soi_space_sections';
    public const TABLE_DOCUMENTS = 'soi_space_documents';
    public const TABLE_TECH_VERSIONS = 'soi_space_tech_versions';

    // Type Enum Constants
    public const TYPE_GENERALDOCS = 'generaldocs';
    public const TYPE_GENERAL_DOCS = 'generaldocs';
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

    /**
     * Return canonical standard initial spaces.
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
            ],
            [
                'slug'        => self::DEFAULT_SPACE_HRMS,
                'title'       => 'HRMS',
                'type'        => self::TYPE_TECH,
                'status'      => self::STATUS_PUBLISHED,
                'visibility'  => self::VISIBILITY_PUBLIC,
                'sortorder'   => 50,
                'description' => 'Human Resource Management System documentation and API reference.',
                'icon'        => '⚙️',
            ],
        ];
    }

    // Status Enum Constants
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_PUBLISHED,
        self::STATUS_ACTIVE,
        self::STATUS_DRAFT,
        self::STATUS_ARCHIVED,
    ];

    public const VALID_STATUSES = self::STATUSES;

    // Visibility Enum Constants
    public const VISIBILITY_PUBLIC = 'public';
    public const VISIBILITY_AUTHENTICATED = 'authenticated';
    public const VISIBILITY_INTERNAL = 'internal';
    public const VISIBILITY_RESTRICTED = 'restricted';
    public const VISIBILITY_PRIVATE = 'private';

    public const VISIBILITIES = [
        self::VISIBILITY_PUBLIC,
        self::VISIBILITY_AUTHENTICATED,
        self::VISIBILITY_INTERNAL,
        self::VISIBILITY_RESTRICTED,
        self::VISIBILITY_PRIVATE,
    ];

    public const VALID_VISIBILITIES = self::VISIBILITIES;

    private const TYPE_ALIASES = [
        'general_docs' => self::TYPE_GENERALDOCS,
        'docs'         => self::TYPE_GENERALDOCS,
        'general'      => self::TYPE_GENERALDOCS,
        'generaldocs'  => self::TYPE_GENERALDOCS,
        'library'      => self::TYPE_LIBRARIES,
        'libraries'    => self::TYPE_LIBRARIES,
        'tech'         => self::TYPE_TECH,
        'technical'    => self::TYPE_TECH,
    ];

    private static bool $ensured = false;

    /**
     * Check if a type is a valid space enum type.
     */
    public static function isValidType(string $type): bool
    {
        $normalized = self::normalizeType($type);
        return in_array($normalized, self::TYPES, true);
    }

    /**
     * Normalize space type string to canonical enum value.
     */
    public static function normalizeType(string $type): string
    {
        $normalized = strtolower(trim($type));
        return self::TYPE_ALIASES[$normalized] ?? $normalized;
    }

    /**
     * Check if a status is valid.
     */
    public static function isValidStatus(string $status): bool
    {
        return in_array(strtolower(trim($status)), self::STATUSES, true);
    }

    /**
     * Check if a visibility setting is valid.
     */
    public static function isValidVisibility(string $visibility): bool
    {
        return in_array(strtolower(trim($visibility)), self::VISIBILITIES, true);
    }

    public static function ensureDocumentTables(?\PDO $pdo = null): void
    {
        self::ensure($pdo);
    }

    /**
     * Return schema column names and definitions.
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
            'sort_order'  => 'int(11) NOT NULL DEFAULT 0',
            'description' => 'text DEFAULT NULL',
            'icon'        => 'varchar(100) DEFAULT NULL',
            'audience_policy' => 'longtext DEFAULT NULL',
            'settings'    => 'longtext DEFAULT NULL',
            'created_at'  => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at'  => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ];
    }

    /**
     * Ensure the space tables and required indexes exist.
     * Supports both MySQL and SQLite test environments.
     */
    public static function ensure(?\PDO $pdo = null): void
    {
        if (self::$ensured && $pdo === null) {
            return;
        }

        $activePdo = $pdo ?? (Database::isConnected() ? Database::pdo() : null);
        if ($activePdo === null) {
            return;
        }

        $driver = $activePdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $activePdo->exec("CREATE TABLE IF NOT EXISTS `soispaces` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `title` TEXT NOT NULL,
                `slug` TEXT NOT NULL UNIQUE,
                `type` TEXT NOT NULL DEFAULT 'generaldocs',
                `status` TEXT NOT NULL DEFAULT 'published',
                `visibility` TEXT NOT NULL DEFAULT 'public',
                `sortorder` INTEGER NOT NULL DEFAULT 0,
                `sort_order` INTEGER NOT NULL DEFAULT 0,
                `description` TEXT NULL,
                `icon` TEXT NULL,
                `audience_policy` TEXT NULL,
                `settings` TEXT NULL,
                `created_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            );");
            $activePdo->exec("CREATE TABLE IF NOT EXISTS `soi_spaces` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `title` TEXT NOT NULL,
                `slug` TEXT NOT NULL UNIQUE,
                `type` TEXT NOT NULL DEFAULT 'generaldocs',
                `status` TEXT NOT NULL DEFAULT 'published',
                `visibility` TEXT NOT NULL DEFAULT 'public',
                `sortorder` INTEGER NOT NULL DEFAULT 0,
                `sort_order` INTEGER NOT NULL DEFAULT 0,
                `description` TEXT NULL,
                `icon` TEXT NULL,
                `audience_policy` TEXT NULL,
                `settings` TEXT NULL,
                `created_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            );");
            $activePdo->exec("CREATE TABLE IF NOT EXISTS `soi_space_sections` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `space_id` INTEGER NOT NULL,
                `parent_id` INTEGER NOT NULL DEFAULT 0,
                `title` TEXT NOT NULL,
                `slug` TEXT NOT NULL,
                `sort_order` INTEGER NOT NULL DEFAULT 0,
                `description` TEXT NULL,
                `icon` TEXT NULL,
                `created_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            );");
            $activePdo->exec("CREATE TABLE IF NOT EXISTS `soi_space_documents` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `space_id` INTEGER NOT NULL,
                `section_id` INTEGER NOT NULL DEFAULT 0,
                `document_type` TEXT NOT NULL DEFAULT 'page',
                `document_id` INTEGER NOT NULL,
                `sort_order` INTEGER NOT NULL DEFAULT 0,
                `is_primary` INTEGER NOT NULL DEFAULT 0,
                `created_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            );");
            $activePdo->exec("CREATE TABLE IF NOT EXISTS `soi_space_tech_versions` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `space_id` INTEGER NOT NULL,
                `version_tag` TEXT NOT NULL,
                `version_name` TEXT NULL,
                `is_latest` INTEGER NOT NULL DEFAULT 0,
                `is_deprecated` INTEGER NOT NULL DEFAULT 0,
                `release_date` TEXT NULL,
                `created_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            );");
            $activePdo->exec("CREATE INDEX IF NOT EXISTS `idx_soispaces_type` ON `soispaces` (`type`);");
            $activePdo->exec("CREATE INDEX IF NOT EXISTS `idx_soispaces_sort_title` ON `soispaces` (`sortorder`, `title`);");
            $activePdo->exec("CREATE INDEX IF NOT EXISTS `idx_soi_sections_space` ON `soi_space_sections` (`space_id`, `parent_id`, `sort_order`);");
        } else {
            // MySQL
            $activePdo->exec("CREATE TABLE IF NOT EXISTS `soispaces` (
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
                `settings` longtext DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_slug` (`slug`),
                KEY `idx_type` (`type`),
                KEY `idx_status` (`status`),
                KEY `idx_visibility` (`visibility`),
                KEY `idx_sort_title` (`sortorder`, `title`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

            $activePdo->exec("CREATE TABLE IF NOT EXISTS `soi_spaces` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `title` varchar(255) NOT NULL,
                `slug` varchar(191) NOT NULL,
                `type` enum('generaldocs','libraries','tech') NOT NULL DEFAULT 'generaldocs',
                `status` varchar(32) NOT NULL DEFAULT 'published',
                `visibility` varchar(32) NOT NULL DEFAULT 'public',
                `sort_order` int(11) NOT NULL DEFAULT 0,
                `sortorder` int(11) NOT NULL DEFAULT 0,
                `description` text DEFAULT NULL,
                `icon` varchar(100) DEFAULT NULL,
                `audience_policy` longtext DEFAULT NULL,
                `settings` longtext DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_slug` (`slug`),
                KEY `idx_type` (`type`),
                KEY `idx_status` (`status`),
                KEY `idx_visibility` (`visibility`),
                KEY `idx_sort_title` (`sortorder`, `title`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        }

        self::$ensured = true;
    }
}
