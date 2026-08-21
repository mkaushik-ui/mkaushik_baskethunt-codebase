<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Technical;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\RenderContext;

/**
 * Task T5: Table Block Provider (Skeleton).
 */
class TableBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'table';
    }

    public function label(): string
    {
        return 'Table';
    }

    public function category(): string
    {
        return 'structured';
    }

    public function sanitize(array $data): array
    {
        return [
            'withHeadings' => !empty($data['withHeadings']),
            'content' => is_array($data['content'] ?? null) ? $data['content'] : [],
        ];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $data = $block['data'] ?? [];
        $withHeadings = !empty($data['withHeadings']);
        $rows = (array)($data['content'] ?? []);
        if (empty($rows)) {
            return '';
        }

        $html = ['<div class="kc-table-wrapper"><table class="kc-table">'];
        foreach ($rows as $rIdx => $row) {
            $html[] = '<tr>';
            foreach ((array)$row as $cell) {
                $cellText = htmlspecialchars((string)$cell, ENT_QUOTES, 'UTF-8');
                if ($rIdx === 0 && $withHeadings) {
                    $html[] = "<th>{$cellText}</th>";
                } else {
                    $html[] = "<td>{$cellText}</td>";
                }
            }
            $html[] = '</tr>';
        }
        $html[] = '</table></div>';
        return implode("\n", $html);
    }
}
