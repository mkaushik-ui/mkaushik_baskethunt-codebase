<?php
/**
 * Admin — Media Manager
 */
$pageTitle = 'Media Library';
$activeNav = 'media';
$pageContentClass = 'page-content--fluid media-library-page';

if (!defined('SOI_ROOT')) define('SOI_ROOT', dirname(__DIR__));
require_once SOI_ROOT . '/config/config.php';
require_once SOI_ROOT . '/core/helpers.php';
require_once SOI_ROOT . '/plugins/files-service-connector/plugin.php';
spl_autoload_register(fn($c) => (fn($f) => file_exists($f) && require_once $f)(SOI_ROOT.'/core/'.str_replace(['SOI\\Core\\','\\'],['','/'],$c).'.php'));
use SOI\Core\{Database, Auth};
Database::connect(['host'=>SOI_DB_HOST,'name'=>SOI_DB_NAME,'user'=>SOI_DB_USER,'pass'=>SOI_DB_PASS,'port'=>SOI_DB_PORT,'prefix'=>SOI_DB_PREFIX]);
Auth::init();
Auth::requireAuth('author');

$allowedMimes = ['image/jpeg','image/png','image/gif','image/webp','application/pdf','text/plain',
    'application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'video/mp4','video/webm','audio/mpeg','audio/wav'];

$error = ''; $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['_csrf'] ?? '')) {
        soi_flash('error', 'CSRF check failed. Reload the page and try again.');
        soi_redirect(SOI_ADMIN_URL . '/media.php');
    }

    if (isset($_FILES['media_file'])) {
        $file = $_FILES['media_file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE => 'The file exceeds the server upload size limit.',
                UPLOAD_ERR_FORM_SIZE => 'The file exceeds the form upload size limit.',
                UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded. Try again.',
                UPLOAD_ERR_NO_FILE => 'Choose a file to upload.',
                UPLOAD_ERR_NO_TMP_DIR => 'The server upload temporary directory is missing.',
                UPLOAD_ERR_CANT_WRITE => 'The server could not write the temporary upload.',
                UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload.',
            ];
            soi_flash('error', $uploadErrors[$file['error']] ?? 'The upload failed with an unknown server error.');
            soi_redirect(SOI_ADMIN_URL . '/media.php');
        }

        $mime = mime_content_type($file['tmp_name']);
        if ($mime === false || !in_array($mime, $allowedMimes, true)) {
            soi_flash('error', 'File type not allowed: ' . ($mime ?: 'unknown'));
        } elseif (!class_exists(\SOI\Core\MediaStorage::class)) {
            soi_flash('error', 'Private media storage is not available on this server.');
        } else {
            $hash = substr(hash('sha256', uniqid() . random_bytes(16)), 0, 16);
            try {
                if (!\SOI\Core\MediaStorage::ensurePrivateMediaRoot()) {
                    throw new RuntimeException('Private media storage is not writable.');
                }
                $destPath = \SOI\Core\MediaStorage::buildPrivateMediaPath($hash, date('Y'), date('m'));
                if (!\SOI\Core\MediaStorage::moveUploadedFileToPrivateStorage($file['tmp_name'], $destPath)) {
                    throw new RuntimeException('Could not move the uploaded file into private storage.');
                }

                try {
                    $mediaId = Database::insert('media', [
                        'filename'      => $hash,
                        'original_name' => $file['name'],
                        'mime_type'     => $mime,
                        'file_size'     => $file['size'],
                        'path'          => $destPath,
                        'url'           => SOI_HOME_URL . '/media/view/' . $hash,
                        'alt_text'      => '',
                        'uploaded_by'   => Auth::id(),
                    ]);
                } catch (Throwable $databaseError) {
                    @unlink($destPath);
                    throw $databaseError;
                }

                $offloadMessage = '';
                if (class_exists('FileServiceMediaAdapter') && FileServiceMediaAdapter::shouldOffloadNewUploads()) {
                    try {
                        $offload = FileServiceMediaAdapter::offloadMediaRow($mediaId, 'new_upload');
                        if (($offload['success'] ?? false) === true) {
                            $offloadMessage = ' Files Service offload mapping saved.';
                        } else {
                            $offloadMessage = ' Files Service offload skipped: ' . (string) ($offload['error'] ?? 'upload failed');
                        }
                    } catch (Throwable $offloadError) {
                        $offloadMessage = ' Files Service offload skipped safely.';
                    }
                }

                soi_flash('success', 'File uploaded securely: ' . $file['name'] . $offloadMessage);
            } catch (Throwable $uploadError) {
                error_log('[Media Upload] ' . $uploadError->getMessage());
                soi_flash('error', 'Upload failed. Check private storage permissions and try again.');
            }
        }
        soi_redirect(SOI_ADMIN_URL . '/media.php');
    }

    if (($_POST['_action'] ?? '') === 'retry_files_service_upload') {
        Auth::requireAuth('admin');
        $retryId = (int) ($_POST['id'] ?? 0);
        if ($retryId < 1) {
            soi_flash('error', 'Choose a valid media file to retry.');
            soi_redirect(SOI_ADMIN_URL . '/media.php');
        }

        if (!class_exists('FileServiceMediaAdapter')) {
            soi_flash('error', 'Files Service media adapter is not available.');
            soi_redirect(SOI_ADMIN_URL . '/media.php');
        }

        try {
            $retry = FileServiceMediaAdapter::retryOffload($retryId);
            if (($retry['success'] ?? false) === true) {
                $msg = (string) ($retry['message'] ?? 'Files Service upload retry completed.');
                if (!empty($retry['media_url'])) {
                    $msg .= ' Media URL: ' . (string) $retry['media_url'];
                }
                if (!empty($retry['duplicate_warning'])) {
                    $msg .= ' Warning: previous remote file retained in history (not deleted).';
                }
                soi_flash('success', $msg);
            } else {
                soi_flash('error', 'Files Service upload retry failed safely: ' . (string) ($retry['error'] ?? 'upload failed'));
            }
        } catch (Throwable $retryError) {
            soi_flash('error', 'Files Service upload retry failed safely. Local media was not changed.');
        }

        soi_redirect(SOI_ADMIN_URL . '/media.php');
    }

    if (($_POST['_action'] ?? '') === 'unmap_files_service') {
        Auth::requireAuth('admin');
        $unmapId = (int) ($_POST['id'] ?? 0);
        if ($unmapId < 1 || !class_exists('FileServiceMediaAdapter')) {
            soi_flash('error', 'Choose a valid media file to unmap.');
            soi_redirect(SOI_ADMIN_URL . '/media.php');
        }
        try {
            $unmap = FileServiceMediaAdapter::unmapMedia($unmapId);
            if (($unmap['success'] ?? false) === true) {
                $msg = (string) ($unmap['message'] ?? 'Unmapped.');
                if (!empty($unmap['content_note'])) {
                    $msg .= ' ' . (string) $unmap['content_note'];
                }
                soi_flash(!empty($unmap['content_warning']) ? 'error' : 'success', $msg);
            } else {
                soi_flash('error', (string) ($unmap['error'] ?? 'Unmap failed.'));
            }
        } catch (Throwable $e) {
            soi_flash('error', 'Unmap failed safely. Local and remote files were not deleted.');
        }
        soi_redirect(SOI_ADMIN_URL . '/media.php');
    }

    if (($_POST['_action'] ?? '') === 'remap_files_service') {
        Auth::requireAuth('admin');
        $remapId = (int) ($_POST['id'] ?? 0);
        if ($remapId < 1 || !class_exists('FileServiceMediaAdapter')) {
            soi_flash('error', 'Choose a valid media file to remap.');
            soi_redirect(SOI_ADMIN_URL . '/media.php');
        }
        try {
            $remap = FileServiceMediaAdapter::remapMedia($remapId);
            if (($remap['success'] ?? false) === true) {
                $msg = (string) ($remap['message'] ?? 'Remap completed.');
                if (!empty($remap['media_url'])) {
                    $msg .= ' Media URL: ' . (string) $remap['media_url'];
                }
                $msg .= ' Content was not rewritten automatically.';
                soi_flash('success', $msg);
            } else {
                soi_flash('error', (string) ($remap['error'] ?? 'Remap failed.'));
            }
        } catch (Throwable $e) {
            soi_flash('error', 'Remap failed safely. Local media was not deleted.');
        }
        soi_redirect(SOI_ADMIN_URL . '/media.php');
    }

    if (($_POST['_action'] ?? '') === 'replace_media_file') {
        Auth::requireAuth('admin');
        $replaceId = (int) ($_POST['id'] ?? 0);
        $file = $_FILES['replace_file'] ?? null;
        if ($replaceId < 1 || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            soi_flash('error', 'Choose a media item and a replacement file.');
            soi_redirect(SOI_ADMIN_URL . '/media.php');
        }
        $existing = Database::selectOne("SELECT * FROM `" . Database::prefix('media') . "` WHERE id = ?", [$replaceId]);
        if (!$existing) {
            soi_flash('error', 'Media item not found.');
            soi_redirect(SOI_ADMIN_URL . '/media.php');
        }
        $mime = mime_content_type($file['tmp_name']);
        if ($mime === false || !in_array($mime, $allowedMimes, true)) {
            soi_flash('error', 'Replacement file type not allowed.');
            soi_redirect(SOI_ADMIN_URL . '/media.php');
        }
        try {
            $destPath = (string) ($existing['path'] ?? '');
            if ($destPath === '' || !class_exists(\SOI\Core\MediaStorage::class)) {
                throw new RuntimeException('Existing media path is not replaceable.');
            }
            // Write to a temp path then swap, preserving filename hash when possible.
            $hash = (string) ($existing['filename'] ?? substr(hash('sha256', uniqid() . random_bytes(8)), 0, 16));
            $newPath = \SOI\Core\MediaStorage::buildPrivateMediaPath($hash, date('Y'), date('m'));
            if (!\SOI\Core\MediaStorage::moveUploadedFileToPrivateStorage($file['tmp_name'], $newPath)) {
                throw new RuntimeException('Could not store replacement file.');
            }
            $oldPath = $destPath;
            Database::update('media', [
                'path' => $newPath,
                'mime_type' => $mime,
                'file_size' => (string) ($file['size'] ?? 0),
                'original_name' => (string) ($file['name'] ?? $existing['original_name']),
                'url' => SOI_HOME_URL . '/media/view/' . rawurlencode($hash),
                'filename' => $hash,
            ], 'id = ?', [$replaceId]);
            if ($oldPath !== '' && $oldPath !== $newPath && is_file($oldPath)) {
                @unlink($oldPath);
            }

            $mapMsg = ' Local file replaced.';
            if (class_exists('FileServiceMediaAdapter')) {
                $map = FileServiceMediaAdapter::offloadMediaRow($replaceId, 'media_replace', ['force' => true, 'replace_existing' => true]);
                if (($map['success'] ?? false) === true) {
                    $mapMsg .= ' New Files Service mapping created.';
                    if (!empty($map['previous_file_id'])) {
                        $mapMsg .= ' Previous remote file_id retained as history (not deleted).';
                    }
                    $mapMsg .= ' Content URLs were not rewritten automatically — use Preview/Commit if needed.';
                } else {
                    $mapMsg .= ' Files Service remap skipped: ' . (string) ($map['error'] ?? 'not uploaded');
                }
            }
            soi_flash('success', 'Media replaced.' . $mapMsg);
        } catch (Throwable $e) {
            error_log('[Media Replace] ' . $e->getMessage());
            soi_flash('error', 'Replace failed safely. Existing mapping was not hard-deleted remotely.');
        }
        soi_redirect(SOI_ADMIN_URL . '/media.php');
    }

    if (($_POST['_action'] ?? '') === 'delete') {
        $delId = (int)($_POST['id'] ?? 0);
        if ($delId) {
            Auth::requireAuth('author');
            $lifecycleNote = '';
            if (class_exists('FileServiceMediaAdapter')) {
                try {
                    $lifecycle = FileServiceMediaAdapter::handleMediaDelete($delId);
                    $lifecycleNote = ' ' . (string) ($lifecycle['message'] ?? '');
                    if (!empty($lifecycle['content_warning'])) {
                        $lifecycleNote .= ' Warning: content may still reference remote media URLs. Run Verify Frontend References. Content was not rewritten.';
                    }
                } catch (Throwable $e) {
                    $lifecycleNote = ' Mapping lifecycle note could not be saved; remote file was still not deleted by CMS.';
                }
            }
            $file = Database::selectOne("SELECT * FROM `" . Database::prefix('media') . "` WHERE id = ?", [$delId]);
            if ($file && !empty($file['path']) && file_exists($file['path'])) {
                @unlink($file['path']);
            }
            Database::delete('media', 'id = ?', [$delId]);
            soi_flash('success', 'Local CMS media deleted.' . $lifecycleNote);
        }
        soi_redirect(SOI_ADMIN_URL . '/media.php');
    }
}

