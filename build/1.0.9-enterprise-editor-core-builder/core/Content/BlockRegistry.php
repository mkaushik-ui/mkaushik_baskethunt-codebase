<?php
declare(strict_types=1);

namespace SOI\Core\Content;

use SOI\Core\Content\Blocks\AccordionBlock;
use SOI\Core\Content\Blocks\CalloutBlock;
use SOI\Core\Content\Blocks\CardsBlock;
use SOI\Core\Content\Blocks\CodeBlock;
use SOI\Core\Content\Blocks\CodeGroupBlock;
use SOI\Core\Content\Blocks\ColumnsBlock;
use SOI\Core\Content\Blocks\DefinitionListBlock;
use SOI\Core\Content\Blocks\DividerBlock;
use SOI\Core\Content\Blocks\FaqBlock;
use SOI\Core\Content\Blocks\FileBlock;
use SOI\Core\Content\Blocks\GroupBlock;
use SOI\Core\Content\Blocks\HeadingBlock;
use SOI\Core\Content\Blocks\ImageBlock;
use SOI\Core\Content\Blocks\LegacyBlock;
use SOI\Core\Content\Blocks\LinkBlock;
use SOI\Core\Content\Blocks\ListBlock;
use SOI\Core\Content\Blocks\ParagraphBlock;
use SOI\Core\Content\Blocks\QuoteBlock;
use SOI\Core\Content\Blocks\StatusBadgeBlock;
use SOI\Core\Content\Blocks\StepsBlock;
use SOI\Core\Content\Blocks\TableBlock;
use SOI\Core\Content\Blocks\TabsBlock;
use SOI\Core\Content\Blocks\UnknownBlock;
use SOI\Core\Hook;

/**
 * Extensible block/tool registry. Future components register here
 * instead of being hard-wired into the editor core.
 *
 * Single source of truth for Editor.js tools, component library, and slash menu.
 */
final class BlockRegistry
{
    /** @var array<string, BlockType> */
    private static array $types = [];

    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        self::register(new ParagraphBlock());
        self::register(new HeadingBlock());
        self::register(new ListBlock());
        self::register(new QuoteBlock());
        self::register(new DividerBlock());
        self::register(new ImageBlock());
        self::register(new LinkBlock());
        self::register(new TableBlock());
        self::register(new CodeBlock());
        self::register(new CalloutBlock());
        self::register(new FileBlock());
        self::register(new StepsBlock());
        self::register(new AccordionBlock());
        self::register(new FaqBlock());
        self::register(new TabsBlock());
        self::register(new CodeGroupBlock());
        self::register(new DefinitionListBlock());
        self::register(new StatusBadgeBlock());
        self::register(new GroupBlock());
        self::register(new ColumnsBlock());
        self::register(new CardsBlock());
        self::register(new LegacyBlock());

