<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Knowledge;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\RenderContext;

/**
 * Task T3: Status / Badge Block Provider (Skeleton).
 */
class StatusBadgeBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'statusBadge';
    }

    public function label(): string
    {
        return 'Status Badge';
    }

    public function category(): string
    {
        return 'notice';
    }

    public function sanitize(array $data): array
    {
        return [
            'status' => $this->enum($data['status'] ?? 'stable', ['stable', 'beta', 'deprecated', 'draft', 'review'], 'stable'),
            'label' => $this->text($data['label'] ?? 'Stable', 50),
        ];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $data = $block['data'] ?? [];
        $status = (string)($data['status'] ?? 'stable');
        $label = (string)($data['label'] ?? 'Stable');
        return "<span class=\"kc-badge kc-badge-{$status}\">{$label}</span>";
    }
}
