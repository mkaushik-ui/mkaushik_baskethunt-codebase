<?php
declare(strict_types=1);

namespace SOI\Core\Relationships;

/**
 * Knowledge Relationships & Related Docs Manager.
 * Maps explicit relationships (parent/child, prerequisites, related topics) between knowledge entities.
 */
class RelationshipManager {
    private static array $relationships = [];

    /**
     * Set explicit related documents for a source document.
     */
    public static function setRelatedDocs(int $sourceId, array $targetIds): void {
        self::$relationships[$sourceId] = array_unique(array_map('intval', $targetIds));
    }

    /**
     * Get explicit related document IDs for a source document.
     */
    public static function getRelatedDocs(int $sourceId): array {
        return self::$relationships[$sourceId] ?? [];
    }

    /**
     * Get dependable related document metadata array for rendering in Reader UI.
     */
    public static function getRelatedDocsMetadata(int $sourceId, array $allDocs): array {
        $relatedIds = self::getRelatedDocs($sourceId);
        $related = [];

        foreach ($allDocs as $doc) {
            $docId = (int)($doc['id'] ?? 0);
            if (in_array($docId, $relatedIds, true) && strtolower($doc['status'] ?? '') === 'published') {
                $related[] = [
                    'id' => $docId,
                    'title' => $doc['title'] ?? '',
                    'slug' => $doc['slug'] ?? '',
                    'view_url' => '/docs/' . ($doc['slug'] ?? ''),
                    'category' => $doc['category'] ?? ''
                ];
            }
        }

        // Fallback: if no explicit relationships set, recommend documents from the same space/category
        if (empty($related)) {
            foreach ($allDocs as $doc) {
                $docId = (int)($doc['id'] ?? 0);
                if ($docId !== $sourceId && strtolower($doc['status'] ?? '') === 'published') {
                    $related[] = [
                        'id' => $docId,
                        'title' => $doc['title'] ?? '',
                        'slug' => $doc['slug'] ?? '',
                        'view_url' => '/docs/' . ($doc['slug'] ?? ''),
                        'category' => $doc['category'] ?? ''
                    ];
                    if (count($related) >= 3) break;
                }
            }
        }

        return $related;
    }
}
