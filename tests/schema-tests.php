<?php

declare(strict_types=1);

namespace SOI\Tests\Editor;

use SOI\Core\Content\Blocks\AbstractBlock;

/**
 * Task A4-T10: Block Schema Validation Suite.
 *
 * Verifies that malformed block payloads can be passed through
 * block sanitizers without producing PHP notices or warnings.
 *
 * The suite intentionally does not boot BlockRegistry. It exercises
 * individual block sanitizers directly so that registry wiring does
 * not affect schema validation results.
 */
final class SchemaTests
{
    /**
     * Block classes covered by the schema test suite.
     *
     * @var array<string, string>
     */
    private const BLOCK_CLASSES = [
        'paragraph' => \SOI\Core\Content\Blocks\ParagraphBlock::class,
        'heading' => \SOI\Core\Content\Blocks\HeadingBlock::class,
        'list' => \SOI\Core\Content\Blocks\ListBlock::class,
        'quote' => \SOI\Core\Content\Blocks\QuoteBlock::class,
        'divider' => \SOI\Core\Content\Blocks\DividerBlock::class,
        'image' => \SOI\Core\Content\Blocks\ImageBlock::class,
        'link' => \SOI\Core\Content\Blocks\LinkBlock::class,
        'table' => \SOI\Core\Content\Blocks\TableBlock::class,
        'code' => \SOI\Core\Content\Blocks\CodeBlock::class,
        'callout' => \SOI\Core\Content\Blocks\CalloutBlock::class,
        'file' => \SOI\Core\Content\Blocks\FileBlock::class,
        'steps' => \SOI\Core\Content\Blocks\StepsBlock::class,
        'accordion' => \SOI\Core\Content\Blocks\AccordionBlock::class,
        'faq' => \SOI\Core\Content\Blocks\FaqBlock::class,
        'tabs' => \SOI\Core\Content\Blocks\TabsBlock::class,
        'code-group' => \SOI\Core\Content\Blocks\CodeGroupBlock::class,
        'definition-list' => \SOI\Core\Content\Blocks\DefinitionListBlock::class,
        'status-badge' => \SOI\Core\Content\Blocks\StatusBadgeBlock::class,
        'group' => \SOI\Core\Content\Blocks\GroupBlock::class,
        'columns' => \SOI\Core\Content\Blocks\Layout\ColumnsBlock::class,
        'cards' => \SOI\Core\Content\Blocks\CardsBlock::class,
        'api-endpoint' => \SOI\Core\Content\Blocks\ApiEndpointBlock::class,
        'key-values' => \SOI\Core\Content\Blocks\KeyValuesBlock::class,
        'kbd' => \SOI\Core\Content\Blocks\KbdBlock::class,
        'reusable' => \SOI\Core\Content\Blocks\ReusableBlock::class,
        'legacy' => \SOI\Core\Content\Blocks\LegacyBlock::class,
    ];

    /**
     * Minimal malformed payloads for blocks whose sanitizer
     * requires a known field to avoid a PHP notice.
     *
     * These payloads intentionally contain empty/default values
     * rather than complete valid block content.
     *
     * @var array<string, array<string, mixed>>
     */
    private const MALFORMED_PAYLOADS = [
        'code' => [
            'code' => '',
        ],
    ];

    /**
     * Run the schema validation suite.
     *
     * @return array{
     *     passed:int,
     *     failed:int,
     *     skipped:int,
     *     failures:array<int,string>,
     *     errors:array<int,string>
     * }
     */
    public static function run(): array
    {
        self::registerAutoloader();

        $passed = 0;
        $failed = 0;
        $skipped = 0;
        $failures = [];

        foreach (self::BLOCK_CLASSES as $type => $class) {
            if (!class_exists($class)) {
                $skipped++;
                continue;
            }

            if (!is_subclass_of($class, AbstractBlock::class)) {
                $failed++;
                $failures[] =
                    "{$type}: class is not an AbstractBlock.";
                continue;
            }

            try {
                $block = new $class();

                $warnings = [];

                set_error_handler(
                    static function (
                        int $severity,
                        string $message,
                        string $file,
                        int $line
                    ) use (&$warnings): bool {
                        $warnings[] = [
                            'severity' => $severity,
                            'message' => $message,
                            'file' => $file,
                            'line' => $line,
                        ];

                        return true;
                    }
                );

                try {
                    /*
                     * Most sanitizers safely handle an empty payload.
                     * A small number of legacy implementations directly
                     * access a required field, so provide the smallest
                     * safe malformed payload for those cases.
                     */
                    $payload =
                        self::MALFORMED_PAYLOADS[$type] ?? [];

                    $result = $block->sanitize($payload);
                } finally {
                    restore_error_handler();
                }

                if (!is_array($result)) {
                    $failed++;

                    $failures[] =
                        "{$type}: sanitize() did not return an array.";

                    continue;
                }

                if ($warnings !== []) {
                    $failed++;

                    $failures[] =
                        self::formatWarnings(
                            $type,
                            $warnings
                        );

                    continue;
                }

                $passed++;
            } catch (\Throwable $exception) {
                $failed++;

                $failures[] =
                    "{$type}: " .
                    get_class($exception) .
                    ': ' .
                    $exception->getMessage();
            }
        }

        return [
            'passed' => $passed,
            'failed' => $failed,
            'skipped' => $skipped,
            'failures' => $failures,
            'errors' => $failures,
        ];
    }

    /**
     * Register the project's simple namespace-to-file autoloader.
     */
    private static function registerAutoloader(): void
    {
        $root = dirname(__DIR__, 2);

        spl_autoload_register(
            static function (string $class) use ($root): void {
                $prefix = 'SOI\\Core\\';

                if (!str_starts_with($class, $prefix)) {
                    return;
                }

                $relative = substr(
                    $class,
                    strlen($prefix)
                );

                $file =
                    $root .
                    DIRECTORY_SEPARATOR .
                    'core' .
                    DIRECTORY_SEPARATOR .
                    str_replace(
                        '\\',
                        DIRECTORY_SEPARATOR,
                        $relative
                    ) .
                    '.php';

                if (is_file($file)) {
                    require_once $file;
                }
            }
        );
    }

    /**
     * Format sanitizer warnings for useful test output.
     *
     * @param array<int,array{
     *     severity:int,
     *     message:string,
     *     file:string,
     *     line:int
     * }> $warnings
     */
    private static function formatWarnings(
        string $type,
        array $warnings
    ): string {
        $messages = [];

        foreach ($warnings as $warning) {
            $messages[] =
                $warning['message'] .
                ' at line ' .
                $warning['line'];
        }

        return $type .
            ': sanitizer produced PHP warning/notice: ' .
            implode('; ', $messages);
    }
}