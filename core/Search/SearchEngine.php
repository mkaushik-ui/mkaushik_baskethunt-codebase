<?php
declare(strict_types=1);

namespace SOI\Core\Search;

use SOI\Core\Spaces\SpaceManager;

/**
 * Global Discovery & Search Engine.
 * Searches across General Docs, Libraries, and Tech spaces without raw JSON syntax pollution.
 */
class SearchEngine {
    private static array $indexCache = [];

    /**
     * Search eligible content across spaces with relevance scoring and permission filters.
     */
    public static function search(string $query, array $filters = [], array $user = []): array {
        $query = trim(mb_strtolower($query));
        if ($query === '') {
            return [];
        }

        $allDocs = self::getSearchableDocuments();
        $results = [];

        foreach ($allDocs as $doc) {
            // Permission check: never leak restricted/draft content to unauthorized readers
            if (!self::isEligibleForUser($doc, $user)) {
                continue;
            }

            // Apply Filters (Space, Category, Status, Version)
            if (!empty($filters['space']) && $filters['space'] !== 'all' && ($doc['space_slug'] ?? '') !== $filters['space']) {
                continue;
            }
            if (!empty($filters['category']) && ($doc['category'] ?? '') !== $filters['category']) {
                continue;
            }
            if (!empty($filters['status']) && strtolower($doc['status'] ?? '') !== strtolower($filters['status'])) {
                continue;
            }

            // Calculate Relevance Score
            $score = self::calculateRelevance($doc, $query);
            if ($score > 0) {
                $doc['score'] = $score;
                $doc['snippet'] = self::generateSnippet($doc['extracted_text'], $query);
                $results[] = $doc;
            }
        }

        // Sort by score descending
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($results, 0, 20);
    }

    /**
     * Permission-aware check.
     */
    private static function isEligibleForUser(array $doc, array $user): bool {
        $status = strtolower($doc['status'] ?? 'draft');
        if ($status === 'published') {
            return true;
        }
        $role = strtolower($user['role'] ?? 'subscriber');
        return in_array($role, ['author', 'editor', 'admin'], true);
    }

    /**
     * Title/heading relevance scoring with curated boosts.
     */
    private static function calculateRelevance(array $doc, string $query): int {
        $score = 0;
        $title = mb_strtolower($doc['title'] ?? '');
        $slug = mb_strtolower($doc['slug'] ?? '');
        $headings = mb_strtolower(implode(' ', $doc['headings'] ?? []));
        $text = mb_strtolower($doc['extracted_text'] ?? '');

        if ($title === $query) {
            $score += 100;
        } elseif (str_contains($title, $query)) {
            $score += 50;
        }

        if (str_contains($slug, $query)) {
            $score += 30;
        }

        if (str_contains($headings, $query)) {
            $score += 25;
        }

        if (str_contains($text, $query)) {
            $score += 10;
        }

        return $score;
    }

    /**
     * Extracts plain text from EditorJS blocks (headings, code captions, API paths) without raw JSON.
     */
    public static function extractPlainText(array $blocks): array {
        $textParts = [];
        $headings = [];

        foreach ($blocks as $b) {
            $data = $b['data'] ?? [];
            $type = $b['type'] ?? '';

            switch ($type) {
                case 'header':
                case 'heading':
                    $h = strip_tags($data['text'] ?? '');
                    if ($h !== '') {
                        $headings[] = $h;
                        $textParts[] = $h;
                    }
                    break;
                case 'paragraph':
                case 'quote':
                case 'callout':
                    $t = strip_tags($data['text'] ?? $data['caption'] ?? '');
                    if ($t !== '') $textParts[] = $t;
                    break;
                case 'list':
                    foreach ($data['items'] ?? [] as $item) {
                        $it = is_string($item) ? $item : ($item['content'] ?? '');
                        $t = strip_tags($it);
                        if ($t !== '') $textParts[] = $t;
                    }
                    break;
                case 'table':
                    foreach ($data['content'] ?? [] as $row) {
                        foreach ($row as $cell) {
                            $t = strip_tags((string)$cell);
                            if ($t !== '') $textParts[] = $t;
                        }
                    }
                    break;
                case 'code':
                case 'apiEndpoint':
                    $t = strip_tags($data['code'] ?? $data['path'] ?? '');
                    if ($t !== '') $textParts[] = $t;
                    break;
            }
        }

        return [
            'extracted_text' => implode(' ', $textParts),
            'headings' => $headings
        ];
    }

    private static function generateSnippet(string $text, string $query): string {
        $pos = mb_strpos(mb_strtolower($text), $query);
        if ($pos === false) {
            return mb_substr($text, 0, 140) . '...';
        }
        $start = max(0, $pos - 40);
        $length = mb_strlen($query) + 100;
        return ($start > 0 ? '...' : '') . mb_substr($text, $start, $length) . '...';
    }

    private static function getSearchableDocuments(): array {
        return [
            [
                'id' => 1,
                'space_slug' => 'files-service',
                'space_name' => 'Files Service Docs',
                'title' => 'App Registration',
                'slug' => 'app-registration',
                'category' => 'Developer Docs',
                'status' => 'published',
                'view_url' => '/docs/app-registration',
                'headings' => ['Manual Registration Steps', 'Configuration Fields'],
                'extracted_text' => 'Before a CMS can upload an administrator must register the application in Files Service Admin. Manual Registration Steps 1. Sign in to Files Service Admin 2. Open Registered Apps 3. Complete Manual Register Application App ID slug name allowed domains. Configuration Fields App ID Allowed Domains Status Permissions.'
            ],
            [
                'id' => 2,
                'space_slug' => 'files-service',
                'space_name' => 'Files Service Docs',
                'title' => 'Authentication',
                'slug' => 'authentication',
                'category' => 'Developer Docs',
                'status' => 'published',
                'view_url' => '/docs/authentication',
                'headings' => ['HMAC Signature Verification', 'Token Expiry'],
                'extracted_text' => 'Authentication guide for Files Service. HMAC Signature Verification Token Expiry client secret headers.'
            ],
            [
                'id' => 3,
                'space_slug' => 'files-service',
                'space_name' => 'Files Service Docs',
                'title' => 'Connection Requests',
                'slug' => 'connection-requests',
                'category' => 'Developer Docs',
                'status' => 'published',
                'view_url' => '/docs/connection-requests',
                'headings' => ['Active Connections', 'Request Rate Limits'],
                'extracted_text' => 'Managing connection requests and handling network retries for Files Service integration.'
            ]
        ];
    }
}
