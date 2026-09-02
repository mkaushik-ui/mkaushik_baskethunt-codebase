<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Basic;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\Contracts\ProviderMetadataInterface;
use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

class HeadingBlock extends AbstractBlock implements ProviderMetadataInterface
{
    public function type(): string
    {
        return 'heading';
    }

    public function label(): string
    {
        return 'Heading';
    }

    public function group(): string
    {
        return 'basic';
    }

    public function description(): string
    {
        return 'Section heading H1–H6';
    }

    public function keywords(): string
    {
        return 'heading title header h1 h2 h3 h4 h5 h6';
    }

    public function icon(): string
    {
        return 'H';
    }

    public function editorType(): string
    {
        return 'heading';
    }

    public function data(): array
    {
        return $this->defaultData();
    }

    public function defaultData(): array
    {
        return [
            'text' => 'Heading',
            'level' => 2,
        ];
    }

    public function sanitize(array $data): array
    {
        $level = (int) ($data['level'] ?? 2);
        if ($level < 1 || $level > 6) {
            $level = 2;
        }
        return [
            'text' => Html::sanitizeInline((string) ($data['text'] ?? ''), 1000),
            'level' => $level,
            'anchor' => Html::plainText((string) ($data['anchor'] ?? '')),
        ];
    }

    public function validate(array $data): array
    {
        return [];
    }

    public function outlineTitle(array $data): ?string
    {
        return Html::plainText((string) ($data['text'] ?? ''));
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $data = is_array($block['data'] ?? null) ? $block['data'] : [];
        $level = (int) ($data['level'] ?? 2);
        if ($level < 1 || $level > 6) {
            $level = 2;
        }
        $text = (string) ($data['text'] ?? '');
        $anchor = (string) ($data['anchor'] ?? '');
        if ($anchor === '') {
            $anchor = $ctx->headingAnchor($text);
        } else {
            $ctx->usedAnchors[$anchor] = true;
        }
        $ctx->headings[] = [
            'id' => $anchor,
            'level' => $level,
            'text' => Html::plainText($text),
        ];
        $tag = 'h' . $level;
        return "<{$tag} id=\"" . Html::escape($anchor) . "\" class=\"kc-heading kc-heading-level-{$level}\">{$text}</{$tag}>";
    }
}
