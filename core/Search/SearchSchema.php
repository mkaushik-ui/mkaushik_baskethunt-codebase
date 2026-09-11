<?php
declare(strict_types=1);

namespace SOI\Core\Search;

use PDO;

/**
 * Search Index & Redirect Schema Manager.
 * Domain: kc.soi.co.in
 *
 * Ensures persistent search indexing, token extraction tables,
 * and historical slug redirect tables exist in the database.
 */
class SearchSchema
{
    /**
     * Ensure search index and redirect tables exist in the database.
     *
     * @param PDO $pdo Database connection handle
     */
    public static function ensure(PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $isSqlite = $driver === 'sqlite';

        // 1. Search Index Table
        if ($isSqlite) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `soi_search_index` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `doc_id` INTEGER NOT NULL,
                `space_id` INTEGER NOT NULL DEFAULT 0,
                `space_slug` TEXT NOT NULL,
                `space_name` TEXT NOT NULL,
                `title` TEXT NOT NULL,
                `slug` TEXT NOT NULL,
                `category` TEXT DEFAULT '',
                `status` TEXT NOT NULL DEFAULT 'published',
                `doc_version` TEXT DEFAULT '',
                `headings` TEXT DEFAULT '',
                `extracted_text` TEXT DEFAULT '',
                `raw_tokens` TEXT DEFAULT '',
                `view_url` TEXT NOT NULL,
                `audience_policy` TEXT DEFAULT NULL,
                `updated_at` TEXT DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(`doc_id`)
            );");

            $pdo->exec("CREATE INDEX IF NOT EXISTS `idx_soi_search_slug` ON `soi_search_index` (`slug`);");
            $pdo->exec("CREATE INDEX IF NOT EXISTS `idx_soi_search_space` ON `soi_search_index` (`space_slug`);");
            $pdo->exec("CREATE INDEX IF NOT EXISTS `idx_soi_search_status` ON `soi_search_index` (`status`);");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `soi_search_index` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `doc_id` BIGINT UNSIGNED NOT NULL,
                `space_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `space_slug` VARCHAR(191) NOT NULL,
                `space_name` VARCHAR(255) NOT NULL,
                `title` VARCHAR(255) NOT NULL,
                `slug` VARCHAR(191) NOT NULL,
                `category` VARCHAR(191) DEFAULT '',
                `status` VARCHAR(50) NOT NULL DEFAULT 'published',
                `doc_version` VARCHAR(50) DEFAULT '',
                `headings` MEDIUMTEXT NULL,
                `extracted_text` LONGTEXT NULL,
                `raw_tokens` LONGTEXT NULL,
                `view_url` VARCHAR(255) NOT NULL,
                `audience_policy` LONGTEXT NULL,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_soi_search_doc` (`doc_id`),
                KEY `idx_soi_search_slug` (`slug`),
                KEY `idx_soi_search_space` (`space_slug`),
                KEY `idx_soi_search_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        }

        // 2. Historical Slug Redirects Table
        if ($isSqlite) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `soi_slug_redirects` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `space_id` INTEGER NOT NULL DEFAULT 0,
                `old_slug` TEXT NOT NULL,
                `new_slug` TEXT NOT NULL,
                `old_url` TEXT DEFAULT NULL,
                `target_url` TEXT NOT NULL,
                `http_code` INTEGER NOT NULL DEFAULT 301,
                `created_at` TEXT DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(`old_slug`)
            );");
            $pdo->exec("CREATE INDEX IF NOT EXISTS `idx_soi_redirect_old` ON `soi_slug_redirects` (`old_slug`);");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `soi_slug_redirects` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `space_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `old_slug` VARCHAR(191) NOT NULL,
                `new_slug` VARCHAR(191) NOT NULL,
                `old_url` VARCHAR(255) DEFAULT NULL,
                `target_url` VARCHAR(255) NOT NULL,
                `http_code` INT NOT NULL DEFAULT 301,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_soi_redirect_old` (`old_slug`),
                KEY `idx_soi_redirect_space` (`space_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        }
    }
}
