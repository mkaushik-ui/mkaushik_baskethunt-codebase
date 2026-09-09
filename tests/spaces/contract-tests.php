<?php
declare(strict_types=1);

/**
 * Task KS-01: Space Domain Service CRUD & Cache Engine Contract Test Suite
 *
 * Validates KnowledgeSpaceServiceInterface compliance, SpaceSchema invariants,
 * validation rules, CRUD operations, filtering, ordering, and Cache engine invalidation.
 */

namespace SOI\Tests\Spaces;

use SOI\Core\Cache;
use SOI\Core\Database;
use SOI\Core\Spaces\KnowledgeSpaceService;
use SOI\Core\Spaces\KnowledgeSpaceServiceInterface;
use SOI\Core\Spaces\SpaceSchema;

// Include core dependencies if not already loaded
if (!class_exists(Database::class)) {
    require_once __DIR__ . '/../../core/Database.php';
}
if (!class_exists(Cache::class)) {
    require_once __DIR__ . '/../../core/Cache.php';
}
if (!class_exists(KnowledgeSpaceServiceInterface::class)) {
    require_once __DIR__ . '/../../core/Spaces/KnowledgeSpaceServiceInterface.php';
}
if (!class_exists(SpaceSchema::class)) {
    require_once __DIR__ . '/../../core/Spaces/SpaceSchema.php';
}
if (!class_exists(KnowledgeSpaceService::class)) {
    require_once __DIR__ . '/../../core/Spaces/KnowledgeSpaceService.php';
}

class SpaceContractTestRunner
{
    private int $passed = 0;
    private int $failed = 0;
    private array $errors = [];
    private \PDO $pdo;
    private KnowledgeSpaceService $service;

    public function __construct()
    {
        // Use SQLite in-memory PDO for isolated, fast, standalone testing
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        // Ensure tables exist
        SpaceSchema::ensure($this->pdo);

        // Inject PDO into Database wrapper and Service
        Database::setPdo($this->pdo, '');
        $this->service = new KnowledgeSpaceService($this->pdo);
    }

    public function runAll(): bool
    {
        echo "======================================================\n";
        echo " SOI Knowledge Center: Space Domain Service Contract Tests\n";
        echo "======================================================\n\n";

        $this->testInterfaceContract();
        $this->testSchemaConstantsAndEnums();
        $this->testCreateSpaceValidation();
        $this->testCreateSpaceSuccess();
        $this->testGetSpaceAndCaching();
        $this->testListSpacesAndFiltering();
        $this->testUpdateSpaceAndCachePurge();
        $this->testDeleteSpaceAndCachePurge();
        $this->testSlugValidationAndReservedWords();

        echo "\n------------------------------------------------------\n";
        echo "Results: {$this->passed} Passed, {$this->failed} Failed\n";
        echo "------------------------------------------------------\n";

        if ($this->failed > 0) {
            foreach ($this->errors as $err) {
                echo "❌ FAIL: {$err}\n";
            }
            return false;
        }

        echo "🎉 ALL SPACE DOMAIN SERVICE CONTRACT TESTS PASSED!\n";
        return true;
    }

    private function assert(bool $condition, string $description): void
    {
        if ($condition) {
            $this->passed++;
            echo "  ✅ PASS: {$description}\n";
        } else {
            $this->failed++;
            $this->errors[] = $description;
            echo "  ❌ FAIL: {$description}\n";
        }
    }

    private function testInterfaceContract(): void
    {
        echo "1. Testing Interface Contract & Class Hierarchy...\n";

        $this->assert($this->service instanceof KnowledgeSpaceServiceInterface, 'KnowledgeSpaceService implements KnowledgeSpaceServiceInterface');

        $ref = new \ReflectionClass(KnowledgeSpaceService::class);
        $methods = ['createSpace', 'getSpace', 'getSpaceBySlug', 'listSpaces', 'getSpaceCount', 'updateSpace', 'deleteSpace', 'validateSlug', 'purgeSpaceCache'];

        foreach ($methods as $method) {
            $this->assert($ref->hasMethod($method), "KnowledgeSpaceService defines method '{$method}'");
        }
    }

