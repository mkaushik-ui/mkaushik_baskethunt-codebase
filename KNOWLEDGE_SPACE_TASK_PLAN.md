# SOI Knowledge Center — Knowledge Space Foundation Task Plan & Architecture Blueprint

> **DOCUMENT STATUS**: AUTHORITATIVE PLANNING ARTIFACT  
> **SCOPE**: SOI Knowledge Center — Workstream B (Knowledge Space Foundation / Milestone M2)  
> **DELIVERY DISCIPLINE**: STRICT SINGLE SOURCE OF TRUTH — ZERO HARDCODED CONTEXTS  

---

## 1. Executive Purpose & Governance Rules

This document establishes a strictly isolated, small-task decomposition for the complete implementation of **Workstream B: Knowledge Space Foundation**. It builds directly upon the pre-scaffolded architectural contracts, interfaces, and database schemas established by the Team Lead, defining explicit file boundaries, preserving backward compatibility, and preventing regressions across the platform.

### Core Hard Rules:
1. **Single Source of Truth Flow**:
   $$\text{Database (`soi_spaces`, `soi_space_sections`)} \longleftrightarrow \text{Domain Services} \longleftrightarrow \text{EditorApi / Admin} \longleftrightarrow \text{Editor Inspector} \longleftrightarrow \text{ContentStore}$$
   No duplicate arrays, shadow mock data, or parallel save paths. All space and taxonomy records originate from the database.
2. **Additive Architecture (No Hard-Coding)**:
   New department libraries (e.g., HR, IT, Finance) and technical products (e.g., Accounts Directory, HRMS, Mail) must be added purely via **data/configuration and content** through the admin UI. Hard-coding `/hr`, `/it`, or specific product logic into PHP controllers is strictly prohibited.
3. **One Platform / One Editor / Multiple Contexts**:
   The editor is platform infrastructure. General Docs, Libraries, and Technical Documentation must all consume the exact same editor and document engine. Do not fork separate authoring systems per space type.
4. **Backward Compatibility & Non-Destructive Migration**:
   Existing pages (`soi_pages`) and posts (`soi_posts`) must remain completely valid, readable, and editable. Zero data loss during space assignment or migration.
5. **Validation Standard**:
   Every task requires **Static Validation (`php -l`) + Automated Unit/Contract Testing + Live Browser Acceptance**.

---

## 2. Workspace Boundary Audit & Runtime Tracing

### 2.1 The Knowledge Space Authority Boundary
The actual runtime for Knowledge Space foundation consists of:
- **Database Layer**:
  - `core/Spaces/SpaceSchema.php` (Idempotent runner ensuring tables and columns)
  - `install/schema.sql` (Fresh install tables: `soi_spaces`, `soi_space_sections`)
  - `update.sql` (Upgrade migration script)
- **Domain Service Layer (`core/Spaces/`)**:
  - Contracts: `KnowledgeSpaceServiceInterface.php`, `TaxonomyServiceInterface.php`, `SpaceDocumentServiceInterface.php`
  - Implementation Classes: `KnowledgeSpaceService.php`, `TaxonomyService.php`, `SpaceDocumentService.php`
- **Admin Management & API**:
  - Admin UI Controller: `admin/spaces.php`
  - Admin AJAX Endpoint: `admin/spaces-api.php`
  - Admin Navigation: `admin/partials/header.php`
- **Enterprise Editor Integration**:
  - Host Markup: `admin/partials/structured-editor.php` (Space/Section/Version controls)
  - Client Runtime: `admin/assets/editor/ui/inspector-shell.js` (`bindSpaceControls`)
  - Client Serializer: `admin/assets/editor/kc-editor.js` (`payload.space_id`, `payload.section_id`, `payload.doc_version`)
  - Save Pipeline: `core/Content/EditorApi.php`, `core/Content/ContentService.php`, `core/Content/ContentStore.php`
