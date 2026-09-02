<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Layout;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\Contracts\ProviderMetadataInterface;
use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

final class CardsBlock extends AbstractBlock implements ProviderMetadataInterface
{
    private const MAX_CARDS = 24;

    public function type(): string
    {
        return 'cards';
    }

    public function label(): string
    {
        return 'Cards Grid';
    }

    public function group(): string
    {
        return 'layout';
    }

    public function description(): string
    {
        return 'Grid layout of content card tiles';
    }

    public function keywords(): string
    {
        return 'cards grid layout tile link icon image feature list';
    }

    public function icon(): string
    {
        return '🃏';
    }

    public function editorType(): string
    {
        return 'cards';
    }

    public function data(): array
    {
        return $this->defaultData();
    }

    public function defaultData(): array
    {
        return [
            'columns' => 3,
            'items' => [
                ['title' => 'Card 1', 'description' => 'Card description 1', 'link' => '#'],
            ],
        ];
    }

    public function sanitize(array $data): array
    {
        $cols = (int) ($data['columns'] ?? 3);
        if ($cols < 1 || $cols > 4) {
            $cols = 3;
        }

        $rawItems = is_array($data['items'] ?? null) ? $data['items'] : [];
        $items = [];
        foreach (array_values($rawItems) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = Html::plainText(Html::clampText((string) ($item['title'] ?? ''), 200));
            $desc = Html::sanitizeInline((string) ($item['description'] ?? $item['desc'] ?? ''), 2000);
            $link = Html::sanitizeUrl((string) ($item['link'] ?? $item['url'] ?? ''));
            $icon = Html::plainText(Html::clampText((string) ($item['icon'] ?? ''), 50));
            $image = Html::sanitizeUrl((string) ($item['image'] ?? ''));

            if ($title === '' && Html::plainText($desc) === '') {
                continue;
            }

            $items[] = [
                'title' => $title,
                'description' => $desc,
                'link' => $link,
                'icon' => $icon,
                'image' => $image,
            ];

            if (count($items) >= self::MAX_CARDS) {
                break;
            }
        }

        return [
            'columns' => $cols,
            'items' => $items,
        ];
    }

    public function validate(array $data): array
    {
        return [];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $d = $this->sanitize($block['data'] ?? []);
        $cols = $d['columns'];
        $items = $d['items'];
        if ($items === []) {
            return '';
        }

        $blockId = isset($block['id']) ? Html::escape((string) $block['id']) : '';
        $dataAttr = $blockId !== '' ? ' data-block-id="' . $blockId . '"' : '';

        $html = '<div class="kc-block kc-block-cards kc-cards-grid kc-cards-cols-' . $cols . '"' . $dataAttr . '>';
        foreach ($items as $card) {
            $hasLink = $card['link'] !== '';
            $tag = $hasLink ? 'a' : 'div';
            $hrefAttr = $hasLink ? ' href="' . Html::escape($card['link']) . '"' : '';

            $html .= '<' . $tag . ' class="kc-card-item"' . $hrefAttr . '>';
            if ($card['image'] !== '') {
                $html .= '<div class="kc-card-image"><img src="' . Html::escape($card['image']) . '" alt="' . Html::escape($card['title']) . '" loading="lazy"></div>';
            } elseif ($card['icon'] !== '') {
                $html .= '<div class="kc-card-icon">' . Html::escape($card['icon']) . '</div>';
            }
            $html .= '<div class="kc-card-body">';
            if ($card['title'] !== '') {
                $html .= '<h4 class="kc-card-title">' . Html::escape($card['title']) . '</h4>';
            }
            if (Html::plainText($card['description']) !== '') {
                $html .= '<div class="kc-card-desc">' . $card['description'] . '</div>';
            }
            $html .= '</div></' . $tag . '>';
        }
        $html .= '</div>';

        return $html;
    }
}
