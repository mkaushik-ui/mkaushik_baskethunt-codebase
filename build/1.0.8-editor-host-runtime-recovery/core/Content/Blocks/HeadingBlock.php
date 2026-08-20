<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks;

use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

final class HeadingBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'heading';
    }

    public function label(): string
    {
        return 'Heading';
    }

    public function sanitize(array $data): array
    {
        $level = (int) ($data['level'] ?? 2);
        if ($level < 2 || $level > 6) {
            $level = 2;
        }
        return [
            'text' => $this->inline($data['text'] ?? '', 5000),
            'level' => $level,
        ];
    }

    public function validate(array $data): array
    {
        $errors = [];
        $level = (int) ($data['level'] ?? 2);
        if ($level < 2 || $level > 6) {
            $errors[] = 'Heading level must be between 2 and 6.';
        }
        return $errors;
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $text = (string) ($block['data']['text'] ?? '');
        $plain = Html::plainText($text);
        if ($plain === '') {
            return '';
        }
        $level = (int) ($block['data']['level'] ?? 2);
        $level = max(2, min(6, $level));
        $anchor = $ctx->headingAnchor($plain);
        $ctx->headings[] = [
            'id' => $anchor,
            'level' => $level,
            'text' => $plain,
        ];
        $tag = 'h' . $level;
        return '<' . $tag . ' class="kc-block kc-block-heading" id="' . Html::escape($anchor) . '" data-block-id="' . Html::escape($block['id']) . '">'
            . $text
            . '</' . $tag . '>';
    }

    public function outlineTitle(array $data): ?string
    {
        $text = Html::plainText((string) ($data['text'] ?? ''));
        return $text !== '' ? $text : null;
    }
}
