<?php
/**
 * Admin — CMS Production Readiness (final audit panel)
 */
$pageTitle = 'CMS Production Readiness';
$activeNav = 'cms-readiness';
$pageContentClass = 'page-content--fluid';

if (!defined('SOI_ROOT')) {
    define('SOI_ROOT', dirname(__DIR__));
}
require_once SOI_ROOT . '/config/config.php';
require_once SOI_ROOT . '/core/helpers.php';
require_once SOI_ROOT . '/plugins/files-service-connector/plugin.php';
spl_autoload_register(fn($c) => (fn($f) => file_exists($f) && require_once $f)(SOI_ROOT . '/core/' . str_replace(['SOI\\Core\\', '\\'], ['', '/'], $c) . '.php'));
use SOI\Core\{Database, Auth};

Database::connect([
    'host' => SOI_DB_HOST,
    'name' => SOI_DB_NAME,
    'user' => SOI_DB_USER,
    'pass' => SOI_DB_PASS,
    'port' => SOI_DB_PORT,
    'prefix' => SOI_DB_PREFIX,
]);
Auth::init();
Auth::requireAuth('admin');

$readiness = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf((string) ($_POST['_csrf'] ?? ''))) {
        soi_flash('error', 'CSRF check failed. Reload the page and try again.');
        soi_redirect(SOI_ADMIN_URL . '/cms-readiness.php');
    }
    if (($_POST['_action'] ?? '') === 'run_final_readiness') {
        $readiness = soi_cms_final_production_readiness_check();
        soi_flash(
            ($readiness['final_status'] ?? '') === 'Ready' ? 'success' : 'error',
            'Final readiness check completed: ' . (string) ($readiness['final_status'] ?? 'Unknown')
        );
    }
}

if ($readiness === null) {
    $stored = (string) Database::getOption('cms_last_production_readiness', '');
    $decoded = $stored !== '' ? json_decode($stored, true) : null;
    if (is_array($decoded) && !empty($decoded['final_status'])) {
        // Show last summary quickly; full detail requires re-run.
        $readiness = [
            'success' => true,
            'timestamp' => (string) ($decoded['timestamp'] ?? Database::getOption('cms_last_production_readiness_at', '')),
            'final_status' => (string) $decoded['final_status'],
            'cms_version' => (string) ($decoded['cms_version'] ?? Database::getOption('cms_version', '')),
            'connector_version' => (string) ($decoded['connector_version'] ?? (defined('FS_CONNECTOR_VERSION') ? FS_CONNECTOR_VERSION : '')),
            'score' => is_array($decoded['score'] ?? null) ? $decoded['score'] : ['pass' => 0, 'warn' => 0, 'fail' => 0, 'total' => 0],
            'areas' => [],
            'blocking' => [],
            'warnings' => ['Re-run the full check to refresh detailed area results.'],
            'from_cache' => true,
        ];
    }
}

$statusColor = static function (string $status): string {
    return match ($status) {
        'Ready', 'pass' => '#15803d',
        'Needs Attention', 'warn' => '#b45309',
        'Not Ready', 'fail' => '#b91c1c',
        default => '#64748b',
    };
};

require_once __DIR__ . '/partials/header.php';
?>

