<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Basic;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

/**
 * Task T3: Paragraph Block Provider (Skeleton).
 */
class ParagraphBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'paragraph';
    }

    public function label(): string
    {
        return 'Paragraph';
    }

    public function category(): string
    {
        return 'basic';
    }

    public function sanitize(array $data): array
    {
        return [
            'text' => $this->inline($data['text'] ?? ''),
        ];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $text = (string)($block['data']['text'] ?? '');
        if ($text === '') {
            return '';
        }
        return '<p class="kc-paragraph">' . $text . '</p>';
    }
}
