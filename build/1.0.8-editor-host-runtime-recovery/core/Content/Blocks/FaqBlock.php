<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks;

use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

final class FaqBlock extends AbstractBlock
{
    public function type(): string
    {
        return 'faq';
    }

    public function label(): string
    {
        return 'FAQ';
    }

    public function sanitize(array $data): array
    {
        return ['items' => self::sanitizeItems($data['items'] ?? [])];
    }

    public function validate(array $data): array
    {
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        if (count($items) > 40) {
            return ['FAQ cannot contain more than 40 items.'];
        }
        return [];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $items = is_array($block['data']['items'] ?? null) ? $block['data']['items'] : [];
        if ($items === []) {
            return '';
        }
        $html = '<div class="kc-block kc-block-faq" data-block-id="' . Html::escape($block['id']) . '" itemscope itemtype="https://schema.org/FAQPage">';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $question = Html::plainText((string) ($item['question'] ?? $item['title'] ?? ''));
            $answer = (string) ($item['answer'] ?? $item['content'] ?? '');
            if ($question === '' && Html::plainText($answer) === '') {
                continue;
            }
            $html .= '<details class="kc-faq-item" itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">';
            $html .= '<summary class="kc-faq-question" itemprop="name">' . Html::escape($question !== '' ? $question : 'Question') . '</summary>';
            $html .= '<div class="kc-faq-answer" itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer">';
            $html .= '<div itemprop="text">' . ($answer !== '' ? $answer : '') . '</div>';
            $html .= '</div></details>';
        }
        return $html . '</div>';
    }

    /**
     * @param mixed $items
     * @return list<array{question:string,answer:string}>
     */
    public static function sanitizeItems(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach (array_values($items) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $question = Html::plainText(Html::clampText((string) ($item['question'] ?? $item['title'] ?? ''), 500));
            $answer = Html::sanitizeInline((string) ($item['answer'] ?? $item['content'] ?? ''), 20000);
            if ($question === '' && Html::plainText($answer) === '') {
                continue;
            }
            $out[] = ['question' => $question, 'answer' => $answer];
            if (count($out) >= 40) {
                break;
            }
        }
        return $out;
    }
}
