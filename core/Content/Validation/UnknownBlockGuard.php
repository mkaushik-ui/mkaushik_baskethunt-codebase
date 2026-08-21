<?php
declare(strict_types=1);

namespace SOI\Core\Content\Validation;

/**
 * Task T8: Unknown Block Fail-Safe Guard (Skeleton).
 * Preserves unrecognizable or future block types safely without crashing.
 */
class UnknownBlockGuard
{
    /**
     * Wrap unknown block payload in neutral container.
     *
     * @param string $type
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function wrap(string $type, array $payload): array
    {
        return [
            'originalType' => $type,
            'payload' => $payload,
            'notice' => "Block type '{$type}' is not supported by current editor version.",
        ];
    }
}
