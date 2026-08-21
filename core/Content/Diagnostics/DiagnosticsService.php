<?php
declare(strict_types=1);

namespace SOI\Core\Content\Diagnostics;

use SOI\Core\Content\BlockRegistry;

/**
 * Task T8: System Health & Diagnostics Service (Skeleton).
 */
class DiagnosticsService
{
    /**
     * Collect system diagnostic metrics.
     *
     * @return array<string, mixed>
     */
    public function getStatus(): array
    {
        $allBlocks = BlockRegistry::all();
        return [
            'status' => 'healthy',
            'registeredBlocksCount' => count($allBlocks),
            'schemaVersion' => 1,
            'editorJsVersion' => '2.30.8',
            'timestamp' => date('c'),
        ];
    }
}
