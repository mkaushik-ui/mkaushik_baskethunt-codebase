<?php
declare(strict_types=1);

namespace SOI\Core\Content\Blocks;

use SOI\Core\Content\Document;
use SOI\Core\Content\DocumentRenderer;
use SOI\Core\Content\Html;
use SOI\Core\Content\RenderContext;
use SOI\Core\Database;

final class ReusableBlock extends AbstractBlock
{
    private static array $renderingStack = [];

    public function type(): string
    {
        return 'reusable';
    }

    public function label(): string
    {
        return 'Reusable Block';
    }

    public function sanitize(array $data): array
    {
        $id = (int) ($data['reusable_id'] ?? $data['id'] ?? 0);
        $title = trim($this->text($data['title'] ?? '', 200));
        return ['reusable_id' => $id, 'title' => $title];
    }

    public function render(array $block, RenderContext $ctx): string
    {
        $d = $this->sanitize($block['data'] ?? []);
        $id = $d['reusable_id'];
        $blockId = Html::escape($block['id'] ?? '');

        if ($id <= 0) {
            return '<div class="kc-reusable-empty" data-block-id="' . $blockId . '">[Empty reusable block]</div>';
        }

        // Prevent infinite recursion loops
        if (in_array($id, self::$renderingStack, true)) {
            return '<div class="kc-reusable-cycle" data-block-id="' . $blockId . '">[Circular reusable reference: #' . $id . ']</div>';
        }

        self::$renderingStack[] = $id;

        try {
            $row = null;
            if (class_exists(Database::class) && Database::isConnected()) {
                $table = Database::prefix('kc_reusable_blocks');
                if (Database::tableExists('kc_reusable_blocks')) {
                    $row = Database::selectOne("SELECT * FROM `$table` WHERE id = ? LIMIT 1", [$id]);
                }
            }

            if (!$row || empty($row['content_json'])) {
                return '<div class="kc-reusable-missing" data-block-id="' . $blockId . '">[Reusable block #' . $id . ' not found]</div>';
            }

            $doc = Document::parse($row['content_json']);
            $html = DocumentRenderer::render($doc, $ctx->preview);

            return '<div class="kc-block kc-block-reusable" data-block-id="' . $blockId . '" data-reusable-id="' . $id . '">'
                . '<div class="kc-reusable-header"><span class="kc-reusable-badge">REUSABLE</span> <span class="kc-reusable-title">' . Html::escape($row['title'] ?: 'Reusable component') . '</span></div>'
                . '<div class="kc-reusable-content">' . $html . '</div>'
                . '</div>';
        } finally {
            array_pop(self::$renderingStack);
        }
    }

    public function outlineTitle(array $data): ?string
    {
        $d = $this->sanitize($data);
        return 'Reusable: ' . ($d['title'] ?: '#' . $d['reusable_id']);
    }
}
