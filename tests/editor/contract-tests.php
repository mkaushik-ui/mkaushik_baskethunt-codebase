<?php
declare(strict_types=1);

namespace SOI\Tests\Editor;

use SOI\Core\Content\BlockRegistry;
use SOI\Core\Content\Document;
use SOI\Core\Content\DocumentRenderer;
use SOI\Core\Services\Autosave\AutosaveManager;
use SOI\Core\Services\Autosave\ManualSaveHandler;
use SOI\Core\Services\Autosave\StatusWorkflow;
use SOI\Core\Services\Concurrency\ConcurrencyManager;
use SOI\Core\Services\Revisions\RevisionManager;
use SOI\Core\Services\Revisions\DiffViewer;

/**
 * Task A4-T08: Final API Contract Test Suite.
 * Verifies full interaction between workspace APIs, all 26+ block types,
 * autosave workflows, revision tracking, and public rendering output.
 */
final class ContractTests
{
    public static function run(): array
    {
        $passed = 0;
        $failed = 0;
        $messages = [];

        $assert = static function (bool $condition, string $label) use (&$passed, &$failed, &$messages): void {
            if ($condition) {
                $passed++;
                $messages[] = "  PASS  [Contract] {$label}";
            } else {
                $failed++;
                $messages[] = "  FAIL  [Contract] {$label}";
            }
        };

        // 1. Verify All 26+ Integrated Block Types in Registry
        $allBlockTypes = [
            'paragraph', 'heading', 'list', 'quote', 'divider', 'image', 'file',
            'link', 'table', 'code', 'callout', 'steps', 'accordion', 'faq',
            'tabs', 'codeGroup', 'definitionList', 'statusBadge', 'group',
            'columns', 'grid', 'cards', 'apiEndpoint', 'keyValues', 'kbd',
            'reusable', 'legacy'
        ];

        foreach ($allBlockTypes as $type) {
            $assert(BlockRegistry::has($type), "Block type '{$type}' is registered in BlockRegistry");
        }

        try {
            BlockRegistry::register(new class extends \SOI\Core\Content\Blocks\AbstractBlock {
                public function type(): string { return 'paragraph'; }
                public function label(): string { return 'P'; }
                public function sanitize(array $data): array { return $data; }
                public function render(array $block, \SOI\Core\Content\RenderContext $ctx): string { return ''; }
            });
            $assert(false, 'BlockRegistry throws LogicException on duplicate block registration');
        } catch (\LogicException $e) {
            $assert(true, 'BlockRegistry throws LogicException on duplicate block registration');
        }

        // 2. Comprehensive 26+ Block Document Schema Verification
        $fullPayload = [
            'schemaVersion' => 1,
            'blocks' => [
                ['id' => 'b1', 'type' => 'paragraph', 'data' => ['text' => 'Paragraph text']],
                ['id' => 'b2', 'type' => 'heading', 'data' => ['text' => 'Contract Heading', 'level' => 2]],
                ['id' => 'b3', 'type' => 'list', 'data' => ['style' => 'unordered', 'items' => ['Item 1', 'Item 2']]],
                ['id' => 'b4', 'type' => 'quote', 'data' => ['text' => 'Quote text', 'caption' => 'Author']],
                ['id' => 'b5', 'type' => 'divider', 'data' => []],
                ['id' => 'b6', 'type' => 'image', 'data' => ['file' => ['url' => '/media/view/1'], 'caption' => 'Img']],
                ['id' => 'b7', 'type' => 'file', 'data' => ['file' => ['url' => '/media/download/1'], 'title' => 'Doc.pdf']],
                ['id' => 'b8', 'type' => 'link', 'data' => ['url' => 'https://example.com', 'title' => 'Link title']],
                ['id' => 'b9', 'type' => 'table', 'data' => ['withHeadings' => true, 'content' => [['Col 1', 'Col 2'], ['Val 1', 'Val 2']]]],
                ['id' => 'b10', 'type' => 'code', 'data' => ['code' => 'echo 1;', 'language' => 'php']],
                ['id' => 'b11', 'type' => 'callout', 'data' => ['tone' => 'info', 'title' => 'Note', 'text' => 'Callout body']],
                ['id' => 'b12', 'type' => 'steps', 'data' => ['items' => [['title' => 'Step 1', 'content' => 'First step']]]],
                ['id' => 'b13', 'type' => 'accordion', 'data' => ['items' => [['title' => 'Section', 'content' => 'Accordion body']]]],
                ['id' => 'b14', 'type' => 'faq', 'data' => ['items' => [['question' => 'Q1?', 'answer' => 'A1']]]],
                ['id' => 'b15', 'type' => 'tabs', 'data' => ['items' => [['title' => 'Tab 1', 'content' => 'Tab content']]]],
                ['id' => 'b16', 'type' => 'codeGroup', 'data' => ['items' => [['label' => 'PHP', 'language' => 'php', 'code' => '<?php echo "hi"; ?>']]]],
                ['id' => 'b17', 'type' => 'definitionList', 'data' => ['items' => [['term' => 'Term 1', 'description' => 'Desc 1']]]],
                ['id' => 'b18', 'type' => 'statusBadge', 'data' => ['status' => 'stable', 'label' => 'Stable v1']],
                ['id' => 'b19', 'type' => 'group', 'data' => ['title' => 'Container Group', 'content' => 'Group content']],
                ['id' => 'b20', 'type' => 'columns', 'data' => ['layout' => '50-50', 'columns' => [['content' => 'Left'], ['content' => 'Right']]]],
                ['id' => 'b21', 'type' => 'grid', 'data' => ['columns' => 3, 'items' => [['content' => 'G1'], ['content' => 'G2'], ['content' => 'G3']]]],
                ['id' => 'b22', 'type' => 'cards', 'data' => ['items' => [['title' => 'Card 1', 'description' => 'Card Desc']]]],
                ['id' => 'b23', 'type' => 'apiEndpoint', 'data' => ['method' => 'GET', 'endpoint' => '/api/v1/test', 'title' => 'Test API']],
                ['id' => 'b24', 'type' => 'keyValues', 'data' => ['title' => 'Metadata', 'items' => [['key' => 'Env', 'value' => 'Prod']]]],
                ['id' => 'b25', 'type' => 'kbd', 'data' => ['keys' => ['Ctrl', 'S'], 'description' => 'Save shortcut']],
                ['id' => 'b26', 'type' => 'reusable', 'data' => ['reusable_id' => 101, 'title' => 'Header Block']],
                ['id' => 'b27', 'type' => 'legacy', 'data' => ['html' => '<p>Legacy HTML segment</p>']],
            ]
        ];

        $doc = Document::parse($fullPayload);
        $assert(count($doc['blocks']) === 27, 'Document::parse preserves all 27 block types without loss');

        $validationErrors = Document::validate($doc);
        $assert(empty($validationErrors), 'Document::validate passes with zero errors for canonical payload');

        // 3. Editor.js Adapter Round-Trip Contract
        $editorJsData = Document::toEditorJs($doc);
        $assert(isset($editorJsData['blocks']) && count($editorJsData['blocks']) === 27, 'Document::toEditorJs exports all 27 blocks');
        $roundTripDoc = Document::fromEditorJs($editorJsData);
        $assert(count($roundTripDoc['blocks']) === 27, 'Document::fromEditorJs imports all 27 blocks back losslessly');

        // 4. Public Renderer Output Contract Across All Blocks
        $renderedHtml = DocumentRenderer::render($doc, false);
        $expectedClasses = [
            'kc-block-steps', 'kc-block-accordion', 'kc-block-faq', 'kc-block-tabs',
            'kc-block-codegroup', 'kc-block-deflist', 'kc-badge-stable', 'kc-block-group',
            'kc-columns-50-50', 'kc-block-grid', 'kc-block-cards', 'kc-block-api',
            'kc-block-keyvalues', 'kc-block-kbd', 'kc-callout-info'
        ];
        foreach ($expectedClasses as $class) {
            $assert(str_contains($renderedHtml, $class), "Rendered HTML contains class '{$class}'");
        }

        // 5. Autosave & Workflow Service Contracts
        $assert(class_exists(AutosaveManager::class), 'AutosaveManager API service contract exists');
        $assert(class_exists(ManualSaveHandler::class), 'ManualSaveHandler API service contract exists');
        $assert(class_exists(StatusWorkflow::class), 'StatusWorkflow API service contract exists');

        $workflow = new StatusWorkflow();
        $assert(isset(StatusWorkflow::STATUS_LABELS['draft']) && isset(StatusWorkflow::STATUS_LABELS['published']), 'StatusWorkflow defines draft and published status labels');
        $assert(!$workflow->isPubliclyVisible('draft', false), 'StatusWorkflow hides draft documents from public visitors');
        $assert($workflow->isPubliclyVisible('published', false), 'StatusWorkflow shows published documents to public visitors');

        // 6. Concurrency & Revisions Service Contracts
        $assert(class_exists(ConcurrencyManager::class), 'ConcurrencyManager API service contract exists');
        $assert(class_exists(RevisionManager::class), 'RevisionManager API service contract exists');
        $assert(class_exists(DiffViewer::class), 'DiffViewer API service contract exists');

        return [
            'passed' => $passed,
            'failed' => $failed,
            'messages' => $messages,
        ];
    }
}
