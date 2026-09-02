<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Technical;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\Contracts\ProviderMetadataInterface;
use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

class CodeBlock extends AbstractBlock implements ProviderMetadataInterface
{
    public const ALLOWED_LANGUAGES = [
        'php', 'javascript', 'typescript', 'html', 'css', 'json', 'sql',
        'bash', 'sh', 'python', 'java', 'go', 'rust', 'c', 'cpp', 'yaml', 'xml', 'text'
    ];

    public function type(): string
    {
        return 'code';
    }

    public function label(): string
    {
        return 'Code Block';
    }

    public function group(): string
    {
        return 'technical';
    }

    public function description(): string
    {
        return 'Syntax highlighted code snippet';
    }

    public function keywords(): string
    {
        return 'code syntax pre programming snippet script developer';
    }

    public function icon(): string
    {
        return '💻';
    }

    public function editorType(): string
    {
        return 'code';
    }

    public function data(): array
    {
        return $this->defaultData();
    }

    public function defaultData(): array
    {
        return [
            'code' => '',
            'language' => 'javascript',
            'caption' => '',
        ];
    }

    public function sanitize(array $data): array
    {
        $code = (string) ($data['code'] ?? $data['text'] ?? '');
        $lang = strtolower(trim((string) ($data['language'] ?? 'javascript')));
        if (!in_array($lang, self::ALLOWED_LANGUAGES, true)) {
            $lang = 'javascript';
        }
        $caption = Html::plainText((string) ($data['caption'] ?? ''));

        return [
            'code' => Html::clampText($code, 100000),
            'language' => $lang,
            'caption' => Html::clampText($caption, 300),
        ];
    }

    public function validate(array $data): array
    {
        return [];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $d = $this->sanitize($block['data'] ?? []);
        $code = $d['code'];
        $lang = $d['language'];
        $caption = $d['caption'];

        $blockId = isset($block['id']) ? Html::escape((string) $block['id']) : '';
        $dataAttr = $blockId !== '' ? ' data-block-id="' . $blockId . '"' : '';

        $html = '<div class="kc-block kc-block-code"' . $dataAttr . '>';
        $html .= '<div class="kc-code-header">';
        $html .= '<span class="kc-code-lang">' . Html::escape($lang) . '</span>';
        $html .= '<button type="button" class="kc-code-copy" onclick="navigator.clipboard.writeText(this.parentNode.nextElementSibling.querySelector(\'code\').innerText)">Copy</button>';
        $html .= '</div>';
        $html .= '<pre class="kc-code-pre" data-language="' . Html::escape($lang) . '"><code class="language-' . Html::escape($lang) . '">' . Html::escape($code) . '</code></pre>';
        if ($caption !== '') {
            $html .= '<div class="kc-code-caption">' . Html::escape($caption) . '</div>';
        }
        $html .= '</div>';

        return $html;
    }
}
