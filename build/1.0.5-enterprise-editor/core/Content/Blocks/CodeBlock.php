<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks;

use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

final class CodeBlock extends AbstractBlock
{
    private const MAX_CODE = 100000;

    public function type(): string
    {
        return 'code';
    }

    public function label(): string
    {
        return 'Code';
    }

    public function sanitize(array $data): array
    {
        $code = is_scalar($data['code'] ?? '') ? (string) $data['code'] : '';
        $language = strtolower(trim((string) ($data['language'] ?? '')));
        $language = preg_replace('/[^a-z0-9+#._-]/', '', $language) ?? '';
        return [
            'code' => Html::clampText($code, self::MAX_CODE),
            'language' => Html::clampText($language, 40),
            'caption' => Html::plainText($this->text($data['caption'] ?? '', 300)),
        ];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $code = (string) ($block['data']['code'] ?? '');
        $language = (string) ($block['data']['language'] ?? '');
        $caption = (string) ($block['data']['caption'] ?? '');
        $langClass = $language !== '' ? ' language-' . Html::escape($language) : '';
        $html = '<figure class="kc-block kc-block-code" data-block-id="' . Html::escape($block['id']) . '">';
        if ($caption !== '') {
            $html .= '<figcaption class="kc-code-caption">' . Html::escape($caption) . '</figcaption>';
        }
        $html .= '<pre><code class="kc-code' . $langClass . '" data-language="' . Html::escape($language) . '">';
        $html .= Html::escape($code);
        $html .= '</code></pre></figure>';
        return $html;
    }
}
