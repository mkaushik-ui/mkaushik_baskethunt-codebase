<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Media;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\RenderContext;

/**
 * Task T5: Link Card Block Provider (Skeleton).
 */
class LinkCardBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'link';
    }

    public function label(): string
    {
        return 'Link Card';
    }

    public function category(): string
    {
        return 'media';
    }

    public function sanitize(array $data): array
    {
        return [
            'url' => $this->text($data['url'] ?? ''),
            'title' => $this->inline($data['title'] ?? ''),
            'text' => $this->inline($data['text'] ?? ''),
        ];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $data = $block['data'] ?? [];
        $url = htmlspecialchars((string)($data['url'] ?? '#'), ENT_QUOTES, 'UTF-8');
        $title = (string)($data['title'] ?? '');
        $text = (string)($data['text'] ?? '');
        return "<a href=\"{$url}\" class=\"kc-link-card\" target=\"_blank\" rel=\"noopener noreferrer\"><h4>{$title}</h4><p>{$text}</p><span class=\"kc-link-url\">{$url}</span></a>";
    }
}
