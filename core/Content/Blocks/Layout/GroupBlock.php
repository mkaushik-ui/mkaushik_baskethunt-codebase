<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Layout;

use SOI\Core\Content\BlockRegistry;
use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\Contracts\ProviderMetadataInterface;
use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

class GroupBlock extends AbstractBlock implements ProviderMetadataInterface
{
    public const ALLOWED_VARIANTS = ['default', 'card', 'callout', 'sidebar', 'hero', 'highlight'];

    public function type(): string
    {
        return 'group';
    }

    public function label(): string
    {
        return 'Group Container';
    }

    public function group(): string
    {
        return 'layout';
    }

    public function description(): string
    {
        return 'Logical container grouping multiple child blocks';
    }

    public function keywords(): string
    {
        return 'group container section wrapper card highlight box';
    }

    public function icon(): string
    {
        return '📦';
    }

    public function editorType(): string
    {
        return 'group';
    }

    public function data(): array
    {
        return $this->defaultData();
    }

    public function defaultData(): array
    {
        return [
            'variant' => 'default',
            'blocks' => [],
        ];
    }

    public function sanitize(array $data): array
    {
        $variant = strtolower(trim((string) ($data['variant'] ?? 'default')));
        if (!in_array($variant, self::ALLOWED_VARIANTS, true)) {
            $variant = 'default';
        }

        $inputBlocks = is_array($data['blocks'] ?? null) ? $data['blocks'] : [];
        $blocks = [];
        foreach ($inputBlocks as $b) {
            if (!is_array($b)) {
                continue;
            }
            $t = (string) ($b['type'] ?? '');
            if ($t === '' || $t === 'group') {
                continue;
            }
            $d = is_array($b['data'] ?? null) ? $b['data'] : [];
            if (BlockRegistry::has($t)) {
                $d = BlockRegistry::get($t)->sanitize($d);
            }
            $blocks[] = [
                'id' => (string) ($b['id'] ?? ''),
                'type' => $t,
                'data' => $d,
            ];
            if (count($blocks) >= 50) {
                break;
            }
        }

        return [
            'variant' => $variant,
            'title' => Html::plainText(Html::clampText((string) ($data['title'] ?? ''), 200)),
            'blocks' => $blocks,
        ];
    }

    public function validate(array $data): array
    {
        return [];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $d = $this->sanitize($block['data'] ?? []);
        $variant = $d['variant'];
        $title = $d['title'];
        $blocks = $d['blocks'];

        $blockId = isset($block['id']) ? Html::escape((string) $block['id']) : '';
        $dataAttr = $blockId !== '' ? ' data-block-id="' . $blockId . '"' : '';

        $html = '<div class="kc-block kc-block-group kc-group-' . Html::escape($variant) . '"' . $dataAttr . '>';
        if ($title !== '') {
            $html .= '<div class="kc-group-header"><h3>' . Html::escape($title) . '</h3></div>';
        }
        $html .= '<div class="kc-group-body">';
        foreach ($blocks as $childBlock) {
            if (!is_array($childBlock)) {
                continue;
            }
            $childType = (string) ($childBlock['type'] ?? '');
            if ($childType === '' || $childType === 'group' || !BlockRegistry::has($childType)) {
                continue;
            }
            $handler = BlockRegistry::get($childType);
            $html .= $handler->render($childBlock, $ctx);
        }
        $html .= '</div></div>';

        return $html;
    }
}
