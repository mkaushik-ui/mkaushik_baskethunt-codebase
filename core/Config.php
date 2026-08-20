<?php
namespace SOI\Core;

/**
 * Config Manager - Reads and writes the CMS configuration
 */
class Config {
    private static array $data = [];
    private static string $configFile = '';

    public static function load(string $file): void {
        self::$configFile = $file;
        if (file_exists($file)) {
            self::$data = require $file;
        }
    }

    public static function get(string $key, mixed $default = null): mixed {
        $keys = explode('.', $key);
        $value = self::$data;
        foreach ($keys as $k) {
            if (!isset($value[$k])) return $default;
            $value = $value[$k];
        }
        return $value;
    }

    public static function set(string $key, mixed $value): void {
        $keys = explode('.', $key);
        $data = &self::$data;
        foreach ($keys as $i => $k) {
            if ($i === count($keys) - 1) {
                $data[$k] = $value;
            } else {
                if (!isset($data[$k]) || !is_array($data[$k])) {
                    $data[$k] = [];
                }
                $data = &$data[$k];
            }
        }
    }

    public static function all(): array {
        return self::$data;
    }

    public static function write(array $data, string $file = ''): bool {
        $file = $file ?: self::$configFile;
        $content = "<?php\nreturn " . var_export($data, true) . ";\n";
        return file_put_contents($file, $content) !== false;
    }
}