- **Public Reader & Routing**:
  - Front Controller & Router: `core/App.php`, `core/Router.php`
  - Public Reader Template: `templates/reader-shell.php`, `assets/kc-reader.js`, `assets/kc-reader.css`

### 2.2 Non-Space Domains (Strictly Isolated / Excluded)
The following files and directories belong to non-space domains and **MUST NOT BE TOUCHED** during Workstream B tasks:
- **Editor Vendor Runtimes**: `admin/assets/editor/vendor/` (`editorjs.js`, `table.js`, `code.js`, etc.)
- **Central Auth & SAML**: `core/SoiCentralAuth.php`, `saml/`, `soi-central/`, `admin/soi-central.php`
- **Unrelated Admin Modules**: `admin/appearance.php`, `admin/plugins.php`, `admin/security.php`, `admin/smtp.php`, `admin/updates.php`

---

## 3. Problem Classification & Task Decomposition

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                             TASK CLASSIFICATION                             │
├───────────────────────┬───────────────────────────┬─────────────────────────┤
│ Classification        │ Task IDs                  │ Primary Focus Area      │
├───────────────────────┼───────────────────────────┼─────────────────────────┤
│ A. Core Data & CRUD   │ KS-01                     │ KnowledgeSpaceService   │
│ B. Admin Interface    │ KS-02                     │ admin/spaces.php (CRUD) │
│ C. Taxonomy & Trees   │ KS-03                     │ TaxonomyService & Nav   │
│ D. Editor Integration │ KS-04                     │ Inspector & Save Flow   │
│ E. Legacy Migration   │ KS-05                     │ Default Space Seeding   │
│ F. Public Routing     │ KS-06                     │ Reader Shell & Router   │
│ G. Integration & QA   │ KS-07                     │ M2 Acceptance Gate      │
└───────────────────────┴───────────────────────────┴─────────────────────────┘
```

---

## 4. Detailed Task Briefings (KS-01 through KS-07)

### [KS-01] Space Domain Service CRUD & Cache Engine
- **Category**: Core Data & Domain Services
- **Assigned Role**: Developer 1 (Backend Core)
- **Phase**: Phase 1 (Foundation) | **Priority**: Critical
- **Dependencies**: None (Contract and schema pre-scaffolded) | **Can Run Independently**: YES
- **Problem Statement**: `core/Spaces/KnowledgeSpaceService.php` contains skeleton method stubs that return empty arrays or mock data. It must be connected directly to `soi_spaces` table with full CRUD, caching, and slug validation.
- **Investigation Scope**:
  - `core/Spaces/KnowledgeSpaceServiceInterface.php`: verify all contract signatures.
  - `core/Spaces/SpaceSchema.php`: verify column names and indexes on `soi_spaces`.
  - `core/Database.php` & `core/Cache.php`: inspect DB helper functions and cache invalidation.
- **Implementation Scope**:
  - Implement `createSpace(array $data)`: validate unique slug, required title, enum type (`general_docs`, `libraries`, `tech`), insert record, return inserted ID.
  - Implement `getSpace(int $id)` and `getSpaceBySlug(string $slug)`: fetch record from DB with caching (`Cache::get`/`Cache::set`).
  - Implement `listSpaces(array $filter)`: query with optional filtering by `type`, `status`, `visibility`, ordered by `sort_order ASC, title ASC`.
  - Implement `updateSpace(int $id, array $data)` and `deleteSpace(int $id)`: update database, purge related cache keys.
- **Files Involved**:
  - Primary: `core/Spaces/KnowledgeSpaceService.php`
  - Secondary: `tests/spaces/contract-tests.php`
  - Do NOT touch: Editor tool classes or `core/App.php`.

---

### [KS-02] Admin Space Management Interface (Directory & Modals)
- **Category**: Admin Interface & Space Configuration
- **Assigned Role**: Developer 1 (Admin UI)
- **Phase**: Phase 2 (Management UI) | **Priority**: High
- **Dependencies**: KS-01 (Working KnowledgeSpaceService) | **Can Run Independently**: NO
- **Problem Statement**: `admin/spaces.php` currently displays a placeholder empty state. Administrators need an interactive directory table listing all spaces with creation, editing, and archiving capabilities.
- **Investigation Scope**:
  - `admin/spaces.php`: inspect action router and tab markup.
  - `admin/partials/header.php` and `admin/partials/footer.php`: maintain admin styling and responsive layout.
- **Implementation Scope**:
  - Replace the placeholder in Tab 1 ("Spaces Directory") with an interactive table:
    - Columns: Icon, Title & Slug, Type badge (`General Docs`, `Library`, `Tech`), Visibility, Status, Actions (Edit, Taxonomy, Delete).
  - Build "+ Create Space" modal / slide-over form:
    - Inputs: Title, Slug (auto-slugify), Type dropdown, Icon badge, Description textarea, Visibility radio (`public`, `authenticated`, `restricted`).
  - Wire POST handlers in `admin/spaces.php` for `save_space` and `delete_space`.
  - Ensure CSRF tokens and permissions are strictly enforced.
- **Files Involved**:
  - Primary: `admin/spaces.php`
  - Secondary: `admin/spaces-api.php`
  - Do NOT touch: Editor canvas or `structured-editor.php`.

---

### [KS-03] Space Taxonomy & Section Hierarchy Builder
- **Category**: Taxonomy & Tree Navigation
- **Assigned Role**: Developer 2 (Taxonomy & Navigation)
- **Phase**: Phase 2 (Taxonomy) | **Priority**: High
- **Dependencies**: KS-01 | **Can Run Independently**: YES
- **Problem Statement**: Knowledge spaces need hierarchical section grouping (`Space -> Section -> Sub-section -> Document`). `TaxonomyService.php` has stub methods and lacks tree assembly and reordering logic.
- **Investigation Scope**:
  - `core/Spaces/TaxonomyServiceInterface.php`: inspect interface requirements.
  - `core/Spaces/SpaceSchema.php`: check `soi_space_sections` schema (`space_id`, `parent_id`, `title`, `slug`, `sort_order`).
- **Implementation Scope**:
  - In `core/Spaces/TaxonomyService.php`:
    - Implement `createSection`, `updateSection`, `deleteSection`, `getSectionsBySpace`.
    - Implement `buildNavigationTree(int $spaceId)`: query sections and assigned documents, assembling a recursive tree structure with active indicators.
  - In `admin/spaces.php` (Tab 2: "Taxonomy & Navigation Trees"):
    - Build Space selector dropdown to switch between spaces.
    - Render interactive section tree with "+ Add Section" button.
    - Support sorting/reordering via drag-and-drop or sort-order inputs.
  - In `admin/spaces-api.php`:
    - Handle `list_sections`, `save_section`, and `reorder_sections` AJAX requests.
- **Files Involved**:
  - Primary: `core/Spaces/TaxonomyService.php`, `admin/spaces.php` (Tab 2), `admin/spaces-api.php`
  - Secondary: `tests/spaces/taxonomy-tests.php`
  - Do NOT touch: `core/Content/Blocks/` or `kc-editor.js`.

---

### [KS-04] Editor Workspace Space Context & Dynamic Inspector Synchronization
- **Category**: Editor Integration & Content Pipeline
- **Assigned Role**: Developer 3 (Editor Integration)
- **Phase**: Phase 3 (Editor Connection) | **Priority**: Critical
- **Dependencies**: KS-01, KS-03 | **Can Run Independently**: NO
- **Problem Statement**: When authors create or edit a document in the Enterprise Editor, the Document tab in the Inspector must dynamically load available Knowledge Spaces, cascade relevant Sections, toggle Tech Version fields, and save atomically.
- **Investigation Scope**:
  - `admin/assets/editor/ui/inspector-shell.js`: inspect `bindSpaceControls()`.
  - `admin/partials/structured-editor.php`: inspect `#kc-doc-space`, `#kc-doc-section`, `#kc-doc-version`.
  - `core/Content/EditorApi.php`: inspect `spaces_list` and `sections_list` endpoints.
