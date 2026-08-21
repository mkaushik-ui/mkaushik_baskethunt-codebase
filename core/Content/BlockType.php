<?php
declare(strict_types=1);

namespace SOI\Core\Content;

use SOI\Core\Content\Contracts\BlockProviderInterface;
use SOI\Core\Content\Contracts\RendererInterface;

/**
 * Contract for a structured document block (extends BlockProviderInterface & RendererInterface).
 * Plugins can register additional implementations via soi_register_editor_blocks.
 */
interface BlockType extends BlockProviderInterface, RendererInterface
{
}
