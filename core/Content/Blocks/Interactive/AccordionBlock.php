<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Interactive;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\Contracts\ProviderMetadataInterface;
use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

final class AccordionBlock extends AbstractBlock implements ProviderMetadataInterface
{
    private const MAX_ITEMS = 50;

    public function type(): string
    {
        return 'accordion';
    }

    public function label(): string
    {
        return 'Accordion';
    }

    public function group(): string
    {
        return 'interactive';
    }

    public function description(): string
    {
        return 'Collapsible content sections';
    }

    public function keywords(): string
    {
        return 'accordion collapse expand details summary toggle';
    }

    public function icon(): string
    {
        return '↕';
    }

    public function editorType(): string
    {
        return 'accordion';
    }

    public function data(): array
    {
        return $this->defaultData();
    }

    public function defaultData(): array
    {
        return [
            'items' => [
                ['title' => 'Section 1', 'content' => 'Content 1', 'open' => false],
            ],
        ];
    }

    public function sanitize(array $data): array
    {
        return ['items' => self::sanitizeItems($data['items'] ?? [])];
    }

    public function validate(array $data): array
    {
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        if (count($items) > self::MAX_ITEMS) {
            return ['Accordion cannot contain more than ' . self::MAX_ITEMS . ' items.'];
        }
        return [];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $data = $this->sanitize(is_array($block['data'] ?? null) ? $block['data'] : []);
        $items = $data['items'];
        if ($items === []) {
            return '';
        }

        $id = isset($block['id']) ? Html::escape((string) $block['id']) : '';
        $dataAttr = $id !== '' ? ' data-block-id="' . $id . '"' : '';

        $html = '<div class="kc-block kc-block-accordion"' . $dataAttr . '>';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = (string) ($item['title'] ?? '');
            $content = (string) ($item['content'] ?? '');
            $open = !empty($item['open']) ? ' open' : '';
            if ($title === '' && Html::plainText($content) === '') {
                continue;
            }
            $html .= '<details class="kc-accordion-item"' . $open . '>';
            $html .= '<summary class="kc-accordion-summary">' . Html::escape($title !== '' ? $title : 'Accordion Item') . '</summary>';
            $html .= '<div class="kc-accordion-content">' . $content . '</div>';
            $html .= '</details>';
        }
        $html .= '</div>';

        return $html;
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
            $open = !empty($item['open']);
            if ($title === '' && Html::plainText($content) === '') {
                continue;
            }
            $out[] = ['title' => $title, 'content' => $content, 'open' => $open];
            if (count($out) >= self::MAX_ITEMS) {
                break;
            }
        }
        return $out;
    }
}
