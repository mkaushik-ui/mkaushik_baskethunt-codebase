<?php
/**
 * Admin — Media Migration Utility
 */
$pageTitle = 'Migrate Media to Extensionless Storage';
$activeNav = 'media';

if (!defined('SOI_ROOT')) define('SOI_ROOT', dirname(__DIR__));
require_once SOI_ROOT . '/config/config.php';
require_once SOI_ROOT . '/core/helpers.php';
spl_autoload_register(fn($c) => (fn($f) => file_exists($f) && require_once $f)(SOI_ROOT.'/core/'.str_replace(['SOI\\Core\\','\\'],['','/'],$c).'.php'));
use SOI\Core\{Database, Auth};

Database::connect(['host'=>SOI_DB_HOST,'name'=>SOI_DB_NAME,'user'=>SOI_DB_USER,'pass'=>SOI_DB_PASS,'port'=>SOI_DB_PORT,'prefix'=>SOI_DB_PREFIX]);
Auth::init();
Auth::requireAuth('admin');

$mediaFiles = Database::select("SELECT * FROM `" . Database::prefix('media') . "`");

$logs = [];
$migratedCount = 0;

if (($_POST['_action'] ?? '') === 'migrate') {
    if (!Auth::verifyCsrf($_POST['_csrf'] ?? '')) die('CSRF');

    foreach ($mediaFiles as $media) {
        $oldPath = $media['path'];
        
        // Skip if it doesn't exist
        if (!file_exists($oldPath)) {
            $logs[] = "⚠️ File missing: {$media['filename']}";
            continue;
        }

        // Check if it's already a 16-char hash with no extension
        if (preg_match('/^[a-f0-9]{16}$/i', $media['filename'])) {
            $logs[] = "⏭️ Skipped (Already extensionless): {$media['filename']}";
            continue;
        }

        // Generate new hash
        $hash = substr(hash('sha256', uniqid() . random_bytes(16)), 0, 16);
        $newPath = dirname($oldPath) . '/' . $hash;

        // Rename the physical file (stripping the extension)
        if (rename($oldPath, $newPath)) {
            $newUrl = SOI_HOME_URL . '/media/view/' . $hash;
            
            // Update the database
            Database::update('media', [
                'filename' => $hash,
                'path'     => $newPath,
                'url'      => $newUrl
            ], 'id = ?', [$media['id']]);

            $logs[] = "✅ Migrated: {$media['filename']} ➔ {$hash}";
            $migratedCount++;
        } else {
            $logs[] = "❌ Failed to rename: {$media['filename']}";
        }
    }
}

$pageContentClass = 'page-content--fluid';
require_once __DIR__ . '/partials/header.php';
?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">Legacy Extensionless Media Migration</h2>
    </div>
    <div class="card-body">
        <p class="page-toolbar-meta">
            Legacy extensionless rename tool.
            <a href="<?= SOI_ADMIN_URL ?>/media-private-storage-migrate.php" class="admin-link">Private Storage Migration</a>
        </p>

        <details class="help-disclosure help-disclosure--inline">
            <summary>What this migration does</summary>
            <div class="help-disclosure-body">
                <p>This is the legacy extensionless filename migration tool. It renames files with extensions (e.g. <code>.png</code>) to extensionless hashes on disk. URLs update to <code>/media/view/{hash}</code>. It does <strong>not</strong> move files into private storage — use Private Storage Migration for that.</p>
            </div>
        </details>

        <?php if (!empty($logs)): ?>
            <pre class="migration-log" style="background:var(--surface2);color:var(--text);max-height:300px;">
<strong>Migration Complete! <?= $migratedCount ?> files migrated.</strong>

<?php foreach ($logs as $log): ?><?= esc($log) . "\n" ?><?php endforeach; ?>
            </pre>
            <a href="<?= SOI_ADMIN_URL ?>/media.php" class="btn btn-primary" style="margin-top:1rem;">Return to Media Library</a>
        <?php else: ?>
            <form method="POST">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="_action" value="migrate">
                <button type="submit" class="btn btn-primary" data-confirm="Are you sure? This will permanently rename files on disk. Ensure you have a backup.">
                    Start Migration Now (<?= count($mediaFiles) ?> Total Files)
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