$mediaFiles = Database::select("SELECT * FROM `" . Database::prefix('media') . "` ORDER BY created_at DESC");
$filesServiceSettings = function_exists('fs_connector_get_settings') ? fs_connector_get_settings() : [
    'delivery_mode' => 'local',
    'media_offload_enabled' => false,
    'offload_new_uploads_enabled' => false,
];

$topbarActions = '<a href="' . SOI_ADMIN_URL . '/media-migrate.php" class="topbar-btn">'.soi_admin_icon('warning', 14, 'topbar-btn-icon').' Legacy Extensionless Migration</a>';
$topbarActions .= '<a href="' . SOI_ADMIN_URL . '/media-private-storage-migrate.php" class="topbar-btn topbar-btn-primary">'.soi_admin_icon('security', 14, 'topbar-btn-icon').' Private Storage Migration</a>';
$topbarActions .= '<a href="' . SOI_ADMIN_URL . '/files-service-connector.php" class="topbar-btn">'.soi_admin_icon('cloud', 14, 'topbar-btn-icon').' Files Service Connector</a>';
$topbarActions .= '<label for="quick-upload" class="topbar-btn topbar-btn-upload">'.soi_admin_icon('upload', 14, 'topbar-btn-icon').' Upload File</label>';

$extraScripts = '<script src="' . esc(soi_admin_asset_url('admin-performance.js')) . '?v=1.2.10.1" defer></script>';
$extraHead = '<link rel="stylesheet" href="' . esc(soi_admin_asset_url('admin-performance.css')) . '?v=1.2.10.1">' . "\n";
$extraHead .= <<<'MEDIA_LIBRARY_CSS'
<style>
/* Media Library card UI — scoped to /admin/media.php only */
.admin-content:has(.media-library-page) .topbar-actions {
  flex-wrap: wrap;
  row-gap: 0.45rem;
  column-gap: 0.5rem;
  max-width: 100%;
}

