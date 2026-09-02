<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Technical;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\Contracts\ProviderMetadataInterface;
use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

class CodeGroupBlock extends AbstractBlock implements ProviderMetadataInterface
{
    public function type(): string
    {
        return 'codeGroup';
    }

    public function label(): string
    {
        return 'Code Group';
    }

    public function group(): string
    {
        return 'technical';
    }

    public function description(): string
    {
        return 'Multi-tabbed code block group';
    }

    public function keywords(): string
    {
        return 'code group tabs multi language snippet syntax';
    }

    public function icon(): string
    {
        return '📑';
    }

    public function editorType(): string
    {
        return 'codeGroup';
    }

    public function data(): array
    {
        return $this->defaultData();
    }

    public function defaultData(): array
    {
        return [
            'items' => [
                ['label' => 'JS', 'language' => 'javascript', 'code' => 'console.log("Hello");'],
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
            $label = Html::plainText(Html::clampText((string) ($item['label'] ?? 'Code'), 50));
            $lang = Html::plainText(Html::clampText((string) ($item['language'] ?? 'text'), 30));
            $code = Html::clampText((string) ($item['code'] ?? ''), 100000);
            if ($label === '' && $code === '') {
                continue;
            }
            $items[] = [
                'label' => $label,
                'language' => $lang,
                'code' => $code,
            ];
            if (count($items) >= 20) {
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

        $html = '<div class="kc-block kc-block-codegroup kc-code-group" data-interactive="tabs"' . $dataAttr . '>';
        $html .= '<div class="kc-code-tabs">';
        foreach ($items as $idx => $item) {
            $activeClass = ($idx === 0) ? ' is-active' : '';
            $html .= '<button type="button" class="kc-code-tab' . $activeClass . '" data-tab-idx="' . $idx . '">' . Html::escape($item['label']) . '</button>';
        }
        $html .= '</div><div class="kc-code-panels">';
        foreach ($items as $idx => $item) {
            $hiddenAttr = ($idx === 0) ? '' : ' hidden="hidden"';
            $html .= '<pre class="kc-code-panel" data-panel-idx="' . $idx . '"' . $hiddenAttr . '><code class="language-' . Html::escape($item['language']) . '">' . Html::escape($item['code']) . '</code></pre>';
        }
        $html .= '</div></div>';

        return $html;
    }
}
