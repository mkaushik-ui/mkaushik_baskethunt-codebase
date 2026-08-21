<?php
declare(strict_types=1);

namespace SOI\Core\Content\Contracts;

/**
 * Formal contract for block metadata, schemas, validation, sanitization,
 * and inspector configuration in the SOI Knowledge Center editor platform.
 */
interface BlockProviderInterface
{
    /**
     * Unique canonical block type identifier (e.g., 'paragraph', 'heading', 'callout', 'columns').
     */
    public function type(): string;

    /**
     * Human-readable label for UI displays.
     */
    public function label(): string;

    /**
     * Component category grouping (basic, media, technical, structured, layout, notice, reuse).
     */
    public function category(): string;

    /**
     * Keywords and search synonyms for slash menu and component palette.
     */
    public function keywords(): string;

    /**
     * Capabilities array (e.g., ['nestable' => bool, 'reusable' => bool, 'wide' => bool, 'interactive' => bool]).
     *
     * @return array<string, bool>
     */
    public function capabilities(): array;

    /**
     * List of allowed parent block types (empty array means allowed anywhere).
     *
     * @return list<string>
     */
    public function allowedParents(): array;

    /**
     * Contextual inspector settings schema definition.
     *
     * @return array<string, mixed>
     */
    public function inspectorSchema(): array;

    /**
     * Default starting block data payload.
     *
     * @return array<string, mixed>
     */
    public function defaultData(): array;

    /**
     * Sanitize and normalize stored data. Must not throw on unknown keys.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function sanitize(array $data): array;

    /**
     * Validate block payload. Returns list of validation error strings (empty if valid).
     *
     * @param array<string, mixed> $data
     * @return list<string>
     */
    public function validate(array $data): array;

    /**
     * Optional outline label (e.g. for headings). Null hides the block from document outline.
     *
     * @param array<string, mixed> $data
     */
    public function outlineTitle(array $data): ?string;
}
