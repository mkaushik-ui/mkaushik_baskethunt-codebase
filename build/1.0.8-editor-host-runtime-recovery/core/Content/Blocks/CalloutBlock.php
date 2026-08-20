<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks;

use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

final class CalloutBlock extends AbstractBlock
{
    public const TONES = ['info', 'note', 'tip', 'warning', 'danger', 'success'];

    public function type(): string
    {
        return 'callout';
    }

    public function label(): string
    {
        return 'Callout';
    }

    public function sanitize(array $data): array
    {
        return [
            'tone' => $this->enum($data['tone'] ?? 'info', self::TONES, 'info'),
            'title' => Html::plainText($this->text($data['title'] ?? '', 200)),
            'text' => $this->inline($data['text'] ?? '', 20000),
        ];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $tone = $this->enum($block['data']['tone'] ?? 'info', self::TONES, 'info');
        $title = (string) ($block['data']['title'] ?? '');
        $text = (string) ($block['data']['text'] ?? '');
        if ($title === '' && Html::plainText($text) === '') {
            return '';
        }
        if ($title === '') {
            $title = ucfirst($tone);
        }
        $html = '<aside class="kc-block kc-block-callout kc-callout-' . Html::escape($tone) . '" data-tone="' . Html::escape($tone) . '" data-block-id="' . Html::escape($block['id']) . '">';
        $html .= '<div class="kc-callout-title">' . Html::escape($title) . '</div>';
        if (Html::plainText($text) !== '') {
            $html .= '<div class="kc-callout-body">' . $text . '</div>';
        }
        return $html . '</aside>';
    }
}
