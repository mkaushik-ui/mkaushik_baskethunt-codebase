<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Technical;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\Contracts\ProviderMetadataInterface;
use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

final class KeyValuesBlock extends AbstractBlock implements ProviderMetadataInterface
{
    public function type(): string
    {
        return 'keyValues';
    }

    public function label(): string
    {
        return 'Key / Values';
    }

    public function group(): string
    {
        return 'technical';
    }

    public function description(): string
    {
        return 'Key and value data pairs';
    }

    public function keywords(): string
    {
        return 'key value metadata specs properties table pair';
    }

    public function icon(): string
    {
        return '🔑';
    }

    public function editorType(): string
    {
        return 'keyValues';
    }

    public function data(): array
    {
        return $this->defaultData();
    }

    public function defaultData(): array
    {
        return [
            'title' => 'Specifications',
            'items' => [
                ['key' => 'Version', 'value' => '1.1.0'],
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
            $key = Html::plainText(Html::clampText((string) ($item['key'] ?? ''), 200));
            $value = Html::sanitizeInline((string) ($item['value'] ?? ''), 2000);
            if ($key === '' && Html::plainText($value) === '') {
                continue;
            }
            $items[] = ['key' => $key, 'value' => $value];
            if (count($items) >= 50) {
                break;
            }
        }

        return [
            'title' => Html::plainText(Html::clampText((string) ($data['title'] ?? ''), 200)),
            'items' => $items,
        ];
    }

    public function validate(array $data): array
    {
        return [];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $d = $this->sanitize($block['data'] ?? []);
        $items = $d['items'];
        $title = $d['title'];

        $blockId = isset($block['id']) ? Html::escape((string) $block['id']) : '';
        $dataAttr = $blockId !== '' ? ' data-block-id="' . $blockId . '"' : '';

        $html = '<div class="kc-block kc-block-keyvalues"' . $dataAttr . '>';
        if ($title !== '') {
            $html .= '<div class="kc-keyvalues-title">' . Html::escape($title) . '</div>';
        }
        if ($items !== []) {
            $html .= '<dl class="kc-keyvalues-list">';
            foreach ($items as $item) {
                $html .= '<div class="kc-keyvalues-row">';
                $html .= '<dt class="kc-keyvalues-key">' . Html::escape($item['key']) . '</dt>';
                $html .= '<dd class="kc-keyvalues-val">' . $item['value'] . '</dd>';
                $html .= '</div>';
            }
            $html .= '</dl>';
        }
        $html .= '</div>';

        return $html;
    }
}
