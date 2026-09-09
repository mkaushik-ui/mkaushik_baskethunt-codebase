# SOI Knowledge Center — Audience & Permissions Task Plan & Architecture Blueprint

> **DOCUMENT STATUS**: AUTHORITATIVE PLANNING ARTIFACT  
> **SCOPE**: SOI Knowledge Center — Workstream D (Audience, Permissions & Access Control / Milestone M4)  
> **TARGET DOMAIN**: `kc.soi.co.in` (Isolated Access Control & Knowledge Authorization Boundary)  
> **DELIVERY DISCIPLINE**: STRICT SINGLE SOURCE OF TRUTH — ZERO CROSS-DOMAIN BLAST RADIUS  

---

## 1. Executive Purpose & Governance Rules

This document establishes a strictly isolated, small-task decomposition for the complete implementation of **Workstream D: Audience & Permissions**. It guarantees that access control, department/team scoping, visibility rules, and space ownership are enforced uniformly across discovery, navigation, reader routing, and administrative actions with **100% Single Source of Truth (SSOT)** alignment and fail-closed (**Deny by Default**) semantics.

```
┌──────────────────────────────────────────────────────────────────────────────────────────────────┐
│                             SINGLE SOURCE OF TRUTH (SSOT) FLOW                                   │
├──────────────────────────────────────────────────────────────────────────────────────────────────┤
│                                                                                                  │
│   SOI Accounts Directory / SSO (accounts.soi.co.in)                                              │
│         │ (SAML Assertion / OAuth Claims: user_id, department, team, groups, app_roles)          │
│         ▼                                                                                        │
│   [ SOI Central Auth Session ]                                                                   │
│         │                                                                                        │
│         ▼                                                                                        │
│   ┌───────────────────────────────────┐                                                          │
│   │      AudienceSubjectContext       │ ◄─── (Normalizes authenticated user vs guest state)      │
│   └─────────────────┬─────────────────┘                                                          │
│                     │                                                                            │
│                     ▼                                                                            │
│   ┌───────────────────────────────────┐                                                          │
│   │       AudiencePolicyService       │ ◄─── [ Space & Document Audience Policies in Database ]  │
│   │   (Single Authoritative Engine)   │                                                          │
│   └─────────────────┬─────────────────┘                                                          │
│                     │                                                                            │
│         ┌───────────┼───────────┬──────────────┬─────────────┐                                   │
│         ▼           ▼           ▼              ▼             ▼                                   │
│    [ Reader ]  [ Nav Tree ]  [ Search ]  [ Reusable Blk ] [ Admin / Space Owner ]                │
│    (App.php)  (TaxonomySvc) (Indexer)      (Renderer)     (spaces.php CRUD)                     │
│                                                                                                  │
└──────────────────────────────────────────────────────────────────────────────────────────────────┘
```

### Core Hard Rules:
1. **Single Source of Truth Flow**:
   - **Identity Authority**: SOI Accounts Directory (`accounts.soi.co.in`) via `SoiCentralAuth` is the sole organizational identity authority. Knowledge Center never maintains a shadow user/org hierarchy.
   - **Policy Authority**: `AudiencePolicyService` in `kc.soi.co.in` is the **single authoritative evaluation engine** for all access decisions. No controller, template, or API makes ad-hoc role or visibility checks.
2. **Policy Isolation from Content Body**:
   - Permissions and audience rules are external metadata/policies attached to Spaces, Sections, or Documents. Permissions are **NEVER** embedded inside structured content `body_json`.
3. **Deny-by-Default (Fail-Closed)**:
   - When audience resolution fails, is ambiguous, or matches no explicit allow-rule in a restricted context, access is denied immediately with a 404 (for discovery/reader obscurity) or 403 (for explicit auth failures).
4. **Uniform Cross-Layer Enforcement**:
   - Direct reader routes (`/docs`, `/library/*`, `/tech/*`), navigation tree builders, related document graph resolvers, search indexers/queries, reusable blocks, and admin actions must all query the exact same `AudiencePolicyService`.
5. **Domain Scope Isolation (`kc.soi.co.in`)**:
   - All code modifications, database schema additions, and test suites are strictly confined to `kc.soi.co.in`. Zero modifications to external SOI services (Accounts Directory, HRMS, Central SAML IdP).
