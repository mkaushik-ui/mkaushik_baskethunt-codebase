<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks;

use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

final class DefinitionListBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'definitionList';
    }

    public function label(): string
    {
        return 'Definition List';
    }

    public function sanitize(array $data): array
    {
        return ['items' => self::sanitizeItems($data['items'] ?? [])];
    }

    public function validate(array $data): array
    {
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        if (count($items) > 80) {
            return ['Definition list cannot contain more than 80 terms.'];
        }
        return [];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $items = is_array($block['data']['items'] ?? null) ? $block['data']['items'] : [];
        if ($items === []) {
            return '';
        }
        $html = '<dl class="kc-block kc-block-deflist" data-block-id="' . Html::escape($block['id']) . '">';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $term = Html::plainText((string) ($item['term'] ?? $item['title'] ?? ''));
            $description = (string) ($item['description'] ?? $item['content'] ?? '');
            if ($term === '' && Html::plainText($description) === '') {
                continue;
            }
            $html .= '<div class="kc-deflist-row">';
            $html .= '<dt class="kc-deflist-term">' . Html::escape($term !== '' ? $term : 'Term') . '</dt>';
            $html .= '<dd class="kc-deflist-desc">' . $description . '</dd>';
            $html .= '</div>';
        }
        return $html . '</dl>';
    }

    /**
     * @param mixed $items
     * @return list<array{term:string,description:string}>
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
            $term = Html::plainText(Html::clampText((string) ($item['term'] ?? $item['title'] ?? ''), 300));
            $description = Html::sanitizeInline((string) ($item['description'] ?? $item['content'] ?? ''), 10000);
            if ($term === '' && Html::plainText($description) === '') {
                continue;
            }
            $out[] = ['term' => $term, 'description' => $description];
            if (count($out) >= 80) {
                break;
            }
        }
        return $out;
    }
}
