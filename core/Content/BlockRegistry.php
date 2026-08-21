<?php
declare(strict_types=1);

namespace SOI\Core\Content;

use SOI\Core\Content\Contracts\BlockProviderInterface;
use SOI\Core\Content\Blocks\AccordionBlock;
use SOI\Core\Content\Blocks\ApiEndpointBlock;
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
use SOI\Core\Content\Blocks\KbdBlock;
use SOI\Core\Content\Blocks\KeyValuesBlock;
use SOI\Core\Content\Blocks\LegacyBlock;
use SOI\Core\Content\Blocks\LinkBlock;
use SOI\Core\Content\Blocks\ListBlock;
use SOI\Core\Content\Blocks\ParagraphBlock;
use SOI\Core\Content\Blocks\QuoteBlock;
use SOI\Core\Content\Blocks\ReusableBlock;
use SOI\Core\Content\Blocks\StatusBadgeBlock;
use SOI\Core\Content\Blocks\StepsBlock;
use SOI\Core\Content\Blocks\TableBlock;
use SOI\Core\Content\Blocks\TabsBlock;
use SOI\Core\Content\Blocks\UnknownBlock;
use SOI\Core\Hook;

/**
 * Modular block/tool registry and provider aggregator.
 * Single source of truth for Editor.js tools, component library, patterns, and slash menu.
 */
final class BlockRegistry
{
    /** @var array<string, BlockType|BlockProviderInterface> */
    private static array $types = [];

    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        // Register default baseline built-in blocks
        self::registerBuiltinDefaults();

        // Auto-discover modular block provider classes in core/Content/Blocks/ subdirectories
        self::discoverProviders(__DIR__ . '/Blocks');

