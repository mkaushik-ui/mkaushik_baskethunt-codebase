<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks;

use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

final class TableBlock extends AbstractBlock
{
    private const MAX_ROWS = 80;
    private const MAX_COLS = 16;

    public function type(): string
    {
        return 'table';
    }

    public function label(): string
    {
        return 'Table';
    }

    public function sanitize(array $data): array
    {
        $rows = is_array($data['content'] ?? null) ? $data['content'] : [];
        $normalized = [];
        foreach (array_slice(array_values($rows), 0, self::MAX_ROWS) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cells = [];
            foreach (array_slice(array_values($row), 0, self::MAX_COLS) as $cell) {
                $cells[] = $this->inline(is_scalar($cell) ? (string) $cell : '', 4000);
            }
            if ($cells !== []) {
                $normalized[] = $cells;
            }
        }
        return [
            'withHeadings' => !empty($data['withHeadings']),
            'content' => $normalized,
        ];
    }

    public function validate(array $data): array
    {
        return [];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $rows = $block['data']['content'] ?? [];
        if (!is_array($rows) || $rows === []) {
            return '';
        }
        $withHeadings = !empty($block['data']['withHeadings']);
        $html = '<div class="kc-block kc-block-table-wrap" data-block-id="' . Html::escape($block['id']) . '">';
        $html .= '<table class="kc-block-table">';
        foreach (array_values($rows) as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $isHead = $withHeadings && $i === 0;
            $html .= $isHead ? '<thead><tr>' : '<tr>';
            $cellTag = $isHead ? 'th' : 'td';
            foreach ($row as $cell) {
                $html .= '<' . $cellTag . '>' . (string) $cell . '</' . $cellTag . '>';
            }
            $html .= $isHead ? '</tr></thead><tbody>' : '</tr>';
        }
        if ($withHeadings && count($rows) > 1) {
            $html .= '</tbody>';
        }
        return $html . '</table></div>';
    }
}
