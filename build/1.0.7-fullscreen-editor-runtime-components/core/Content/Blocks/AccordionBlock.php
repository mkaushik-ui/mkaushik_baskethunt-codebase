<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks;

use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

final class AccordionBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'accordion';
    }

    public function label(): string
    {
        return 'Accordion';
    }

    public function sanitize(array $data): array
    {
        return ['items' => self::sanitizeItems($data['items'] ?? [])];
    }

    public function validate(array $data): array
    {
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        if (count($items) > 40) {
            return ['Accordion cannot contain more than 40 items.'];
        }
        return [];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $items = is_array($block['data']['items'] ?? null) ? $block['data']['items'] : [];
        if ($items === []) {
            return '';
        }
        $html = '<div class="kc-block kc-block-accordion" data-block-id="' . Html::escape($block['id']) . '">';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = Html::plainText((string) ($item['title'] ?? ''));
            $content = (string) ($item['content'] ?? '');
            if ($title === '' && Html::plainText($content) === '') {
                continue;
            }
            $open = !empty($item['open']);
            $html .= '<details class="kc-accordion-item"' . ($open ? ' open' : '') . '>';
            $html .= '<summary class="kc-accordion-title">' . Html::escape($title !== '' ? $title : 'Section') . '</summary>';
            if (Html::plainText($content) !== '') {
                $html .= '<div class="kc-accordion-content">' . $content . '</div>';
            }
            $html .= '</details>';
        }
        return $html . '</div>';
    }

    /**
     * @param mixed $items
     * @return list<array{title:string,content:string,open:bool}>
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
            $title = Html::plainText(Html::clampText((string) ($item['title'] ?? ''), 300));
            $content = Html::sanitizeInline((string) ($item['content'] ?? ''), 20000);
            if ($title === '' && Html::plainText($content) === '') {
                continue;
            }
            $out[] = [
                'title' => $title,
                'content' => $content,
                'open' => !empty($item['open']),
            ];
            if (count($out) >= 40) {
                break;
            }
        }
        return $out;
    }
}
