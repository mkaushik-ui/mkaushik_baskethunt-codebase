<?php
declare(strict_types=1);

namespace SOI\Core\Links;

use PDO;
use SOI\Core\Database;
use SOI\Core\Spaces\SpaceDocumentService;
use SOI\Core\Search\IndexManager;

/**
 * Stable Document Reference & Link Resolver.
 * Domain: kc.soi.co.in (Workstream E / Milestone M5 / Task DS-03)
 *
 * Resolves stable document identities (e.g. `doc:42#heading-anchor`) to current active URLs
 * based on live space taxonomy routing. Gracefully handles unpublished or deleted targets.
 */
class LinkResolver
{
    /**
     * Resolve a raw link target (e.g. "doc:1#manual-steps" or "/docs/app-registration") to live URL and metadata.
     *
     * @param string $linkRef Stable link format ("doc:{id}#{anchor}") or standard URL
     * @param array<int, array<string, mixed>> $documentMap Optional pre-cached document map
     * @param bool $isAuthor Whether viewer is logged-in author (displays draft warning badge)
     * @param PDO|null $pdo Optional PDO database connection
     * @return array{url: string, valid: bool, status: string, title?: string, html: string}
     */
    public static function resolve(
        string $linkRef,
        array $documentMap = [],
        bool $isAuthor = false,
        ?PDO $pdo = null
    ): array {
        $linkRef = trim($linkRef);

        // Check if link is a stable reference format "doc:{id}#{anchor}"
        if (preg_match('/^doc:(\d+)(?:#(.*))?$/i', $linkRef, $matches)) {
            $docId = (int) $matches[1];
            $anchor = $matches[2] ?? '';

            // 1. Check in-memory documentMap if provided
            $foundDoc = null;
            foreach ($documentMap as $doc) {
                if (((int) ($doc['id'] ?? 0)) === $docId) {
                    $foundDoc = $doc;
                    break;
                }
            }

            // 2. Query database if not in-memory
            if (!$foundDoc) {
                $foundDoc = self::fetchDocumentFromDb($docId, $pdo);
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

            $spaceType = (string) ($foundDoc['space_type'] ?? 'docs');
            $spaceSlug = (string) ($foundDoc['space_slug'] ?? 'docs');
            $sectionSlug = (string) ($foundDoc['section_slug'] ?? '');
            $slug = (string) ($foundDoc['slug'] ?? 'doc-' . $docId);

            $canonicalUrl = IndexManager::buildCanonicalUrl($spaceType, $spaceSlug, $sectionSlug, $slug);
            $fullUrl = $canonicalUrl . ($anchor !== '' ? '#' . ltrim($anchor, '#') : '');
            $title = (string) ($foundDoc['title'] ?? 'Document #' . $docId);

            $status = strtolower((string) ($foundDoc['status'] ?? 'published'));
            if ($status !== 'published') {
                // Target unpublished / draft
                return [
                    'url' => $fullUrl,
                    'valid' => false,
                    'status' => 'unpublished',
                    'title' => $title,
                    'html' => $isAuthor
                        ? '<a href="' . htmlspecialchars($fullUrl) . '" class="kc-link-warning" title="Target document is in draft/unpublished status">⚠️ ' . htmlspecialchars($title) . ' (Draft)</a>'
                        : '<span class="kc-link-broken">' . htmlspecialchars($title) . '</span>'
                ];
            }

            // Target valid and published
            return [
                'url' => $fullUrl,
                'valid' => true,
                'status' => 'published',
                'title' => $title,
                'html' => '<a href="' . htmlspecialchars($fullUrl) . '">' . htmlspecialchars($title) . '</a>'
            ];
        }

        // Normal external or direct path URL string
        return [
            'url' => $linkRef,
            'valid' => true,
            'status' => 'external',
            'html' => '<a href="' . htmlspecialchars($linkRef) . '">' . htmlspecialchars($linkRef) . '</a>'
        ];
    }

    /**
     * Create a canonical stable document link string.
     */
    public static function makeStableRef(int $docId, string $anchor = ''): string
    {
        return 'doc:' . $docId . ($anchor !== '' ? '#' . ltrim($anchor, '#') : '');
    }

    /**
     * Fetch document record with space metadata from database.
     */
    private static function fetchDocumentFromDb(int $docId, ?PDO $pdo): ?array
    {
        $pdo = $pdo ?? self::getPdo();
        if (!$pdo || $docId <= 0) {
            return null;
        }

        try {
            // Check pages table first
            $stmt = $pdo->prepare("SELECT p.id, p.title, p.slug, p.status, p.space_id, p.section_id, 
                                          s.slug as space_slug, s.type as space_type, s.title as space_name,
                                          sec.slug as section_slug
                                   FROM `soi_pages` p
                                   LEFT JOIN `soi_spaces` s ON p.space_id = s.id
                                   LEFT JOIN `soi_space_sections` sec ON p.section_id = sec.id
                                   WHERE p.id = :id LIMIT 1");
            $stmt->execute([':id' => $docId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        } catch (\Throwable $e) {
            // Table might not exist or alternate table name
        }

        return null;
    }

    private static function getPdo(): ?PDO
    {
        try {
            if (class_exists(Database::class)) {
                $db = Database::getInstance();
                return $db ? $db->getConnection() : null;
            }
        } catch (\Throwable $e) {
            // DB not loaded
        }
        return null;
    }
}
