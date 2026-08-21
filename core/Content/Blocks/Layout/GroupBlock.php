<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Layout;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\RenderContext;

/**
 * Task T4: Group / Container Block Provider (Skeleton).
 */
class GroupBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'group';
    }

    public function label(): string
    {
        return 'Group Container';
    }

    public function category(): string
    {
        return 'layout';
    }

    public function capabilities(): array
    {
        return [
            'nestable' => true,
            'reusable' => true,
            'wide' => true,
            'interactive' => false,
        ];
    }

    public function sanitize(array $data): array
    {
        return [
            'title' => $this->inline($data['title'] ?? ''),
            'content' => $this->text($data['content'] ?? ''),
        ];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $data = $block['data'] ?? [];
        $title = (string)($data['title'] ?? '');
        $content = (string)($data['content'] ?? '');
        $html = ['<div class="kc-group-container">'];
        if ($title !== '') {
            $html[] = "<div class=\"kc-group-header\"><h3>{$title}</h3></div>";
        }
        $html[] = "<div class=\"kc-group-content\">{$content}</div>";
        $html[] = '</div>';
        return implode("\n", $html);
    }
}
