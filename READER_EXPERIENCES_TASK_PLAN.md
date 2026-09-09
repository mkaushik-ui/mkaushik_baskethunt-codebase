# SOI Knowledge Center — Reader Experiences Task Plan & Architecture Blueprint

> **DOCUMENT STATUS**: AUTHORITATIVE PLANNING ARTIFACT  
> **SCOPE**: SOI Knowledge Center — Workstream C (Reader Experiences & Public Shell / Milestone M3)  
> **TARGET DOMAIN**: `kc.soi.co.in` (Isolated Public Reader Authority Boundary)  
> **DELIVERY DISCIPLINE**: STRICT SINGLE SOURCE OF TRUTH — ZERO CROSS-DOMAIN BLAST RADIUS  

---

## 1. Executive Purpose & Governance Rules

This document establishes a strictly isolated, small-task decomposition for the complete implementation of **Workstream C: Reader Experiences & Public Shell**. It guarantees that public readers across all Knowledge Spaces (`/docs`, `/library/*`, `/tech/*`) receive a responsive reading experience with **100% canonical render parity** with the Enterprise Authoring Suite (1.1.0), without altering or compromising any core authoring, central authentication, or administrative subsystems.

### Core Hard Rules:
1. **Single Source of Truth Flow**:
   $$\text{Database (`soi_spaces`, `soi_space_sections`, `soi_pages`)} \longleftrightarrow \text{Domain Services} \longleftrightarrow \text{Front Controller (`core/App.php`)} \longleftrightarrow \text{DocumentRenderer} \longleftrightarrow \text{Reader Shell}$$
   No shadow templates, no duplicate static arrays, and no disconnected styling forks. All spaces, section trees, documents, and structured block elements originate from the database and canonical `BlockRegistry`.
2. **One Platform / One Document Engine / Multiple Reading Contexts**:
   General Docs (`/docs`), Department Libraries (`/library/{slug}`), and Technical Product Documentation (`/tech/{slug}`) consume the exact same reader shell layout and block rendering pipeline. Context-specific features (such as technical product version dropdowns) activate strictly through data and space metadata.
3. **Canonical Render Parity (Zero Visual Drift)**:
   Every structured block (Callouts, Steps, API Endpoints, Cards, Key/Values, Code Groups, Tabs, Accordions, FAQ, Definition Lists, KBD, Tables) authored in the Editor must render identically in the public Reader view.
4. **Domain Scope Isolation (`kc.soi.co.in`)**:
   All changes are restricted strictly to public reader frontend templates, public assets (`assets/kc-*.css`, `assets/kc-*.js`), reader tests, and reader route dispatchers. Non-reader domains (Authoring Canvas, Inspector, SAML SSO, Central Auth, Admin modules) must remain untouched.
5. **Validation Standard**:
   Every task requires **Static Validation (`php -l`) + Automated Test Suite (`php tests/spaces/reader-tests.php`) + Live Browser Acceptance**.

---

## 2. Workspace Boundary Audit & Runtime Tracing

### 2.1 The Public Reader Authority Boundary (`kc.soi.co.in`)
The runtime for Workstream C consists strictly of:
- **Reader Shell Host Template**:
  - `templates/reader-shell.php`
- **Public Client Stylesheets & Micro-Runtimes (`assets/`)**:
  - Shared Canonical Block Stylesheet: `assets/kc-blocks.css` (Task RC-01)
  - Reader Layout & Typography Stylesheet: `assets/kc-reader.css` (Tasks RC-03, RC-05)
  - Reader Navigation & Layout Runtime: `assets/kc-reader.js` (Tasks RC-02, RC-03, RC-04, RC-05)
  - Public Structured Block Interactivity: `assets/kc-public.js` (Task RC-02)
- **Backend Dispatch & Rendering Engine**:
  - Route Dispatcher: `core/App.php` (`resolveReaderShellRoute`)
  - Server-Side Block Dispatcher: `core/Content/DocumentRenderer.php`
  - Space Resolution Services: `core/Spaces/SpaceDocumentService.php`, `core/Spaces/TaxonomyService.php`
- **Verification & Acceptance Test Suite**:
  - Automated Reader Test Suite: `tests/spaces/reader-tests.php`
  - Space Regression Suite: `tests/spaces/run.php`

