<?php
declare(strict_types=1);

namespace SOI\Core\Links;

use PDO;
use SOI\Core\Database;
use SOI\Core\Search\SearchSchema;

/**
 * Historical Slug Redirect Manager.
 * Domain: kc.soi.co.in (Workstream E / Milestone M5 / Task DS-03)
 *
 * Ensures slug renames generate automatic 301 redirects stored in `soi_slug_redirects`
 * so bookmarks, search indexes, and internal document references never 404.
 */
class RedirectManager
{
    private static array $inMemoryMap = [];

    /**
     * Record a slug change (oldSlug -> newSlug) in persistent database.
     *
     * @param string $oldSlug Historical slug
     * @param string $newSlug Target active slug
     * @param int $spaceId Space ID context
     * @param PDO|null $pdo Optional PDO handle
     * @return bool
     */
    public static function registerRedirect(
        string $oldSlug,
        string $newSlug,
        int $spaceId = 0,
        ?PDO $pdo = null
    ): bool {
        $oldSlug = trim($oldSlug, '/');
        $newSlug = trim($newSlug, '/');

        if ($oldSlug === '' || $newSlug === '' || $oldSlug === $newSlug) {
            return false;
        }

        self::$inMemoryMap[$oldSlug] = $newSlug;

        $pdo = $pdo ?? self::getPdo();
        if (!$pdo) {
            return true;
        }

        SearchSchema::ensure($pdo);

        try {
            $stmt = $pdo->prepare("INSERT INTO `soi_slug_redirects` 
                (`space_id`, `old_slug`, `new_slug`, `target_url`, `http_code`, `created_at`)
                VALUES (:space_id, :old_slug, :new_slug, :target_url, 301, CURRENT_TIMESTAMP)
                ON CONFLICT(`old_slug`) DO UPDATE SET
                    `space_id` = excluded.space_id,
                    `new_slug` = excluded.new_slug,
                    `target_url` = excluded.target_url,
                    `http_code` = 301");
            return $stmt->execute([
                ':space_id' => $spaceId,
                ':old_slug' => $oldSlug,
                ':new_slug' => $newSlug,
                ':target_url' => '/' . $newSlug,
            ]);
        } catch (\Throwable $e) {
            try {
                $stmt = $pdo->prepare("INSERT INTO `soi_slug_redirects` 
                    (`space_id`, `old_slug`, `new_slug`, `target_url`, `http_code`, `created_at`)
                    VALUES (:space_id, :old_slug, :new_slug, :target_url, 301, NOW())
                    ON DUPLICATE KEY UPDATE
                        `space_id` = VALUES(`space_id`),
                        `new_slug` = VALUES(`new_slug`),
                        `target_url` = VALUES(`target_url`)");
                return $stmt->execute([
                    ':space_id' => $spaceId,
                    ':old_slug' => $oldSlug,
                    ':new_slug' => $newSlug,
                    ':target_url' => '/' . $newSlug,
                ]);
            } catch (\Throwable $ex) {
                return false;
            }
        }
    }

    /**
     * Resolve target slug for an old slug following redirect chains.
     *
     * @param string $slug Old slug to check
     * @param PDO|null $pdo Optional PDO handle
     * @return string|null Resolved target slug, or null if no redirect exists
     */
    public static function getTargetSlug(string $slug, ?PDO $pdo = null): ?string
    {
        $slug = trim($slug, '/');
        $visited = [];

        // Check in-memory map first
        while (isset(self::$inMemoryMap[$slug]) && !in_array($slug, $visited, true)) {
            $visited[] = $slug;
            $slug = self::$inMemoryMap[$slug];
        }
        if (!empty($visited)) {
            return $slug;
        }

        // Query database
        $pdo = $pdo ?? self::getPdo();
        if (!$pdo) {
            return null;
        }

        try {
            SearchSchema::ensure($pdo);
            $curr = $slug;
            for ($i = 0; $i < 5; $i++) {
                $stmt = $pdo->prepare("SELECT `new_slug`, `target_url` FROM `soi_slug_redirects` WHERE `old_slug` = :slug LIMIT 1");
                $stmt->execute([':slug' => $curr]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row || empty($row['new_slug'])) {
                    break;
                }
                $curr = (string) $row['new_slug'];
                $visited[] = $curr;
            }

            return !empty($visited) ? $curr : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Helper to get database connection.
     */
    private static function getPdo(): ?PDO
    {
        try {
            if (class_exists(Database::class)) {
                $db = Database::getInstance();
                return $db ? $db->getConnection() : null;
            }
        } catch (\Throwable $e) {
            // DB not loaded
        }
        return null;
    }
}
