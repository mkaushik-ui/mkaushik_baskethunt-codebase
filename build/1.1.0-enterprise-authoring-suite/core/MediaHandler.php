<?php
declare(strict_types=1);

namespace SOI\Core;

/**
 * Media Handler for serving extensionless files with dynamic decoding
 * and universal PHP-based hotlink protection.
 */
class MediaHandler {
    
    public static function serve(string $hash): void {
        // Enforce Universal Hotlink Protection directly in PHP
        self::enforceHotlinkProtection();

        // Enforce Strict Login Requirement for Media
        Auth::init();
        if (!Auth::check()) {
            if (class_exists(SoiCentralAuth::class)) {
                $returnUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
                SoiCentralAuth::redirectToLogin($returnUrl);
            }
            http_response_code(403);
            die("403 Forbidden: You must be securely logged in to access media files.");
        }

        $media = Database::selectOne("SELECT * FROM `" . Database::prefix('media') . "` WHERE filename = ?", [$hash]);

        if (!$media || !file_exists($media['path'])) {
            http_response_code(404);
            die("File not found.");
        }

        $path = $media['path'];
        
        // Security check: ensure path is allowed
        if (!\SOI\Core\MediaStorage::isPathInsidePrivateMediaRoot($path) && 
            !\SOI\Core\MediaStorage::isPathInsideLegacyUploadsRoot($path)) {
            http_response_code(403);
            die("403 Forbidden: Invalid media storage path.");
        }
        
        $mime = $media['mime_type'];
        $size = filesize($path);
        
        // Output headers
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $size);
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Content-Disposition: inline; filename="' . basename($media['original_name']) . '"');
        
        // Support for range requests (video/audio streaming)
        if (isset($_SERVER['HTTP_RANGE'])) {
            self::serveRangeRequest($path, $size, $mime);
            exit;
        }

        readfile($path);
        exit;
    }

    private static function enforceHotlinkProtection(): void {
        $settings = Security::getHotlinkSettings();
        
        if (!$settings['enabled']) return;

        $referer = $_SERVER['HTTP_REFERER'] ?? '';

        if (empty($referer)) {
            if ($settings['block_direct']) {
                http_response_code(403);
                die("403 Forbidden: Direct access to this file is not allowed.");
            }
            return; // Empty referers allowed if strict mode is off
        }

        $parsedUrl = parse_url($referer);
        if (!$parsedUrl || empty($parsedUrl['host'])) {
            return;
        }
        
        $refererHost = strtolower($parsedUrl['host']);
        
        $domains = array_map('trim', explode(',', $settings['allowed_domains']));
        $domains = array_filter($domains);
        
        $allowed = false;
        foreach ($domains as $domain) {
            $domain = strtolower($domain);
            if ($refererHost === $domain || str_ends_with($refererHost, '.' . $domain)) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            http_response_code(403);
            die("403 Forbidden: Hotlinking is disabled.");
        }
    }

    private static function serveRangeRequest(string $path, int $size, string $mime): void {
        $fp = @fopen($path, 'rb');
        if (!$fp) {
            http_response_code(500);
            exit;
        }
        
        header('Accept-Ranges: bytes');
        
        $range = $_SERVER['HTTP_RANGE'];
        list($param, $range) = explode('=', $range);
        
        if (strtolower(trim($param)) !== 'bytes') {
            http_response_code(400);
            exit;
        }
        
        $range = explode(',', $range);
        $range = explode('-', $range[0]);
        
        $start = $range[0] === '' ? 0 : (int)$range[0];
        $end = (isset($range[1]) && $range[1] !== '') ? (int)$range[1] : $size - 1;
        
        if ($start > $end || $end > $size - 1 || $start < 0) {
            http_response_code(416);
            header("Content-Range: bytes */$size");
            exit;
        }
        
        $length = $end - $start + 1;
        
        header('HTTP/1.1 206 Partial Content');
        header("Content-Range: bytes $start-$end/$size");
        header("Content-Length: $length");
        
        fseek($fp, $start);
        
        $buffer = 1024 * 8;
        while (!feof($fp) && ($p = ftell($fp)) <= $end) {
            if ($p + $buffer > $end) {
                $buffer = $end - $p + 1;
            }
            echo fread($fp, $buffer);
            flush();
        }
        
        fclose($fp);
    }
}