6. **Validation Standard**:
   - Every task requires **Static Syntax Check (`php -l`) + Automated Unit/Contract Test Suite (`php tests/spaces/audience-tests.php`) + Live Browser Acceptance**.

---

## 2. Workspace Boundary Audit & Runtime Tracing

### 2.1 The Audience & Permissions Authority Boundary (`kc.soi.co.in`)
The runtime for Workstream D consists strictly of:
- **Audience Domain Core (`core/Spaces/Audience/`)**:
  - Subject Context Normalizer: `core/Spaces/Audience/AudienceSubjectContext.php`
  - Policy Contract Interface: `core/Spaces/Audience/AudiencePolicyServiceInterface.php`
  - Canonical Policy Engine: `core/Spaces/Audience/AudiencePolicyService.php`
- **Database & Space Engine Integration**:
  - Schema Runner: `core/Spaces/SpaceSchema.php` (`audience_policy` longtext & indices)
  - Space Management Service: `core/Spaces/KnowledgeSpaceService.php`
  - Document Resolution Service: `core/Spaces/SpaceDocumentService.php`
  - Taxonomy & Navigation Service: `core/Spaces/TaxonomyService.php`
- **Frontend & Route Authorization Guards**:
  - Front Controller & Route Guard: `core/App.php` (`resolveReaderShellRoute`)
  - Reusable Block Permission Guard: `core/Reusable/ReusableBlockService.php`
- **Admin Audience UI & Ownership**:
  - Admin Space Controller: `admin/spaces.php`
  - Admin Space API Endpoint: `admin/spaces-api.php`
- **Verification & Acceptance Test Suite**:
  - Audience Automated Test Suite: `tests/spaces/audience-tests.php`
  - Space Regression Suite: `tests/spaces/run.php`

### 2.2 Non-Audience Domains (Strictly Isolated / Excluded)
The following files and directories belong to other domains and **MUST NOT BE MODIFIED** during Workstream D tasks:
- **Editor Vendor Runtimes & Canvas**: `admin/assets/editor/vendor/`, `admin/assets/editor/layout/`, `admin/assets/editor/blocks/`
- **External Identity IdP**: `core/SoiCentralAuth.php` (Read session claims via public helper methods only; do NOT modify SAML protocol or network endpoints)
- **Unrelated Admin Modules**: `admin/appearance.php`, `admin/plugins.php`, `admin/smtp.php`, `admin/updates.php`

---

