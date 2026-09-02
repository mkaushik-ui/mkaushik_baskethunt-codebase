<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Interactive;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\Contracts\ProviderMetadataInterface;
use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

final class TabsBlock extends AbstractBlock implements ProviderMetadataInterface
{
    private const MAX_TABS = 20;

    public function type(): string
    {
        return 'tabs';
    }

    public function label(): string
    {
        return 'Tabs';
    }

    public function group(): string
    {
        return 'interactive';
    }

    public function description(): string
    {
        return 'Tabbed content container';
    }

    public function keywords(): string
    {
        return 'tabs tab container panel interactive navigation';
    }

    public function icon(): string
    {
        return '⬒';
    }

    public function editorType(): string
    {
        return 'tabs';
    }

    public function data(): array
    {
        return $this->defaultData();
    }

    public function defaultData(): array
    {
        return [
            'items' => [
                ['title' => 'Tab 1', 'content' => 'Tab 1 content'],
                ['title' => 'Tab 2', 'content' => 'Tab 2 content'],
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
            $title = Html::plainText(Html::clampText((string) ($item['title'] ?? ''), 200));
            $content = Html::sanitizeInline((string) ($item['content'] ?? ''), 20000);
            if ($title === '' && Html::plainText($content) === '') {
                continue;
            }
            $items[] = ['title' => $title, 'content' => $content];
            if (count($items) >= self::MAX_TABS) {
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
        $data = $this->sanitize(is_array($block['data'] ?? null) ? $block['data'] : []);
        $items = $data['items'];
        if ($items === []) {
            return '';
        }

        $id = isset($block['id']) ? Html::escape((string) $block['id']) : 'tabs-' . substr(md5(uniqid('', true)), 0, 8);
        $dataAttr = ' data-block-id="' . $id . '"';

        $html = '<div class="kc-block kc-block-tabs"' . $dataAttr . '>';
        $html .= '<div class="kc-tabs-header" role="tablist">';
        foreach ($items as $idx => $item) {
            $active = $idx === 0 ? ' is-active' : '';
            $aria = $idx === 0 ? ' true' : ' false';
            $html .= '<button type="button" class="kc-tab-btn' . $active . '" role="tab" aria-selected="' . trim($aria) . '" data-tab-index="' . $idx . '">' . Html::escape($item['title'] !== '' ? $item['title'] : 'Tab ' . ($idx + 1)) . '</button>';
        }
        $html .= '</div><div class="kc-tabs-body">';
        foreach ($items as $idx => $item) {
            $active = $idx === 0 ? ' is-active' : '';
            $html .= '<div class="kc-tab-panel' . $active . '" role="tabpanel" data-tab-index="' . $idx . '">' . $item['content'] . '</div>';
        }
        $html .= '</div></div>';

        return $html;
    }
}
