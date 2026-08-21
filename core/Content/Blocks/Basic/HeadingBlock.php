<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Basic;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

/**
 * Task T3: Heading Block Provider (Skeleton).
 */
class HeadingBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'heading';
    }

    public function label(): string
    {
        return 'Heading';
    }

    public function category(): string
    {
        return 'basic';
    }

    public function sanitize(array $data): array
    {
        $level = (int)($data['level'] ?? 2);
        if ($level < 2 || $level > 6) {
            $level = 2;
        }
        return [
            'text' => $this->inline($data['text'] ?? ''),
            'level' => $level,
            'anchor' => $this->text($data['anchor'] ?? ''),
        ];
    }

    public function outlineTitle(array $data): ?string
    {
        return Html::plainText((string)($data['text'] ?? ''));
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $data = $block['data'] ?? [];
        $level = (int)($data['level'] ?? 2);
        $text = (string)($data['text'] ?? '');
        $anchor = (string)($data['anchor'] ?? '');
        if ($anchor === '') {
            $anchor = Html::slug($text);
        }
        $tag = 'h' . $level;
        return "<{$tag} id=\"{$anchor}\" class=\"kc-heading\">{$text}</{$tag}>";
    }
}
