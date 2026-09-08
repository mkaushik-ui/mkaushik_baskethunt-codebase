<?php
declare(strict_types=1);

/**
 * Admin — Knowledge Spaces Management Interface (Directory & Modals)
 * Task KS-02: Interactive Directory Table, Slide-over Create/Edit Modal, Slug Generation, and Action Handlers.
 */
$pageTitle = 'Knowledge Spaces';
$activeNav = 'spaces';
$pageContentClass = 'page-content--fluid';

if (!defined('SOI_ROOT')) {
    define('SOI_ROOT', dirname(__DIR__));
}

if (file_exists(SOI_ROOT . '/config/config.php')) {
    require_once SOI_ROOT . '/config/config.php';
}
if (file_exists(SOI_ROOT . '/core/helpers.php')) {
    require_once SOI_ROOT . '/core/helpers.php';
}

spl_autoload_register(function (string $class) {
    $file = SOI_ROOT . '/core/' . str_replace(['SOI\\Core\\', '\\'], ['', '/'], $class) . '.php';
    if (file_exists($file)) require_once $file;
});

use SOI\Core\{Database, Auth, Cache};
use SOI\Core\Spaces\{KnowledgeSpaceService, SpaceSchema};

// Connect DB if configured and not already connected
if (defined('SOI_DB_HOST') && class_exists(Database::class) && !Database::isConnected()) {
    Database::connect([
        'host'   => SOI_DB_HOST,
        'name'   => SOI_DB_NAME,
        'user'   => SOI_DB_USER,
        'pass'   => SOI_DB_PASS,
        'port'   => SOI_DB_PORT,
        'prefix' => SOI_DB_PREFIX,
    ]);
}

if (class_exists(Auth::class) && Database::isConnected()) {
    Auth::init();
    if (method_exists(Auth::class, 'requireAuth')) {
        Auth::requireAuth('author'); // Reading spaces requires author; writing requires editor/admin
    }
}

if (class_exists(SpaceSchema::class)) {
    SpaceSchema::ensure();
}

$service = new KnowledgeSpaceService();

// ===========================
// Handle POST Actions
// ===========================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = (string) ($_POST['_csrf'] ?? $_POST['csrf_token'] ?? '');
    if (class_exists(Auth::class) && method_exists(Auth::class, 'verifyCsrf')) {
        if (!Auth::verifyCsrf($csrfToken)) {
            die('CSRF token validation failed.');
        }
    }

    $postAction = (string) ($_POST['_action'] ?? $_POST['action'] ?? '');

    // Action: Save Space (Create / Update) - supports 'save_space' and 'savespace'
    if ($postAction === 'save_space' || $postAction === 'savespace') {
        if (class_exists(Auth::class) && method_exists(Auth::class, 'requireAuth')) {
            Auth::requireAuth('editor');
        }

        $editId = (int) ($_POST['id'] ?? 0);
        $data = [
            'title'       => trim((string) ($_POST['title'] ?? '')),
            'slug'        => trim((string) ($_POST['slug'] ?? '')),
            'type'        => (string) ($_POST['type'] ?? SpaceSchema::TYPE_GENERALDOCS),
            'icon'        => trim((string) ($_POST['icon'] ?? '')),
            'description' => trim((string) ($_POST['description'] ?? '')),
            'visibility'  => (string) ($_POST['visibility'] ?? SpaceSchema::VISIBILITY_PUBLIC),
            'status'      => (string) ($_POST['status'] ?? SpaceSchema::STATUS_PUBLISHED),
            'sort_order'  => (int) ($_POST['sort_order'] ?? $_POST['sortorder'] ?? 0),
            'sortorder'   => (int) ($_POST['sort_order'] ?? $_POST['sortorder'] ?? 0),
        ];

        try {
            if ($editId > 0) {
                $service->updateSpace($editId, $data);
                if (function_exists('soi_flash')) {
                    soi_flash('success', "Knowledge space '{$data['title']}' updated successfully.");
                }
            } else {
                $newId = $service->createSpace($data);
                if (function_exists('soi_flash')) {
                    soi_flash('success', "Knowledge space '{$data['title']}' created successfully.");
                }
            }
        } catch (\InvalidArgumentException $e) {
            if (function_exists('soi_flash')) {
                soi_flash('error', $e->getMessage());
            }
        } catch (\Throwable $e) {
            if (function_exists('soi_flash')) {
                soi_flash('error', 'Database error: ' . $e->getMessage());
            }
        }

        $redirectUrl = defined('SOI_ADMIN_URL') ? SOI_ADMIN_URL . '/spaces.php' : 'spaces.php';
        if (function_exists('soi_redirect')) {
            soi_redirect($redirectUrl);
        } else {
            header('Location: ' . $redirectUrl);
            exit;
        }
    }

    // Action: Delete Space - supports 'delete_space' and 'deletespace'
    if ($postAction === 'delete_space' || $postAction === 'deletespace') {
        if (class_exists(Auth::class) && method_exists(Auth::class, 'requireAuth')) {
            Auth::requireAuth('admin');
        }

        $delId = (int) ($_POST['id'] ?? 0);
        if ($delId > 0) {
            $space = $service->getSpace($delId);
            if ($space && $service->deleteSpace($delId)) {
                if (function_exists('soi_flash')) {
                    soi_flash('success', "Knowledge space '{$space['title']}' deleted successfully.");
                }
            } else {
                if (function_exists('soi_flash')) {
                    soi_flash('error', 'Failed to delete knowledge space.');
                }
            }
        }
        $redirectUrl = defined('SOI_ADMIN_URL') ? SOI_ADMIN_URL . '/spaces.php' : 'spaces.php';
        if (function_exists('soi_redirect')) {
            soi_redirect($redirectUrl);
        } else {
            header('Location: ' . $redirectUrl);
            exit;
        }
    }

    // Action: Flush Space Caches
    if ($postAction === 'flush_space_cache' || $postAction === 'flushcache') {
        if (class_exists(Auth::class) && method_exists(Auth::class, 'requireAuth')) {
            Auth::requireAuth('editor');
        }
        $service->purgeSpaceCache();
        if (function_exists('soi_flash')) {
            soi_flash('success', 'Knowledge Space transient & page caches flushed.');
        }
        $redirectUrl = defined('SOI_ADMIN_URL') ? SOI_ADMIN_URL . '/spaces.php?tab=settings' : 'spaces.php?tab=settings';
        if (function_exists('soi_redirect')) {
            soi_redirect($redirectUrl);
        } else {
            header('Location: ' . $redirectUrl);
            exit;
        }
    }
}

