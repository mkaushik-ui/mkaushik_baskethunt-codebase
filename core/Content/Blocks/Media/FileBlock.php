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
            'size' => $this->text($data['size'] ?? ''),
            'extension' => $this->text($data['extension'] ?? ''),
        ];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $data = $block['data'] ?? [];
        $url = htmlspecialchars((string)($data['url'] ?? '#'), ENT_QUOTES, 'UTF-8');
        $name = htmlspecialchars((string)($data['name'] ?? 'Download File'), ENT_QUOTES, 'UTF-8');
        $size = htmlspecialchars((string)($data['size'] ?? ''), ENT_QUOTES, 'UTF-8');
        return "<a href=\"{$url}\" class=\"kc-file-attachment\" download><span class=\"kc-file-icon\">📎</span><span class=\"kc-file-name\">{$name}</span><span class=\"kc-file-size\">{$size}</span></a>";
    }
}
