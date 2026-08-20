<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks;

use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

final class CardsBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'cards';
    }

    public function label(): string
    {
        return 'Cards';
    }

    public function sanitize(array $data): array
    {
        return ['items' => self::sanitizeItems($data['items'] ?? [])];
    }

    public function validate(array $data): array
    {
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        if (count($items) > 24) {
            return ['Card grid cannot contain more than 24 cards.'];
        }
        return [];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $items = is_array($block['data']['items'] ?? null) ? $block['data']['items'] : [];
        if ($items === []) {
            return '';
        }
        $html = '<div class="kc-block kc-block-cards" data-block-id="' . Html::escape($block['id']) . '">';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = Html::plainText((string) ($item['title'] ?? ''));
            $description = (string) ($item['description'] ?? '');
            $icon = Html::plainText((string) ($item['icon'] ?? ''));
            $imageUrl = Html::sanitizeUrl((string) ($item['imageUrl'] ?? ''));
            $linkUrl = Html::sanitizeUrl((string) ($item['linkUrl'] ?? ''));
            if ($title === '' && Html::plainText($description) === '' && $imageUrl === '') {
                continue;
            }
            $inner = '';
            if ($imageUrl !== '') {
                $inner .= '<div class="kc-card-media"><img src="' . Html::escape($imageUrl) . '" alt="" loading="lazy"></div>';
            } elseif ($icon !== '') {
                $inner .= '<div class="kc-card-icon" aria-hidden="true">' . Html::escape($icon) . '</div>';
            }
            if ($title !== '') {
                $inner .= '<div class="kc-card-title">' . Html::escape($title) . '</div>';
            }
            if (Html::plainText($description) !== '') {
                $inner .= '<div class="kc-card-desc">' . $description . '</div>';
            }
            if ($linkUrl !== '') {
                $html .= '<a class="kc-card" href="' . Html::escape($linkUrl) . '" rel="noopener noreferrer">' . $inner . '</a>';
            } else {
                $html .= '<div class="kc-card">' . $inner . '</div>';
            }
        }
        return $html . '</div>';
    }

    /**
     * @param mixed $items
     * @return list<array{title:string,description:string,icon:string,imageUrl:string,linkUrl:string}>
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
            $title = Html::plainText(Html::clampText((string) ($item['title'] ?? ''), 200));
            $description = Html::sanitizeInline((string) ($item['description'] ?? ''), 4000);
            $icon = Html::plainText(Html::clampText((string) ($item['icon'] ?? ''), 8));
            $imageUrl = Html::sanitizeUrl((string) ($item['imageUrl'] ?? $item['image'] ?? ''));
            $linkUrl = Html::sanitizeUrl((string) ($item['linkUrl'] ?? $item['url'] ?? ''));
            if ($title === '' && Html::plainText($description) === '' && $imageUrl === '') {
                continue;
            }
            $out[] = [
                'title' => $title,
                'description' => $description,
                'icon' => $icon,
                'imageUrl' => $imageUrl,
                'linkUrl' => $linkUrl,
            ];
            if (count($out) >= 24) {
                break;
            }
        }
        return $out;
    }
}
