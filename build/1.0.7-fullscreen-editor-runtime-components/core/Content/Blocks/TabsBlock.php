<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks;

use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

final class TabsBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'tabs';
    }

    public function label(): string
    {
        return 'Tabs';
    }

    public function sanitize(array $data): array
    {
        return ['items' => self::sanitizeItems($data['items'] ?? [])];
    }

    public function validate(array $data): array
    {
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        if (count($items) > 12) {
            return ['Tabs cannot contain more than 12 panels.'];
        }
        return [];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $items = is_array($block['data']['items'] ?? null) ? $block['data']['items'] : [];
        if ($items === []) {
            return '';
        }
        $id = Html::escape($block['id']);
        $html = '<div class="kc-block kc-block-tabs" data-block-id="' . $id . '">';
        $html .= '<div class="kc-tabs" role="tablist" aria-label="Tabbed content">';
        foreach ($items as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = Html::plainText((string) ($item['title'] ?? ''));
            if ($title === '') {
                $title = 'Tab ' . ($i + 1);
            }
            $tabId = $id . '-tab-' . $i;
            $panelId = $id . '-panel-' . $i;
            $selected = $i === 0 ? 'true' : 'false';
            $html .= '<button type="button" class="kc-tabs-tab' . ($i === 0 ? ' is-active' : '') . '" role="tab" id="' . $tabId . '" aria-controls="' . $panelId . '" aria-selected="' . $selected . '" data-kc-tab="' . $i . '">' . Html::escape($title) . '</button>';
        }
        $html .= '</div>';
        foreach ($items as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            $content = (string) ($item['content'] ?? '');
            $tabId = $id . '-tab-' . $i;
            $panelId = $id . '-panel-' . $i;
            $hidden = $i === 0 ? '' : ' hidden';
            $html .= '<div class="kc-tabs-panel' . ($i === 0 ? ' is-active' : '') . '" role="tabpanel" id="' . $panelId . '" aria-labelledby="' . $tabId . '"' . $hidden . ' data-kc-panel="' . $i . '">';
            $html .= $content !== '' ? $content : '';
            $html .= '</div>';
        }
        return $html . '</div>';
    }

    /**
     * @param mixed $items
     * @return list<array{title:string,content:string}>
     */
    public static function sanitizeItems(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach (array_values($items) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = Html::plainText(Html::clampText((string) ($item['title'] ?? ''), 120));
            $content = Html::sanitizeInline((string) ($item['content'] ?? ''), 20000);
            if ($title === '' && Html::plainText($content) === '') {
                continue;
            }
            $out[] = ['title' => $title !== '' ? $title : 'Tab', 'content' => $content];
            if (count($out) >= 12) {
                break;
            }
        }
        return $out;
    }
}
