<?php
/**
 * Public Reader Experiences (Workstream C / Tasks RC-01 through RC-06)
 * Automated Contract & Regression Verification Suite.
 *
 * Location: tests/spaces/reader-tests.php
 * Run: php tests/spaces/reader-tests.php
 *
 * Single Source of Truth verification:
 * Database (soi_spaces, soi_space_sections) -> Services -> Front Controller -> DocumentRenderer -> Reader Shell
 *
 * @author Team Lead & QA
 */
declare(strict_types=1);

if (!defined('SOI_ROOT')) {
    define('SOI_ROOT', dirname(__DIR__, 2));
}
require_once SOI_ROOT . '/core/helpers.php';

spl_autoload_register(static function (string $class): void {
    $file = SOI_ROOT . '/core/' . str_replace(['SOI\\Core\\', '\\'], ['', '/'], $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use SOI\Core\Database;
use SOI\Core\Content\DocumentRenderer;
use SOI\Core\Content\BlockRegistry;
use SOI\Core\Spaces\KnowledgeSpaceService;
use SOI\Core\Spaces\SpaceDocumentService;
use SOI\Core\Spaces\SpaceSchema;
use SOI\Core\Spaces\TaxonomyService;
use SOI\Core\App;

$failed = 0;
$passed = 0;

function assert_true(bool $cond, string $message): void
{
    global $failed, $passed;
    if ($cond) {
        $passed++;
        echo "  PASS  {$message}\n";
        return;
    }
    $failed++;
    echo "  FAIL  {$message}\n";
}

function assert_false(bool $cond, string $message): void
{
    assert_true(!$cond, $message);
}

function assert_contains(string $haystack, string $needle, string $message): void
{
    assert_true(str_contains($haystack, $needle), $message);
}

echo "========================================================\n";
echo "Workstream C: Public Reader Experiences Test Suite\n";
echo "========================================================\n";

// 1. Setup in-memory SQLite database
echo "\n[1. In-Memory Database & Schema Setup]\n";
$sqlitePdo = new \PDO('sqlite::memory:');
$sqlitePdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
Database::setPdo($sqlitePdo);
assert_true(Database::isConnected(), 'Database connection established for reader tests');

$sqlitePdo->exec("CREATE TABLE IF NOT EXISTS `soi_pages` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `title` TEXT,
    `slug` TEXT,
    `content` TEXT,
    `body_json` TEXT,
    `editor_format` TEXT DEFAULT 'json',
    `layout_preset` TEXT DEFAULT 'standard',
    `space_id` INTEGER NULL,
    `section_id` INTEGER NULL,
    `doc_version` TEXT NULL,
    `status` TEXT DEFAULT 'published',
    `created_at` TEXT DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TEXT DEFAULT CURRENT_TIMESTAMP
);");

$sqlitePdo->exec("CREATE TABLE IF NOT EXISTS `soi_posts` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `title` TEXT,
    `slug` TEXT,
    `content` TEXT,
    `body_json` TEXT,
    `editor_format` TEXT DEFAULT 'json',
    `layout_preset` TEXT DEFAULT 'standard',
    `space_id` INTEGER NULL,
    `section_id` INTEGER NULL,
    `doc_version` TEXT NULL,
    `status` TEXT DEFAULT 'published',
    `created_at` TEXT DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TEXT DEFAULT CURRENT_TIMESTAMP
);");

SpaceSchema::ensure($sqlitePdo);
$docService = new SpaceDocumentService($sqlitePdo);
$docService->seedInitialSpaces(true);

// 2. Canonical Routing Tests (RC-04 & KS-06)
echo "\n[2. Dynamic Route Resolution & Security]\n";
$app = new App();
$themeDir = SOI_ROOT . '/themes/default';

// Test canonical routes
[$templateDocs, $varsDocs] = $app->resolveReaderShellRoute('docs', $themeDir);
assert_true(str_ends_with($templateDocs, 'reader-shell.php'), 'Route /docs resolves to reader-shell.php');
assert_true(isset($varsDocs['space']) && $varsDocs['space']['slug'] === 'general-docs', 'Route /docs resolves general-docs space');

// Test injection and traversal resistance
[$templateInj, $varsInj] = $app->resolveReaderShellRoute("tech/../secret/../../etc", $themeDir);
assert_true(str_ends_with($templateInj, '404.php'), 'Path traversal rejected with 404 template');

// 3. DocumentRenderer & Block Visual Parity (RC-01)
echo "\n[3. Structured Block Rendering & Canonical Parity (RC-01)]\n";
$testDocument = [
    'schemaVersion' => 1,
    'blocks' => [
        [
            'id' => 'callout-1',
            'type' => 'callout',
            'data' => [
                'type' => 'tip',
                'title' => 'Authentication Tip',
                'text' => 'Always pass your Bearer token in the Authorization header.'
            ]
        ],
        [
            'id' => 'api-1',
            'type' => 'apiEndpoint',
            'data' => [
                'method' => 'GET',
                'endpoint' => '/api/v1/spaces',
                'auth' => 'Bearer Token',
                'title' => 'List Spaces',
                'description' => 'Retrieve list of all active spaces.'
            ]
        ],
        [
            'id' => 'steps-1',
            'type' => 'steps',
            'data' => [
                'items' => [
                    ['title' => 'Install CLI', 'text' => 'Run npm install -g @soi/cli'],
                    ['title' => 'Authenticate', 'text' => 'Run agy login']
                ]
            ]
        ]
    ]
];

$renderedHtml = DocumentRenderer::render($testDocument, false);
assert_true(str_contains($renderedHtml, 'kc-callout') || str_contains($renderedHtml, 'kc-block-callout'), 'Rendered HTML contains kc-callout class');
assert_contains($renderedHtml, 'kc-block-api', 'Rendered HTML contains kc-block-api class');
assert_contains($renderedHtml, 'kc-block-steps', 'Rendered HTML contains kc-block-steps class');

// 4. Table of Contents & Headings Extraction (RC-03)
echo "\n[4. Heading Extraction & Table of Contents (RC-03)]\n";
$headingDoc = [
    'schemaVersion' => 1,
    'blocks' => [
        ['id' => 'h-1', 'type' => 'heading', 'data' => ['text' => 'Overview & Architecture', 'level' => 2]],
        ['id' => 'h-2', 'type' => 'heading', 'data' => ['text' => 'Setup & Installation', 'level' => 2]],
        ['id' => 'h-3', 'type' => 'heading', 'data' => ['text' => 'Configuration Keys', 'level' => 3]]
    ]
];

$headings = DocumentRenderer::extractHeadings($headingDoc);
assert_true(count($headings) === 3, 'DocumentRenderer::extractHeadings extracts all H2/H3 headings');
assert_true($headings[0]['text'] === 'Overview & Architecture', 'First heading text is preserved');
assert_true($headings[2]['level'] === 3, 'Third heading level is correctly identified as H3');
assert_true(!empty($headings[0]['id']), 'Heading has unique anchor slug');

// Test HTML fallback
$htmlSample = '<h2>Getting Started</h2><p>Intro</p><h3>Prerequisites</h3><p>Node</p>';
$htmlHeadings = DocumentRenderer::extractHeadings($htmlSample);
assert_true(count($htmlHeadings) === 2, 'extractHeadings extracts headings from raw HTML string');

// 5. Tech Space Version Resolution (RC-04)
echo "\n[5. Technical Space Version Queries (RC-04)]\n";
$sqlitePdo->exec("INSERT INTO `soi_space_tech_versions` (space_id, version_tag, version_name, is_latest, is_deprecated, release_date) 
VALUES (4, 'v1.0', 'Version 1.0', 0, 0, '2026-01-01'), (4, 'v2.0', 'Version 2.0', 1, 0, '2026-06-01');");

$versions = $docService->getSpaceVersions(4);
assert_true(count($versions) === 2, 'getSpaceVersions retrieves all versions for space');
assert_true($versions[0]['version_tag'] === 'v2.0' || $versions[1]['version_tag'] === 'v2.0', 'v2.0 version retrieved');

// 6. Template Markup & Asset Verification
echo "\n[6. Template Markup & Shared Asset Verification]\n";
$templateContent = file_get_contents(SOI_ROOT . '/templates/reader-shell.php');
assert_contains($templateContent, 'kc-blocks.css', 'reader-shell.php links to kc-blocks.css');
assert_contains($templateContent, 'kc-reader.css', 'reader-shell.php links to kc-reader.css');
assert_contains($templateContent, 'kc-public.js', 'reader-shell.php links to kc-public.js');
assert_contains($templateContent, 'kc-reader.js', 'reader-shell.php links to kc-reader.js');
assert_contains($templateContent, 'id="kcThemeToggle"', 'reader-shell.php contains Theme Toggle button');
assert_contains($templateContent, 'id="kcSidebarSearch"', 'reader-shell.php contains Sidebar Search input');
assert_contains($templateContent, 'id="kcToc"', 'reader-shell.php contains TOC rail container');

// 7. Stylesheet and Script Asset Discovery
assert_true(file_exists(SOI_ROOT . '/assets/kc-blocks.css'), 'assets/kc-blocks.css exists');
assert_true(file_exists(SOI_ROOT . '/assets/kc-reader.css'), 'assets/kc-reader.css exists');
assert_true(file_exists(SOI_ROOT . '/assets/kc-reader.js'), 'assets/kc-reader.js exists');
assert_true(file_exists(SOI_ROOT . '/assets/kc-public.js'), 'assets/kc-public.js exists');

echo "\n========================================================\n";
echo "Results: {$passed} Passed, {$failed} Failed\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}
echo "🎉 ALL WORKSTREAM C INTEGRATION TESTS PASSED CLEANLY!\n";
