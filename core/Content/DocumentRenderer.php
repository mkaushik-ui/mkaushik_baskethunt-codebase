<?php
declare(strict_types=1);

namespace SOI\Core\Content;

/**
 * Server-side renderer. Independent of the editor DOM.
 */
final class DocumentRenderer
{
    /**
     * Render a pages/posts record using structured data when present.
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
     * Headings collected during a render pass — used for future On this page.
     *
     * @param array{schemaVersion?:int,blocks:list<array{id:string,type:string,data:array<string,mixed>}>} $document
     * @return list<array{id:string,level:int,text:string}>
     */
    public static function extractHeadings(array $document): array
    {
        $ctx = new RenderContext();
        foreach ($document['blocks'] ?? [] as $block) {
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
}
