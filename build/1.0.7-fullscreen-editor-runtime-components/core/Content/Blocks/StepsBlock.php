<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks;

use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

final class StepsBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'steps';
    }

    public function label(): string
    {
        return 'Steps';
    }

    public function sanitize(array $data): array
    {
        return ['items' => self::sanitizeItems($data['items'] ?? [])];
    }

    public function validate(array $data): array
    {
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        if (count($items) > 50) {
            return ['Steps cannot contain more than 50 items.'];
        }
        return [];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $items = is_array($block['data']['items'] ?? null) ? $block['data']['items'] : [];
        if ($items === []) {
            return '';
        }
        $html = '<ol class="kc-block kc-block-steps" data-block-id="' . Html::escape($block['id']) . '">';
        foreach ($items as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = Html::plainText((string) ($item['title'] ?? ''));
            $content = (string) ($item['content'] ?? '');
            if ($title === '' && Html::plainText($content) === '') {
                continue;
            }
            $html .= '<li class="kc-steps-item">';
            $html .= '<div class="kc-steps-index" aria-hidden="true">' . (int) ($i + 1) . '</div>';
            $html .= '<div class="kc-steps-body">';
            if ($title !== '') {
                $html .= '<div class="kc-steps-title">' . Html::escape($title) . '</div>';
            }
            if (Html::plainText($content) !== '') {
                $html .= '<div class="kc-steps-content">' . $content . '</div>';
            }
            $html .= '</div></li>';
        }
        return $html . '</ol>';
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
            $title = Html::plainText(Html::clampText((string) ($item['title'] ?? ''), 300));
            $content = Html::sanitizeInline((string) ($item['content'] ?? ''), 20000);
            if ($title === '' && Html::plainText($content) === '') {
                continue;
            }
            $out[] = ['title' => $title, 'content' => $content];
            if (count($out) >= 50) {
                break;
            }
        }
        return $out;
    }
}
