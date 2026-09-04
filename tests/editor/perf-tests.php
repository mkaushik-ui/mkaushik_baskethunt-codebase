<?php
declare(strict_types=1);

namespace SOI\Tests\Editor;

use SOI\Core\Content\Document;
use SOI\Core\Content\DocumentRenderer;

/**
 * Task A4-T13: Large Document Performance Benchmarks Suite.
 * Executes performance benchmarks on 500+ block documents to assert
 * sub-100ms parse and render times.
 */
final class PerfTests
{
    public static function run(): array
    {
        $passed = 0;
        $failed = 0;
        $messages = [];

        $assert = static function (bool $condition, string $label) use (&$passed, &$failed, &$messages): void {
            if ($condition) {
                $passed++;
                $messages[] = "  PASS  [Perf] {$label}";
            } else {
                $failed++;
                $messages[] = "  FAIL  [Perf] {$label}";
            }
        };

        // 1. Generate a 500+ Block Document Payload
        $blockPrototypes = [
            ['type' => 'paragraph', 'data' => ['text' => 'Benchmark paragraph content with sample text.']],
            ['type' => 'heading', 'data' => ['text' => 'Benchmark Section Heading', 'level' => 2]],
            ['type' => 'callout', 'data' => ['tone' => 'info', 'title' => 'Notice', 'text' => 'Callout payload.']],
            ['type' => 'code', 'data' => ['code' => 'function bench() { return 42; }', 'language' => 'javascript']],
            ['type' => 'table', 'data' => ['withHeadings' => true, 'content' => [['Metric', 'Value'], ['Latency', '5ms']]]],
            ['type' => 'steps', 'data' => ['items' => [['title' => 'Step A', 'content' => 'Content A']]]],
            ['type' => 'accordion', 'data' => ['items' => [['title' => 'Accordion A', 'content' => 'Body A']]]],
            ['type' => 'faq', 'data' => ['items' => [['question' => 'Q?', 'answer' => 'A']]]],
            ['type' => 'grid', 'data' => ['columns' => 3, 'items' => [['content' => 'G1'], ['content' => 'G2'], ['content' => 'G3']]]],
            ['type' => 'cards', 'data' => ['items' => [['title' => 'Card A', 'description' => 'Card Desc A']]]],
            ['type' => 'apiEndpoint', 'data' => ['method' => 'GET', 'endpoint' => '/api/v1/status', 'title' => 'Status API']],
            ['type' => 'keyValues', 'data' => ['title' => 'Benchmark Metrics', 'items' => [['key' => 'Rate', 'value' => '1000/s']]]],
            ['type' => 'kbd', 'data' => ['keys' => ['Ctrl', 'Shift', 'R'], 'description' => 'Hard refresh']],
        ];

        $blocks = [];
        $totalTarget = 520;
        for ($i = 0; $i < $totalTarget; $i++) {
            $proto = $blockPrototypes[$i % count($blockPrototypes)];
            $blocks[] = [
                'id' => 'blk_perf_' . $i,
                'type' => $proto['type'],
                'data' => $proto['data'],
            ];
        }

        $rawPayload = [
            'schemaVersion' => 1,
            'blocks' => $blocks,
        ];

        $jsonString = json_encode($rawPayload, JSON_UNESCAPED_SLASHES);
        $memBefore = memory_get_usage(true);

        // 2. Parse Benchmark
        $parseStart = microtime(true);
        $parsedDoc = Document::parse($jsonString);
        $parseEnd = microtime(true);
        $parseTimeMs = ($parseEnd - $parseStart) * 1000.0;

        $assert(count($parsedDoc['blocks']) === 520, "Generated and parsed 520 blocks successfully");
        $assert($parseTimeMs < 50.0, sprintf("Document::parse() for 520 blocks took %.2f ms (target < 50.0 ms)", $parseTimeMs));

        // 3. Render Benchmark
        $renderStart = microtime(true);
        $renderedHtml = DocumentRenderer::render($parsedDoc, false);
        $renderEnd = microtime(true);
        $renderTimeMs = ($renderEnd - $renderStart) * 1000.0;

        $assert(strlen($renderedHtml) > 50000, sprintf("DocumentRenderer output length is %d bytes", strlen($renderedHtml)));
        $assert($renderTimeMs < 60.0, sprintf("DocumentRenderer::render() for 520 blocks took %.2f ms (target < 60.0 ms)", $renderTimeMs));

        // 4. Combined Total Execution Time Benchmark (< 100ms)
        $totalTimeMs = $parseTimeMs + $renderTimeMs;
        $assert($totalTimeMs < 100.0, sprintf("Combined parse + render time for 520 blocks took %.2f ms (MUST be < 100.0 ms)", $totalTimeMs));

        // 5. Memory Footprint Allocation Check
        $memAfter = memory_get_usage(true);
        $memUsedMb = ($memAfter - $memBefore) / (1024 * 1024);
        $assert($memUsedMb < 15.0, sprintf("Memory footprint allocation was %.2f MB (target < 15.0 MB)", $memUsedMb));

        return [
            'passed' => $passed,
            'failed' => $failed,
            'messages' => $messages,
        ];
    }
}
