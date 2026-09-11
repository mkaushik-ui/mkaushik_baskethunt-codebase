<?php
declare(strict_types=1);

namespace SOI\Core\Spaces\Audience;

/**
 * AudiencePolicyService
 *
 * Canonical policy evaluation and authorization engine implementing AudiencePolicyServiceInterface [WD-02].
 *
 * Enforces strict fail-closed (deny-by-default) multi-dimensional audience access rules across:
 * - Space Visibility (public, authenticated, restricted, private)
 * - Department membership ($subject->getDepartment() in policy.departments)
 * - Team membership ($subject->getTeam() in policy.teams)
 * - Group membership (array intersection between $subject->getGroups() and policy.groups)
 * - Specific User allowlists ($subject->getId() in policy.allowed_users)
 * - Space Ownership ($subject->getId() in policy.space_owners or space.owner_id)
 * - Exclusion rules (excluded_users, excluded_departments, excluded_teams)
 * - Document inheritance and override policies
 * - Collection and hierarchical navigation tree pruning
 */
class AudiencePolicyService implements AudiencePolicyServiceInterface
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @inheritDoc
     */
    public function canAccessSpace(array|object $space, ?AudienceSubjectContext $subject = null): bool
    {
        $subject = $subject ?? AudienceSubjectContext::fromCurrentSession();
        $spaceData = $this->normalizeResourceArray($space);

        if (empty($spaceData)) {
            return false;
        }

        // 1. Global Admin Bypass
        if ($subject->isAdmin()) {
            return true;
        }

        // 2. Space Status Guard (Draft / Archived / Inactive)
        $status = strtolower(trim((string) ($spaceData['status'] ?? 'published')));
        if (in_array($status, ['draft', 'archived', 'inactive', 'hidden'], true)) {
            if (!$subject->isLoggedIn()) {
                return false;
            }
            // Space owners, editors, and managers can access draft/archived spaces
            if ($this->isSpaceOwner($spaceData, $subject) || $subject->isEditor() || $this->canManageSpace($spaceData, $subject)) {
                return true;
            }
            return false;
        }

        // 3. Space Owner Access
        if ($this->isSpaceOwner($spaceData, $subject)) {
            return true;
        }

        // 4. Resolve Raw Policy & Visibility
        $rawPolicy = $spaceData['audience_policy'] ?? null;
        $policyValidation = AudiencePolicySchema::validate($rawPolicy);
        $policy = $policyValidation['normalized'] ?? AudiencePolicySchema::defaultPolicy();

        $visibility = strtolower(trim((string) (
            $spaceData['visibility']
            ?? $policy[AudiencePolicySchema::KEY_VISIBILITY]
            ?? AudiencePolicySchema::VISIBILITY_PUBLIC
        )));

        // 5. Check Exclusions (takes precedence over all grant rules)
        if ($subject->isLoggedIn() && $this->isSubjectExcluded($policy, $subject)) {
            return false;
        }

        // 6. Evaluate by Visibility Mode
        switch ($visibility) {
            case AudiencePolicySchema::VISIBILITY_PUBLIC:
                return true;

            case AudiencePolicySchema::VISIBILITY_AUTHENTICATED:
            case AudiencePolicySchema::VISIBILITY_INTERNAL:
                if (!$subject->isLoggedIn()) {
                    return false;
                }
                // If specific restricted rules are also attached, evaluate them
                if ($this->hasRestrictedRules($policy)) {
                    return $this->evaluateRestrictedSpace($policy, $subject, $spaceData);
                }
                return true;

            case AudiencePolicySchema::VISIBILITY_RESTRICTED:
                // If raw policy was empty or invalid JSON string, strictly fail-closed (deny-by-default)
                if (is_string($rawPolicy) && trim($rawPolicy) !== '') {
                    if (!$policyValidation['valid']) {
                        return false;
                    }
                } elseif (empty($rawPolicy) && empty($spaceData['audience_policy'])) {
                    // Empty policy on restricted space fails closed
                    return false;
                }
                return $this->evaluateRestrictedSpace($policy, $subject, $spaceData);

            case AudiencePolicySchema::VISIBILITY_PRIVATE:
                return $this->evaluatePrivateSpace($spaceData, $policy, $subject);

            default:
                // Unknown visibility falls back to restricted fail-closed
                return $this->evaluateRestrictedSpace($policy, $subject, $spaceData);
        }
    }

    /**
     * @inheritDoc
     */
    public function canAccessDocument(array|object $document, array|object $space, ?AudienceSubjectContext $subject = null): bool
    {
        $subject = $subject ?? AudienceSubjectContext::fromCurrentSession();
        $docData = $this->normalizeResourceArray($document);
        $spaceData = $this->normalizeResourceArray($space);

        if (empty($docData)) {
            return false;
        }

        // 1. Global Admin Bypass
        if ($subject->isAdmin()) {
            return true;
        }

        // 2. Space-Level Access Guard (Inheritance Principle)
        if (!$this->canAccessSpace($spaceData, $subject)) {
            return false;
        }

        // 3. Document Author / Creator Access
        $authorId = isset($docData['author_id']) ? (int) $docData['author_id'] : (isset($docData['user_id']) ? (int) $docData['user_id'] : null);
        if ($authorId !== null && $authorId > 0 && $subject->getId() === $authorId) {
            return true;
        }

        // 4. Document Status Check (Draft / In Review / Archived)
        $docStatus = strtolower(trim((string) ($docData['status'] ?? 'published')));
        if (in_array($docStatus, ['draft', 'in_review', 'pending', 'archived'], true)) {
            if (!$subject->isLoggedIn()) {
                return false;
            }
            if ($subject->isAuthor() || $subject->isEditor() || $this->canManageSpace($spaceData, $subject)) {
                return true;
            }
            return false;
        }

        // 5. Document Specific Visibility & Policy Override
        if (isset($docData['visibility']) || !empty($docData['audience_policy'])) {
            $rawDocPolicy = $docData['audience_policy'] ?? null;
            $docPolicyValidation = AudiencePolicySchema::validate($rawDocPolicy);
            $docPolicy = $docPolicyValidation['normalized'] ?? AudiencePolicySchema::defaultPolicy();

            $docVisibility = strtolower(trim((string) (
                $docData['visibility']
                ?? $docPolicy[AudiencePolicySchema::KEY_VISIBILITY]
                ?? AudiencePolicySchema::VISIBILITY_PUBLIC
            )));

            // Document exclusions
            if ($subject->isLoggedIn() && $this->isSubjectExcluded($docPolicy, $subject)) {
                return false;
            }

            if ($docVisibility === AudiencePolicySchema::VISIBILITY_RESTRICTED || $docVisibility === AudiencePolicySchema::VISIBILITY_PRIVATE) {
                if (is_string($rawDocPolicy) && trim($rawDocPolicy) !== '' && !$docPolicyValidation['valid']) {
                    return false;
                }
                return $this->evaluateRestrictedSpace($docPolicy, $subject, $spaceData);
            }

            if ($docVisibility === AudiencePolicySchema::VISIBILITY_AUTHENTICATED && !$subject->isLoggedIn()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function canManageSpace(array|object $space, ?AudienceSubjectContext $subject = null): bool
    {
        $subject = $subject ?? AudienceSubjectContext::fromCurrentSession();
        if (!$subject->isLoggedIn()) {
            return false;
        }

        // 1. Global Admin Bypass
        if ($subject->isAdmin()) {
            return true;
        }

        $spaceData = $this->normalizeResourceArray($space);
        if (empty($spaceData)) {
            return false;
        }

        // 2. Space Owner check (space record owner_id / user_id)
        if ($this->isSpaceOwner($spaceData, $subject)) {
            return true;
        }

        // 3. Explicit permission check
        if ($subject->hasPermission('kb.space.manage') || $subject->hasPermission('cms.admin')) {
            return true;
        }

        // 4. Policy-defined Space Owners & Managers
        $policy = AudiencePolicySchema::normalize($spaceData['audience_policy'] ?? null);
        $userId = $subject->getId();
        $userEmail = $subject->getEmail();

        // policy.space_owners check
        foreach ($policy[AudiencePolicySchema::KEY_SPACE_OWNERS] as $owner) {
            if (is_int($owner) && $userId !== null && $owner === $userId) {
                return true;
            }
            if (is_string($owner) && $userEmail !== null && strtolower($owner) === $userEmail) {
                return true;
            }
        }

        // policy.manager_roles check
        foreach ($policy[AudiencePolicySchema::KEY_MANAGER_ROLES] as $role) {
            if ($subject->hasRole($role)) {
                return true;
            }
        }

        // policy.manager_groups check
        foreach ($policy[AudiencePolicySchema::KEY_MANAGER_GROUPS] as $group) {
            if ($subject->inGroup($group)) {
                return true;
            }
        }

        // policy.manager_users check
        foreach ($policy[AudiencePolicySchema::KEY_MANAGER_USERS] as $targetUser) {
            if (is_int($targetUser) && $userId !== null && $targetUser === $userId) {
                return true;
            }
            if (is_string($targetUser) && $userEmail !== null && strtolower($targetUser) === $userEmail) {
                return true;
            }
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function filterAccessibleSpaces(array $spaces, ?AudienceSubjectContext $subject = null): array
    {
        $subject = $subject ?? AudienceSubjectContext::fromCurrentSession();
        $results = [];

        foreach ($spaces as $space) {
            if ($this->canAccessSpace($space, $subject)) {
                $results[] = $space;
            }
        }

        return array_values($results);
    }

    /**
     * @inheritDoc
     */
    public function filterNavigationTree(array $nodes, array|object $space, ?AudienceSubjectContext $subject = null): array
    {
        $subject = $subject ?? AudienceSubjectContext::fromCurrentSession();
        $spaceData = $this->normalizeResourceArray($space);
        $filtered = [];

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            // Check if node is a document node with audience restriction
            $isNodeAuthorized = true;
            if (isset($node['document']) || (isset($node['type']) && $node['type'] === 'document')) {
                $docData = $node['document'] ?? $node;
                $isNodeAuthorized = $this->canAccessDocument($docData, $spaceData, $subject);
            } elseif (isset($node['visibility']) || !empty($node['audience_policy'])) {
                $isNodeAuthorized = $this->canAccessDocument($node, $spaceData, $subject);
            }

            // Process child nodes recursively
            $childNodes = $node['children'] ?? $node['items'] ?? $node['nodes'] ?? null;
            if (is_array($childNodes) && !empty($childNodes)) {
                $childKey = isset($node['children']) ? 'children' : (isset($node['items']) ? 'items' : 'nodes');
                $filteredChildren = $this->filterNavigationTree($childNodes, $spaceData, $subject);
                $node[$childKey] = $filteredChildren;

                // Retain folder/section if authorized or contains authorized child nodes
                if ($isNodeAuthorized || !empty($filteredChildren)) {
                    $filtered[] = $node;
                }
            } elseif ($isNodeAuthorized) {
                $filtered[] = $node;
            }
        }

        return array_values($filtered);
    }

    /**
     * @inheritDoc
     */
    public function validatePolicySchema(array|string|null $policy): array
    {
        return AudiencePolicySchema::validate($policy);
    }

    /**
     * @inheritDoc
     */
    public function normalizePolicy(array|string|null $policy, ?string $visibility = null): array
    {
        return AudiencePolicySchema::normalize($policy, $visibility);
    }

    /**
     * @inheritDoc
     */
    public function getDefaultPolicy(?string $visibility = null): array
    {
        return AudiencePolicySchema::defaultPolicy($visibility);
    }

    /**
     * @inheritDoc
     */
    public function explainAccess(array|object $resource, ?AudienceSubjectContext $subject = null, string $action = 'view'): array
    {
        $subject = $subject ?? AudienceSubjectContext::fromCurrentSession();
        $resData = $this->normalizeResourceArray($resource);

        if ($subject->isAdmin()) {
            return [
                'allowed'      => true,
                'reason'       => 'Access granted via administrator superuser privileges.',
                'rule_matched' => 'admin_bypass',
                'subject'      => $subject->toArray(),
            ];
        }

        if (strtolower($action) === 'manage' || strtolower($action) === 'edit') {
            $canManage = $this->canManageSpace($resData, $subject);
            return [
                'allowed'      => $canManage,
                'reason'       => $canManage ? 'User has management permissions for this space.' : 'User is not a space manager, owner, or editor.',
                'rule_matched' => $canManage ? 'space_manager' : null,
                'subject'      => $subject->toArray(),
            ];
        }

        $canAccess = $this->canAccessSpace($resData, $subject);
        $visibility = strtolower(trim((string) ($resData['visibility'] ?? AudiencePolicySchema::VISIBILITY_PUBLIC)));

        return [
            'allowed'      => $canAccess,
            'reason'       => $canAccess
                ? "Access granted under '{$visibility}' visibility and audience policy criteria."
                : "Access denied under '{$visibility}' policy: subject lacks qualifying department, team, role, or authentication.",
            'rule_matched' => $canAccess ? "visibility_{$visibility}" : null,
            'subject'      => $subject->toArray(),
        ];
    }

    // =========================================================================
    // Internal Evaluation Mechanics
    // =========================================================================

    /**
     * Evaluate restricted visibility space.
     *
     * @param array<string, mixed> $policy
     * @param AudienceSubjectContext $subject
     * @param array<string, mixed> $spaceData
     * @return bool
     */
    private function evaluateRestrictedSpace(array $policy, AudienceSubjectContext $subject, array $spaceData = []): bool
    {
        if (!$subject->isLoggedIn()) {
            return false;
        }

        // 1. Space Ownership in policy or spaceData
        if ($this->isSpaceOwner($spaceData, $subject) || $this->isPolicySpaceOwner($policy, $subject)) {
            return true;
        }

        $mode = $policy[AudiencePolicySchema::KEY_MODE] ?? AudiencePolicySchema::MODE_ANY;
        $activeCategories = $this->getActiveRuleCategories($policy);

        // If no criteria are configured in restricted mode, deny by default (fail-closed)
        if (empty($activeCategories)) {
            return false;
        }

        if ($mode === AudiencePolicySchema::MODE_ALL) {
            // ALL active rule categories must have at least one match
            foreach ($activeCategories as $category) {
                if (!$this->matchesCategory($category, $policy, $subject)) {
                    return false;
                }
            }
            return true;
        }

        // ANY mode (default): match at least one active rule
        foreach ($activeCategories as $category) {
            if ($this->matchesCategory($category, $policy, $subject)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Evaluate private visibility space.
     *
     * @param array<string, mixed> $spaceData
     * @param array<string, mixed> $policy
     * @param AudienceSubjectContext $subject
     * @return bool
     */
    private function evaluatePrivateSpace(array $spaceData, array $policy, AudienceSubjectContext $subject): bool
    {
        if (!$subject->isLoggedIn()) {
            return false;
        }

        if ($this->isSpaceOwner($spaceData, $subject) || $this->isPolicySpaceOwner($policy, $subject)) {
            return true;
        }

        // Direct user inclusion (allowed_users / users)
        $userId = $subject->getId();
        $userEmail = $subject->getEmail();
        $allowedUsers = array_merge(
            $policy[AudiencePolicySchema::KEY_ALLOWED_USERS] ?? [],
            $policy[AudiencePolicySchema::KEY_USERS] ?? []
        );

        foreach ($allowedUsers as $targetUser) {
            if (is_int($targetUser) && $userId !== null && $targetUser === $userId) {
                return true;
            }
            if (is_string($targetUser) && $userEmail !== null && strtolower($targetUser) === $userEmail) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the subject is explicitly excluded by the policy.
     *
     * @param array<string, mixed> $policy
     * @param AudienceSubjectContext $subject
     * @return bool
     */
    private function isSubjectExcluded(array $policy, AudienceSubjectContext $subject): bool
    {
        $userId = $subject->getId();
        $userEmail = $subject->getEmail();

        // Check excluded users
        foreach ($policy[AudiencePolicySchema::KEY_EXCLUDED_USERS] as $excludedUser) {
            if (is_int($excludedUser) && $userId !== null && $excludedUser === $userId) {
                return true;
            }
            if (is_string($excludedUser) && $userEmail !== null && strtolower($excludedUser) === $userEmail) {
                return true;
            }
        }

        // Check excluded departments
        foreach ($policy[AudiencePolicySchema::KEY_EXCLUDED_DEPARTMENTS] as $excludedDept) {
            if ($subject->inDepartment($excludedDept)) {
                return true;
            }
        }

        // Check excluded teams
        foreach ($policy[AudiencePolicySchema::KEY_EXCLUDED_TEAMS] as $excludedTeam) {
            if ($subject->inTeam($excludedTeam)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match a specific rule category against subject claims.
     *
     * @param string $category
     * @param array<string, mixed> $policy
     * @param AudienceSubjectContext $subject
     * @return bool
     */
    private function matchesCategory(string $category, array $policy, AudienceSubjectContext $subject): bool
    {
        switch ($category) {
            case AudiencePolicySchema::KEY_DEPARTMENTS:
                foreach ($policy[AudiencePolicySchema::KEY_DEPARTMENTS] as $dept) {
                    if ($subject->inDepartment($dept)) {
                        return true;
                    }
                }
                return false;

            case AudiencePolicySchema::KEY_TEAMS:
                foreach ($policy[AudiencePolicySchema::KEY_TEAMS] as $team) {
                    if ($subject->inTeam($team)) {
                        return true;
                    }
                }
                return false;

            case AudiencePolicySchema::KEY_GROUPS:
                // Array intersection between subject groups and policy groups
                $subjectGroups = array_map('strtolower', $subject->getGroups());
                $policyGroups = array_map('strtolower', (array) ($policy[AudiencePolicySchema::KEY_GROUPS] ?? []));
                $intersection = array_intersect($subjectGroups, $policyGroups);
                return !empty($intersection);

            case AudiencePolicySchema::KEY_ROLES:
                foreach ($policy[AudiencePolicySchema::KEY_ROLES] as $role) {
                    if ($subject->hasRole($role)) {
                        return true;
                    }
                }
                return false;

            case AudiencePolicySchema::KEY_PERMISSIONS:
                foreach ($policy[AudiencePolicySchema::KEY_PERMISSIONS] as $perm) {
                    if ($subject->hasPermission($perm)) {
                        return true;
                    }
                }
                return false;

            case AudiencePolicySchema::KEY_ALLOWED_USERS:
            case AudiencePolicySchema::KEY_USERS:
                $userId = $subject->getId();
                $userEmail = $subject->getEmail();
                $allowedUsers = array_merge(
                    $policy[AudiencePolicySchema::KEY_ALLOWED_USERS] ?? [],
                    $policy[AudiencePolicySchema::KEY_USERS] ?? []
                );

                foreach ($allowedUsers as $targetUser) {
                    if (is_int($targetUser) && $userId !== null && $targetUser === $userId) {
                        return true;
                    }
                    if (is_string($targetUser) && $userEmail !== null && strtolower($targetUser) === $userEmail) {
                        return true;
                    }
                }
                return false;

            case AudiencePolicySchema::KEY_SPACE_OWNERS:
                return $this->isPolicySpaceOwner($policy, $subject);

            default:
                return false;
        }
    }

    /**
     * @param array<string, mixed> $policy
     * @return list<string>
     */
    private function getActiveRuleCategories(array $policy): array
    {
        $categories = [
            AudiencePolicySchema::KEY_DEPARTMENTS,
            AudiencePolicySchema::KEY_TEAMS,
            AudiencePolicySchema::KEY_GROUPS,
            AudiencePolicySchema::KEY_ALLOWED_USERS,
            AudiencePolicySchema::KEY_USERS,
            AudiencePolicySchema::KEY_SPACE_OWNERS,
            AudiencePolicySchema::KEY_ROLES,
            AudiencePolicySchema::KEY_PERMISSIONS,
        ];

        $active = [];
        foreach ($categories as $cat) {
            if (!empty($policy[$cat]) && is_array($policy[$cat])) {
                if (!in_array($cat, $active, true)) {
                    $active[] = $cat;
                }
            }
        }
        return $active;
    }

    /**
     * @param array<string, mixed> $policy
     * @return bool
     */
    private function hasRestrictedRules(array $policy): bool
    {
        return !empty($this->getActiveRuleCategories($policy));
    }

    /**
     * Check whether the subject is the registered owner of the space via space data.
     *
     * @param array<string, mixed> $spaceData
     * @param AudienceSubjectContext $subject
     * @return bool
     */
    private function isSpaceOwner(array $spaceData, AudienceSubjectContext $subject): bool
    {
        $subjectId = $subject->getId();
        if ($subjectId === null || $subjectId <= 0) {
            return false;
        }

        $ownerId = isset($spaceData['owner_id']) ? (int) $spaceData['owner_id'] : (isset($spaceData['user_id']) ? (int) $spaceData['user_id'] : null);
        return $ownerId !== null && $ownerId === $subjectId;
    }

    /**
     * Check whether the subject is in policy.space_owners.
     *
     * @param array<string, mixed> $policy
     * @param AudienceSubjectContext $subject
     * @return bool
     */
    private function isPolicySpaceOwner(array $policy, AudienceSubjectContext $subject): bool
    {
        $userId = $subject->getId();
        $userEmail = $subject->getEmail();
        $owners = (array) ($policy[AudiencePolicySchema::KEY_SPACE_OWNERS] ?? []);

        foreach ($owners as $owner) {
            if (is_int($owner) && $userId !== null && $owner === $userId) {
                return true;
            }
            if (is_string($owner) && $userEmail !== null && strtolower($owner) === $userEmail) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert space or document object/array into associative array.
     *
     * @param array<string, mixed>|object $resource
     * @return array<string, mixed>
     */
    private function normalizeResourceArray(array|object $resource): array
    {
        if (is_array($resource)) {
            return $resource;
        }

        if (is_object($resource)) {
            if (method_exists($resource, 'toArray')) {
                return (array) $resource->toArray();
            }
            return get_object_vars($resource);
        }

        return [];
    }
}