// Active Tab
$activeTab = function_exists('soi_get') ? soi_get('tab', 'directory') : ($_GET['tab'] ?? 'directory');
if (!in_array($activeTab, ['directory', 'settings', 'taxonomy'], true)) {
    $activeTab = 'directory';
}

// Query parameters for directory filtering
$filterType       = function_exists('soi_get') ? soi_get('type', '') : ($_GET['type'] ?? '');
$filterStatus     = function_exists('soi_get') ? soi_get('status', '') : ($_GET['status'] ?? '');
$filterVisibility = function_exists('soi_get') ? soi_get('visibility', '') : ($_GET['visibility'] ?? '');
$filterSearch     = function_exists('soi_get') ? soi_get('q', '') : ($_GET['q'] ?? '');

$filter = [];
if ($filterType !== '')       $filter['type'] = $filterType;
if ($filterStatus !== '')     $filter['status'] = $filterStatus;
if ($filterVisibility !== '') $filter['visibility'] = $filterVisibility;
if ($filterSearch !== '')     $filter['q'] = $filterSearch;

$spaces = $service->listSpaces($filter);
$totalCount = $service->getSpaceCount();
$generalDocsCount = $service->getSpaceCount(['type' => SpaceSchema::TYPE_GENERALDOCS]);
$librariesCount   = $service->getSpaceCount(['type' => SpaceSchema::TYPE_LIBRARIES]);
$techCount        = $service->getSpaceCount(['type' => SpaceSchema::TYPE_TECH]);

$topbarActions = '<button type="button" id="kc-open-create-modal" class="topbar-btn topbar-btn-primary">+ Create Space</button>';

