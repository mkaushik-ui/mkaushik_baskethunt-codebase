<?php
declare(strict_types=1);

namespace SOI\Core\Spaces\Audience;

/**
 * AudiencePolicyServiceInterface
 *
 * Single Source of Truth contract for Knowledge Center authorization, visibility,
 * and audience policy evaluation.
 */
interface AudiencePolicyServiceInterface
{
    /**
     * Check if a subject has read/view access to a knowledge space.
     *
     * @param array<string, mixed>|int $space Space record array or Space ID.
     * @param AudienceSubjectContext|null $subject Subject context (defaults to current session).
     * @return bool True if permitted, false if denied.
     */
    public function canAccessSpace($space, ?AudienceSubjectContext $subject = null): bool;

    /**
     * Check if a subject has read/view access to a specific document.
     *
     * @param array<string, mixed>|int $document Document record array or Document ID.
     * @param AudienceSubjectContext|null $subject Subject context (defaults to current session).
     * @return bool True if permitted, false if denied.
     */
    public function canAccessDocument($document, ?AudienceSubjectContext $subject = null): bool;

    /**
     * Check if a subject has management/administrative rights over a knowledge space
     * (e.g. delegated Space Owners or global administrators).
     *
     * @param array<string, mixed>|int $space Space record array or Space ID.
     * @param AudienceSubjectContext|null $subject Subject context (defaults to current session).
     * @return bool True if permitted, false if denied.
     */
    public function canManageSpace($space, ?AudienceSubjectContext $subject = null): bool;

    /**
     * Filter a list of space records, removing spaces the subject is not allowed to view.
     *
     * @param array<int, array<string, mixed>> $spaces List of space records.
     * @param AudienceSubjectContext|null $subject Subject context.
     * @return array<int, array<string, mixed>> Filtered list.
     */
    public function filterAccessibleSpaces(array $spaces, ?AudienceSubjectContext $subject = null): array;

    /**
     * Filter a hierarchical navigation tree, removing sections and document leaves
     * that the subject cannot access.
     *
     * @param array<string, mixed> $navTree Navigation tree structure.
     * @param AudienceSubjectContext|null $subject Subject context.
     * @return array<string, mixed> Sanitized navigation tree.
     */
    public function filterNavigationTree(array $navTree, ?AudienceSubjectContext $subject = null): array;

    /**
     * Validate and normalize an audience policy data array or JSON string.
     *
     * @param mixed $policy Raw policy input.
     * @return array<string, mixed> Normalized policy structure.
     * @throws \InvalidArgumentException If policy schema is malformed.
     */
    public function normalizePolicy($policy): array;
}
