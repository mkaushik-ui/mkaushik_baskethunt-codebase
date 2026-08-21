<?php
declare(strict_types=1);

namespace SOI\Core\Content\Patterns;

/**
 * Task T7: Copy-Insert Content Patterns Service (Skeleton).
 */
class PatternService
{
    /**
     * @return list<array{id:string,title:string,category:string,blocks:list<array<string,mixed>>}>
     */
    public function getPatterns(): array
    {
        return [
            [
                'id' => 'callout_procedure',
                'title' => 'Important Procedure Warning',
                'category' => 'notice',
                'blocks' => [
                    ['type' => 'callout', 'data' => ['tone' => 'warning', 'title' => 'Prerequisite Required', 'text' => 'Ensure you have administrator access before proceeding.']],
                ],
            ],
        ];
    }
}