if (!function_exists('esc')) {
    function esc(?string $str): string {
        return htmlspecialchars((string) ($str ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

$hasHeader = file_exists(__DIR__ . '/partials/header.php');
if ($hasHeader) {
    require_once __DIR__ . '/partials/header.php';
} else {
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= esc($pageTitle) ?> — SOI Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    :root {
      --soi-bg: #0f172a;
      --soi-surface: #1e293b;
      --soi-surface-secondary: #24334a;
      --soi-border: #334155;
      --soi-border-strong: #475569;
      --soi-primary: #38bdf8;
      --soi-primary-soft: rgba(56, 189, 248, 0.12);
      --soi-text: #f8fafc;
      --soi-text-muted: #94a3b8;
      --soi-success: #22c55e;
      --soi-warning: #eab308;
      --soi-danger: #ef4444;
      --soi-radius-sm: 6px;
      --soi-radius-md: 10px;
      --soi-radius-lg: 14px;
      --soi-font-sm: 0.875rem;
      --soi-font-base: 0.95rem;
      --soi-font-xs: 0.75rem;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Inter', system-ui, sans-serif;
      background: var(--soi-bg);
      color: var(--soi-text);
      line-height: 1.5;
      padding: 1.5rem;
    }
    .admin-container { max-width: 1200px; margin: 0 auto; }
    .card { background: var(--soi-surface); border: 1px solid var(--soi-border); border-radius: var(--soi-radius-md); padding: 1.25rem; }
    .btn { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.5rem 1rem; border-radius: var(--soi-radius-sm); font-size: 0.85rem; font-weight: 600; cursor: pointer; border: none; text-decoration: none; }
    .btn-primary { background: #0284c7; color: #fff; }
    .btn-primary:hover { background: #0369a1; }
    .btn-ghost { background: var(--soi-surface-secondary); color: var(--soi-text); border: 1px solid var(--soi-border); }
    .btn-ghost:hover { background: var(--soi-border); }
    .btn-danger { background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); }
    .btn-danger:hover { background: #ef4444; color: #fff; }
    .btn-sm { padding: 0.3rem 0.65rem; font-size: 0.78rem; }
    .toolbar { display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; }
    .search-input { background: #0b1120; border: 1px solid var(--soi-border); border-radius: var(--soi-radius-sm); padding: 0.5rem 0.75rem; color: #fff; outline: none; }
    .table-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; font-size: 0.875rem; text-align: left; }
    th { padding: 0.65rem 0.85rem; border-bottom: 1px solid var(--soi-border); color: var(--soi-text-muted); font-size: 0.75rem; text-transform: uppercase; }
    td { padding: 0.75rem 0.85rem; border-bottom: 1px solid rgba(51, 65, 85, 0.4); }
    .badge { display: inline-block; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600; }
    .badge-success { background: rgba(34, 197, 94, 0.15); color: #22c55e; }
    .badge-warning { background: rgba(234, 179, 8, 0.15); color: #eab308; }
    .badge-muted { background: rgba(148, 163, 184, 0.15); color: #94a3b8; }
    .table-link { color: var(--soi-primary); font-weight: 600; text-decoration: none; }
    .table-link:hover { text-decoration: underline; }
    .empty-state { text-align: center; padding: 3rem 1.5rem; }
    .empty-state-icon { font-size: 2.5rem; margin-bottom: 0.75rem; }
    .empty-state-title { font-size: 1.15rem; font-weight: 700; margin-bottom: 0.4rem; }
    .empty-state-text { color: var(--soi-text-muted); max-width: 480px; margin: 0 auto; font-size: 0.875rem; }
  </style>
</head>
<body>
<div class="admin-container">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;">
    <div>
      <h1 style="font-size:1.5rem;font-weight:800;letter-spacing:-0.02em;">🌌 Knowledge Spaces Management</h1>
      <p style="color:var(--soi-text-muted);font-size:0.875rem;">Manage continuous authoring spaces, contextual routing, and audience policies.</p>
    </div>
    <button type="button" id="kc-open-create-modal" class="btn btn-primary">+ Create Space</button>
  </div>
<?php } ?>

<style>
/* Task KS-02: Knowledge Spaces Admin Directory Styles */
.kc-spaces-header {
  margin-bottom: 1.25rem;
}
.kc-spaces-stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 0.75rem;
  margin-bottom: 1.25rem;
}
.kc-stat-card {
  background: var(--soi-surface, #1e293b);
  border: 1px solid var(--soi-border, #334155);
  border-radius: var(--soi-radius-md, 10px);
  padding: 0.85rem 1rem;
  display: flex;
  align-items: center;
  gap: 0.85rem;
  transition: border-color 0.15s ease;
}
.kc-stat-card:hover {
  border-color: var(--soi-border-strong, #475569);
}
.kc-stat-icon {
  width: 38px;
  height: 38px;
  border-radius: var(--soi-radius-md, 10px);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.25rem;
  background: var(--soi-surface-secondary, #24334a);
}
.kc-stat-info {
  display: flex;
  flex-direction: column;
}
.kc-stat-value {
  font-size: 1.3rem;
  font-weight: 700;
  color: var(--soi-text, #f8fafc);
  line-height: 1.1;
}
.kc-stat-label {
  font-size: 0.75rem;
  color: var(--soi-text-muted, #94a3b8);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.kc-tabs-nav {
  display: flex;
  gap: 0.25rem;
  border-bottom: 1px solid var(--soi-border, #334155);
  margin-bottom: 1rem;
}
.kc-tab-link {
  padding: 0.55rem 1rem;
  font-size: var(--soi-font-sm, 0.875rem);
  font-weight: 600;
  color: var(--soi-text-muted, #94a3b8);
  text-decoration: none;
  border-bottom: 2px solid transparent;
  display: flex;
  align-items: center;
  gap: 0.4rem;
  transition: all 0.15s ease;
}
.kc-tab-link:hover {
  color: var(--soi-text, #f8fafc);
}
.kc-tab-link.is-active {
  color: var(--soi-primary, #38bdf8);
  border-bottom-color: var(--soi-primary, #38bdf8);
}

.kc-space-icon-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 34px;
  height: 34px;
  background: var(--soi-surface-secondary, #24334a);
  border: 1px solid var(--soi-border, #334155);
  border-radius: var(--soi-radius-md, 10px);
  font-size: 1.15rem;
}

.badge-type-generaldocs { background: rgba(56, 189, 248, 0.15); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3); }
.badge-type-libraries   { background: rgba(168, 85, 247, 0.15); color: #c084fc; border: 1px solid rgba(168, 85, 247, 0.3); }
.badge-type-tech        { background: rgba(234, 179, 8, 0.15); color: #facc15; border: 1px solid rgba(234, 179, 8, 0.3); }

.kc-space-meta-url {
  font-family: 'JetBrains Mono', monospace;
  font-size: 0.78rem;
  color: var(--soi-text-muted, #94a3b8);
  margin-top: 2px;
  display: inline-block;
  text-decoration: none;
}
.kc-space-meta-url:hover {
  color: var(--soi-primary, #38bdf8);
  text-decoration: underline;
}

/* Modal / Slide-over Styles */
.kc-space-modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.65);
  backdrop-filter: blur(4px);
  z-index: 9999;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
}
.kc-space-modal-backdrop[hidden] {
  display: none !important;
}

.kc-space-modal {
  background: var(--soi-surface, #1e293b);
  border-radius: var(--soi-radius-lg, 14px);
  box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
  border: 1px solid var(--soi-border-strong, #475569);
  width: 100%;
  max-width: 600px;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  animation: kcModalFadeIn 0.15s ease-out;
}
@keyframes kcModalFadeIn {
  from { opacity: 0; transform: scale(0.97); }
  to { opacity: 1; transform: scale(1); }
}
.kc-space-modal-header {
  padding: 1.1rem 1.4rem;
  border-bottom: 1px solid var(--soi-border, #334155);
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.kc-space-modal-title {
  font-size: 1.1rem;
  font-weight: 700;
  color: var(--soi-text, #f8fafc);
}
.kc-space-modal-close {
  background: none;
  border: none;
  font-size: 1.25rem;
  color: var(--soi-text-muted, #94a3b8);
  cursor: pointer;
  padding: 0.25rem 0.5rem;
  border-radius: var(--soi-radius-sm, 6px);
}
.kc-space-modal-close:hover {
  background: var(--soi-surface-secondary, #24334a);
  color: var(--soi-text, #f8fafc);
}
.kc-space-modal-body {
  padding: 1.4rem;
  overflow-y: auto;
}
.kc-space-modal-footer {
  padding: 1rem 1.4rem;
  border-top: 1px solid var(--soi-border, #334155);
  background: var(--soi-surface-secondary, #24334a);
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 0.6rem;
  border-radius: 0 0 var(--soi-radius-lg, 14px) var(--soi-radius-lg, 14px);
}

.kc-form-group {
  margin-bottom: 1rem;
}
.kc-form-label {
  display: block;
  font-size: var(--soi-font-sm, 0.875rem);
  font-weight: 600;
  color: var(--soi-text, #f8fafc);
  margin-bottom: 0.35rem;
}
.kc-form-control {
  width: 100%;
  padding: 0.55rem 0.75rem;
  font-size: var(--soi-font-base, 0.95rem);
  border: 1px solid var(--soi-border-strong, #475569);
  border-radius: var(--soi-radius-md, 10px);
  background: #0b1120;
  color: var(--soi-text, #f8fafc);
  outline: none;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.kc-form-control:focus {
  border-color: var(--soi-primary, #38bdf8);
  box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.2);
}
.kc-form-hint {
  font-size: 0.75rem;
  color: var(--soi-text-muted, #94a3b8);
  margin-top: 0.3rem;
}

.kc-vis-options {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 0.5rem;
}
.kc-vis-card {
  border: 1px solid var(--soi-border-strong, #475569);
  border-radius: var(--soi-radius-md, 10px);
  padding: 0.65rem 0.5rem;
  text-align: center;
  cursor: pointer;
  background: #0b1120;
  transition: all 0.15s ease;
}
.kc-vis-card input[type="radio"] {
  display: none;
}
.kc-vis-card.is-selected {
  border-color: var(--soi-primary, #38bdf8);
  background: rgba(56, 189, 248, 0.12);
  color: var(--soi-primary, #38bdf8);
  font-weight: 600;
}
.kc-vis-card-title {
  font-size: 0.8rem;
  margin-top: 0.2rem;
}

.kc-emoji-row {
  display: flex;
  gap: 0.35rem;
  margin-top: 0.4rem;
  flex-wrap: wrap;
}
.kc-emoji-btn {
  background: var(--soi-surface-secondary, #24334a);
  border: 1px solid var(--soi-border, #334155);
  border-radius: var(--soi-radius-sm, 6px);
  padding: 0.25rem 0.5rem;
  cursor: pointer;
  font-size: 1rem;
}
.kc-emoji-btn:hover {
  background: var(--soi-border-strong, #475569);
}
</style>

<div class="kc-spaces-header">
  <div class="kc-spaces-stats-grid">
    <div class="kc-stat-card">
      <div class="kc-stat-icon">🌌</div>
      <div class="kc-stat-info">
        <span class="kc-stat-value"><?= $totalCount ?></span>
        <span class="kc-stat-label">Total Spaces</span>
      </div>
    </div>
    <div class="kc-stat-card">
      <div class="kc-stat-icon" style="color:#38bdf8;background:rgba(56,189,248,0.15);">📚</div>
      <div class="kc-stat-info">
        <span class="kc-stat-value"><?= $generalDocsCount ?></span>
        <span class="kc-stat-label">General Docs (/docs)</span>
      </div>
    </div>
    <div class="kc-stat-card">
      <div class="kc-stat-icon" style="color:#c084fc;background:rgba(168,85,247,0.15);">🏢</div>
      <div class="kc-stat-info">
        <span class="kc-stat-value"><?= $librariesCount ?></span>
        <span class="kc-stat-label">Libraries (/library/*)</span>
      </div>
    </div>
    <div class="kc-stat-card">
      <div class="kc-stat-icon" style="color:#facc15;background:rgba(234,179,8,0.15);">💻</div>
      <div class="kc-stat-info">
        <span class="kc-stat-value"><?= $techCount ?></span>
        <span class="kc-stat-label">Tech Docs (/tech/*)</span>
      </div>
    </div>
  </div>

  <nav class="kc-tabs-nav" aria-label="Spaces Navigation">
    <a href="?tab=directory" class="kc-tab-link <?= $activeTab === 'directory' ? 'is-active' : '' ?>">
      <span>🗂️</span> Spaces Directory
    </a>
    <a href="?tab=settings" class="kc-tab-link <?= $activeTab === 'settings' ? 'is-active' : '' ?>">
      <span>⚙️</span> Routing & Policies
    </a>
    <a href="?tab=taxonomy" class="kc-tab-link <?= $activeTab === 'taxonomy' ? 'is-active' : '' ?>">
      <span>🏷️</span> Global Taxonomy
    </a>
  </nav>
</div>

<?php if ($activeTab === 'directory'): ?>

<!-- Search & Filter Toolbar -->
<form method="GET" class="toolbar" style="margin-bottom:1rem;">
  <input type="hidden" name="tab" value="directory">
  <input type="hidden" name="type" value="<?= esc($filterType) ?>">
  <input type="hidden" name="status" value="<?= esc($filterStatus) ?>">
  
  <input class="search-input" type="text" name="q" placeholder="Search spaces by name, slug, or description…" value="<?= esc($filterSearch) ?>" style="min-width:280px;">
  <button type="submit" class="btn btn-ghost">Search</button>

  <div style="margin-left:auto;display:flex;align-items:center;gap:0.4rem;">
    <span style="font-size:var(--soi-font-xs, 0.75rem);color:var(--soi-text-muted,#94a3b8);">Type:</span>
    <a href="?tab=directory<?= $filterSearch ? '&q='.urlencode($filterSearch) : '' ?>" class="btn btn-ghost btn-sm <?= $filterType==='' ? 'btn-primary' : '' ?>">All</a>
    <a href="?tab=directory&type=generaldocs<?= $filterSearch ? '&q='.urlencode($filterSearch) : '' ?>" class="btn btn-ghost btn-sm <?= $filterType==='generaldocs' ? 'btn-primary' : '' ?>">Docs</a>
    <a href="?tab=directory&type=libraries<?= $filterSearch ? '&q='.urlencode($filterSearch) : '' ?>" class="btn btn-ghost btn-sm <?= $filterType==='libraries' ? 'btn-primary' : '' ?>">Libraries</a>
    <a href="?tab=directory&type=tech<?= $filterSearch ? '&q='.urlencode($filterSearch) : '' ?>" class="btn btn-ghost btn-sm <?= $filterType==='tech' ? 'btn-primary' : '' ?>">Tech</a>
  </div>
</form>

<div class="card">
  <?php if (empty($spaces)): ?>
  <div class="empty-state">
    <div class="empty-state-icon">🌌</div>
    <div class="empty-state-title">No Knowledge Spaces Found</div>
    <div class="empty-state-text">
      Get started by registering your first Knowledge Space for company documentation, audience libraries, or technical products.
    </div>
    <div style="margin-top:1.25rem;">
      <button type="button" class="btn btn-primary kc-trigger-create">+ Create Knowledge Space</button>
    </div>
  </div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th style="width:48px;">Icon</th>
          <th>Space Title & Identifier</th>
          <th>Type Context</th>
          <th>Visibility</th>
          <th>Status</th>
          <th style="text-align:center;width:60px;">Sort</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($spaces as $s): 
          $icon = !empty($s['icon']) ? $s['icon'] : ($s['type'] === 'tech' ? '💻' : ($s['type'] === 'libraries' ? '🏢' : '📚'));
          $publicPath = $s['type'] === 'tech' ? '/tech/' . $s['slug'] : ($s['type'] === 'libraries' ? '/library/' . $s['slug'] : '/docs/' . $s['slug']);
          $typeLabel = $s['type'] === 'tech' ? 'Tech Docs' : ($s['type'] === 'libraries' ? 'Library' : 'General Docs');
          $typeBadgeClass = 'badge-type-' . $s['type'];
          $homeUrl = defined('SOI_HOME_URL') ? SOI_HOME_URL : '';
        ?>
        <tr>
          <td>
            <span class="kc-space-icon-badge"><?= esc($icon) ?></span>
          </td>
          <td class="td-title">
            <a href="javascript:void(0)" class="table-link kc-edit-space-btn" data-space="<?= esc(json_encode($s)) ?>">
              <?= esc($s['title']) ?>
            </a>
            <div>
              <a href="<?= esc($homeUrl . $publicPath) ?>" target="_blank" class="kc-space-meta-url">
                <?= esc($publicPath) ?> ↗
              </a>
            </div>
            <?php if (!empty($s['description'])): ?>
            <div style="font-size:0.75rem;color:var(--soi-text-muted,#94a3b8);margin-top:2px;">
              <?= esc(mb_strimwidth((string)$s['description'], 0, 90, '…')) ?>
            </div>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge <?= $typeBadgeClass ?>"><?= esc($typeLabel) ?></span>
          </td>
          <td>
            <?php if ($s['visibility'] === 'public'): ?>
              <span style="font-size:0.8rem;color:var(--soi-success,#22c55e);">🌐 Public</span>
            <?php elseif ($s['visibility'] === 'authenticated'): ?>
              <span style="font-size:0.8rem;color:var(--soi-warning,#eab308);">🔑 Authenticated</span>
            <?php else: ?>
              <span style="font-size:0.8rem;color:var(--soi-danger,#ef4444);">🛡️ Restricted</span>
            <?php endif; ?>
          </td>
          <td>
            <?php 
              $statusBadge = ($s['status'] === 'active' || $s['status'] === 'published') ? 'badge-success' : ($s['status'] === 'draft' ? 'badge-warning' : 'badge-muted');
            ?>
            <span class="badge <?= $statusBadge ?>"><?= ucfirst(esc($s['status'])) ?></span>
          </td>
          <td style="text-align:center;font-weight:600;color:var(--soi-text-muted,#94a3b8);">
            <?= (int)($s['sortorder'] ?? $s['sort_order'] ?? 0) ?>
          </td>
          <td style="text-align:right;">
            <div style="display:flex;justify-content:flex-end;gap:0.35rem;align-items:center;">
              <button type="button" class="btn btn-ghost btn-sm kc-edit-space-btn" data-space="<?= esc(json_encode($s)) ?>" title="Edit Space">
                ✏️ Edit
              </button>
              <a href="<?= esc($homeUrl . $publicPath) ?>" target="_blank" class="btn btn-ghost btn-sm" title="View Public Space">
                🌐
              </a>
              <form method="POST" style="display:inline-block;" onsubmit="return confirm('Delete space \'<?= esc(addslashes($s['title'])) ?>\'? All unlinked items will be preserved.');">
                <?= (class_exists(Auth::class) && method_exists(Auth::class, 'csrfField')) ? Auth::csrfField() : '<input type="hidden" name="_csrf" value="">' ?>
                <input type="hidden" name="_action" value="delete_space">
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm" title="Delete Space">
                  🗑️
                </button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php elseif ($activeTab === 'settings'): ?>

<div class="card" style="max-width:760px;">
  <h3 style="font-size:1.05rem;font-weight:700;margin-bottom:0.75rem;">🌌 Knowledge Space Architecture & Routing</h3>
  <p style="font-size:var(--soi-font-sm, 0.875rem);color:var(--soi-text-muted,#94a3b8);margin-bottom:1.25rem;">
    SOI Knowledge Center provides one continuous authoring engine supporting three contextual domains:
  </p>
  
  <div style="display:flex;flex-direction:column;gap:0.75rem;margin-bottom:1.5rem;">
    <div style="padding:0.75rem 1rem;border:1px solid var(--soi-border,#334155);border-radius:var(--soi-radius-md,10px);background:var(--soi-surface-secondary,#24334a);">
      <strong>1. General Docs (<code>/docs/*</code>)</strong>
      <p style="font-size:0.8rem;color:var(--soi-text-muted,#94a3b8);margin-top:0.25rem;">Company-wide documentation, standard operating procedures, policies, and onboarding guides.</p>
    </div>
    <div style="padding:0.75rem 1rem;border:1px solid var(--soi-border,#334155);border-radius:var(--soi-radius-md,10px);background:var(--soi-surface-secondary,#24334a);">
      <strong>2. Audience Libraries (<code>/library/{slug}/*</code>)</strong>
      <p style="font-size:0.8rem;color:var(--soi-text-muted,#94a3b8);margin-top:0.25rem;">Departmental knowledge collections (e.g. HR, IT, Finance, Operations) scoped by audience policies.</p>
    </div>
    <div style="padding:0.75rem 1rem;border:1px solid var(--soi-border,#334155);border-radius:var(--soi-radius-md,10px);background:var(--soi-surface-secondary,#24334a);">
      <strong>3. Technical Products (<code>/tech/{slug}/*</code>)</strong>
      <p style="font-size:0.8rem;color:var(--soi-text-muted,#94a3b8);margin-top:0.25rem;">Product documentation spaces (e.g. Accounts Directory, HRMS) with versioning, architecture, and API references.</p>
    </div>
  </div>

  <form method="POST" onsubmit="return confirm('Purge all Knowledge Space transient caches?');">
    <?= (class_exists(Auth::class) && method_exists(Auth::class, 'csrfField')) ? Auth::csrfField() : '<input type="hidden" name="_csrf" value="">' ?>
    <input type="hidden" name="_action" value="flush_space_cache">
    <button type="submit" class="btn btn-ghost">🧹 Purge Spaces Cache Engine</button>
  </form>
</div>

<?php elseif ($activeTab === 'taxonomy'): ?>

<div class="card" style="max-width:760px;">
  <h3 style="font-size:1.05rem;font-weight:700;margin-bottom:0.75rem;">🏷️ Knowledge Space Taxonomy Mapping</h3>
  <p style="font-size:var(--soi-font-sm, 0.875rem);color:var(--soi-text-muted,#94a3b8);margin-bottom:1.25rem;">
    Spaces organize child documents into hierarchical Sections, Categories, and Navigation trees.
  </p>
  <div class="empty-state" style="padding:1.5rem;">
    <div class="empty-state-icon">🗂️</div>
    <div class="empty-state-title">Hierarchy Model</div>
    <div class="empty-state-text">Space → Documentation Version → Section → Category → Document</div>
    <div style="margin-top:1rem;">
      <a href="?tab=directory" class="btn btn-ghost">← Return to Spaces Directory</a>
    </div>
  </div>
</div>

<?php endif; ?>

<!-- ============================================== -->
<!-- Create / Edit Space Modal (Slide-over / Dialog) -->
<!-- ============================================== -->
<div id="space-modal-backdrop" class="kc-space-modal-backdrop" hidden>
  <div id="space-modal" class="kc-space-modal" role="dialog" aria-modal="true" aria-labelledby="space-modal-heading">
    <form id="kc-space-form" method="POST" action="<?= defined('SOI_ADMIN_URL') ? SOI_ADMIN_URL . '/spaces.php' : 'spaces.php' ?>">
      <?= (class_exists(Auth::class) && method_exists(Auth::class, 'csrfField')) ? Auth::csrfField() : '<input type="hidden" name="_csrf" value="">' ?>
      <input type="hidden" name="_action" value="save_space">
      <input type="hidden" id="space-modal-id" name="id" value="0">

      <div class="kc-space-modal-header">
        <h3 id="space-modal-heading" class="kc-space-modal-title">Create Knowledge Space</h3>
        <button type="button" class="kc-space-modal-close" id="kc-modal-close-btn" aria-label="Close modal">✕</button>
      </div>

      <div class="kc-space-modal-body">
        <!-- Space Title -->
        <div class="kc-form-group">
          <label class="kc-form-label" for="space-modal-title">Space Title *</label>
          <input type="text" id="space-modal-title" name="title" class="kc-form-control" placeholder="e.g. Accounts Directory Documentation" required autocomplete="off">
        </div>

        <!-- Slug with Auto-Slugifier & Live Hint -->
        <div class="kc-form-group">
          <label class="kc-form-label" for="space-modal-slug">Space Slug (URL Identifier) *</label>
          <input type="text" id="space-modal-slug" name="slug" class="kc-form-control" placeholder="accounts-directory" required autocomplete="off">
          <div class="kc-form-hint" id="kc-slug-preview">
            Public URL: <code>kc.soi.co.in/<span id="kc-slug-prefix">docs/</span><span id="kc-slug-target">new-space</span></code>
          </div>
          <div id="kc-slug-status" style="font-size:0.75rem;margin-top:3px;"></div>
        </div>

        <!-- Space Type Selector -->
        <div class="kc-form-group">
          <label class="kc-form-label" for="space-modal-type">Knowledge Context / Type *</label>
          <select id="space-modal-type" name="type" class="kc-form-control">
            <option value="generaldocs">📚 General Docs (/docs/)</option>
            <option value="libraries">🏢 Department Library (/library/)</option>
            <option value="tech">💻 Technical Documentation (/tech/)</option>
          </select>
          <div class="kc-form-hint">Determines public routing domain and default navigation layouts.</div>
        </div>

        <!-- Icon Picker & Emojis -->
        <div class="kc-form-group">
          <label class="kc-form-label" for="space-modal-icon">Space Icon / Emoji</label>
          <input type="text" id="space-modal-icon" name="icon" class="kc-form-control" placeholder="e.g. 📚, 💻, 🏢" style="max-width:120px;">
          <div class="kc-emoji-row">
            <button type="button" class="kc-emoji-btn" data-emoji="📚">📚</button>
            <button type="button" class="kc-emoji-btn" data-emoji="💻">💻</button>
            <button type="button" class="kc-emoji-btn" data-emoji="🏢">🏢</button>
            <button type="button" class="kc-emoji-btn" data-emoji="⚙️">⚙️</button>
            <button type="button" class="kc-emoji-btn" data-emoji="🔒">🔒</button>
            <button type="button" class="kc-emoji-btn" data-emoji="🚀">🚀</button>
            <button type="button" class="kc-emoji-btn" data-emoji="📘">📘</button>
            <button type="button" class="kc-emoji-btn" data-emoji="🛡️">🛡️</button>
          </div>
        </div>

        <!-- Description -->
        <div class="kc-form-group">
          <label class="kc-form-label" for="space-modal-desc">Description</label>
          <textarea id="space-modal-desc" name="description" class="kc-form-control" rows="2" placeholder="Brief summary of knowledge topics covered in this space…"></textarea>
        </div>

        <!-- Visibility Radio Cards -->
        <div class="kc-form-group">
          <label class="kc-form-label">Audience Visibility</label>
          <div class="kc-vis-options">
            <label class="kc-vis-card is-selected" data-vis="public">
              <input type="radio" name="visibility" value="public" checked>
              <div style="font-size:1.1rem;">🌐</div>
              <div class="kc-vis-card-title">Public</div>
            </label>
            <label class="kc-vis-card" data-vis="authenticated">
              <input type="radio" name="visibility" value="authenticated">
              <div style="font-size:1.1rem;">🔑</div>
              <div class="kc-vis-card-title">SOI Login</div>
            </label>
            <label class="kc-vis-card" data-vis="restricted">
              <input type="radio" name="visibility" value="restricted">
              <div style="font-size:1.1rem;">🛡️</div>
              <div class="kc-vis-card-title">Restricted</div>
            </label>
          </div>
        </div>

        <!-- Status & Sort Order in 2 Columns -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
          <div class="kc-form-group">
            <label class="kc-form-label" for="space-modal-status">Publish Status</label>
            <select id="space-modal-status" name="status" class="kc-form-control">
              <option value="published">Published (Active)</option>
              <option value="active">Active</option>
              <option value="draft">Draft (Private)</option>
              <option value="archived">Archived (Read-Only)</option>
            </select>
          </div>
          <div class="kc-form-group">
            <label class="kc-form-label" for="space-modal-sort">Sort Order</label>
            <input type="number" id="space-modal-sort" name="sort_order" class="kc-form-control" value="0">
          </div>
        </div>
      </div>

      <div class="kc-space-modal-footer">
        <button type="button" class="btn btn-ghost" id="kc-modal-cancel-btn">Cancel</button>
        <button type="submit" class="btn btn-primary" id="kc-modal-submit-btn">Save Space</button>
      </div>
    </form>
  </div>
</div>

<script>
(function() {
  'use strict';

  const backdrop = document.getElementById('space-modal-backdrop');
  const modal = document.getElementById('space-modal');
  const form = document.getElementById('kc-space-form');
  const modalHeading = document.getElementById('space-modal-heading');
  const idInput = document.getElementById('space-modal-id');
  const titleInput = document.getElementById('space-modal-title');
  const slugInput = document.getElementById('space-modal-slug');
  const typeSelect = document.getElementById('space-modal-type');
  const iconInput = document.getElementById('space-modal-icon');
  const descInput = document.getElementById('space-modal-desc');
  const statusSelect = document.getElementById('space-modal-status');
  const sortInput = document.getElementById('space-modal-sort');
  const slugPrefixTarget = document.getElementById('kc-slug-prefix');
  const slugPreviewTarget = document.getElementById('kc-slug-target');
  const slugStatus = document.getElementById('kc-slug-status');
  const openCreateBtn = document.getElementById('kc-open-create-modal');
  const closeBtn = document.getElementById('kc-modal-close-btn');
  const cancelBtn = document.getElementById('kc-modal-cancel-btn');

  let isManualSlug = false;
  let slugCheckTimeout = null;

  function slugify(text) {
    return (text || '').toLowerCase().trim().replace(/[^a-z0-9\-]+/g, '-').replace(/^-+|-+$/g, '');
  }

  function updateUrlPreview() {
    const slug = slugInput ? (slugInput.value || 'new-space') : 'new-space';
    const type = typeSelect ? typeSelect.value : 'generaldocs';
    const prefix = type === 'tech' ? 'tech/' : (type === 'libraries' ? 'library/' : 'docs/');
    if (slugPrefixTarget) slugPrefixTarget.textContent = prefix;
    if (slugPreviewTarget) slugPreviewTarget.textContent = slug;
  }

  function checkSlugAvailability() {
    clearTimeout(slugCheckTimeout);
    if (!slugInput || !slugStatus) return;
    const slug = slugInput.value.trim();
    const excludeId = idInput ? (parseInt(idInput.value, 10) || 0) : 0;
    if (!slug) {
      slugStatus.textContent = '';
      return;
    }

    slugCheckTimeout = setTimeout(function() {
      fetch('spaces-api.php?action=validate_slug&slug=' + encodeURIComponent(slug) + '&exclude_id=' + excludeId)
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            if (data.available) {
              slugStatus.textContent = '✓ ' + data.message;
              slugStatus.style.color = 'var(--soi-success, #22c55e)';
            } else {
              slugStatus.textContent = '✗ ' + data.message;
              slugStatus.style.color = 'var(--soi-danger, #ef4444)';
            }
          }
        })
        .catch(() => {});
    }, 250);
  }

  function setVisibilityCard(visValue) {
    document.querySelectorAll('.kc-vis-card').forEach(function(card) {
      const radio = card.querySelector('input[type="radio"]');
      const selected = (card.dataset.vis === visValue);
      card.classList.toggle('is-selected', selected);
      if (radio) radio.checked = selected;
    });
  }

  document.querySelectorAll('.kc-vis-card').forEach(function(card) {
    card.addEventListener('click', function() {
      setVisibilityCard(card.dataset.vis);
    });
  });

  document.querySelectorAll('.kc-emoji-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      if (iconInput) iconInput.value = btn.dataset.emoji;
    });
  });

  if (titleInput) {
    titleInput.addEventListener('input', function() {
      if (!isManualSlug && slugInput) {
        slugInput.value = slugify(titleInput.value);
        updateUrlPreview();
        checkSlugAvailability();
      }
    });
  }

  if (slugInput) {
    slugInput.addEventListener('input', function() {
      isManualSlug = true;
      slugInput.value = slugify(slugInput.value);
      updateUrlPreview();
      checkSlugAvailability();
    });
  }

  if (typeSelect) {
    typeSelect.addEventListener('change', updateUrlPreview);
  }

  function openModal(isEdit, data) {
    if (form) form.reset();
    isManualSlug = !!isEdit;
    if (slugStatus) slugStatus.textContent = '';

    if (isEdit && data) {
      if (modalHeading) modalHeading.textContent = 'Edit Knowledge Space';
      if (idInput) idInput.value = data.id || 0;
      if (titleInput) titleInput.value = data.title || '';
      if (slugInput) slugInput.value = data.slug || '';
      if (typeSelect) typeSelect.value = data.type || 'generaldocs';
      if (iconInput) iconInput.value = data.icon || '';
      if (descInput) descInput.value = data.description || '';
      if (statusSelect) statusSelect.value = data.status || 'published';
      if (sortInput) sortInput.value = data.sortorder !== undefined ? data.sortorder : (data.sort_order || 0);
      setVisibilityCard(data.visibility || 'public');
    } else {
      if (modalHeading) modalHeading.textContent = 'Create Knowledge Space';
      if (idInput) idInput.value = 0;
      if (typeSelect) typeSelect.value = 'generaldocs';
      if (statusSelect) statusSelect.value = 'published';
      if (sortInput) sortInput.value = 0;
      setVisibilityCard('public');
    }

    updateUrlPreview();
    if (backdrop) backdrop.hidden = false;
    setTimeout(() => { if (titleInput) titleInput.focus(); }, 50);
  }

  function closeModal() {
    if (backdrop) backdrop.hidden = true;
  }

  if (openCreateBtn) openCreateBtn.addEventListener('click', () => openModal(false));
  document.querySelectorAll('.kc-trigger-create').forEach(b => b.addEventListener('click', () => openModal(false)));

  if (closeBtn) closeBtn.addEventListener('click', closeModal);
  if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

  if (backdrop) {
    backdrop.addEventListener('click', function(e) {
      if (e.target === backdrop) closeModal();
    });
  }

  window.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && backdrop && !backdrop.hidden) {
      closeModal();
    }
  });

  // Wire Edit buttons on table rows
  document.querySelectorAll('.kc-edit-space-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      try {
        const raw = btn.dataset.space;
        const data = JSON.parse(raw);
        openModal(true, data);
      } catch (err) {
        console.error('Failed to parse space data:', err);
      }
    });
  });
})();
</script>

<?php 
if ($hasHeader && file_exists(__DIR__ . '/partials/footer.php')) {
    require_once __DIR__ . '/partials/footer.php';
} else {
    echo '</div></body></html>';
}
?>
