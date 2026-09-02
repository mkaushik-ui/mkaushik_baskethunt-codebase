<?php
declare(strict_types=1);

namespace SOI\Core\Blocks;

/**
 * Block transformation utilities.
 *
 * Task A2-T21:
 * Convert compatible blocks between block types while preserving
 * their meaningful content.
 */
final class Transform
{
    /**
     * Transform a block from one compatible type to another.
     *
     * Currently supported:
     * - paragraph -> heading
     *
     * @param array<string, mixed> $block
     * @param string $targetType
     * @return array<string, mixed>
     */
    public static function transform(
        array $block,
        string $targetType
    ): array {
        $sourceType = (string) ($block['type'] ?? '');

        if ($sourceType === 'paragraph' && $targetType === 'heading') {
            return self::paragraphToHeading($block);
        }

        /*
         * Unsupported transformations return the original block
         * unchanged rather than silently corrupting its data.
         */
        return $block;
    }

    /**
     * Convert a Paragraph block to a Heading block.
     *
     * The paragraph's text is preserved and a valid default
     * heading level is supplied.
     *
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private static function paragraphToHeading(
        array $block
    ): array {
        $data = is_array($block['data'] ?? null)
            ? $block['data']
            : [];

        return array_merge(
            $block,
            [
                'type' => 'heading',
                'data' => array_merge(
                    $data,
                    [
                        'text' => (string) ($data['text'] ?? ''),
                        'level' => 2,
                    ]
                ),
            ]
        );
    }
}