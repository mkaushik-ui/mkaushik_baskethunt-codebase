<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Knowledge;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\RenderContext;

/**
 * Task T3: FAQ Block Provider (Skeleton).
 */
class FaqBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'faq';
    }

    public function label(): string
    {
        return 'FAQ';
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
                    'question' => $this->inline($item['question'] ?? ''),
                    'answer' => $this->inline($item['answer'] ?? ''),
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
        $html = ['<div class="kc-faq" itemscope itemtype="https://schema.org/FAQPage">'];
        foreach ($items as $item) {
            $q = (string)($item['question'] ?? '');
            $a = (string)($item['answer'] ?? '');
            $html[] = "<div class=\"kc-faq-item\" itemscope itemprop=\"mainEntity\" itemtype=\"https://schema.org/Question\"><strong itemprop=\"name\">{$q}</strong><div itemscope itemprop=\"acceptedAnswer\" itemtype=\"https://schema.org/Answer\"><p itemprop=\"text\">{$a}</p></div></div>";
        }
        $html[] = '</div>';
        return implode("\n", $html);
    }
}
