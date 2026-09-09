<?php
/**
 * Audience & Permissions (Workstream D / Milestone M4 / Tasks WD-01 through WD-06)
 * Automated Contract, Boundary & Security Verification Suite.
 *
 * Location: tests/spaces/audience-tests.php
 * Run: php tests/spaces/audience-tests.php
 *
 * Single Source of Truth verification:
 * Accounts / SAML Claims -> AudienceSubjectContext -> AudiencePolicyService -> Reader / Nav / Reusable / Admin
 *
 * @author Team Lead & Security QA
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

use SOI\Core\Spaces\Audience\AudienceSubjectContext;
use SOI\Core\Spaces\Audience\AudiencePolicyService;
use SOI\Core\Spaces\SpaceSchema;

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

function assert_equals($expected, $actual, string $message): void
{
    assert_true($expected === $actual, "{$message} (Expected: " . json_encode($expected) . ", Got: " . json_encode($actual) . ")");
}

echo "\n=== SOI KNOWLEDGE CENTER: WORKSTREAM D (AUDIENCE & PERMISSIONS) TEST SUITE ===\n\n";

$policyService = new AudiencePolicyService();

// --------------------------------------------------------------------------
// 1. AudienceSubjectContext Normalization Tests (WD-01)
// --------------------------------------------------------------------------
echo "[Suite 1] AudienceSubjectContext Normalization\n";

$guest = AudienceSubjectContext::guest();
assert_false($guest->isLoggedIn(), "Guest subject isLoggedIn is false");
assert_false($guest->isAdmin(), "Guest subject isAdmin is false");
assert_equals('guest', $guest->getRole(), "Guest subject role is 'guest'");
assert_equals(null, $guest->getId(), "Guest user ID is null");

$hrUser = new AudienceSubjectContext(
    101,
    'alice@soi.co.in',
    'author',
    'HR',
    'Recruitment',
    ['hr-managers', 'reviewers'],
    ['edit_docs'],
    true
);
assert_true($hrUser->isLoggedIn(), "HR user isLoggedIn is true");
assert_false($hrUser->isAdmin(), "HR user is not global admin");
assert_equals('HR', $hrUser->getDepartment(), "HR user department is 'HR'");
assert_equals('Recruitment', $hrUser->getTeam(), "HR user team is 'Recruitment'");
assert_true($hrUser->hasGroup('hr-managers'), "HR user has 'hr-managers' group");
assert_false($hrUser->hasGroup('dev-team'), "HR user does not have 'dev-team' group");

$adminUser = new AudienceSubjectContext(1, 'admin@soi.co.in', 'admin', 'IT', 'Core', [], [], true);
assert_true($adminUser->isAdmin(), "Admin subject isAdmin is true");

// --------------------------------------------------------------------------
// 2. Space Visibility & Policy Evaluation Tests (WD-02)
// --------------------------------------------------------------------------
echo "\n[Suite 2] Space Visibility & Policy Evaluation (Deny by Default)\n";

$publicSpace = [
    'id' => 1,
    'slug' => 'general-docs',
    'status' => 'published',
    'visibility' => 'public',
];

$authSpace = [
    'id' => 2,
    'slug' => 'internal-handbook',
    'status' => 'published',
    'visibility' => 'authenticated',
];

$restrictedSpace = [
    'id' => 3,
    'slug' => 'hr-library',
    'status' => 'published',
    'visibility' => 'restricted',
    'audience_policy' => json_encode([
        'visibility' => 'restricted',
        'departments' => ['HR'],
        'teams' => ['People Ops'],
        'allowed_users' => [202],
        'space_owners' => [303],
    ]),
];

// Public space checks
assert_true($policyService->canAccessSpace($publicSpace, $guest), "Guest can view public space");
assert_true($policyService->canAccessSpace($publicSpace, $hrUser), "HR User can view public space");

// Authenticated space checks
assert_false($policyService->canAccessSpace($authSpace, $guest), "Guest cannot view authenticated space");
assert_true($policyService->canAccessSpace($authSpace, $hrUser), "Logged-in user can view authenticated space");

// Restricted space checks
$itUser = new AudienceSubjectContext(102, 'bob@soi.co.in', 'author', 'IT', 'DevOps', [], [], true);
$oneOffUser = new AudienceSubjectContext(202, 'charlie@soi.co.in', 'subscriber', 'Finance', 'Payroll', [], [], true);
$spaceOwner = new AudienceSubjectContext(303, 'dana@soi.co.in', 'editor', 'Legal', 'Compliance', [], [], true);

assert_false($policyService->canAccessSpace($restrictedSpace, $guest), "Guest denied restricted HR space");
assert_false($policyService->canAccessSpace($restrictedSpace, $itUser), "IT user denied restricted HR space");
assert_true($policyService->canAccessSpace($restrictedSpace, $hrUser), "HR department user permitted access to HR space");
assert_true($policyService->canAccessSpace($restrictedSpace, $oneOffUser), "Allowed user ID 202 permitted access to HR space");
assert_true($policyService->canAccessSpace($restrictedSpace, $spaceOwner), "Space owner permitted access to HR space");
assert_true($policyService->canAccessSpace($restrictedSpace, $adminUser), "Global admin always permitted access to restricted space");

// --------------------------------------------------------------------------
// 3. Space Management & Delegation Tests (WD-02 / WD-05)
// --------------------------------------------------------------------------
echo "\n[Suite 3] Space Management & Delegation\n";

assert_true($policyService->canManageSpace($restrictedSpace, $adminUser), "Admin can manage space");
assert_true($policyService->canManageSpace($restrictedSpace, $spaceOwner), "Designated space owner can manage space");
assert_false($policyService->canManageSpace($restrictedSpace, $hrUser), "Regular HR member cannot manage space without owner role");
assert_false($policyService->canManageSpace($restrictedSpace, $guest), "Guest cannot manage space");

// --------------------------------------------------------------------------
// 4. Policy Normalization & Schema Validation (WD-03)
// --------------------------------------------------------------------------
echo "\n[Suite 4] Policy Normalization & Schema Validation\n";

$rawPolicy = [
    'departments' => [' HR ', 'HR', 'Finance '],
    'allowed_users' => ['10', 20, '20'],
    'teams' => [' Recruitment '],
];
$normalized = $policyService->normalizePolicy($rawPolicy);

assert_equals(['HR', 'Finance'], $normalized['departments'], "Departments trimmed and deduplicated");
assert_equals([10, 20], $normalized['allowed_users'], "User IDs cast to integers and deduplicated");
assert_equals(['Recruitment'], $normalized['teams'], "Teams trimmed");

// --------------------------------------------------------------------------
// 5. Navigation Tree Sanitization (WD-04)
// --------------------------------------------------------------------------
echo "\n[Suite 5] Navigation Tree Sanitization (Zero Leakage)\n";

$spacesList = [$publicSpace, $authSpace, $restrictedSpace];

$guestSpaces = $policyService->filterAccessibleSpaces($spacesList, $guest);
assert_equals(1, count($guestSpaces), "Guest sees only 1 public space in list");

$hrSpaces = $policyService->filterAccessibleSpaces($spacesList, $hrUser);
assert_equals(3, count($hrSpaces), "HR user sees all 3 spaces (public, auth, hr restricted)");

$itSpaces = $policyService->filterAccessibleSpaces($spacesList, $itUser);
assert_equals(2, count($itSpaces), "IT user sees only 2 spaces (public, auth)");

echo "\n=================================================================\n";
echo "TEST RESULTS: Passed: {$passed} | Failed: {$failed}\n";
echo "=================================================================\n";

if ($failed > 0) {
    exit(1);
}
