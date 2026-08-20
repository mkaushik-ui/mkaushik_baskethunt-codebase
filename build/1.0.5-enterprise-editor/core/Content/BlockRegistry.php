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
     * Catalog for the editor UI (no renderer internals).
     *
     * @return list<array{type:string,label:string}>
     */
    public static function catalog(): array
    {
        self::boot();
        $out = [];
        foreach (self::$types as $block) {
            if ($block->type() === 'legacy') {
                continue;
            }
            $out[] = [
                'type' => $block->type(),
                'label' => $block->label(),
            ];
        }
        return $out;
    }
}