- **Implementation Scope**:
  - In `inspector-shell.js` (`bindSpaceControls`):
    - When `#kc-doc-space` changes, fetch sections via `GET admin/editor-api.php?action=sections_list&space_id={id}` and populate `#kc-doc-section`.
    - If the selected space is of type `tech`, display `#kc-version-field-wrap`; otherwise hide it.
    - Update live preview permalink below slug field (`kc.soi.co.in/tech/{product-slug}/{doc-slug}`).
  - In `kc-editor.js`:
    - Verify that `space_id`, `section_id`, and `doc_version` are included in save/autosave payloads.
  - In `ContentStore.php`:
    - Ensure `space_id`, `section_id`, and `doc_version` are saved and returned on document reload.
- **Files Involved**:
  - Primary: `admin/assets/editor/ui/inspector-shell.js`, `admin/partials/structured-editor.php`
  - Secondary: `core/Content/EditorApi.php`, `core/Content/ContentStore.php`
  - Do NOT touch: Block tools or Editor.js vendor runtime.

---

### [KS-05] Legacy Document Compatibility & Initial Space Seeding
- **Category**: Legacy Compatibility & Migration
- **Assigned Role**: Developer 3 (Migration & Data Safety)
- **Phase**: Phase 3 (Migration) | **Priority**: High
- **Dependencies**: KS-01 | **Can Run Independently**: YES
- **Problem Statement**: Existing `soi_pages` and `soi_posts` have `space_id = NULL`. Unassigned legacy documents must be automatically mapped to canonical spaces so no content is stranded.
- **Investigation Scope**:
  - `core/Spaces/SpaceDocumentService.php`: inspect `migrateLegacyDocuments()`.
  - Existing database contents of `soi_pages` and `soi_posts`.
