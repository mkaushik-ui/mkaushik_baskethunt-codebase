<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Knowledge;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\Contracts\ProviderMetadataInterface;
use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

final class StepsBlock extends AbstractBlock implements ProviderMetadataInterface
{
    private const MAX_STEPS = 50;

    public function type(): string
    {
        return 'steps';
    }

    public function label(): string
    {
        return 'Steps List';
    }

    public function group(): string
    {
        return 'knowledge';
    }

    public function description(): string
    {
        return 'Numbered step-by-step procedure guide';
    }

    public function keywords(): string
    {
        return 'steps procedure guide tutorial sequence process timeline';
    }

    public function icon(): string
    {
        return '🔢';
    }

    public function editorType(): string
    {
        return 'steps';
    }

    public function data(): array
    {
        return $this->defaultData();
    }

    public function defaultData(): array
    {
        return [
            'items' => [
                ['title' => 'Step 1', 'content' => 'Description for step 1'],
            ],
        ];
    }

    public function sanitize(array $data): array
    {
        $rawItems = is_array($data['items'] ?? null) ? $data['items'] : [];
        $items = [];
        foreach (array_values($rawItems) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = Html::plainText(Html::clampText((string) ($item['title'] ?? ''), 300));
            $content = Html::sanitizeInline((string) ($item['content'] ?? ''), 20000);
            if ($title === '' && Html::plainText($content) === '') {
                continue;
            }
            $items[] = ['title' => $title, 'content' => $content];
            if (count($items) >= self::MAX_STEPS) {
                break;
            }
        }
        return ['items' => $items];
    }

    public function validate(array $data): array
    {
        return [];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $d = $this->sanitize($block['data'] ?? []);
        $items = $d['items'];
        if ($items === []) {
            return '';
        }

        $blockId = isset($block['id']) ? Html::escape((string) $block['id']) : '';
        $dataAttr = $blockId !== '' ? ' data-block-id="' . $blockId . '"' : '';

        $html = '<ol class="kc-block kc-block-steps"' . $dataAttr . '>';
        foreach ($items as $idx => $item) {
            $num = $idx + 1;
            $title = $item['title'] !== '' ? $item['title'] : 'Step ' . $num;
            $html .= '<li class="kc-step-item" data-step-number="' . $num . '">';
            $html .= '<div class="kc-step-header"><span class="kc-step-badge">' . $num . '</span><h4 class="kc-step-title">' . Html::escape($title) . '</h4></div>';
            $html .= '<div class="kc-step-body">' . $item['content'] . '</div>';
            $html .= '</li>';
        }
        $html .= '</ol>';

        return $html;
    }
}
