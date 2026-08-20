<?php
namespace SOI\Core;

/**
 * Hook System - WordPress-style actions and filters
 */
class Hook {
    private static array $actions = [];
    private static array $filters = [];

    /** Register an action callback */
    public static function addAction(string $hook, callable $callback, int $priority = 10): void {
        self::$actions[$hook][$priority][] = $callback;
    }

    /** Execute all callbacks for an action */
    public static function doAction(string $hook, mixed ...$args): void {
        if (empty(self::$actions[$hook])) return;
        ksort(self::$actions[$hook]);
        foreach (self::$actions[$hook] as $callbacks) {
            foreach ($callbacks as $callback) {
                call_user_func_array($callback, $args);
            }
        }
    }

    /** Register a filter callback */
    public static function addFilter(string $hook, callable $callback, int $priority = 10): void {
        self::$filters[$hook][$priority][] = $callback;
    }

    /** Apply filters and return modified value */
    public static function applyFilters(string $hook, mixed $value, mixed ...$args): mixed {
        if (empty(self::$filters[$hook])) return $value;
        ksort(self::$filters[$hook]);
        foreach (self::$filters[$hook] as $callbacks) {
            foreach ($callbacks as $callback) {
                $value = call_user_func_array($callback, array_merge([$value], $args));
            }
        }
        return $value;
    }

    /** Check if an action has callbacks */
    public static function hasAction(string $hook): bool {
        return !empty(self::$actions[$hook]);
    }

    /** Check if a filter has callbacks */
    public static function hasFilter(string $hook): bool {
        return !empty(self::$filters[$hook]);
    }

    /** Remove a specific action callback */
    public static function removeAction(string $hook, callable $callback, int $priority = 10): void {
        foreach (self::$actions[$hook][$priority] ?? [] as $key => $cb) {
            if ($cb === $callback) {
                unset(self::$actions[$hook][$priority][$key]);
            }
        }
    }
}