    private function testSchemaConstantsAndEnums(): void
    {
        echo "\n2. Testing SpaceSchema Constants and Enum Normalization...\n";

        $this->assert(SpaceSchema::TABLE_NAME === 'spaces', 'Table name constant is spaces');
        $this->assert(in_array('generaldocs', SpaceSchema::VALID_TYPES, true), 'generaldocs is valid type');
        $this->assert(in_array('libraries', SpaceSchema::VALID_TYPES, true), 'libraries is valid type');
        $this->assert(in_array('tech', SpaceSchema::VALID_TYPES, true), 'tech is valid type');

        $this->assert(SpaceSchema::normalizeType('general_docs') === 'generaldocs', 'Alias general_docs normalizes to generaldocs');
        $this->assert(SpaceSchema::normalizeType('docs') === 'generaldocs', 'Alias docs normalizes to generaldocs');
        $this->assert(SpaceSchema::normalizeType('library') === 'libraries', 'Alias library normalizes to libraries');
        $this->assert(SpaceSchema::normalizeType('technical') === 'tech', 'Alias technical normalizes to tech');

        $this->assert(SpaceSchema::isValidType('generaldocs'), 'isValidType generaldocs is true');
        $this->assert(SpaceSchema::isValidType('libraries'), 'isValidType libraries is true');
        $this->assert(SpaceSchema::isValidType('tech'), 'isValidType tech is true');
        $this->assert(!SpaceSchema::isValidType('invalid_type'), 'isValidType invalid_type is false');

        $this->assert(SpaceSchema::isValidVisibility('public'), 'public is valid visibility');
        $this->assert(SpaceSchema::isValidVisibility('authenticated'), 'authenticated is valid visibility');
        $this->assert(SpaceSchema::isValidVisibility('restricted'), 'restricted is valid visibility');
        $this->assert(!SpaceSchema::isValidVisibility('unknown_vis'), 'unknown_vis is invalid visibility');

        $this->assert(SpaceSchema::isValidStatus('published'), 'published is valid status');
        $this->assert(SpaceSchema::isValidStatus('active'), 'active is valid status');
        $this->assert(SpaceSchema::isValidStatus('draft'), 'draft is valid status');
        $this->assert(SpaceSchema::isValidStatus('archived'), 'archived is valid status');
        $this->assert(!SpaceSchema::isValidStatus('deleted'), 'deleted is invalid status');
    }

    private function testCreateSpaceValidation(): void
    {
        echo "\n3. Testing createSpace Input Validation Rules...\n";

        // Missing Title
        $titleException = false;
        try {
            $this->service->createSpace(['title' => '', 'type' => 'generaldocs']);
        } catch (\InvalidArgumentException $e) {
            $titleException = true;
        }
        $this->assert($titleException, 'createSpace rejects empty title with InvalidArgumentException');

        // Invalid Type
        $typeException = false;
        try {
            $this->service->createSpace(['title' => 'Valid Title', 'type' => 'invalid_random_type']);
        } catch (\InvalidArgumentException $e) {
            $typeException = true;
        }
        $this->assert($typeException, 'createSpace rejects invalid type with InvalidArgumentException');

        // Reserved Slug
        $slugException = false;
        try {
            $this->service->createSpace(['title' => 'Admin Panel', 'slug' => 'admin']);
        } catch (\InvalidArgumentException $e) {
            $slugException = true;
        }
        $this->assert($slugException, 'createSpace rejects reserved slug "admin" with InvalidArgumentException');
    }

