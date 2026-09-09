<?php
declare(strict_types=1);

namespace SOI\Core\Spaces\Audience;

require_once __DIR__ . '/AudienceSubjectContext.php';
require_once __DIR__ . '/AudiencePolicyServiceInterface.php';

use SOI\Core\Cache;
use SOI\Core\Database;
use SOI\Core\Spaces\SpaceSchema;

/**
 * AudiencePolicyService
 *
 * Single Source of Truth authorization evaluation engine for SOI Knowledge Center.
 * Enforces multi-dimensional rules (visibility, departments, teams, groups, user lists, and space ownership)
 * with strict fail-closed (deny-by-default) semantics.
 */
class AudiencePolicyService implements AudiencePolicyServiceInterface
{
    private static ?self $instance = null;
    private ?\PDO $pdo;

    public function __construct(?\PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    public static function instance(?\PDO $pdo = null): self
    {
        if (self::$instance === null || $pdo !== null) {
            self::$instance = new self($pdo);
        }
        return self::$instance;
    }

    private function getPdo(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        return Database::pdo();
    }

    /**
     * {@inheritdoc}
     */
    public function canAccessSpace($space, ?AudienceSubjectContext $subject = null): bool
    {
        $subject = $subject ?? AudienceSubjectContext::fromCurrentSession();

        // 1. Global Admin always has access
        if ($subject->isAdmin()) {
            return true;
        }

        // 2. Resolve space array
        $spaceData = $this->resolveSpaceData($space);
        if (!$spaceData) {
            return false; // Deny by default: unknown space
        }

        // 3. Draft/archived space handling: require space management privilege
        $status = strtolower((string) ($spaceData['status'] ?? 'published'));
        if ($status === 'draft' || $status === 'private') {
            return $this->canManageSpace($spaceData, $subject);
        }

        // 4. Visibility evaluation
        $visibility = strtolower((string) ($spaceData['visibility'] ?? SpaceSchema::VISIBILITY_PUBLIC));

        if ($visibility === SpaceSchema::VISIBILITY_PUBLIC) {
            return true;
        }

        if ($visibility === SpaceSchema::VISIBILITY_AUTHENTICATED || $visibility === 'internal') {
            return $subject->isLoggedIn();
        }

        if ($visibility === SpaceSchema::VISIBILITY_RESTRICTED || $visibility === 'private') {
            if (!$subject->isLoggedIn()) {
                return false; // Guests cannot view restricted spaces
            }

            // Space owners can always view their space
            if ($this->isSpaceOwner($spaceData, $subject)) {
                return true;
            }

            $policy = $this->extractPolicyArray($spaceData);
            return $this->evaluateRestrictedPolicy($policy, $subject);
        }

        return false; // Deny by default
    }

    /**
     * {@inheritdoc}
     */
    public function canAccessDocument($document, ?AudienceSubjectContext $subject = null): bool
    {
        $subject = $subject ?? AudienceSubjectContext::fromCurrentSession();

        if ($subject->isAdmin()) {
            return true;
        }

        $docData = $this->resolveDocumentData($document);
        if (!$docData) {
            return false;
        }

        // Check parent space access first
        $spaceId = (int) ($docData['space_id'] ?? 0);
        if ($spaceId > 0 && !$this->canAccessSpace($spaceId, $subject)) {
            return false;
        }

        // If document status is draft/private, require manager rights
        $docStatus = strtolower((string) ($docData['status'] ?? 'published'));
        if ($docStatus === 'draft' || $docStatus === 'private') {
            return $this->canManageSpace($spaceId, $subject);
        }

        // Check document-level override policy if configured
        if (!empty($docData['audience_policy'])) {
            $docPolicy = $this->extractPolicyArray($docData);
            if (!empty($docPolicy['visibility']) && $docPolicy['visibility'] === 'restricted') {
                return $this->evaluateRestrictedPolicy($docPolicy, $subject);
            }
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function canManageSpace($space, ?AudienceSubjectContext $subject = null): bool
    {
        $subject = $subject ?? AudienceSubjectContext::fromCurrentSession();

        if ($subject->isAdmin()) {
            return true;
        }

        if (!$subject->isLoggedIn()) {
            return false;
        }

        $spaceData = $this->resolveSpaceData($space);
        if (!$spaceData) {
            return false;
        }

        return $this->isSpaceOwner($spaceData, $subject);
    }

    /**
     * {@inheritdoc}
     */
    public function filterAccessibleSpaces(array $spaces, ?AudienceSubjectContext $subject = null): array
    {
        $subject = $subject ?? AudienceSubjectContext::fromCurrentSession();
        $filtered = [];

        foreach ($spaces as $space) {
            if ($this->canAccessSpace($space, $subject)) {
                $filtered[] = $space;
            }
        }

        return array_values($filtered);
    }

    /**
     * {@inheritdoc}
     */
    public function filterNavigationTree(array $navTree, ?AudienceSubjectContext $subject = null): array
    {
        $subject = $subject ?? AudienceSubjectContext::fromCurrentSession();
        if ($subject->isAdmin()) {
            return $navTree;
        }

        $filteredTree = $navTree;

        if (isset($filteredTree['sections']) && is_array($filteredTree['sections'])) {
            $sanitizedSections = [];
            foreach ($filteredTree['sections'] as $sec) {
                // Filter child documents if present
                if (isset($sec['documents']) && is_array($sec['documents'])) {
                    $sec['documents'] = array_values(array_filter($sec['documents'], function ($doc) use ($subject) {
                        return $this->canAccessDocument($doc, $subject);
                    }));
                }
                $sanitizedSections[] = $sec;
            }
            $filteredTree['sections'] = $sanitizedSections;
        }

        return $filteredTree;
    }

    /**
     * {@inheritdoc}
     */
    public function normalizePolicy($policy): array
    {
        if (is_string($policy)) {
            $decoded = json_decode($policy, true);
            $policy = is_array($decoded) ? $decoded : [];
        } elseif (!is_array($policy)) {
            $policy = [];
        }

        $clean = [
            'visibility'    => strtolower((string) ($policy['visibility'] ?? 'public')),
            'departments'   => [],
            'teams'         => [],
            'groups'        => [],
            'allowed_users' => [],
            'space_owners'  => [],
            'inheritance'   => (string) ($policy['inheritance'] ?? 'inherit'),
        ];

        if (isset($policy['departments']) && is_array($policy['departments'])) {
            $clean['departments'] = array_values(array_unique(array_filter(array_map('trim', $policy['departments']))));
        }
        if (isset($policy['teams']) && is_array($policy['teams'])) {
            $clean['teams'] = array_values(array_unique(array_filter(array_map('trim', $policy['teams']))));
        }
        if (isset($policy['groups']) && is_array($policy['groups'])) {
            $clean['groups'] = array_values(array_unique(array_filter(array_map('trim', $policy['groups']))));
        }
        if (isset($policy['allowed_users']) && is_array($policy['allowed_users'])) {
            $clean['allowed_users'] = array_values(array_unique(array_filter(array_map('intval', $policy['allowed_users']))));
        }
        if (isset($policy['space_owners']) && is_array($policy['space_owners'])) {
            $clean['space_owners'] = array_values(array_unique(array_filter(array_map('intval', $policy['space_owners']))));
        }

        return $clean;
    }

    /**
     * Evaluate a restricted policy against a subject's claims.
     */
    private function evaluateRestrictedPolicy(array $policy, AudienceSubjectContext $subject): bool
    {
        $userId = $subject->getId();

        // 1. Check explicit user allow-list
        if ($userId !== null && !empty($policy['allowed_users'])) {
            if (in_array($userId, $policy['allowed_users'], true)) {
                return true;
            }
        }

        // 2. Check department match
        $subjectDept = $subject->getDepartment();
        if ($subjectDept !== null && !empty($policy['departments'])) {
            $normDept = strtolower($subjectDept);
            foreach ($policy['departments'] as $allowedDept) {
                if (strtolower(trim($allowedDept)) === $normDept) {
                    return true;
                }
            }
        }

        // 3. Check team match
        $subjectTeam = $subject->getTeam();
        if ($subjectTeam !== null && !empty($policy['teams'])) {
            $normTeam = strtolower($subjectTeam);
            foreach ($policy['teams'] as $allowedTeam) {
                if (strtolower(trim($allowedTeam)) === $normTeam) {
                    return true;
                }
            }
        }

        // 4. Check group/role match
        $subjectGroups = $subject->getGroups();
        if (!empty($subjectGroups) && !empty($policy['groups'])) {
            $normGroups = array_map('strtolower', $subjectGroups);
            foreach ($policy['groups'] as $allowedGroup) {
                if (in_array(strtolower(trim($allowedGroup)), $normGroups, true)) {
                    return true;
                }
            }
        }

        return false; // Fail closed
    }

    private function isSpaceOwner(array $spaceData, AudienceSubjectContext $subject): bool
    {
        $userId = $subject->getId();
        if ($userId === null) {
            return false;
        }

        $policy = $this->extractPolicyArray($spaceData);
        if (!empty($policy['space_owners']) && is_array($policy['space_owners'])) {
            return in_array($userId, array_map('intval', $policy['space_owners']), true);
        }

        return false;
    }

    private function extractPolicyArray(array $data): array
    {
        if (isset($data['audience_policy_parsed']) && is_array($data['audience_policy_parsed'])) {
            return $data['audience_policy_parsed'];
        }

        if (isset($data['audience_policy'])) {
            return $this->normalizePolicy($data['audience_policy']);
        }

        return $this->normalizePolicy([]);
    }

    private function resolveSpaceData($space): ?array
    {
        if (is_array($space)) {
            return $space;
        }

        $spaceId = (int) $space;
        if ($spaceId <= 0) {
            return null;
        }

        $cached = Cache::get("space:{$spaceId}");
        if (is_array($cached)) {
            return $cached;
        }

        $pdo = $this->getPdo();
        $table = SpaceSchema::TABLE;
        $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE `id` = ? LIMIT 1");
        $stmt->execute([$spaceId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function resolveDocumentData($document): ?array
    {
        if (is_array($document)) {
            return $document;
        }

        $docId = (int) $document;
        if ($docId <= 0) {
            return null;
        }

        $pdo = $this->getPdo();
        $stmt = $pdo->prepare("SELECT * FROM `soi_pages` WHERE `id` = ? LIMIT 1");
        $stmt->execute([$docId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
