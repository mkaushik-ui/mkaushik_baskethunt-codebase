-- SOI Knowledge Center v1.1.0 Database Migration
-- Table: soi_document_revisions
CREATE TABLE IF NOT EXISTS `soi_document_revisions` (
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
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_entity_doc` (`entity`, `document_id`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: soi_kc_reusable_blocks
CREATE TABLE IF NOT EXISTS `soi_kc_reusable_blocks` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `title` varchar(255) NOT NULL DEFAULT '',
    `category` varchar(64) NOT NULL DEFAULT 'general',
    `content_json` longtext NOT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_title` (`title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
