<?php
declare(strict_types=1);

namespace SOI\Core\Services\Autosave;

use SOI\Core\Content\Html;

/**
 * Task A4-T06: Document Status Lifecycle Manager (StatusWorkflow).
 *
 * Manages document status lifecycle (Draft, Published, Private, In Review, Approved, Archived),
 * enforces public site visibility rules, handles state transitions, and guarantees
 * Save vs Publish invariant compliance.
 *
 * Acceptance Criteria: Document set to Draft is hidden from unauthenticated public site requests.
 */
class StatusWorkflow
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_PRIVATE = 'private';
    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_ARCHIVED = 'archived';

    /** @var array<string, string> Human-readable labels for document lifecycle statuses */
    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_PUBLISHED => 'Published',
        self::STATUS_PRIVATE => 'Private',
        self::STATUS_IN_REVIEW => 'In Review',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_ARCHIVED => 'Archived',
    ];

    /** @var array<string, string> UI CSS Badge mapping for status indicators */
    public const STATUS_BADGES = [
        self::STATUS_DRAFT => 'badge-warning',
        self::STATUS_PUBLISHED => 'badge-success',
        self::STATUS_PRIVATE => 'badge-info',
        self::STATUS_IN_REVIEW => 'badge-primary',
        self::STATUS_APPROVED => 'badge-accent',
        self::STATUS_ARCHIVED => 'badge-secondary',
    ];

    /** @var array<string, array<int, string>> State machine defining valid status transitions */
    private const ALLOWED_TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_PRIVATE, self::STATUS_IN_REVIEW],
        self::STATUS_IN_REVIEW => [self::STATUS_DRAFT, self::STATUS_APPROVED, self::STATUS_PUBLISHED, self::STATUS_PRIVATE],
        self::STATUS_APPROVED => [self::STATUS_PUBLISHED, self::STATUS_DRAFT, self::STATUS_PRIVATE],
        self::STATUS_PUBLISHED => [self::STATUS_DRAFT, self::STATUS_PRIVATE, self::STATUS_ARCHIVED, self::STATUS_PUBLISHED],
        self::STATUS_PRIVATE => [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED, self::STATUS_PRIVATE],
        self::STATUS_ARCHIVED => [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_PRIVATE],
    ];

    /**
     * Determines whether a document is visible on the public site based on status and auth state.
     *
     * ACCEPTANCE CRITERIA ENFORCEMENT:
     * - Document set to Draft is STRICTLY HIDDEN (returns false) for unauthenticated public site requests.
     * - Document set to Private is STRICTLY HIDDEN (returns false) for unauthenticated public site requests.
     * - Document set to Published is VISIBLE (returns true) for all visitors.
     *
     * @param string $status
     * @param bool $isAuthenticated
     * @param array<string, mixed>|null $user
     * @return bool
     */
    public function isPubliclyVisible(string $status, bool $isAuthenticated = false, ?array $user = null): bool
    {
        $status = $this->normalizeStatus($status);

        // Published documents are universally visible on public site
        if ($status === self::STATUS_PUBLISHED) {
            return true;
        }

        // Unauthenticated requests MUST NOT view Draft, Private, In Review, Approved, or Archived documents
        if (!$isAuthenticated) {
            return false;
        }

        // Authenticated users can view internal drafts/privates if logged in
        if (in_array($status, [self::STATUS_DRAFT, self::STATUS_PRIVATE, self::STATUS_IN_REVIEW, self::STATUS_APPROVED], true)) {
            return true;
        }

        return false;
    }

    /**
     * Filters an array of documents to include only those visible under the specified authentication state.
     *
     * @param array<int, array<string, mixed>> $documents
     * @param bool $isAuthenticated
     * @return array<int, array<string, mixed>>
     */
    public function filterPublicDocuments(array $documents, bool $isAuthenticated = false): array
    {
        $visible = [];
        foreach ($documents as $doc) {
            $status = (string) ($doc['status'] ?? self::STATUS_DRAFT);
            if ($this->isPubliclyVisible($status, $isAuthenticated, $doc['author'] ?? null)) {
                $visible[] = $doc;
            }
        }
        return array_values($visible);
    }

    /**
     * Returns an array of statuses allowed for querying in database calls based on authentication state.
     *
     * @param bool $isAuthenticated
     * @return array<int, string>
     */
    public function getPublicAllowedStatuses(bool $isAuthenticated = false): array
    {
        if (!$isAuthenticated) {
            return [self::STATUS_PUBLISHED];
        }

        return [
            self::STATUS_PUBLISHED,
            self::STATUS_DRAFT,
            self::STATUS_PRIVATE,
            self::STATUS_IN_REVIEW,
            self::STATUS_APPROVED,
        ];
    }

    /**
     * Check if transitioning from currentStatus to newStatus is valid.
     *
     * @param string $currentStatus
     * @param string $newStatus
     * @return bool
     */
    public function canTransition(string $currentStatus, string $newStatus): bool
    {
        $current = $this->normalizeStatus($currentStatus);
        $target = $this->normalizeStatus($newStatus);

        $allowed = self::ALLOWED_TRANSITIONS[$current] ?? [self::STATUS_DRAFT, self::STATUS_PUBLISHED];
        return in_array($target, $allowed, true);
    }

    /**
     * Execute a status transition and return metadata.
     *
     * @param string $currentStatus
     * @param string $newStatus
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function transitionStatus(string $currentStatus, string $newStatus, array $context = []): array
    {
        $current = $this->normalizeStatus($currentStatus);
        $target = $this->normalizeStatus($newStatus);

        if (!$this->canTransition($current, $target)) {
            return [
                'ok' => false,
                'error' => sprintf('Invalid status transition from "%s" to "%s".', $current, $target),
                'current_status' => $current,
                'target_status' => $target,
            ];
        }

        $now = date('c');
        $publishedAt = ($target === self::STATUS_PUBLISHED)
            ? ($context['published_at'] ?? $now)
            : ($current === self::STATUS_PUBLISHED ? ($context['published_at'] ?? $now) : null);

        return [
            'ok' => true,
            'status' => $target,
            'previous_status' => $current,
            'status_label' => $this->getStatusLabel($target),
            'status_badge' => $this->getStatusBadgeClass($target),
            'published_at' => $publishedAt,
            'transition_time' => $now,
            'is_publicly_visible' => $this->isPubliclyVisible($target, false),
        ];
    }

    /**
     * Process an incoming background status workflow payload (Save vs Publish invariant compliance).
     *
     * @param array<string, mixed> $payload
     * @param string $currentDbStatus
     * @param bool $isAuthenticated
     * @return array<string, mixed>
     */
    public function processStatusWorkflow(array $payload, string $currentDbStatus = self::STATUS_DRAFT, bool $isAuthenticated = false): array
    {
        $documentId = Html::plainText((string) ($payload['document_id'] ?? $payload['id'] ?? ''));
        $action = strtolower(trim((string) ($payload['action'] ?? 'save')));
        $requestedStatus = strtolower(trim((string) ($payload['status'] ?? '')));

        // Map UI actions to target status
        $targetStatus = match ($action) {
            'publish' => self::STATUS_PUBLISHED,
            'unpublish', 'draft', 'save_draft' => self::STATUS_DRAFT,
            'private', 'make_private' => self::STATUS_PRIVATE,
            'submit_review' => self::STATUS_IN_REVIEW,
            'approve' => self::STATUS_APPROVED,
            'archive' => self::STATUS_ARCHIVED,
            default => ($requestedStatus !== '' ? $this->normalizeStatus($requestedStatus) : $this->normalizeStatus($currentDbStatus)),
        };

        $currentStatus = $this->normalizeStatus($currentDbStatus);

        // Perform state transition check
        $transitionResult = $this->transitionStatus($currentStatus, $targetStatus, $payload);
        if (!$transitionResult['ok']) {
            return [
                'success' => false,
                'error' => $transitionResult['error'],
                'document_id' => $documentId,
                'status' => $currentStatus,
                'status_label' => $this->getStatusLabel($currentStatus),
                'status_badge' => $this->getStatusBadgeClass($currentStatus),
                'is_publicly_visible' => $this->isPubliclyVisible($currentStatus, $isAuthenticated),
            ];
        }

        $isPublic = $this->isPubliclyVisible($targetStatus, false); // Check public visitor access

        return [
            'success' => true,
            'action' => $action,
            'document_id' => $documentId,
            'status' => $targetStatus,
            'previous_status' => $currentStatus,
            'status_label' => $this->getStatusLabel($targetStatus),
            'status_badge' => $this->getStatusBadgeClass($targetStatus),
            'published_at' => $transitionResult['published_at'],
            'is_publicly_visible' => $isPublic,
            'visibility_notice' => $isPublic
                ? 'Document is Published and visible on the public site.'
                : 'Document is ' . $this->getStatusLabel($targetStatus) . ' and HIDDEN from unauthenticated public site requests.',
            'updated_at' => date('c'),
        ];
    }

    /**
     * Normalizes an arbitrary status string to a valid status identifier.
     *
     * @param string $status
     * @param string $default
     * @return string
     */
    public function normalizeStatus(string $status, string $default = self::STATUS_DRAFT): string
    {
        $status = strtolower(trim($status));
        return isset(self::STATUS_LABELS[$status]) ? $status : $default;
    }

    /**
     * Get the CSS badge class for a given status.
     *
     * @param string $status
     * @return string
     */
    public function getStatusBadgeClass(string $status): string
    {
        $status = $this->normalizeStatus($status);
        return self::STATUS_BADGES[$status] ?? 'badge-secondary';
    }

    /**
     * Get human-readable label for a status.
     *
     * @param string $status
     * @return string
     */
    public function getStatusLabel(string $status): string
    {
        $status = $this->normalizeStatus($status);
        return self::STATUS_LABELS[$status] ?? 'Draft';
    }

    /**
     * Get all supported status choices.
     *
     * @return array<string, string>
     */
    public function getAllStatuses(): array
    {
        return self::STATUS_LABELS;
    }
}
