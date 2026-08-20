<?php
declare(strict_types=1);

namespace SOI\Core\Content;

/**
 * Contract for a structured document block.
 * Plugins can register additional implementations via soi_register_editor_blocks.
 */
interface BlockType
{
    public function type(): string;

    public function label(): string;

    /**
     * Sanitize and normalize stored data. Must not throw on unknown keys.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function sanitize(array $data): array;

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    public function validate(array $data): array;

    /**
     * @param array{id:string,type:string,data:array<string,mixed>} $block
     */
    public function render(array $block, RenderContext $ctx): string;

    /**
     * Optional outline label (headings). Null hides the block from the outline.
     *
     * @param array<string, mixed> $data
     */
    public function outlineTitle(array $data): ?string;
}
