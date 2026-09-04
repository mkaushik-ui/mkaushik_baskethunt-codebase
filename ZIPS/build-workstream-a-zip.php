<?php
declare(strict_types=1);

/**
 * Build Script for Complete Workstream A Update ZIP Package.
 * Includes all host pages (admin/pages.php, admin/posts.php, cms-manifest.json)
 * to ensure live PHP site instantly reflects Workstream A updates.
 */
$rootDir = dirname(__DIR__);
$zipsDir = $rootDir . '/ZIPS';
if (!is_dir($zipsDir)) {
    mkdir($zipsDir, 0755, true);
}

$zipPath = $zipsDir . '/workstream-a-ux-v1.1.0.zip';
if (file_exists($zipPath)) {
    unlink($zipPath);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die("Failed to create ZIP at {$zipPath}\n");
}

echo "=== Building Complete Workstream A Update Package ===\n";

// 1. Root manifest.json for Update Center
$manifest = [
    "version" => "1.1.0",
    "name" => "Workstream A — Authoring UX Finalization",
    "type" => "core",
    "min_php" => "8.0",
    "description" => "Redesigned 1.1.0 workspace with task-focused ribbon tabs, progressive disclosure, left panel tabs, right contextual inspector, navigator, drag/drop, nested layout UX, dialogs, command palette, local recovery, revisions UX, and subtle legacy converter."
];
$zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// 2. Root update.sql
$updateSql = <<<SQL
CREATE TABLE IF NOT EXISTS `soi_document_revisions` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `document_type` VARCHAR(32) NOT NULL DEFAULT 'post',
  `document_id` BIGINT UNSIGNED NOT NULL,
  `revision_number` INT UNSIGNED NOT NULL DEFAULT 1,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `user_name` VARCHAR(128) DEFAULT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'draft',
  `title` VARCHAR(255) NOT NULL,
  `slug` VARCHAR(255) NOT NULL,
  `content_json` LONGTEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_doc_entity` (`document_type`, `document_id`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `soi_kc_reusable_blocks` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `category` VARCHAR(64) NOT NULL DEFAULT 'General',
  `content_json` LONGTEXT NOT NULL,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_title` (`title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
$zip->addFromString('update.sql', $updateSql);

// 3. CMS Version Manifest
if (file_exists($rootDir . '/cms-manifest.json')) {
    $zip->addFile($rootDir . '/cms-manifest.json', 'cms-manifest.json');
}

// Helper to add files recursively
function addDirectoryToZip(ZipArchive $zip, string $sourceDir, string $localPrefix) {
    if (!is_dir($sourceDir)) return;
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($files as $file) {
        $filePath = $file->getRealPath();
        $relativePath = substr($filePath, strlen($sourceDir) + 1);
        $zipPath = $localPrefix . '/' . str_replace('\\', '/', $relativePath);

        if ($file->isDir()) {
            $zip->addEmptyDir($zipPath);
        } else {
            $zip->addFile($filePath, $zipPath);
        }
    }
}

// 4. Admin Host Pages & Partials
$zip->addFile($rootDir . '/admin/pages.php', 'admin/pages.php');
$zip->addFile($rootDir . '/admin/posts.php', 'admin/posts.php');
$zip->addFile($rootDir . '/admin/partials/structured-editor.php', 'admin/partials/structured-editor.php');

// 5. Admin Assets
addDirectoryToZip($zip, $rootDir . '/admin/assets/editor', 'admin/assets/editor');

// 6. Public Assets & Templates
if (file_exists($rootDir . '/assets/kc-reader.css')) {
    $zip->addFile($rootDir . '/assets/kc-reader.css', 'assets/kc-reader.css');
}
if (file_exists($rootDir . '/assets/kc-reader.js')) {
    $zip->addFile($rootDir . '/assets/kc-reader.js', 'assets/kc-reader.js');
}
if (file_exists($rootDir . '/templates/reader-shell.php')) {
    $zip->addFile($rootDir . '/templates/reader-shell.php', 'templates/reader-shell.php');
}

// 7. Core Engine & Domain Services
addDirectoryToZip($zip, $rootDir . '/core/Content', 'core/Content');
addDirectoryToZip($zip, $rootDir . '/core/Services', 'core/Services');
addDirectoryToZip($zip, $rootDir . '/core/Legacy', 'core/Legacy');
addDirectoryToZip($zip, $rootDir . '/core/Patterns', 'core/Patterns');
addDirectoryToZip($zip, $rootDir . '/core/Reusable', 'core/Reusable');
addDirectoryToZip($zip, $rootDir . '/core/Spaces', 'core/Spaces');
addDirectoryToZip($zip, $rootDir . '/core/Search', 'core/Search');
addDirectoryToZip($zip, $rootDir . '/core/Links', 'core/Links');
addDirectoryToZip($zip, $rootDir . '/core/Relationships', 'core/Relationships');

$zip->close();

if (file_exists($rootDir . '/updates')) {
    copy($zipPath, $rootDir . '/updates/1.1.0-enterprise-authoring-suite.zip');
}

echo "Complete ZIP Package successfully created at: {$zipPath} (" . filesize($zipPath) . " bytes)\n";
