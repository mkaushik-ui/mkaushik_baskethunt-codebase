<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks;

use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

/**
 * Structured wrapper for related content. Stores sanitized body text only
 * (no free CSS / absolute positioning).
 */
final class GroupBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'group';
    }

    public function label(): string
    {
        return 'Group';
    }

    public function sanitize(array $data): array
    {
        return [
            'title' => Html::plainText(Html::clampText((string) ($data['title'] ?? ''), 200)),
            'content' => Html::sanitizeInline((string) ($data['content'] ?? ''), 40000),
        ];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $title = Html::plainText((string) ($block['data']['title'] ?? ''));
        $content = (string) ($block['data']['content'] ?? '');
        if ($title === '' && Html::plainText($content) === '') {
            return '';
        }
        $html = '<section class="kc-block kc-block-group" data-block-id="' . Html::escape($block['id']) . '">';
        if ($title !== '') {
            $html .= '<h3 class="kc-group-title">' . Html::escape($title) . '</h3>';
        }
        if (Html::plainText($content) !== '') {
            $html .= '<div class="kc-group-content">' . $content . '</div>';
        }
        return $html . '</section>';
    }
}
