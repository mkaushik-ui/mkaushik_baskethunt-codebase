<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Basic;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

/**
 * Task A2-T05: Checklist Block Provider
 */
class ChecklistBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'checklist';
    }

    public function label(): string
    {
        return 'Checklist';
    }

    public function category(): string
    {
        return 'basic';
    }

    public function sanitize(array $data): array
    {
        $items = $this->sanitizeItemsArray($data['items'] ?? []);
        return [
            'items' => $items,
        ];
    }

    private function sanitizeItemsArray(array $items): array
    {
        $clean = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $clean[] = [
                'text' => Html::sanitizeInline((string)($item['text'] ?? '')),
                'checked' => filter_var($item['checked'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }
        return $clean;
    }

    public function icon(): string
    {
        // SVG Icon for checklist
        return '<svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none"><path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>';
    }

    public function outlineTitle(array $data): ?string
    {
        $items = $data['items'] ?? [];
        if (!empty($items) && isset($items[0]['text'])) {
            return Html::plainText((string)$items[0]['text']);
        }
        return null;
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $items = $block['data']['items'] ?? [];
        if (empty($items)) {
            return '';
        }

        $html = '<div class="kc-checklist">';
        foreach ($items as $item) {
            $text = (string)($item['text'] ?? '');
            $checked = !empty($item['checked']);
            
            $itemClass = $checked ? 'kc-checklist-item kc-checklist-item-checked' : 'kc-checklist-item';
            
            // Render authentic SVG icons for checked vs unchecked
            $icon = $checked 
                ? '<svg class="kc-checkbox-icon kc-checkbox-checked" viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none"><path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>'
                : '<svg class="kc-checkbox-icon kc-checkbox-unchecked" viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect></svg>';

            $html .= "<div class=\"{$itemClass}\">";
            $html .= "<div class=\"kc-checklist-checkbox\">{$icon}</div>";
            $html .= "<div class=\"kc-checklist-text\">{$text}</div>";
            $html .= "</div>";
        }
        $html .= '</div>';

        return $html;
    }
}
