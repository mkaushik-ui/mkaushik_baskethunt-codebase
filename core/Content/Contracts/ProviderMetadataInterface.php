<?php
declare(strict_types=1);

namespace SOI\Core\Content\Contracts;

/**
 * Formal contract for block provider catalog metadata.
 */
interface ProviderMetadataInterface
{
    /**
     * Unique canonical block type identifier (e.g., 'paragraph', 'heading').
     */
    public function type(): string;

    /**
     * Editor.js block tool mapping (e.g., 'paragraph', 'header', 'linkCard').
     */
    public function editorType(): string;

    /**
     * Human-readable label for UI displays.
     */
    public function label(): string;

    /**
     * SVG icon or character representation.
     */
    public function icon(): string;

    /**
     * Component category grouping (e.g., 'basic', 'media', 'technical', 'structured', 'layout', 'notice', 'legacy').
     */
    public function group(): string;

    /**
     * Short description of what the block is used for.
     */
    public function description(): string;

    /**
     * Keywords and search synonyms for slash menu and component palette.
     */
    public function keywords(): string;

    /**
     * Default starting block data payload.
     *
     * @return array<string, mixed>
     */
    public function data(): array;
}
