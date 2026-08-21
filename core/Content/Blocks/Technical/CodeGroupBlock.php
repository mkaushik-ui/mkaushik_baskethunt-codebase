<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Technical;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\RenderContext;

/**
 * Task T5: Code Group Block Provider (Skeleton).
 */
class CodeGroupBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'codeGroup';
    }

    public function label(): string
    {
        return 'Code Group';
    }

    public function category(): string
    {
        return 'technical';
    }

    public function capabilities(): array
    {
        return [
            'nestable' => false,
            'reusable' => true,
            'wide' => true,
            'interactive' => true,
        ];
    }

    public function sanitize(array $data): array
    {
        $rawItems = is_array($data['items'] ?? null) ? $data['items'] : [];
        $items = [];
        foreach ($rawItems as $item) {
            if (is_array($item)) {
                $items[] = [
                    'label' => $this->text($item['label'] ?? 'Code', 50),
                    'language' => $this->text($item['language'] ?? 'text', 30),
                    'code' => (string)($item['code'] ?? ''),
                ];
            }
        }
        return ['items' => $items];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $items = (array)($block['data']['items'] ?? []);
        if (empty($items)) {
            return '';
        }
        $html = ['<div class="kc-code-group" data-interactive="tabs">'];
        $html[] = '<div class="kc-code-tabs">';
        foreach ($items as $idx => $item) {
            $label = htmlspecialchars((string)($item['label'] ?? 'Tab'), ENT_QUOTES, 'UTF-8');
            $activeClass = ($idx === 0) ? 'is-active' : '';
            $html[] = "<button type=\"button\" class=\"kc-code-tab {$activeClass}\" data-tab-idx=\"{$idx}\">{$label}</button>";
        }
        $html[] = '</div><div class="kc-code-panels">';
        foreach ($items as $idx => $item) {
            $code = htmlspecialchars((string)($item['code'] ?? ''), ENT_QUOTES, 'UTF-8');
            $lang = htmlspecialchars((string)($item['language'] ?? 'text'), ENT_QUOTES, 'UTF-8');
            $hiddenAttr = ($idx === 0) ? '' : 'hidden';
            $html[] = "<pre class=\"kc-code-panel\" data-panel-idx=\"{$idx}\" {$hiddenAttr}><code class=\"language-{$lang}\">{$code}</code></pre>";
        }
        $html[] = '</div></div>';
        return implode("\n", $html);
    }
}
