<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Technical;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\Contracts\ProviderMetadataInterface;
use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

class KbdBlock extends AbstractBlock implements ProviderMetadataInterface
{
    public function type(): string
    {
        return 'kbd';
    }

    public function label(): string
    {
        return 'Keyboard Shortcut';
    }

    public function group(): string
    {
        return 'technical';
    }

    public function description(): string
    {
        return 'Keyboard key sequence shortcut';
    }

    public function keywords(): string
    {
        return 'kbd key shortcut keypress keyboard hotkey';
    }

    public function icon(): string
    {
        return '⌨️';
    }

    public function editorType(): string
    {
        return 'kbd';
    }

    public function data(): array
    {
        return $this->defaultData();
    }

    public function defaultData(): array
    {
        return [
            'keys' => ['Ctrl', 'Shift', 'P'],
            'description' => 'Open command palette',
        ];
    }

    public function sanitize(array $data): array
    {
        $rawKeys = is_array($data['keys'] ?? null) ? $data['keys'] : [];
        $keys = [];
        foreach ($rawKeys as $k) {
            $keyText = Html::plainText((string) $k);
            if ($keyText !== '') {
                $keys[] = Html::clampText($keyText, 30);
            }
        }
        return [
            'keys' => $keys,
            'description' => Html::plainText(Html::clampText((string) ($data['description'] ?? ''), 300)),
        ];
    }

    public function validate(array $data): array
    {
        return [];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $d = $this->sanitize($block['data'] ?? []);
        $keys = $d['keys'];
        $desc = $d['description'];

        $blockId = isset($block['id']) ? Html::escape((string) $block['id']) : '';
        $dataAttr = $blockId !== '' ? ' data-block-id="' . $blockId . '"' : '';

        $html = '<span class="kc-block kc-block-kbd kc-kbd-seq"' . $dataAttr . '>';
        $kbdParts = [];
        foreach ($keys as $k) {
            $kbdParts[] = '<kbd class="kc-kbd">' . Html::escape($k) . '</kbd>';
        }
        $html .= implode(' + ', $kbdParts);
        if ($desc !== '') {
            $html .= ' <span class="kc-kbd-desc">' . Html::escape($desc) . '</span>';
        }
        $html .= '</span>';

        return $html;
    }
}
