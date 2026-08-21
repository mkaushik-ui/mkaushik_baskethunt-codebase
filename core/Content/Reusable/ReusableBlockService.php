<?php
declare(strict_types=1);

namespace SOI\Core\Content\Reusable;

use SOI\Core\Content\Document;

/**
 * Task T7: Reusable Block Service & Circular Recursion Guard (Skeleton).
 */
class ReusableBlockService
{
    /**
     * Resolve reusable block reference while guarding against circular loops.
     *
     * @param int $reusableId
     * @param list<int> $callStack Stack of active reusable IDs in current render pass
     * @return array<string, mixed>
     */
    public function resolve(int $reusableId, array &$callStack = []): array
    {
        if (in_array($reusableId, $callStack, true)) {
            // Circular loop detected! Terminate safely
            return [
                'type' => 'callout',
                'data' => [
                    'tone' => 'warning',
                    'title' => 'Circular Reference Detected',
                    'text' => "Reusable block #{$reusableId} references itself circularly.",
                ],
            ];
        }

        $callStack[] = $reusableId;
        // Developer 7: Fetch reusable payload from database and return
        return Document::empty();
    }
}
