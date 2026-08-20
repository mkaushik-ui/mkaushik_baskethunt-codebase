<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks;

use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

final class KeyValuesBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'keyValues';
    }

    public function label(): string
    {
        return 'Key / Value';
    }

    public function sanitize(array $data): array
    {
        $items = [];
        if (isset($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $item) {
                if (!is_array($item)) continue;
                $key = trim($this->text($item['key'] ?? $item['label'] ?? '', 200));
                $value = trim($this->inline($item['value'] ?? $item['val'] ?? '', 1000));
                if ($key !== '' || $value !== '') {
                    $items[] = ['key' => $key, 'value' => $value];
                }
            }
        }
        $title = trim($this->text($data['title'] ?? '', 200));
        return ['title' => $title, 'items' => $items];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $d = $this->sanitize($block['data'] ?? []);
        if (empty($d['items'])) {
            return '';
        }
        $titleHtml = $d['title'] !== '' ? '<div class="kc-keyvalues-title">' . Html::escape($d['title']) . '</div>' : '';
        $rows = '';
        foreach ($d['items'] as $item) {
            $rows .= '<div class="kc-keyvalue-row"><span class="kc-keyvalue-key">' . Html::escape($item['key']) . '</span><span class="kc-keyvalue-val">' . $item['value'] . '</span></div>';
        }
        $blockId = Html::escape($block['id'] ?? '');
        return '<div class="kc-block kc-block-keyvalues" data-block-id="' . $blockId . '">' . $titleHtml . '<div class="kc-keyvalues-grid">' . $rows . '</div></div>';
    }
}
