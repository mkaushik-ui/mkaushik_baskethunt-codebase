<?php
namespace SOI\Core;

/**
 * Cache - Handles Server-level LiteSpeed Page Caching and Purging.
 */
class Cache {

    /**
     * Determine if LiteSpeed caching is enabled system-wide.
     */
    public static function isEnabled(): bool {
        return (bool) Database::getOption('litespeed_cache_enabled', '0');
    }

    /**
     * Emit LiteSpeed Page Cache headers for frontend public pages.
     * @param int|null $ttl Time to live in seconds
     */
    public static function setCacheable(?int $ttl = null): void {
        if (!self::isEnabled()) return;

        if ($ttl === null) {
            $ttl = (int) Database::getOption('litespeed_cache_ttl', '28800');
        }
        
        // Do not cache if user is logged in
        if (Auth::check()) {
            self::setNoCache();
            return;
        }

        header("X-LiteSpeed-Cache-Control: public,max-age={$ttl}");
    }

    /**
     * Emit LiteSpeed No-Cache header explicitly.
     */
    public static function setNoCache(): void {
        header('X-LiteSpeed-Cache-Control: no-cache');
    }

    /**
     * Purge the entire LiteSpeed Cache.
     */
    public static function purgeAll(): void {
        if (!self::isEnabled()) return;
        header('X-LiteSpeed-Purge: *');
    }

    /**
     * Purge a specific URL/URI.
     * @param string $uri The URI to purge (e.g. /about-us)
     */
    public static function purgeUri(string $uri): void {
        if (!self::isEnabled()) return;
        $uri = trim($uri, '/');
        header('X-LiteSpeed-Purge: /' . $uri);
    }
}
