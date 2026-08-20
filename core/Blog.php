<?php
namespace SOI\Core;

/**
 * Blog feature flag — gates blog UI/routes without removing blog data or files.
 */
class Blog {
    private static bool $migrated = false;

    public static function isEnabled(): bool {
        self::ensureMigrated();
        return Database::getOption('blog_enabled', '0') === '1';
    }

    /**
     * One-time migration: existing sites with blog content keep blog enabled.
     */
    public static function ensureMigrated(): void {
        if (self::$migrated) {
            return;
        }
        self::$migrated = true;

        $table = Database::prefix('options');
        $row = Database::selectOne(
            "SELECT option_value FROM `$table` WHERE option_key = 'blog_enabled' LIMIT 1"
        );
        if ($row !== null) {
            return;
        }

        $enabled = '0';
        if (Database::tableExists('posts') && Database::count('posts') > 0) {
            $enabled = '1';
        } elseif (Database::tableExists('categories') && Database::count('categories') > 0) {
            $enabled = '1';
        }

        Database::setOption('blog_enabled', $enabled);
    }
}