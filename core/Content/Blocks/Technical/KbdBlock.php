<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Technical;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\RenderContext;

/**
 * Task T5: Keyboard Key Block Provider (Skeleton).
 */
class KbdBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'kbd';
    }

    public function label(): string
    {
        return 'Keyboard Shortcut';
    }

    public function category(): string
    {
        return 'technical';
    }

    public function sanitize(array $data): array
    {
        $rawKeys = is_array($data['keys'] ?? null) ? $data['keys'] : [];
        $keys = [];
        foreach ($rawKeys as $k) {
            $keys[] = $this->text($k, 20);
        }
        return [
            'keys' => $keys,
            'description' => $this->inline($data['description'] ?? ''),
        ];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $keys = (array)($block['data']['keys'] ?? []);
        $desc = (string)($block['data']['description'] ?? '');
        $kbdHtml = [];
        foreach ($keys as $key) {
            $kEsc = htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8');
            $kbdHtml[] = "<kbd class=\"kc-kbd\">{$kEsc}</kbd>";
        }
        return "<span class=\"kc-kbd-seq\">" . implode(' + ', $kbdHtml) . "</span> <span class=\"kc-kbd-desc\">{$desc}</span>";
    }
}
