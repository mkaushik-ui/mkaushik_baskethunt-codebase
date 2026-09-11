<?php
declare(strict_types=1);

namespace SOI\Core\Relationships;

use PDO;
use SOI\Core\Database;
use SOI\Core\Search\IndexManager;
use SOI\Core\Spaces\Audience\AudienceSubjectContext;
use SOI\Core\Spaces\Audience\AudiencePolicyService;

/**
 * Knowledge Relationships & Related Docs Manager.
 * Domain: kc.soi.co.in (Workstream E / Milestone M5 / Task DS-04)
 *
 * Maps and queries explicit relationships (parent/child, prerequisites, related topics)
 * between knowledge entities with SSOT AudiencePolicyService access control.
 */
class RelationshipManager
{
    private static array $inMemoryRelationships = [];

    /**
     * Set explicit related documents for a source document.
     *
     * @param int $sourceId Source document ID
     * @param list<int> $targetIds Target document IDs
     * @param string $type Relationship type ('related', 'parent', 'prerequisite')
     * @param PDO|null $pdo Optional PDO connection handle
     * @return bool
     */
    public static function setRelatedDocs(
        int $sourceId,
        array $targetIds,
        string $type = 'related',
        ?PDO $pdo = null
    ): bool {
        $cleanTargets = array_unique(array_filter(array_map('intval', $targetIds), static fn(int $id) => $id > 0 && $id !== $sourceId));
        self::$inMemoryRelationships[$sourceId] = $cleanTargets;

        $pdo = $pdo ?? self::getPdo();
        if (!$pdo || $sourceId <= 0) {
            return true;
        }

        RelationshipSchema::ensure($pdo);

        try {
            $pdo->beginTransaction();

            $delStmt = $pdo->prepare("DELETE FROM `soi_document_relationships` WHERE `source_doc_id` = :src AND `relationship_type` = :type");
            $delStmt->execute([':src' => $sourceId, ':type' => $type]);

            $insStmt = $pdo->prepare("INSERT INTO `soi_document_relationships` (`source_doc_id`, `target_doc_id`, `relationship_type`, `sort_order`) VALUES (:src, :tgt, :type, :sort)");
            $order = 0;
            foreach ($cleanTargets as $tgt) {
                $insStmt->execute([
                    ':src' => $sourceId,
                    ':tgt' => $tgt,
                    ':type' => $type,
                    ':sort' => $order++
                ]);
            }

            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return false;
        }
    }

    /**
     * Get explicit related document IDs for a source document.
     *
     * @param int $sourceId Source document ID
     * @param PDO|null $pdo Optional PDO handle
     * @return list<int>
     */
    public static function getRelatedDocs(int $sourceId, ?PDO $pdo = null): array
    {
        if (isset(self::$inMemoryRelationships[$sourceId])) {
            return self::$inMemoryRelationships[$sourceId];
        }

        $pdo = $pdo ?? self::getPdo();
        if (!$pdo || $sourceId <= 0) {
            return [];
        }

        try {
            RelationshipSchema::ensure($pdo);
            $stmt = $pdo->prepare("SELECT `target_doc_id` FROM `soi_document_relationships` WHERE `source_doc_id` = :src ORDER BY `sort_order` ASC");
            $stmt->execute([':src' => $sourceId]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get dependable related document metadata array for rendering in Reader UI.
     * Filters results through `AudiencePolicyService` to ensure zero information disclosure.
     *
     * @param int $sourceId Source document ID
     * @param array<int, array<string, mixed>> $allDocs Optional candidate documents
     * @param AudienceSubjectContext|null $subject Current user session context
     * @param PDO|null $pdo Optional PDO handle
     * @return list<array<string, mixed>>
     */
    public static function getRelatedDocsMetadata(
        int $sourceId,
        array $allDocs = [],
        ?AudienceSubjectContext $subject = null,
        ?PDO $pdo = null
    ): array {
        $subject = $subject ?? AudienceSubjectContext::fromCurrentSession();
        $policyService = new AudiencePolicyService();
        $relatedIds = self::getRelatedDocs($sourceId, $pdo);

        $candidates = !empty($allDocs) ? $allDocs : self::fetchCandidatesFromDb($sourceId, $pdo);
        $related = [];

        // 1. Process explicit relationship matches
        foreach ($candidates as $doc) {
            $docId = (int) ($doc['id'] ?? 0);
            $space = [
                'id' => $doc['space_id'] ?? 0,
                'slug' => $doc['space_slug'] ?? 'docs',
                'name' => $doc['space_name'] ?? 'Knowledge Center',
                'audience_policy' => $doc['audience_policy'] ?? null
            ];
            if (in_array($docId, $relatedIds, true) && strtolower((string) ($doc['status'] ?? '')) === 'published') {
                if ($policyService->canAccessDocument($doc, $space, $subject)) {
                    $related[] = self::formatRelatedDoc($doc);
                }
            }
        }

        // 2. Fallback: if no explicit relationships set, recommend published documents from same space/category
        if (empty($related) && !empty($candidates)) {
            foreach ($candidates as $doc) {
                $docId = (int) ($doc['id'] ?? 0);
                $space = [
                    'id' => $doc['space_id'] ?? 0,
                    'slug' => $doc['space_slug'] ?? 'docs',
                    'name' => $doc['space_name'] ?? 'Knowledge Center',
                    'audience_policy' => $doc['audience_policy'] ?? null
                ];
                if ($docId !== $sourceId && strtolower((string) ($doc['status'] ?? '')) === 'published') {
                    if ($policyService->canAccessDocument($doc, $space, $subject)) {
                        $related[] = self::formatRelatedDoc($doc);
                        if (count($related) >= 3) {
                            break;
                        }
                    }
                }
            }
        }

        return $related;
    }

    private static function formatRelatedDoc(array $doc): array
    {
        $spaceType = (string) ($doc['space_type'] ?? 'docs');
        $spaceSlug = (string) ($doc['space_slug'] ?? 'docs');
        $sectionSlug = (string) ($doc['section_slug'] ?? '');
        $slug = (string) ($doc['slug'] ?? '');

        return [
            'id' => (int) ($doc['id'] ?? 0),
            'title' => (string) ($doc['title'] ?? ''),
            'slug' => $slug,
            'view_url' => IndexManager::buildCanonicalUrl($spaceType, $spaceSlug, $sectionSlug, $slug),
            'category' => (string) ($doc['category'] ?? ($doc['section_title'] ?? '')),
            'space_name' => (string) ($doc['space_name'] ?? '')
        ];
    }

    private static function fetchCandidatesFromDb(int $sourceId, ?PDO $pdo): array
    {
        $pdo = $pdo ?? self::getPdo();
        if (!$pdo) {
            return [];
        }

        try {
            $stmt = $pdo->prepare("SELECT p.id, p.title, p.slug, p.status, p.space_id, p.section_id, p.audience_policy,
                                          s.slug as space_slug, s.type as space_type, s.title as space_name,
                                          sec.title as section_title, sec.slug as section_slug
                                   FROM `soi_pages` p
                                   LEFT JOIN `soi_spaces` s ON p.space_id = s.id
                                   LEFT JOIN `soi_space_sections` sec ON p.section_id = sec.id
                                   WHERE p.status = 'published' LIMIT 50");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
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
