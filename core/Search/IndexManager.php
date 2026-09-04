<?php
declare(strict_types=1);

namespace SOI\Core\Search;

/**
 * Search Index Lifecycle Manager.
 * Reliably updates or invalidates search entries on publish/unpublish/permission changes.
 */
class IndexManager {
    private static array $indexedStore = [];

    /**
     * Update search index for a document.
     */
    public static function indexDocument(array $document): bool {
        if (empty($document['id']) || empty($document['title'])) {
            return false;
        }

        $slug = $document['slug'] ?? 'doc-' . $document['id'];
        $blocks = $document['document']['blocks'] ?? $document['blocks'] ?? [];
        $extracted = SearchEngine::extractPlainText($blocks);

        self::$indexedStore[$slug] = [
            'id' => (int)$document['id'],
            'title' => (string)$document['title'],
            'slug' => $slug,
            'space_slug' => $document['space_slug'] ?? 'files-service',
            'space_name' => $document['space_name'] ?? 'Files Service Docs',
            'category' => $document['category'] ?? 'General Docs',
            'status' => strtolower($document['status'] ?? 'draft'),
            'view_url' => '/docs/' . $slug,
            'headings' => $extracted['headings'],
            'extracted_text' => $extracted['extracted_text'],
            'updated_at' => date('Y-m-d H:i:s')
        ];

        return true;
    }

    /**
     * Invalidate search index entry when unpublished or deleted.
     */
    public static function invalidateDocument(string $slug): bool {
        if (isset(self::$indexedStore[$slug])) {
            unset(self::$indexedStore[$slug]);
            return true;
        }
        return false;
    }

    /**
     * Get indexed document by slug.
     */
    public static function getIndexed(string $slug): ?array {
        return self::$indexedStore[$slug] ?? null;
    }
}
