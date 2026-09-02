<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Layout;

use SOI\Core\Content\BlockRegistry;
use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

/**
 * Responsive controlled grid layout block.
 *
 * Supports:
 * - Gap presets: sm, md, lg
 * - Column counts: 2, 3, 4
 * - Nested child blocks
 */
final class GridBlock extends AbstractBlock
{
    public const GAPS = ['sm', 'md', 'lg'];
    public const COLUMNS = [2, 3, 4];

    public function type(): string
    {
        return 'grid';
    }

    public function label(): string
    {
        return 'Grid';
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

    public function defaultData(): array
    {
        return [
            'gap' => 'md',
            'columns' => 2,
            'blocks' => [],
        ];
    }

    public function sanitize(array $data): array
    {
        $gap = $this->enum(
            $data['gap'] ?? 'md',
            self::GAPS,
            'md'
        );

        $columns = isset($data['columns']) && is_numeric($data['columns'])
            ? (int) $data['columns']
            : 2;

        if (!in_array($columns, self::COLUMNS, true)) {
            $columns = 2;
        }

        $blocks = is_array($data['blocks'] ?? null)
            ? $data['blocks']
            : [];

        return [
            'gap' => $gap,
            'columns' => $columns,
            'blocks' => $blocks,
        ];
    }

    public function validate(array $data): array
    {
        $errors = [];

        $gap = $data['gap'] ?? 'md';

        if (!in_array($gap, self::GAPS, true)) {
            $errors[] = "Invalid grid gap '{$gap}'.";
        }

        $columns = isset($data['columns']) && is_numeric($data['columns'])
            ? (int) $data['columns']
            : 2;

        if (!in_array($columns, self::COLUMNS, true)) {
            $errors[] = "Grid columns must be 2, 3, or 4.";
        }

        if (isset($data['blocks']) && !is_array($data['blocks'])) {
            $errors[] = 'Grid blocks must be an array.';
        }

        return $errors;
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $data = $this->sanitize($block['data'] ?? []);

        $gap = $data['gap'];
        $columns = $data['columns'];

        $blockId = Html::escape(
            (string) ($block['id'] ?? '')
        );

        /*
         * CSS variables are controlled by sanitized enum/integer values.
         * This prevents arbitrary CSS injection through block data.
         */
        $style = sprintf(
            '--kc-grid-columns:%d;--kc-grid-gap:var(--kc-grid-gap-%s);',
            $columns,
            Html::escape($gap)
        );

        $html = sprintf(
            '<div class="kc-block kc-block-grid" data-block-id="%s" data-block-type="grid" style="%s">',
            $blockId,
            $style
        );

        foreach ($data['blocks'] as $childBlock) {
            if (!is_array($childBlock)) {
            continue;
        }

        $type = (string) ($childBlock['type'] ?? '');

        if ($type === '') {
            continue;
        }

        $handler = BlockRegistry::get($type);

        $html .= $handler->render([
            'id' => (string) ($childBlock['id'] ?? ''),
            'type' => $type,
            'data' => is_array($childBlock['data'] ?? null)
                ? $childBlock['data']
                : [],
            ], $ctx);
        }
        $html .= '</div>';

        return $html;
    }
}