.media-library {
  --ml-thumb-h: 148px;
  --ml-radius: var(--soi-radius-md, 0.5rem);
  width: 100%;
  max-width: 100%;
  overflow-x: hidden;
}

.media-library .ml-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 1.25rem;
  width: 100%;
}

@media (max-width: 1440px) {
  .media-library .ml-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (max-width: 1024px) {
  .media-library .ml-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 640px) {
  .media-library .ml-grid { grid-template-columns: minmax(0, 1fr); }
}

.media-library .ml-card {
  display: flex;
  flex-direction: column;
  min-width: 0;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--ml-radius);
  box-shadow: var(--soi-shadow-sm);
  overflow: hidden;
  transition: border-color 0.2s, box-shadow 0.2s;
}

.media-library .ml-card:hover {
  border-color: var(--soi-border-strong);
  box-shadow: var(--soi-shadow-md, 0 4px 12px rgba(15, 23, 42, 0.08));
}

.media-library .ml-card-thumb {
  position: relative;
  height: var(--ml-thumb-h);
  background: var(--surface2);
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}

.media-library .ml-card-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.media-library .ml-card-thumb img.ml-thumb--hidden { display: none; }

.media-library .ml-thumb-placeholder {
  display: none;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.35rem;
  width: 100%;
  height: 100%;
  color: var(--text-muted);
  font-size: 0.72rem;
  text-align: center;
  padding: 0.5rem;
}

