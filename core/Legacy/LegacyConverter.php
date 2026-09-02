<?php
declare(strict_types=1);

namespace SOI\Core\Legacy;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMComment;
use InvalidArgumentException;
use Throwable;
use SOI\Core\Content\BlockRegistry;
use SOI\Core\Content\Document;
use SOI\Core\Content\Html;

/**
 * Task A3-T20: Legacy HTML Compatibility Block & Converter
 *
 * Backward compatibility handler for legacy HTML documents.
 * Safely preserves legacy HTML inside a structured legacy block when opened,
 * preventing data loss and unauthorized automatic mutation.
 * Provides explicit, non-destructive conversion into structured blocks
 * (heading, paragraph, list, quote, code, table, image, divider, callout)
 * with graceful fallback to legacy blocks for complex or custom HTML.
 *
 * @author Saurabh
 */
class LegacyConverter
{
    /**
     * Open a legacy HTML document safely as a structured document without
     * automatic conversion or data loss.
     *
     * @param string $html Original legacy HTML content
     * @param string|null $blockId Optional explicit block ID
     * @return array{schemaVersion: int, blocks: list<array<string, mixed>>}
     */
    public static function openLegacyDocument(string $html, ?string $blockId = null): array
    {
        $trimmed = trim($html);
        if ($trimmed === '') {
            return [
                'schemaVersion' => Document::SCHEMA_VERSION,
                'blocks' => [],
            ];
        }

        return [
            'schemaVersion' => Document::SCHEMA_VERSION,
            'blocks' => [
                self::createLegacyBlock($html, $blockId),
            ],
        ];
    }

    /**
     * Create a structured block of type "legacy" containing sanitized legacy HTML.
     *
     * @param string $html
     * @param string|null $blockId
     * @return array{id: string, type: string, data: array{html: string}}
     */
    public static function createLegacyBlock(string $html, ?string $blockId = null): array
    {
        $id = $blockId && trim($blockId) !== '' ? trim($blockId) : Document::newId();
        return [
            'id' => $id,
            'type' => 'legacy',
            'data' => [
                'html' => Html::sanitizeLegacy($html),
            ],
        ];
    }

    /**
     * Alias for openLegacyDocument to wrap raw legacy HTML.
     *
     * @param string $html
     * @param string|null $blockId
     * @return array{schemaVersion: int, blocks: list<array<string, mixed>>}
     */
    public static function wrapLegacyHtml(string $html, ?string $blockId = null): array
    {
        return self::openLegacyDocument($html, $blockId);
    }

    /**
     * Check whether a document or payload represents legacy content.
     *
     * @param mixed $document
     * @return bool
     */
    public static function isLegacyDocument(mixed $document): bool
    {
        if (is_string($document)) {
            return true;
        }
        if (!is_array($document)) {
            return false;
        }
        if (isset($document['editor_format']) && $document['editor_format'] === 'legacy') {
            return true;
        }
        if (isset($document['blocks']) && is_array($document['blocks'])) {
            return self::hasLegacyBlocks($document);
        }
        return false;
    }

