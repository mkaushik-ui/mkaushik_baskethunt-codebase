<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Media;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\RenderContext;

/**
 * Task T5: File Block Provider (Skeleton).
 */
class FileBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'file';
    }

    public function label(): string
    {
        return 'File Attachment';
    }

    public function category(): string
    {
        return 'media';
    }

    public function sanitize(array $data): array
    {
        return [
    'url' => $this->text($data['url'] ?? ''),
    'name' => $this->text($data['name'] ?? ''),
    'size' => (int)($data['size'] ?? 0),
    'extension' => strtoupper($this->text($data['extension'] ?? '')),
];
    }

    public function render(array $block, RenderContext $ctx): string
{
    $data = $block['data'] ?? [];

    $url = htmlspecialchars((string)($data['url'] ?? '#'), ENT_QUOTES, 'UTF-8');
    $name = htmlspecialchars((string)($data['name'] ?? 'Download File'), ENT_QUOTES, 'UTF-8');

    $extension = strtoupper((string)($data['extension'] ?? 'FILE'));

    $sizeBytes = (int)($data['size'] ?? 0);

    if ($sizeBytes >= 1024 * 1024) {
        $size = round($sizeBytes / 1024 / 1024, 1) . ' MB';
    } elseif ($sizeBytes >= 1024) {
        $size = round($sizeBytes / 1024, 1) . ' KB';
    } else {
        $size = $sizeBytes . ' B';
    }

    return
        '<a class="kc-file-card" href="' . $url . '" download>' .
            '<div class="kc-file-badge">' . $extension . '</div>' .
            '<div class="kc-file-info">' .
                '<div class="kc-file-name">' . $name . '</div>' .
                '<div class="kc-file-size">' . htmlspecialchars($size, ENT_QUOTES, 'UTF-8') . '</div>' .
            '</div>' .
        '</a>';
}
}
