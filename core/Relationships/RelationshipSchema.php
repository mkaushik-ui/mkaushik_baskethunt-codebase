<?php
declare(strict_types=1);

namespace SOI\Core\Relationships;

use PDO;

/**
 * Knowledge Graph & Explicit Relationship Schema.
 * Domain: kc.soi.co.in
 *
 * Ensures persistent relationship mapping between documents
 * (parent/child, prerequisites, related articles).
 */
class RelationshipSchema
{
    /**
     * Ensure relationships table exists in the database.
     *
     * @param PDO $pdo Database connection handle
     */
    public static function ensure(PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $isSqlite = $driver === 'sqlite';

        if ($isSqlite) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `soi_document_relationships` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `source_doc_id` INTEGER NOT NULL,
                `target_doc_id` INTEGER NOT NULL,
                `relationship_type` TEXT NOT NULL DEFAULT 'related',
                `sort_order` INTEGER NOT NULL DEFAULT 0,
                `created_at` TEXT DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(`source_doc_id`, `target_doc_id`, `relationship_type`)
            );");
            $pdo->exec("CREATE INDEX IF NOT EXISTS `idx_soi_rel_source` ON `soi_document_relationships` (`source_doc_id`);");
            $pdo->exec("CREATE INDEX IF NOT EXISTS `idx_soi_rel_target` ON `soi_document_relationships` (`target_doc_id`);");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `soi_document_relationships` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `source_doc_id` BIGINT UNSIGNED NOT NULL,
                `target_doc_id` BIGINT UNSIGNED NOT NULL,
                `relationship_type` VARCHAR(50) NOT NULL DEFAULT 'related',
                `sort_order` INT NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_soi_rel` (`source_doc_id`, `target_doc_id`, `relationship_type`),
                KEY `idx_soi_rel_source` (`source_doc_id`),
                KEY `idx_soi_rel_target` (`target_doc_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        }
    }
}
