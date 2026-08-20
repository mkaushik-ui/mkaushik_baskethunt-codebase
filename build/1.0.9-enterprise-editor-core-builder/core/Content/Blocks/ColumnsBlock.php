<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks;

use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

/**
 * Controlled responsive columns. No free positioning or arbitrary CSS.
 */
final class ColumnsBlock extends AbstractBlock
{
    public const LAYOUTS = ['50-50', '33-67', '67-33', '33-33-33'];

    public function type(): string
    {
        return 'columns';
    }

    public function label(): string
    {
        return 'Columns';
    }

    public function sanitize(array $data): array
    {
        $layout = $this->enum($data['layout'] ?? '50-50', self::LAYOUTS, '50-50');
        $count = $layout === '33-33-33' ? 3 : 2;
        $columnsIn = is_array($data['columns'] ?? null) ? array_values($data['columns']) : [];
        $columns = [];
        for ($i = 0; $i < $count; $i++) {
            $col = is_array($columnsIn[$i] ?? null) ? $columnsIn[$i] : [];
            $columns[] = [
                'content' => Html::sanitizeInline((string) ($col['content'] ?? ''), 20000),
            ];
        }
        return [
            'layout' => $layout,
            'columns' => $columns,
        ];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $layout = $this->enum($block['data']['layout'] ?? '50-50', self::LAYOUTS, '50-50');
        $columns = is_array($block['data']['columns'] ?? null) ? $block['data']['columns'] : [];
        if ($columns === []) {
            return '';
        }
        $html = '<div class="kc-block kc-block-columns kc-columns-' . Html::escape($layout) . '" data-block-id="' . Html::escape($block['id']) . '" data-layout="' . Html::escape($layout) . '">';
        foreach ($columns as $col) {
            if (!is_array($col)) {
                continue;
            }
            $content = (string) ($col['content'] ?? '');
            $html .= '<div class="kc-column">';
            $html .= Html::plainText($content) !== '' ? $content : '&nbsp;';
            $html .= '</div>';
        }
        return $html . '</div>';
    }
}
