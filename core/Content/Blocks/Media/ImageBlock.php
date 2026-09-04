<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Media;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\RenderContext;

/**
 * Task T5: Image Block Provider (Skeleton).
 */
class ImageBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'image';
    }

    public function label(): string
    {
        return 'Image';
    }

    public function category(): string
    {
        return 'media';
    }

    public function sanitize(array $data): array
    {
        return [
            'url' => $this->text($data['url'] ?? ''),
            'alt' => $this->text($data['alt'] ?? ''),
            'caption' => $this->inline($data['caption'] ?? ''),
            'width' => $this->enum(
                (string)($data['width'] ?? '100'),
                ['25', '50', '75', '100'],
                '100'
            ),

            'align' => $this->enum(
                (string)($data['align'] ?? 'center'),
                ['left', 'center', 'right'],
                'center'
            ),
            'assetId' => $this->intOrNull($data['assetId'] ?? null),
        ];
    }

    public function render(array $block, RenderContext $ctx): string {
        $data = $block['data'] ?? [];

        $url = htmlspecialchars((string)($data['url'] ?? ''), ENT_QUOTES, 'UTF-8');
        $alt = htmlspecialchars((string)($data['alt'] ?? ''), ENT_QUOTES, 'UTF-8');

        $caption = (string)($data['caption'] ?? '');

        $width = (string)($data['width'] ?? '100');
        $align = (string)($data['align'] ?? 'center');

        if ($url === '') {
            return '';
        }

        $style = '';

        switch ($width) {

            case '25':
                $style = 'width:25%;';
                break;

            case '50':
                $style = 'width:50%;';
                break;

            case '75':
                $style = 'width:75%;';
                break;

            default:
                $style = 'width:100%;';
        }

        $html  = '<figure class="kc-image align-' . $align . '">';

        $html .= '<img src="' . $url . '"';

        $html .= ' alt="' . $alt . '"';

        $html .= ' style="' . $style . '"';

        $html .= ' />';

        if ($caption !== '') {

            $html .= '<figcaption>' . $caption . '</figcaption>';

        }

        $html .= '</figure>';

        return $html;
    }
}