## 3. Problem Classification & Task Decomposition

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                       WORKSTREAM D TASK CLASSIFICATION                      │
├───────────────────────┬───────────────────────────┬─────────────────────────┤
│ Classification        │ Task IDs                  │ Primary Focus Area      │
├───────────────────────┼───────────────────────────┼─────────────────────────┤
│ A. Subject & Contract │ WD-01                     │ AudienceSubjectContext  │
│ B. Core Policy Engine │ WD-02                     │ AudiencePolicyService   │
│ C. Space Schema & DDL │ WD-03                     │ SpaceSchema & Services  │
│ D. Reader & Nav Guard │ WD-04                     │ App.php & TaxonomySvc   │
│ E. Admin Policy UI    │ WD-05                     │ admin/spaces.php Modal  │
│ F. Reusable & QA Gate │ WD-06                     │ audience-tests.php Gate │
└───────────────────────┴───────────────────────────┴─────────────────────────┘
```

---

## 4. Detailed Task Briefings (WD-01 through WD-06)

---

### [WD-01] Audience Subject Context & Policy Service Contract
- **Category**: Subject Normalization & Contract Definition
- **Assigned Role**: Developer 1 (Backend Core & Security)
- **Phase**: Phase 1 (Foundation) | **Priority**: Critical
- **Dependencies**: None | **Can Run Independently**: YES
- **Problem Statement**: Knowledge Center needs a single normalized representation of the requesting subject (whether guest, logged-in user, department member, team member, or space owner) that bridges `SOI\Core\Auth` and `SoiCentralAuth` claims without coupling core services directly to raw session superglobals.
- **Investigation Scope**:
  - `core/Auth.php`: inspect `Auth::user()`, `Auth::role()`, `Auth::id()`, `Auth::check()`.
  - `core/SoiCentralAuth.php`: inspect claims extraction (`department`, `team`, `app_roles`, `app_permissions`, `email`).
- **Implementation Scope**:
  - Create `core/Spaces/Audience/AudienceSubjectContext.php`:
    - Method `fromCurrentSession(): AudienceSubjectContext`: extracts authenticated user ID, email, role, department, team, groups, and permissions from `Auth::user()` and `SoiCentralAuth::currentSessionProfile()`.
    - Method `guest(): AudienceSubjectContext`: builds anonymous guest subject with `isLoggedIn = false`.
    - Immutable accessors: `getId(): ?int`, `getEmail(): ?string`, `getRole(): string`, `getDepartment(): ?string`, `getTeam(): ?string`, `getGroups(): array`, `isLoggedIn(): bool`, `isAdmin(): bool`.
  - Create `core/Spaces/Audience/AudiencePolicyServiceInterface.php`:
    - Define contract methods: `canAccessSpace()`, `canAccessDocument()`, `canManageSpace()`, `filterAccessibleSpaces()`, `filterNavigationTree()`, `validatePolicySchema()`.
- **Files Involved**:
  - Primary: `core/Spaces/Audience/AudienceSubjectContext.php`, `core/Spaces/Audience/AudiencePolicyServiceInterface.php`
  - Secondary: `core/Spaces/Audience/`
  - Protected: Do NOT modify session management in `core/Auth.php` or `core/SoiCentralAuth.php`.

---

### [WD-02] Canonical Audience Policy Evaluation Engine
- **Category**: Policy Evaluation & Authorization Rules
- **Assigned Role**: Developer 2 (Policy Engine & Security)
- **Phase**: Phase 1 (Foundation) | **Priority**: Critical
- **Dependencies**: WD-01 | **Can Run Independently**: YES
- **Problem Statement**: Authorization checks are currently absent in Space routing and navigation. A robust policy evaluation engine must implement multi-dimensional rules (visibility, departments, teams, groups, specific user allow-lists, and space ownership) with strict fail-closed (deny-by-default) semantics.
- **Investigation Scope**:
  - Handoff Document Page 14 (Audience Dimensions & Authorization Principles).
  - `core/Spaces/SpaceSchema.php`: check `VISIBILITY_*` constants (`public`, `authenticated`, `restricted`).
- **Implementation Scope**:
  - Create `core/Spaces/Audience/AudiencePolicyService.php` implementing `AudiencePolicyServiceInterface`:
    - **Global Admin Bypass**: If `$subject->isAdmin()`, allow full read/manage access.
    - **Space Visibility Evaluation (`canAccessSpace`)**:
      - `public`: Allow all subjects (including guests).
      - `authenticated`: Allow if `$subject->isLoggedIn() === true`.
      - `restricted`: Parse space `audience_policy` JSON. Deny if empty or invalid. Check if subject matches any:
        1. `$subject->getDepartment()` in `policy.departments`
        2. `$subject->getTeam()` in `policy.teams`
        3. Array intersection between `$subject->getGroups()` and `policy.groups`
        4. `$subject->getId()` in `policy.allowed_users`
        5. `$subject->getId()` in `policy.space_owners`
        If no rule matches, return `false` (Deny by Default).
    - **Space Management Evaluation (`canManageSpace`)**:
      - Returns `true` if subject is global admin OR subject ID is in `policy.space_owners`.
    - **Document Visibility Evaluation (`canAccessDocument`)**:
      - Evaluates parent space access; if space allows, checks document-level override policy if present.
    - **Filter Collections (`filterAccessibleSpaces`, `filterNavigationTree`)**:
      - Iterates and strips any spaces/sections/documents that `$subject` cannot access.
- **Data Policy Schema**:
```json
{
  "visibility": "restricted",
  "departments": ["HR", "Operations"],
  "teams": ["Recruitment", "People Ops"],
  "groups": ["hr-partners"],
  "allowed_users": [42, 108],
  "space_owners": [42],
  "inheritance": "inherit"
}
```
- **Files Involved**:
  - Primary: `core/Spaces/Audience/AudiencePolicyService.php`
  - Secondary: `tests/spaces/audience-tests.php`

---

### [WD-03] Policy Schema Alignment & Space Service Integration
- **Category**: Database Schema & Space Domain Services
- **Assigned Role**: Developer 3 (Database & Domain Services)
- **Phase**: Phase 2 (Space Integration) | **Priority**: High
- **Dependencies**: WD-01, WD-02 | **Can Run Independently**: YES
- **Problem Statement**: `KnowledgeSpaceService` and `SpaceSchema` store raw `audience_policy` strings without structured JSON validation, default initialization, or space-owner indexing.
- **Investigation Scope**:
  - `core/Spaces/SpaceSchema.php`: verify table definitions for `soispaces` and `soi_spaces`.
  - `core/Spaces/KnowledgeSpaceService.php`: verify `createSpace()` and `updateSpace()`.
- **Implementation Scope**:
  - In `core/Spaces/SpaceSchema.php`:
    - Ensure `audience_policy` column exists on both `soispaces` and `soi_spaces` tables with appropriate indexes for `visibility` and `status`.
  - In `core/Spaces/KnowledgeSpaceService.php`:
    - Integrate `AudiencePolicyService::validatePolicySchema()` on `createSpace()` and `updateSpace()`.
    - Normalize audience policies before saving (sanitize array values, deduplicate user IDs).
    - Add `listSpacesForSubject(AudienceSubjectContext $subject, array $filter = []): array` to return filtered spaces tailored to the viewer.
- **Files Involved**:
  - Primary: `core/Spaces/SpaceSchema.php`, `core/Spaces/KnowledgeSpaceService.php`
  - Secondary: `core/Spaces/SpaceDocumentService.php`

---

### [WD-04] Reader Route Dispatcher & Navigation Tree Authorization Guards
- **Category**: Reader Security & Navigation Filtering
- **Assigned Role**: Developer 4 (Routing & Public Reader)
- **Phase**: Phase 2 (Enforcement) | **Priority**: Critical
- **Dependencies**: WD-01, WD-02, WD-03 | **Can Run Independently**: NO
- **Problem Statement**: `core/App.php` (`resolveReaderShellRoute`) and `TaxonomyService::buildNavigationTree()` currently serve published content without evaluating whether the requesting user meets the space's audience policy. Unauthorized users can view restricted knowledge simply by knowing the URL.
- **Investigation Scope**:
  - `core/App.php`: inspect lines 250–370 (`resolveReaderShellRoute`).
  - `core/Spaces/TaxonomyService.php`: inspect `buildNavigationTree($spaceId)`.
- **Implementation Scope**:
  - In `core/App.php` (`resolveReaderShellRoute`):
    - Resolve current `$subject = AudienceSubjectContext::fromCurrentSession()`.
    - Instantiate `AudiencePolicyService`.
    - Check `$policyService->canAccessSpace($space, $subject)`. If denied:
      - If `$subject->isLoggedIn()`: return HTTP 403 Forbidden with clean reader denial view.
      - If guest: redirect to login or return HTTP 404 (prevent information disclosure of restricted space slugs).
    - If document is requested, verify `$policyService->canAccessDocument($document, $subject)`.
  - In `core/Spaces/TaxonomyService.php`:
    - Update `buildNavigationTree(int $spaceId, ?AudienceSubjectContext $subject = null): array` to filter out sections or documents inaccessible to the subject.
- **Files Involved**:
  - Primary: `core/App.php`, `core/Spaces/TaxonomyService.php`
  - Secondary: `templates/reader-shell.php`

---

### [WD-05] Admin Audience Policy Builder & Space Ownership Management
- **Category**: Admin UI & Space Governance
- **Assigned Role**: Developer 5 (Admin UI & Space Settings)
- **Phase**: Phase 3 (Admin UI) | **Priority**: High
- **Dependencies**: WD-01, WD-02, WD-03 | **Can Run Independently**: YES
- **Problem Statement**: `admin/spaces.php` only offers a basic radio group for visibility (`public`, `authenticated`, `restricted`). Space administrators cannot configure target departments, teams, groups, individual allowed users, or delegate space ownership without granting full global admin rights.
- **Investigation Scope**:
  - `admin/spaces.php`: inspect space creation/edit modal (lines 800–860) and table listing.
  - `admin/spaces-api.php`: inspect AJAX handlers for space create/update.
- **Implementation Scope**:
  - In `admin/spaces.php`:
    - Add an extensible **Audience Policy Configuration Panel** inside the Space Modal:
      - Department tag input (e.g. `HR`, `IT`, `Finance`, `Engineering`, `Operations`).
      - Team tag input (e.g. `Recruitment`, `Core Infrastructure`).
      - Space Owners selector (delegated users who can edit space docs & taxonomy without global admin).
      - Allowed User IDs / Email selector for restricted one-off access.
    - Conditionally reveal the Policy Configuration Panel when Visibility is set to `Restricted`.
    - Display Audience & Owner badges in the spaces table list.
  - In `admin/spaces-api.php`:
    - Enforce `$policyService->canManageSpace($space, $subject)` on all update, delete, and section reorder requests.
    - Validate and persist `audience_policy` payload.
- **UI/UX Guidelines**:
  - Clean tag/chip input with keyboard support (Enter/Comma to add tag).
  - Subtle badges in the Space table: `🌐 Public`, `🔑 SOI Login`, `🛡️ Restricted (3 Depts, 1 Team)`.
  - Responsive layout fitting existing SOI design system tokens (`--soi-surface`, `--soi-border`).
- **Files Involved**:
  - Primary: `admin/spaces.php`, `admin/spaces-api.php`
  - Secondary: `admin/assets/js/spaces.js` (if decoupled)

---

### [WD-06] Reusable Content Guards, Automated Test Suite & Milestone M4 Acceptance Gate
- **Category**: QA, Reusable Blocks & Acceptance Verification
- **Assigned Role**: Team Lead & QA
- **Phase**: Phase 4 (Acceptance Gate) | **Priority**: Critical
- **Dependencies**: Completion of WD-01 through WD-05
- **Problem Statement**: Reusable blocks and cross-space links could inadvertently leak restricted snippet content into public pages. In addition, an automated regression suite must certify that unauthorized users cannot discover, search, or fetch restricted content through any vector.
- **Investigation Scope**:
  - `core/Reusable/ReusableBlockService.php`: inspect block rendering pipeline.
  - `tests/spaces/`: inspect test harness structure.
- **Implementation Scope**:
  - In `core/Reusable/ReusableBlockService.php`:
    - Add permission check before embedding restricted reusable blocks into reader contexts.
  - Create `tests/spaces/audience-tests.php`:
    1. **Public Space Test**: Guests and authenticated users can access `/docs` and public libraries.
    2. **Authenticated Space Test**: Guests receive redirect/404; logged-in users are permitted.
    3. **Restricted Department Test**: User in `HR` department can access `/library/hr-library`; user in `Engineering` is rejected (403/404).
    4. **Restricted Team Test**: User in `Recruitment` team permitted; non-member rejected.
    5. **Space Ownership Test**: Delegated space owner can update space settings & taxonomy without global `admin` role.
    6. **Navigation Leak Test**: Restricted sections are completely absent from navigation JSON for unauthorized subjects.
    7. **Regression Test**: Verify all existing test suites (`tests/editor/run.php`, `tests/spaces/run.php`) pass with 0 errors.
- **Files Involved**:
  - Primary: `tests/spaces/audience-tests.php`, `core/Reusable/ReusableBlockService.php`
  - Secondary: `tests/spaces/run.php`

---

## 5. Task Dependency Graph & Sequencing

```
                               PHASE 1: FOUNDATION & CONTRACTS
                    ┌──────────────────────────────────────────────────┐
                    │ [WD-01] AudienceSubjectContext & Policy Interface│
                    └────────────────────────┬─────────────────────────┘
                                             │
                                             ▼
                    ┌──────────────────────────────────────────────────┐
                    │ [WD-02] Canonical AudiencePolicyService Engine   │
                    └────────────────────────┬─────────────────────────┘
                                             │
                                             ▼
                                PHASE 2: DOMAIN & ROUTE GUARDS
                    ┌────────────────────────┴─────────────────────────┐
                    ▼                                                  ▼
     [WD-03] SpaceSchema & Service                      [WD-04] Reader Route & Nav
     (Policy validation & storage)                       (App.php & Taxonomy filter)
                    │                                                  │
                    └────────────────────────┬─────────────────────────┘
                                             │
                                             ▼
                                 PHASE 3: ADMIN GOVERNANCE
                    ┌──────────────────────────────────────────────────┐
                    │ [WD-05] Admin Audience Policy Builder & Owners   │
                    └────────────────────────┬─────────────────────────┘
                                             │
                                             ▼
                                  PHASE 4: ACCEPTANCE GATE
                    ┌──────────────────────────────────────────────────┐
                    │ [WD-06] Reusable Guards & M4 Acceptance Suite    │
                    └──────────────────────────────────────────────────┘
