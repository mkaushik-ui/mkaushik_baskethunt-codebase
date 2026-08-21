<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Basic;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\RenderContext;

/**
 * Task T3: Quote Block Provider (Skeleton).
 */
class QuoteBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'quote';
    }

    public function label(): string
    {
        return 'Quote';
    }

    public function category(): string
    {
        return 'basic';
    }

    public function sanitize(array $data): array
    {
        return [
            'text' => $this->inline($data['text'] ?? ''),
            'caption' => $this->inline($data['caption'] ?? ''),
        ];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $data = $block['data'] ?? [];
        $text = (string)($data['text'] ?? '');
        $caption = (string)($data['caption'] ?? '');
        $out = "<blockquote class=\"kc-quote\"><p>{$text}</p>";
        if ($caption !== '') {
            $out .= "<cite>{$caption}</cite>";
        }
        $out .= '</blockquote>';
        return $out;
    }
}
