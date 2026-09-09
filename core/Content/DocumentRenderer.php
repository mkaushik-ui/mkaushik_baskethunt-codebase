<?php
declare(strict_types=1);

namespace SOI\Core\Content;

use SOI\Core\Content\Contracts\RendererInterface;

/**
 * Canonical server-side document renderer and block dispatcher.
 * Independent of the client-side Editor.js DOM.
 */
final class DocumentRenderer
{
    /**
     * Render a pages/posts record using structured data when present.
     *
     * @param array<string, mixed> $record
     */
    public static function renderRecord(array $record, bool $preview = false): string
    {
        if (EditorSchema::isStructured($record)) {
            try {
                $document = Document::parse($record['body_json'] ?? '');
                return self::render($document, $preview);
            } catch (\Throwable $e) {
                $fallback = (string) ($record['content'] ?? '');
                return $fallback;
            }
        }
        return (string) ($record['content'] ?? '');
    }

    /**
     * Render a full canonical document structure into semantic HTML.
     *
     * @param array{schemaVersion?:int,blocks:list<array{id:string,type:string,data:array<string,mixed>}>} $document
     */
    public static function render(array $document, bool $preview = false): string
    {
        $ctx = new RenderContext();
        $ctx->preview = $preview;
        $html = [];

        foreach ($document['blocks'] ?? [] as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? '');
            $handler = BlockRegistry::get($type);
            $chunk = $handler->render([
                'id' => (string) ($block['id'] ?? ''),
                'type' => $type,
                'data' => is_array($block['data'] ?? null) ? $block['data'] : [],
            ], $ctx);
            if ($chunk !== '') {
                $html[] = $chunk;
            }
        }
        return implode("\n", $html);
    }

    /**
     * Headings collected during a render pass — used for Table of Contents (On this page).
     * Supports structured document blocks array or raw HTML string.
     *
     * @param array{schemaVersion?:int,blocks?:list<array{id?:string,type?:string,data?:array<string,mixed>}>}|string $document
     * @return list<array{id:string,level:int,text:string}>
     */
    public static function extractHeadings(mixed $document): array
    {
        $ctx = new RenderContext();

        // 1. Structured Document (Blocks Array)
        if (is_array($document) && isset($document['blocks']) && is_array($document['blocks'])) {
            foreach ($document['blocks'] as $block) {
                if (!is_array($block) || ($block['type'] ?? '') !== 'heading') {
                    continue;
                }
                BlockRegistry::get('heading')->render([
                    'id' => (string) ($block['id'] ?? ''),
                    'type' => 'heading',
                    'data' => is_array($block['data'] ?? null) ? $block['data'] : [],
                ], $ctx);
            }
            return $ctx->headings;
        }

        // 2. Raw HTML string fallback
        $html = is_string($document) ? $document : (is_array($document) && isset($document['content']) ? (string) $document['content'] : '');
        $headings = [];
        $usedAnchors = [];

        if ($html !== '') {
            if (preg_match_all('/<h([2-3])(?:\s+[^>]*id=["\']([^"\']+)["\'][^>]*|\s*[^>]*)>(.*?)<\/h\1>/is', $html, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $level = (int) $m[1];
                    $existingId = trim($m[2] ?? '');
                    $rawText = $m[3] ?? '';
                    $clean = strip_tags($rawText);
                    $text = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if (trim($text) === '') {
                        continue;
                    }
                    $anchor = $existingId !== '' ? $existingId : self::generateUniqueAnchor($text, $usedAnchors);
                    $usedAnchors[$anchor] = true;

                    $headings[] = [
                        'id' => $anchor,
                        'level' => $level,
                        'text' => $text,
                    ];
                }
            }
        }

        return $headings;
    }

    /**
     * Generate unique URL-safe anchor slug for headings.
     *
     * @param string $text
     * @param array<string, bool> $usedAnchors
     * @return string
     */
    public static function generateUniqueAnchor(string $text, array &$usedAnchors): string
    {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9\-_]+/i', '-', $slug) ?? 'heading';
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'heading';
        }

        $base = $slug;
        $counter = 2;
        while (isset($usedAnchors[$slug])) {
            $slug = $base . '-' . $counter;
            $counter++;
        }
        $usedAnchors[$slug] = true;
        return $slug;
    }
}
