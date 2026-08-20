<?php
declare(strict_types=1);

namespace SOI\Core\Content;

/**
 * Mutable rendering state for a single document pass.
 */
final class RenderContext
{
    /** @var array<string, true> */
    public array $usedAnchors = [];

    /** @var list<array{id:string,level:int,text:string}> */
    public array $headings = [];

    public bool $preview = false;

    public function headingAnchor(string $text): string
    {
        $base = function_exists('slugify') ? slugify(Html::plainText($text)) : strtolower(trim($text));
        $base = preg_replace('/[^a-z0-9\-_]/', '-', $base) ?? 'heading';
        $base = trim($base, '-');
        if ($base === '') {
            $base = 'heading';
        }
        $anchor = $base;
        $i = 2;
        while (isset($this->usedAnchors[$anchor])) {
            $anchor = $base . '-' . $i;
            $i++;
        }
        $this->usedAnchors[$anchor] = true;
        return $anchor;
    }
}
