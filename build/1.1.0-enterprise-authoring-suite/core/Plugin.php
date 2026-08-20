<?php
namespace SOI\Core;

/**
 * Plugin Loader - loads and manages plugins
 */
class Plugin {
    private static array $loaded = [];
    private static array $adminMenus = [];

    /** Load all active plugins from DB */
    public static function loadActive(): void {
        if (!Database::tableExists('plugins')) return;

        $table = Database::prefix('plugins');
        $plugins = Database::select("SELECT * FROM `$table` WHERE active = 1");

        foreach ($plugins as $plugin) {
            self::load($plugin['slug']);
        }
    }

    /** Load a single plugin by slug */
    public static function load(string $slug): bool {
        $file = SOI_ROOT . "/plugins/{$slug}/plugin.php";
        if (!file_exists($file)) return false;
        if (isset(self::$loaded[$slug])) return true;

        self::$loaded[$slug] = true;
        require_once $file;

        Hook::doAction("plugin_loaded_{$slug}");
        Hook::doAction('plugin_loaded', $slug);
        return true;
    }

    /** Register an admin menu item from a plugin */
    public static function addAdminMenu(string $title, string $slug, string $url, string $icon = '◆', int $position = 50): void {
        self::$adminMenus[$position][] = compact('title', 'slug', 'url', 'icon');
    }

    /** Get all registered admin menu items */
    public static function getAdminMenus(): array {
        ksort(self::$adminMenus);
        $menus = [];
        foreach (self::$adminMenus as $items) {
            foreach ($items as $item) $menus[] = $item;
        }
        return $menus;
    }

    /** Get header data from a plugin file */
    public static function getPluginInfo(string $slug): array {
        $file = SOI_ROOT . "/plugins/{$slug}/plugin.php";
        if (!file_exists($file)) return [];

        $content = file_get_contents($file);
        $info = [];
        $fields = ['Plugin Name', 'Version', 'Description', 'Author', 'Author URI'];
        foreach ($fields as $field) {
            if (preg_match('/\*\s+' . preg_quote($field) . ':\s+(.+)/i', $content, $m)) {
                $key = str_replace(' ', '_', strtolower($field));
                $info[$key] = trim($m[1]);
            }
        }
        return $info;
    }

    /** Scan plugins directory for all available plugins */
    public static function scanAll(): array {
        $dir = SOI_ROOT . '/plugins';
        $plugins = [];
        if (!is_dir($dir)) return $plugins;

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            if (!is_dir("$dir/$item")) continue;
            $info = self::getPluginInfo($item);
            if ($info) {
                $info['slug'] = $item;
                $plugins[] = $info;
            }
        }
        return $plugins;
    }

    public static function isLoaded(string $slug): bool {
        return isset(self::$loaded[$slug]);
    }

    public static function getLoaded(): array {
        return array_keys(self::$loaded);
    }
}
