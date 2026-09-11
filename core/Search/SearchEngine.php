<?php
declare(strict_types=1);

namespace SOI\Core\Search;

use PDO;
use SOI\Core\Database;
use SOI\Core\Spaces\Audience\AudienceSubjectContext;
use SOI\Core\Spaces\Audience\AudiencePolicyService;

/**
 * Global Discovery & Search Engine.
 * Domain: kc.soi.co.in (Workstream E / Milestone M5 / Task DS-02)
 *
 * Authoritative global search engine querying `soi_search_index` across
 * General Docs, Departmental Libraries, and Technical Product Spaces.
 * Restricts results fail-closed using `AudiencePolicyService` (SSOT).
 */
class SearchEngine
{
    /**
     * Search eligible content across spaces with relevance scoring and SSOT audience policy filters.
     *
     * @param string $query Search query text
     * @param array<string, mixed> $filters Space, category, status, version filters
     * @param AudienceSubjectContext|null $subject Current user session subject
     * @param PDO|null $pdo Optional PDO connection handle
     * @return list<array<string, mixed>> Ranked, permission-filtered search result items
     */
    public static function search(
        string $query,
        array $filters = [],
        ?AudienceSubjectContext $subject = null,
        ?PDO $pdo = null
    ): array {
        $cleanQuery = trim(mb_strtolower($query));
        if ($cleanQuery === '') {
            return [];
        }

        $subject = $subject ?? AudienceSubjectContext::fromCurrentSession();
        $policyService = new AudiencePolicyService();
        $pdo = $pdo ?? self::getPdo();

        $allDocs = self::fetchIndexedDocuments($cleanQuery, $filters, $pdo);
        $results = [];

        foreach ($allDocs as $doc) {
            $space = [
                'id' => $doc['space_id'] ?? 0,
                'slug' => $doc['space_slug'] ?? 'docs',
                'name' => $doc['space_name'] ?? 'Knowledge Center',
                'audience_policy' => $doc['audience_policy'] ?? null
            ];

            // 1. Audience Policy Verification (Fail-Closed / SSOT)
            if (!$policyService->canAccessDocument($doc, $space, $subject)) {
                continue;
            }

            // 2. Filter Evaluation (Space, Category, Status, Version)
            if (!empty($filters['space']) && $filters['space'] !== 'all' && ($doc['space_slug'] ?? '') !== $filters['space']) {
                continue;
            }
            if (!empty($filters['category']) && ($doc['category'] ?? '') !== $filters['category']) {
                continue;
            }
            if (!empty($filters['version']) && !empty($doc['doc_version']) && $doc['doc_version'] !== $filters['version']) {
                continue;
            }
            if (!empty($filters['status']) && strtolower((string) ($doc['status'] ?? '')) !== strtolower((string) $filters['status'])) {
                continue;
            }

            // 3. Relevance Scoring & Snippet Generation
            $score = self::calculateRelevance($doc, $cleanQuery);
            if ($score > 0) {
                $doc['score'] = $score;
                $doc['snippet'] = self::generateSnippet((string) ($doc['extracted_text'] ?? ''), $cleanQuery);
                $results[] = $doc;
            }
        }

        // Sort by relevance score descending
        usort($results, static fn(array $a, array $b): int => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        return array_slice($results, 0, 25);
    }

    /**
     * Title, slug, heading, and body relevance scoring.
     */
    public static function calculateRelevance(array $doc, string $query): int
    {
        $score = 0;
        $title = mb_strtolower((string) ($doc['title'] ?? ''));
        $slug = mb_strtolower((string) ($doc['slug'] ?? ''));
        $headings = mb_strtolower(is_array($doc['headings'] ?? null) ? implode(' ', $doc['headings']) : (string) ($doc['headings'] ?? ''));
        $text = mb_strtolower((string) ($doc['extracted_text'] ?? ''));

        if ($title === $query) {
            $score += 150;
        } elseif (str_starts_with($title, $query)) {
            $score += 90;
        } elseif (str_contains($title, $query)) {
            $score += 60;
        }

        if ($slug === $query || str_contains($slug, $query)) {
            $score += 35;
        }

        if (str_contains($headings, $query)) {
            $score += 30;
        }

        if (str_contains($text, $query)) {
            $score += 15;
        }

        return $score;
    }

    /**
     * Extracts clean plain text and headings from structured EditorJS blocks without raw JSON.
     *
     * @param array<array<string, mixed>> $blocks Block data list
     * @return array{extracted_text: string, headings: list<string>}
     */
    public static function extractPlainText(array $blocks): array
    {
        $textParts = [];
        $headings = [];

        foreach ($blocks as $b) {
            if (!is_array($b)) {
                continue;
            }
            $data = is_array($b['data'] ?? null) ? $b['data'] : [];
            $type = (string) ($b['type'] ?? '');

            switch ($type) {
                case 'header':
                case 'heading':
                    $h = strip_tags((string) ($data['text'] ?? ''));
                    if ($h !== '') {
                        $headings[] = $h;
                        $textParts[] = $h;
                    }
                    break;
                case 'paragraph':
                case 'quote':
                case 'callout':
                    $t = strip_tags((string) ($data['text'] ?? ($data['caption'] ?? '')));
                    if ($t !== '') {
                        $textParts[] = $t;
                    }
                    break;
                case 'list':
                case 'checklist':
                    foreach ($data['items'] ?? [] as $item) {
                        $it = is_string($item) ? $item : ($item['content'] ?? ($item['text'] ?? ''));
                        $t = strip_tags((string) $it);
                        if ($t !== '') {
                            $textParts[] = $t;
                        }
                    }
                    break;
                case 'table':
                    foreach ($data['content'] ?? [] as $row) {
                        if (is_array($row)) {
                            foreach ($row as $cell) {
                                $t = strip_tags((string) $cell);
                                if ($t !== '') {
                                    $textParts[] = $t;
                                }
                            }
                        }
                    }
                    break;
                case 'code':
                case 'apiEndpoint':
                    $t = strip_tags((string) ($data['code'] ?? ($data['path'] ?? ($data['caption'] ?? ''))));
                    if ($t !== '') {
                        $textParts[] = $t;
                    }
                    break;
                case 'faq':
                case 'steps':
                    foreach ($data['items'] ?? [] as $item) {
                        if (is_array($item)) {
                            $q = strip_tags((string) ($item['question'] ?? ($item['title'] ?? '')));
                            $a = strip_tags((string) ($item['answer'] ?? ($item['description'] ?? '')));
                            if ($q !== '') $textParts[] = $q;
                            if ($a !== '') $textParts[] = $a;
                        }
                    }
                    break;
            }
        }

        return [
            'extracted_text' => implode(' ', $textParts),
            'headings' => $headings
        ];
    }

    /**
     * Generate highlighting snippet around query occurrences.
     */
    public static function generateSnippet(string $text, string $query): string
    {
        $pos = mb_strpos(mb_strtolower($text), $query);
        if ($pos === false) {
            return mb_substr($text, 0, 140) . (mb_strlen($text) > 140 ? '...' : '');
        }
        $start = max(0, $pos - 40);
        $length = mb_strlen($query) + 100;
        return ($start > 0 ? '...' : '') . mb_substr($text, $start, $length) . '...';
    }

    /**
     * Fetch indexed document candidates from the database or fallback table.
     */
    private static function fetchIndexedDocuments(string $query, array $filters, ?PDO $pdo): array
    {
        if (!$pdo) {
            return [];
        }

        try {
            SearchSchema::ensure($pdo);

            $sql = "SELECT `doc_id` as `id`, `space_id`, `space_slug`, `space_name`, `title`, `slug`, `category`, `status`, `doc_version`, `headings`, `extracted_text`, `view_url`, `audience_policy` 
                    FROM `soi_search_index` 
                    WHERE `raw_tokens` LIKE :query OR `title` LIKE :queryTitle";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':query' => '%' . $query . '%',
                ':queryTitle' => '%' . $query . '%'
            ]);

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$row) {
                if (is_string($row['headings'] ?? null) && str_starts_with((string)$row['headings'], '[')) {
                    $row['headings'] = json_decode((string)$row['headings'], true) ?: [];
                }
            }
            return $rows;
        } catch (\Throwable $e) {
            return [];
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
            // No global Database active
        }
        return null;
    }
}
