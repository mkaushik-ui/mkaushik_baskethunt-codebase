<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Layout;

use SOI\Core\Content\BlockRegistry;
use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\Contracts\ProviderMetadataInterface;
use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

class ColumnsBlock extends AbstractBlock implements ProviderMetadataInterface
{
    public const LAYOUTS = [
        '50-50',
        '33-33-33',
        '25-25-25-25',
        '66-33',
        '33-66',
    ];

    public function type(): string
    {
        return 'columns';
    }

    public function label(): string
    {
        return 'Columns';
    }

    public function group(): string
    {
        return 'layout';
    }

    public function description(): string
    {
        return 'Multi-column layout container';
    }

    public function keywords(): string
    {
        return 'columns layout grid container 50-50 33-33-33 flex';
    }

    public function icon(): string
    {
        return '▥';
    }

    public function editorType(): string
    {
        return 'columns';
    }

    public function data(): array
    {
        return $this->defaultData();
    }

    public function defaultData(): array
    {
        return [
            'layout' => '50-50',
            'columns' => [
                ['blocks' => [], 'content' => ''],
                ['blocks' => [], 'content' => ''],
            ],
        ];
    }

    public function sanitize(array $data): array
    {
        $rawLayout = (string) ($data['layout'] ?? '50-50');
        $layout = in_array($rawLayout, self::LAYOUTS, true) ? $rawLayout : '50-50';

        $columnCount = match ($layout) {
            '33-33-33' => 3,
            '25-25-25-25' => 4,
            default => 2,
        };

        $columns = [];
        $inputColumns = is_array($data['columns'] ?? null) ? $data['columns'] : [];

        for ($i = 0; $i < $columnCount; $i++) {
            $col = is_array($inputColumns[$i] ?? null) ? $inputColumns[$i] : [];
            $blocks = [];
            if (is_array($col['blocks'] ?? null)) {
                foreach ($col['blocks'] as $childBlock) {
                    if (!is_array($childBlock)) {
                        continue;
                    }
                    $childType = (string) ($childBlock['type'] ?? '');
                    if ($childType === '' || $childType === 'columns') {
                        continue;
                    }
                    $childData = is_array($childBlock['data'] ?? null) ? $childBlock['data'] : [];
                    if (BlockRegistry::has($childType)) {
                        $childHandler = BlockRegistry::get($childType);
                        $childData = $childHandler->sanitize($childData);
                    }
                    $blocks[] = [
                        'id' => (string) ($childBlock['id'] ?? ''),
                        'type' => $childType,
                        'data' => $childData,
                    ];
                }
            }
            $content = (string) ($col['content'] ?? '');
            $columns[] = [
                'blocks' => $blocks,
                'content' => Html::sanitizeInline($content, 10000),
            ];
        }

        return [
            'layout' => $layout,
            'columns' => $columns,
        ];
    }

    public function validate(array $data): array
    {
        return [];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $data = $this->sanitize(is_array($block['data'] ?? null) ? $block['data'] : []);
        $layout = $data['layout'];
        $columns = $data['columns'];

        $blockId = isset($block['id']) ? Html::escape((string) $block['id']) : '';
        $dataAttr = $blockId !== '' ? ' data-block-id="' . $blockId . '"' : '';

        $html = '<div class="kc-block kc-block-columns kc-columns-' . Html::escape($layout) . '"' . $dataAttr . '>';
        foreach ($columns as $index => $column) {
            $html .= '<div class="kc-column kc-column-' . ($index + 1) . '" data-col-index="' . $index . '">';
            if (!empty($column['content'])) {
                $html .= $column['content'];
            }
            if (!empty($column['blocks']) && is_array($column['blocks'])) {
                foreach ($column['blocks'] as $childBlock) {
                    if (!is_array($childBlock)) {
                        continue;
                    }
                    $childType = (string) ($childBlock['type'] ?? '');
                    if ($childType === '' || $childType === 'columns' || !BlockRegistry::has($childType)) {
                        continue;
                    }
                    $childHandler = BlockRegistry::get($childType);
                    $html .= $childHandler->render($childBlock, $ctx);
                }
            }
            $html .= '</div>';
        }
        $html .= '</div>';

        return $html;
    }
}