### 2.2 Non-Reader Domains (Strictly Isolated / Excluded)
The following files and directories belong to other domains and **MUST NOT BE MODIFIED** during Workstream C tasks:
- **Editor Vendor Runtimes & Canvas**: `admin/assets/editor/vendor/`, `admin/assets/editor/layout/`, `admin/assets/editor/blocks/`, `admin/assets/editor/ui/`
- **Authoring Admin UI**: `admin/partials/structured-editor.php`, `admin/pages.php`, `admin/posts.php`, `admin/spaces.php`
- **Central Auth & SAML**: `core/SoiCentralAuth.php`, `saml/`, `soi-central/`, `admin/soi-central.php`
- **Unrelated Admin Modules**: `admin/appearance.php`, `admin/plugins.php`, `admin/security.php`, `admin/smtp.php`, `admin/updates.php`

---

## 3. Problem Classification & Task Decomposition

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                       WORKSTREAM C TASK CLASSIFICATION                      │
├───────────────────────┬───────────────────────────┬─────────────────────────┤
│ Classification        │ Task IDs                  │ Primary Focus Area      │
├───────────────────────┼───────────────────────────┼─────────────────────────┤
│ A. Block Visual Parity│ RC-01                     │ assets/kc-blocks.css    │
│ B. Block Interactivity│ RC-02                     │ assets/kc-public.js     │
│ C. Navigation & TOC   │ RC-03                     │ "On this page" TOC Rail │
│ D. Tech Space Versions│ RC-04                     │ Versioning & Filter     │
│ E. Reader UI & Themes │ RC-05                     │ Dark / Light Mode       │
│ F. Automated QA       │ RC-06                     │ reader-tests.php Suite  │
└───────────────────────┴───────────────────────────┴─────────────────────────┘
```

---

## 4. Detailed Task Briefings (RC-01 through RC-06)

---

### [RC-01] Canonical Block Styling & Design System Parity Asset
- **Category**: Block Visual Parity & Design System
- **Assigned Role**: Developer 1 (Frontend Styling & Design System)
- **Phase**: Phase 1 (Foundation) | **Priority**: Critical
- **Dependencies**: None | **Can Run Independently**: YES
- **Problem Statement**: Structured blocks (Callouts, Steps, API Endpoints, Key/Values, Cards, Tables, Quotes, Dividers, KBD) lose their styling in the public reader shell because `templates/reader-shell.php` only loaded basic typography CSS. A single shared stylesheet must style all blocks identically across Editor preview and Public Reader.
- **Investigation Scope**:
  - `assets/kc-blocks.css`: verify CSS custom properties and block classes.
  - `core/Content/Blocks/*`: check generated HTML classes (`kc-callout`, `kc-block-api`, `kc-block-steps`, `kc-block-tabs`, etc.).
  - `templates/reader-shell.php`: verify inclusion of `<link rel="stylesheet" href="assets/kc-blocks.css">`.
- **Implementation Scope**:
  - Complete all enterprise block visual styles in `assets/kc-blocks.css`:
    - `.kc-callout` / `.kc-block-callout`: colored left borders, soft background tones (`info`, `note`, `tip`, `warning`, `danger`, `success`).
    - `.kc-block-steps`: numbered circular step badges with continuous subtle connector line and bold step headers.
    - `.kc-block-api`: method badges (`GET` blue, `POST` green, `PUT` amber, `DELETE` red, `PATCH` purple), endpoint code container, params table.
    - `.kc-block-keyvalues`: 2-column key-value reference grid with zebra striping and bold keys.
    - `.kc-block-cards`: responsive card grid with hover elevation (`translateY(-2px)` + soft drop shadow).
    - `.kc-block-kbd`: elevated keyboard shortcut chips with tactile bottom shadow.
    - `.kc-block-deflist`: clean semantic definition lists with responsive mobile stacking.
- **UI/UX Design Guidelines**:
  - **Color Tokens**:
    - Primary Brand: `#2563eb` (Hover: `#1d4ed8`, Soft: `#eff6ff`)
    - Border Default: `#e2e8f0` (Dark mode: `#334155`)
    - Surface Background: `#ffffff` (Dark mode: `#1e293b`)
    - Body Text: `#0f172a` (Dark mode: `#f8fafc`), Muted Text: `#64748b` (Dark mode: `#94a3b8`)
  - **Typography**: Font family `-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif`; Monospace `ui-monospace, Menlo, Monaco, Consolas, monospace`.
  - **Spacing & Radius**: Border radii: `4px` (small badges/kbd), `8px` (cards/callouts), `12px` (api blocks/groups). Margin baseline: `1.5rem` between blocks.
  - **Micro-Interactions**: Hover transitions `0.15s ease-in-out` on cards and links.
- **Files Involved**:
  - Primary: `assets/kc-blocks.css`, `templates/reader-shell.php`
  - Secondary: `tests/spaces/reader-tests.php`
  - Protected: Do NOT touch `admin/assets/editor/` or `core/SoiCentralAuth.php`.

---

### [RC-02] Public Block Interactivity & Code Copy Micro-Runtime
- **Category**: Block Interactivity & Client Runtime
- **Assigned Role**: Developer 2 (Client JS & Interactivity)
- **Phase**: Phase 1 (Interactivity) | **Priority**: High
- **Dependencies**: None | **Can Run Independently**: YES
- **Problem Statement**: Multi-language Code Groups and Interactive Tabs are inert in the public reader. In addition, developers reading technical documentation require a one-click "Copy Code" button on all code snippets with visual copied feedback.
- **Investigation Scope**:
  - `assets/kc-public.js`: inspect ARIA tab switching and keyboard navigation hooks.
  - `assets/kc-reader.js`: inspect initialization lifecycle.
  - `templates/reader-shell.php`: ensure `kc-public.js` is enqueued before `kc-reader.js`.
- **Implementation Scope**:
  - In `assets/kc-public.js`:
    - Ensure Tab and Code Group switching toggles `aria-selected="true"`, updates active tab styles, and toggles `hidden` on corresponding tab panels.
    - Support arrow key navigation (`ArrowRight`/`ArrowLeft`) across tabs.
  - In `assets/kc-reader.js`:
    - Implement automatic "Copy" button injector for all `.kc-block-code pre` and `.kc-codegroup-pre` blocks.
    - On click, copy raw text via `navigator.clipboard.writeText()`, switch button text to `✓ Copied!`, and revert after 2000ms.
    - Graceful fallback for non-secure / non-navigator clipboard environments.
- **UI/UX Design Guidelines**:
  - **Copy Button Styling**: Positioned absolute top-right (`top: 0.6rem; right: 0.6rem;`), semi-transparent background `rgba(255,255,255,0.1)`, border `1px solid rgba(255,255,255,0.2)`, text color `#e2e8f0`, font size `0.75rem`, border-radius `4px`, padding `0.25rem 0.55rem`.
  - **Hover & Active States**: Hover background `rgba(255,255,255,0.2)`, active scale `0.96`.
  - **Accessibility**: Provide `aria-label="Copy code to clipboard"` and announce copy status to screen readers.
- **Files Involved**:
  - Primary: `assets/kc-public.js`, `assets/kc-reader.js`, `templates/reader-shell.php`
  - Secondary: `assets/kc-blocks.css`
  - Protected: Do NOT load heavy third-party vendor libraries.

---

### [RC-03] "On This Page" Table of Contents (TOC) & Scroll-Spy Rail
- **Category**: Navigation & Reader Layout
- **Assigned Role**: Developer 3 (Layout & Navigation)
- **Phase**: Phase 2 (Layout & Navigation) | **Priority**: High
- **Dependencies**: RC-01 | **Can Run Independently**: YES
- **Problem Statement**: Long technical articles and guides lack an in-page navigation outline. Readers cannot jump between document sections or see their current reading position.
- **Investigation Scope**:
  - `core/Content/DocumentRenderer.php`: inspect `extractHeadings($document)`.
  - `templates/reader-shell.php`: inspect main content area grid and right-rail insertion point.
  - `assets/kc-reader.js`: inspect scroll event listener and `IntersectionObserver` support.
- **Implementation Scope**:
  - In `templates/reader-shell.php`:
    - Extract headings using `DocumentRenderer::extractHeadings($document)` when `$document` has structured blocks.
    - Render right-side sticky rail `<aside class="kc-toc" id="kcToc">` with list of H2 and H3 heading anchor links.
    - Automatically hide the TOC rail if the document contains fewer than 2 headings.
  - In `assets/kc-reader.js`:
    - Implement smooth scrolling when TOC anchor links are clicked.
    - Hook `IntersectionObserver` or scroll listener to highlight the active heading link (`.is-active`) as the user scrolls through the article.
- **UI/UX Design Guidelines**:
  - **Layout & Rail Width**: Fixed right width `220px`, sticky position `top: calc(var(--kc-header-height) + 2rem)`, max-height `calc(100vh - 100px)`, overflow-y `auto`.
  - **Heading Indentation**: H2 items font-weight `600`, font-size `0.85rem`, color `#64748b`; H3 items indented with `padding-left: 0.75rem`, font-size `0.8rem`.
  - **Active State**: Left border indicator `2px solid var(--kc-primary)`, color `var(--kc-primary)`, font-weight `700`.
  - **Responsive Behavior**: Automatically hidden on screens $\le 1180\text{px}$ to preserve reading canvas focus.
- **Files Involved**:
  - Primary: `templates/reader-shell.php`, `assets/kc-reader.js`, `assets/kc-reader.css`
  - Secondary: `tests/spaces/reader-tests.php`

---

### [RC-04] Technical Space Product Version Switcher & Quick Navigation Filter
- **Category**: Tech Space Versioning & Search
- **Assigned Role**: Developer 4 (Versioning & Search)
- **Phase**: Phase 2 (Space Customization) | **Priority**: High
- **Dependencies**: None | **Can Run Independently**: YES
- **Problem Statement**: Technical product spaces (`type = 'tech'`) often contain multiple versioned releases (`v1.0`, `v2.0`). Readers need a clean version picker in the header/sidebar, plus a real-time filter input to search long sidebar navigation trees.
- **Investigation Scope**:
  - `core/Spaces/SpaceDocumentService.php`: inspect `soi_space_tech_versions` queries.
  - `core/App.php`: inspect version query parameter handling (`?v=...`).
  - `templates/reader-shell.php`: inspect sidebar header area.
- **Implementation Scope**:
  - In `core/Spaces/SpaceDocumentService.php`:
    - Implement `getSpaceVersions(int $spaceId): array` to fetch all defined release versions for a technical product.
  - In `templates/reader-shell.php`:
    - If `$spaceType === 'tech'`, render the Version Switcher dropdown (`#kcVersionSelect`) in the sidebar header.
    - Render real-time client-side search input `<input type="search" id="kcSidebarSearch" placeholder="Filter topics...">` at the top of the sidebar.
  - In `assets/kc-reader.js`:
    - Filter sidebar `.kc-nav-item` links instantly as user types in `#kcSidebarSearch`, expanding matching section groups and hiding non-matching items.
    - Wire version dropdown change event to reload document under the selected version context.
- **UI/UX Design Guidelines**:
  - **Version Selector UI**: Clean native or custom select box with subtle chevron icon, background `#f1f5f9`, border `1px solid var(--kc-border)`, border-radius `6px`, font-size `0.82rem`, font-weight `600`.
  - **Search Input UI**: Search input with embedded glass icon prefix, height `36px`, border-radius `6px`, padding `0 0.75rem 0 2rem`, placeholder color `#94a3b8`, focus outline `2px solid var(--kc-primary)`. Clear button (`×`) when text is entered.
- **Files Involved**:
  - Primary: `templates/reader-shell.php`, `core/Spaces/SpaceDocumentService.php`, `assets/kc-reader.js`
  - Secondary: `tests/spaces/reader-tests.php`

---

### [RC-05] Accessible Dark / Light Mode Reader Theme Engine
- **Category**: Reader UI & Theming
- **Assigned Role**: Developer 1 (Theme & Visual Polish)
- **Phase**: Phase 3 (Theming & Polish) | **Priority**: Medium
- **Dependencies**: RC-01, RC-03 | **Can Run Independently**: YES
- **Problem Statement**: Reading technical documentation in dark environments causes eye strain. Readers require an instant Dark / Light mode toggle with automatic system preference detection (`prefers-color-scheme`) and persistent `localStorage` memory.
- **Investigation Scope**:
  - `templates/reader-shell.php`: inspect root CSS variables and top header actions.
  - `assets/kc-reader.css` & `assets/kc-blocks.css`: inspect CSS color tokens.
  - `assets/kc-reader.js`: inspect client theme initialization.
- **Implementation Scope**:
  - In `assets/kc-reader.css` & `assets/kc-blocks.css`:
    - Define dark theme variables under `[data-theme="dark"]` / `.theme-dark`:
      - `--kc-bg: #0b0f19`, `--kc-surface: #111827`, `--kc-border: #1f2937`, `--kc-text: #f9fafb`, `--kc-muted: #9ca3af`.
  - In `templates/reader-shell.php`:
    - Add Theme Toggle button `<button id="kcThemeToggle" aria-label="Toggle theme">` with sun/moon SVG icons in the top navigation bar.
    - Add early non-blocking inline `<script>` in `<head>` to apply saved theme from `localStorage` immediately, preventing white-flash on page load.
  - In `assets/kc-reader.js`:
    - Handle toggle click: switch between `dark` and `light`, persist choice in `localStorage.setItem('kc_reader_theme', ...)`.
- **UI/UX Design Guidelines**:
  - **Dark Mode Palette**: Deep slate neutral dark (NOT harsh pure black `#000000`). Contrast ratio must exceed WCAG AA standards (4.5:1 for body text, 3:1 for headings).
  - **Theme Toggle Button**: Circular button `36px x 36px`, display grid place-items center, background `transparent`, hover background `var(--kc-primary-soft)`, border `1px solid var(--kc-border)`, border-radius `50%`, smooth icon rotation transition.
- **Files Involved**:
  - Primary: `templates/reader-shell.php`, `assets/kc-reader.css`, `assets/kc-blocks.css`, `assets/kc-reader.js`
  - Secondary: `tests/spaces/reader-tests.php`

---

### [RC-06] Workstream C Automated Regression & Acceptance Test Suite
- **Category**: QA & Acceptance Verification
- **Assigned Role**: Team Lead & QA
- **Phase**: Phase 4 (Acceptance Gate) | **Priority**: Critical
- **Dependencies**: Completion of RC-01 through RC-05
- **Problem Statement**: Guarantee that all reader enhancements satisfy acceptance criteria, adhere to single source of truth rules, and produce zero regressions in the existing authoring test suite (253 tests).
- **Investigation Scope**:
  - `tests/spaces/reader-tests.php`: inspect automated contract assertions.
  - `tests/spaces/run.php` and `tests/editor/run.php`: verify baseline passes.
- **Implementation Scope**:
  - Implement full test runner in `tests/spaces/reader-tests.php`:
    1. Route resolution tests for `/docs`, `/library/*`, and `/tech/*`.
    2. Path traversal and SQL injection validation tests.
    3. Block HTML class presence tests for all enterprise block providers.
    4. Table of Contents heading extraction assertions.
    5. Version resolution tests for tech spaces.
    6. Verify that `php tests/editor/run.php` passes with 253/253 tests and `tests/spaces/run.php` passes with 32/32 tests.
- **Files Involved**:
  - Primary: `tests/spaces/reader-tests.php`
  - Secondary: `tests/spaces/run.php`, `tests/editor/run.php`

---

## 5. Task Dependency Graph & Sequencing

```
                             PHASE 0: SKELETON & SCAFFOLDING
                    [kc-blocks.css, reader-tests.php, template hooks]
                                            │
                                            ▼
                                 PHASE 1: CORE PARITY
                        ┌───────────────────┴───────────────────┐
                        ▼                                       ▼
             [RC-01] Block CSS Parity               [RC-02] Block Interactivity
             (Design System & Classes)                (Tabs & Code Copy Engine)
                        │                                       │
                        └───────────────────┬───────────────────┘
                                            │
                                            ▼
                              PHASE 2: NAVIGATION & TECH
                        ┌───────────────────┴───────────────────┐
                        ▼                                       ▼
             [RC-03] "On This Page" TOC             [RC-04] Version Switcher
               (Heading Rail & Spy)                   (Tech Spaces & Filter)
                        │                                       │
                        └───────────────────┬───────────────────┘
                                            │
                                            ▼
                                PHASE 3: THEME & POLISH
                             [RC-05] Dark / Light Mode Engine
                                            │
                                            ▼
                                PHASE 4: FINAL ACCEPTANCE
                        [RC-06] Workstream C Acceptance Gate
```

---
Aditya-1,3
Gouransh-2
Pruthvi-4
Sourabh-5

## 6. Execution Matrix: Independent vs Sequenced Tasks

### 6.1 Parallelizable Tasks
- **RC-01** (Block CSS Parity) can be implemented independently using `assets/kc-blocks.css`.
- **RC-02** (Block Interactivity & Copy) can be developed independently using `assets/kc-public.js` and `assets/kc-reader.js`.
- **RC-04** (Version Switcher & Sidebar Search) can be built independently in `SpaceDocumentService` and `kc-reader.js`.

### 6.2 Sequenced Tasks
- **RC-03** (TOC Rail) requires RC-01 layout classes.
- **RC-05** (Dark Mode) requires CSS color tokens established in RC-01 and RC-03.
- **RC-06** (Acceptance Gate) runs strictly after tasks RC-01 through RC-05 are integrated.

### 6.3 Shared-File Coordination Matrix

| Shared File | Tasks Modifying File | Specific Concern Per Task | Conflict Prevention Rule |
| :--- | :--- | :--- | :--- |
| `templates/reader-shell.php` | RC-01, RC-03, RC-04, RC-05 | RC-01: Block CSS link<br>RC-03: Right-rail TOC markup<br>RC-04: Version selector & search input<br>RC-05: Theme toggle button in header | Insert into designated annotated HTML slots (`#kcSidebarSearch`, `#kcTocRail`, `#kcThemeToggle`). |
| `assets/kc-reader.js` | RC-02, RC-03, RC-04, RC-05 | RC-02: Code copy button<br>RC-03: TOC scroll-spy<br>RC-04: Quick search filter<br>RC-05: Theme toggle handler | Encapsulate features in discrete named functions inside the IIFE. |
| `assets/kc-reader.css` | RC-03, RC-05 | RC-03: TOC rail sticky layout<br>RC-05: Dark mode CSS variables | Keep TOC styles under `/* TOC */` and theme tokens under `[data-theme="dark"]`. |

---

## 7. Complete Test & Acceptance Matrix

| Task ID | Test Name | Verification Procedure | Expected Result | Regression Risk |
| :---: | :--- | :--- | :--- | :--- |
| **RC-01** | Block Visual Parity | Open reader page with Callout, Steps, API Endpoint, Cards. | Rendered blocks match Editor preview styling 100%. | Low |
| **RC-02** | Tab & Code Interactivity | Click Tab panels and multi-lang Code Groups; click Copy Code. | Panels toggle without page reload; code copies to clipboard with "Copied!" feedback. | Low |
| **RC-03** | "On This Page" TOC | Load article with H2/H3 headings; scroll page. | TOC renders on right rail; active heading highlights dynamically on scroll. | Medium |
| **RC-04** | Tech Versioning & Filter | Type in sidebar filter; change version dropdown in tech space. | Sidebar items filter instantly; version parameter resolves matching doc version. | Low |
| **RC-05** | Dark Mode Switcher | Click theme toggle button in reader header; refresh page. | Color scheme switches to dark slate; preference persists across reload. | Low |
| **RC-06** | Acceptance Gate | Run `php tests/spaces/reader-tests.php`, `php tests/spaces/run.php`, and `php tests/editor/run.php`. | 100% test pass rate with 0 regressions. | Critical |

---

## 8. Milestone M3 Acceptance Standard

Before closing Workstream C (Milestone M3), the following acceptance gates must be satisfied:
1. **Single Source of Truth Integrity**: All space, taxonomy, and structured document data originates from MySQL (`soi_spaces`, `soi_space_sections`, `soi_pages`); zero hardcoded routes or static data arrays exist.
2. **Canonical Visual Parity**: All 18 block types render identically between the Enterprise Authoring Suite (1.1.0) and the Public Reader Shell.
3. **Responsive Tri-Pane Layout**: The Reader Shell gracefully adapts across all viewport sizes (Desktop $\ge 1200\text{px}$ tri-pane with TOC, Tablet $768\text{px}-1180\text{px}$ dual-pane, Mobile $<768\text{px}$ single-pane with slide-over drawer).
4. **Scope Isolation**: Zero modifications to authoring canvas, SAML SSO, or administrative core.
5. **Zero Test Regressions**: All test suites (`reader-tests.php`, `tests/spaces/run.php`, `tests/editor/run.php`) pass with 100% success rate.
