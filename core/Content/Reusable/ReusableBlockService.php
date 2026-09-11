<?php
declare(strict_types=1);

namespace SOI\Core\Content\Reusable;

use SOI\Core\Content\Document;

/**
 * Task T7: Reusable Block Service & Circular Recursion Guard (Skeleton).
 */
class ReusableBlockService
{
    /**
     * Resolve reusable block reference while guarding against circular loops and permission checks.
     *
     * @param int $reusableId
     * @param list<int> $callStack Stack of active reusable IDs in current render pass
     * @param \SOI\Core\Spaces\Audience\AudienceSubjectContext|null $subject
     * @return array<string, mixed>
     */
    public function resolve(int $reusableId, array &$callStack = [], ?\SOI\Core\Spaces\Audience\AudienceSubjectContext $subject = null): array
    {
        if (in_array($reusableId, $callStack, true)) {
            // Circular loop detected! Terminate safely
            return [
                'type' => 'callout',
                'data' => [
                    'tone' => 'warning',
                    'title' => 'Circular Reference Detected',
                    'text' => "Reusable block #{$reusableId} references itself circularly.",
                ],
            ];
        }

        $callStack[] = $reusableId;

        if (class_exists(\SOI\Core\Reusable\ReusableManager::class)) {
            $block = \SOI\Core\Reusable\ReusableManager::get($reusableId);
            if ($block && !empty($block['audience_policy'])) {
                $subject = $subject ?? \SOI\Core\Spaces\Audience\AudienceSubjectContext::fromCurrentSession();
                $policyService = \SOI\Core\Spaces\Audience\AudiencePolicyService::instance();
                if (!$policyService->canAccessSpace($block, $subject)) {
                    return [
                        'type' => 'callout',
                        'data' => [
                            'tone' => 'warning',
                            'title' => 'Access Restricted',
                            'text' => 'You do not have permission to view this reusable block content.',
                        ],
                    ];
                }
            }
            if ($block && isset($block['content']) && is_array($block['content'])) {
                return $block['content'];
            }
        }

        return Document::empty();
    }
}