    private function testCreateSpaceSuccess(): void
    {
        echo "\n4. Testing createSpace Storage & Slug Generation...\n";

        // Auto slug derivation from title
        $id1 = $this->service->createSpace([
            'title'       => 'Company Guidelines & Handbook',
            'type'        => 'generaldocs',
            'description' => 'Official employee guidelines',
            'sortorder'   => 10,
        ]);
        $this->assert($id1 > 0, "createSpace returned valid inserted ID ({$id1})");

        $space1 = $this->service->getSpace($id1);
        $this->assert($space1 !== null && $space1['slug'] === 'company-guidelines-handbook', 'createSpace auto-derives slug "company-guidelines-handbook"');
        $this->assert($space1['type'] === 'generaldocs', 'createSpace correctly stored type "generaldocs"');
        $this->assert($space1['sortorder'] === 10, 'createSpace correctly stored sortorder 10');

        // Custom slug & tech type
        $id2 = $this->service->createSpace([
            'title'       => 'Accounts Directory Docs',
            'slug'        => 'accounts-directory',
            'type'        => 'tech',
            'visibility'  => 'public',
            'status'      => 'published',
            'sortorder'   => 5,
        ]);
        $this->assert($id2 > 0, "createSpace created tech space with custom slug ({$id2})");

        // Duplicate slug detection
        $dupException = false;
        try {
            $this->service->createSpace([
                'title' => 'Duplicate Accounts Directory',
                'slug'  => 'accounts-directory',
                'type'  => 'tech',
            ]);
        } catch (\InvalidArgumentException $e) {
            $dupException = true;
        }
        $this->assert($dupException, 'createSpace throws InvalidArgumentException on duplicate slug');
    }

    private function testGetSpaceAndCaching(): void
    {
        echo "\n5. Testing getSpace & getSpaceBySlug with Cache Engine...\n";

        // Fetch by ID
        $space = $this->service->getSpace(2);
        $this->assert($space !== null && $space['slug'] === 'accounts-directory', 'getSpace(2) returns space record');

        // Fetch by Slug
        $bySlug = $this->service->getSpaceBySlug('accounts-directory');
        $this->assert($bySlug !== null && (int)$bySlug['id'] === 2, 'getSpaceBySlug("accounts-directory") returns space record');

        // Verify Cache hits
        $cachedById = Cache::get('space:id:2') ?? Cache::get('space_id_2');
        $this->assert($cachedById !== null && $cachedById['slug'] === 'accounts-directory', 'Cache contains space by ID');

        $cachedBySlug = Cache::get('space:slug:accounts-directory') ?? Cache::get('space_slug_accounts-directory');
        $this->assert($cachedBySlug !== null && (int)$cachedBySlug['id'] === 2, 'Cache contains space by slug');

        // Non-existent ID & Slug return null
        $this->assert($this->service->getSpace(99999) === null, 'getSpace(99999) returns null');
        $this->assert($this->service->getSpaceBySlug('non-existent-slug') === null, 'getSpaceBySlug("non-existent-slug") returns null');
    }

    private function testListSpacesAndFiltering(): void
    {
        echo "\n6. Testing listSpaces Filter Builder & Ordering...\n";

        // Add 3rd space for libraries
        $this->service->createSpace([
            'title'     => 'HR Policies Library',
            'slug'      => 'hr-policies',
            'type'      => 'libraries',
            'sortorder' => 1,
        ]);

        // Filter by type
        $techList = $this->service->listSpaces(['type' => 'tech']);
        $this->assert(count($techList) === 1 && $techList[0]['slug'] === 'accounts-directory', 'listSpaces(["type" => "tech"]) returns only tech spaces');

        $docsList = $this->service->listSpaces(['type' => 'generaldocs']);
        $this->assert(count($docsList) === 1 && $docsList[0]['slug'] === 'company-guidelines-handbook', 'listSpaces(["type" => "generaldocs"]) returns only generaldocs spaces');

        // Search filter
        $searchList = $this->service->listSpaces(['q' => 'Guidelines']);
        $this->assert(count($searchList) === 1 && $searchList[0]['slug'] === 'company-guidelines-handbook', 'listSpaces(["q" => "Guidelines"]) searches across title/slug/description');

        // Ordering by sortorder ASC, title ASC
        $all = $this->service->listSpaces();
        $this->assert(count($all) === 3, 'listSpaces() returns all 3 spaces');
        $this->assert($all[0]['slug'] === 'hr-policies', 'First space is hr-policies (sortorder 1)');
        $this->assert($all[1]['slug'] === 'accounts-directory', 'Second space is accounts-directory (sortorder 5)');
        $this->assert($all[2]['slug'] === 'company-guidelines-handbook', 'Third space is company-guidelines-handbook (sortorder 10)');
    }

