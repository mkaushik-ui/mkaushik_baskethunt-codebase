<?php
declare(strict_types=1);

namespace SOI\Core;

/**
 * Media Storage Manager for Server-Independent Private Media Containment
 */
class MediaStorage {

    private static function normalizeConfiguredPath(string $path, string $constant): string {
        $path = rtrim(trim($path), DIRECTORY_SEPARATOR);
        $isAbsolute = str_starts_with($path, DIRECTORY_SEPARATOR)
            || (bool)preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
        if ($path === '' || !$isAbsolute) {
            throw new \RuntimeException($constant . ' must be an absolute filesystem path.');
        }
        return $path;
    }
    
    public static function getPrivateStorageRoot(): string {
        if (defined('SOI_PRIVATE_STORAGE_PATH')) {
            return self::normalizeConfiguredPath((string)SOI_PRIVATE_STORAGE_PATH, 'SOI_PRIVATE_STORAGE_PATH');
        }
        // Attempt to place outside webroot
        $parentDir = dirname(\SOI_ROOT);
        return $parentDir . '/soi-private-storage';
    }

    public static function getPrivateMediaRoot(): string {
        if (defined('SOI_PRIVATE_MEDIA_PATH')) {
            return self::normalizeConfiguredPath((string)SOI_PRIVATE_MEDIA_PATH, 'SOI_PRIVATE_MEDIA_PATH');
        }
        return self::getPrivateStorageRoot() . '/media';
    }
    
    public static function getQuarantineRoot(): string {
        return self::getPrivateStorageRoot() . '/quarantine-uploads';
    }

    public static function ensurePrivateMediaRoot(): bool {
        $path = self::getPrivateMediaRoot();
        if (!is_dir($path) && !@mkdir($path, 0755, true) && !is_dir($path)) {
            return false;
        }
        return is_writable($path);
    }
    
    public static function ensureQuarantineRoot(): bool {
        $path = self::getQuarantineRoot();
        if (!is_dir($path) && !@mkdir($path, 0755, true) && !is_dir($path)) {
            return false;
        }
        return is_writable($path);
    }

    public static function isPathInsidePrivateMediaRoot(string $path): bool {
        $realPath = realpath($path);
        $realRoot = realpath(self::getPrivateMediaRoot());
        
        if (!$realPath || !$realRoot) {
            return false;
        }
        return $realPath === $realRoot || str_starts_with($realPath, $realRoot . DIRECTORY_SEPARATOR);
    }
    
    public static function isPathInsideLegacyUploadsRoot(string $path): bool {
        $realPath = realpath($path);
        $realRoot = realpath(\SOI_ROOT . '/uploads');
        
        if (!$realPath || !$realRoot) {
            return false;
        }
        return $realPath === $realRoot || str_starts_with($realPath, $realRoot . DIRECTORY_SEPARATOR);
    }

    public static function buildPrivateMediaPath(string $hash, string $year, string $month): string {
        $dir = self::getPrivateMediaRoot() . '/' . $year . '/' . $month;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create private media directory: ' . $dir);
        }
        if (!is_writable($dir)) {
            throw new \RuntimeException('Private media directory is not writable: ' . $dir);
        }
        return $dir . '/' . $hash;
    }

    public static function moveUploadedFileToPrivateStorage(string $tmpFile, string $targetPath): bool {
        if (!is_uploaded_file($tmpFile) || file_exists($targetPath)) {
            return false;
        }
        $dir = dirname($targetPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        return move_uploaded_file($tmpFile, $targetPath);
    }

    public static function copyLegacyFileToPrivateStorage(string $oldPath, string $targetPath): bool {
        if (!is_file($oldPath) || file_exists($targetPath)) {
            return false;
        }
        $dir = dirname($targetPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        return copy($oldPath, $targetPath);
    }

    public static function verifyChecksum(string $source, string $target): bool {
        if (!file_exists($source) || !file_exists($target)) {
            return false;
        }
        return hash_file('sha256', $source) === hash_file('sha256', $target);
    }
    
    public static function quarantineLegacyFile(string $oldPath, string $hash): bool {
        if (!file_exists($oldPath)) {
            return false;
        }
        if (!self::ensureQuarantineRoot()) {
            return false;
        }
        $target = self::getQuarantineRoot() . '/' . $hash . '_' . time() . '_' . bin2hex(random_bytes(4));
        if (rename($oldPath, $target)) {
            return true;
        }
        return false;
    }
}
