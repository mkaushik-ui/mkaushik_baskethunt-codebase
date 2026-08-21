<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Layout;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\RenderContext;

/**
 * Task T4: Columns Block Provider (Skeleton).
 */
class ColumnsBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'columns';
    }

    public function label(): string
    {
        return 'Columns';
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
        $layout = $this->enum($data['layout'] ?? '50-50', ['50-50', '33-67', '67-33', 'thirds', 'quarters'], '50-50');
        $rawCols = is_array($data['columns'] ?? null) ? $data['columns'] : [];
        $cols = [];
        foreach ($rawCols as $col) {
            if (is_array($col)) {
                $cols[] = [
                    'content' => (string)($col['content'] ?? ''),
                ];
            }
        }
        return [
            'layout' => $layout,
            'columns' => $cols,
        ];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $data = $block['data'] ?? [];
        $layout = (string)($data['layout'] ?? '50-50');
        $columns = (array)($data['columns'] ?? []);

        $html = ["<div class=\"kc-columns kc-columns-{$layout}\">"];
        foreach ($columns as $col) {
            $content = (string)($col['content'] ?? '');
            $html[] = "<div class=\"kc-column\">{$content}</div>";
        }
        $html[] = '</div>';
        return implode("\n", $html);
    }
}
