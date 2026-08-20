<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks;

use SOI\Core\Content\AssetResolver;
use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

final class FileBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'file';
    }

    public function label(): string
    {
        return 'File';
    }

    public function sanitize(array $data): array
    {
        $file = is_array($data['file'] ?? null) ? $data['file'] : [];
        return [
            'assetId' => $this->intOrNull($data['assetId'] ?? $file['assetId'] ?? null),
            'url' => Html::sanitizeUrl((string) ($data['url'] ?? $file['url'] ?? '')),
            'name' => Html::plainText($this->text($data['name'] ?? $file['name'] ?? $data['title'] ?? '', 300)),
            'mime' => Html::plainText($this->text($data['mime'] ?? $file['mime'] ?? '', 100)),
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
        $name = $asset['name'] !== '' ? $asset['name'] : Html::plainText((string) ($block['data']['name'] ?? 'Download file'));
        if ($name === '') {
            $name = 'Download file';
        }
        return '<p class="kc-block kc-block-file" data-block-id="' . Html::escape($block['id']) . '">'
            . '<a class="kc-file-link" href="' . Html::escape($asset['url']) . '" rel="noopener noreferrer">'
            . Html::escape($name)
            . '</a></p>';
    }
}
