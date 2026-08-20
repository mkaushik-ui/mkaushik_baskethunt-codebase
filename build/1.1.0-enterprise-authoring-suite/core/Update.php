<?php
namespace SOI\Core;

/**
 * Update Manager - handles zip-based updates for core and plugins
 */
class Update {
    private static string $lastError = '';
    private static string $lastMessage = '';

    /**
     * Process an uploaded update ZIP file
     * Returns ['success' => bool, 'message' => string, 'version' => string]
     */
    public static function process(string $zipPath): array {
        if (!extension_loaded('zip')) {
            return self::fail('PHP ZipArchive extension is not installed.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return self::fail('Could not open ZIP file. It may be corrupted.');
        }

        // Read manifest.json from zip
        $manifestJson = $zip->getFromName('manifest.json');
        if ($manifestJson === false) {
            $zip->close();
            return self::fail('Invalid update package: manifest.json not found.');
        }

        $manifest = json_decode($manifestJson, true);
        if (!$manifest) {
            $zip->close();
            return self::fail('Invalid manifest.json: JSON parse error.');
        }

        // Validate required fields
        foreach (['version', 'name'] as $field) {
            if (empty($manifest[$field])) {
                $zip->close();
                return self::fail("manifest.json missing required field: $field");
            }
        }

        $version = $manifest['version'];
        $name    = $manifest['name'];
        $type    = $manifest['type'] ?? 'core'; // core | plugin
        $minPhp  = $manifest['min_php'] ?? '8.0';

        // Check PHP version requirement
        if (version_compare(PHP_VERSION, $minPhp, '<')) {
            $zip->close();
            return self::fail("This update requires PHP {$minPhp}+. Your version: " . PHP_VERSION);
        }

        // Extract all files (skip manifest.json and update.sql)
        $extractCount = 0;
        $skippedCount = 0;
        $rootPath = realpath(SOI_ROOT);
        $excludes = $manifest['excludes'] ?? [];
        if (!empty($manifest['safe_mode'])) {
            $excludes[] = '.htaccess';
        }
        $excludes = array_values(array_unique(array_filter(array_map('strval', $excludes))));
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name_in_zip = $zip->getNameIndex($i);
            if ($name_in_zip === 'manifest.json' || $name_in_zip === 'update.sql') continue;
            if (str_ends_with($name_in_zip, '/')) continue; // skip directories

            // Security: prevent path traversal
            $entryName = str_replace('\\', '/', $name_in_zip);
            if ($entryName === '' || str_starts_with($entryName, '/') || preg_match('#(^|/)\.\.(/|$)#', $entryName)) {
                continue;
            }
            if (in_array($entryName, ['config/config.php', 'config/install.lock', 'config/.installing.lock'], true)) {
                continue;
            }

            $skipExcluded = false;
            foreach ($excludes as $excluded) {
                if ($entryName === $excluded || str_starts_with($entryName, rtrim($excluded, '/') . '/')) {
                    $skipExcluded = true;
                    break;
                }
            }
            if ($skipExcluded) {
                $skippedCount++;
                continue;
            }

            $realPath = $rootPath . '/' . $entryName;
            if (is_link($realPath)) {
                continue;
            }

            $dir = dirname($realPath);
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                    continue;
                }
            }
            $realDir = realpath($dir);
            if ($realDir === false || ($realDir !== $rootPath && !str_starts_with($realDir, $rootPath . DIRECTORY_SEPARATOR))) {
                continue;
            }

            $contents = $zip->getFromIndex($i);
            if ($contents !== false && file_put_contents($realPath, $contents, LOCK_EX) !== false) {
                @chmod($realPath, 0644);
                $extractCount++;
            }
        }

        // Run optional SQL patch
        $sqlPatch = $zip->getFromName('update.sql');
        $sqlResult = true;
        if ($sqlPatch !== false && !empty(trim($sqlPatch))) {
            try {
                $statements = array_filter(
                    array_map('trim', explode(';', $sqlPatch)),
                    fn($s) => !empty($s)
                );
                foreach ($statements as $stmt) {
                    Database::exec($stmt);
                }
            } catch (\PDOException $e) {
                $zip->close();
                return self::fail('SQL patch failed: ' . $e->getMessage());
            }
        }

        $zip->close();

        // Record update in options
        if ($type === 'core') {
            Database::setOption('cms_version', $version);
        }

        // Log the update
        self::logUpdate($manifest, $extractCount);

        $message = "Successfully installed \"{$manifest['name']}\" v{$version}. {$extractCount} files updated.";
        if ($skippedCount > 0) {
            $message .= " {$skippedCount} safe-mode file(s) skipped.";
        }

        return [
            'success' => true,
            'message' => $message,
            'version' => $version,
        ];
    }

    private static function logUpdate(array $manifest, int $fileCount): void {
        $log = [
            'version'    => $manifest['version'],
            'name'       => $manifest['name'],
            'type'       => $manifest['type'] ?? 'core',
            'files'      => $fileCount,
            'installed'  => date('Y-m-d H:i:s'),
            'installed_by' => Auth::id(),
        ];

        $existing = Database::getOption('update_log', '[]');
        $logs = json_decode($existing, true) ?: [];
        array_unshift($logs, $log);
        $logs = array_slice($logs, 0, 50); // keep last 50
        Database::setOption('update_log', json_encode($logs));
    }

    public static function getUpdateLog(): array {
        $log = Database::getOption('update_log', '[]');
        return json_decode($log, true) ?: [];
    }

    private static function fail(string $message): array {
        self::$lastError = $message;
        return ['success' => false, 'message' => $message, 'version' => ''];
    }

    public static function getLastError(): string {
        return self::$lastError;
    }

    /**
     * Validate a ZIP before processing (returns manifest data or error)
     */
    public static function validateZip(string $zipPath): array {
        if (!extension_loaded('zip')) {
            return ['valid' => false, 'error' => 'ZipArchive not available'];
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['valid' => false, 'error' => 'Cannot open ZIP'];
        }
        $manifestJson = $zip->getFromName('manifest.json');
        $zip->close();

        if ($manifestJson === false) {
            return ['valid' => false, 'error' => 'No manifest.json found'];
        }
        $manifest = json_decode($manifestJson, true);
        if (!$manifest) {
            return ['valid' => false, 'error' => 'manifest.json is not valid JSON'];
        }
        return ['valid' => true, 'manifest' => $manifest];
    }
}
