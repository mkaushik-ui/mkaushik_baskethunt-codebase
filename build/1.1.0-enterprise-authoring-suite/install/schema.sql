CREATE TABLE IF NOT EXISTS `soi_options` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `option_key` varchar(191) NOT NULL,
    `option_value` longtext DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `option_key` (`option_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `soi_users` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `accounts_user_id` varchar(191) DEFAULT NULL,
    `username` varchar(100) NOT NULL,
    `email` varchar(191) NOT NULL,
    `password` varchar(255) DEFAULT NULL,
    `role` enum('admin','editor','author','subscriber') NOT NULL DEFAULT 'subscriber',
    `display_name` varchar(200) DEFAULT NULL,
    `bio` text DEFAULT NULL,
    `avatar` varchar(500) DEFAULT NULL,
    `status` tinyint(1) NOT NULL DEFAULT 1,
    `last_login` datetime DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `username` (`username`),
    UNIQUE KEY `email` (`email`),
    UNIQUE KEY `accounts_user_id` (`accounts_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `soi_pages` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `title` varchar(500) NOT NULL,
    `slug` varchar(191) NOT NULL,
    `content` longtext DEFAULT NULL,
    `body_json` longtext DEFAULT NULL,
    `editor_format` varchar(20) NOT NULL DEFAULT 'legacy',
    `schema_version` smallint UNSIGNED NOT NULL DEFAULT 0,
    `meta_title` varchar(500) DEFAULT NULL,
    `meta_desc` varchar(1000) DEFAULT NULL,
    `status` enum('published','draft','private') NOT NULL DEFAULT 'draft',
    `author_id` int(11) DEFAULT NULL,
    `sort_order` int(11) NOT NULL DEFAULT 0,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `soi_posts` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `title` varchar(500) NOT NULL,
    `slug` varchar(191) NOT NULL,
    `content` longtext DEFAULT NULL,
    `body_json` longtext DEFAULT NULL,
    `editor_format` varchar(20) NOT NULL DEFAULT 'legacy',
    `schema_version` smallint UNSIGNED NOT NULL DEFAULT 0,
    `excerpt` text DEFAULT NULL,
    `featured_image` varchar(500) DEFAULT NULL,
    `meta_title` varchar(500) DEFAULT NULL,
    `meta_desc` varchar(1000) DEFAULT NULL,
    `status` enum('published','draft','private') NOT NULL DEFAULT 'draft',
    `author_id` int(11) DEFAULT NULL,
    `comment_status` enum('open','closed') NOT NULL DEFAULT 'open',
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `soi_categories` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(200) NOT NULL,
    `slug` varchar(191) NOT NULL,
    `description` text DEFAULT NULL,
    `parent_id` int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `soi_post_categories` (
    `post_id` int(11) NOT NULL,
    `category_id` int(11) NOT NULL,
    PRIMARY KEY (`post_id`, `category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `soi_tags` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(200) NOT NULL,
    `slug` varchar(191) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `soi_post_tags` (
    `post_id` int(11) NOT NULL,
    `tag_id` int(11) NOT NULL,
    PRIMARY KEY (`post_id`, `tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `soi_media` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `filename` varchar(500) NOT NULL,
    `original_name` varchar(500) DEFAULT NULL,
    `mime_type` varchar(100) DEFAULT NULL,
    `file_size` bigint(20) DEFAULT NULL,
    `path` varchar(1000) DEFAULT NULL,
    `url` varchar(1000) DEFAULT NULL,
    `alt_text` varchar(500) DEFAULT NULL,
    `uploaded_by` int(11) DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `soi_menus` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(200) NOT NULL,
    `location` varchar(100) DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `soi_menu_items` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `menu_id` int(11) NOT NULL,
    `parent_id` int(11) NOT NULL DEFAULT 0,
    `title` varchar(200) NOT NULL,
    `url` varchar(1000) DEFAULT NULL,
    `type` enum('page','post','custom') NOT NULL DEFAULT 'custom',
    `object_id` int(11) NOT NULL DEFAULT 0,
    `sort_order` int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `soi_plugins` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `slug` varchar(191) NOT NULL,
    `name` varchar(200) NOT NULL,
    `version` varchar(50) DEFAULT NULL,
    `active` tinyint(1) NOT NULL DEFAULT 0,
    `installed_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `soi_comments` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `post_id` int(11) NOT NULL,
    `parent_id` int(11) NOT NULL DEFAULT 0,
    `author_name` varchar(200) NOT NULL,
    `author_email` varchar(191) NOT NULL,
    `content` text NOT NULL,
    `status` enum('pending','approved','spam','trash') NOT NULL DEFAULT 'pending',
    `ip_address` varchar(45) DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `soi_files_service_media_map` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `local_path` varchar(1000) DEFAULT NULL,
    `local_url` varchar(1000) DEFAULT NULL,
    `file_id` varchar(191) DEFAULT NULL,
    `remote_url` varchar(1000) DEFAULT NULL,
    `media_url` varchar(1000) DEFAULT NULL,
    `download_url` varchar(1000) DEFAULT NULL,
    `mime_type` varchar(100) DEFAULT NULL,
    `size_bytes` bigint(20) DEFAULT 0,
    `sha256` char(64) DEFAULT NULL,
    `source_table` varchar(64) DEFAULT NULL,
    `source_column` varchar(64) DEFAULT NULL,
    `source_record_id` int(11) DEFAULT NULL,
    `migration_status` varchar(50) NOT NULL DEFAULT 'pending',
    `last_error` text DEFAULT NULL,
    `previous_file_id` varchar(191) DEFAULT NULL,
    `previous_media_url` varchar(1000) DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_file_id` (`file_id`),
    KEY `idx_sha256` (`sha256`),
    KEY `idx_status` (`migration_status`),
    KEY `idx_source_ref` (`source_table`, `source_column`, `source_record_id`),
    KEY `idx_local_path` (`local_path`(191)),
    KEY `idx_local_url` (`local_url`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `soi_files_service_content_backup` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `table_name` varchar(64) NOT NULL,
    `row_id` int(11) NOT NULL,
    `column_name` varchar(64) NOT NULL,
    `original_value_hash` char(64) NOT NULL,
    `original_value` longtext NOT NULL,
    `replacement_count` int(11) NOT NULL DEFAULT 0,
    `batch_token` varchar(80) NOT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_batch_token` (`batch_token`),
    KEY `idx_content_ref` (`table_name`, `row_id`, `column_name`),
    KEY `idx_original_hash` (`original_value_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