.media-library .ml-thumb-placeholder.is-visible { display: flex; }

.media-library .ml-thumb-placeholder-icon {
  font-size: 1.75rem;
  line-height: 1;
  opacity: 0.85;
}

.media-library .ml-card-body {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
  padding: 0.75rem 0.85rem 0.85rem;
  min-width: 0;
}

.media-library .ml-card-title {
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--text);
  line-height: 1.35;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.media-library .ml-card-subtitle {
  margin-top: 0.15rem;
  font-size: 0.68rem;
  color: var(--text-muted);
  font-weight: 500;
}

.media-library .ml-meta {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
  min-width: 0;
}

.media-library .ml-meta-row {
  display: grid;
  grid-template-columns: 5.5rem minmax(0, 1fr);
  gap: 0.5rem;
  align-items: center;
  min-width: 0;
}

.media-library .ml-meta-label {
  font-size: 0.68rem;
  font-weight: 600;
  color: var(--text-muted);
  white-space: nowrap;
}

.media-library .ml-meta-value {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  min-width: 0;
  flex-wrap: wrap;
}

.media-library .ml-pill {
  display: inline-flex;
  align-items: center;
  padding: 0.12rem 0.5rem;
  border-radius: 999px;
  font-size: 0.62rem;
  font-weight: 600;
  line-height: 1.25;
  white-space: nowrap;
  letter-spacing: 0.01em;
}

.media-library .ml-pill--success { background: var(--soi-success-soft); color: var(--soi-success); }
.media-library .ml-pill--warning { background: var(--soi-warning-soft); color: var(--soi-warning); }
.media-library .ml-pill--danger { background: var(--soi-danger-soft); color: var(--soi-danger); }
.media-library .ml-pill--muted { background: var(--surface2); color: var(--text-muted); }
.media-library .ml-pill--info { background: var(--soi-primary-soft); color: var(--soi-primary); }

.media-library .ml-mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 0.68rem;
  color: var(--text);
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: 0.3rem;
  padding: 0.15rem 0.4rem;
  min-width: 0;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.media-library .ml-mono.is-expanded {
  white-space: normal;
  word-break: break-all;
  overflow: visible;
  text-overflow: unset;
}

