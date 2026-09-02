<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Knowledge;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\Contracts\ProviderMetadataInterface;
use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

final class StatusBadgeBlock extends AbstractBlock implements ProviderMetadataInterface
{
    public const ALLOWED_STATUSES = ['stable', 'beta', 'deprecated', 'experimental', 'draft', 'info', 'warning', 'danger'];

    public function type(): string
    {
        return 'statusBadge';
    }

    public function label(): string
    {
        return 'Status Badge';
    }

    public function group(): string
    {
        return 'knowledge';
    }

    public function description(): string
    {
        return 'API/Doc status badge indicator';
    }

    public function keywords(): string
    {
        return 'status badge tag pill indicator state beta stable deprecated';
    }

    public function icon(): string
    {
        return '🏷️';
    }

    public function editorType(): string
    {
        return 'statusBadge';
    }

    public function data(): array
    {
        return $this->defaultData();
    }

    public function defaultData(): array
    {
        return [
            'status' => 'stable',
            'label' => 'Stable',
        ];
    }

    public function sanitize(array $data): array
    {
        $status = strtolower(trim((string) ($data['status'] ?? 'stable')));
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            $status = 'stable';
        }
        $label = Html::plainText((string) ($data['label'] ?? ''));
        if ($label === '') {
            $label = ucfirst($status);
        }

        return [
            'status' => $status,
            'label' => Html::clampText($label, 100),
        ];
    }

    public function validate(array $data): array
    {
        return [];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $d = $this->sanitize($block['data'] ?? []);
        $status = $d['status'];
        $label = $d['label'];

        $blockId = isset($block['id']) ? Html::escape((string) $block['id']) : '';
        $dataAttr = $blockId !== '' ? ' data-block-id="' . $blockId . '"' : '';

        return '<span class="kc-badge kc-badge-' . Html::escape($status) . '"' . $dataAttr . '>' . Html::escape($label) . '</span>';
    }
}