        if (class_exists(Hook::class)) {
            Hook::doAction('soi_register_editor_blocks', self::class);
        }
    }

    public static function register(BlockType $block): void
    {
        self::$types[$block->type()] = $block;
    }

    public static function has(string $type): bool
    {
        self::boot();
        return isset(self::$types[$type]);
    }

    public static function get(string $type): BlockType
    {
        self::boot();
        return self::$types[$type] ?? new UnknownBlock($type);
    }

    /**
     * @return array<string, BlockType>
     */
    public static function all(): array
    {
        self::boot();
        return self::$types;
    }

    /**
     * Catalog for the editor UI (slash menu, component library, tests).
     *
     * @return list<array<string, mixed>>
     */
    public static function catalog(bool $includeLegacy = false): array
    {
        self::boot();
        $items = [
            self::item('paragraph', 'paragraph', 'Paragraph', 'basic', 'Body text', 'text p write', [], 'P'),
            self::item('heading', 'header', 'Heading', 'basic', 'Section title', 'h2 h3 title', ['level' => 2], 'H'),
            self::item('list', 'list', 'Bulleted list', 'basic', 'Unordered list', 'ul bullet', ['style' => 'unordered'], '•', 'list-unordered'),
            self::item('list', 'list', 'Numbered list', 'basic', 'Ordered list', 'ol numbered', ['style' => 'ordered'], '1', 'list-ordered'),
            self::item('list', 'list', 'Checklist', 'basic', 'Task list', 'todo check', ['style' => 'checklist'], '☑', 'list-checklist'),
            self::item('quote', 'quote', 'Quote', 'basic', 'Pull quote', 'blockquote', [], '“'),
            self::item('divider', 'delimiter', 'Divider', 'basic', 'Horizontal rule', 'hr rule', [], '—'),
            self::item('image', 'image', 'Image', 'media', 'Picture from the media library', 'photo media', [], '🖼'),
            self::item('file', 'file', 'File', 'media', 'Downloadable attachment', 'attachment download', [], '📎'),
            // editorType linkCard avoids colliding with Editor.js built-in Link inline tool
            self::item('link', 'linkCard', 'Link card', 'media', 'Titled URL card', 'url bookmark', [], '🔗'),
            self::item('table', 'table', 'Table', 'structured', 'Rows and columns', 'grid', ['withHeadings' => true], '▦'),
            self::item('code', 'code', 'Code', 'technical', 'Technical snippet', 'snippet pre', [], '</>'),
            self::item('callout', 'callout', 'Callout', 'notice', 'Info, tip, warning, or danger', 'notice warning tip info danger success note', ['tone' => 'info'], '!'),
            self::item('steps', 'steps', 'Steps', 'structured', 'Numbered procedural instructions', 'procedure howto guide', [
                'items' => [
                    ['title' => 'Step 1', 'content' => 'Describe the first action.'],
                    ['title' => 'Step 2', 'content' => 'Describe the next action.'],
                ],
            ], '1.'),
            self::item('accordion', 'accordion', 'Accordion', 'structured', 'Expandable sections', 'collapse expand section', [
                'items' => [
                    ['title' => 'Section title', 'content' => 'Section details.', 'open' => true],
                ],
            ], '▾'),
            self::item('faq', 'faq', 'FAQ', 'structured', 'Questions and answers', 'question answer help', [
                'items' => [
                    ['question' => 'What is this?', 'answer' => 'A short answer.'],
                ],
            ], '?'),
            self::item('tabs', 'tabs', 'Tabs', 'structured', 'Windows / macOS / Linux style panels', 'tab panel platform language', [
                'items' => [
                    ['title' => 'Windows', 'content' => 'Windows instructions.'],
                    ['title' => 'macOS', 'content' => 'macOS instructions.'],
                    ['title' => 'Linux', 'content' => 'Linux instructions.'],
                ],
            ], '↹'),
            self::item('codeGroup', 'codeGroup', 'Code Group', 'technical', 'Multi-language code samples', 'curl php javascript snippets languages', [
                'items' => [
                    ['label' => 'cURL', 'language' => 'bash', 'code' => "curl https://api.example.com\n", 'caption' => ''],
                    ['label' => 'PHP', 'language' => 'php', 'code' => "<?php\necho 'ok';\n", 'caption' => ''],
                    ['label' => 'JavaScript', 'language' => 'javascript', 'code' => "fetch('/api')\n", 'caption' => ''],
                ],
            ], '{ }'),
            self::item('definitionList', 'definitionList', 'Definition List', 'structured', 'Terms and descriptions', 'glossary terminology dl', [
                'items' => [
                    ['term' => 'Entity ID', 'description' => 'Unique identifier of a SAML application.'],
                ],
            ], '≡'),
            self::item('statusBadge', 'statusBadge', 'Status / Badge', 'notice', 'Stable, Beta, Deprecated, and more', 'badge status stable beta', [
                'status' => 'stable',
                'label' => 'Stable',
            ], '●'),
            self::item('group', 'group', 'Group', 'layout', 'Wrapper for related content', 'container section box', [
                'title' => '',
                'content' => '',
            ], '▢'),
            self::item('columns', 'columns', 'Columns', 'layout', 'Responsive multi-column layout', 'two three 50 33 grid layout', [
                'layout' => '50-50',
                'columns' => [
                    ['content' => 'Left column'],
                    ['content' => 'Right column'],
                ],
            ], '▥'),
            self::item('cards', 'cards', 'Cards', 'layout', 'Card grid with optional links', 'card grid tiles', [
                'items' => [
                    ['title' => 'Card title', 'description' => 'Short description', 'icon' => '', 'imageUrl' => '', 'linkUrl' => ''],
                ],
            ], '▤'),
        ];

        if ($includeLegacy && isset(self::$types['legacy'])) {
            $items[] = self::item('legacy', 'legacy', 'Legacy HTML', 'legacy', 'Preserved HTML from the previous editor', 'html tinymce', [], 'HTML');
        }

        if (class_exists(Hook::class)) {
            $items = Hook::applyFilters('soi_editor_block_catalog', $items);
        }

        $out = [];
        foreach ($items as $item) {
            if (!is_array($item) || ($item['type'] ?? '') === '') {
                continue;
            }
            if (($item['type'] ?? '') === 'legacy' && !$includeLegacy) {
                continue;
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function item(
        string $type,
        string $editorType,
        string $label,
        string $group,
        string $description,
        string $alias,
        array $data,
        string $icon = '',
        ?string $id = null
    ): array {
        return [
            'id' => $id ?: $type,
            'type' => $type,
            'editorType' => $editorType,
            'label' => $label,
            'group' => $group,
            'description' => $description,
            'alias' => $alias,
            'keywords' => $alias,
            'icon' => $icon !== '' ? $icon : mb_substr($label, 0, 1),
            'data' => $data,
        ];
    }
}
