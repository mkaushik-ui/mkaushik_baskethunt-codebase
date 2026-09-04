<?php
declare(strict_types=1);

namespace SOI\Core\Links;

/**
 * Stable Document Reference & Link Resolver.
 * Resolves stable document identities (doc:42#heading) to current active URLs and handles unpublished/deleted targets gracefully.
 */
class LinkResolver {

    /**
     * Resolve a raw link target (e.g. "doc:1#manual-steps" or "/docs/app-registration#steps") to current live URL and metadata.
     */
    public static function resolve(string $linkRef, array $documentMap = [], bool $isAuthor = false): array {
        $linkRef = trim($linkRef);

        // Check if link is a stable reference format "doc:{id}#{anchor}"
        if (preg_match('/^doc:(\d+)(?:#(.*))?$/i', $linkRef, $matches)) {
            $docId = (int)$matches[1];
            $anchor = $matches[2] ?? '';

            // Find matching document by ID
            $foundDoc = null;
            foreach ($documentMap as $doc) {
                if (((int)($doc['id'] ?? 0)) === $docId) {
                    $foundDoc = $doc;
                    break;
                }
            }

            if (!$foundDoc) {
                // Target deleted / missing
                return [
                    'url' => '#',
                    'valid' => false,
                    'status' => 'deleted',
                    'html' => $isAuthor 
                        ? '<span class="kc-link-warning" title="Linked document has been deleted">⚠️ Broken Link (Doc #' . $docId . ')</span>' 
                        : '<span class="kc-link-broken">Unavailable Document</span>'
                ];
            }

            $status = strtolower($foundDoc['status'] ?? 'published');
            if ($status !== 'published') {
                // Target unpublished / draft
                $url = '/docs/' . $foundDoc['slug'] . ($anchor ? '#' . $anchor : '');
                return [
                    'url' => $url,
                    'valid' => false,
                    'status' => 'unpublished',
                    'html' => $isAuthor 
                        ? '<a href="' . htmlspecialchars($url) . '" class="kc-link-warning" title="Target document is in draft/unpublished status">⚠️ ' . htmlspecialchars($foundDoc['title']) . ' (Draft)</a>' 
                        : '<span class="kc-link-broken">' . htmlspecialchars($foundDoc['title']) . '</span>'
                ];
            }

            // Target valid and published
            $url = '/docs/' . $foundDoc['slug'] . ($anchor ? '#' . $anchor : '');
            return [
                'url' => $url,
                'valid' => true,
                'status' => 'published',
                'title' => $foundDoc['title'],
                'html' => '<a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($foundDoc['title']) . '</a>'
            ];
        }

        // Normal URL string link
        return [
            'url' => $linkRef,
            'valid' => true,
            'status' => 'external',
            'html' => '<a href="' . htmlspecialchars($linkRef) . '">' . htmlspecialchars($linkRef) . '</a>'
        ];
    }

    /**
     * Create a stable document link string.
     */
    public static function makeStableRef(int $docId, string $anchor = ''): string {
        return 'doc:' . $docId . ($anchor !== '' ? '#' . ltrim($anchor, '#') : '');
    }
}
