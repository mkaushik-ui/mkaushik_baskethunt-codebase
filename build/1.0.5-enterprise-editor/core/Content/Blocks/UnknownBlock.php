<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks;

use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

/**
 * Future/unknown block types are stored safely and never executed.
 */
final class UnknownBlock extends AbstractBlock
{
    public function __construct(private readonly string $originalType = 'unknown')
    {
    }

    public function type(): string
    {
        return 'unknown';
    }

    public function label(): string
    {
        return 'Unknown block';
    }

    public function sanitize(array $data): array
    {
        $originalType = (string) ($data['originalType'] ?? $this->originalType);
        $originalType = preg_replace('/[^a-zA-Z0-9_-]/', '', $originalType) ?? 'unknown';
        return [
            'originalType' => Html::clampText($originalType, 60),
            'payload' => self::opaquePayload($data['payload'] ?? $data),
        ];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        if (!$ctx->preview) {
            return '';
        }
        $type = Html::escape((string) ($block['data']['originalType'] ?? 'unknown'));
        return '<div class="kc-block kc-block-unknown" data-block-id="' . Html::escape($block['id']) . '">Unsupported block: ' . $type . '</div>';
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private static function opaquePayload(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > 20000) {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
