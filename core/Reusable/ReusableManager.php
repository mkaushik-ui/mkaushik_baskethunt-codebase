<?php
declare(strict_types=1);

namespace SOI\Core\Reusable;

use InvalidArgumentException;
use RuntimeException;
use SOI\Core\Database;
use SOI\Core\Content\Document;
use SOI\Core\Content\DocumentRenderer;
use SOI\Core\Content\Html;

/**
 * Task A3-T17: Reusable Blocks System
 * 
 * Manages central shared reusable blocks stored in `soi_kc_reusable_blocks`.
 * Provides live synchronized references across documents so updating a reusable block's
 * source instantly updates all documents referencing its ID in real time.
 * 
 * @author Saurabh
 */
class ReusableManager
{
    private const TABLE_NAME = 'kc_reusable_blocks';
    private const FULL_TABLE = 'soi_kc_reusable_blocks';

    /**
     * Stack to track active rendering IDs to prevent circular reference recursion loops.
     * 
     * @var list<int>
     */
    private static array $renderStack = [];

    /**
     * Create a new reusable block.
     *
     * @param string $title Block descriptive title
     * @param mixed $content Block content structure (array or JSON string)
     * @param string $category Organizational category (default: 'general')
     * @return array{id: int, title: string, category: string, content: array, content_json: string}
     * @throws InvalidArgumentException
     */
    public static function create(string $title, mixed $content, string $category = 'general'): array
    {
        $cleanTitle = trim(Html::plainText(Html::clampText($title, 255)));
        if ($cleanTitle === '') {
            $cleanTitle = 'Untitled Reusable Block';
        }

        $cleanCategory = trim(preg_replace('/[^a-zA-Z0-9_-]/', '', $category));
        if ($cleanCategory === '') {
            $cleanCategory = 'general';
        }

        $normalizedContent = self::normalizeContent($content);
        $encodedJson = is_string($content) && self::isValidJson($content)
            ? $content
            : json_encode($normalizedContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($encodedJson === false) {
            $encodedJson = '{"blocks":[]}';
        }

        $id = 0;
        if (class_exists(Database::class) && Database::isConnected()) {
            $id = Database::insert(self::TABLE_NAME, [
                'title' => $cleanTitle,
                'category' => $cleanCategory,
                'content_json' => $encodedJson,
            ]);
        }

        return [
            'id' => $id,
            'title' => $cleanTitle,
            'category' => $cleanCategory,
            'content' => $normalizedContent,
            'content_json' => $encodedJson,
        ];
    }

    /**
     * Retrieve a reusable block by its ID.
     *
     * @param int $id
     * @return array{id: int, title: string, category: string, content: array, content_json: string, created_at?: string, updated_at?: string}|null
     */
    public static function get(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        if (!class_exists(Database::class) || !Database::isConnected()) {
            return null;
        }

        $table = Database::prefix(self::TABLE_NAME);
        if (!Database::tableExists(self::TABLE_NAME)) {
            return null;
        }

        $row = Database::selectOne("SELECT * FROM `$table` WHERE id = ? LIMIT 1", [$id]);
        if (!$row) {
            return null;
        }

        $contentJson = (string) ($row['content_json'] ?? '{}');
        $parsedContent = json_decode($contentJson, true);
        if (!is_array($parsedContent)) {
            $parsedContent = ['blocks' => []];
        }

        return [
            'id' => (int) $row['id'],
            'title' => (string) ($row['title'] ?? ''),
            'category' => (string) ($row['category'] ?? 'general'),
            'content' => $parsedContent,
            'content_json' => $contentJson,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * Update an existing reusable block by ID.
     * Live references across all documents will immediately reflect this update.
     *
     * @param int $id
     * @param array{title?: string, category?: string, content?: mixed, content_json?: string} $data
     * @return bool
     * @throws InvalidArgumentException
     */
    public static function update(int $id, array $data): bool
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid reusable block ID for update: ' . $id);
        }

        if (!class_exists(Database::class) || !Database::isConnected()) {
            return false;
        }

        $fields = [];

        if (isset($data['title'])) {
            $cleanTitle = trim(Html::plainText(Html::clampText((string) $data['title'], 255)));
            $fields['title'] = $cleanTitle !== '' ? $cleanTitle : 'Untitled Reusable Block';
        }

        if (isset($data['category'])) {
            $cleanCategory = trim(preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $data['category']));
            $fields['category'] = $cleanCategory !== '' ? $cleanCategory : 'general';
        }

        if (isset($data['content_json']) && is_string($data['content_json'])) {
            $fields['content_json'] = $data['content_json'];
        } elseif (isset($data['content'])) {
            $normalized = self::normalizeContent($data['content']);
            $encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $fields['content_json'] = $encoded !== false ? $encoded : '{"blocks":[]}';
        }

        if ($fields === []) {
            return true;
        }

        $affected = Database::update(self::TABLE_NAME, $fields, 'id = ?', [$id]);
        return $affected >= 0;
    }