```

---

## 6. Execution Matrix: Independent vs Sequenced Tasks

### 6.1 Parallelizable Tasks
- **WD-01** and **WD-02** form the standalone core foundation and can be built without touching frontends.
- **WD-03** (Schema/Service) and **WD-05** (Admin UI) can proceed in parallel once WD-02 is defined.

### 6.2 Sequenced Tasks
- **WD-04** (Reader Guard) requires WD-01, WD-02, and WD-03.
- **WD-06** (Acceptance Suite) runs strictly after WD-01 through WD-05 are integrated.

### 6.3 Shared-File Coordination Matrix

| Shared File | Tasks Modifying File | Specific Concern Per Task | Conflict Prevention Rule |
| :--- | :--- | :--- | :--- |
| `core/Spaces/SpaceSchema.php` | WD-03 | Column validation & index declarations | Add helpers under `AudiencePolicy` section. |
| `core/App.php` | WD-04 | Public reader route access check | Encapsulate within `resolveReaderShellRoute` authorization block. |
| `admin/spaces.php` | WD-05 | Audience policy builder & modal inputs | Add controls into dedicated modal container `#kcAudiencePolicyGroup`. |
| `core/Spaces/TaxonomyService.php` | WD-04 | Subject-filtered navigation tree | Accept optional `AudienceSubjectContext` parameter with default `null`. |

