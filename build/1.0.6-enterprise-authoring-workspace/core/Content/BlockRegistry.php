<?php
declare(strict_types=1);

namespace SOI\Core\Content;

use SOI\Core\Content\Blocks\CalloutBlock;
use SOI\Core\Content\Blocks\CodeBlock;
use SOI\Core\Content\Blocks\DividerBlock;
use SOI\Core\Content\Blocks\FileBlock;
use SOI\Core\Content\Blocks\HeadingBlock;
use SOI\Core\Content\Blocks\ImageBlock;
use SOI\Core\Content\Blocks\LegacyBlock;
use SOI\Core\Content\Blocks\LinkBlock;
use SOI\Core\Content\Blocks\ListBlock;
use SOI\Core\Content\Blocks\ParagraphBlock;
use SOI\Core\Content\Blocks\QuoteBlock;
use SOI\Core\Content\Blocks\TableBlock;
use SOI\Core\Content\Blocks\UnknownBlock;
use SOI\Core\Hook;

/**
 * Extensible block/tool registry. Future components register here
 * instead of being hard-wired into the editor core.
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
     * Future blocks register via soi_register_editor_blocks and appear here.
     *
     * @return list<array<string, mixed>>
     */
    public static function catalog(bool $includeLegacy = false): array
    {
        self::boot();
        $items = [
            self::item('paragraph', 'paragraph', 'Paragraph', 'basic', 'Body text', 'text p write', []),
            self::item('heading', 'header', 'Heading', 'basic', 'Section title', 'h2 h3 title', ['level' => 2]),
            self::item('list', 'list', 'Bulleted list', 'basic', 'Unordered list', 'ul bullet', ['style' => 'unordered'], 'list-unordered'),
            self::item('list', 'list', 'Numbered list', 'basic', 'Ordered list', 'ol numbered', ['style' => 'ordered'], 'list-ordered'),
            self::item('list', 'list', 'Checklist', 'basic', 'Task list', 'todo check', ['style' => 'checklist'], 'list-checklist'),
            self::item('quote', 'quote', 'Quote', 'basic', 'Pull quote', 'blockquote', []),
            self::item('divider', 'delimiter', 'Divider', 'basic', 'Horizontal rule', 'hr rule', []),
            self::item('image', 'image', 'Image', 'media', 'Picture from the media library', 'photo media', []),
            self::item('file', 'file', 'File', 'media', 'Downloadable attachment', 'attachment download', []),
            self::item('link', 'link', 'Link card', 'media', 'Titled URL', 'url bookmark', []),
            self::item('table', 'table', 'Table', 'structured', 'Rows and columns', 'grid', ['withHeadings' => true]),
            self::item('code', 'code', 'Code', 'structured', 'Technical snippet', 'snippet pre', []),
            self::item('callout', 'callout', 'Callout', 'structured', 'Info, tip, warning, or danger', 'notice warning tip info danger success note', ['tone' => 'info']),
        ];

        if ($includeLegacy && isset(self::$types['legacy'])) {
            $items[] = self::item('legacy', 'legacy', 'Legacy HTML', 'legacy', 'Preserved HTML from the previous editor', 'html tinymce', []);
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
            'data' => $data,
        ];
    }
}
