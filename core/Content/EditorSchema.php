<?php
declare(strict_types=1);

namespace SOI\Core\Content;

use SOI\Core\Database;

/**
 * Ensures schema tables and columns exist for structured documents, revisions, and reusable blocks.
 * Safe to call repeatedly.
 */
final class EditorSchema
{
    public const FORMAT_LEGACY = 'legacy';
    public const FORMAT_STRUCTURED = 'structured';
    public const SCHEMA_VERSION = 1;

    private static bool $ensured = false;

    public static function ensure(): void
    {
        if (self::$ensured) {
            return;
        }
        self::$ensured = true;

        foreach (['pages', 'posts'] as $table) {
            if (!Database::tableExists($table)) {
                continue;
            }
            self::ensureColumn($table, 'body_json', 'longtext NULL');
            self::ensureColumn($table, 'editor_format', "varchar(20) NOT NULL DEFAULT 'legacy'");
            self::ensureColumn($table, 'schema_version', 'smallint UNSIGNED NOT NULL DEFAULT 0');
            self::ensureColumn($table, 'revision_count', 'int UNSIGNED NOT NULL DEFAULT 1');
        }

        self::ensureRevisionsTable();
        self::ensureReusableBlocksTable();
    }

    public static function isStructured(array $record): bool
    {
        $format = (string) ($record['editor_format'] ?? '');
        if ($format === self::FORMAT_STRUCTURED && trim((string) ($record['body_json'] ?? '')) !== '') {
            return true;
        }
        return false;
    }

    public static function isLegacy(array $record): bool
    {
        return !self::isStructured($record);
    }

    private static function ensureColumn(string $table, string $column, string $definition): void
    {
        $prefixed = Database::prefix($table);
        $row = Database::selectOne(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1",
            [$prefixed, $column]
        );
        if (!$row) {
            Database::exec("ALTER TABLE `$prefixed` ADD COLUMN `$column` $definition");
        }
    }

    private static function ensureRevisionsTable(): void
    {
        $table = Database::prefix('document_revisions');
        Database::exec("CREATE TABLE IF NOT EXISTS `$table` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `entity` varchar(32) NOT NULL DEFAULT 'page',
            `document_id` bigint unsigned NOT NULL,
            `revision_num` int unsigned NOT NULL DEFAULT 1,
            `title` varchar(255) NOT NULL DEFAULT '',
            `slug` varchar(255) NOT NULL DEFAULT '',
            `status` varchar(32) NOT NULL DEFAULT 'draft',
            `body_json` longtext NULL,
            `content` longtext NULL,
            `user_id` bigint unsigned NULL,
            `user_name` varchar(128) NOT NULL DEFAULT '',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_entity_doc` (`entity`, `document_id`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private static function ensureReusableBlocksTable(): void
    {
        $table = Database::prefix('kc_reusable_blocks');
        Database::exec("CREATE TABLE IF NOT EXISTS `$table` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `title` varchar(255) NOT NULL DEFAULT '',
            `category` varchar(64) NOT NULL DEFAULT 'general',
            `content_json` longtext NOT NULL,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_title` (`title`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
