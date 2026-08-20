<?php
/**
 * Admin — API Keys Manager
 */
$pageTitle = 'API Keys';
$activeNav = 'api-keys';
$pageContentClass = 'page-content--fluid';

if (!defined('SOI_ROOT')) define('SOI_ROOT', dirname(__DIR__));
require_once SOI_ROOT . '/config/config.php';
require_once SOI_ROOT . '/core/helpers.php';
spl_autoload_register(fn($c) => (fn($f) => file_exists($f) && require_once $f)(SOI_ROOT.'/core/'.str_replace(['SOI\\Core\\','\\'],['','/'],$c).'.php'));
use SOI\Core\{Database, Auth};

Database::connect(['host'=>SOI_DB_HOST,'name'=>SOI_DB_NAME,'user'=>SOI_DB_USER,'pass'=>SOI_DB_PASS,'port'=>SOI_DB_PORT,'prefix'=>SOI_DB_PREFIX]);
Auth::init();
Auth::requireAuth('admin');

// Ensure table exists
$prefix = Database::prefix('api_keys');
$sql = "CREATE TABLE IF NOT EXISTS `$prefix` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(255) NOT NULL,
    `api_key` varchar(255) NOT NULL,
    `permissions` varchar(255) NOT NULL DEFAULT 'read',
    `status` tinyint(1) NOT NULL DEFAULT 1,
    `created_at` datetime NOT NULL,
    `last_used_at` datetime DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `api_key` (`api_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
Database::exec($sql);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['_csrf'] ?? '')) die('CSRF');
    $action = $_POST['_action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $permissions = $_POST['permissions'] ?? 'read';
        
        if ($name === '') {
            soi_flash('error', 'API Key name is required.');
        } else {
            // Generate a secure API Key
            $apiKey = 'soi_api_' . bin2hex(random_bytes(24));
            
            Database::insert('api_keys', [
                'name' => $name,
                'api_key' => $apiKey,
                'permissions' => $permissions,
                'status' => 1,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            soi_flash('success', "API Key created successfully! Your key is: <strong>{$apiKey}</strong> (Please copy it now, it won't be shown again.)");
        }
        soi_redirect(SOI_ADMIN_URL . '/api-keys.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            Database::delete('api_keys', 'id = ?', [$id]);
            soi_flash('success', 'API Key deleted.');
        }
        soi_redirect(SOI_ADMIN_URL . '/api-keys.php');
    }
}

$keys = Database::select("SELECT * FROM `$prefix` ORDER BY id DESC");

require_once __DIR__ . '/partials/header.php';
?>

<div style="display:flex; gap:1.5rem; flex-wrap:wrap; align-items:flex-start;">
    
    <!-- Key Generation Form -->
    <div class="card" style="flex:1; min-width:300px;">
        <div class="card-header">
            <h3>Generate New API Key</h3>
        </div>
        <div class="card-body">
            <form method="POST">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="_action" value="create">
                
                <div class="form-group">
                    <label>Key Name / Description</label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. Mobile App, Zapier Integration" required>
                </div>
                
                <div class="form-group">
                    <label>Permissions Scope</label>
                    <select name="permissions" class="form-control" required>
                        <option value="read">Read-Only (GET requests)</option>
                        <option value="read,write">Read & Write (GET, POST, PUT, DELETE)</option>
                        <option value="full">Full Administrative Access</option>
                    </select>
                    <small style="color:var(--text-muted); display:block; margin-top:0.25rem;">
                        Limit the scope of this key to minimize security risks.
                    </small>
                </div>
                
                <button type="submit" class="btn btn-primary" style="margin-top:0.5rem;">🔑 Generate Key</button>
            </form>
        </div>
    </div>
    
    <!-- Existing Keys List -->
    <div class="card" style="flex:2; min-width:300px;">
        <div class="card-header">
            <h3>Active API Keys</h3>
        </div>
        <div class="card-body" style="padding:0;">
            <?php if (!$keys): ?>
                <div style="padding:2rem; text-align:center; color:var(--text-muted);">
                    <div style="font-size:2rem; margin-bottom:1rem;">🔐</div>
                    No API keys have been generated yet.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Key Prefix</th>
                                <th>Permissions</th>
                                <th>Created</th>
                                <th>Last Used</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($keys as $k): ?>
                            <tr>
                                <td style="font-weight:600;"><?= esc($k['name']) ?></td>
                                <td>
                                    <code>soi_api_<?= substr($k['api_key'], 8, 4) ?>...</code>
                                </td>
                                <td>
                                    <span class="badge badge-info"><?= esc($k['permissions']) ?></span>
                                </td>
                                <td style="color:var(--text-muted); font-size:0.85rem;">
                                    <?= date('M j, Y', strtotime($k['created_at'])) ?>
                                </td>
                                <td style="color:var(--text-muted); font-size:0.85rem;">
                                    <?= $k['last_used_at'] ? date('M j, Y H:i', strtotime($k['last_used_at'])) : 'Never' ?>
                                </td>
                                <td>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Revoke this API Key permanently? Applications using it will immediately lose access.');">
                                        <?= Auth::csrfField() ?>
                                        <input type="hidden" name="_action" value="delete">
                                        <input type="hidden" name="id" value="<?= $k['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Revoke</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
