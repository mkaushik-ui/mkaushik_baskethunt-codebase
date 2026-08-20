<?php
/**
 * Admin — Media Private Storage Migration
 */
$pageTitle = 'Private Storage Migration';
$activeNav = 'media';

if (!defined('SOI_ROOT')) define('SOI_ROOT', dirname(__DIR__));
require_once SOI_ROOT . '/config/config.php';
require_once SOI_ROOT . '/core/helpers.php';
spl_autoload_register(fn($c) => (fn($f) => file_exists($f) && require_once $f)(SOI_ROOT.'/core/'.str_replace(['SOI\\Core\\','\\'],['','/'],$c).'.php'));
use SOI\Core\{Database, Auth, MediaStorage};

Database::connect(['host'=>SOI_DB_HOST,'name'=>SOI_DB_NAME,'user'=>SOI_DB_USER,'pass'=>SOI_DB_PASS,'port'=>SOI_DB_PORT,'prefix'=>SOI_DB_PREFIX]);
Auth::init();
Auth::requireAuth('admin');

$logs = [];
$stats = [
    'total' => 0,
    'public' => 0,
    'private' => 0,
    'missing' => 0
];

$mediaFiles = Database::select("SELECT * FROM `" . Database::prefix('media') . "`");
$stats['total'] = count($mediaFiles);

foreach ($mediaFiles as $media) {
    if (!file_exists($media['path'])) {
        $stats['missing']++;
    } elseif (MediaStorage::isPathInsidePrivateMediaRoot($media['path'])) {
        $stats['private']++;
    } else {
        $stats['public']++;
    }
}

$isDryRunGet = ($_GET['mode'] ?? '') === 'dry-run';
if ($_SERVER['REQUEST_METHOD'] === 'POST' || $isDryRunGet) {
    if (!$isDryRunGet && !Auth::verifyCsrf($_POST['_csrf'] ?? '')) die('CSRF');

    $isExecute = !$isDryRunGet && isset($_POST['execute_migration']);
    $confirmBackup = isset($_POST['confirm_backup']);
    
    if ($isExecute && !$confirmBackup) {
        soi_flash('error', 'You must confirm that you have a backup before executing the migration.');
        soi_redirect(SOI_ADMIN_URL . '/media-private-storage-migrate.php');
    }

    $logs[] = $isExecute ? "Starting EXECUTED MIGRATION" : "Starting DRY RUN MIGRATION";

    MediaStorage::ensurePrivateMediaRoot();

    $migrated = 0;
    $errors = 0;

    foreach ($mediaFiles as $media) {
        $oldPath = $media['path'];
        
        if (MediaStorage::isPathInsidePrivateMediaRoot($oldPath)) {
            continue; // Already private
        }
        if (!file_exists($oldPath)) {
            $logs[] = "[WARN] File missing: {$media['original_name']} ({$oldPath})";
            continue;
        }

        // It's a public file that needs migration
        $hash = $media['filename'];
        $year = date('Y', strtotime($media['created_at']));
        $month = date('m', strtotime($media['created_at']));
        
        $newPath = MediaStorage::buildPrivateMediaPath($hash, $year, $month);
        
        if (!$isExecute) {
            $logs[] = "[DRY-RUN] Would move '{$media['original_name']}' from " . dirname($oldPath) . " to " . dirname($newPath);
            continue;
        }

        $logs[] = "[EXECUTE] Processing '{$media['original_name']}'...";

        // 1. Copy
        if (!MediaStorage::copyLegacyFileToPrivateStorage($oldPath, $newPath)) {
            $logs[] = "  [FAIL] Could not copy file to private storage.";
            $errors++;
            continue;
        }

        // 2. Verify
        if (!MediaStorage::verifyChecksum($oldPath, $newPath)) {
            $logs[] = "  [FAIL] Checksum mismatch. Deleting invalid copy.";
            @unlink($newPath);
            $errors++;
            continue;
        }

        // 3. Update DB
        try {
            Database::update('media', ['path' => $newPath], 'id = ?', [$media['id']]);
        } catch (\Exception $e) {
            $logs[] = "  [FAIL] Database update failed. Deleting copy.";
            @unlink($newPath);
            $errors++;
            continue;
        }

        // 4. Quarantine old file
        if (MediaStorage::quarantineLegacyFile($oldPath, $hash)) {
            $logs[] = "  [SUCCESS] Migrated to private storage and quarantined old file.";
        } else {
            $logs[] = "  [WARN] Migrated to private storage but failed to quarantine old file. You may need to delete it manually: " . basename($oldPath);
        }
        
        $migrated++;
    }

    $logs[] = "Migration complete. Migrated: $migrated, Errors: $errors";
}

$pageContentClass = 'page-content--fluid';
require_once __DIR__ . '/partials/header.php';
?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">Private Storage Migration</h2>
    </div>
    <div class="card-body">
        <details class="help-disclosure help-disclosure--inline">
            <summary>About this migration</summary>
            <div class="help-disclosure-body">
                Moves public <code>/uploads/</code> media into PHP-controlled private storage. Run Dry Run first. Execute only after backup.
            </div>
        </details>

        <div class="migration-stats-grid">
            <div class="migration-stat-card">
                <div class="value" style="color:var(--brand);"><?= $stats['total'] ?></div>
                <div class="label">Total Files</div>
            </div>
            <div class="migration-stat-card">
                <div class="value" style="color:var(--danger);"><?= $stats['public'] ?></div>
                <div class="label">Public (Needs Migration)</div>
            </div>
            <div class="migration-stat-card">
                <div class="value" style="color:var(--success);"><?= $stats['private'] ?></div>
                <div class="label">Private (Secure)</div>
            </div>
            <div class="migration-stat-card">
                <div class="value" style="color:var(--warning);"><?= $stats['missing'] ?></div>
                <div class="label">Missing Files</div>
            </div>
        </div>

        <form method="POST" class="danger-zone">
            <?= Auth::csrfField() ?>
            <h4>Migration Actions</h4>
            <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-start;">
                <button type="submit" name="dry_run" value="1" class="btn btn-secondary">Run Dry-Run (Safe)</button>
                <div class="hide-mobile" style="width:1px;height:40px;background:rgba(209,52,56,0.2);"></div>
                <div style="display:flex;flex-direction:column;gap:0.5rem;flex:1;min-width:240px;">
                    <label class="form-switch-wrap" style="background:transparent;border:none;padding:0;">
                        <input type="checkbox" name="confirm_backup" value="1">
                        <div>
                            <span class="form-switch-label" style="color:#991b1b;">I confirm I have a backup of database and uploads.</span>
                        </div>
                    </label>
                    <button type="submit" name="execute_migration" value="1" class="btn btn-danger" data-confirm="Execute private storage migration? This will move files. Ensure backup is complete.">Execute Migration</button>
                </div>
            </div>
        </form>

        <?php if (!empty($logs)): ?>
        <h3 class="card-title" style="margin-top:1.5rem;margin-bottom:0.5rem;">Migration Logs</h3>
        <pre class="migration-log"><?php foreach ($logs as $log) echo esc($log) . "\n"; ?></pre>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