        if (class_exists(Hook::class)) {
            Hook::doAction('soi_register_editor_blocks', self::class);
        }
    }

    /**
     * Register a block type implementing BlockType or BlockProviderInterface.
     */
    public static function register(BlockType|BlockProviderInterface $block): void
    {
        self::$types[$block->type()] = $block;
    }

    /**
     * Alias for registering modular block provider.
     */
    public static function registerProvider(BlockProviderInterface $provider): void
    {
        self::register($provider);
    }

    public static function has(string $type): bool
    {
        self::boot();
        return isset(self::$types[$type]);
    }

    public static function get(string $type): BlockType|BlockProviderInterface
    {
        self::boot();
        return self::$types[$type] ?? new UnknownBlock($type);
    }

    /**
     * @return array<string, BlockType|BlockProviderInterface>
     */
    public static function all(): array
    {
        self::boot();
        return self::$types;
    }

    /**
     * Catalog for the editor UI (slash menu, component library, inspector, tests).
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
            self::item('image', 'image', 'Image', 'media', 'Picture from media library', 'photo media upload', [], '🖼'),
            self::item('file', 'file', 'File', 'media', 'Downloadable attachment', 'attachment download', [], '📎'),
            self::item('link', 'linkCard', 'Link card', 'media', 'Titled URL bookmark card', 'url bookmark web', [], '🔗'),
            self::item('table', 'table', 'Table', 'structured', 'Rows and columns grid', 'grid sheet data', ['withHeadings' => true], '▦'),
            self::item('code', 'code', 'Code', 'technical', 'Technical snippet', 'snippet pre terminal', [], '</>'),
            self::item('callout', 'callout', 'Callout', 'notice', 'Semantic info, tip, warning, or danger notice', 'notice warning tip info danger success note', ['tone' => 'info'], '!'),
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
            self::item('faq', 'faq', 'FAQ', 'structured', 'Questions and answers with Schema.org markup', 'question answer help faq', [
                'items' => [
                    ['question' => 'What is this?', 'answer' => 'A short answer.'],
                ],
            ], '?'),
            self::item('tabs', 'tabs', 'Tabs', 'structured', 'Multi-tab content switcher', 'tab panel platform language', [
                'items' => [
                    ['title' => 'Windows', 'content' => 'Windows instructions.'],
                    ['title' => 'macOS', 'content' => 'macOS instructions.'],
                    ['title' => 'Linux', 'content' => 'Linux instructions.'],
                ],
            ], '↹'),
            self::item('codeGroup', 'codeGroup', 'Code Group', 'technical', 'Multi-language code tabs (cURL, PHP, JS, Python)', 'curl php javascript snippets languages', [
                'items' => [
                    ['label' => 'cURL', 'language' => 'bash', 'code' => "curl https://api.example.com\n", 'caption' => ''],
                    ['label' => 'PHP', 'language' => 'php', 'code' => "<?php\necho 'ok';\n", 'caption' => ''],
                    ['label' => 'JavaScript', 'language' => 'javascript', 'code' => "fetch('/api')\n", 'caption' => ''],
                ],
            ], '{ }'),
            self::item('definitionList', 'definitionList', 'Definition List', 'structured', 'Terms and definitions glossary', 'glossary terminology dl terms', [
                'items' => [
                    ['term' => 'Entity ID', 'description' => 'Unique identifier of a SAML application.'],
                ],
            ], '≡'),
            self::item('statusBadge', 'statusBadge', 'Status / Badge', 'notice', 'Stable, Beta, Deprecated tags', 'badge status stable beta tag', [
                'status' => 'stable',
                'label' => 'Stable',
            ], '●'),
            self::item('apiEndpoint', 'apiEndpoint', 'API Endpoint', 'technical', 'Structured API specification with method and params', 'api endpoint rest http request response get post', [
                'method' => 'GET',
                'endpoint' => '/api/v1/resource',
                'title' => 'Get Resource',
                'description' => 'Retrieve a resource by ID.',
                'auth' => 'Bearer JWT',
                'parameters' => [
                    ['name' => 'id', 'type' => 'int', 'required' => true, 'description' => 'Resource ID'],
                ],
                'requestBody' => '',
                'responseBody' => "{\n  \"ok\": true,\n  \"id\": 123\n}",
            ], '⚡'),
            self::item('keyValues', 'keyValues', 'Key / Value', 'technical', 'Reference specifications list', 'key value spec reference table properties', [
                'title' => 'Specification',
                'items' => [
                    ['key' => 'Owner', 'value' => 'Engineering'],
                    ['key' => 'Environment', 'value' => 'Production'],
                ],
            ], '☷'),
            self::item('kbd', 'kbd', 'Keyboard Key', 'technical', 'Semantic key shortcut sequence', 'kbd shortcut key keypress command', [
                'keys' => ['Ctrl', 'Shift', 'P'],
                'description' => 'Open command palette',
            ], '⌨'),
            self::item('group', 'group', 'Group Container', 'layout', 'Wrapper container for related content', 'container section box wrapper', [
                'title' => '',
                'content' => '',
            ], '▢'),
            self::item('columns', 'columns', 'Columns', 'layout', 'Responsive multi-column layout (50/50, 33/67, 3-col)', 'two three 50 33 grid layout split', [
                'layout' => '50-50',
                'columns' => [
                    ['content' => 'Left column content'],
                    ['content' => 'Right column content'],
                ],
            ], '▥'),
            self::item('cards', 'cards', 'Cards Grid', 'layout', 'Card grid with links and icons', 'card grid tiles cards', [
                'items' => [
                    ['title' => 'Card title', 'description' => 'Short description', 'icon' => '★', 'imageUrl' => '', 'linkUrl' => 'https://example.com'],
                ],
            ], '▤'),
            self::item('reusable', 'reusable', 'Reusable Block', 'layout', 'Insert a shared reusable component', 'reusable shared synced component pattern', [
                'reusable_id' => 0,
                'title' => '',
            ], '♻'),
        ];

        if ($includeLegacy && isset(self::$types['legacy'])) {
            $items[] = self::item('legacy', 'legacy', 'Legacy HTML', 'legacy', 'Preserved HTML from the previous editor', 'html tinymce raw', [], 'HTML');
        }

        if (class_exists(Hook::class)) {
            $items = Hook::applyFilters('soi_editor_block_catalog', $items);
        }

        return $items;
    }

    /**
     * Register default built-in block types.
     */
    private static function registerBuiltinDefaults(): void
    {
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
        self::register(new ApiEndpointBlock());
        self::register(new KeyValuesBlock());
        self::register(new KbdBlock());
        self::register(new ReusableBlock());
        self::register(new LegacyBlock());
    }

    /**
     * Auto-discover block provider classes in subdirectories.
     */
    private static function discoverProviders(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $subdirs = glob($directory . '/*', GLOB_ONLYDIR);
        if (!$subdirs) {
            return;
        }

        foreach ($subdirs as $subdir) {
            $files = glob($subdir . '/*Block.php');
            if (!$files) {
                continue;
            }
            $subNamespace = basename($subdir);
            foreach ($files as $file) {
                $className = basename($file, '.php');
                $fqcn = "SOI\\Core\\Content\\Blocks\\{$subNamespace}\\{$className}";
                if (class_exists($fqcn) && !isset(self::$types[strtolower($className)])) {
                    try {
                        $ref = new \ReflectionClass($fqcn);
                        if (!$ref->isAbstract() && ($ref->implementsInterface(BlockProviderInterface::class) || $ref->implementsInterface(BlockType::class))) {
                            /** @var BlockType|BlockProviderInterface $instance */
                            $instance = new $fqcn();
                            self::register($instance);
                        }
                    } catch (\Throwable $e) {
                        // Safe skip on instantiation failure
                    }
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function item(
        string $id,
        string $editorType,
        string $label,
        string $group,
        string $description,
        string $keywords,
        array $data = [],
        string $icon = '',
        ?string $alias = null,
    ): array {
        return [
            'id' => $alias ?? $id,
            'type' => $id,
            'editorType' => $editorType,
            'label' => $label,
            'group' => $group,
            'description' => $description,
            'keywords' => $keywords,
            'icon' => $icon,
            'data' => $data,
        ];
    }
}
