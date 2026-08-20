<?php
declare(strict_types=1);

namespace SOI\Core\Content;

use SOI\Core\Database;

/**
 * Adds the minimum columns needed for structured documents.
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
        }
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
}
