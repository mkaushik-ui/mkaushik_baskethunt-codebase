<?php
declare(strict_types=1);

namespace SOI\Core\Content\Contracts;

use SOI\Core\Content\RenderContext;

/**
 * Formal contract for canonical server-side block HTML rendering.
 */
interface RendererInterface
{
    /**
     * Render structured block data into semantic HTML.
     *
     * @param array{id:string,type:string,data:array<string,mixed>} $block
     */
    public function render(array $block, RenderContext $ctx): string;
}
