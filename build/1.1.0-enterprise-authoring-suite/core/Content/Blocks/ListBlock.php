<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks;

use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

final class ListBlock extends AbstractBlock
{
    private const MAX_ITEMS = 500;
    private const MAX_DEPTH = 4;

    public function type(): string
    {
        return 'list';
    }

    public function label(): string
    {
        return 'List';
    }

    public function sanitize(array $data): array
    {
        $style = $this->enum($data['style'] ?? 'unordered', ['unordered', 'ordered', 'checklist'], 'unordered');
        return [
            'style' => $style,
            'items' => $this->sanitizeItems($data['items'] ?? [], 0),
        ];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $style = (string) ($block['data']['style'] ?? 'unordered');
        $items = $block['data']['items'] ?? [];
        if (!is_array($items) || $items === []) {
            return '';
        }
        return $this->renderItems($items, $style, $block['id'], 0);
    }

    /**
     * @param mixed $items
     * @return list<array<string, mixed>>
     */
    private function sanitizeItems(mixed $items, int $depth): array
    {
        if (!is_array($items) || $depth > self::MAX_DEPTH) {
            return [];
        }
        $out = [];
        foreach (array_slice(array_values($items), 0, self::MAX_ITEMS) as $item) {
            if (is_string($item) || is_numeric($item)) {
                $out[] = [
                    'text' => $this->inline($item, 10000),
                    'checked' => false,
                    'items' => [],
                ];
                continue;
            }
            if (!is_array($item)) {
                continue;
            }
            $text = $item['text'] ?? $item['content'] ?? '';
            $out[] = [
                'text' => $this->inline($text, 10000),
                'checked' => !empty($item['checked']) || !empty($item['meta']['checked']),
                'items' => $this->sanitizeItems($item['items'] ?? [], $depth + 1),
            ];
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function renderItems(array $items, string $style, string $blockId, int $depth): string
    {
        if ($items === [] || $depth > self::MAX_DEPTH) {
            return '';
        }
        if ($style === 'checklist') {
            $html = '<ul class="kc-block kc-block-list kc-block-checklist" data-block-id="' . Html::escape($blockId) . '">';
            foreach ($items as $item) {
                $checked = !empty($item['checked']);
                $html .= '<li class="kc-checklist-item' . ($checked ? ' is-checked' : '') . '">';
                $html .= '<span class="kc-checklist-box" aria-hidden="true">' . ($checked ? '✓' : '') . '</span>';
                $html .= '<span class="kc-checklist-text">' . (string) ($item['text'] ?? '') . '</span>';
                $nested = $item['items'] ?? [];
                if (is_array($nested) && $nested !== []) {
                    $html .= $this->renderItems($nested, $style, $blockId, $depth + 1);
                }
                $html .= '</li>';
            }
            return $html . '</ul>';
        }

        $tag = $style === 'ordered' ? 'ol' : 'ul';
        $html = '<' . $tag . ' class="kc-block kc-block-list kc-block-list-' . Html::escape($style) . '" data-block-id="' . Html::escape($blockId) . '">';
        foreach ($items as $item) {
            $html .= '<li>' . (string) ($item['text'] ?? '');
            $nested = $item['items'] ?? [];
            if (is_array($nested) && $nested !== []) {
                $html .= $this->renderItems($nested, $style, $blockId, $depth + 1);
            }
            $html .= '</li>';
        }
        return $html . '</' . $tag . '>';
    }
}
