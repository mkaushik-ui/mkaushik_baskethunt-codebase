<?php
declare(strict_types=1);

namespace SOI\Core\Links;

/**
 * Historical Slug Redirect Manager.
 * Ensures slug changes create automatic 301 redirects so internal references and external bookmarks never break.
 */
class RedirectManager {
    private static array $redirectMap = [];

    /**
     * Record a slug change (oldSlug -> newSlug).
     */
    public static function registerRedirect(string $oldSlug, string $newSlug): void {
        $oldSlug = trim($oldSlug, '/');
        $newSlug = trim($newSlug, '/');

        if ($oldSlug !== '' && $newSlug !== '' && $oldSlug !== $newSlug) {
            self::$redirectMap[$oldSlug] = $newSlug;
        }
    }

    /**
     * Resolve current target slug for an old slug (follows redirect chain).
     */
    public static function getTargetSlug(string $slug): ?string {
        $slug = trim($slug, '/');
        $visited = [];

        while (isset(self::$redirectMap[$slug]) && !in_array($slug, $visited, true)) {
            $visited[] = $slug;
            $slug = self::$redirectMap[$slug];
        }

        return !empty($visited) ? $slug : null;
    }

    /**
     * Get full redirect map.
     */
    public static function getRedirects(): array {
        return self::$redirectMap;
    }
}
