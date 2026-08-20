<?php
declare(strict_types=1);

namespace SOI\Core\Content;

/**
 * Canonical structured document: parse, adapt, validate, sanitize.
 */
final class Document
{
    public const SCHEMA_VERSION = 1;
    public const MAX_BYTES = 1500000;
    public const MAX_BLOCKS = 1500;

    /**
     * @return array{schemaVersion:int,blocks:list<array{id:string,type:string,data:array<string,mixed>}>}
     */
    public static function empty(): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'blocks' => [],
        ];
    }

    /**
     * Accepts canonical JSON, Editor.js output, or a PHP array.
     *
     * @param mixed $raw
     * @return array{schemaVersion:int,blocks:list<array{id:string,type:string,data:array<string,mixed>}>}
     */
    public static function parse(mixed $raw): array
    {
        if (is_string($raw)) {
            if (strlen($raw) > self::MAX_BYTES) {
                throw new DocumentException('Document exceeds the maximum allowed size.');
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new DocumentException('Document JSON is malformed.');
            }
            $raw = $decoded;
        }
        if (!is_array($raw)) {
            throw new DocumentException('Document must be an object.');
        }
        if (isset($raw['time']) || isset($raw['version']) || self::looksLikeEditorJs($raw)) {
            $raw = self::fromEditorJs($raw);
        }
        return self::normalize($raw);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{schemaVersion:int,blocks:list<array{id:string,type:string,data:array<string,mixed>}>}
     */
    public static function normalize(array $input): array
    {
        $blocksIn = $input['blocks'] ?? null;
        if (!is_array($blocksIn)) {
            throw new DocumentException('Document must contain a blocks array.');
        }
        if (count($blocksIn) > self::MAX_BLOCKS) {
            throw new DocumentException('Document has too many blocks.');
        }

        $seen = [];
        $blocks = [];
        foreach (array_values($blocksIn) as $index => $block) {
            if (!is_array($block)) {
                throw new DocumentException('Block at index ' . $index . ' is invalid.');
            }
            $type = self::canonicalType((string) ($block['type'] ?? ''));
            if ($type === '') {
                throw new DocumentException('Block at index ' . $index . ' is missing a type.');
            }
            $id = self::normalizeId((string) ($block['id'] ?? ''), $index, $seen);
            $seen[$id] = true;
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];

            if (!BlockRegistry::has($type)) {
                $handler = new Blocks\UnknownBlock($type);
                $blocks[] = [
                    'id' => $id,
                    'type' => 'unknown',
                    'data' => $handler->sanitize([
                        'originalType' => $type,
                        'payload' => $data,
                    ]),
                ];
                continue;
            }

            $handler = BlockRegistry::get($type);
            $blocks[] = [
                'id' => $id,
                'type' => $type,
                'data' => $handler->sanitize($data),
            ];
        }

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'blocks' => $blocks,
        ];
    }

    /**
     * @param array<string, mixed> $document
     * @return list<string>
     */
    public static function validate(array $document): array
    {
        $errors = [];
        if ((int) ($document['schemaVersion'] ?? 0) !== self::SCHEMA_VERSION) {
            $errors[] = 'Unsupported document schema version.';
        }
        $blocks = $document['blocks'] ?? null;
        if (!is_array($blocks)) {
            return ['Document is missing blocks.'];
        }
        foreach ($blocks as $i => $block) {
            if (!is_array($block)) {
                $errors[] = 'Block ' . $i . ' is malformed.';
                continue;
            }
            $type = (string) ($block['type'] ?? '');
            $handler = BlockRegistry::get($type);
            foreach ($handler->validate(is_array($block['data'] ?? null) ? $block['data'] : []) as $error) {
                $errors[] = 'Block ' . $i . ' (' . $type . '): ' . $error;
            }
        }
        return $errors;
    }

    /**
     * @param array{schemaVersion:int,blocks:list<array{id:string,type:string,data:array<string,mixed>}>} $document
     */
    public static function encode(array $document): string
    {
        $json = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new DocumentException('Unable to encode document.');
        }
        return $json;
    }

    /**
     * Convert canonical document to Editor.js data.
     *
     * @param array{schemaVersion?:int,blocks:list<array{id:string,type:string,data:array<string,mixed>}>} $document
     * @return array{time:int,blocks:list<array<string,mixed>>,version:string}
     */
    public static function toEditorJs(array $document): array
    {
        $blocks = [];
        foreach ($document['blocks'] ?? [] as $block) {
            $blocks[] = self::toEditorJsBlock($block);
        }
        return [
            'time' => (int) floor(microtime(true) * 1000),
            'blocks' => $blocks,
            'version' => '2.30.8',
        ];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{schemaVersion:int,blocks:list<array<string,mixed>>}
     */
    public static function fromEditorJs(array $raw): array
    {
        $blocks = [];
        foreach (array_values($raw['blocks'] ?? []) as $block) {
            if (!is_array($block)) {
                continue;
            }
            $blocks[] = self::fromEditorJsBlock($block);
        }
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'blocks' => $blocks,
        ];
    }

    public static function newId(): string
    {
        try {
            return 'blk_' . bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            return 'blk_' . substr(hash('sha256', uniqid((string) mt_rand(), true)), 0, 16);
        }
    }

    /**
     * @return list<array{id:string,level:int,text:string}>
     */
    public static function outline(array $document): array
    {
        $out = [];
        foreach ($document['blocks'] ?? [] as $block) {
            if (!is_array($block) || ($block['type'] ?? '') !== 'heading') {
                continue;
            }
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $text = Html::plainText((string) ($data['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $out[] = [
                'id' => (string) ($block['id'] ?? ''),
                'level' => (int) ($data['level'] ?? 2),
                'text' => $text,
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function looksLikeEditorJs(array $raw): bool
    {
        $first = $raw['blocks'][0] ?? null;
        if (!is_array($first)) {
            return false;
        }
        $type = (string) ($first['type'] ?? '');
        return in_array($type, ['header', 'delimiter', 'linkTool', 'attaches'], true);
    }

    private static function canonicalType(string $type): string
    {
        $type = trim($type);
        return match ($type) {
            'header' => 'heading',
            'delimiter' => 'divider',
            'linkTool' => 'link',
            'attaches' => 'file',
            'checklist' => 'list',
            default => preg_replace('/[^a-zA-Z0-9_-]/', '', $type) ?? '',
        };
    }

    /**
     * @param array<string, true> $seen
     */
    private static function normalizeId(string $id, int $index, array $seen): string
    {
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id) ?? '';
        if ($id === '' || isset($seen[$id])) {
            $id = self::newId() . '_' . $index;
        }
        return Html::clampText($id, 64);
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private static function fromEditorJsBlock(array $block): array
    {
        $type = (string) ($block['type'] ?? '');
        $data = is_array($block['data'] ?? null) ? $block['data'] : [];
        if ($type === 'checklist') {
            $items = [];
            foreach ($data['items'] ?? [] as $item) {
                if (is_array($item)) {
                    $items[] = [
                        'text' => (string) ($item['text'] ?? ''),
                        'checked' => !empty($item['checked']),
                        'items' => [],
                    ];
                }
            }
            $data = ['style' => 'checklist', 'items' => $items];
        } elseif ($type === 'list') {
            $data['items'] = self::normalizeEditorListItems($data['items'] ?? []);
        } elseif ($type === 'linkTool') {
            $data = [
                'url' => (string) ($data['link'] ?? $data['url'] ?? ''),
                'title' => (string) (($data['meta']['title'] ?? '') ?: ''),
                'text' => (string) (($data['meta']['description'] ?? '') ?: ''),
            ];
        }
        return [
            'id' => (string) ($block['id'] ?? ''),
            'type' => self::canonicalType($type),
            'data' => $data,
        ];
    }

    /**
     * @param mixed $items
     * @return list<array<string, mixed>>
     */
    private static function normalizeEditorListItems(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (is_string($item) || is_numeric($item)) {
                $out[] = ['text' => (string) $item, 'checked' => false, 'items' => []];
                continue;
            }
            if (!is_array($item)) {
                continue;
            }
            $out[] = [
                'text' => (string) ($item['content'] ?? $item['text'] ?? ''),
                'checked' => !empty($item['meta']['checked']) || !empty($item['checked']),
                'items' => self::normalizeEditorListItems($item['items'] ?? []),
            ];
        }
        return $out;
    }

    /**
     * @param array{id?:string,type?:string,data?:array<string,mixed>} $block
     * @return array<string, mixed>
     */
    private static function toEditorJsBlock(array $block): array
    {
        $type = (string) ($block['type'] ?? 'paragraph');
        $data = is_array($block['data'] ?? null) ? $block['data'] : [];
        $editorType = match ($type) {
            'heading' => 'header',
            'divider' => 'delimiter',
            'list' => 'list',
            default => $type,
        };

        if ($type === 'list') {
            $data = [
                'style' => (string) ($data['style'] ?? 'unordered'),
                'items' => self::toEditorListItems($data['items'] ?? []),
            ];
        } elseif ($type === 'image') {
            $data = [
                'file' => [
                    'url' => (string) ($data['url'] ?? ''),
                    'assetId' => $data['assetId'] ?? null,
                ],
                'caption' => (string) ($data['caption'] ?? ''),
                'stretched' => (($data['width'] ?? '') === 'full'),
                'withBorder' => false,
                'withBackground' => false,
                'alt' => (string) ($data['alt'] ?? ''),
                'align' => (string) ($data['align'] ?? 'center'),
                'width' => (string) ($data['width'] ?? 'default'),
                'assetId' => $data['assetId'] ?? null,
                'url' => (string) ($data['url'] ?? ''),
            ];
        } elseif ($type === 'unknown') {
            $editorType = (string) ($data['originalType'] ?? 'paragraph');
            $data = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        }

        return [
            'id' => (string) ($block['id'] ?? self::newId()),
            'type' => $editorType,
            'data' => $data,
        ];
    }

    /**
     * @param mixed $items
     * @return list<array<string, mixed>>
     */
    private static function toEditorListItems(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $out[] = [
                'content' => (string) ($item['text'] ?? ''),
                'meta' => ['checked' => !empty($item['checked'])],
                'items' => self::toEditorListItems($item['items'] ?? []),
            ];
        }
        return $out;
    }
}
