<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Technical;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\Contracts\ProviderMetadataInterface;
use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

final class DefinitionListBlock extends AbstractBlock implements ProviderMetadataInterface
{
    private const MAX_ITEMS = 50;

    public function type(): string
    {
        return 'definitionList';
    }

    public function label(): string
    {
        return 'Definition List';
    }

    public function group(): string
    {
        return 'technical';
    }

    public function description(): string
    {
        return 'Term and description pairs';
    }

    public function keywords(): string
    {
        return 'definition list term description glossary dl dt dd';
    }

    public function icon(): string
    {
        return '≡';
    }

    public function editorType(): string
    {
        return 'definitionList';
    }

    public function data(): array
    {
        return $this->defaultData();
    }

    public function defaultData(): array
    {
        return [
            'items' => [
                ['term' => 'API', 'description' => 'Application Programming Interface'],
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
            return ['Definition list cannot contain more than ' . self::MAX_ITEMS . ' items.'];
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

        $html = '<dl class="kc-block kc-block-deflist"' . $dataAttr . '>';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $term = (string) ($item['term'] ?? '');
            $desc = (string) ($item['description'] ?? '');
            if ($term === '' && Html::plainText($desc) === '') {
                continue;
            }
            $html .= '<div class="kc-deflist-item">';
            $html .= '<dt class="kc-deflist-term">' . Html::escape($term !== '' ? $term : 'Term') . '</dt>';
            $html .= '<dd class="kc-deflist-desc">' . $desc . '</dd>';
            $html .= '</div>';
        }
        $html .= '</dl>';

        return $html;
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
            $term = Html::plainText(Html::clampText((string) ($item['term'] ?? ''), 300));
            $desc = Html::sanitizeInline((string) ($item['description'] ?? ''), 20000);
            if ($term === '' && Html::plainText($desc) === '') {
                continue;
            }
            $out[] = ['term' => $term, 'description' => $desc];
            if (count($out) >= self::MAX_ITEMS) {
                break;
            }
        }
        return $out;
    }
}
