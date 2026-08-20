<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks;

use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

final class StatusBadgeBlock extends AbstractBlock
{
    public const STATUSES = [
        'stable',
        'beta',
        'deprecated',
        'internal',
        'experimental',
        'recommended',
    ];

    public function type(): string
    {
        return 'statusBadge';
    }

    public function label(): string
    {
        return 'Status Badge';
    }

    public function sanitize(array $data): array
    {
        $status = $this->enum($data['status'] ?? 'stable', self::STATUSES, 'stable');
        $label = Html::plainText(Html::clampText((string) ($data['label'] ?? ''), 40));
        if ($label === '') {
            $label = ucfirst($status);
        }
        return [
            'status' => $status,
            'label' => $label,
        ];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $status = $this->enum($block['data']['status'] ?? 'stable', self::STATUSES, 'stable');
        $label = Html::plainText((string) ($block['data']['label'] ?? ucfirst($status)));
        if ($label === '') {
            $label = ucfirst($status);
        }
        return '<p class="kc-block kc-block-status" data-block-id="' . Html::escape($block['id']) . '">'
            . '<span class="kc-badge kc-badge-' . Html::escape($status) . '">' . Html::escape($label) . '</span>'
            . '</p>';
    }
}
