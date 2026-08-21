<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Technical;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\RenderContext;

/**
 * Task T5: Definition List Block Provider (Skeleton).
 */
class DefinitionListBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'definitionList';
    }

    public function label(): string
    {
        return 'Definition List';
    }

    public function category(): string
    {
        return 'structured';
    }

    public function sanitize(array $data): array
    {
        $rawItems = is_array($data['items'] ?? null) ? $data['items'] : [];
        $items = [];
        foreach ($rawItems as $item) {
            if (is_array($item)) {
                $items[] = [
                    'term' => $this->inline($item['term'] ?? ''),
                    'description' => $this->inline($item['description'] ?? ''),
                ];
            }
        }
        return ['items' => $items];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $items = (array)($block['data']['items'] ?? []);
        $html = ['<dl class="kc-def-list">'];
        foreach ($items as $item) {
            $t = (string)($item['term'] ?? '');
            $d = (string)($item['description'] ?? '');
            $html[] = "<dt class=\"kc-dt\">{$t}</dt><dd class=\"kc-dd\">{$d}</dd>";
        }
        $html[] = '</dl>';
        return implode("\n", $html);
    }
}