    /**
     * Check if a structured document contains any blocks of type "legacy".
     *
     * @param array<string, mixed> $document
     * @return bool
     */
    public static function hasLegacyBlocks(array $document): bool
    {
        $blocks = $document['blocks'] ?? null;
        if (!is_array($blocks)) {
            return false;
        }
        foreach ($blocks as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'legacy') {
                return true;
            }
        }
        return false;
    }

    /**
     * Extract and combine all legacy HTML content from legacy blocks within a document.
     *
     * @param array<string, mixed> $document
     * @return string
     */
    public static function extractLegacyHtml(array $document): string
    {
        $blocks = $document['blocks'] ?? null;
        if (!is_array($blocks)) {
            return '';
        }
        $parts = [];
        foreach ($blocks as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'legacy') {
                $html = (string) ($block['data']['html'] ?? '');
                if (trim($html) !== '') {
                    $parts[] = $html;
                }
            }
        }
        return implode("\n\n", $parts);
    }

    /**
     * Universal explicit conversion entrypoint.
     * Converts either raw legacy HTML string or a structured document containing legacy blocks
     * into native structured blocks where possible.
     *
     * @param string|array<string, mixed> $input
     * @return array{schemaVersion: int, blocks: list<array<string, mixed>>}
     */
    public static function convert(string|array $input): array
    {
        if (is_string($input)) {
            return self::convertToStructured($input);
        }
        if (is_array($input)) {
            return self::convertDocument($input);
        }
        throw new InvalidArgumentException('Input to LegacyConverter::convert must be string HTML or document array.');
    }

    /**
     * Explicit conversion of a raw HTML string into a full structured document.
     *
     * @param string $html
     * @return array{schemaVersion: int, blocks: list<array<string, mixed>>}
     */
    public static function convertToStructured(string $html): array
    {
        $blocks = self::convertHtmlToBlocks($html);
        return [
            'schemaVersion' => Document::SCHEMA_VERSION,
            'blocks' => $blocks,
        ];
    }

    /**
     * Explicit conversion of an existing structured document:
     * replaces all "legacy" blocks with converted structured blocks while preserving all other blocks.
     *
     * @param array<string, mixed> $document
     * @return array{schemaVersion: int, blocks: list<array<string, mixed>>}
     */
    public static function convertDocument(array $document): array
    {
        $blocksIn = $document['blocks'] ?? [];
        if (!is_array($blocksIn)) {
            return [
                'schemaVersion' => Document::SCHEMA_VERSION,
                'blocks' => [],
            ];
        }

        $newBlocks = [];
        foreach ($blocksIn as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['type'] ?? '') === 'legacy') {
                $converted = self::convertLegacyBlock($block);
                if (!empty($converted)) {
                    foreach ($converted as $cb) {
                        $newBlocks[] = $cb;
                    }
                }
            } else {
                $newBlocks[] = $block;
            }
        }

        return [
            'schemaVersion' => (int) ($document['schemaVersion'] ?? Document::SCHEMA_VERSION),
            'blocks' => $newBlocks,
        ];
    }

    /**
     * Explicit conversion of a single legacy block into one or more structured blocks.
     *
     * @param array<string, mixed> $block
     * @return list<array<string, mixed>>
     */
    public static function convertLegacyBlock(array $block): array
    {
        $html = (string) ($block['data']['html'] ?? '');
        if (trim($html) === '') {
            return [];
        }
        $converted = self::convertHtmlToBlocks($html);
        if (empty($converted) && trim(Html::plainText($html)) !== '') {
            // Safe fallback to preserve original block if no structured blocks could be generated
            return [$block];
        }
        return $converted;
    }

    /**
     * Core conversion algorithm:
     * parses an HTML string into structured block arrays (heading, paragraph, list, quote, code, table, image, divider, callout).
     * Unconvertible or complex nodes are safely preserved as legacy blocks so zero data is lost.
     *
     * @param string $html
     * @return list<array<string, mixed>>
     */
    public static function convertHtmlToBlocks(string $html): array
    {
        $html = trim($html);
        if ($html === '') {
            return [];
        }

        // Clean unsafe tags/scripts first using project sanitizer
        $cleanHtml = Html::sanitizeLegacy($html);
        if (trim($cleanHtml) === '') {
            return [];
        }

        // If no HTML tags are present, wrap in a simple paragraph
        if (!str_contains($cleanHtml, '<')) {
            return [
                [
                    'id' => Document::newId(),
                    'type' => 'paragraph',
                    'data' => [
                        'text' => Html::sanitizeInline($cleanHtml),
                    ],
                ],
            ];
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        $wrapped = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body><div id="legacy-converter-root">'
            . $cleanHtml
            . '</div></body></html>';

        $loaded = $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            // In case of fatal parse failure, fallback to safe legacy block
            return [self::createLegacyBlock($html)];
        }

        $root = $dom->getElementById('legacy-converter-root');
        if (!$root) {
            return [self::createLegacyBlock($html)];
        }

        $blocks = [];
        $inlineBuffer = '';

        $flushInlineBuffer = function () use (&$blocks, &$inlineBuffer, $dom): void {
            $trimmed = trim($inlineBuffer);
            if ($trimmed !== '') {
                $sanitized = Html::sanitizeInline($trimmed);
                if (trim(Html::plainText($sanitized)) !== '' || str_contains($sanitized, '<img')) {
                    $blocks[] = [
                        'id' => Document::newId(),
                        'type' => 'paragraph',
                        'data' => [
                            'text' => $sanitized,
                        ],
                    ];
                }
            }
            $inlineBuffer = '';
        };

        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMComment) {
                continue;
            }

            if ($child instanceof DOMText) {
                $inlineBuffer .= $child->textContent;
                continue;
            }

            if (!$child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            // Inline elements directly under root: append to inline buffer
            if (in_array($tag, ['span', 'strong', 'b', 'em', 'i', 'u', 's', 'del', 'strike', 'code', 'a', 'mark', 'br', 'sub', 'sup'], true)) {
                $inlineBuffer .= $dom->saveHTML($child);
                continue;
            }

            // Flush preceding inline nodes before handling block element
            $flushInlineBuffer();

            $nodeBlocks = self::convertElementNode($child, $dom);
            foreach ($nodeBlocks as $nb) {
                $blocks[] = $nb;
            }
        }

        $flushInlineBuffer();

        // If no blocks were created but original input had text content, preserve as paragraph or legacy block
        if (empty($blocks)) {
            $plain = Html::plainText($cleanHtml);
            if ($plain !== '') {
                $blocks[] = [
                    'id' => Document::newId(),
                    'type' => 'paragraph',
                    'data' => [
                        'text' => Html::sanitizeInline($cleanHtml),
                    ],
                ];
            }
        }

        return $blocks;
    }

    /**
     * Convert a single DOMElement node into one or more structured blocks.
     *
     * @param DOMElement $el
     * @param DOMDocument $dom
     * @return list<array<string, mixed>>
     */
    private static function convertElementNode(DOMElement $el, DOMDocument $dom): array
    {
        $tag = strtolower($el->tagName);

        // Headings: H1 - H6
        if (preg_match('/^h([1-6])$/', $tag, $m)) {
            $level = (int) $m[1];
            // Normalize level to range 2-6 (level 1 clamped to 2 as H1 is reserved for document title)
            $level = max(2, min(6, $level));
            $text = self::getInnerHtmlSanitized($el, $dom);
            if (trim(Html::plainText($text)) === '') {
                return [];
            }
            return [
                [
                    'id' => Document::newId(),
                    'type' => 'heading',
                    'data' => [
                        'text' => $text,
                        'level' => $level,
                    ],
                ],
            ];
        }

        // Paragraph: P
        if ($tag === 'p') {
            // Check if paragraph contains only an image
            $firstImg = self::findSingleChildElement($el, 'img');
            if ($firstImg instanceof DOMElement && trim(Html::plainText($el->textContent)) === '') {
                return self::convertImageElement($firstImg, $dom);
            }

            $text = self::getInnerHtmlSanitized($el, $dom);
            if (trim(Html::plainText($text)) === '' && !str_contains($text, '<img')) {
                return [];
            }
            return [
                [
                    'id' => Document::newId(),
                    'type' => 'paragraph',
                    'data' => [
                        'text' => $text,
                    ],
                ],
            ];
        }

        // Lists: UL, OL
        if ($tag === 'ul' || $tag === 'ol') {
            $style = $tag === 'ol' ? 'ordered' : 'unordered';
            $items = self::parseListItems($el, $dom, 0);
            if (empty($items)) {
                return [];
            }

            // Check if list is a checklist
            $isChecklist = false;
            if ($style === 'unordered') {
                $hasChecked = false;
                foreach ($items as $it) {
                    if (!empty($it['checked'])) {
                        $hasChecked = true;
                        break;
                    }
                }
                if ($hasChecked || str_contains($el->getAttribute('class'), 'checklist')) {
                    $style = 'checklist';
                }
            }

            return [
                [
                    'id' => Document::newId(),
                    'type' => 'list',
                    'data' => [
                        'style' => $style,
                        'items' => $items,
                    ],
                ],
            ];
        }

        // Blockquote: QUOTE
        if ($tag === 'blockquote') {
            $caption = '';
            $footers = $el->getElementsByTagName('footer');
            if ($footers->length > 0) {
                $footer = $footers->item(0);
                if ($footer instanceof DOMElement) {
                    $caption = self::getInnerHtmlSanitized($footer, $dom);
                    $footer->parentNode?->removeChild($footer);
                }
            } else {
                $cites = $el->getElementsByTagName('cite');
                if ($cites->length > 0) {
                    $cite = $cites->item(0);
                    if ($cite instanceof DOMElement) {
                        $caption = self::getInnerHtmlSanitized($cite, $dom);
                        $cite->parentNode?->removeChild($cite);
                    }
                }
            }

            $text = self::getInnerHtmlSanitized($el, $dom);
            if (trim(Html::plainText($text)) === '') {
                return [];
            }

            return [
                [
                    'id' => Document::newId(),
                    'type' => 'quote',
                    'data' => [
                        'text' => $text,
                        'caption' => $caption,
                    ],
                ],
            ];
        }

        // Preformatted Code: PRE
        if ($tag === 'pre') {
            $codeText = '';
            $language = 'plain';

            $codeElements = $el->getElementsByTagName('code');
            if ($codeElements->length > 0) {
                $codeEl = $codeElements->item(0);
                if ($codeEl instanceof DOMElement) {
                    $codeText = $codeEl->textContent;
                    $class = $codeEl->getAttribute('class') . ' ' . $el->getAttribute('class');
                    if (preg_match('/(?:language|lang)-([a-z0-9+#._-]+)/i', $class, $m)) {
                        $language = strtolower($m[1]);
                    } elseif ($codeEl->hasAttribute('data-language')) {
                        $language = strtolower($codeEl->getAttribute('data-language'));
                    }
                }
            } else {
                $codeText = $el->textContent;
                $class = $el->getAttribute('class');
                if (preg_match('/(?:language|lang)-([a-z0-9+#._-]+)/i', $class, $m)) {
                    $language = strtolower($m[1]);
                }
            }

            if (trim($codeText) === '') {
                return [];
            }

            return [
                [
                    'id' => Document::newId(),
                    'type' => 'code',
                    'data' => [
                        'code' => $codeText,
                        'language' => $language,
                        'caption' => '',
                    ],
                ],
            ];
        }

        // Horizontal Divider: HR
        if ($tag === 'hr') {
            return [
                [
                    'id' => Document::newId(),
                    'type' => 'divider',
                    'data' => [],
                ],
            ];
        }

        // Image: IMG
        if ($tag === 'img') {
            return self::convertImageElement($el, $dom);
        }

        // Figure: FIGURE
        if ($tag === 'figure') {
            $imgs = $el->getElementsByTagName('img');
            if ($imgs->length > 0) {
                $img = $imgs->item(0);
                if ($img instanceof DOMElement) {
                    $caption = '';
                    $captions = $el->getElementsByTagName('figcaption');
                    if ($captions->length > 0) {
                        $cap = $captions->item(0);
                        if ($cap instanceof DOMElement) {
                            $caption = self::getInnerHtmlSanitized($cap, $dom);
                        }
                    }
                    $imgBlock = self::convertImageElement($img, $dom);
                    if (!empty($imgBlock) && $caption !== '') {
                        $imgBlock[0]['data']['caption'] = $caption;
                    }
                    return $imgBlock;
                }
            }
        }

        // Table: TABLE
        if ($tag === 'table') {
            $tableBlock = self::parseTableElement($el, $dom);
            if ($tableBlock !== null) {
                return [$tableBlock];
            }
        }

        // Callout Containers: DIV / ASIDE with callout classes
        $class = strtolower($el->getAttribute('class'));
        if (str_contains($class, 'callout') || str_contains($class, 'alert') || str_contains($class, 'kc-callout') || str_contains($class, 'notice')) {
            $tone = 'info';
            if (str_contains($class, 'warning') || str_contains($class, 'warn')) {
                $tone = 'warning';
            } elseif (str_contains($class, 'danger') || str_contains($class, 'error')) {
                $tone = 'danger';
            } elseif (str_contains($class, 'tip') || str_contains($class, 'recommend')) {
                $tone = 'tip';
            } elseif (str_contains($class, 'note')) {
                $tone = 'note';
            } elseif (str_contains($class, 'success')) {
                $tone = 'success';
            }

            $title = '';
            $titles = $el->getElementsByTagName('strong');
            if ($titles->length > 0) {
                $firstStrong = $titles->item(0);
                if ($firstStrong instanceof DOMElement && $firstStrong->parentNode === $el) {
                    $title = Html::plainText($firstStrong->textContent);
                    $firstStrong->parentNode->removeChild($firstStrong);
                }
            }

            $bodyHtml = self::getInnerHtmlSanitized($el, $dom);
            if (trim($title) !== '' || trim(Html::plainText($bodyHtml)) !== '') {
                return [
                    [
                        'id' => Document::newId(),
                        'type' => 'callout',
                        'data' => [
                            'tone' => $tone,
                            'title' => $title,
                            'text' => $bodyHtml,
                        ],
                    ],
                ];
            }
        }

        // Standard Containers: DIV, SECTION, ARTICLE, ASIDE, MAIN
        if (in_array($tag, ['div', 'section', 'article', 'aside', 'main'], true)) {
            $childBlocks = [];
            $hasComplexMarkup = false;

            foreach ($el->childNodes as $child) {
                if ($child instanceof DOMComment) {
                    continue;
                }
                if ($child instanceof DOMText) {
                    if (trim($child->textContent) !== '') {
                        $childBlocks[] = [
                            'id' => Document::newId(),
                            'type' => 'paragraph',
                            'data' => [
                                'text' => Html::sanitizeInline($child->textContent),
                            ],
                        ];
                    }
                    continue;
                }
                if ($child instanceof DOMElement) {
                    $res = self::convertElementNode($child, $dom);
                    if (!empty($res)) {
                        foreach ($res as $rb) {
                            $childBlocks[] = $rb;
                        }
                    }
                }
            }

            if (!empty($childBlocks)) {
                return $childBlocks;
            }
        }

        // Fallback for custom or unconvertible HTML elements: preserve safely as a legacy block
        $outerHtml = self::getNodeOuterHtml($el, $dom);
        if (trim($outerHtml) !== '') {
            return [
                self::createLegacyBlock($outerHtml),
            ];
        }

        return [];
    }

    /**
     * Convert an IMG element into an image block.
     *
     * @param DOMElement $img
     * @param DOMDocument $dom
     * @return list<array<string, mixed>>
     */
    private static function convertImageElement(DOMElement $img, DOMDocument $dom): array
    {
        $src = (string) $img->getAttribute('src');
        $sanitizedUrl = Html::sanitizeUrl($src);
        if ($sanitizedUrl === '') {
            return [];
        }

        $alt = Html::plainText((string) $img->getAttribute('alt'));
        $title = (string) $img->getAttribute('title');
        $caption = $title !== '' ? Html::sanitizeInline($title) : '';

        $width = 'default';
        $imgWidth = $img->getAttribute('width');
        $style = $img->getAttribute('style');
        if (str_contains($style, '100%') || str_contains($imgWidth, '100%') || str_contains($img->getAttribute('class'), 'full-width')) {
            $width = 'full';
        } elseif (str_contains($img->getAttribute('class'), 'wide')) {
            $width = 'wide';
        }

        $align = 'center';
        if (str_contains($style, 'float: left') || str_contains($style, 'float:left') || str_contains($img->getAttribute('class'), 'alignleft')) {
            $align = 'left';
        } elseif (str_contains($style, 'float: right') || str_contains($style, 'float:right') || str_contains($img->getAttribute('class'), 'alignright')) {
            $align = 'right';
        }

        return [
            [
                'id' => Document::newId(),
                'type' => 'image',
                'data' => [
                    'assetId' => null,
                    'url' => $sanitizedUrl,
                    'alt' => $alt,
                    'caption' => $caption,
                    'align' => $align,
                    'width' => $width,
                ],
            ],
        ];
    }

    /**
     * Parse a list element (UL or OL) and its nested child items.
     *
     * @param DOMElement $listEl
     * @param DOMDocument $dom
     * @param int $depth
     * @return list<array{text: string, checked: bool, items: list<mixed>}>
     */
    private static function parseListItems(DOMElement $listEl, DOMDocument $dom, int $depth): array
    {
        if ($depth > 4) {
            return [];
        }

        $items = [];
        foreach ($listEl->childNodes as $child) {
            if (!$child instanceof DOMElement || strtolower($child->tagName) !== 'li') {
                continue;
            }

            $checked = false;
            $nestedList = [];
            $textContent = '';

            // Check for checklist pattern like [x] or [ ] or <input type="checkbox">
            $inputs = $child->getElementsByTagName('input');
            if ($inputs->length > 0) {
                $input = $inputs->item(0);
                if ($input instanceof DOMElement && $input->getAttribute('type') === 'checkbox') {
                    $checked = $input->hasAttribute('checked');
                    $input->parentNode?->removeChild($input);
                }
            }

            // Extract nested UL / OL
            foreach (iterator_to_array($child->childNodes) as $subChild) {
                if ($subChild instanceof DOMElement && in_array(strtolower($subChild->tagName), ['ul', 'ol'], true)) {
                    $nestedList = self::parseListItems($subChild, $dom, $depth + 1);
                    $subChild->parentNode?->removeChild($subChild);
                }
            }

            $rawHtml = self::getInnerHtmlSanitized($child, $dom);

            if (!$checked) {
                if (preg_match('/^\s*\[([ xX])\]\s*(.*)$/u', $rawHtml, $m)) {
                    $checked = strtolower($m[1]) === 'x';
                    $rawHtml = $m[2];
                }
            }

            $items[] = [
                'text' => trim($rawHtml),
                'checked' => $checked,
                'items' => $nestedList,
            ];
        }

        return $items;
    }

    /**
     * Parse a TABLE element into a structured table block.
     *
     * @param DOMElement $tableEl
     * @param DOMDocument $dom
     * @return array<string, mixed>|null
     */
    private static function parseTableElement(DOMElement $tableEl, DOMDocument $dom): ?array
    {
        $rows = [];
        $hasHeadings = false;

        $trElements = $tableEl->getElementsByTagName('tr');
        if ($trElements->length === 0) {
            return null;
        }

        foreach ($trElements as $tr) {
            if (!$tr instanceof DOMElement) {
                continue;
            }

            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if (!$cell instanceof DOMElement) {
                    continue;
                }
                $cellTag = strtolower($cell->tagName);
                if ($cellTag === 'th') {
                    $hasHeadings = true;
                    $cells[] = self::getInnerHtmlSanitized($cell, $dom);
                } elseif ($cellTag === 'td') {
                    $cells[] = self::getInnerHtmlSanitized($cell, $dom);
                }
            }

            if (!empty($cells)) {
                $rows[] = $cells;
            }
        }

        if (empty($rows)) {
            return null;
        }

        // Check if the first row is within a thead
        $theads = $tableEl->getElementsByTagName('thead');
        if ($theads->length > 0) {
            $hasHeadings = true;
        }

        return [
            'id' => Document::newId(),
            'type' => 'table',
            'data' => [
                'withHeadings' => $hasHeadings,
                'content' => $rows,
            ],
        ];
    }

    /**
     * Get sanitized inner HTML from a DOM node.
     *
     * @param DOMNode $node
     * @param DOMDocument $dom
     * @return string
     */
    private static function getInnerHtmlSanitized(DOMNode $node, DOMDocument $dom): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $dom->saveHTML($child);
        }
        return Html::sanitizeInline(trim($html));
    }

    /**
     * Get sanitized outer HTML from a DOM node.
     *
     * @param DOMNode $node
     * @param DOMDocument $dom
     * @return string
     */
    private static function getNodeOuterHtml(DOMNode $node, DOMDocument $dom): string
    {
        $raw = $dom->saveHTML($node);
        return Html::sanitizeLegacy(trim($raw));
    }

    /**
     * Find single child element of a given tag if it is the only element child.
     *
     * @param DOMElement $parent
     * @param string $tagName
     * @return DOMElement|null
     */
    private static function findSingleChildElement(DOMElement $parent, string $tagName): ?DOMElement
    {
        $found = null;
        $elementCount = 0;
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $elementCount++;
                if (strtolower($child->tagName) === strtolower($tagName)) {
                    $found = $child;
                }
            }
        }
        return ($elementCount === 1) ? $found : null;
    }
}
