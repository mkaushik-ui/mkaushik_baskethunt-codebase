  </div><!-- .page-content -->

  <?php if (!str_contains((string) ($bodyClass ?? ''), 'kc-authoring')): ?>
  <footer class="admin-footer">
    <span>SOI (School Of Interns) CMS v<?= SOI_VERSION ?> — Made with ❤️</span>
    <span><?= date('Y') ?> · <a href="<?= SOI_HOME_URL ?>" target="_blank" rel="noopener"><?= esc(Database::getOption('site_name', 'SOI (School Of Interns) CMS')) ?></a></span>
  </footer>
  <?php endif; ?>
</div><!-- .admin-content -->
</div><!-- .admin-layout -->

<script src="<?= esc(soi_admin_asset_url('admin.js')) ?>?v=<?= defined('SOI_ADMIN_ASSET_VERSION') ? SOI_ADMIN_ASSET_VERSION : '1.3.4-search-auth' ?>"></script>
<?php if (isset($extraScripts)) echo $extraScripts; ?>
</body>
</html>
