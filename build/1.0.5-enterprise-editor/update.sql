ALTER TABLE `soi_pages` ADD COLUMN `body_json` longtext NULL
;
ALTER TABLE `soi_pages` ADD COLUMN `editor_format` varchar(20) NOT NULL DEFAULT 'legacy'
;
ALTER TABLE `soi_pages` ADD COLUMN `schema_version` smallint UNSIGNED NOT NULL DEFAULT 0
;
ALTER TABLE `soi_posts` ADD COLUMN `body_json` longtext NULL
;
ALTER TABLE `soi_posts` ADD COLUMN `editor_format` varchar(20) NOT NULL DEFAULT 'legacy'
;
ALTER TABLE `soi_posts` ADD COLUMN `schema_version` smallint UNSIGNED NOT NULL DEFAULT 0
;
