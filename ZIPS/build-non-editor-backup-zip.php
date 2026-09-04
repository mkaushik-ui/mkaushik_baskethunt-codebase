<?php
declare(strict_types=1);

/**
 * Backup Script for Non-Editor Code.
 * Packages all non-editor application files, Reader Shell, Knowledge Space, and general admin modules.
 */
$rootDir = dirname(__DIR__);
$zipsDir = $rootDir . '/ZIPS';
if (!is_dir($zipsDir)) {
    mkdir($zipsDir, 0755, true);
}

$zipPath = $zipsDir . '/non-editor-code-backup.zip';
if (file_exists($zipPath)) {
    unlink($zipPath);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die("Failed to create ZIP at {$zipPath}\n");
}

echo "=== Packaging Non-Editor Code Backup ===\n";

function addDir(ZipArchive $zip, string $sourceDir, string $localPrefix) {
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

function addSingleFile(ZipArchive $zip, string $filePath, string $localPath) {
    if (file_exists($filePath)) {
        $zip->addFile($filePath, $localPath);
    }
}

// 1. Reader Shell & Public Frontend
addSingleFile($zip, $rootDir . '/assets/kc-reader.css', 'assets/kc-reader.css');
addSingleFile($zip, $rootDir . '/assets/kc-reader.js', 'assets/kc-reader.js');
addDir($zip, $rootDir . '/templates', 'templates');

// 2. Knowledge Spaces, Search, Links, Relationships
addDir($zip, $rootDir . '/core/Spaces', 'core/Spaces');
addDir($zip, $rootDir . '/core/Search', 'core/Search');
addDir($zip, $rootDir . '/core/Links', 'core/Links');
addDir($zip, $rootDir . '/core/Relationships', 'core/Relationships');

// 3. Central Auth & SAML
addSingleFile($zip, $rootDir . '/core/SoiCentralAuth.php', 'core/SoiCentralAuth.php');
addDir($zip, $rootDir . '/saml', 'saml');
addDir($zip, $rootDir . '/soi-central', 'soi-central');

// 4. Non-Editor Admin Modules
$nonEditorAdminFiles = [
    'analytics.php', 'api-keys.php', 'categories.php', 'comments.php',
    'login.php', 'logout.php', 'media.php', 'menus.php', 'options.php',
    'plugins.php', 'profile.php', 'soicentral.php', 'tags.php',
    'update.php', 'users.php'
];
foreach ($nonEditorAdminFiles as $f) {
    addSingleFile($zip, $rootDir . '/admin/' . $f, 'admin/' . $f);
}

// 5. Non-Editor Core Services
$nonEditorCoreFiles = [
    'Accounts.php', 'AdminSearch.php', 'Api.php', 'App.php', 'Auth.php',
    'Blog.php', 'Cache.php', 'Config.php', 'Database.php', 'Hook.php',
    'Mailer.php', 'Maintenance.php', 'MediaHandler.php', 'MediaStorage.php',
    'Plugin.php', 'Router.php', 'Security.php', 'Update.php'
];
foreach ($nonEditorCoreFiles as $f) {
    addSingleFile($zip, $rootDir . '/core/' . $f, 'core/' . $f);
}

$zip->close();

echo "Non-Editor Code Backup ZIP successfully created: {$zipPath} (" . filesize($zipPath) . " bytes)\n";
