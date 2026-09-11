<?php
declare(strict_types=1);

namespace SOI\Core\Search;

use PDO;
use SOI\Core\Database;
use SOI\Core\Content\EditorSchema;

/**
 * Search Index Lifecycle Manager.
 * Domain: kc.soi.co.in (Workstream E / Milestone M5 / Task DS-01)
 *
 * Reliably extracts, indexes, updates, and invalidates searchable document tokens
 * in the database (`soi_search_index`) on publish, edit, unpublish, or delete events.
 */
class IndexManager
{
    /**
     * Update or insert search index record for a document in the database.
     *
     * @param array<string, mixed> $document Document record (pages/posts row + space metadata)
     * @param PDO|null $pdo Optional PDO instance for testing / transactions
     * @return bool True if indexing succeeded
     */
    public static function indexDocument(array $document, ?PDO $pdo = null): bool
    {
        $docId = (int) ($document['id'] ?? 0);
        $title = trim((string) ($document['title'] ?? ''));
        if ($docId <= 0 || $title === '') {
            return false;
        }

        $pdo = $pdo ?? self::getPdo();
        if (!$pdo) {
            return false;
        }

        SearchSchema::ensure($pdo);

        $slug = (string) ($document['slug'] ?? 'doc-' . $docId);
        $spaceId = (int) ($document['space_id'] ?? 0);
        $spaceSlug = (string) ($document['space_slug'] ?? 'docs');
        $spaceName = (string) ($document['space_name'] ?? 'Knowledge Center');
        $category = (string) ($document['category'] ?? ($document['section_title'] ?? ''));
        $status = strtolower((string) ($document['status'] ?? 'published'));
        $docVersion = (string) ($document['doc_version'] ?? '');
        $audiencePolicy = is_array($document['audience_policy'] ?? null)
            ? json_encode($document['audience_policy'])
            : (string) ($document['audience_policy'] ?? null);

        // Determine view URL
        $spaceType = (string) ($document['space_type'] ?? 'docs');
        $sectionSlug = (string) ($document['section_slug'] ?? '');
        $viewUrl = self::buildCanonicalUrl($spaceType, $spaceSlug, $sectionSlug, $slug);

        // Extract clean plain text from structured blocks or fallback content
        $blocks = [];
        if (!empty($document['body_json'])) {
            $parsed = is_string($document['body_json']) ? json_decode($document['body_json'], true) : $document['body_json'];
            $blocks = $parsed['blocks'] ?? [];
        } elseif (!empty($document['blocks'])) {
            $blocks = $document['blocks'];
        }

        $extracted = SearchEngine::extractPlainText($blocks);
        if (empty($extracted['extracted_text']) && !empty($document['content'])) {
            $extracted['extracted_text'] = strip_tags((string) $document['content']);
        }

        $headingsStr = json_encode($extracted['headings'] ?? [], JSON_UNESCAPED_UNICODE);
        $extractedText = (string) ($extracted['extracted_text'] ?? '');
        $rawTokens = mb_strtolower($title . ' ' . $slug . ' ' . implode(' ', $extracted['headings'] ?? []) . ' ' . $extractedText);

        try {
            $stmt = $pdo->prepare("INSERT INTO `soi_search_index` 
                (`doc_id`, `space_id`, `space_slug`, `space_name`, `title`, `slug`, `category`, `status`, `doc_version`, `headings`, `extracted_text`, `raw_tokens`, `view_url`, `audience_policy`, `updated_at`)
                VALUES (:doc_id, :space_id, :space_slug, :space_name, :title, :slug, :category, :status, :doc_version, :headings, :extracted_text, :raw_tokens, :view_url, :audience_policy, CURRENT_TIMESTAMP)
                ON CONFLICT(`doc_id`) DO UPDATE SET
                    `space_id` = excluded.space_id,
                    `space_slug` = excluded.space_slug,
                    `space_name` = excluded.space_name,
                    `title` = excluded.title,
                    `slug` = excluded.slug,
                    `category` = excluded.category,
                    `status` = excluded.status,
                    `doc_version` = excluded.doc_version,
                    `headings` = excluded.headings,
                    `extracted_text` = excluded.extracted_text,
                    `raw_tokens` = excluded.raw_tokens,
                    `view_url` = excluded.view_url,
                    `audience_policy` = excluded.audience_policy,
                    `updated_at` = CURRENT_TIMESTAMP");

            return $stmt->execute([
                ':doc_id' => $docId,
                ':space_id' => $spaceId,
                ':space_slug' => $spaceSlug,
                ':space_name' => $spaceName,
                ':title' => $title,
                ':slug' => $slug,
                ':category' => $category,
                ':status' => $status,
                ':doc_version' => $docVersion,
                ':headings' => $headingsStr,
                ':extracted_text' => $extractedText,
                ':raw_tokens' => $rawTokens,
                ':view_url' => $viewUrl,
                ':audience_policy' => $audiencePolicy,
            ]);
        } catch (\Throwable $e) {
            // Fallback for MySQL duplicate key update syntax if not SQLite
            try {
                $stmt = $pdo->prepare("INSERT INTO `soi_search_index` 
                    (`doc_id`, `space_id`, `space_slug`, `space_name`, `title`, `slug`, `category`, `status`, `doc_version`, `headings`, `extracted_text`, `raw_tokens`, `view_url`, `audience_policy`, `updated_at`)
                    VALUES (:doc_id, :space_id, :space_slug, :space_name, :title, :slug, :category, :status, :doc_version, :headings, :extracted_text, :raw_tokens, :view_url, :audience_policy, NOW())
                    ON DUPLICATE KEY UPDATE
                        `space_id` = VALUES(`space_id`),
                        `space_slug` = VALUES(`space_slug`),
                        `space_name` = VALUES(`space_name`),
                        `title` = VALUES(`title`),
                        `slug` = VALUES(`slug`),
                        `category` = VALUES(`category`),
                        `status` = VALUES(`status`),
                        `doc_version` = VALUES(`doc_version`),
                        `headings` = VALUES(`headings`),
                        `extracted_text` = VALUES(`extracted_text`),
                        `raw_tokens` = VALUES(`raw_tokens`),
                        `view_url` = VALUES(`view_url`),
                        `audience_policy` = VALUES(`audience_policy`),
                        `updated_at` = NOW()");
                return $stmt->execute([
                    ':doc_id' => $docId,
                    ':space_id' => $spaceId,
                    ':space_slug' => $spaceSlug,
                    ':space_name' => $spaceName,
                    ':title' => $title,
                    ':slug' => $slug,
                    ':category' => $category,
                    ':status' => $status,
                    ':doc_version' => $docVersion,
                    ':headings' => $headingsStr,
                    ':extracted_text' => $extractedText,
                    ':raw_tokens' => $rawTokens,
                    ':view_url' => $viewUrl,
                    ':audience_policy' => $audiencePolicy,
                ]);
            } catch (\Throwable $ex) {
                return false;
            }
        }
    }

    /**
     * Invalidate and remove a document from search index.
     *
     * @param int $docId Document ID
     * @param PDO|null $pdo Optional PDO instance
     * @return bool True if removal executed
     */
    public static function invalidateDocument(int $docId, ?PDO $pdo = null): bool
    {
        $pdo = $pdo ?? self::getPdo();
        if (!$pdo || $docId <= 0) {
            return false;
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM `soi_search_index` WHERE `doc_id` = :doc_id");
            return $stmt->execute([':doc_id' => $docId]);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Build canonical reader route URL.
     */
    public static function buildCanonicalUrl(string $spaceType, string $spaceSlug, string $sectionSlug, string $slug): string
    {
        if ($spaceType === 'docs') {
            return $sectionSlug !== '' ? "/docs/{$sectionSlug}/{$slug}" : "/docs/{$slug}";
        }
        if ($spaceType === 'library') {
            return $sectionSlug !== '' ? "/library/{$spaceSlug}/{$sectionSlug}/{$slug}" : "/library/{$spaceSlug}/{$slug}";
        }
        if ($spaceType === 'tech') {
            return $sectionSlug !== '' ? "/tech/{$spaceSlug}/{$sectionSlug}/{$slug}" : "/tech/{$spaceSlug}/{$slug}";
        }
        return "/docs/{$slug}";
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
            // No global Database active
        }
        return null;
    }
}
