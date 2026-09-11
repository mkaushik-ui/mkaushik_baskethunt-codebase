<?php
declare(strict_types=1);

namespace SOI\Core\Spaces\Audience;

/**
 * AudiencePolicyServiceInterface
 *
 * Contract governing audience resolution, space authorization, document authorization,
 * space management permissions, collection filtering, navigation tree pruning, and policy validation
 * in SOI Knowledge Center.
 */
interface AudiencePolicyServiceInterface
{
    /**
     * Determine whether the given subject is authorized to view / read the specified Space.
     *
     * @param array<string, mixed>|object $space Space record or entity
     * @param AudienceSubjectContext|null $subject Requesting subject (defaults to current session if null)
     * @return bool True if authorized, false otherwise (deny-by-default)
     */
    public function canAccessSpace(array|object $space, ?AudienceSubjectContext $subject = null): bool;

    /**
     * Determine whether the given subject is authorized to view / read a specific Document
     * within a Space (considering both space-level and document-level audience rules).
     *
     * @param array<string, mixed>|object $document Document record or entity
     * @param array<string, mixed>|object $space Space record or entity
     * @param AudienceSubjectContext|null $subject Requesting subject (defaults to current session if null)
     * @return bool True if authorized, false otherwise (deny-by-default)
     */
    public function canAccessDocument(array|object $document, array|object $space, ?AudienceSubjectContext $subject = null): bool;

    /**
     * Determine whether the given subject is authorized to manage, edit, configure, or administer the Space.
     *
     * @param array<string, mixed>|object $space Space record or entity
     * @param AudienceSubjectContext|null $subject Requesting subject (defaults to current session if null)
     * @return bool True if authorized, false otherwise
     */
    public function canManageSpace(array|object $space, ?AudienceSubjectContext $subject = null): bool;

    /**
     * Filter a collection of Space records, returning only those accessible to the subject.
     *
     * @param list<array<string, mixed>|object>|array<mixed> $spaces List of space records
     * @param AudienceSubjectContext|null $subject Requesting subject (defaults to current session if null)
     * @return list<array<string, mixed>|object> Filtered list of accessible space records
     */
    public function filterAccessibleSpaces(array $spaces, ?AudienceSubjectContext $subject = null): array;

    /**
     * Filter a hierarchical navigation tree, pruning out inaccessible nodes, sections, or documents.
     *
     * @param list<array<string, mixed>>|array<mixed> $nodes Hierarchical navigation nodes
     * @param array<string, mixed>|object $space Space record or entity context
     * @param AudienceSubjectContext|null $subject Requesting subject (defaults to current session if null)
     * @return list<array<string, mixed>> Filtered tree with inaccessible nodes removed
     */
    public function filterNavigationTree(array $nodes, array|object $space, ?AudienceSubjectContext $subject = null): array;

    /**
     * Validate an audience policy schema structure and syntax.
     *
     * @param array<string, mixed>|string|null $policy Raw JSON string, array, or null
     * @return array{valid: bool, errors: list<string>, normalized: array<string, mixed>|null} Validation result
     */
    public function validatePolicySchema(array|string|null $policy): array;

    /**
     * Normalize an audience policy into canonical schema format with default fallbacks.
     *
     * @param array<string, mixed>|string|null $policy Raw JSON string, array, or null
     * @param string|null $visibility Optional visibility constraint
     * @return array<string, mixed> Normalized policy array
     */
    public function normalizePolicy(array|string|null $policy, ?string $visibility = null): array;

    /**
     * Get default canonical audience policy for a given visibility level.
     *
     * @param string|null $visibility
     * @return array<string, mixed>
     */
    public function getDefaultPolicy(?string $visibility = null): array;

    /**
     * Generate an audit explanation detailing why access was granted or denied for a resource.
     *
     * @param array<string, mixed>|object $resource Space or Document record
     * @param AudienceSubjectContext|null $subject Requesting subject (defaults to current session if null)
     * @param string $action 'view'|'read'|'manage'|'edit'
     * @return array{allowed: bool, reason: string, rule_matched: string|null, subject: array<string, mixed>}
     */
    public function explainAccess(array|object $resource, ?AudienceSubjectContext $subject = null, string $action = 'view'): array;
}
