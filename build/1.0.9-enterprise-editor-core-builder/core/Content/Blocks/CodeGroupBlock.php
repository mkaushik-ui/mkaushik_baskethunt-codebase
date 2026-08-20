<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks;

use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

final class CodeGroupBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'codeGroup';
    }

    public function label(): string
    {
        return 'Code Group';
    }

    public function sanitize(array $data): array
    {
        return ['items' => self::sanitizeItems($data['items'] ?? [])];
    }

    public function validate(array $data): array
    {
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        if (count($items) > 12) {
            return ['Code group cannot contain more than 12 snippets.'];
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
        $html = '<div class="kc-block kc-block-codegroup" data-block-id="' . $id . '">';
        $html .= '<div class="kc-codegroup-tabs" role="tablist" aria-label="Code languages">';
        foreach ($items as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = Html::plainText((string) ($item['label'] ?? $item['language'] ?? ''));
            if ($label === '') {
                $label = 'Code ' . ($i + 1);
            }
            $tabId = $id . '-ctab-' . $i;
            $panelId = $id . '-cpanel-' . $i;
            $html .= '<button type="button" class="kc-codegroup-tab' . ($i === 0 ? ' is-active' : '') . '" role="tab" id="' . $tabId . '" aria-controls="' . $panelId . '" aria-selected="' . ($i === 0 ? 'true' : 'false') . '" data-kc-tab="' . $i . '">' . Html::escape($label) . '</button>';
        }
        $html .= '</div>';
        foreach ($items as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = Html::plainText((string) ($item['label'] ?? $item['language'] ?? 'code'));
            $code = (string) ($item['code'] ?? '');
            $caption = Html::plainText((string) ($item['caption'] ?? ''));
            $tabId = $id . '-ctab-' . $i;
            $panelId = $id . '-cpanel-' . $i;
            $html .= '<div class="kc-codegroup-panel' . ($i === 0 ? ' is-active' : '') . '" role="tabpanel" id="' . $panelId . '" aria-labelledby="' . $tabId . '"' . ($i === 0 ? '' : ' hidden') . ' data-kc-panel="' . $i . '">';
            if ($caption !== '') {
                $html .= '<div class="kc-code-caption">' . Html::escape($caption) . '</div>';
            }
            $html .= '<pre class="kc-codegroup-pre"><code class="language-' . Html::escape(preg_replace('/[^a-z0-9_+-]/i', '', strtolower($label)) ?: 'text') . '">' . Html::escape($code) . '</code></pre>';
            $html .= '</div>';
        }
        return $html . '</div>';
    }

    /**
     * @param mixed $items
     * @return list<array{label:string,language:string,code:string,caption:string}>
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
            $label = Html::plainText(Html::clampText((string) ($item['label'] ?? ''), 40));
            $language = Html::plainText(Html::clampText((string) ($item['language'] ?? $label), 40));
            $code = Html::clampText((string) ($item['code'] ?? ''), 100000);
            $caption = Html::plainText(Html::clampText((string) ($item['caption'] ?? ''), 200));
            if ($label === '' && $language === '' && trim($code) === '') {
                continue;
            }
            if ($label === '') {
                $label = $language !== '' ? $language : 'Code';
            }
            $out[] = [
                'label' => $label,
                'language' => $language !== '' ? $language : $label,
                'code' => $code,
                'caption' => $caption,
            ];
            if (count($out) >= 12) {
                break;
            }
        }
        return $out;
    }
}
