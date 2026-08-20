<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks;

use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

final class QuoteBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'quote';
    }

    public function label(): string
    {
        return 'Quote';
    }

    public function sanitize(array $data): array
    {
        return [
            'text' => $this->inline($data['text'] ?? '', 20000),
            'caption' => $this->inline($data['caption'] ?? '', 2000),
        ];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $text = (string) ($block['data']['text'] ?? '');
        if (Html::plainText($text) === '') {
            return '';
        }
        $caption = (string) ($block['data']['caption'] ?? '');
        $html = '<blockquote class="kc-block kc-block-quote" data-block-id="' . Html::escape($block['id']) . '">';
        $html .= '<div class="kc-quote-text">' . $text . '</div>';
        if (Html::plainText($caption) !== '') {
            $html .= '<footer class="kc-quote-caption">' . $caption . '</footer>';
        }
        return $html . '</blockquote>';
    }
}
