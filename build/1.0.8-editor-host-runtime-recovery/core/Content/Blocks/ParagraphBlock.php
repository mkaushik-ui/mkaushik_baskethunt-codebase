<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks;

use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

final class ParagraphBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'paragraph';
    }

    public function label(): string
    {
        return 'Paragraph';
    }

    public function sanitize(array $data): array
    {
        return [
            'text' => $this->inline($data['text'] ?? ''),
        ];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $text = (string) ($block['data']['text'] ?? '');
        if (trim(Html::plainText($text)) === '' && !str_contains($text, '<img')) {
            return '';
        }
        return '<p class="kc-block kc-block-paragraph" data-block-id="' . Html::escape($block['id']) . '">' . $text . '</p>';
    }
}