    private function testUpdateSpaceAndCachePurge(): void
    {
        echo "\n7. Testing updateSpace and Cache Invalidation...\n";

        $space2 = $this->service->getSpace(2);
        $this->assert($space2 !== null, 'Found space 2 for update test');

        // Update title and sortorder
        $success = $this->service->updateSpace(2, [
            'title'     => 'Updated Accounts Directory Docs',
            'sortorder' => 20,
        ]);
        $this->assert($success === true, 'updateSpace(2, ...) returns true');

        $updated = $this->service->getSpace(2);
        $this->assert($updated['title'] === 'Updated Accounts Directory Docs', 'DB and Cache reflect updated title');
        $this->assert($updated['sortorder'] === 20, 'DB and Cache reflect updated sortorder (20)');

        // Slug change purges old slug cache
        $this->service->updateSpace(2, ['slug' => 'accounts-directory-v2']);
        $oldCache = Cache::get('space:slug:accounts-directory') ?? Cache::get('space_slug_accounts-directory');
        $this->assert($oldCache === null, 'Old slug cache key was purged on slug rename');

        $newSpace = $this->service->getSpaceBySlug('accounts-directory-v2');
        $this->assert($newSpace !== null && (int)$newSpace['id'] === 2, 'New slug "accounts-directory-v2" resolves correctly');
    }

    private function testDeleteSpaceAndCachePurge(): void
    {
        echo "\n8. Testing deleteSpace and Cache Cleanup...\n";

        $countBefore = $this->service->getSpaceCount();
        $delSuccess = $this->service->deleteSpace(3); // delete hr-policies (id 3)
        $this->assert($delSuccess === true, 'deleteSpace(3) returns true');

        $countAfter = $this->service->getSpaceCount();
        $this->assert($countAfter === $countBefore - 1, "Space count decreased by 1 ({$countBefore} -> {$countAfter})");

        $deletedSpace = $this->service->getSpace(3);
        $this->assert($deletedSpace === null, 'getSpace(3) returns null after deletion');

        $cachedDeleted = Cache::get('space:id:3') ?? Cache::get('space_id_3');
        $this->assert($cachedDeleted === null, 'Cache key for deleted space was purged');

        // Deleting non-existent returns false
        $this->assert($this->service->deleteSpace(99999) === false, 'deleteSpace(99999) returns false');
    }

    private function testSlugValidationAndReservedWords(): void
    {
        echo "\n9. Testing validateSlug & Reserved System Words...\n";

        $this->assert($this->service->validateSlug('hr-policies'), 'Valid slug "hr-policies" is accepted');
        $this->assert($this->service->validateSlug('tech-docs-2026'), 'Valid slug "tech-docs-2026" is accepted');

        $this->assert(!$this->service->validateSlug('admin'), 'Reserved slug "admin" is rejected');
        $this->assert(!$this->service->validateSlug('api'), 'Reserved slug "api" is rejected');
        $this->assert(!$this->service->validateSlug('install'), 'Reserved slug "install" is rejected');
        $this->assert(!$this->service->validateSlug('themes'), 'Reserved slug "themes" is rejected');

        $this->assert(!$this->service->validateSlug('Invalid Slug!@#'), 'Slug with spaces/symbols is rejected');
        $this->assert(!$this->service->validateSlug(''), 'Empty slug is rejected');
    }
}

// Execute if run from CLI
if (php_sapi_name() === 'cli' || !isset($_SERVER['HTTP_HOST'])) {
    $runner = new SpaceContractTestRunner();
    $exitCode = $runner->runAll() ? 0 : 1;
    if (php_sapi_name() === 'cli') {
        exit($exitCode);
    }
}