.media-library .ml-short-link {
  font-size: 0.68rem;
  color: var(--brand);
  text-decoration: none;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.media-library .ml-short-link:hover { text-decoration: underline; }

.media-library .ml-inline-actions {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  margin-left: auto;
  flex-shrink: 0;
}

.media-library .ml-btn-mini {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.12rem 0.45rem;
  font-size: 0.62rem;
  font-weight: 600;
  line-height: 1.2;
  border-radius: 0.3rem;
  border: 1px solid var(--border);
  background: var(--surface);
  color: var(--text);
  cursor: pointer;
  text-decoration: none;
  white-space: nowrap;
}

.media-library .ml-btn-mini:hover {
  background: var(--surface2);
  border-color: var(--soi-border-strong);
}

.media-library .ml-error-note {
  font-size: 0.65rem;
  color: var(--soi-danger);
  background: var(--soi-danger-soft);
  border-left: 2px solid var(--soi-danger);
  padding: 0.3rem 0.45rem;
  border-radius: 0.25rem;
  line-height: 1.35;
  word-break: break-word;
}

.media-library .ml-card-actions {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
  padding-top: 0.55rem;
  margin-top: 0.15rem;
  border-top: 1px solid var(--border);
}

.media-library .ml-action-group {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  align-items: center;
}

.media-library .ml-action-group--danger {
  padding-top: 0.15rem;
  border-top: 1px dashed var(--border);
}

.media-library .ml-action-group .btn {
  font-size: 0.7rem;
  padding: 0.28rem 0.55rem;
}

.media-library .ml-action-group .btn-danger {
  margin-left: 0;
}
</style>
MEDIA_LIBRARY_CSS;

require_once __DIR__ . '/partials/header.php';

$mlShortId = static function (string $id, int $visible = 13): string {
    $id = trim($id);
    if ($id === '') {
        return '';
    }
    if (strlen($id) <= $visible + 3) {
        return $id;
    }
    return substr($id, 0, $visible) . '...';
};

$mlShortMediaUrlLabel = static function (string $mediaUrl, string $fileId) use ($mlShortId): string {
    if ($fileId !== '') {
        return 'files.soi.co.in/media/' . $mlShortId($fileId, 8);
    }
    if (preg_match('#files\.soi\.co\.in/media/([^/?#]+)#', $mediaUrl, $matches)) {
        return 'files.soi.co.in/media/' . $mlShortId((string) $matches[1], 8);
    }
    $trimmed = preg_replace('#^https?://#', '', $mediaUrl) ?? $mediaUrl;
    if (strlen($trimmed) > 42) {
        return substr($trimmed, 0, 39) . '...';
    }
    return $trimmed;
};

$mlMimeIcon = static function (?string $mime): string {
    $mime = (string) $mime;
    if (str_contains($mime, 'pdf')) {
        return '📄';
    }
    if (str_contains($mime, 'video')) {
        return '🎬';
    }
    if (str_contains($mime, 'audio')) {
        return '🎵';
    }
    if (str_starts_with($mime, 'image/')) {
        return '🖼️';
    }
    return '📎';
};
?>

<!-- Hidden Quick Upload -->
<form method="POST" enctype="multipart/form-data" id="quick-upload-form">
  <?= Auth::csrfField() ?>
  <input type="file" id="quick-upload" class="upload-file-input" name="media_file" accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.txt" style="display:none;"
    onchange="this.form.submit()">
</form>

<!-- Upload Zone -->
<div class="upload-zone" onclick="document.getElementById('quick-upload').click()" style="cursor:pointer;">
  <div class="upload-zone-text">Click to upload or drag & drop files here</div>
  <div class="upload-zone-hint">Images, PDF, Word, Video, Audio (max <?= ini_get('upload_max_filesize') ?>)</div>
</div>

<!-- Media Grid -->
<?php if (!$mediaFiles): ?>
<div class="empty-state">
  <div class="empty-state-title">No media files yet</div>
  <div class="empty-state-text">Upload your first file using the zone above.</div>
</div>
<?php else: ?>
<?php
$privateCount = 0;
$legacyCount = 0;
$missingCount = 0;
foreach ($mediaFiles as $m) {
    if (!file_exists($m['path'])) {
        $missingCount++;
    } elseif (class_exists(\SOI\Core\MediaStorage::class) && \SOI\Core\MediaStorage::isPathInsidePrivateMediaRoot($m['path'])) {
        $privateCount++;
    } else {
        $legacyCount++;
    }
}
?>
<p class="page-toolbar-meta"><?= count($mediaFiles) ?> file(s) · <?= format_bytes(array_sum(array_column($mediaFiles,'file_size'))) ?> total</p>

<?php if ($legacyCount > 0 || $missingCount > 0): ?>
<div class="alert alert-warning storage-alert">
  <div>
    <?php if ($legacyCount > 0): ?>
    <strong><?= $legacyCount ?></strong> file(s) still in legacy public storage.
    <?php endif; ?>
    <?php if ($missingCount > 0): ?>
    <strong><?= $missingCount ?></strong> file(s) missing on disk.
    <?php endif; ?>
  </div>
  <?php if ($legacyCount > 0): ?>
  <a href="<?= SOI_ADMIN_URL ?>/media-private-storage-migrate.php?mode=dry-run" class="btn btn-warning btn-sm">Run Dry Run</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<details class="help-disclosure help-disclosure--inline">
  <summary>Storage breakdown</summary>
  <div class="help-disclosure-body">
    <div class="health-kv"><span>Private storage</span><span><?= $privateCount ?> files</span></div>
    <div class="health-kv"><span>Legacy public</span><span><?= $legacyCount ?> files</span></div>
    <div class="health-kv"><span>Missing</span><span><?= $missingCount ?> files</span></div>
    <div class="health-kv"><span>Files Service delivery mode</span><span><?= esc($filesServiceSettings['delivery_mode'] ?? 'local') ?></span></div>
    <div class="health-kv"><span>New upload offload</span><span><?= !empty($filesServiceSettings['offload_new_uploads_enabled']) ? 'Enabled' : 'Off' ?></span></div>
  </div>
</details>

<div class="media-library">
  <div class="ml-grid">
  <?php foreach ($mediaFiles as $media):
      $fileExists = file_exists($media['path']);
      $isPrivate = class_exists(\SOI\Core\MediaStorage::class) && \SOI\Core\MediaStorage::isPathInsidePrivateMediaRoot($media['path']);
      $localBadgeClass = !$fileExists ? 'ml-pill--danger' : ($isPrivate ? 'ml-pill--success' : 'ml-pill--warning');
      $localBadgeText = !$fileExists ? 'Missing' : ($isPrivate ? 'Private' : 'Legacy');
      $life = class_exists('FileServiceMediaAdapter')
          ? FileServiceMediaAdapter::mediaCardLifecycle($media)
          : ['status' => 'unmapped', 'status_label' => 'Not mapped', 'mapping' => null, 'file_id' => '', 'media_url' => '', 'last_error' => '', 'can_retry' => false, 'can_unmap' => false, 'can_remap' => false, 'remote_url_status' => 'none', 'duplicate_active' => false, 'content_may_use_remote' => false, 'previous_file_id' => '', 'remote_cleanup_note' => ''];
      $mapping = $life['mapping'] ?? null;
      $mediaUrl = (string) ($life['media_url'] ?? '');
      $fileId = (string) ($life['file_id'] ?? '');
      $localViewUrl = !empty($media['filename']) ? SOI_HOME_URL . '/media/view/' . rawurlencode((string) $media['filename']) : (string) ($media['url'] ?? '');
      $displayUrl = (string) ($media['url'] ?? $localViewUrl);
      $activeMapped = in_array((string) ($life['status'] ?? ''), ['mapped', 'remote_verified'], true) && $mediaUrl !== '';
      if (($filesServiceSettings['delivery_mode'] ?? 'local') === 'local' && $localViewUrl !== '') {
          $displayUrl = $localViewUrl;
      } elseif (in_array(($filesServiceSettings['delivery_mode'] ?? 'local'), ['hybrid', 'files_service'], true) && $activeMapped) {
          $displayUrl = $mediaUrl;
      } else {
          $displayUrl = $localViewUrl !== '' ? $localViewUrl : $displayUrl;
      }
      $syncStatus = (string) ($life['status_label'] ?? 'Unknown');
      $fsBadgeClass = match ((string) ($life['status'] ?? '')) {
          'mapped', 'remote_verified' => 'ml-pill--success',
          'retry_required', 'upload_failed', 'local_missing' => 'ml-pill--danger',
          'pending_upload', 'remote_unknown', 'replaced' => 'ml-pill--warning',
          'unmapped', 'cms_deleted', 'orphaned_mapping' => 'ml-pill--muted',
          default => 'ml-pill--muted',
      };
      $remoteStatusLabel = match ((string) ($life['remote_url_status'] ?? 'none')) {
          'canonical_media' => 'Canonical /media',
          'legacy_files_view' => 'Legacy /files/view',
          'non_canonical' => 'Non-canonical',
          default => 'None',
      };
      $shortFileId = $fileId !== '' ? $mlShortId($fileId) : '';
      $shortMediaUrlLabel = $mediaUrl !== '' ? $mlShortMediaUrlLabel($mediaUrl, $fileId) : '';
      $isImage = str_starts_with($media['mime_type'] ?? '', 'image/');
      $mimeIcon = $mlMimeIcon($media['mime_type'] ?? '');
      $showRetry = !empty($life['can_retry']);
      $showUnmap = !empty($life['can_unmap']);
      $showRemap = !empty($life['can_remap']);
  ?>
  <article class="ml-card">
    <div class="ml-card-thumb<?= $isImage ? ' is-thumb-loading' : '' ?>">
      <?php if ($isImage): ?>
      <div class="ml-skel" aria-hidden="true"></div>
      <img src="<?= esc($displayUrl) ?>" alt="<?= esc($media['alt_text'] ?: $media['original_name']) ?>" loading="lazy" decoding="async" class="ml-thumb-image" onerror="this.classList.add('ml-thumb--hidden');this.parentElement?.classList.add('is-thumb-error');this.parentElement?.classList.remove('is-thumb-loading');this.nextElementSibling?.classList.add('is-visible');" onload="this.parentElement?.classList.add('is-thumb-loaded');this.parentElement?.classList.remove('is-thumb-loading');">
      <?php endif; ?>
      <div class="ml-thumb-placeholder<?= $isImage ? '' : ' is-visible' ?>">
        <span class="ml-thumb-placeholder-icon" aria-hidden="true"><?= $mimeIcon ?></span>
        <span><?= $isImage ? 'Preview unavailable' : esc(strtoupper((string) preg_replace('#^([^/]+)/.*$#', '$1', (string) ($media['mime_type'] ?? 'file')))) ?></span>
      </div>
    </div>

    <div class="ml-card-body">
      <div>
        <div class="ml-card-title" title="<?= esc($media['original_name']) ?>"><?= esc($media['original_name']) ?></div>
        <div class="ml-card-subtitle"><?= format_bytes((int) $media['file_size']) ?></div>
      </div>

      <div class="ml-meta">
        <div class="ml-meta-row">
          <span class="ml-meta-label">Local</span>
          <span class="ml-meta-value">
            <span class="ml-pill <?= esc($localBadgeClass) ?>"><?= esc($localBadgeText) ?></span>
          </span>
        </div>

        <div class="ml-meta-row">
          <span class="ml-meta-label">Files Svc</span>
          <span class="ml-meta-value">
            <span class="ml-pill <?= esc($fsBadgeClass) ?>"><?= esc($syncStatus) ?></span>
          </span>
        </div>

        <div class="ml-meta-row">
          <span class="ml-meta-label">Sync</span>
          <span class="ml-meta-value">
            <span class="ml-pill <?= esc($fsBadgeClass) ?>"><?= esc((string) ($life['status'] ?? 'unknown')) ?></span>
            <?php if (!empty($life['duplicate_active'])): ?>
            <span class="ml-pill ml-pill--warning">Duplicate maps</span>
            <?php endif; ?>
          </span>
        </div>

        <div class="ml-meta-row">
          <span class="ml-meta-label">Remote URL</span>
          <span class="ml-meta-value"><span class="ml-pill ml-pill--muted"><?= esc($remoteStatusLabel) ?></span></span>
        </div>

        <?php if ($fileId !== ''): ?>
        <div class="ml-meta-row">
          <span class="ml-meta-label">File ID</span>
          <span class="ml-meta-value">
            <code class="ml-mono" title="<?= esc($fileId) ?>" data-full-id="<?= esc($fileId) ?>"><?= esc($shortFileId) ?></code>
            <span class="ml-inline-actions">
              <button type="button" class="ml-btn-mini" data-copy-text="<?= esc($fileId) ?>" data-copy-label="Copy">Copy</button>
              <button type="button" class="ml-btn-mini" data-reveal-id>Details</button>
            </span>
          </span>
        </div>
        <?php endif; ?>

        <?php if (!empty($life['previous_file_id'])): ?>
        <div class="ml-meta-row">
          <span class="ml-meta-label">Previous</span>
          <span class="ml-meta-value"><code class="ml-mono" title="<?= esc((string) $life['previous_file_id']) ?>"><?= esc($mlShortId((string) $life['previous_file_id'])) ?></code></span>
        </div>
        <?php endif; ?>

        <?php if ($mediaUrl !== '' && $activeMapped): ?>
        <div class="ml-meta-row">
          <span class="ml-meta-label">Media URL</span>
          <span class="ml-meta-value">
            <a href="<?= esc($mediaUrl) ?>" target="_blank" rel="noopener" class="ml-short-link" title="<?= esc($mediaUrl) ?>"><?= esc($shortMediaUrlLabel) ?></a>
            <span class="ml-inline-actions">
              <a href="<?= esc($mediaUrl) ?>" target="_blank" rel="noopener" class="ml-btn-mini">Open</a>
              <button type="button" class="ml-btn-mini" data-copy-text="<?= esc($mediaUrl) ?>" data-copy-label="Copy">Copy</button>
            </span>
          </span>
        </div>
        <?php endif; ?>

        <?php if (!empty($life['last_error'])): ?>
        <div class="ml-error-note"><strong>Last sync:</strong> <?= esc(substr((string) $life['last_error'], 0, 180)) ?></div>
        <?php endif; ?>
        <?php if (!empty($life['content_may_use_remote'])): ?>
        <div class="ml-error-note"><strong>Content:</strong> Remote URL may be used in pages/posts. Lifecycle actions do not auto-rewrite content.</div>
        <?php endif; ?>
      </div>

      <div class="ml-card-actions">
        <div class="ml-action-group ml-action-group--primary">
          <a href="<?= esc($localViewUrl !== '' ? $localViewUrl : $displayUrl) ?>" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">View Local</a>
          <?php if ($mediaUrl !== '' && $activeMapped): ?>
          <a href="<?= esc($mediaUrl) ?>" target="_blank" rel="noopener" class="btn btn-primary btn-sm">Open Remote</a>
          <?php endif; ?>
        </div>

        <div class="ml-action-group ml-action-group--secondary">
          <?php if ($mediaUrl !== '' && $activeMapped): ?>
          <button type="button" class="btn btn-ghost btn-sm" data-copy-text="<?= esc($mediaUrl) ?>" data-copy-label="Copy Remote URL">Copy Remote URL</button>
          <?php endif; ?>
          <?php if ($showRetry): ?>
          <form method="POST" style="display:inline;margin:0;">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="_action" value="retry_files_service_upload">
            <input type="hidden" name="id" value="<?= (int) $media['id'] ?>">
            <button type="submit" class="btn btn-ghost btn-sm" data-confirm="Retry Files Service upload for this local file? Local media will be kept. No duplicate active mapping will be created if already mapped.">Retry</button>
          </form>
          <?php endif; ?>
          <?php if ($showRemap): ?>
          <form method="POST" style="display:inline;margin:0;">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="_action" value="remap_files_service">
            <input type="hidden" name="id" value="<?= (int) $media['id'] ?>">
            <button type="submit" class="btn btn-ghost btn-sm" data-confirm="Remap/reupload this local file to Files Service? Content URLs will not be rewritten automatically.">Remap</button>
          </form>
          <?php endif; ?>
          <form method="POST" action="<?= SOI_ADMIN_URL ?>/files-service-connector.php" style="display:inline;margin:0;">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="_action" value="reference_preview">
            <button type="submit" class="btn btn-ghost btn-sm">Preview Rewrite</button>
          </form>
          <details class="ml-replace-details" style="display:inline-block;margin:0;">
            <summary class="btn btn-ghost btn-sm" style="cursor:pointer;list-style:none;">Replace</summary>
            <form method="POST" enctype="multipart/form-data" style="margin-top:0.35rem;">
              <?= Auth::csrfField() ?>
              <input type="hidden" name="_action" value="replace_media_file">
              <input type="hidden" name="id" value="<?= (int) $media['id'] ?>">
              <input type="file" name="replace_file" required style="max-width:12rem;">
              <button type="submit" class="btn btn-warning btn-sm" data-confirm="Replace local file? Previous remote mapping will be kept as history (not hard-deleted). Content will not auto-rewrite.">Upload replacement</button>
            </form>
          </details>
        </div>

        <div class="ml-action-group ml-action-group--danger">
          <?php if ($showUnmap): ?>
          <form method="POST" style="display:inline;margin:0;">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="_action" value="unmap_files_service">
            <input type="hidden" name="id" value="<?= (int) $media['id'] ?>">
            <button type="submit" class="btn btn-warning btn-sm" data-confirm="DANGEROUS: Unmap this media from Files Service delivery? Local file is kept. Remote Files Service file is NOT deleted. Content is not rewritten.">Unmap</button>
          </form>
          <?php endif; ?>
          <form method="POST" style="display:inline;margin:0;">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="_action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $media['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm" data-confirm="DANGEROUS: Delete local CMS media permanently? Mapping will be marked cms_deleted. Remote Files Service file will NOT be hard-deleted.">Delete</button>
          </form>
        </div>
      </div>
    </div>
  </article>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<script>
(function () {
  const zone = document.querySelector('.upload-zone');
  const input = document.getElementById('quick-upload');
  if (zone && input) {
    zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag-over'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
    zone.addEventListener('drop', e => {
      e.preventDefault();
      zone.classList.remove('drag-over');
      if (e.dataTransfer.files.length) {
        const dt = new DataTransfer();
        for (const f of e.dataTransfer.files) dt.items.add(f);
        input.files = dt.files;
        document.getElementById('quick-upload-form').submit();
      }
    });
  }

  async function copyText(button, text) {
    if (!text) return;
    const original = button.textContent;
    try {
      await navigator.clipboard.writeText(text);
      button.textContent = 'Copied';
      setTimeout(() => { button.textContent = button.getAttribute('data-copy-label') || original; }, 1200);
    } catch (err) {
      window.prompt('Copy to clipboard', text);
    }
  }

  document.querySelectorAll('[data-copy-text]').forEach(button => {
    button.addEventListener('click', () => {
      copyText(button, button.getAttribute('data-copy-text') || '');
    });
  });

  document.querySelectorAll('[data-reveal-id]').forEach(button => {
    button.addEventListener('click', () => {
      const row = button.closest('.ml-meta-value');
      const code = row ? row.querySelector('.ml-mono') : null;
      if (!code) return;
      const expanded = code.classList.toggle('is-expanded');
      const fullId = code.getAttribute('data-full-id') || code.textContent || '';
      if (expanded) {
        code.textContent = fullId;
        button.textContent = 'Hide';
      } else {
        const short = fullId.length > 16 ? fullId.slice(0, 13) + '...' : fullId;
        code.textContent = short;
        button.textContent = 'Details';
      }
    });
  });
})();
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>