<div style="display:flex;flex-direction:column;gap:1.5rem;">
  <div class="card">
    <div class="card-header"><h3 class="card-title">CMS Production Readiness</h3></div>
    <div class="card-body">
      <p class="text-muted" style="margin-top:0;">
        Final production readiness audit for this Source CMS install. Does not reset credentials, rewrite content, or delete media.
      </p>
      <form method="POST" style="margin-bottom:1rem;">
        <?= Auth::csrfField() ?>
        <input type="hidden" name="_action" value="run_final_readiness">
        <button type="submit" class="btn btn-primary">Run Final Readiness Check</button>
        <a class="btn btn-ghost" href="<?= esc(SOI_ADMIN_URL . '/files-service-connector.php') ?>">Files Connector</a>
        <a class="btn btn-ghost" href="<?= esc(SOI_ADMIN_URL . '/media.php') ?>">Media Library</a>
        <a class="btn btn-ghost" href="<?= esc(SOI_ADMIN_URL . '/updates.php') ?>">Update Center</a>
      </form>

      <?php if ($readiness): ?>
      <?php
        $final = (string) ($readiness['final_status'] ?? 'Unknown');
        $score = $readiness['score'] ?? [];
      ?>
      <div class="dashboard-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:0.75rem;margin-bottom:1rem;">
        <div>
          <div class="text-muted">Final status</div>
          <strong style="color:<?= esc($statusColor($final)) ?>;"><?= esc($final) ?></strong>
        </div>
        <div><div class="text-muted">CMS version</div><strong><?= esc((string) ($readiness['cms_version'] ?? '—')) ?></strong></div>
        <div><div class="text-muted">Connector version</div><strong><?= esc((string) ($readiness['connector_version'] ?? '—')) ?></strong></div>
        <div><div class="text-muted">Last run</div><strong><?= esc((string) ($readiness['timestamp'] ?? '—')) ?></strong></div>
        <div><div class="text-muted">Pass / Warn / Fail</div>
          <strong><?= (int) ($score['pass'] ?? 0) ?> / <?= (int) ($score['warn'] ?? 0) ?> / <?= (int) ($score['fail'] ?? 0) ?></strong>
        </div>
      </div>

      <?php if (!empty($readiness['blocking'])): ?>
      <div class="alert alert-warning">
        <strong>Blocking:</strong>
        <ul style="margin:0.5rem 0 0;padding-left:1.25rem;">
          <?php foreach ($readiness['blocking'] as $b): ?>
          <li><?= esc((string) $b) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <?php if (!empty($readiness['warnings'])): ?>
      <div class="alert alert-warning">
        <strong>Warnings / notes:</strong>
        <ul style="margin:0.5rem 0 0;padding-left:1.25rem;">
          <?php foreach ($readiness['warnings'] as $w): ?>
          <li><?= esc((string) $w) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <?php if (!empty($readiness['areas'])): ?>
      <div class="table-wrap">
        <table class="table--dense">
          <thead>
            <tr>
              <th>Area</th>
              <th>Status</th>
              <th>Summary</th>
            </tr>
          </thead>
          <tbody>
          <?php
            $labels = [
                'frontend' => 'Frontend readiness',
                'admin' => 'Admin readiness',
                'saml_login' => 'SAML / login / logout',
                'update_center' => 'Update Center',
                'files_connector' => 'Files Service Connector',
                'media_library' => 'Media Library',
                'lifecycle_rewrite' => 'Lifecycle / rewrite safety',
                'reusable_domain' => 'Reusable domain packaging',
                'performance' => 'Performance / lazy loading',
                'security' => 'Security readiness',
            ];
            foreach ($labels as $key => $label):
                $area = $readiness['areas'][$key] ?? null;
                if (!$area) {
                    continue;
                }
                $st = (string) ($area['status'] ?? '');
          ?>
            <tr>
              <td><strong><?= esc($label) ?></strong></td>
              <td style="color:<?= esc($statusColor($st)) ?>;font-weight:600;"><?= esc(strtoupper($st)) ?></td>
              <td><?= esc((string) ($area['summary'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <details class="help-disclosure help-disclosure--inline" style="margin-top:1rem;">
        <summary>Detailed check matrix (JSON-safe summary)</summary>
        <pre class="migration-log" style="white-space:pre-wrap;overflow:auto;max-height:28rem;"><?= esc(json_encode([
            'final_status' => $readiness['final_status'] ?? '',
            'cms_version' => $readiness['cms_version'] ?? '',
            'connector_version' => $readiness['connector_version'] ?? '',
            'score' => $readiness['score'] ?? [],
            'areas' => array_map(static function ($a) {
                return [
                    'status' => $a['status'] ?? '',
                    'summary' => $a['summary'] ?? '',
                    'checks' => $a['checks'] ?? [],
                ];
            }, $readiness['areas'] ?? []),
            'timestamp' => $readiness['timestamp'] ?? '',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}') ?></pre>
      </details>
      <?php elseif (!empty($readiness['from_cache'])): ?>
      <p class="text-muted">Showing last saved status only. Click <strong>Run Final Readiness Check</strong> for a full area matrix.</p>
      <?php endif; ?>
      <?php else: ?>
      <p class="text-muted">No readiness run yet. Click <strong>Run Final Readiness Check</strong>.</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="card" style="border-left:4px solid #0d6efd;">
    <div class="card-header"><h3 class="card-title">Operator verification still required</h3></div>
    <div class="card-body">
      <ul style="margin:0;padding-left:1.25rem;">
        <li>Logged-out admin redirect and post-logout denial (live SAML session).</li>
        <li>Update Center ZIP install on staging/production.</li>
        <li>Media Open/Copy/Retry and one unmap/remap on a disposable test item.</li>
        <li>Frontend mobile/desktop visual check after lazy/skeleton install.</li>
      </ul>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
