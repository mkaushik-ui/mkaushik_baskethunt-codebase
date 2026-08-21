<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Basic;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\RenderContext;

/**
 * Task T3: Callout Block Provider (Skeleton).
 */
class CalloutBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'callout';
    }

    public function label(): string
    {
        return 'Callout';
    }

    public function category(): string
    {
        return 'notice';
    }

    public function sanitize(array $data): array
    {
        return [
            'tone' => $this->enum($data['tone'] ?? 'info', ['info', 'note', 'tip', 'warning', 'danger', 'success'], 'info'),
            'title' => $this->inline($data['title'] ?? ''),
            'text' => $this->inline($data['text'] ?? ''),
        ];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $data = $block['data'] ?? [];
        $tone = (string)($data['tone'] ?? 'info');
        $title = (string)($data['title'] ?? '');
        $text = (string)($data['text'] ?? '');

        $html = ["<aside class=\"kc-callout kc-callout-{$tone}\" role=\"note\">"];
        if ($title !== '') {
            $html[] = "<strong class=\"kc-callout-title\">{$title}</strong>";
        }
        $html[] = "<div class=\"kc-callout-body\">{$text}</div>";
        $html[] = '</aside>';
        return implode("\n", $html);
    }
}
