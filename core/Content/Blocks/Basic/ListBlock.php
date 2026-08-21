<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Basic;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\RenderContext;

/**
 * Task T3: List Block Provider (Skeleton).
 */
class ListBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'list';
    }

    public function label(): string
    {
        return 'List';
    }

    public function category(): string
    {
        return 'basic';
    }

    public function sanitize(array $data): array
    {
        return [
            'style' => $this->enum($data['style'] ?? 'unordered', ['unordered', 'ordered', 'checklist'], 'unordered'),
            'items' => is_array($data['items'] ?? null) ? $data['items'] : [],
        ];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $data = $block['data'] ?? [];
        $style = (string)($data['style'] ?? 'unordered');
        $tag = ($style === 'ordered') ? 'ol' : 'ul';
        $items = (array)($data['items'] ?? []);
        
        $html = ["<{$tag} class=\"kc-list kc-list-{$style}\">"];
        foreach ($items as $item) {
            $text = is_array($item) ? (string)($item['text'] ?? '') : (string)$item;
            $html[] = "<li>{$text}</li>";
        }
        $html[] = "</{$tag}>";
        return implode("\n", $html);
    }
}
