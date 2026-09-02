<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Media;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\Contracts\ProviderMetadataInterface;
use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

class LinkCardBlock extends AbstractBlock implements ProviderMetadataInterface
{
    public function type(): string
    {
        return 'link';
    }

    public function label(): string
    {
        return 'Link Card';
    }

    public function group(): string
    {
        return 'media';
    }

    public function description(): string
    {
        return 'Bookmark or embedded link card';
    }

    public function keywords(): string
    {
        return 'link card bookmark url external preview';
    }

    public function icon(): string
    {
        return '🔗';
    }

    public function editorType(): string
    {
        return 'linkCard';
    }

    public function data(): array
    {
        return $this->defaultData();
    }

    public function defaultData(): array
    {
        return [
            'url' => 'https://example.com',
            'title' => 'Example Link',
            'text' => 'Description of the link target',
        ];
    }

    public function sanitize(array $data): array
    {
        return [
            'url' => Html::sanitizeUrl((string) ($data['url'] ?? $data['link'] ?? '')),
            'title' => Html::plainText((string) ($data['title'] ?? '')),
            'text' => Html::sanitizeInline((string) ($data['text'] ?? $data['description'] ?? ''), 1000),
        ];
    }

    public function validate(array $data): array
    {
        return [];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $d = $this->sanitize($block['data'] ?? []);
        $url = $d['url'];
        $title = $d['title'] !== '' ? $d['title'] : $url;
        $text = $d['text'];

        if ($url === '') {
            return '';
        }

        $blockId = isset($block['id']) ? Html::escape((string) $block['id']) : '';
        $dataAttr = $blockId !== '' ? ' data-block-id="' . $blockId . '"' : '';

        $html = '<a href="' . Html::escape($url) . '" class="kc-link-card"' . $dataAttr . ' target="_blank" rel="noopener noreferrer">';
        $html .= '<div class="kc-link-card-body">';
        $html .= '<div class="kc-link-card-title">' . Html::escape($title) . '</div>';
        if (Html::plainText($text) !== '') {
            $html .= '<div class="kc-link-card-desc">' . $text . '</div>';
        }
        $html .= '<span class="kc-link-card-url">' . Html::escape($url) . '</span>';
        $html .= '</div></a>';

        return $html;
    }
}
