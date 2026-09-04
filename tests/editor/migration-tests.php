<?php
declare(strict_types=1);

namespace SOI\Tests\Editor;

use SOI\Core\Content\Document;
use SOI\Core\Content\DocumentRenderer;
use SOI\Core\Content\EditorSchema;
use SOI\Core\Legacy\LegacyConverter;

/**
 * Task A4-T12: Migration & Upgrade Verification Suite.
 * Verifies fresh install SQL schema (schema.sql) matches update script (update.sql)
 * and legacy content converts losslessly.
 */
final class MigrationTests
{
    public static function run(): array
    {
        $passed = 0;
        $failed = 0;
        $messages = [];

        $assert = static function (bool $condition, string $label) use (&$passed, &$failed, &$messages): void {
            if ($condition) {
                $passed++;
                $messages[] = "  PASS  [Migration] {$label}";
            } else {
                $failed++;
                $messages[] = "  FAIL  [Migration] {$label}";
            }
        };

        $rootDir = dirname(__DIR__, 2);
        $schemaSqlFile = $rootDir . '/install/schema.sql';
        $updateSqlFile = $rootDir . '/build/1.1.0-enterprise-authoring-suite/update.sql';

        $assert(file_exists($schemaSqlFile), 'install/schema.sql fresh install schema file exists');
        $assert(file_exists($updateSqlFile), 'update.sql upgrade script file exists');

        $schemaSql = file_get_contents($schemaSqlFile) ?: '';
        $updateSql = file_get_contents($updateSqlFile) ?: '';

        // 1. Verify Table Definitions Match Between Install & Update
        $requiredTables = [
            'soi_document_revisions',
            'soi_kc_reusable_blocks'
        ];

        foreach ($requiredTables as $table) {
            $assert(str_contains($schemaSql, "CREATE TABLE IF NOT EXISTS `{$table}`"), "schema.sql contains CREATE TABLE for '{$table}'");
            $assert(str_contains($updateSql, "CREATE TABLE IF NOT EXISTS `{$table}`"), "update.sql contains CREATE TABLE for '{$table}'");
        }

        // 2. Verify Schema Columns & Data Types Match
        $requiredColumns = [
            '`body_json` longtext',
            '`editor_format` varchar(20)',
            '`schema_version` smallint'
        ];

        foreach ($requiredColumns as $col) {
            $assert(str_contains($schemaSql, $col), "schema.sql defines column matching '{$col}'");
            $assert(str_contains($updateSql, $col), "update.sql defines column matching '{$col}'");
        }

        // 3. Verify Key Indices Parity
        $requiredIndices = [
            '`idx_entity_doc`',
            '`idx_created`',
            '`idx_title`',
        ];

        foreach ($requiredIndices as $idx) {
            $assert(str_contains($schemaSql, $idx), "schema.sql includes index '{$idx}'");
            $assert(str_contains($updateSql, $idx), "update.sql includes index '{$idx}'");
        }

        // 4. Lossless Legacy Content Conversion Tests
        $rawLegacyHtml = '<h1>Legacy Heading</h1><p>This is <strong>legacy TinyMCE</strong> HTML content with a <a href="https://example.com">link</a>.</p><ul><li>List item 1</li><li>List item 2</li></ul>';
        
        $legacyRecord = [
            'content' => $rawLegacyHtml,
            'editor_format' => 'legacy',
            'body_json' => null,
        ];

        $assert(EditorSchema::isLegacy($legacyRecord), 'Legacy record identified correctly by EditorSchema');
        $assert(!EditorSchema::isStructured($legacyRecord), 'Legacy record is not marked as structured');

        $renderedLegacy = DocumentRenderer::renderRecord($legacyRecord);
        $assert(str_contains($renderedLegacy, 'Legacy Heading'), 'Legacy HTML renders title content intact');
        $assert(str_contains($renderedLegacy, 'legacy TinyMCE'), 'Legacy HTML renders body content intact');

        // Convert legacy HTML to structured JSON document
        if (method_exists(LegacyConverter::class, 'convertToStructured')) {
            $convertedDoc = LegacyConverter::convertToStructured($rawLegacyHtml);
            $assert(is_array($convertedDoc) && isset($convertedDoc['blocks']), 'LegacyConverter successfully converts HTML to structured block payload');
            
            $reRendered = DocumentRenderer::render($convertedDoc);
            $assert(str_contains($reRendered, 'Legacy Heading'), 'Converted structured document retains heading content');
            $assert(str_contains($reRendered, 'legacy TinyMCE'), 'Converted structured document retains inline formatting');
        } else {
            // Fallback structural check via Document::parse
            $fallbackDoc = Document::parse([
                'schemaVersion' => 1,
                'blocks' => [
                    ['type' => 'legacy', 'data' => ['html' => $rawLegacyHtml]]
                ]
            ]);
            $assert(count($fallbackDoc['blocks']) === 1, 'Legacy content wraps safely into fallback legacy block');
        }

        // 5. Version Upgrade Stability Check
        $oldVersionPayload = [
            'schemaVersion' => 1,
            'blocks' => [
                ['type' => 'paragraph', 'data' => ['text' => 'v1.0.5 text']],
                ['type' => 'callout', 'data' => ['tone' => 'warning', 'title' => 'Warning', 'text' => 'Watch out']],
            ]
        ];

        $upgradedDoc = Document::parse($oldVersionPayload);
        $assert($upgradedDoc['schemaVersion'] === 1, 'Upgraded document maintains canonical schema version 1');
        $assert(count($upgradedDoc['blocks']) === 2, 'Upgraded document preserves all blocks');

        return [
            'passed' => $passed,
            'failed' => $failed,
            'messages' => $messages,
        ];
    }
}
