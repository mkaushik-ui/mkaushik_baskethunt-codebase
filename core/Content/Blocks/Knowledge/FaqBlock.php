<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks\Knowledge;

use SOI\Core\Content\Blocks\AbstractBlock;
use SOI\Core\Content\Contracts\ProviderMetadataInterface;
use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;

final class FaqBlock extends AbstractBlock implements ProviderMetadataInterface
{
    private const MAX_ITEMS = 50;

    public function type(): string
    {
        return 'faq';
    }

    public function label(): string
    {
        return 'FAQ Accordion';
    }

    public function group(): string
    {
        return 'knowledge';
    }

    public function description(): string
    {
        return 'Structured FAQ accordion with Schema.org microdata';
    }

    public function keywords(): string
    {
        return 'faq question answer schema microdata accordion kb';
    }

    public function icon(): string
    {
        return '❓';
    }

    public function editorType(): string
    {
        return 'faq';
    }

    public function data(): array
    {
        return $this->defaultData();
    }

    public function defaultData(): array
    {
        return [
            'items' => [
                ['question' => 'What is this?', 'answer' => 'This is a FAQ item.'],
            ],
        ];
    }

    public function sanitize(array $data): array
    {
        $rawItems = is_array($data['items'] ?? null) ? $data['items'] : [];
        $items = [];
        foreach (array_values($rawItems) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $question = Html::plainText(Html::clampText((string) ($item['question'] ?? ''), 500));
            $answer = Html::sanitizeInline((string) ($item['answer'] ?? ''), 20000);
            $open = !empty($item['open']);
            if ($question === '' && Html::plainText($answer) === '') {
                continue;
            }
            $items[] = ['question' => $question, 'answer' => $answer, 'open' => $open];
            if (count($items) >= self::MAX_ITEMS) {
                break;
            }
        }
        return ['items' => $items];
    }

    public function validate(array $data): array
    {
        return [];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $d = $this->sanitize($block['data'] ?? []);
        $items = $d['items'];
        if ($items === []) {
            return '';
        }

        $blockId = isset($block['id']) ? Html::escape((string) $block['id']) : '';
        $dataAttr = $blockId !== '' ? ' data-block-id="' . $blockId . '"' : '';

        $html = '<div class="kc-block kc-block-faq"' . $dataAttr . ' itemscope itemtype="https://schema.org/FAQPage">';
        foreach ($items as $item) {
            $q = $item['question'] !== '' ? $item['question'] : 'Question';
            $a = $item['answer'];
            $open = $item['open'] ? ' open' : '';
            $html .= '<details class="kc-faq-item"' . $open . ' itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">';
            $html .= '<summary class="kc-faq-question" itemprop="name">' . Html::escape($q) . '</summary>';
            $html .= '<div class="kc-faq-answer" itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer">';
            $html .= '<div itemprop="text">' . $a . '</div>';
            $html .= '</div></details>';
        }
        $html .= '</div>';

        return $html;
    }
}
