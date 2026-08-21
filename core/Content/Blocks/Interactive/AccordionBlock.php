<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Interactive;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\RenderContext;

/**
 * Task T5: Accordion Block Provider (Skeleton).
 */
class AccordionBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'accordion';
    }

    public function label(): string
    {
        return 'Accordion';
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
            'wide' => false,
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
                    'open' => !empty($item['open']),
                ];
            }
        }
        return ['items' => $items];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $items = (array)($block['data']['items'] ?? []);
        $html = ['<div class="kc-accordion" data-interactive="accordion">'];
        foreach ($items as $item) {
            $title = (string)($item['title'] ?? '');
            $content = (string)($item['content'] ?? '');
            $openAttr = !empty($item['open']) ? 'open' : '';
            $html[] = "<details class=\"kc-accordion-item\" {$openAttr}><summary class=\"kc-accordion-header\">{$title}</summary><div class=\"kc-accordion-body\">{$content}</div></details>";
        }
        $html[] = '</div>';
        return implode("\n", $html);
    }
}