---

## 7. Complete Test & Acceptance Matrix

| Task ID | Test Name | Verification Procedure | Expected Result | Regression Risk |
| :---: | :--- | :--- | :--- | :--- |
| **WD-01** | Subject Context Normalization | Instantiate context from guest vs SAML session claims. | Returns accurate department, team, role, and login state. | Low |
| **WD-02** | Policy Engine Unit Tests | Evaluate public, authenticated, department, team, and user rules. | Evaluates accurately with strict fail-closed (deny-by-default) logic. | Low |
| **WD-03** | Policy Persistence Test | Save valid and invalid `audience_policy` JSON in `KnowledgeSpaceService`. | Valid policies persist; invalid schemas rejected with descriptive error. | Medium |
| **WD-04** | Route & Nav Guard Live Test | Request `/library/{restricted-slug}` as guest, unauthorized user, and allowed user. | Guest/unauthorized receives 404/403; allowed user views content. Nav tree hides restricted nodes. | Critical |
| **WD-05** | Admin Policy Builder Test | Configure department & team rules in admin modal; save space. | Rules save cleanly and reflect immediately in space badges and access enforcement. | Medium |
| **WD-06** | Milestone M4 Acceptance Gate | Run `php tests/spaces/audience-tests.php` and full regression suites. | 100% test pass rate with 0 uncaught errors and zero regressions. | Critical |

---

## 8. Milestone M4 Acceptance Standard

Before closing Workstream D (Milestone M4), the following acceptance criteria must be satisfied:
1. **Single Source of Truth Adherence**: Organizational identity flows purely from SOI Accounts Directory (`accounts.soi.co.in`); policy evaluation flows purely through `AudiencePolicyService`.
2. **Fail-Closed Security (Deny by Default)**: Any unauthenticated request or unapproved subject attempting to access a restricted space or document is denied.
3. **Zero Information Leaks**: Restricted spaces and documents do not appear in navigation trees, search results, or related document graphs for unauthorized users.
4. **Space Ownership Delegation**: Designated space owners can manage space settings, taxonomy, and documents without requiring global CMS administrator privileges.
5. **Clean Workspace Boundaries**: All code and schema changes are strictly limited to `kc.soi.co.in`.
6. **Zero Regression Guarantee**: All existing test suites (`tests/spaces/run.php`, `tests/editor/run.php`, and `tests/spaces/audience-tests.php`) pass with 100% success rate.
