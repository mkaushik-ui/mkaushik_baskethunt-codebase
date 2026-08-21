<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Interactive;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\RenderContext;

/**
 * Task T5: Tabs Block Provider (Skeleton).
 */
class TabsBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'tabs';
    }

    public function label(): string
    {
        return 'Tabs';
    }

    public function category(): string
    {
        return 'structured';
    }

    public function capabilities(): array
    {
        return [
            'nestable' => true,
            'reusable' => true,
            'wide' => true,
            'interactive' => true,
        ];
    }

    public function sanitize(array $data): array
    {
        $rawItems = is_array($data['items'] ?? null) ? $data['items'] : [];
        $items = [];
        foreach ($rawItems as $item) {
            if (is_array($item)) {
                $items[] = [
                    'title' => $this->inline($item['title'] ?? ''),
                    'content' => $this->inline($item['content'] ?? ''),
                ];
            }
        }
        return ['items' => $items];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $items = (array)($block['data']['items'] ?? []);
        if (empty($items)) {
            return '';
        }
        $html = ['<div class="kc-tabs-container" data-interactive="tabs">'];
        $html[] = '<div class="kc-tabs-nav">';
        foreach ($items as $idx => $item) {
            $t = (string)($item['title'] ?? 'Tab');
            $activeClass = ($idx === 0) ? 'is-active' : '';
            $html[] = "<button type=\"button\" class=\"kc-tab-nav-btn {$activeClass}\" data-tab-index=\"{$idx}\">{$t}</button>";
        }
        $html[] = '</div><div class="kc-tabs-panels">';
        foreach ($items as $idx => $item) {
            $c = (string)($item['content'] ?? '');
            $hiddenAttr = ($idx === 0) ? '' : 'hidden';
            $html[] = "<div class=\"kc-tab-panel\" data-tab-index=\"{$idx}\" {$hiddenAttr}>{$c}</div>";
        }
        $html[] = '</div></div>';
        return implode("\n", $html);
    }
}
