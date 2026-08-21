<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Legacy;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

/**
 * Task T7: Legacy HTML Wrapper Block Provider (Skeleton).
 * Wraps old TinyMCE HTML safely without destructive mass-conversion.
 */
class LegacyBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'legacy';
    }

    public function label(): string
    {
        return 'Legacy Content';
    }

    public function category(): string
    {
        return 'legacy';
    }

    public function sanitize(array $data): array
    {
        return [
            'html' => Html::sanitizeContent((string)($data['html'] ?? '')),
        ];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $html = (string)($block['data']['html'] ?? '');
        return "<div class=\"kc-legacy-content\">{$html}</div>";
    }
}
