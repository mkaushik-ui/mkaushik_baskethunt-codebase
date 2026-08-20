<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks;

use SOI\Core\Content\AssetResolver;
use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

final class ImageBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'image';
    }

    public function label(): string
    {
        return 'Image';
    }

    public function sanitize(array $data): array
    {
        $file = is_array($data['file'] ?? null) ? $data['file'] : [];
        $url = (string) ($data['url'] ?? $file['url'] ?? '');
        $assetId = $this->intOrNull($data['assetId'] ?? $file['assetId'] ?? null);
        return [
            'assetId' => $assetId,
            'url' => Html::sanitizeUrl($url),
            'alt' => Html::plainText($this->text($data['alt'] ?? '', 500)),
            'caption' => $this->inline($data['caption'] ?? '', 2000),
            'align' => $this->enum($data['align'] ?? 'center', ['left', 'center', 'right'], 'center'),
            'width' => $this->enum($data['width'] ?? ($data['stretched'] ?? false ? 'full' : 'default'), ['default', 'wide', 'full'], 'default'),
        ];
    }

    public function validate(array $data): array
    {
        return [];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $asset = AssetResolver::resolve($block['data']);
        if ($asset['url'] === '') {
            return '';
        }
        $align = $this->enum($block['data']['align'] ?? 'center', ['left', 'center', 'right'], 'center');
        $width = $this->enum($block['data']['width'] ?? 'default', ['default', 'wide', 'full'], 'default');
        $caption = (string) ($block['data']['caption'] ?? '');
        $alt = $asset['alt'] !== '' ? $asset['alt'] : Html::plainText($caption);

        $html = '<figure class="kc-block kc-block-image kc-image-' . Html::escape($align) . ' kc-image-' . Html::escape($width) . '" data-block-id="' . Html::escape($block['id']) . '">';
        $html .= '<img src="' . Html::escape($asset['url']) . '" alt="' . Html::escape($alt) . '" loading="lazy" decoding="async">';
        if (Html::plainText($caption) !== '') {
            $html .= '<figcaption>' . $caption . '</figcaption>';
        }
        return $html . '</figure>';
    }
}
