<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Basic;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\RenderContext;

/**
 * Task T3: Divider Block Provider (Skeleton).
 */
class DividerBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'divider';
    }

    public function label(): string
    {
        return 'Divider';
    }

    public function category(): string
    {
        return 'basic';
    }

    public function sanitize(array $data): array
    {
        return [];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        return '<hr class="kc-divider" />';
    }
}
