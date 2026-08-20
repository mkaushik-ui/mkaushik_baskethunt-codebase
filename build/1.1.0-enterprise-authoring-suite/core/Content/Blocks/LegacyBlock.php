<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks;

use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

/**
 * Preserves unsanitized-but-scrubbed TinyMCE HTML when an author
 * opts a legacy document into the structured editor.
 */
final class LegacyBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'legacy';
    }

    public function label(): string
    {
        return 'Legacy content';
    }

    public function sanitize(array $data): array
    {
        return [
            'html' => Html::sanitizeLegacy((string) ($data['html'] ?? $data['text'] ?? '')),
        ];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $html = (string) ($block['data']['html'] ?? '');
        if (trim($html) === '') {
            return '';
        }
        return '<div class="kc-block kc-block-legacy" data-block-id="' . Html::escape($block['id']) . '">' . $html . '</div>';
    }
}
