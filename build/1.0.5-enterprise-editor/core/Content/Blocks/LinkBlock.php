<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks;

use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

final class LinkBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'link';
    }

    public function label(): string
    {
        return 'Link';
    }

    public function sanitize(array $data): array
    {
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $url = Html::sanitizeUrl((string) ($data['url'] ?? $data['link'] ?? ''));
        $title = Html::plainText((string) ($data['title'] ?? $meta['title'] ?? ''));
        $text = $this->inline($data['text'] ?? $meta['description'] ?? '', 4000);
        return [
            'url' => $url,
            'title' => Html::clampText($title, 300),
            'text' => $text,
        ];
    }

    public function validate(array $data): array
    {
        $url = (string) ($data['url'] ?? '');
        if ($url !== '' && Html::sanitizeUrl($url) === '') {
            return ['Link URL is not allowed.'];
        }
        return [];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $url = Html::sanitizeUrl((string) ($block['data']['url'] ?? ''));
        if ($url === '') {
            return '';
        }
        $title = Html::plainText((string) ($block['data']['title'] ?? ''));
        $text = (string) ($block['data']['text'] ?? '');
        if ($title === '') {
            $title = $url;
        }
        $html = '<p class="kc-block kc-block-link" data-block-id="' . Html::escape($block['id']) . '">';
        $html .= '<a href="' . Html::escape($url) . '" rel="noopener noreferrer">' . Html::escape($title) . '</a>';
        if (Html::plainText($text) !== '') {
            $html .= '<span class="kc-link-text"> — ' . $text . '</span>';
        }
        return $html . '</p>';
    }
}
