<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks;

use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

final class DividerBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'divider';
    }

    public function label(): string
    {
        return 'Divider';
    }

    public function sanitize(array $data): array
    {
        return [];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        return '<hr class="kc-block kc-block-divider" data-block-id="' . Html::escape($block['id']) . '">';
    }
}
