<?php
declare(strict_types=1);

/**
 * Task T8: Release Packaging Script (Skeleton).
 * Assembles release files and builds updates/soi-knowledge-center-v1.2.0.zip.
 */
$rootDir = dirname(__DIR__);
$updatesDir = $rootDir . '/updates';
if (!is_dir($updatesDir)) {
    mkdir($updatesDir, 0755, true);
}

echo "=== Packaging Release v1.2.0 ===\n";
echo "Target: {$updatesDir}/soi-knowledge-center-v1.2.0.zip\n";
// Developer 8: Assemble ZIP with manifest.json, update.sql, and application codebase
