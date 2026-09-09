<?php
/**
 * Knowledge Space Foundation (Workstream B: KS-01 through KS-06) Automated Verification Suite.
 * Run: php tests/spaces/run.php
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

use SOI\Core\Cache;
use SOI\Core\Database;
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
echo "Knowledge Space Foundation (Workstream B) Full Verification\n";
echo "========================================================\n";

// 1. Setup in-memory SQLite database
echo "\n[1. Database Connection & Schema Bootstrapping]\n";
$sqlitePdo = new \PDO('sqlite::memory:');
$sqlitePdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
Database::setPdo($sqlitePdo);
assert_true(Database::isConnected(), 'In-memory database connection established');

// Create test tables for pages and posts
$sqlitePdo->exec("CREATE TABLE IF NOT EXISTS `soi_pages` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `title` TEXT,
    `slug` TEXT,
    `content` TEXT,
    `space_id` INTEGER NULL,
    `section_id` INTEGER NULL,
    `doc_version` TEXT NULL,
    `status` TEXT DEFAULT 'published'
);");
$sqlitePdo->exec("CREATE TABLE IF NOT EXISTS `soi_posts` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `title` TEXT,
    `slug` TEXT,
    `content` TEXT,
    `space_id` INTEGER NULL,
    `section_id` INTEGER NULL,
    `doc_version` TEXT NULL,
    `status` TEXT DEFAULT 'published'
);");
$sqlitePdo->exec("INSERT INTO `soi_pages` (id, title, slug, content) VALUES (101, 'Design Tokens', 'design-tokens', '<p>Color palette specs</p>');");

SpaceSchema::ensure($sqlitePdo);
$tables = $sqlitePdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(\PDO::FETCH_COLUMN);
assert_true(in_array('soi_spaces', $tables, true) || in_array('soispaces', $tables, true), 'Table soi_spaces/soispaces created');
assert_true(in_array('soi_space_sections', $tables, true), 'Table soi_space_sections created');
assert_true(in_array('soi_space_documents', $tables, true), 'Table soi_space_documents created');
assert_true(in_array('soi_space_tech_versions', $tables, true), 'Table soi_space_tech_versions created');

// 2. Canonical Initial Space Seeding
echo "\n[2. Canonical Space Seeding (KS-05)]\n";
$docService = new SpaceDocumentService($sqlitePdo);
$seeded = $docService->seedInitialSpaces(true);
assert_true(count($seeded) >= 5, 'At least 5 standard canonical spaces seeded');

$slugs = array_column($seeded, 'slug');
assert_true(in_array('general-docs', $slugs, true), 'Canonical space general-docs seeded');
assert_true(in_array('hr-library', $slugs, true), 'Canonical space hr-library seeded');
assert_true(in_array('it-library', $slugs, true), 'Canonical space it-library seeded');
assert_true(in_array('accounts-directory', $slugs, true), 'Canonical space accounts-directory seeded');
assert_true(in_array('hrms', $slugs, true), 'Canonical space hrms seeded');

// 3. Space CRUD & Validation (KS-01)
echo "\n[3. Knowledge Space Service CRUD & Normalization (KS-01)]\n";
$ksService = new KnowledgeSpaceService($sqlitePdo);
$customSpaceId = $ksService->createSpace([
    'title' => 'Payment Gateway API',
    'slug' => 'payment-api',
    'type' => 'tech',
    'status' => 'published',
    'visibility' => 'public',
    'description' => 'Documentation for payment gateway endpoints.',
]);
assert_true($customSpaceId > 0, 'Custom space created with ID ' . $customSpaceId);

$fetched = $ksService->getSpace($customSpaceId);
assert_true($fetched !== null && $fetched['slug'] === 'payment-api', 'getSpace retrieves created space');
assert_true($fetched['type'] === 'tech', 'Space type properly normalized to tech');

$cached = Cache::get(KnowledgeSpaceService::CACHE_PREFIX . "id:{$customSpaceId}") ?: Cache::get("space_id_{$customSpaceId}") ?: Cache::get("space:{$customSpaceId}");
assert_true($cached !== null && $cached['title'] === 'Payment Gateway API', 'Space cached in transient memory');

// 4. Section Hierarchy & Taxonomy Navigation (KS-03)
echo "\n[4. Space Taxonomy & Navigation Hierarchy (KS-03)]\n";
$taxService = new TaxonomyService($sqlitePdo);

$sec1Id = $taxService->createSection([
    'space_id' => $customSpaceId,
    'parent_id' => 0,
    'title' => 'Getting Started',
    'slug' => 'getting-started',
    'sort_order' => 10,
]);
assert_true($sec1Id > 0, 'Root section Getting Started created (ID ' . $sec1Id . ')');

$sec2Id = $taxService->createSection([
    'space_id' => $customSpaceId,
    'parent_id' => $sec1Id,
    'title' => 'Authentication',
    'slug' => 'authentication',
    'sort_order' => 20,
]);
assert_true($sec2Id > 0, 'Child section Authentication created (ID ' . $sec2Id . ')');

// Circular dependency prevention (sec2 is child of sec1)
$isCircular = $taxService->isDescendantOf($sec2Id, $sec1Id, $customSpaceId);
assert_true($isCircular, 'isDescendantOf correctly detects child relationship');

// Build navigation tree
$tree = $taxService->buildNavigationTree($customSpaceId);
assert_true(!empty($tree['sections']), 'buildNavigationTree returns root sections');
assert_true(!empty($tree['sections'][0]['children']), 'Tree preserves hierarchical child sections');

// 5. Document Assignment & Legacy Migration (KS-05)
echo "\n[5. Document Assignment & Legacy Migration (KS-05)]\n";
$assigned = $docService->assignDocument('page', 101, $customSpaceId, $sec2Id);
assert_true($assigned, 'Document 101 assigned to custom space and section');

$docContext = $docService->getDocumentSpaceContext('page', 101);
assert_true($docContext !== null && (int)$docContext['space_id'] === $customSpaceId, 'Document space context retrieved correctly');

// Test unassigned count & migration
$sqlitePdo->exec("INSERT INTO `soi_pages` (title, slug, content) VALUES ('Unassigned Page', 'unassigned-page', '<p>Test</p>');");
$unassigned = $docService->getUnassignedCounts();
assert_true($unassigned['pages'] >= 1, 'getUnassignedCounts detects unassigned legacy pages');

$report = $docService->migrateLegacyDocuments();
assert_true(($report['pages_migrated'] ?? 0) >= 1, 'migrateLegacyDocuments maps unassigned pages to default general-docs space');

// 6. Reader Shell Routing & Resolution (KS-06)
echo "\n[6. Reader Shell & Dynamic Space Routing (KS-06)]\n";
$app = new App();
$themeDir = SOI_ROOT . '/themes/default';

// Add dummy published document to test route resolution
$sqlitePdo->exec("UPDATE `soi_pages` SET status = 'published', space_id = {$customSpaceId}, section_id = {$sec2Id} WHERE id = 101;");

[$template, $vars] = $app->resolveReaderShellRoute("tech/payment-api/authentication/design-tokens", $themeDir);
assert_contains($template, 'reader-shell.php', 'Route tech/payment-api/... resolves to reader-shell.php');
assert_true(!empty($vars['space']), 'Resolved view variables include space metadata');
assert_true(!empty($vars['document']), 'Resolved view variables include active document record');
assert_true(!empty($vars['sections']), 'Resolved view variables include navigation sections');

// 7. Security & Input Sanitization
echo "\n[7. Security & Injection Protection]\n";
assert_false(SpaceSchema::isValidType('malicious_type'), 'Invalid space type recognized as invalid');

assert_true(SpaceSchema::isValidStatus('published'), 'Valid status recognized');
assert_false(SpaceSchema::isValidStatus("published' OR 1=1--"), 'SQL injection status rejected');

$treeBadSpace = $taxService->buildNavigationTree(999999);
assert_true(is_array($treeBadSpace) && empty($treeBadSpace), 'Non-existent space returns safe empty navigation tree');

echo "\n========================================================\n";
echo "Results: {$passed} Passed, {$failed} Failed\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}
echo "🎉 ALL WORKSTREAM B TESTS PASSED CLEANLY!\n";
