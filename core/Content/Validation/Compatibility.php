<?php
declare(strict_types=1);

namespace SOI\Core\Blocks;

/**
 * Central registry for parent/child block compatibility rules.
 *
 * An empty rule means the parent has no explicit child restrictions.
 */
final class Compatibility
{
    /**
     * Explicitly disallowed parent => child combinations.
     *
     * @var array<string, list<string>>
     */
    private const DISALLOWED = [
        'columns' => [
            'columns',
        ],
    ];

    /**
     * Check whether a child block may be placed directly inside a parent.
     */
    public static function isAllowed(string $parentType, string $childType): bool
    {
        $parentType = trim($parentType);
        $childType = trim($childType);

        if ($parentType === '' || $childType === '') {
            return false;
        }

        return !in_array(
            $childType,
            self::DISALLOWED[$parentType] ?? [],
            true
        );
    }

    /**
     * Return a clear validation error, or null when compatible.
     */
    public static function validate(string $parentType, string $childType): ?string
    {
        if (self::isAllowed($parentType, $childType)) {
            return null;
        }

        return sprintf(
            'Block "%s" cannot contain block "%s" directly.',
            $parentType,
            $childType
        );
    }

    /**
     * Return all explicitly disallowed children for a parent.
     *
     * @return list<string>
     */
    public static function disallowedChildren(string $parentType): array
    {
        return self::DISALLOWED[$parentType] ?? [];
    }
}
