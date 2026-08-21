<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Technical;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\RenderContext;

/**
 * Task T5: Key / Value Block Provider (Skeleton).
 */
class KeyValuesBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'keyValues';
    }

    public function label(): string
    {
        return 'Key / Value';
    }

    public function category(): string
    {
        return 'technical';
    }

    public function sanitize(array $data): array
    {
        $rawItems = is_array($data['items'] ?? null) ? $data['items'] : [];
        $items = [];
        foreach ($rawItems as $item) {
            if (is_array($item)) {
                $items[] = [
                    'key' => $this->inline($item['key'] ?? ''),
                    'value' => $this->inline($item['value'] ?? ''),
                ];
            }
        }
        return [
            'title' => $this->inline($data['title'] ?? ''),
            'items' => $items,
        ];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $items = (array)($block['data']['items'] ?? []);
        $html = ['<div class="kc-key-values">'];
        foreach ($items as $item) {
            $k = (string)($item['key'] ?? '');
            $v = (string)($item['value'] ?? '');
            $html[] = "<div class=\"kc-kv-row\"><span class=\"kc-kv-key\">{$k}</span><span class=\"kc-kv-val\">{$v}</span></div>";
        }
        $html[] = '</div>';
        return implode("\n", $html);
    }
}