- **Implementation Scope**:
  - In `core/Spaces/SpaceDocumentService.php`:
    - Seed standard initial spaces if table is empty:
      1. `general-docs` (Type: `general_docs`, Title: `General Docs`)
      2. `hr-library` (Type: `libraries`, Title: `HR Library`)
      3. `it-library` (Type: `libraries`, Title: `IT Library`)
      4. `accounts-directory` (Type: `tech`, Title: `Accounts Directory`)
      5. `hrms` (Type: `tech`, Title: `HRMS`)
    - Implement `migrateLegacyDocuments()`:
      - Assign all existing unassigned `soi_pages` (`space_id IS NULL`) to `general-docs`.
      - Assign existing `soi_posts` to their respective category libraries or `general-docs`.
      - Non-destructive: Preserve existing slugs, titles, and body content exactly.
- **Files Involved**:
  - Primary: `core/Spaces/SpaceDocumentService.php`
  - Secondary: `core/Spaces/SpaceSchema.php`
  - Do NOT touch: Admin controllers or public reader CSS.

---

### [KS-06] Public Reader Shell Dynamic Routing & Navigation Binding
- **Category**: Public Frontend & Route Resolution
- **Assigned Role**: Developer 4 (Routing & Reader Shell)
- **Phase**: Phase 4 (Reader Integration) | **Priority**: High
- **Dependencies**: KS-01, KS-03, KS-05 | **Can Run Independently**: NO
- **Problem Statement**: Reader requests for `/docs/...`, `/library/{library-slug}/...`, and `/tech/{product-slug}/...` must dynamically resolve the target document from the database and render the space navigation tree in `templates/reader-shell.php`.
- **Investigation Scope**:
  - `core/App.php`: inspect `resolveTemplate()` space routing hook.
  - `templates/reader-shell.php`: inspect how sidebar navigation tree is rendered.
  - `assets/kc-reader.js`: verify active section highlighting.
