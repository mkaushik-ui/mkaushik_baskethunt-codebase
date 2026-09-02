<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Basic;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\Contracts\ProviderMetadataInterface;
use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

class ListBlock extends AbstractBlock implements ProviderMetadataInterface
{
    public function type(): string
    {
        return 'list';
    }

    public function label(): string
    {
        return 'List';
    }

    public function group(): string
    {
        return 'basic';
    }

    public function description(): string
    {
        return 'Unordered, ordered, or checklist';
    }

    public function keywords(): string
    {
        return 'list bullet number checklist task todo ul ol';
    }

    public function icon(): string
    {
        return '•';
    }

    public function editorType(): string
    {
        return 'list';
    }

    public function data(): array
    {
        return $this->defaultData();
    }

    public function defaultData(): array
    {
        return [
            'style' => 'unordered',
            'items' => ['Item 1', 'Item 2'],
        ];
    }

    public function sanitize(array $data): array
    {
        $style = (string) ($data['style'] ?? 'unordered');
        if (!in_array($style, ['ordered', 'unordered', 'checklist'], true)) {
            $style = 'unordered';
        }
        $items = $this->sanitizeItemsArray(is_array($data['items'] ?? null) ? $data['items'] : []);
        return [
            'style' => $style,
            'items' => $items,
        ];
    }

    private function sanitizeItemsArray(array $items): array
    {
        $clean = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                $clean[] = Html::sanitizeInline((string) $item);
                continue;
            }
            $meta = is_array($item['meta'] ?? null) ? $item['meta'] : [];
            $checked = !empty($meta['checked']) || !empty($item['checked']);
            $clean[] = [
                'content' => Html::sanitizeInline((string) ($item['content'] ?? $item['text'] ?? '')),
                'meta' => ['checked' => $checked],
                'checked' => $checked,
                'items' => $this->sanitizeItemsArray(is_array($item['items'] ?? null) ? $item['items'] : []),
            ];
        }
        return $clean;
    }

    public function validate(array $data): array
    {
        return [];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $data = $block['data'] ?? [];
        $style = (string) ($data['style'] ?? 'unordered');
        if (!in_array($style, ['ordered', 'unordered', 'checklist'], true)) {
            $style = 'unordered';
        }
        $items = $data['items'] ?? [];

        if (empty($items) || !is_array($items)) {
            return '';
        }

        $tag = $style === 'ordered' ? 'ol' : 'ul';
        $listClass = 'kc-list kc-list-' . $style;
        $html = "<{$tag} class=\"{$listClass}\">";
        $html .= $this->renderItems($items, $style);
        $html .= "</{$tag}>";

        return $html;
    }

    private function renderItems(array $items, string $parentStyle): string
    {
        $html = '';
        foreach ($items as $item) {
            if (!is_array($item)) {
                $content = Html::sanitizeInline((string) $item);
                $html .= "<li class=\"kc-list-item\">{$content}</li>";
                continue;
            }

            $content = Html::sanitizeInline((string) ($item['content'] ?? $item['text'] ?? ''));
            $subItems = $item['items'] ?? [];
            $checked = !empty($item['meta']['checked']) || !empty($item['checked']);

            if ($parentStyle === 'checklist') {
                $checkAttr = $checked ? ' checked="checked"' : '';
                $itemClass = $checked ? 'kc-list-item kc-checklist-item is-checked' : 'kc-list-item kc-checklist-item';
                $prefix = '<input type="checkbox" class="kc-checklist-box" disabled="disabled"' . $checkAttr . ' aria-hidden="true"> ';
                $html .= "<li class=\"{$itemClass}\">{$prefix}<span>{$content}</span>";
            } else {
                $html .= "<li class=\"kc-list-item\">{$content}";
            }

            if (!empty($subItems) && is_array($subItems)) {
                $subStyle = ($item['style'] ?? $parentStyle);
                $subTag = $subStyle === 'ordered' ? 'ol' : 'ul';
                $subClass = 'kc-list-nested kc-list-' . $subStyle;
                
                $html .= "<{$subTag} class=\"{$subClass}\">";
                $html .= $this->renderItems($subItems, $subStyle);
                $html .= "</{$subTag}>";
            }
            $html .= "</li>";
        }
        return $html;
    }
}
