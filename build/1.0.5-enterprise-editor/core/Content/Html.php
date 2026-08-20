<?php
declare(strict_types=1);

namespace SOI\Core\Content;

/**
 * HTML/URL sanitizer for structured document fields.
 * Never used as a public renderer — only to clean author-entered fragments.
 */
final class Html
{
    private const INLINE_TAGS = ['strong', 'b', 'em', 'i', 'u', 's', 'del', 'strike', 'code', 'a', 'br', 'mark'];
    private const LEGACY_TAGS = [
        'p', 'br', 'hr', 'div', 'span', 'section',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'blockquote', 'pre', 'code',
        'strong', 'b', 'em', 'i', 'u', 's', 'del', 'strike', 'mark',
        'a', 'img', 'figure', 'figcaption',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
        'caption', 'colgroup', 'col',
    ];
    private const URL_ATTRS = ['href', 'src'];
    private const SAFE_SCHEMES = ['http', 'https', 'mailto'];

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function plainText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    public static function sanitizeInline(string $html, int $maxLength = 50000): string
    {
        return self::sanitizeFragment($html, self::INLINE_TAGS, $maxLength);
    }

    public static function sanitizeLegacy(string $html, int $maxLength = 500000): string
    {
        return self::sanitizeFragment($html, self::LEGACY_TAGS, $maxLength);
    }

    public static function sanitizeUrl(string $url, bool $allowRelative = true): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $url = preg_replace('/[\x00-\x1F\x7F]/u', '', $url) ?? $url;
        if ($url === '' || strlen($url) > 2000) {
            return '';
        }
        if (preg_match('/^\s*(javascript|data|vbscript|file|about)\s*:/i', $url)) {
            return '';
        }
        if ($url[0] === '#') {
            return preg_match('/^#[A-Za-z0-9\-_:.]+$/', $url) ? $url : '';
        }
        if ($allowRelative && ($url[0] === '/' && !str_starts_with($url, '//'))) {
            if (str_contains($url, '\\') || str_contains($url, "\n") || str_contains($url, "\r")) {
                return '';
            }
            return $url;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme'])) {
            return '';
        }
        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, self::SAFE_SCHEMES, true)) {
            return '';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }
        if ($scheme !== 'mailto' && empty($parts['host'])) {
            return '';
        }
        return $url;
    }

    public static function clampText(string $text, int $maxLength): string
    {
        if (strlen($text) <= $maxLength) {
            return $text;
        }
        return substr($text, 0, $maxLength);
    }

    /**
     * @param list<string> $allowedTags
     */
    private static function sanitizeFragment(string $html, array $allowedTags, int $maxLength): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $html = self::clampText($html, $maxLength);
        if (!str_contains($html, '<')) {
            return self::escape($html);
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $wrapped = '<div id="soi-sanitize-root">' . $html . '</div>';
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $wrapped,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return self::escape(self::plainText($html));
        }

        $root = $dom->getElementById('soi-sanitize-root');
        if (!$root) {
            return self::escape(self::plainText($html));
        }

        self::scrubNode($root, $allowedTags);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }
        return trim($out);
    }

    /**
     * @param list<string> $allowedTags
     */
    private static function scrubNode(\DOMNode $node, array $allowedTags): void
    {
        $toRemove = [];
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMComment) {
                $toRemove[] = $child;
                continue;
            }
            if ($child instanceof \DOMText) {
                continue;
            }
            if (!$child instanceof \DOMElement) {
                $toRemove[] = $child;
                continue;
            }

            $tag = strtolower($child->tagName);
            if ($tag === 'script' || $tag === 'style' || $tag === 'iframe' || $tag === 'object' || $tag === 'embed' || $tag === 'svg' || $tag === 'math' || $tag === 'link' || $tag === 'meta') {
                $toRemove[] = $child;
                continue;
            }

            if (!in_array($tag, $allowedTags, true)) {
                self::unwrapElement($child);
                self::scrubNode($node, $allowedTags);
                return;
            }

            self::scrubAttributes($child, $tag);
            self::scrubNode($child, $allowedTags);
        }

        foreach ($toRemove as $dead) {
            if ($dead->parentNode) {
                $dead->parentNode->removeChild($dead);
            }
        }
    }

    private static function unwrapElement(\DOMElement $el): void
    {
        $parent = $el->parentNode;
        if (!$parent) {
            return;
        }
        while ($el->firstChild) {
            $parent->insertBefore($el->firstChild, $el);
        }
        $parent->removeChild($el);
    }

    private static function scrubAttributes(\DOMElement $el, string $tag): void
    {
        $keep = [];
        if ($tag === 'a') {
            $href = self::sanitizeUrl((string) $el->getAttribute('href'));
            if ($href !== '') {
                $keep['href'] = $href;
            }
            $title = trim((string) $el->getAttribute('title'));
            if ($title !== '') {
                $keep['title'] = self::plainText($title);
            }
            $target = strtolower(trim((string) $el->getAttribute('target')));
            if ($target === '_blank') {
                $keep['target'] = '_blank';
                $keep['rel'] = 'noopener noreferrer';
            }
        } elseif ($tag === 'img') {
            $src = self::sanitizeUrl((string) $el->getAttribute('src'));
            if ($src !== '') {
                $keep['src'] = $src;
            }
            $alt = trim((string) $el->getAttribute('alt'));
            $keep['alt'] = self::plainText($alt);
            $width = trim((string) $el->getAttribute('width'));
            if ($width !== '' && preg_match('/^\d{1,4}(%|px)?$/', $width)) {
                $keep['width'] = $width;
            }
        } elseif (in_array($tag, ['td', 'th'], true)) {
            $colspan = trim((string) $el->getAttribute('colspan'));
            $rowspan = trim((string) $el->getAttribute('rowspan'));
            if (ctype_digit($colspan) && (int) $colspan > 0 && (int) $colspan <= 20) {
                $keep['colspan'] = $colspan;
            }
            if (ctype_digit($rowspan) && (int) $rowspan > 0 && (int) $rowspan <= 50) {
                $keep['rowspan'] = $rowspan;
            }
        }

        $remove = [];
        foreach ($el->attributes ?? [] as $attr) {
            $remove[] = $attr->nodeName;
        }
        foreach ($remove as $name) {
            $el->removeAttribute($name);
        }
        foreach ($keep as $name => $value) {
            $el->setAttribute($name, $value);
        }
    }
}
