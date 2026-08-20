<?php
/**
 * Admin — Updates (Upload ZIP, view update log)
 */
$pageTitle = 'Updates';
$activeNav = 'updates';
$pageContentClass = 'page-content--fluid';

if (!defined('SOI_ROOT')) define('SOI_ROOT', dirname(__DIR__));
require_once SOI_ROOT . '/config/config.php';
require_once SOI_ROOT . '/core/helpers.php';
spl_autoload_register(fn($c) => (fn($f) => file_exists($f) && require_once $f)(SOI_ROOT.'/core/'.str_replace(['SOI\\Core\\','\\'],['','/'],$c).'.php'));
use SOI\Core\{Database, Auth, Update};
Database::connect(['host'=>SOI_DB_HOST,'name'=>SOI_DB_NAME,'user'=>SOI_DB_USER,'pass'=>SOI_DB_PASS,'port'=>SOI_DB_PORT,'prefix'=>SOI_DB_PREFIX]);
Auth::init();
Auth::requireAuth('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['_csrf'] ?? '')) {
        soi_flash('error', 'CSRF check failed. Reload the page and try the upload again.');
        soi_redirect(SOI_ADMIN_URL . '/updates.php');
    }

    if (isset($_FILES['update_zip']) && $_FILES['update_zip']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['update_zip'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($ext !== 'zip') {
            soi_flash('error', 'Invalid file. Only .zip files are accepted.');
        } else {
            $tmpPath = SOI_ROOT . '/updates/' . 'update_' . time() . '.zip';
            if (move_uploaded_file($file['tmp_name'], $tmpPath)) {
                $validation = Update::validateZip($tmpPath);
                if (!$validation['valid']) {
                    @unlink($tmpPath);
                    soi_flash('error', 'Invalid update package: ' . $validation['error']);
                } else {
                    $result = Update::process($tmpPath);
                    @unlink($tmpPath);
                    if ($result['success']) {
                        soi_flash('success', $result['message']);
                    } else {
                        soi_flash('error', 'Update failed: ' . $result['message']);
                    }
                }
            } else {
                soi_flash('error', 'Failed to save uploaded file. Check updates/ directory permissions.');
            }
        }
    } else {
        $uploadError = $_FILES['update_zip']['error'] ?? -1;
        $errors = [
            UPLOAD_ERR_INI_SIZE  => 'File exceeds PHP upload_max_filesize limit.',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE form limit.',
            UPLOAD_ERR_NO_FILE   => 'No file was uploaded.',
        ];
        soi_flash('error', $errors[$uploadError] ?? 'Upload error code: ' . $uploadError);
    }
    soi_redirect(SOI_ADMIN_URL . '/updates.php');
}

$updateLog   = Update::getUpdateLog();
$cmsVersion  = Database::getOption('cms_version', '1.0.0');
$phpVersion  = PHP_VERSION;
$zipEnabled  = extension_loaded('zip');

require_once __DIR__ . '/partials/header.php';
?>