- **Implementation Scope**:
  - In `core/App.php` (`resolveTemplate`):
    - Parse URL patterns:
      - `/docs` or `/docs/{section}/{slug}`
      - `/library/{library-slug}` or `/library/{library-slug}/{section}/{slug}`
      - `/tech/{product-slug}` or `/tech/{product-slug}/{section}/{slug}`
    - Query document by resolved slug and space ID.
    - Query navigation tree via `TaxonomyService::buildNavigationTree($space['id'])`.
    - Pass `$space`, `$sections`, `$document`, and breadcrumbs to `templates/reader-shell.php`.
  - Ensure canonical render parity: rendered HTML matches preview exactly.
  - Ensure non-existent slugs return clean 404 template.
- **Files Involved**:
  - Primary: `core/App.php`, `templates/reader-shell.php`
  - Secondary: `core/Spaces/SpaceDocumentService.php`
  - Do NOT touch: Editor admin JS or authoring styles.

---

### [KS-07] End-to-End Regression Verification & Milestone M2 Acceptance Gate
- **Category**: Integration & Quality Assurance
- **Assigned Role**: Team Lead & QA
- **Phase**: Phase 5 (Acceptance Gate) | **Priority**: Critical
- **Dependencies**: Completion of KS-01 through KS-06
- **Problem Statement**: Validate that Milestone M2 acceptance criteria are 100% satisfied without regression in the Enterprise Authoring Suite (1.1.0) or existing test suite.
- **Scope**:
  - **Live Acceptance Gate Test**:
    1. Log into Admin and navigate to Knowledge Spaces (`admin/spaces.php`).
    2. Create a brand-new Technical Product space (e.g. `Files Service`, slug `files-service`, type `tech`) **without modifying any PHP code**.
    3. Add sections (`Getting Started`, `API Reference`) in the Taxonomy Builder.
    4. Open Enterprise Editor, author a new article, assign it to `Files Service` and section `Getting Started`.
    5. Save and publish.
    6. Visit `/tech/files-service/getting-started/{doc-slug}` on the public reader.
    7. Verify breadcrumb, title, rendered structured blocks, and sidebar navigation tree.
  - Run full test runner: `php tests/editor/run.php` (must pass 253+ tests with 0 failures).
- **Files Involved**:
  - `tests/editor/run.php`, all Workstream B files.

---

## 5. Task Dependency Graph & Sequencing

```
                             PHASE 0: TEAM LEAD CONTRACT SCAFFOLD
                       [SpaceSchema, Interfaces, Skeletons, Wiring]
                                           │
                                           ▼
                               PHASE 1: DOMAIN SERVICES
                       [KS-01] KnowledgeSpaceService CRUD Engine
                                           │
                        ┌──────────────────┴──────────────────┐
                        ▼                                     ▼
             PHASE 2: ADMIN MANAGEMENT             PHASE 2: TAXONOMY
          [KS-02] Space Admin Interface         [KS-03] Section Builder
            (Directory, Modals, Forms)             (Trees & Reordering)
                        │                                     │
                        └──────────────────┬──────────────────┘
                                           │
                        ┌──────────────────┴──────────────────┐
                        ▼                                     ▼
             PHASE 3: EDITOR INTEGRATION           PHASE 3: MIGRATION
          [KS-04] Inspector Space Context       [KS-05] Legacy Content
            (Cascading Pickers & Save)             (Space Auto-Seeding)
                        │                                     │
                        └──────────────────┬──────────────────┘
                                           │
                                           ▼
                               PHASE 4: PUBLIC READER
                       [KS-06] Dynamic Routing & Reader Nav
                                           │
                                           ▼
                               PHASE 5: FINAL ACCEPTANCE
                       [KS-07] Milestone M2 Acceptance Gate
```

---

## 6. Execution Matrix: Independent vs Sequenced Tasks

### 6.1 Parallelizable Tasks
- **KS-01** (Space Service CRUD) can begin immediately.
- **KS-03** (Taxonomy Service) can be developed in parallel with KS-01 using pre-scaffolded interfaces.
- **KS-05** (Legacy Seeding Logic) can be developed independently using `SpaceDocumentService`.