    /**
     * Delete a reusable block by ID.
     *
     * @param int $id
     * @return bool
     */
    public static function delete(int $id): bool
    {
        if ($id <= 0 || !class_exists(Database::class) || !Database::isConnected()) {
            return false;
        }

        $affected = Database::delete(self::TABLE_NAME, 'id = ?', [$id]);
        return $affected > 0;
    }

    /**
     * List all reusable blocks with optional category filtering.
     *
     * @param string $category
     * @param int $limit
     * @param int $offset
     * @return list<array{id: int, title: string, category: string, created_at: string, updated_at: string}>
     */
    public static function list(string $category = '', int $limit = 100, int $offset = 0): array
    {
        if (!class_exists(Database::class) || !Database::isConnected()) {
            return [];
        }

        $table = Database::prefix(self::TABLE_NAME);
        if (!Database::tableExists(self::TABLE_NAME)) {
            return [];
        }

        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);

        if ($category !== '') {
            return Database::select(
                "SELECT id, title, category, created_at, updated_at FROM `$table` WHERE category = ? ORDER BY title ASC LIMIT $limit OFFSET $offset",
                [$category]
            );
        }

        return Database::select(
            "SELECT id, title, category, created_at, updated_at FROM `$table` ORDER BY title ASC LIMIT $limit OFFSET $offset"
        );
    }

    /**
     * Dynamically resolve live reusable block content for document rendering.
     * Guarantees all documents referencing $id always display the latest content in real-time.
     *
     * @param int $id Reusable Block ID
     * @param bool $preview Whether in preview mode
     * @return string Rendered HTML content or fallback markup
     */
    public static function resolveLiveContent(int $id, bool $preview = false, ?\SOI\Core\Spaces\Audience\AudienceSubjectContext $subject = null): string
    {
        if ($id <= 0) {
            return '<div class="kc-reusable-empty">[Empty reusable block reference]</div>';
        }

        // Circular reference recursion prevention
        if (in_array($id, self::$renderStack, true)) {
            return '<div class="kc-reusable-cycle">[Circular reusable reference detected: #' . $id . ']</div>';
        }

        self::$renderStack[] = $id;

        try {
            $block = self::get($id);
            if (!$block || empty($block['content_json'])) {
                return '<div class="kc-reusable-missing">[Reusable block #' . $id . ' not found]</div>';
            }

            // Audience / Permission guard (WD-06)
            if (!empty($block['audience_policy'])) {
                $subject = $subject ?? \SOI\Core\Spaces\Audience\AudienceSubjectContext::fromCurrentSession();
                $policyService = \SOI\Core\Spaces\Audience\AudiencePolicyService::instance();
                if (!$policyService->canAccessSpace($block, $subject)) {
                    return '<div class="kc-reusable-restricted">[Restricted reusable content - Access Denied]</div>';
                }
            }

            if (class_exists(Document::class) && class_exists(DocumentRenderer::class)) {
                $doc = Document::parse($block['content_json']);
                $innerHtml = DocumentRenderer::render($doc, $preview);
            } else {
                $innerHtml = '<div class="kc-reusable-raw">' . Html::escape($block['title']) . '</div>';
            }

            return '<div class="kc-reusable-live-wrapper" data-reusable-id="' . $id . '">'
                . '<div class="kc-reusable-badge">REUSABLE: ' . Html::escape($block['title']) . '</div>'
                . '<div class="kc-reusable-content">' . $innerHtml . '</div>'
                . '</div>';
        } finally {
            array_pop(self::$renderStack);
        }
    }

    /**
     * Normalize content input to standardized array structure.
     *
     * @param mixed $content
     * @return array
     */
    private static function normalizeContent(mixed $content): array
    {
        if (is_string($content)) {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            return ['time' => time() * 1000, 'blocks' => [
                ['type' => 'paragraph', 'data' => ['text' => $content]]
            ], 'version' => '2.28.0'];
        }

        if (is_array($content)) {
            if (isset($content['blocks']) && is_array($content['blocks'])) {
                return $content;
            }
            return ['time' => time() * 1000, 'blocks' => $content, 'version' => '2.28.0'];
        }

        return ['time' => time() * 1000, 'blocks' => [], 'version' => '2.28.0'];
    }

    /**
     * Check if a string is valid JSON.
     *
     * @param string $string
     * @return bool
     */
    private static function isValidJson(string $string): bool
    {
        if ($string === '') {
            return false;
        }
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }
}
