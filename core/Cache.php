<?php
declare(strict_types=1);

namespace SOI\Core;

/**
 * Cache - Handles Key-Value Transient Caching and Server-level LiteSpeed Page Caching.
 */
class Cache
{
    /** @var array<string, array{value: mixed, expires_at: int|null}> */
    private static array $store = [];

    /**
     * Retrieve an item from the cache.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (!array_key_exists($key, self::$store)) {
            return $default;
        }

        $item = self::$store[$key];
        if ($item['expires_at'] !== null && time() > $item['expires_at']) {
            unset(self::$store[$key]);
            return $default;
        }

        return $item['value'];
    }

    /**
     * Store an item in the cache with a time-to-live.
     */
    public static function set(string $key, mixed $value, ?int $ttl = 3600): bool
    {
        $expiresAt = ($ttl !== null && $ttl > 0) ? (time() + $ttl) : null;
        self::$store[$key] = [
            'value'      => $value,
            'expires_at' => $expiresAt,
        ];
        return true;
    }

    /**
     * Check if an item exists in cache.
     */
    public static function has(string $key): bool
    {
        return self::get($key) !== null;
    }

    /**
     * Delete an item from the cache.
     */
    public static function delete(string $key): bool
    {
        if (array_key_exists($key, self::$store)) {
            unset(self::$store[$key]);
            return true;
        }
        return false;
    }

    /**
     * Alias for delete().
     */
    public static function forget(string $key): bool
    {
        return self::delete($key);
    }

    /**
     * Purge a key or tag from cache.
     */
    public static function purge(string $key): bool
    {
        return self::delete($key);
    }

    /**
     * Flush all key-value entries from cache.
     */
    public static function flush(): void
    {
        self::$store = [];
    }

    /**
     * Get an item from cache or compute and store it.
     */
    public static function remember(string $key, ?int $ttl, callable $callback): mixed
    {
        $value = self::get($key);
        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        self::set($key, $value, $ttl);
        return $value;
    }

    // --- LiteSpeed Page Cache Methods ---

    public static function isEnabled(): bool
    {
        if (class_exists(Database::class) && Database::isConnected()) {
            try {
                if (!Database::tableExists('options')) {
                    return false;
                }
                return (bool) Database::getOption('litespeed_cache_enabled', '0');
            } catch (\Throwable $e) {
                return false;
            }
        }
        return false;
    }

    public static function setCacheable(?int $ttl = null): void
    {
        if (!self::isEnabled()) return;

        if ($ttl === null) {
            $ttl = (int) (class_exists(Database::class) ? Database::getOption('litespeed_cache_ttl', '28800') : 28800);
        }

        if (class_exists(Auth::class) && Auth::check()) {
            self::setNoCache();
            return;
        }

        if (!headers_sent()) {
            header("X-LiteSpeed-Cache-Control: public,max-age={$ttl}");
        }
    }

    public static function setNoCache(): void
    {
        if (!headers_sent()) {
            header('X-LiteSpeed-Cache-Control: no-cache');
        }
    }

    public static function purgeAll(): void
    {
        self::flush();
        if (!self::isEnabled()) return;
        if (!headers_sent()) {
            header('X-LiteSpeed-Purge: *');
        }
    }

    public static function purgeUri(string $uri): void
    {
        if (!self::isEnabled()) return;
        $uri = trim($uri, '/');
        if (!headers_sent()) {
            header('X-LiteSpeed-Purge: /' . $uri);
        }
    }
}
