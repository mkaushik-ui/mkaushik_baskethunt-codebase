<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks;

use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

final class KbdBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'kbd';
    }

    public function label(): string
    {
        return 'Keyboard Key';
    }

    public function sanitize(array $data): array
    {
        $keys = [];
        if (isset($data['keys']) && is_array($data['keys'])) {
            foreach ($data['keys'] as $k) {
                $k = trim($this->text($k, 50));
                if ($k !== '') $keys[] = $k;
            }
        } elseif (isset($data['text']) || isset($data['keys'])) {
            $raw = (string) ($data['text'] ?? $data['keys'] ?? '');
            $parts = preg_split('/[\+,]/', $raw);
            if (is_array($parts)) {
                foreach ($parts as $p) {
                    $p = trim($this->text($p, 50));
                    if ($p !== '') $keys[] = $p;
                }
            }
        }
        $description = trim($this->text($data['description'] ?? '', 300));
        return ['keys' => $keys, 'description' => $description];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $d = $this->sanitize($block['data'] ?? []);
        if (empty($d['keys'])) {
            return '';
        }
        $renderedKeys = array_map(static fn($k) => '<kbd class="kc-kbd">' . Html::escape($k) . '</kbd>', $d['keys']);
        $seq = implode(' <span class="kc-kbd-plus">+</span> ', $renderedKeys);
        $desc = $d['description'] !== '' ? ' <span class="kc-kbd-desc">' . Html::escape($d['description']) . '</span>' : '';
        $blockId = Html::escape($block['id'] ?? '');
        return '<div class="kc-block kc-block-kbd" data-block-id="' . $blockId . '"><span class="kc-kbd-combo">' . $seq . '</span>' . $desc . '</div>';
    }
}
