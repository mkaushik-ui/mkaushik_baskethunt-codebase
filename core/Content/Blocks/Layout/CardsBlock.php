<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Layout;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\RenderContext;

/**
 * Task T4: Cards Block Provider (Skeleton).
 */
class CardsBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'cards';
    }

    public function label(): string
    {
        return 'Cards Grid';
    }

    public function category(): string
    {
        return 'layout';
    }

    public function sanitize(array $data): array
    {
        $rawItems = is_array($data['items'] ?? null) ? $data['items'] : [];
        $items = [];
        foreach ($rawItems as $item) {
            if (is_array($item)) {
                $items[] = [
                    'title' => $this->inline($item['title'] ?? ''),
                    'description' => $this->inline($item['description'] ?? ''),
                    'icon' => $this->text($item['icon'] ?? '★', 10),
                    'linkUrl' => $this->text($item['linkUrl'] ?? ''),
                ];
            }
        }
        return ['items' => $items];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $items = (array)($block['data']['items'] ?? []);
        $html = ['<div class="kc-cards-grid">'];
        foreach ($items as $item) {
            $title = (string)($item['title'] ?? '');
            $desc = (string)($item['description'] ?? '');
            $icon = (string)($item['icon'] ?? '★');
            $link = (string)($item['linkUrl'] ?? '#');
            $html[] = "<a href=\"{$link}\" class=\"kc-card\"><span class=\"kc-card-icon\">{$icon}</span><h4>{$title}</h4><p>{$desc}</p></a>";
        }
        $html[] = '</div>';
        return implode("\n", $html);
    }
}