### 6.2 Sequenced Tasks
- **KS-02** (Admin GUI) requires KS-01 methods to fetch and save spaces.
- **KS-04** (Editor Inspector) requires KS-01 and KS-03 AJAX endpoints to populate dropdowns.
- **KS-06** (Reader Shell) requires KS-01, KS-03, and KS-05 data resolution.
- **KS-07** (Acceptance Gate) must run strictly after KS-01 through KS-06 are complete.

### 6.3 Shared-File Coordination Matrix

| Shared File | Tasks Modifying File | Specific Concern Per Task | Conflict Prevention Rule |
| :--- | :--- | :--- | :--- |
| `admin/spaces.php` | KS-02, KS-03 | KS-02: Space Directory Tab<br>KS-03: Taxonomy Builder Tab | Edit inside respective tab `if ($activeTab === ...)` blocks. |
| `admin/spaces-api.php` | KS-02, KS-03 | KS-02: `list_spaces`<br>KS-03: `list_sections`, `reorder` | Add discrete `case` branches in action router. |
| `admin/assets/editor/ui/inspector-shell.js` | KS-04 | KS-04: `bindSpaceControls` | Keep logic isolated within `bindSpaceControls()` method. |
| `core/App.php` | KS-06 | KS-06: Space route resolution | Isolate edits to the `// Knowledge Space Canonical Routes` block. |

---

## 7. Complete Test & Acceptance Matrix

| Task ID | Test Name | Verification Procedure | Expected Result | Regression Risk |
| :---: | :--- | :--- | :--- | :--- |
| **KS-01** | Space CRUD & Cache | Unit test `KnowledgeSpaceService` methods via CLI. | Records persist to `soi_spaces`; unique slugs enforced; cache invalidates. | Low |
| **KS-02** | Space Admin Directory | Open `admin/spaces.php`; create, edit, and delete space. | Modal submits cleanly; table reflects spaces; CSRF prevents unauthorized post. | Low |
| **KS-03** | Taxonomy Tree Builder | Create sections and subsections; reorder in Admin. | `buildNavigationTree` returns hierarchical tree; sort order preserved. | Low |
| **KS-04** | Editor Space Selector | Open editor; change space; verify section cascading; save. | `space_id`, `section_id`, and `doc_version` persist in DB; reload displays exact state. | Medium |
| **KS-05** | Legacy Migration | Run `migrateLegacyDocuments()` on test database. | Unassigned pages mapped to `general-docs`; zero data loss. | Low |
| **KS-06** | Reader Dynamic Routing | Visit `/docs/{sec}/{slug}`, `/library/{lib}/{sec}/{slug}`, `/tech/{prod}/{sec}/{slug}`. | Template resolves target document; sidebar tree renders correctly; 404 on missing slug. | Medium |
| **KS-07** | Milestone M2 Acceptance Gate | End-to-end walkthrough: create tech space $\rightarrow$ write doc $\rightarrow$ view reader. | Complete workflow functions with zero code modifications and 100% test pass rate. | Critical |

---

## 8. Milestone M2 Acceptance Standard

Before closing Workstream B (Milestone M2), the following gates must be satisfied:
1. **Zero Code Changes for New Contexts**: An administrator can create a new Library (e.g. `Finance Library`) or Tech Product (e.g. `Meetings Docs`), assign sections, author documents, and publish them with full sidebar navigation without modifying any PHP, CSS, or JS files.
2. **Canonical URL Integrity**: All routes adhere strictly to Appendix A (`/docs/...`, `/library/{slug}/...`, `/tech/{slug}/...`).
3. **Canonical Render Parity**: Document appearance in the editor preview matches the public reader view 100%.
4. **Single Source of Truth**: All space data resides in MySQL (`soi_spaces`, `soi_space_sections`); zero static array dependencies remain.
5. **Existing Suite**: `php tests/editor/run.php` passes with 253+ passing tests and zero failures.