<div class="dashboard-grid workspace-grid workspace-grid--split">

  <div class="workspace-main">

    <details class="help-disclosure help-disclosure--inline">
      <summary>Package requirements &amp; manifest format</summary>
      <div class="help-disclosure-body">
        <ol>
          <li>Upload a <strong>.zip</strong> update package with <code>manifest.json</code> at the root.</li>
          <li>Files are extracted and SQL patches run automatically when valid.</li>
          <li>The site version is updated from the manifest.</li>
        </ol>
        <strong>manifest.json example:</strong>
        <pre><?= esc(json_encode([
            'version' => '1.1.0',
            'name' => 'My Feature Update',
            'type' => 'core',
            'min_php' => '8.0',
            'description' => 'Adds new features',
        ], JSON_PRETTY_PRINT)) ?></pre>
      </div>
    </details>

    <?php if (!$zipEnabled): ?>
    <div class="alert alert-error">PHP ZipArchive extension is not installed. Updates cannot be processed.</div>
    <?php else: ?>
    <div class="card">
      <div class="card-header"><h3 class="card-title">Upload Update Package</h3></div>
      <div class="card-body">
        <form method="POST" enctype="multipart/form-data" id="update-form">
          <?= Auth::csrfField() ?>
          <div class="upload-zone" id="update-zone">
            <div class="upload-zone-text">Click to select update package</div>
            <div class="upload-zone-hint">Only .zip files · Max <?= ini_get('upload_max_filesize') ?></div>
          </div>
          <input type="file" id="update-file-input" name="update_zip" accept=".zip" class="upload-file-input" style="display:none;">

          <div class="update-progress" id="update-progress" style="display:none;">
            <div class="progress-bar-wrap"><div class="progress-bar-fill"></div></div>
            <div class="text-muted" style="font-size:0.78rem;text-align:center;">Installing update, please wait…</div>
          </div>

          <div id="selected-file-info" style="display:none;margin-top:0.75rem;padding:0.65rem 0.75rem;background:var(--surface2);border-radius:var(--soi-radius-sm);font-size:0.78rem;">
            <strong>Selected:</strong> <span id="file-name-display">—</span>
          </div>

          <div class="btn-row" style="border:none;padding-top:1rem;margin-top:0;">
            <button type="submit" class="btn btn-primary" id="install-btn" disabled>Install Update</button>
            <span class="text-muted" style="font-size:0.75rem;">Select a file first</span>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Update History</h3></div>
      <?php if (!$updateLog): ?>
      <div class="empty-state" style="padding:2.5rem 1.5rem;">
        <div class="empty-state-title">No updates installed yet</div>
      </div>
      <?php else: ?>
      <div class="table-wrap">
        <table class="table--dense">
          <thead><tr><th>Package</th><th>Version</th><th>Type</th><th>Files</th><th>Installed</th></tr></thead>
          <tbody>
            <?php foreach ($updateLog as $log): ?>
            <tr>
              <td class="td-title"><?= esc($log['name'] ?? '—') ?></td>
              <td><span class="badge badge-info">v<?= esc($log['version'] ?? '?') ?></span></td>
              <td><span class="badge badge-muted"><?= esc($log['type'] ?? 'core') ?></span></td>
              <td><?= (int)($log['files'] ?? 0) ?> files</td>
              <td class="text-muted nowrap"><?= esc($log['installed'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

  </div>

  <aside class="workspace-rail">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Current Version</h3></div>
      <div class="card-body">
        <div class="health-kv"><span>CMS</span><span>v<?= esc($cmsVersion) ?></span></div>
        <div class="health-kv"><span>PHP</span><span><?= esc($phpVersion) ?></span></div>
        <div class="health-kv"><span>ZipArchive</span><span><?= $zipEnabled ? 'Available' : 'Not installed' ?></span></div>
      </div>
    </div>

    <details class="help-disclosure">
      <summary>System information</summary>
      <div class="help-disclosure-body">
        <?php
        $info = [
            'Upload Max Size' => ini_get('upload_max_filesize'),
            'Post Max Size'   => ini_get('post_max_size'),
            'Memory Limit'    => ini_get('memory_limit'),
            'Max Exec Time'   => ini_get('max_execution_time') . 's',
            'OS'              => PHP_OS,
            'Server'          => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        ];
        foreach ($info as $k => $v):
        ?>
        <div class="health-kv"><span><?= esc($k) ?></span><span><?= esc((string)$v) ?></span></div>
        <?php endforeach; ?>
      </div>
    </details>
  </aside>

</div>

<script>
const zone = document.getElementById('update-zone');
const fileInput = document.getElementById('update-file-input');
const installBtn = document.getElementById('install-btn');
const fileInfo = document.getElementById('selected-file-info');
const fileName = document.getElementById('file-name-display');

if (zone && fileInput) {
  zone.addEventListener('click', () => fileInput.click());
  zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag-over'); });
  zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
  zone.addEventListener('drop', e => {
    e.preventDefault();
    zone.classList.remove('drag-over');
    if (e.dataTransfer.files.length) {
      const dt = new DataTransfer();
      dt.items.add(e.dataTransfer.files[0]);
      fileInput.files = dt.files;
      fileInput.dispatchEvent(new Event('change'));
    }
  });

  fileInput.addEventListener('change', function () {
    if (this.files.length) {
      const f = this.files[0];
      fileName.textContent = f.name + ' (' + Math.round(f.size/1024) + ' KB)';
      fileInfo.style.display = 'block';
      installBtn.disabled = false;
      installBtn.textContent = 'Install Update';
    }
  });
}

document.getElementById('update-form')?.addEventListener('submit', function () {
  installBtn.disabled = true;
  installBtn.textContent = 'Installing…';
  document.getElementById('update-progress').style.display = 'block';
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>