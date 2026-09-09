<?php
/**
 * Knowledge Space Editor Integration Suite
 * Verifies all Knowledge Spaces features integrated in the Structured Editor (KS-01 through KS-04).
 */
declare(strict_types=1);

define('SOI_ROOT', dirname(__DIR__, 2));
require_once SOI_ROOT . '/core/helpers.php';

spl_autoload_register(static function (string $class): void {
    $file = SOI_ROOT . '/core/' . str_replace(['SOI\\Core\\', '\\'], ['', '/'], $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use SOI\Core\Database;
use SOI\Core\Spaces\SpaceSchema;
use SOI\Core\Spaces\KnowledgeSpaceService;
use SOI\Core\Spaces\TaxonomyService;
use SOI\Core\Content\ContentService;
use SOI\Core\Content\ContentStore;
use SOI\Core\Content\EditorSchema;

$passed = 0;
$failed = 0;

function assert_check(bool $cond, string $msg): void {
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  PASS  {$msg}\n";
    } else {
        $failed++;
        echo "  FAIL  {$msg}\n";
    }
}

echo "========================================================\n";
echo "Knowledge Space Editor Integration Test Suite (KS-01 to KS-04)\n";
echo "========================================================\n\n";

// 1. Setup DB
$pdo = new \PDO('sqlite::memory:');
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
Database::setPdo($pdo);

$pdo->exec("CREATE TABLE IF NOT EXISTS `soi_pages` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `title` TEXT,
    `slug` TEXT,
    `content` TEXT,
    `excerpt` TEXT,
    `meta_title` TEXT,
    `meta_desc` TEXT,
    `status` TEXT DEFAULT 'draft',
    `body_json` TEXT,
    `editor_format` TEXT DEFAULT 'structured',
    `schema_version` INTEGER DEFAULT 1,
    `author_id` INTEGER NULL,
    `space_id` INTEGER NULL,
    `section_id` INTEGER NULL,
    `doc_version` TEXT NULL,
    `updated_at` TEXT DEFAULT CURRENT_TIMESTAMP,
    `created_at` TEXT DEFAULT CURRENT_TIMESTAMP
);");

SpaceSchema::ensure($pdo);
$schemaRef = new \ReflectionProperty(EditorSchema::class, 'ensured');
$schemaRef->setValue(null, true);

$ksService = new KnowledgeSpaceService($pdo);
$taxService = new TaxonomyService($pdo);

// 2. Create canonical spaces
$spaceId1 = $ksService->createSpace([
    'title' => 'API Reference',
    'slug' => 'api-reference',
    'type' => 'tech',
    'status' => 'published',
    'visibility' => 'public'
]);
assert_check($spaceId1 > 0, 'Tech space created successfully (ID: ' . $spaceId1 . ')');

$spaceId2 = $ksService->createSpace([
    'title' => 'User Guides',
    'slug' => 'user-guides',
    'type' => 'generaldocs',
    'status' => 'published',
    'visibility' => 'public'
]);
assert_check($spaceId2 > 0, 'Generaldocs space created successfully (ID: ' . $spaceId2 . ')');

$spaces = $ksService->listSpaces();
assert_check(count($spaces) >= 2, 'KnowledgeSpaceService lists available spaces');

// Create sections for tech space
$secId = $taxService->createSection([
    'space_id' => $spaceId1,
    'title' => 'Authentication Guides',
    'slug' => 'auth-guides',
]);
assert_check($secId > 0, 'Section created in tech space');

$sections = $taxService->getSectionsBySpace($spaceId1);
assert_check(count($sections) > 0, 'TaxonomyService retrieves sections for space');

// 3. Save Document with Space & Taxonomy attributes
$savePayload = [
    'entity' => 'page',
    'id' => 0,
    'title' => 'OAuth 2.0 Integration',
    'slug' => 'oauth-2-integration',
    'status' => 'published',
    'mode' => 'structured',
    'space_id' => $spaceId1,
    'section_id' => $secId,
    'doc_version' => 'v2.1.0',
    'document' => [
        'blocks' => [
            ['type' => 'paragraph', 'data' => ['text' => 'Welcome to OAuth 2.0 guide.']]
        ]
    ]
];

$res = ContentService::saveDocument($savePayload, false);
assert_check(($res['ok'] ?? false) === true, 'ContentService saves document with Space metadata');

$savedId = (int) $res['id'];
$savedPage = ContentStore::find('page', $savedId);
assert_check((int) ($savedPage['space_id'] ?? 0) === $spaceId1, 'Saved page retains space_id in database');
assert_check((int) ($savedPage['section_id'] ?? 0) === $secId, 'Saved page retains section_id in database');
assert_check(($savedPage['doc_version'] ?? '') === 'v2.1.0', 'Saved page retains doc_version in database');

// 4. Test UI Markup in Structured Editor Partial
$partialHtml = file_get_contents(SOI_ROOT . '/admin/partials/structured-editor.php') ?: '';
assert_check(str_contains($partialHtml, 'id="kc-space-taxonomy-card"'), 'Document tab contains Space & Taxonomy card (#kc-space-taxonomy-card)');
assert_check(str_contains($partialHtml, 'id="kc-doc-space"'), 'Document tab contains Knowledge Space selector (#kc-doc-space)');
assert_check(str_contains($partialHtml, 'id="kc-doc-section"'), 'Document tab contains Section Hierarchy selector (#kc-doc-section)');
assert_check(str_contains($partialHtml, 'id="kc-doc-version"'), 'Document tab contains Tech Version input (#kc-doc-version)');
assert_check(str_contains($partialHtml, 'id="kc-space-type-badge"'), 'Document tab contains Space Type Badge (#kc-space-type-badge)');

// 5. Test Inspector Shell JS Client Wiring
$inspectorJs = file_get_contents(SOI_ROOT . '/admin/assets/editor/ui/inspector-shell.js') ?: '';
assert_check(str_contains($inspectorJs, 'bindSpaceControls'), 'Inspector shell binds space controls (bindSpaceControls)');
assert_check(str_contains($inspectorJs, 'fetchAndPopulateSections'), 'Inspector shell includes dynamic section fetcher (fetchAndPopulateSections)');
assert_check(str_contains($inspectorJs, 'updateVersionWrap'), 'Inspector shell toggles version field visibility (updateVersionWrap)');
assert_check(str_contains($inspectorJs, 'updatePermalink'), 'Inspector shell updates permalink dynamically (updatePermalink)');

// 6. Test Node Mock Server Markup
$mockServerJs = file_get_contents(SOI_ROOT . '/tests/editor/server.mjs') ?: '';
assert_check(str_contains($mockServerJs, 'id="kc-space-taxonomy-card"'), 'Mock dev server template includes #kc-space-taxonomy-card');

echo "\nSummary: {$passed} passed, {$failed} failed.\n";
if ($failed > 0) {
    exit(1);
}
