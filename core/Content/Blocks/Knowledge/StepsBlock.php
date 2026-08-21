<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Knowledge;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\RenderContext;

/**
 * Task T3: Steps Block Provider (Skeleton).
 */
class StepsBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'steps';
    }

    public function label(): string
    {
        return 'Steps';
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
        $html = ['<div class="kc-steps">'];
        foreach ($items as $idx => $step) {
            $num = $idx + 1;
            $title = (string)($step['title'] ?? '');
            $content = (string)($step['content'] ?? '');
            $html[] = "<div class=\"kc-step\"><span class=\"kc-step-num\">{$num}</span><div class=\"kc-step-body\"><h4>{$title}</h4><p>{$content}</p></div></div>";
        }
        $html[] = '</div>';
        return implode("\n", $html);
    }
}
