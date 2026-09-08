<?php
namespace SOI\Core;

/**
 * Database - PDO wrapper with query builder helpers
 */
class Database {
    private static ?\PDO $pdo = null;
    private static string $prefix = 'soi_';

    public static function connect(array $config): void {
        $dsn = "mysql:host={$config['host']};dbname={$config['name']};charset=utf8mb4;port={$config['port']}";
        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            self::$pdo = new \PDO($dsn, $config['user'], $config['pass'], $options);
            self::$prefix = $config['prefix'] ?? 'soi_';
        } catch (\PDOException $e) {
            if (defined('SOI_JSON_REQUEST') && SOI_JSON_REQUEST) {
                throw $e;
            }
            if ($config['name'] === 'test' && $config['user'] === 'root' && php_sapi_name() !== 'cli') {
                // Looks like default config from a zip on a new server
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                header('Location: ' . $protocol . '://' . $host . '/install/index.php');
                exit;
            }
            if (php_sapi_name() !== 'cli') {
                http_response_code(500);
                die('<h1>Database Connection Error</h1><p>The CMS could not connect to the database. Please check your config/config.php file.</p>');
            }
            throw $e;
        }
    }

    public static function isConnected(): bool {
        return self::$pdo !== null;
    }

    public static function setPdo(?\PDO $pdo): void {
        self::$pdo = $pdo;
    }

    public static function pdo(): \PDO {
        if (!self::$pdo) {
            throw new \RuntimeException('Database not connected.');
        }
        return self::$pdo;
    }

    public static function prefix(string $table): string {
        return self::$prefix . $table;
    }

    public static function query(string $sql, array $params = []): \PDOStatement {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function select(string $sql, array $params = []): array {
        return self::query($sql, $params)->fetchAll();
    }

    public static function selectOne(string $sql, array $params = []): ?array {
        $row = self::query($sql, $params)->fetch();
        return $row ?: null;
    }

    public static function insert(string $table, array $data): int {
        $table = self::prefix($table);
        $cols = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        self::query("INSERT INTO `$table` ($cols) VALUES ($placeholders)", array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int {
        $table = self::prefix($table);
        $set = implode(' = ?, ', array_keys($data)) . ' = ?';
        $params = array_merge(array_values($data), $whereParams);
        return self::query("UPDATE `$table` SET $set WHERE $where", $params)->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int {
        $table = self::prefix($table);
        return self::query("DELETE FROM `$table` WHERE $where", $params)->rowCount();
    }

    public static function count(string $table, string $where = '1', array $params = []): int {
        $table = self::prefix($table);
        $row = self::selectOne("SELECT COUNT(*) as cnt FROM `$table` WHERE $where", $params);
        return (int) ($row['cnt'] ?? 0);
    }

    public static function tableExists(string $table): bool {
        $table = self::prefix($table);
        try {
            self::pdo()->query("SELECT 1 FROM `$table` LIMIT 1");
            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }

    public static function exec(string $sql): void {
        self::pdo()->exec($sql);
    }

    /** Get option value from soi_options */
    public static function getOption(string $key, mixed $default = null): mixed {
        $table = self::prefix('options');
        $row = self::selectOne("SELECT option_value FROM `$table` WHERE option_key = ?", [$key]);
        return $row ? $row['option_value'] : $default;
    }

    /** Set option value in soi_options */
    public static function setOption(string $key, mixed $value): void {
        $table = self::prefix('options');
        $exists = self::selectOne("SELECT id FROM `$table` WHERE option_key = ?", [$key]);
        if ($exists) {
            self::query("UPDATE `$table` SET option_value = ? WHERE option_key = ?", [$value, $key]);
        } else {
            self::query("INSERT INTO `$table` (option_key, option_value) VALUES (?, ?)", [$key, $value]);
        }
    }

    /**
     * Persist multiple options atomically inside a single transaction.
     *
     * @param array<string, mixed> $options
     */
    public static function setOptions(array $options): void {
        if ($options === []) {
            return;
        }

        self::beginTransaction();
        try {
            foreach ($options as $key => $value) {
                self::setOption((string) $key, $value);
            }
            self::commit();
        } catch (\Throwable $e) {
            self::rollback();
            throw $e;
        }
    }

    public static function beginTransaction(): void {
        if (!self::pdo()->inTransaction()) {
            self::pdo()->beginTransaction();
        }
    }

    public static function commit(): void {
        if (self::pdo()->inTransaction()) {
            self::pdo()->commit();
        }
    }

    public static function rollback(): void {
        if (self::pdo()->inTransaction()) {
            self::pdo()->rollBack();
        }
    }
}
