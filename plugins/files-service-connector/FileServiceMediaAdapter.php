<?php
if (!defined('SOI_ROOT')) exit;

use SOI\Core\Auth;
use SOI\Core\Database;

if (!class_exists('FileServiceMediaAdapter')) {
    class FileServiceMediaAdapter
    {
        public const UPLOAD_PATH = '/api/files/upload';
        private static ?array $mapColumns = null;

        public static function mapTableExists(): bool
        {
            return class_exists(Database::class) && Database::tableExists('files_service_media_map');
        }

        public static function backupTableExists(): bool
        {
            return class_exists(Database::class) && Database::tableExists('files_service_content_backup');
        }

        public static function mapTableColumns(): array
        {
            if (!self::mapTableExists()) {
                return [];
            }

            if (is_array(self::$mapColumns)) {
                return self::$mapColumns;
            }

            self::$mapColumns = [];
            foreach (Database::select("SHOW COLUMNS FROM `" . Database::prefix('files_service_media_map') . "`") as $row) {
                if (!empty($row['Field'])) {
                    self::$mapColumns[] = (string) $row['Field'];
                }
            }

            return self::$mapColumns;
        }

        public static function mapTableHasColumn(string $column): bool
        {
            return in_array($column, self::mapTableColumns(), true);
        }

        public static function ensureMapSchema(): array
        {
            if (!self::mapTableExists()) {
                return ['success' => false, 'added' => [], 'errors' => ['Media mapping table missing.']];
            }

            $definitions = [
                'media_url' => "`media_url` varchar(1000) DEFAULT NULL",
                'download_url' => "`download_url` varchar(1000) DEFAULT NULL",
                'source_table' => "`source_table` varchar(64) DEFAULT NULL",
                'source_column' => "`source_column` varchar(64) DEFAULT NULL",
                'source_record_id' => "`source_record_id` int(11) DEFAULT NULL",
                'previous_file_id' => "`previous_file_id` varchar(191) DEFAULT NULL",
                'previous_media_url' => "`previous_media_url` varchar(1000) DEFAULT NULL",
            ];
            $added = [];
            $errors = [];

            foreach ($definitions as $column => $definition) {
                if (self::mapTableHasColumn($column)) {
                    continue;
                }

                try {
                    Database::query("ALTER TABLE `" . Database::prefix('files_service_media_map') . "` ADD COLUMN " . $definition);
                    self::$mapColumns = null;
                    $added[] = $column;
                } catch (Throwable $e) {
                    $errors[] = $column . ' could not be added';
                }
            }

            return ['success' => $errors === [], 'added' => $added, 'errors' => $errors];
        }

        public static function stabilizeExistingMappings(): array
        {
            if (!self::mapTableExists()) {
                return ['success' => false, 'updated' => 0, 'error' => 'Media mapping table missing.'];
            }

            self::ensureMapSchema();

            $updated = 0;
            $hasMediaUrl = self::mapTableHasColumn('media_url');
            $hasDownloadUrl = self::mapTableHasColumn('download_url');
            $hasRemoteUrl = self::mapTableHasColumn('remote_url');
            $hasStatus = self::mapTableHasColumn('migration_status');
            if (!$hasMediaUrl && !$hasRemoteUrl) {
                return ['success' => true, 'updated' => 0];
            }

            $rows = Database::select("SELECT * FROM `" . Database::prefix('files_service_media_map') . "` WHERE file_id <> '' ORDER BY id ASC");
            foreach ($rows as $row) {
                $fileId = (string) ($row['file_id'] ?? '');
                if ($fileId === '') {
                    continue;
                }

                $mediaUrl = self::mediaUrl($fileId);
                $downloadUrl = self::remoteDownloadUrl($fileId);
                $data = [];
                if ($hasMediaUrl && (string) ($row['media_url'] ?? '') !== $mediaUrl) {
                    $data['media_url'] = $mediaUrl;
                }
                if ($hasRemoteUrl && (string) ($row['remote_url'] ?? '') !== $mediaUrl) {
                    $data['remote_url'] = $mediaUrl;
                }
                if ($hasDownloadUrl && (string) ($row['download_url'] ?? '') !== $downloadUrl) {
                    $data['download_url'] = $downloadUrl;
                }
                $status = (string) ($row['migration_status'] ?? '');
                // Do not rewrite terminal/audit lifecycle states.
                if ($hasStatus && in_array($status, ['', 'mapped', 'uploaded_mapped', 'new_upload_offloaded'], true)) {
                    $data['migration_status'] = 'mapped';
                }

                if ($data !== []) {
                    Database::update('files_service_media_map', $data, 'id = ?', [(int) $row['id']]);
                    $updated++;
                }
            }

            return ['success' => true, 'updated' => $updated];
        }

        public static function readiness(): array
        {
            $settings = fs_connector_get_settings();
            $reasons = [];

            if (!$settings['connector_enabled']) {
                $reasons[] = 'connector disabled';
            }
            if (($settings['connection_status'] ?? '') !== 'connected') {
                $reasons[] = 'connection status is not Connected';
            }
            if (!fs_connector_credentials_configured()) {
                $reasons[] = 'credentials not configured';
            }
            if (!fs_connector_encryption_ready()) {
                $reasons[] = 'encryption not ready';
            }
            if (!function_exists('curl_init')) {
                $reasons[] = 'PHP cURL extension missing';
            }
            if (!self::mapTableExists()) {
                $reasons[] = 'media mapping table missing';
            }

            return [
                'ready' => $reasons === [],
                'reasons' => $reasons,
            ];
        }

        public static function shouldOffloadNewUploads(): bool
        {
            $settings = fs_connector_get_settings();
            $readiness = self::readiness();

            return $settings['media_offload_enabled']
                && $settings['offload_new_uploads_enabled']
                && $readiness['ready'];
        }

        public static function mediaUrl(string $fileId): string
        {
            return rtrim(FS_CONNECTOR_FILES_URL, '/') . '/media/' . rawurlencode($fileId);
        }

        public static function remoteViewUrl(string $fileId): string
        {
            return self::mediaUrl($fileId);
        }

        public static function remoteDownloadUrl(string $fileId): string
        {
            return rtrim(FS_CONNECTOR_FILES_URL, '/') . '/files/download/' . rawurlencode($fileId);
        }

        public static function canonicalMediaUrlPattern(): string
        {
            return '#^https://files\.soi\.co\.in/media/[^/\s]+$#';
        }

        public static function isCanonicalMediaUrl(string $url): bool
        {
            return $url !== '' && (bool) preg_match(self::canonicalMediaUrlPattern(), $url);
        }

        public static function mappingMediaUrl(array $mapping): string
        {
            $fileId = (string) ($mapping['file_id'] ?? '');
            if ($fileId === '') {
                return '';
            }

            $canonical = self::mediaUrl($fileId);
            $mediaUrl = (string) ($mapping['media_url'] ?? '');
            $remoteUrl = (string) ($mapping['remote_url'] ?? '');

            if (self::isCanonicalMediaUrl($mediaUrl)) {
                return $mediaUrl;
            }

            if (self::isCanonicalMediaUrl($remoteUrl)) {
                return $remoteUrl;
            }

            return $canonical;
        }

        public static function safeDisplayPath(string $path): string
        {
            $path = str_replace('\\', '/', $path);
            $root = defined('SOI_ROOT') ? str_replace('\\', '/', (string) SOI_ROOT) : '';
            if ($root !== '' && str_starts_with($path, rtrim($root, '/') . '/')) {
                return '[cms-root]/' . ltrim(substr($path, strlen(rtrim($root, '/'))), '/');
            }

            $privateRoot = class_exists(\SOI\Core\MediaStorage::class)
                ? str_replace('\\', '/', \SOI\Core\MediaStorage::getPrivateMediaRoot())
                : '';
            if ($privateRoot !== '' && str_starts_with($path, rtrim($privateRoot, '/') . '/')) {
                return '[private-media]/' . ltrim(substr($path, strlen(rtrim($privateRoot, '/'))), '/');
            }

            return basename($path);
        }

        public static function storageLabel(array $media): string
        {
            $path = (string) ($media['path'] ?? '');
            if ($path === '' || !is_file($path)) {
                return 'missing';
            }

            if (class_exists(\SOI\Core\MediaStorage::class) && \SOI\Core\MediaStorage::isPathInsidePrivateMediaRoot($path)) {
                return 'private';
            }

            if (class_exists(\SOI\Core\MediaStorage::class) && \SOI\Core\MediaStorage::isPathInsideLegacyUploadsRoot($path)) {
                return 'legacy_uploads';
            }

            return 'local';
        }

        public static function collectMediaPlan(int $limit = 200): array
        {
            $rows = Database::select(
                "SELECT * FROM `" . Database::prefix('media') . "` ORDER BY created_at DESC, id DESC" . ($limit > 0 ? " LIMIT " . (int) $limit : '')
            );

            $items = [];
            $stats = [
                'total' => 0,
                'mappable' => 0,
                'mapped' => 0,
                'missing' => 0,
                'size_bytes' => 0,
                'content_references_found' => 0,
                'references_replaceable' => 0,
                'references_unmapped' => 0,
            ];

            foreach ($rows as $media) {
                $stats['total']++;
                $path = (string) ($media['path'] ?? '');
                $exists = $path !== '' && is_file($path);
                $size = $exists ? (int) filesize($path) : (int) ($media['file_size'] ?? 0);
                $stats['size_bytes'] += max(0, $size);
                $mapping = self::findMappingForMedia($media);

                if ($mapping) {
                    $stats['mapped']++;
                } elseif (!$exists) {
                    $stats['missing']++;
                } else {
                    $stats['mappable']++;
                }

                $items[] = [
                    'id' => (int) ($media['id'] ?? 0),
                    'name' => (string) ($media['original_name'] ?? $media['filename'] ?? ''),
                    'storage' => self::storageLabel($media),
                    'display_path' => self::safeDisplayPath($path),
                    'local_url' => (string) ($media['url'] ?? ''),
                    'mime_type' => (string) ($media['mime_type'] ?? ''),
                    'size_bytes' => $size,
                    'exists' => $exists,
                    'mapped' => (bool) $mapping,
                    'file_id' => (string) ($mapping['file_id'] ?? ''),
                    'media_url' => $mapping ? self::mappingMediaUrl($mapping) : '',
                    'download_url' => (string) ($mapping['download_url'] ?? ''),
                    'remote_url' => $mapping ? self::mappingMediaUrl($mapping) : '',
                    'status' => $mapping ? 'mapped' : ($exists ? 'ready' : 'missing'),
                ];
            }

            $referenceStats = self::referenceStats();
            $stats['content_references_found'] = $referenceStats['references_found'];
            $stats['references_replaceable'] = $referenceStats['replaceable'];
            $stats['references_unmapped'] = $referenceStats['unmapped'];

            return ['stats' => $stats, 'items' => $items];
        }

        /** Lifecycle states stored primarily in migration_status. */
        public const LIFECYCLE_ACTIVE = ['mapped', 'media_url_ready', 'uploaded_mapped', 'new_upload_offloaded', 'remote_verified', 'pending_upload'];
        public const LIFECYCLE_FAILED = ['upload_failed', 'failed', 'retry_required'];
        public const LIFECYCLE_INACTIVE = ['unmapped', 'cms_deleted', 'orphaned_mapping', 'replaced', 'archived_remote_pending', 'local_missing', 'remote_unknown'];

        public static function findMappingsForMedia(array $media): array
        {
            if (!self::mapTableExists()) {
                return [];
            }

            $path = (string) ($media['path'] ?? '');
            $url = (string) ($media['url'] ?? '');
            $mediaId = (int) ($media['id'] ?? 0);
            $rows = [];

            if ($path !== '') {
                $rows = array_merge($rows, Database::select(
                    "SELECT * FROM `" . Database::prefix('files_service_media_map') . "` WHERE local_path = ? ORDER BY id DESC",
                    [$path]
                ) ?: []);
            }
            if ($url !== '') {
                $rows = array_merge($rows, Database::select(
                    "SELECT * FROM `" . Database::prefix('files_service_media_map') . "` WHERE local_url = ? ORDER BY id DESC",
                    [$url]
                ) ?: []);
            }
            if ($mediaId > 0 && self::mapTableHasColumn('source_record_id')) {
                $rows = array_merge($rows, Database::select(
                    "SELECT * FROM `" . Database::prefix('files_service_media_map') . "` WHERE source_table = 'media' AND source_record_id = ? ORDER BY id DESC",
                    [$mediaId]
                ) ?: []);
            }

            $byId = [];
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id > 0) {
                    $byId[$id] = $row;
                }
            }

            return array_values($byId);
        }

        public static function findMappingForMedia(array $media): ?array
        {
            $rows = self::findMappingsForMedia($media);
            if ($rows === []) {
                return null;
            }

            // Prefer active mapped rows with a file_id.
            foreach ($rows as $row) {
                if (self::isActiveLifecycleStatus((string) ($row['migration_status'] ?? '')) && trim((string) ($row['file_id'] ?? '')) !== '') {
                    return $row;
                }
            }
            foreach ($rows as $row) {
                if (self::isActiveLifecycleStatus((string) ($row['migration_status'] ?? ''))) {
                    return $row;
                }
            }

            return $rows[0];
        }

        public static function isActiveLifecycleStatus(string $status): bool
        {
            $status = strtolower(trim($status));
            if ($status === '') {
                return true;
            }
            if (in_array($status, self::LIFECYCLE_INACTIVE, true) || in_array($status, self::LIFECYCLE_FAILED, true)) {
                return false;
            }
            return in_array($status, self::LIFECYCLE_ACTIVE, true) || !in_array($status, array_merge(self::LIFECYCLE_INACTIVE, self::LIFECYCLE_FAILED), true);
        }

        public static function normalizeLifecycleStatus(string $status): string
        {
            $status = strtolower(trim($status));
            $aliases = [
                'failed' => 'upload_failed',
                'media_url_ready' => 'mapped',
                'uploaded_mapped' => 'mapped',
                'new_upload_offloaded' => 'mapped',
                'local_deleted' => 'cms_deleted',
            ];
            if (isset($aliases[$status])) {
                return $aliases[$status];
            }
            return $status !== '' ? $status : 'unmapped';
        }

        public static function describeLifecycleStatus(string $status): string
        {
            $status = self::normalizeLifecycleStatus($status);
            return match ($status) {
                'mapped', 'remote_verified' => 'Mapped',
                'pending_upload' => 'Pending upload',
                'upload_failed', 'retry_required' => 'Retry required',
                'local_missing' => 'Local missing',
                'remote_unknown' => 'Remote unknown',
                'unmapped' => 'Unmapped',
                'cms_deleted' => 'CMS deleted',
                'orphaned_mapping' => 'Orphaned mapping',
                'replaced' => 'Replaced',
                'archived_remote_pending' => 'Remote cleanup pending',
                default => ucwords(str_replace('_', ' ', $status)),
            };
        }

        public static function mediaCardLifecycle(array $media): array
        {
            $path = (string) ($media['path'] ?? '');
            $localExists = $path !== '' && is_file($path);
            $mappings = self::findMappingsForMedia($media);
            $active = null;
            $activeCount = 0;
            foreach ($mappings as $row) {
                if (self::isActiveLifecycleStatus((string) ($row['migration_status'] ?? '')) && trim((string) ($row['file_id'] ?? '')) !== '') {
                    $activeCount++;
                    if ($active === null) {
                        $active = $row;
                    }
                }
            }
            if ($active === null && $mappings !== []) {
                $active = $mappings[0];
            }

            $status = 'unmapped';
            $fileId = '';
            $mediaUrl = '';
            $lastError = '';
            $previousFileId = '';
            $previousMediaUrl = '';

            if ($active) {
                $rawStatus = (string) ($active['migration_status'] ?? '');
                $fileId = trim((string) ($active['file_id'] ?? ''));
                $mediaUrl = $fileId !== '' ? self::mappingMediaUrl($active) : '';
                $lastError = (string) ($active['last_error'] ?? '');
                $previousFileId = (string) ($active['previous_file_id'] ?? '');
                $previousMediaUrl = (string) ($active['previous_media_url'] ?? '');

                if (!$localExists && $fileId !== '' && self::isActiveLifecycleStatus($rawStatus)) {
                    $status = 'local_missing';
                } elseif (in_array(self::normalizeLifecycleStatus($rawStatus), ['upload_failed', 'retry_required'], true) || $rawStatus === 'failed') {
                    $status = 'retry_required';
                } elseif (self::normalizeLifecycleStatus($rawStatus) === 'unmapped') {
                    $status = 'unmapped';
                } elseif (self::normalizeLifecycleStatus($rawStatus) === 'cms_deleted') {
                    $status = 'cms_deleted';
                } elseif (self::normalizeLifecycleStatus($rawStatus) === 'replaced') {
                    $status = 'replaced';
                } elseif ($fileId !== '' && $mediaUrl !== '' && !self::isCanonicalMediaUrl($mediaUrl)) {
                    $status = 'remote_unknown';
                } elseif ($fileId !== '') {
                    $status = 'mapped';
                } elseif ($rawStatus === 'pending_upload' || $rawStatus === '') {
                    $status = $localExists ? 'pending_upload' : 'local_missing';
                } else {
                    $status = self::normalizeLifecycleStatus($rawStatus);
                }
            } else {
                $status = $localExists ? 'unmapped' : 'local_missing';
            }

            $remoteUrlStatus = 'none';
            if ($mediaUrl !== '') {
                $remoteUrlStatus = self::isCanonicalMediaUrl($mediaUrl) ? 'canonical_media' : (str_contains($mediaUrl, '/files/view/') ? 'legacy_files_view' : 'non_canonical');
            }

            $contentWarning = ($fileId !== '' || $mediaUrl !== '')
                ? self::contentMayReferenceRemote($fileId, $mediaUrl)
                : ['referenced' => false, 'count' => 0];

            return [
                'status' => $status,
                'status_label' => self::describeLifecycleStatus($status),
                'local_exists' => $localExists,
                'mapping' => $active,
                'mapping_count' => count($mappings),
                'active_mapping_count' => $activeCount,
                'duplicate_active' => $activeCount > 1,
                'file_id' => $fileId,
                'media_url' => $mediaUrl,
                'previous_file_id' => $previousFileId,
                'previous_media_url' => $previousMediaUrl,
                'last_error' => $lastError,
                'remote_url_status' => $remoteUrlStatus,
                'can_retry' => $localExists && in_array($status, ['retry_required', 'upload_failed', 'pending_upload'], true),
                'can_unmap' => $active !== null && $fileId !== '' && self::isActiveLifecycleStatus((string) ($active['migration_status'] ?? 'mapped')),
                'can_remap' => $localExists && (
                    $active === null
                    || $fileId === ''
                    || in_array($status, ['unmapped', 'retry_required', 'upload_failed', 'replaced'], true)
                    || !self::isActiveLifecycleStatus((string) ($active['migration_status'] ?? ''))
                ),
                'content_may_use_remote' => !empty($contentWarning['referenced']),
                'content_reference_count' => (int) ($contentWarning['count'] ?? 0),
                'remote_cleanup' => 'unsupported',
                'remote_cleanup_note' => 'Remote cleanup unsupported by current Files Service API (retain remote by default).',
            ];
        }

        public static function contentMayReferenceRemote(string $fileId, string $mediaUrl): array
        {
            $needles = array_values(array_filter([$fileId, $mediaUrl]));
            if ($needles === []) {
                return ['referenced' => false, 'count' => 0];
            }

            $count = 0;
            $targets = [
                ['table' => 'pages', 'columns' => ['content']],
                ['table' => 'posts', 'columns' => ['content', 'featured_image']],
            ];
            foreach ($targets as $target) {
                if (!Database::tableExists($target['table'])) {
                    continue;
                }
                $rows = Database::select("SELECT id, " . implode(', ', array_map(static fn($c) => "`{$c}`", $target['columns'])) . " FROM `" . Database::prefix($target['table']) . "`");
                foreach ($rows as $row) {
                    foreach ($target['columns'] as $column) {
                        $value = (string) ($row[$column] ?? '');
                        if ($value === '') {
                            continue;
                        }
                        foreach ($needles as $needle) {
                            if ($needle !== '' && str_contains($value, $needle)) {
                                $count++;
                            }
                        }
                    }
                }
            }

            return ['referenced' => $count > 0, 'count' => $count];
        }

        public static function offloadMediaRow(int $mediaId, string $context = 'media_library', array $options = []): array
        {
            $force = !empty($options['force']);
            $replaceExisting = !empty($options['replace_existing']);

            $media = Database::selectOne("SELECT * FROM `" . Database::prefix('media') . "` WHERE id = ?", [$mediaId]);
            if (!$media) {
                return ['success' => false, 'error' => 'Media row not found.'];
            }

            $existing = self::findMappingForMedia($media);
            $existingStatus = (string) ($existing['migration_status'] ?? '');
            $existingFileId = trim((string) ($existing['file_id'] ?? ''));
            $isActiveMapped = $existing && $existingFileId !== '' && self::isActiveLifecycleStatus($existingStatus);

            if ($isActiveMapped && !$force && !$replaceExisting) {
                $mediaUrl = self::mappingMediaUrl($existing);
                // Ensure status is normalized for active mappings.
                if (self::mapTableHasColumn('migration_status') && !in_array($existingStatus, ['mapped', 'remote_verified'], true)) {
                    Database::update('files_service_media_map', [
                        'migration_status' => 'mapped',
                        'last_error' => '',
                    ], 'id = ?', [(int) $existing['id']]);
                }
                return [
                    'success' => true,
                    'skipped' => true,
                    'message' => 'Already mapped. No duplicate upload created.',
                    'file_id' => $existingFileId,
                    'media_url' => $mediaUrl,
                    'mapping' => $existing,
                    'duplicate_warning' => false,
                ];
            }

            $path = (string) ($media['path'] ?? '');
            if ($path === '' || !is_file($path) || !is_readable($path)) {
                if ($existing) {
                    self::updateMappingLifecycle((int) $existing['id'], 'local_missing', 'Local file is missing or unreadable.');
                }
                return ['success' => false, 'error' => 'Local file is missing or unreadable.', 'lifecycle_status' => 'local_missing'];
            }

            // Mark pending before upload attempt.
            if ($existing && !$isActiveMapped) {
                self::updateMappingLifecycle((int) $existing['id'], 'pending_upload', '');
            }

            $upload = self::uploadLocalFile($path, [
                'uploaded_by_user_id' => (string) (class_exists(Auth::class) ? (Auth::id() ?: 0) : 0),
                'context' => $context,
                'folder' => 'source-cms-media',
                'mime_type' => (string) ($media['mime_type'] ?? ''),
                'original_name' => (string) ($media['original_name'] ?? basename($path)),
            ]);

            if (!($upload['success'] ?? false)) {
                $err = (string) ($upload['error'] ?? 'Upload failed.');
                self::upsertMapping([
                    'local_path' => $path,
                    'local_url' => self::localViewUrlForMedia($media),
                    'file_id' => $existingFileId,
                    'remote_url' => (string) ($existing['remote_url'] ?? ''),
                    'media_url' => (string) ($existing['media_url'] ?? ''),
                    'download_url' => (string) ($existing['download_url'] ?? ''),
                    'mime_type' => (string) ($media['mime_type'] ?? ''),
                    'size_bytes' => (string) filesize($path),
                    'sha256' => hash_file('sha256', $path) ?: '',
                    'source_table' => 'media',
                    'source_column' => 'url',
                    'source_record_id' => (string) $mediaId,
                    'migration_status' => 'retry_required',
                    'last_error' => $err,
                    'previous_file_id' => (string) ($existing['previous_file_id'] ?? ''),
                    'previous_media_url' => (string) ($existing['previous_media_url'] ?? ''),
                ]);
                $upload['lifecycle_status'] = 'retry_required';
                return $upload;
            }

            $fileId = (string) ($upload['file_id'] ?? '');
            $mediaUrl = (string) ($upload['media_url'] ?? '');
            if ($mediaUrl === '' || !self::isCanonicalMediaUrl($mediaUrl)) {
                $mediaUrl = self::mediaUrl($fileId);
            }
            $downloadUrl = (string) ($upload['download_url'] ?? self::remoteDownloadUrl($fileId));

            $previousFileId = '';
            $previousMediaUrl = '';
            $duplicateWarning = false;

            if ($isActiveMapped && $replaceExisting && $existingFileId !== '' && $existingFileId !== $fileId) {
                // Preserve old mapping history as a replaced row, then write new active mapping.
                $previousFileId = $existingFileId;
                $previousMediaUrl = self::mappingMediaUrl($existing);
                Database::update('files_service_media_map', [
                    'migration_status' => 'replaced',
                    'last_error' => 'Superseded by new Files Service file_id ' . $fileId . ' at ' . date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [(int) $existing['id']]);
                $duplicateWarning = true;
                // Force new insert by clearing path match via separate insert path.
                $mapping = self::insertMapping([
                    'local_path' => $path,
                    'local_url' => self::localViewUrlForMedia($media),
                    'file_id' => $fileId,
                    'remote_url' => $mediaUrl,
                    'media_url' => $mediaUrl,
                    'download_url' => $downloadUrl,
                    'mime_type' => (string) ($media['mime_type'] ?? ''),
                    'size_bytes' => (string) filesize($path),
                    'sha256' => hash_file('sha256', $path) ?: '',
                    'source_table' => 'media',
                    'source_column' => 'url',
                    'source_record_id' => (string) $mediaId,
                    'migration_status' => 'mapped',
                    'last_error' => '',
                    'previous_file_id' => $previousFileId,
                    'previous_media_url' => $previousMediaUrl,
                ]);
            } else {
                if ($existingFileId !== '' && $existingFileId !== $fileId && $isActiveMapped) {
                    $previousFileId = $existingFileId;
                    $previousMediaUrl = self::mappingMediaUrl($existing);
                    $duplicateWarning = true;
                }
                $mapping = self::upsertMapping([
                    'local_path' => $path,
                    'local_url' => self::localViewUrlForMedia($media),
                    'file_id' => $fileId,
                    'remote_url' => $mediaUrl,
                    'media_url' => $mediaUrl,
                    'download_url' => $downloadUrl,
                    'mime_type' => (string) ($media['mime_type'] ?? ''),
                    'size_bytes' => (string) filesize($path),
                    'sha256' => hash_file('sha256', $path) ?: '',
                    'source_table' => 'media',
                    'source_column' => 'url',
                    'source_record_id' => (string) $mediaId,
                    'migration_status' => 'mapped',
                    'last_error' => '',
                    'previous_file_id' => $previousFileId !== '' ? $previousFileId : (string) ($existing['previous_file_id'] ?? ''),
                    'previous_media_url' => $previousMediaUrl !== '' ? $previousMediaUrl : (string) ($existing['previous_media_url'] ?? ''),
                ]);
            }

            $settings = fs_connector_get_settings();
            if (in_array($settings['delivery_mode'], ['hybrid', 'files_service'], true) && $mediaUrl !== '') {
                Database::update('media', ['url' => $mediaUrl], 'id = ?', [$mediaId]);
            }

            return [
                'success' => true,
                'file_id' => $fileId,
                'media_url' => $mediaUrl,
                'download_url' => $downloadUrl,
                'remote_url' => $mediaUrl,
                'mapping' => $mapping,
                'lifecycle_status' => 'mapped',
                'duplicate_warning' => $duplicateWarning,
                'previous_file_id' => $previousFileId,
                'message' => $duplicateWarning
                    ? 'Mapped to Files Service. Previous remote file retained as history (not deleted).'
                    : 'Mapped to Files Service.',
            ];
        }

        public static function localViewUrlForMedia(array $media): string
        {
            $filename = (string) ($media['filename'] ?? '');
            if ($filename !== '' && defined('SOI_HOME_URL')) {
                return rtrim(SOI_HOME_URL, '/') . '/media/view/' . rawurlencode($filename);
            }
            return (string) ($media['url'] ?? '');
        }

        public static function updateMappingLifecycle(int $mapId, string $status, string $error = ''): void
        {
            if ($mapId < 1 || !self::mapTableExists()) {
                return;
            }
            $data = [
                'migration_status' => self::normalizeLifecycleStatus($status),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if (self::mapTableHasColumn('last_error')) {
                $data['last_error'] = substr($error, 0, 1000);
            }
            Database::update('files_service_media_map', $data, 'id = ?', [$mapId]);
        }

        public static function insertMapping(array $data): array
        {
            if (!self::mapTableExists()) {
                throw new RuntimeException('Files Service media mapping table does not exist.');
            }
            self::ensureMapSchema();
            $row = [
                'local_path' => (string) ($data['local_path'] ?? ''),
                'local_url' => (string) ($data['local_url'] ?? ''),
                'file_id' => (string) ($data['file_id'] ?? ''),
                'remote_url' => (string) ($data['remote_url'] ?? ''),
                'media_url' => (string) ($data['media_url'] ?? $data['remote_url'] ?? ''),
                'download_url' => (string) ($data['download_url'] ?? ''),
                'mime_type' => (string) ($data['mime_type'] ?? ''),
                'size_bytes' => (string) ($data['size_bytes'] ?? '0'),
                'sha256' => (string) ($data['sha256'] ?? ''),
                'source_table' => (string) ($data['source_table'] ?? ''),
                'source_column' => (string) ($data['source_column'] ?? ''),
                'source_record_id' => (string) ($data['source_record_id'] ?? '0'),
                'migration_status' => (string) ($data['migration_status'] ?? 'mapped'),
                'last_error' => substr((string) ($data['last_error'] ?? ''), 0, 1000),
                'previous_file_id' => (string) ($data['previous_file_id'] ?? ''),
                'previous_media_url' => (string) ($data['previous_media_url'] ?? ''),
                'updated_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ];
            $availableColumns = self::mapTableColumns();
            $row = array_intersect_key($row, array_flip($availableColumns));
            $id = Database::insert('files_service_media_map', $row);
            return Database::selectOne("SELECT * FROM `" . Database::prefix('files_service_media_map') . "` WHERE id = ?", [$id]) ?: $row;
        }

        public static function retryOffload(int $mediaId): array
        {
            $media = Database::selectOne("SELECT * FROM `" . Database::prefix('media') . "` WHERE id = ?", [$mediaId]);
            if (!$media) {
                return ['success' => false, 'error' => 'Media row not found.'];
            }
            $existing = self::findMappingForMedia($media);
            if ($existing && trim((string) ($existing['file_id'] ?? '')) !== '' && self::isActiveLifecycleStatus((string) ($existing['migration_status'] ?? ''))) {
                return [
                    'success' => true,
                    'skipped' => true,
                    'message' => 'Active mapping already exists. Retry skipped to avoid duplicate remote uploads.',
                    'file_id' => (string) $existing['file_id'],
                    'media_url' => self::mappingMediaUrl($existing),
                    'duplicate_warning' => false,
                ];
            }
            $result = self::offloadMediaRow($mediaId, 'manual_retry');
            if (function_exists('fs_connector_record_migration_result')) {
                fs_connector_record_migration_result('lifecycle_retry', [
                    'success' => (bool) ($result['success'] ?? false),
                    'processed' => 1,
                    'uploaded' => !empty($result['success']) && empty($result['skipped']) ? 1 : 0,
                    'failed' => empty($result['success']) ? 1 : 0,
                    'error' => (string) ($result['error'] ?? ''),
                ]);
            }
            return $result;
        }

        public static function unmapMedia(int $mediaId): array
        {
            $media = Database::selectOne("SELECT * FROM `" . Database::prefix('media') . "` WHERE id = ?", [$mediaId]);
            if (!$media) {
                return ['success' => false, 'error' => 'Media row not found.'];
            }

            $mapping = self::findMappingForMedia($media);
            if (!$mapping || trim((string) ($mapping['file_id'] ?? '')) === '') {
                return ['success' => false, 'error' => 'No active Files Service mapping to unmap.'];
            }

            $fileId = (string) $mapping['file_id'];
            $mediaUrl = self::mappingMediaUrl($mapping);
            $content = self::contentMayReferenceRemote($fileId, $mediaUrl);
            $localUrl = self::localViewUrlForMedia($media);

            $update = [
                'migration_status' => 'unmapped',
                'last_error' => 'Unmapped from CMS delivery at ' . date('Y-m-d H:i:s') . '. Remote Files Service file retained.',
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if (self::mapTableHasColumn('previous_file_id')) {
                $update['previous_file_id'] = $fileId;
            }
            if (self::mapTableHasColumn('previous_media_url')) {
                $update['previous_media_url'] = $mediaUrl;
            }
            // Keep file_id/media_url for audit; delivery usage is disabled via status.
            Database::update('files_service_media_map', $update, 'id = ?', [(int) $mapping['id']]);

            // Restore CMS media URL to local fallback when it pointed at remote.
            $currentUrl = (string) ($media['url'] ?? '');
            if ($localUrl !== '' && ($currentUrl === $mediaUrl || str_contains($currentUrl, 'files.soi.co.in'))) {
                Database::update('media', ['url' => $localUrl], 'id = ?', [$mediaId]);
            }

            return [
                'success' => true,
                'message' => 'Unmapped from Files Service delivery. Local media kept. Remote file not deleted.',
                'lifecycle_status' => 'unmapped',
                'file_id' => $fileId,
                'media_url' => $mediaUrl,
                'content_warning' => !empty($content['referenced']),
                'content_reference_count' => (int) ($content['count'] ?? 0),
                'content_note' => !empty($content['referenced'])
                    ? 'Warning: content may still reference the remote URL. Run Preview/Commit rewrite or rollback if needed. Content was not modified.'
                    : 'No content references to this remote URL were detected in pages/posts fields.',
                'remote_deleted' => false,
            ];
        }

        public static function remapMedia(int $mediaId): array
        {
            $media = Database::selectOne("SELECT * FROM `" . Database::prefix('media') . "` WHERE id = ?", [$mediaId]);
            if (!$media) {
                return ['success' => false, 'error' => 'Media row not found.'];
            }
            $path = (string) ($media['path'] ?? '');
            if ($path === '' || !is_file($path)) {
                return ['success' => false, 'error' => 'Local file is missing. Remap requires a readable local file.', 'lifecycle_status' => 'local_missing'];
            }

            $existing = self::findMappingForMedia($media);
            $existingFileId = trim((string) ($existing['file_id'] ?? ''));
            $existingStatus = (string) ($existing['migration_status'] ?? '');

            if ($existing && $existingFileId !== '' && self::isActiveLifecycleStatus($existingStatus)) {
                return [
                    'success' => false,
                    'error' => 'An active mapping already exists. Unmap first, or use Retry only for failed uploads. Remap will not create a duplicate active mapping.',
                    'file_id' => $existingFileId,
                    'media_url' => self::mappingMediaUrl($existing),
                    'duplicate_warning' => true,
                ];
            }

            // For unmapped/replaced/failed rows, force a new successful map (may create new remote file).
            $result = self::offloadMediaRow($mediaId, 'manual_remap', ['force' => true]);
            if (function_exists('fs_connector_record_migration_result')) {
                fs_connector_record_migration_result('lifecycle_remap', [
                    'success' => (bool) ($result['success'] ?? false),
                    'processed' => 1,
                    'uploaded' => !empty($result['success']) ? 1 : 0,
                    'failed' => empty($result['success']) ? 1 : 0,
                    'error' => (string) ($result['error'] ?? ''),
                ]);
            }
            return $result;
        }

        /**
         * Called before CMS hard-deletes a media library row.
         * Does not delete remote Files Service files.
         * Does not rewrite content.
         */
        public static function handleMediaDelete(int $mediaId): array
        {
            $media = Database::selectOne("SELECT * FROM `" . Database::prefix('media') . "` WHERE id = ?", [$mediaId]);
            if (!$media) {
                return ['success' => true, 'message' => 'Media already gone.', 'mapping_updated' => false];
            }

            if (self::mapTableExists()) {
                self::ensureMapSchema();
            }

            $mappings = self::findMappingsForMedia($media);
            $updated = 0;
            $fileIds = [];
            foreach ($mappings as $mapping) {
                $fileId = trim((string) ($mapping['file_id'] ?? ''));
                if ($fileId !== '') {
                    $fileIds[] = $fileId;
                }
                $data = [
                    'migration_status' => 'cms_deleted',
                    'last_error' => substr(
                        'CMS media deleted at ' . date('Y-m-d H:i:s') . '. Remote file retained. Remote cleanup unsupported by current Files Service API.',
                        0,
                        1000
                    ),
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
                if (self::mapTableHasColumn('previous_file_id') && $fileId !== '') {
                    $data['previous_file_id'] = $fileId;
                }
                if (self::mapTableHasColumn('previous_media_url') && $fileId !== '') {
                    $data['previous_media_url'] = self::mappingMediaUrl($mapping);
                }
                Database::update('files_service_media_map', $data, 'id = ?', [(int) $mapping['id']]);
                $updated++;
            }

            $contentWarnings = [];
            foreach (array_unique($fileIds) as $fid) {
                $check = self::contentMayReferenceRemote($fid, self::mediaUrl($fid));
                if (!empty($check['referenced'])) {
                    $contentWarnings[] = $fid;
                }
            }

            return [
                'success' => true,
                'mapping_updated' => $updated > 0,
                'mappings_marked' => $updated,
                'remote_deleted' => false,
                'remote_cleanup' => 'unsupported',
                'file_ids' => array_values(array_unique($fileIds)),
                'content_warning' => $contentWarnings !== [],
                'message' => $updated > 0
                    ? 'Local media will be deleted. Mapping marked cms_deleted. Remote Files Service file retained (no hard-delete). Remote cleanup unsupported by current API.'
                    : 'Local media will be deleted. No Files Service mapping found.',
            ];
        }

        public static function remoteLifecycleCapabilities(): array
        {
            return [
                'delete_api' => false,
                'archive_api' => false,
                'status_api' => false,
                'modes' => [
                    'retain_remote' => ['enabled' => true, 'default' => true, 'label' => 'Retain remote (default)'],
                    'archive_remote_if_supported' => ['enabled' => false, 'default' => false, 'label' => 'Archive remote if supported (unavailable)'],
                    'delete_remote_if_supported' => ['enabled' => false, 'default' => false, 'label' => 'Delete remote if supported (dangerous, unavailable)'],
                ],
                'note' => 'Files Service discovery (protocol 1.2.2) exposes upload/download/signed_links only. No delete/archive endpoint is available to this connector. Remote hard-delete is disabled.',
            ];
        }

        public static function runLifecycleAudit(int $limit = 500): array
        {
            self::ensureMapSchema();
            $summary = [
                'success' => true,
                'local_media_total' => 0,
                'local_only' => 0,
                'remote_mapped' => 0,
                'failed_sync' => 0,
                'unmapped_status' => 0,
                'cms_deleted' => 0,
                'orphaned_mapping' => 0,
                'local_missing' => 0,
                'missing_file_id' => 0,
                'non_canonical_media_url' => 0,
                'legacy_files_view' => 0,
                'duplicate_active' => 0,
                'replaced' => 0,
                'content_refs_without_mapping' => 0,
                'items' => [],
                'recommendations' => [],
                'remote_cleanup' => self::remoteLifecycleCapabilities(),
                'timestamp' => date('Y-m-d H:i:s'),
            ];

            $mediaRows = Database::tableExists('media')
                ? Database::select("SELECT * FROM `" . Database::prefix('media') . "` ORDER BY id DESC" . ($limit > 0 ? ' LIMIT ' . (int) $limit : ''))
                : [];
            $summary['local_media_total'] = count($mediaRows);
            $seenMediaIds = [];

            foreach ($mediaRows as $media) {
                $mediaId = (int) ($media['id'] ?? 0);
                $seenMediaIds[$mediaId] = true;
                $card = self::mediaCardLifecycle($media);
                if ($card['duplicate_active']) {
                    $summary['duplicate_active']++;
                    $summary['items'][] = [
                        'type' => 'duplicate_active',
                        'media_id' => $mediaId,
                        'name' => (string) ($media['original_name'] ?? ''),
                        'status' => $card['status'],
                        'action' => 'Review mappings; unmap extras; keep one active mapping.',
                    ];
                }
                if ($card['status'] === 'mapped' || $card['status'] === 'remote_verified') {
                    $summary['remote_mapped']++;
                } elseif (in_array($card['status'], ['retry_required', 'upload_failed'], true)) {
                    $summary['failed_sync']++;
                    $summary['items'][] = [
                        'type' => 'failed_sync',
                        'media_id' => $mediaId,
                        'name' => (string) ($media['original_name'] ?? ''),
                        'status' => $card['status'],
                        'error' => substr((string) $card['last_error'], 0, 200),
                        'action' => 'retry upload',
                    ];
                } elseif ($card['status'] === 'unmapped' || $card['mapping'] === null) {
                    $summary['local_only']++;
                } elseif ($card['status'] === 'local_missing') {
                    $summary['local_missing']++;
                    $summary['items'][] = [
                        'type' => 'local_missing',
                        'media_id' => $mediaId,
                        'name' => (string) ($media['original_name'] ?? ''),
                        'status' => 'local_missing',
                        'action' => 'restore local file or unmap / keep remote audit',
                    ];
                }
                if ($card['remote_url_status'] === 'legacy_files_view') {
                    $summary['legacy_files_view']++;
                }
                if ($card['remote_url_status'] === 'non_canonical') {
                    $summary['non_canonical_media_url']++;
                }
            }

            if (self::mapTableExists()) {
                $maps = Database::select("SELECT * FROM `" . Database::prefix('files_service_media_map') . "` ORDER BY id DESC" . ($limit > 0 ? ' LIMIT ' . (int) $limit : ''));
                foreach ($maps as $map) {
                    $status = self::normalizeLifecycleStatus((string) ($map['migration_status'] ?? ''));
                    $fileId = trim((string) ($map['file_id'] ?? ''));
                    $mediaUrl = (string) ($map['media_url'] ?? $map['remote_url'] ?? '');
                    $sourceId = (int) ($map['source_record_id'] ?? 0);
                    $path = (string) ($map['local_path'] ?? '');

                    if ($status === 'cms_deleted') {
                        $summary['cms_deleted']++;
                    }
                    if ($status === 'unmapped') {
                        $summary['unmapped_status']++;
                    }
                    if ($status === 'replaced') {
                        $summary['replaced']++;
                    }
                    if ($fileId === '' && self::isActiveLifecycleStatus((string) ($map['migration_status'] ?? ''))) {
                        $summary['missing_file_id']++;
                        $summary['items'][] = [
                            'type' => 'missing_file_id',
                            'map_id' => (int) ($map['id'] ?? 0),
                            'action' => 'retry upload or unmap',
                        ];
                    }
                    if ($mediaUrl !== '' && str_contains($mediaUrl, '/files/view/')) {
                        $summary['legacy_files_view']++;
                    } elseif ($mediaUrl !== '' && $fileId !== '' && !self::isCanonicalMediaUrl(self::mappingMediaUrl($map))) {
                        $summary['non_canonical_media_url']++;
                    }

                    $mediaMissing = true;
                    if ($sourceId > 0 && isset($seenMediaIds[$sourceId])) {
                        $mediaMissing = false;
                    } elseif ($sourceId > 0 && Database::tableExists('media')) {
                        $exists = Database::selectOne("SELECT id FROM `" . Database::prefix('media') . "` WHERE id = ? LIMIT 1", [$sourceId]);
                        $mediaMissing = !$exists;
                    } elseif ($path !== '') {
                        // Path-only mapping without media row.
                        $mediaMissing = true;
                        if (Database::tableExists('media')) {
                            $byPath = Database::selectOne("SELECT id FROM `" . Database::prefix('media') . "` WHERE path = ? LIMIT 1", [$path]);
                            $mediaMissing = !$byPath;
                        }
                    } else {
                        $mediaMissing = $sourceId > 0;
                    }

                    if ($mediaMissing && $fileId !== '' && !in_array($status, ['cms_deleted', 'replaced'], true)) {
                        $summary['orphaned_mapping']++;
                        // Soft-mark orphaned for visibility (audit side-effect, safe).
                        if ($status !== 'orphaned_mapping' && self::mapTableHasColumn('migration_status')) {
                            Database::update('files_service_media_map', [
                                'migration_status' => 'orphaned_mapping',
                                'last_error' => substr('Orphaned mapping detected at ' . date('Y-m-d H:i:s') . '. Local media record missing. Remote file retained.', 0, 1000),
                                'updated_at' => date('Y-m-d H:i:s'),
                            ], 'id = ?', [(int) $map['id']]);
                        }
                        $summary['items'][] = [
                            'type' => 'orphaned_mapping',
                            'map_id' => (int) ($map['id'] ?? 0),
                            'file_id' => $fileId,
                            'action' => 'keep audit / remote cleanup unsupported',
                        ];
                    } elseif ($path !== '' && !is_file($path) && $fileId !== '' && self::isActiveLifecycleStatus((string) ($map['migration_status'] ?? ''))) {
                        $summary['local_missing']++;
                    }
                }
            }

            // Lightweight content refs: local uploads without mapping (sample).
            if (method_exists(self::class, 'countLocalMediaReferences')) {
                // private method - use referenceStats if available
            }
            if (method_exists(self::class, 'referenceStats')) {
                $stats = self::referenceStats();
                $summary['content_refs_without_mapping'] = (int) ($stats['unmapped'] ?? 0);
            }

            $summary['items'] = array_slice($summary['items'], 0, 100);
            $summary['recommendations'] = self::lifecycleRecommendations($summary);

            if (function_exists('fs_connector_set_option')) {
                fs_connector_set_option('fs_conn_last_lifecycle_audit_at', $summary['timestamp']);
                fs_connector_set_option('fs_conn_last_lifecycle_audit', json_encode([
                    'local_only' => $summary['local_only'],
                    'remote_mapped' => $summary['remote_mapped'],
                    'failed_sync' => $summary['failed_sync'],
                    'orphaned_mapping' => $summary['orphaned_mapping'],
                    'duplicate_active' => $summary['duplicate_active'],
                    'cms_deleted' => $summary['cms_deleted'],
                    'non_canonical_media_url' => $summary['non_canonical_media_url'],
                    'legacy_files_view' => $summary['legacy_files_view'],
                    'timestamp' => $summary['timestamp'],
                ], JSON_UNESCAPED_SLASHES) ?: '');
            }

            return $summary;
        }

        private static function lifecycleRecommendations(array $summary): array
        {
            $recs = [];
            if (($summary['failed_sync'] ?? 0) > 0) {
                $recs[] = 'Retry failed uploads from Media Library for items marked Retry required.';
            }
            if (($summary['local_only'] ?? 0) > 0) {
                $recs[] = 'Local-only media can stay local, or use Remap/Upload + Map when offload is desired.';
            }
            if (($summary['orphaned_mapping'] ?? 0) > 0) {
                $recs[] = 'Orphaned mappings retained for audit. Remote hard-delete is unsupported; remote files are retained.';
            }
            if (($summary['duplicate_active'] ?? 0) > 0) {
                $recs[] = 'Resolve duplicate active mappings: unmap extras so only one active mapping remains per media item.';
            }
            if (($summary['non_canonical_media_url'] ?? 0) > 0 || ($summary['legacy_files_view'] ?? 0) > 0) {
                $recs[] = 'Run Repair non-canonical media URLs, then Verify Frontend References / Preview rewrite if content still uses old paths.';
            }
            if (($summary['content_refs_without_mapping'] ?? 0) > 0) {
                $recs[] = 'Content has local media references without mapping. Review with Preview Reference Replacements (does not auto-commit).';
            }
            if ($recs === []) {
                $recs[] = 'Lifecycle looks healthy. No automatic content rewrite is performed by lifecycle actions.';
            }
            return $recs;
        }

        public static function repairNonCanonicalMediaUrls(): array
        {
            if (!self::mapTableExists()) {
                return ['success' => false, 'updated' => 0, 'error' => 'Media mapping table missing.'];
            }
            self::ensureMapSchema();
            $updated = 0;
            $skipped = 0;
            $rows = Database::select("SELECT * FROM `" . Database::prefix('files_service_media_map') . "` WHERE file_id <> '' ORDER BY id ASC");
            foreach ($rows as $row) {
                $status = (string) ($row['migration_status'] ?? '');
                if (in_array(self::normalizeLifecycleStatus($status), ['cms_deleted', 'replaced', 'unmapped'], true) && !self::isActiveLifecycleStatus($status)) {
                    // Still repair stored URLs for audit consistency on non-replaced rows with file_id.
                }
                $fileId = trim((string) ($row['file_id'] ?? ''));
                if ($fileId === '') {
                    $skipped++;
                    continue;
                }
                $canonical = self::mediaUrl($fileId);
                $data = [];
                if (self::mapTableHasColumn('media_url') && (string) ($row['media_url'] ?? '') !== $canonical) {
                    $data['media_url'] = $canonical;
                }
                if (self::mapTableHasColumn('remote_url') && (string) ($row['remote_url'] ?? '') !== $canonical) {
                    $data['remote_url'] = $canonical;
                }
                if ($data !== []) {
                    $data['updated_at'] = date('Y-m-d H:i:s');
                    if (self::mapTableHasColumn('last_error') && str_contains((string) ($row['media_url'] ?? $row['remote_url'] ?? ''), '/files/view/')) {
                        $data['last_error'] = substr('Repaired non-canonical URL to /media/{file_id} at ' . date('Y-m-d H:i:s'), 0, 1000);
                    }
                    Database::update('files_service_media_map', $data, 'id = ?', [(int) $row['id']]);
                    $updated++;
                } else {
                    $skipped++;
                }
            }

            if (function_exists('fs_connector_record_migration_result')) {
                fs_connector_record_migration_result('lifecycle_repair_urls', [
                    'success' => true,
                    'processed' => $updated + $skipped,
                    'changed' => $updated,
                ]);
            }

            return [
                'success' => true,
                'updated' => $updated,
                'skipped' => $skipped,
                'message' => "Repaired {$updated} mapping URL(s) to canonical /media/{file_id}. Content was not rewritten.",
            ];
        }

        public static function migrateBatch(int $batchSize): array
        {
            $readiness = self::readiness();
            if (!$readiness['ready']) {
                return ['success' => false, 'error' => 'Migration is not ready: ' . implode(', ', $readiness['reasons']), 'logs' => []];
            }

            $settings = fs_connector_get_settings();
            if (!$settings['migration_enabled']) {
                return ['success' => false, 'error' => 'Migration tooling is disabled in settings.', 'logs' => []];
            }

            $batchSize = max(1, min(50, $batchSize));
            $rows = Database::select("SELECT * FROM `" . Database::prefix('media') . "` ORDER BY created_at ASC, id ASC");
            $processed = 0;
            $uploaded = 0;
            $skipped = 0;
            $failed = 0;
            $logs = [];

            foreach ($rows as $media) {
                if ($processed >= $batchSize) {
                    break;
                }

                if (self::findMappingForMedia($media)) {
                    $skipped++;
                    continue;
                }

                $path = (string) ($media['path'] ?? '');
                $name = (string) ($media['original_name'] ?? $media['filename'] ?? ('media #' . ($media['id'] ?? '')));
                if ($path === '' || !is_file($path)) {
                    $failed++;
                    $processed++;
                    $logs[] = '[SKIP] Missing local file for ' . $name;
                    continue;
                }

                $processed++;
                $result = self::offloadMediaRow((int) $media['id'], 'existing_media_migration');
                if ($result['success'] ?? false) {
                    $uploaded++;
                    $logs[] = '[OK] Uploaded and mapped ' . $name;
                } else {
                    $failed++;
                    $logs[] = '[FAIL] ' . $name . ': ' . (string) ($result['error'] ?? 'Upload failed.');
                }
            }

            return [
                'success' => $failed === 0,
                'processed' => $processed,
                'uploaded' => $uploaded,
                'skipped' => $skipped,
                'failed' => $failed,
                'logs' => $logs,
            ];
        }

        public static function uploadLocalFile(string $path, array $args = []): array
        {
            if (!is_file($path) || !is_readable($path)) {
                return ['success' => false, 'error' => 'Local file is missing or unreadable.'];
            }

            try {
                $credentials = fs_connector_get_credentials();
            } catch (Throwable $e) {
                $msg = trim($e->getMessage());
                return [
                    'success' => false,
                    'error' => $msg !== '' ? $msg : 'Files Service credentials are not available.',
                ];
            }

            // Identity must match connection approval: installed domain + credential-bound app id.
            $identity = function_exists('fs_connector_installed_identity')
                ? fs_connector_installed_identity()
                : [];
            $sourceDomain = fs_connector_normalize_hostname((string) ($credentials['source_domain'] ?? ''));
            if ($sourceDomain === '') {
                $sourceDomain = fs_connector_normalize_hostname((string) ($identity['source_domain'] ?? ''));
            }
            if ($sourceDomain === '') {
                $sourceDomain = self::sourceCmsHostname();
            }
            if ($sourceDomain === '') {
                return ['success' => false, 'error' => 'Source domain is not configured. Set site_domain / site_url or run Repair Files Service Identity.'];
            }

            $appId = (string) ($credentials['app_id'] ?? '');
            if ($appId === '' && function_exists('fs_connector_resolve_upload_app_id')) {
                $appId = fs_connector_resolve_upload_app_id();
            }
            if ($appId === '') {
                return ['success' => false, 'error' => 'Files Service app id is not configured for this CMS installation.'];
            }

            $timestamp = fs_connector_current_timestamp();
            $nonce = bin2hex(random_bytes(16));
            $uploadedBy = (string) ($args['uploaded_by_user_id'] ?? '0');
            $context = preg_replace('/[^a-zA-Z0-9._:-]/', '_', (string) ($args['context'] ?? 'media'));
            $folder = preg_replace('/[^a-zA-Z0-9._:-]/', '_', (string) ($args['folder'] ?? 'media'));
            $fileSha256 = hash_file('sha256', $path);
            if ($fileSha256 === false) {
                return ['success' => false, 'error' => 'Could not hash local file.'];
            }

            $canonical = implode('|', [
                'POST',
                self::UPLOAD_PATH,
                $timestamp,
                $nonce,
                $appId,
                $credentials['client_id'],
                $sourceDomain,
                $uploadedBy,
                $context,
                $folder,
                $fileSha256,
            ]);
            $signature = hash_hmac('sha256', $canonical, $credentials['client_secret']);
            $mimeType = (string) ($args['mime_type'] ?? '');
            if ($mimeType === '' && function_exists('mime_content_type')) {
                $mimeType = (string) (mime_content_type($path) ?: '');
            }
            $uploadName = basename((string) ($args['original_name'] ?? basename($path)));

            $filesBase = rtrim(
                function_exists('fs_connector_installed_identity')
                    ? (string) (fs_connector_installed_identity()['files_service_url'] ?? FS_CONNECTOR_FILES_URL)
                    : FS_CONNECTOR_FILES_URL,
                '/'
            );
            $ch = curl_init($filesBase . self::UPLOAD_PATH);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_POST, true);
            if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/json',
                'X-App-Id: ' . $appId,
                'X-Client-Id: ' . $credentials['client_id'],
                'X-Timestamp: ' . $timestamp,
                'X-Nonce: ' . $nonce,
                'X-Signature: ' . $signature,
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                'file' => new CURLFile($path, $mimeType !== '' ? $mimeType : 'application/octet-stream', $uploadName),
                'source_domain' => $sourceDomain,
                'uploaded_by_user_id' => $uploadedBy,
                'context' => $context,
                'folder' => $folder,
                'file_sha256' => $fileSha256,
            ]);

            $response = curl_exec($ch);
            $curlError = curl_errno($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false) {
                $err = 'Files Service upload failed before a response was received. cURL error ' . $curlError . '.';
                if (function_exists('fs_connector_record_upload_attempt')) {
                    fs_connector_record_upload_attempt([
                        'source_domain' => $sourceDomain,
                        'app_id' => $appId,
                        'client_id' => $credentials['client_id'],
                        'http_code' => 0,
                        'success' => false,
                        'error' => $err,
                    ]);
                }
                return ['success' => false, 'error' => $err];
            }

            $decoded = json_decode((string) $response, true);
            $success = in_array($httpCode, [200, 201, 202], true)
                && is_array($decoded)
                && ($decoded['success'] ?? false) === true
                && !empty($decoded['file_id']);

            $safeResponse = function_exists('fs_connector_safe_remote_response')
                ? fs_connector_safe_remote_response((string) $response)
                : '(response redacted)';

            if (function_exists('fs_connector_record_upload_attempt')) {
                fs_connector_record_upload_attempt([
                    'source_domain' => $sourceDomain,
                    'app_id' => $appId,
                    'client_id' => $credentials['client_id'],
                    'http_code' => $httpCode,
                    'success' => $success,
                    'error' => $success ? '' : $safeResponse,
                ]);
            }

            if (!$success) {
                $error = function_exists('fs_connector_friendly_upload_error')
                    ? fs_connector_friendly_upload_error($httpCode, $safeResponse)
                    : ('Files Service upload failed. HTTP ' . $httpCode . '. ' . $safeResponse);

                return [
                    'success' => false,
                    'error' => $error,
                    'http_code' => $httpCode,
                    'upload_identity' => [
                        'source_domain' => $sourceDomain,
                        'app_id' => $appId,
                        'client_id_prefix' => function_exists('fs_connector_client_id_prefix')
                            ? fs_connector_client_id_prefix((string) $credentials['client_id'])
                            : '',
                    ],
                ];
            }

            return [
                'success' => true,
                'file_id' => (string) $decoded['file_id'],
                'media_url' => self::mediaUrl((string) $decoded['file_id']),
                'download_url' => !empty($decoded['download_url']) ? (string) $decoded['download_url'] : self::remoteDownloadUrl((string) $decoded['file_id']),
                'http_code' => $httpCode,
            ];
        }

        public static function upsertMapping(array $data): array
        {
            if (!self::mapTableExists()) {
                throw new RuntimeException('Files Service media mapping table does not exist.');
            }

            self::ensureMapSchema();

            $localPath = (string) ($data['local_path'] ?? '');
            $localUrl = (string) ($data['local_url'] ?? '');
            $existing = null;
            if ($localPath !== '') {
                $existing = Database::selectOne("SELECT * FROM `" . Database::prefix('files_service_media_map') . "` WHERE local_path = ? ORDER BY id DESC LIMIT 1", [$localPath]);
            }
            if (!$existing && $localUrl !== '') {
                $existing = Database::selectOne("SELECT * FROM `" . Database::prefix('files_service_media_map') . "` WHERE local_url = ? ORDER BY id DESC LIMIT 1", [$localUrl]);
            }

            $row = [
                'local_path' => $localPath,
                'local_url' => $localUrl,
                'file_id' => (string) ($data['file_id'] ?? ''),
                'remote_url' => (string) ($data['remote_url'] ?? ''),
                'media_url' => (string) ($data['media_url'] ?? $data['remote_url'] ?? ''),
                'download_url' => (string) ($data['download_url'] ?? ''),
                'mime_type' => (string) ($data['mime_type'] ?? ''),
                'size_bytes' => (string) ($data['size_bytes'] ?? '0'),
                'sha256' => (string) ($data['sha256'] ?? ''),
                'source_table' => (string) ($data['source_table'] ?? ''),
                'source_column' => (string) ($data['source_column'] ?? ''),
                'source_record_id' => (string) ($data['source_record_id'] ?? '0'),
                'migration_status' => (string) ($data['migration_status'] ?? 'mapped'),
                'last_error' => substr((string) ($data['last_error'] ?? ''), 0, 1000),
                'previous_file_id' => (string) ($data['previous_file_id'] ?? ''),
                'previous_media_url' => (string) ($data['previous_media_url'] ?? ''),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            $availableColumns = self::mapTableColumns();
            $row = array_intersect_key($row, array_flip($availableColumns));

            if ($existing) {
                Database::update('files_service_media_map', $row, 'id = ?', [(int) $existing['id']]);
                $id = (int) $existing['id'];
            } else {
                if (in_array('created_at', $availableColumns, true)) {
                    $row['created_at'] = date('Y-m-d H:i:s');
                }
                $id = Database::insert('files_service_media_map', $row);
            }

            return Database::selectOne("SELECT * FROM `" . Database::prefix('files_service_media_map') . "` WHERE id = ?", [$id]) ?: $row;
        }

        /** Maximum age (seconds) of a stored preview before commit rejects it as stale. */
        public const PREVIEW_MAX_AGE_SECONDS = 86400;

        public static function rewriteTargets(): array
        {
            return [
                ['table' => 'pages', 'columns' => ['content']],
                ['table' => 'posts', 'columns' => ['content', 'featured_image']],
                ['table' => 'media', 'columns' => ['url']],
            ];
        }

        public static function frontendVerifyTargets(): array
        {
            return [
                ['table' => 'pages', 'columns' => ['content']],
                ['table' => 'posts', 'columns' => ['content', 'featured_image']],
            ];
        }

        /**
         * Current CMS hostname for upload identity and rewrite source patterns.
         * Uses the single installed-identity helper — never a hardcoded product domain.
         */
        public static function sourceCmsHostname(): string
        {
            if (function_exists('fs_connector_installed_identity')) {
                $identity = fs_connector_installed_identity();
                $domain = fs_connector_normalize_hostname((string) ($identity['source_domain'] ?? ''));
                if ($domain !== '') {
                    return $domain;
                }
                $domain = fs_connector_normalize_hostname((string) ($identity['site_domain'] ?? ''));
                if ($domain !== '') {
                    return $domain;
                }
            }
            if (function_exists('fs_connector_option')) {
                $configured = fs_connector_normalize_hostname(fs_connector_option('fs_conn_domain', ''));
                if ($configured !== '') {
                    return $configured;
                }
            }
            if (function_exists('soi_site_domain')) {
                $domain = soi_site_domain();
                if ($domain !== '') {
                    return $domain;
                }
            }
            if (function_exists('fs_connector_site_identity')) {
                $domain = fs_connector_normalize_hostname((string) (fs_connector_site_identity()['site_domain'] ?? ''));
                if ($domain !== '') {
                    return $domain;
                }
            }
            if (defined('SOI_HOME_URL')) {
                return fs_connector_normalize_hostname(SOI_HOME_URL);
            }
            return '';
        }

        /**
         * @return list<string>
         */
        public static function allowedSourceUrlPatterns(): array
        {
            $domain = self::sourceCmsHostname();
            $patterns = [
                '#^/uploads/.+#i',
                '#^uploads/.+#i',
                '#^/media/view/[A-Za-z0-9._-]+$#i',
                '#^media/view/[A-Za-z0-9._-]+$#i',
            ];
            if ($domain !== '') {
                $quoted = preg_quote($domain, '#');
                $patterns[] = '#^https?://' . $quoted . '/uploads/.+#i';
                $patterns[] = '#^https?://' . $quoted . '/media/view/[A-Za-z0-9._-]+$#i';
            }
            return $patterns;
        }

        /**
         * @return list<string>
         */
        public static function allowedSourceUrlPatternDescriptions(): array
        {
            $domain = self::sourceCmsHostname();
            $host = $domain !== '' ? $domain : '{current_domain}';
            return [
                'https://' . $host . '/uploads/...',
                'http(s)://' . $host . '/uploads/...',
                '/uploads/...',
                'uploads/...',
                'https://' . $host . '/media/view/{hash}',
                '/media/view/{hash}',
                'media/view/{hash}',
            ];
        }

        public static function isAllowedSourceNeedle(string $needle): bool
        {
            $needle = trim($needle);
            if ($needle === '' || str_starts_with($needle, 'data:')) {
                return false;
            }

            foreach (self::allowedSourceUrlPatterns() as $pattern) {
                if (preg_match($pattern, $needle)) {
                    return true;
                }
            }

            return false;
        }

        public static function referencePreview(int $limit = 100): array
        {
            $scan = self::scanReferenceReplacements(false, $limit);
            if (!($scan['success'] ?? false)) {
                self::clearStoredPreview();
                return $scan;
            }

            $previewToken = 'fsprev_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
            $items = $scan['items'] ?? [];
            $safeItems = [];
            $skippedItems = [];
            $tables = [];
            $columns = [];
            $recordIds = [];
            $safeReplacementCount = 0;
            /** @var array<string, array> $fieldPlans Group safe replacements by field for atomic commit. */
            $fieldPlans = [];

            foreach ($items as $item) {
                $isSafe = !empty($item['safe']) && empty($item['will_skip']);
                if ($isSafe) {
                    $safeItems[] = $item;
                    $safeReplacementCount += (int) ($item['replacement_count'] ?? 0);
                    $table = (string) ($item['table'] ?? '');
                    $column = (string) ($item['column'] ?? '');
                    $rowId = (int) ($item['row_id'] ?? 0);
                    if ($table !== '') {
                        $tables[$table] = true;
                    }
                    if ($column !== '') {
                        $columns[$column] = true;
                    }
                    if ($rowId > 0) {
                        $recordIds[$table . '#' . $rowId] = true;
                    }

                    $key = $table . "\0" . $rowId . "\0" . $column;
                    if (!isset($fieldPlans[$key])) {
                        $fieldPlans[$key] = [
                            'table' => $table,
                            'row_id' => $rowId,
                            'column' => $column,
                            'content_hash' => (string) ($item['content_hash'] ?? ''),
                            'replacement_count' => 0,
                            'pairs' => [],
                        ];
                    }
                    $fieldPlans[$key]['replacement_count'] += (int) ($item['replacement_count'] ?? 0);
                    $fieldPlans[$key]['pairs'][] = [
                        'from' => (string) ($item['from'] ?? ''),
                        'to' => (string) ($item['to'] ?? ''),
                        'count' => (int) ($item['replacement_count'] ?? 0),
                        'mapping_source' => (string) ($item['mapping_source'] ?? ''),
                    ];
                } else {
                    $skippedItems[] = $item;
                }
            }

            $hasUnsafe = false;
            foreach ($safeItems as $item) {
                if (!self::isAllowedSourceNeedle((string) ($item['from'] ?? '')) || !self::isCanonicalMediaUrl((string) ($item['to'] ?? ''))) {
                    $hasUnsafe = true;
                    break;
                }
            }

            $planList = array_values($fieldPlans);
            $payload = [
                'preview_token' => $previewToken,
                'created_at' => date('Y-m-d H:i:s'),
                'created_ts' => time(),
                'replacement_count' => $safeReplacementCount,
                'record_count' => count($planList),
                'tables' => array_keys($tables),
                'columns' => array_keys($columns),
                'record_ids' => array_keys($recordIds),
                'has_unsafe' => $hasUnsafe,
                'items' => $planList,
                'skipped_count' => count($skippedItems),
                'fingerprint' => hash('sha256', json_encode($planList, JSON_UNESCAPED_SLASHES) ?: ''),
            ];
            self::storePreview($payload);

            $scan['preview_token'] = $previewToken;
            $scan['preview_created_at'] = $payload['created_at'];
            $scan['preview_valid'] = !$hasUnsafe;
            $scan['has_unsafe'] = $hasUnsafe;
            $scan['candidate_count'] = count($items);
            $scan['safe_count'] = count($safeItems);
            $scan['skipped_count'] = count($skippedItems);
            $scan['replacement_count'] = $safeReplacementCount;
            $scan['tables_affected'] = array_keys($tables);
            $scan['columns_affected'] = array_keys($columns);
            $scan['record_ids_affected'] = array_keys($recordIds);
            $scan['items'] = $items;
            $scan['committed'] = false;
            $scan['changed'] = 0;

            return $scan;
        }

        public static function commitReferenceReplacements(int $limit = 100): array
        {
            $settings = function_exists('fs_connector_get_settings') ? fs_connector_get_settings() : [];
            if (empty($settings['rewrite_content_urls_enabled'])) {
                return [
                    'success' => false,
                    'error' => 'Content URL rewrite is disabled. Enable “Rewrite content URLs after preview” before committing.',
                    'items' => [],
                    'changed' => 0,
                    'committed' => false,
                ];
            }

            if (!self::mapTableExists()) {
                return ['success' => false, 'error' => 'Media mapping table missing.', 'items' => [], 'changed' => 0, 'committed' => false];
            }
            if (!self::backupTableExists()) {
                return ['success' => false, 'error' => 'Content backup table missing. Cannot commit without rollback storage.', 'items' => [], 'changed' => 0, 'committed' => false];
            }

            $preview = self::getStoredPreview();
            if ($preview === null) {
                return [
                    'success' => false,
                    'error' => 'No valid rewrite preview exists. Run Preview Reference Replacements first.',
                    'items' => [],
                    'changed' => 0,
                    'committed' => false,
                ];
            }

            $staleness = self::previewStaleness($preview);
            if ($staleness !== null) {
                return [
                    'success' => false,
                    'error' => $staleness,
                    'items' => [],
                    'changed' => 0,
                    'committed' => false,
                    'preview_stale' => true,
                ];
            }

            if (!empty($preview['has_unsafe'])) {
                return [
                    'success' => false,
                    'error' => 'Stored preview contains unsafe replacements. Re-run preview and review skipped rows.',
                    'items' => [],
                    'changed' => 0,
                    'committed' => false,
                ];
            }

            $previewItems = $preview['items'] ?? [];
            if (!is_array($previewItems) || $previewItems === []) {
                return [
                    'success' => false,
                    'error' => 'Stored preview has no safe replacement candidates to commit.',
                    'items' => [],
                    'changed' => 0,
                    'committed' => false,
                ];
            }

            if (count($previewItems) > $limit) {
                return [
                    'success' => false,
                    'error' => 'Commit limit is lower than previewed record count. Re-run preview or raise limit.',
                    'items' => [],
                    'changed' => 0,
                    'committed' => false,
                ];
            }

            self::stabilizeExistingMappings();

            $batchToken = 'fscref_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
            $changed = 0;
            $replacementTotal = 0;
            $committedItems = [];
            $maxRecords = count($previewItems);

            foreach ($previewItems as $plan) {
                if ($changed >= $maxRecords) {
                    break;
                }

                $table = (string) ($plan['table'] ?? '');
                $column = (string) ($plan['column'] ?? '');
                $rowId = (int) ($plan['row_id'] ?? 0);
                $expectedHash = (string) ($plan['content_hash'] ?? '');
                $pairs = $plan['pairs'] ?? null;

                // Backward-compatible single-pair plan shape.
                if (!is_array($pairs) || $pairs === []) {
                    $pairs = [[
                        'from' => (string) ($plan['from'] ?? ''),
                        'to' => (string) ($plan['to'] ?? ''),
                        'count' => (int) ($plan['replacement_count'] ?? 0),
                        'mapping_source' => (string) ($plan['mapping_source'] ?? 'preview_batch'),
                    ]];
                }

                if (!in_array($table, ['pages', 'posts', 'media'], true)
                    || !in_array($column, ['content', 'featured_image', 'url'], true)
                    || $rowId < 1
                ) {
                    self::clearStoredPreview();
                    return [
                        'success' => false,
                        'error' => 'Commit aborted: preview plan contains an invalid target field.',
                        'items' => $committedItems,
                        'changed' => $changed,
                        'committed' => false,
                    ];
                }

                foreach ($pairs as $pair) {
                    $from = (string) ($pair['from'] ?? '');
                    $to = (string) ($pair['to'] ?? '');
                    if (!self::isAllowedSourceNeedle($from) || !self::isCanonicalMediaUrl($to)) {
                        self::clearStoredPreview();
                        return [
                            'success' => false,
                            'error' => 'Commit aborted: preview plan contains an unsafe or invalid replacement rule.',
                            'items' => $committedItems,
                            'changed' => $changed,
                            'committed' => false,
                        ];
                    }
                }

                if (!Database::tableExists($table)) {
                    continue;
                }

                $row = Database::selectOne("SELECT `{$column}` AS value FROM `" . Database::prefix($table) . "` WHERE id = ? LIMIT 1", [$rowId]);
                if (!$row) {
                    self::clearStoredPreview();
                    return [
                        'success' => false,
                        'error' => "Commit aborted: preview is stale (missing {$table}#{$rowId}). Re-run preview.",
                        'items' => $committedItems,
                        'changed' => $changed,
                        'committed' => false,
                        'preview_stale' => true,
                    ];
                }

                $original = (string) ($row['value'] ?? '');
                $actualHash = hash('sha256', $original);
                if ($expectedHash !== '' && !hash_equals($expectedHash, $actualHash)) {
                    self::clearStoredPreview();
                    return [
                        'success' => false,
                        'error' => "Commit aborted: preview is stale ({$table}#{$rowId}.{$column} changed since preview). Re-run preview.",
                        'items' => $committedItems,
                        'changed' => $changed,
                        'committed' => false,
                        'preview_stale' => true,
                    ];
                }

                $updated = $original;
                $fieldReplacementCount = 0;
                // Apply longest needles first to avoid partial collisions.
                usort($pairs, static fn(array $a, array $b): int => strlen((string) ($b['from'] ?? '')) <=> strlen((string) ($a['from'] ?? '')));

                foreach ($pairs as $pair) {
                    $from = (string) ($pair['from'] ?? '');
                    $to = (string) ($pair['to'] ?? '');
                    $expectedCount = (int) ($pair['count'] ?? 0);
                    if ($from === '' || !str_contains($updated, $from)) {
                        self::clearStoredPreview();
                        return [
                            'success' => false,
                            'error' => "Commit aborted: expected source URL no longer present in {$table}#{$rowId}.{$column}. Re-run preview.",
                            'items' => $committedItems,
                            'changed' => $changed,
                            'committed' => false,
                            'preview_stale' => true,
                        ];
                    }
                    $occurrences = substr_count($updated, $from);
                    if ($expectedCount > 0 && $occurrences !== $expectedCount) {
                        self::clearStoredPreview();
                        return [
                            'success' => false,
                            'error' => "Commit aborted: replacement count mismatch for {$table}#{$rowId}.{$column}. Re-run preview.",
                            'items' => $committedItems,
                            'changed' => $changed,
                            'committed' => false,
                            'preview_stale' => true,
                        ];
                    }
                    $updated = str_replace($from, $to, $updated);
                    $fieldReplacementCount += $occurrences;
                    $committedItems[] = [
                        'table' => $table,
                        'row_id' => $rowId,
                        'column' => $column,
                        'replacement_count' => $occurrences,
                        'from' => $from,
                        'to' => $to,
                        'safe' => true,
                        'will_skip' => false,
                        'skip_reason' => '',
                        'mapping_source' => (string) ($pair['mapping_source'] ?? 'preview_batch'),
                        'sample' => substr(strip_tags($original), 0, 120),
                    ];
                }

                if ($updated === $original || $fieldReplacementCount < 1) {
                    continue;
                }

                // Backup original content before any permanent rewrite.
                self::backupContentValue($table, $rowId, $column, $original, $fieldReplacementCount, $batchToken);
                Database::update($table, [$column => $updated], 'id = ?', [$rowId]);
                $changed++;
                $replacementTotal += $fieldReplacementCount;
            }

            if ($changed > $maxRecords) {
                self::clearStoredPreview();
                return [
                    'success' => false,
                    'error' => 'Commit aborted: would rewrite more records than previewed.',
                    'items' => [],
                    'changed' => 0,
                    'committed' => false,
                ];
            }

            self::clearStoredPreview();
            if (function_exists('fs_connector_set_option')) {
                fs_connector_set_option('fs_conn_last_rewrite_batch', $batchToken);
                fs_connector_set_option('fs_conn_last_rewrite_commit_at', date('Y-m-d H:i:s'));
                fs_connector_set_option('fs_conn_last_rewrite_commit_count', (string) $replacementTotal);
            }

            return [
                'success' => true,
                'committed' => true,
                'batch_token' => $changed > 0 ? $batchToken : '',
                'changed' => $changed,
                'replacement_count' => $replacementTotal,
                'preview_token' => (string) ($preview['preview_token'] ?? ''),
                'items' => $committedItems,
                'message' => $changed > 0
                    ? "Committed {$replacementTotal} replacement(s) across {$changed} field(s). Rollback batch: {$batchToken}."
                    : 'No content fields required changes.',
            ];
        }

        private static function scanReferenceReplacements(bool $commit, int $limit): array
        {
            // Commit path uses commitReferenceReplacements(); scan is preview-only.
            if ($commit) {
                return self::commitReferenceReplacements($limit);
            }

            if (!self::mapTableExists()) {
                return ['success' => false, 'error' => 'Media mapping table missing.', 'items' => []];
            }

            self::stabilizeExistingMappings();

            $maps = Database::select("SELECT * FROM `" . Database::prefix('files_service_media_map') . "` WHERE file_id <> '' ORDER BY id ASC");
            $needleIndex = self::buildNeedleIndex($maps);
            $items = [];
            $targets = self::rewriteTargets();

            foreach ($targets as $target) {
                if (!Database::tableExists($target['table'])) {
                    continue;
                }

                $rows = Database::select("SELECT * FROM `" . Database::prefix($target['table']) . "` ORDER BY id ASC");
                foreach ($rows as $row) {
                    $rowId = (int) ($row['id'] ?? 0);
                    foreach ($target['columns'] as $column) {
                        $original = (string) ($row[$column] ?? '');
                        if (trim($original) === '') {
                            continue;
                        }

                        $fieldItems = self::classifyFieldReferences(
                            $target['table'],
                            $rowId,
                            $column,
                            $original,
                            $needleIndex,
                            $maps
                        );

                        foreach ($fieldItems as $fieldItem) {
                            $items[] = $fieldItem;
                            if (count($items) >= $limit) {
                                return self::finalizeScanResult($items, true);
                            }
                        }
                    }
                }
            }

            return self::finalizeScanResult($items, false);
        }

        private static function finalizeScanResult(array $items, bool $truncated): array
        {
            $safeCount = 0;
            $skippedCount = 0;
            $replacementCount = 0;
            $tables = [];
            $columns = [];
            $recordIds = [];

            foreach ($items as $item) {
                if (!empty($item['safe']) && empty($item['will_skip'])) {
                    $safeCount++;
                    $replacementCount += (int) ($item['replacement_count'] ?? 0);
                    $tables[(string) ($item['table'] ?? '')] = true;
                    $columns[(string) ($item['column'] ?? '')] = true;
                    $recordIds[(string) ($item['table'] ?? '') . '#' . (int) ($item['row_id'] ?? 0)] = true;
                } else {
                    $skippedCount++;
                }
            }

            return [
                'success' => true,
                'committed' => false,
                'truncated' => $truncated,
                'batch_token' => '',
                'changed' => 0,
                'items' => $items,
                'candidate_count' => count($items),
                'safe_count' => $safeCount,
                'skipped_count' => $skippedCount,
                'replacement_count' => $replacementCount,
                'tables_affected' => array_values(array_filter(array_keys($tables))),
                'columns_affected' => array_values(array_filter(array_keys($columns))),
                'record_ids_affected' => array_values(array_filter(array_keys($recordIds))),
            ];
        }

        /**
         * @return array<string, array{to:string,map:array,mapping_source:string}>
         */
        private static function buildNeedleIndex(array $maps): array
        {
            $index = [];
            foreach ($maps as $map) {
                $fileId = trim((string) ($map['file_id'] ?? ''));
                if ($fileId === '') {
                    continue;
                }

                $mediaUrl = self::mappingMediaUrl($map);
                $mappingSource = 'map#' . (int) ($map['id'] ?? 0) . '/file_id=' . $fileId;

                if (!self::isCanonicalMediaUrl($mediaUrl)) {
                    continue;
                }

                foreach (self::referenceNeedles($map) as $needle) {
                    if (!self::isAllowedSourceNeedle($needle)) {
                        continue;
                    }
                    // Prefer longer needles when the same key appears (more specific match).
                    if (!isset($index[$needle]) || strlen($needle) >= strlen((string) ($index[$needle]['needle'] ?? ''))) {
                        $index[$needle] = [
                            'needle' => $needle,
                            'to' => $mediaUrl,
                            'map' => $map,
                            'mapping_source' => $mappingSource,
                        ];
                    }
                }
            }

            // Longest-first so nested path matches resolve consistently.
            uksort($index, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

            return $index;
        }

        private static function classifyFieldReferences(
            string $table,
            int $rowId,
            string $column,
            string $original,
            array $needleIndex,
            array $maps
        ): array {
            $items = [];
            $contentHash = hash('sha256', $original);
            $sample = substr(strip_tags($original), 0, 120);
            $matchedSpans = [];

            // Safe mapped replacements first.
            foreach ($needleIndex as $needle => $meta) {
                if ($needle === '' || !str_contains($original, $needle)) {
                    continue;
                }

                // Skip if this span was already claimed by a longer needle.
                $already = false;
                foreach ($matchedSpans as $span) {
                    if (str_contains($span, $needle) || str_contains($needle, $span)) {
                        // Allow exact same needle once.
                        if ($span === $needle) {
                            $already = true;
                        }
                    }
                }
                if ($already) {
                    continue;
                }

                $occurrences = substr_count($original, $needle);
                if ($occurrences < 1) {
                    continue;
                }

                $matchedSpans[] = $needle;
                $items[] = [
                    'table' => $table,
                    'row_id' => $rowId,
                    'column' => $column,
                    'replacement_count' => $occurrences,
                    'from' => $needle,
                    'to' => (string) $meta['to'],
                    'mapping_source' => (string) $meta['mapping_source'],
                    'safe' => true,
                    'will_skip' => false,
                    'skip_reason' => '',
                    'content_hash' => $contentHash,
                    'sample' => $sample,
                ];
            }

            // Additional media-like references for skip reporting.
            $candidates = self::extractMediaLikeReferences($original);
            foreach ($candidates as $candidate) {
                $covered = false;
                foreach ($matchedSpans as $span) {
                    if ($candidate === $span || str_contains($candidate, $span) || str_contains($span, $candidate)) {
                        $covered = true;
                        break;
                    }
                }
                if ($covered) {
                    continue;
                }

                $classification = self::classifySkippedReference($candidate, $maps);
                $items[] = [
                    'table' => $table,
                    'row_id' => $rowId,
                    'column' => $column,
                    'replacement_count' => 0,
                    'from' => $candidate,
                    'to' => '',
                    'mapping_source' => $classification['mapping_source'],
                    'safe' => false,
                    'will_skip' => true,
                    'skip_reason' => $classification['reason'],
                    'content_hash' => $contentHash,
                    'sample' => $sample,
                ];
            }

            return $items;
        }

        /**
         * @return list<string>
         */
        private static function extractMediaLikeReferences(string $value): array
        {
            if ($value === '') {
                return [];
            }

            $found = [];
            $patterns = [
                // data: URI images
                '~data:image/[a-zA-Z0-9.+-]+;base64,[A-Za-z0-9+/=]+~i',
                // Absolute and relative uploads / media view / files service
                '~https?://[^\s"\'<>\)]+~i',
                '~(?<![A-Za-z0-9:/])/(?:uploads/[^\s"\'<>\)]+|media/view/[A-Za-z0-9._-]+|files/view/[A-Za-z0-9._-]+)~i',
                '~(?<![A-Za-z0-9:/])(?:uploads/[^\s"\'<>\)]+|media/view/[A-Za-z0-9._-]+)~i',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $value, $matches)) {
                    foreach ($matches[0] as $match) {
                        $match = trim((string) $match);
                        if ($match !== '') {
                            $found[$match] = true;
                        }
                    }
                }
            }

            return array_keys($found);
        }

        /**
         * @return array{reason:string,mapping_source:string}
         */
        private static function classifySkippedReference(string $candidate, array $maps): array
        {
            $candidate = trim($candidate);
            if ($candidate === '') {
                return ['reason' => 'empty value', 'mapping_source' => 'none'];
            }
            if (str_starts_with(strtolower($candidate), 'data:')) {
                return ['reason' => 'base64 inline image', 'mapping_source' => 'none'];
            }

            if (preg_match('#^https://files\.soi\.co\.in/media/[^/\s]+$#i', $candidate)) {
                return ['reason' => 'already rewritten Files Service /media URL', 'mapping_source' => 'files_service'];
            }
            if (preg_match('#https?://files\.soi\.co\.in/files/view/#i', $candidate) || preg_match('#(?<![A-Za-z0-9:])/files/view/#i', $candidate)) {
                return ['reason' => 'legacy /files/view URL (not rewritten; use /media/{file_id})', 'mapping_source' => 'legacy_files_view'];
            }

            $host = parse_url($candidate, PHP_URL_HOST);
            $sourceHost = self::sourceCmsHostname();
            if (is_string($host) && $host !== '') {
                $hostNorm = strtolower($host);
                if ($hostNorm === 'files.soi.co.in') {
                    return ['reason' => 'Files Service URL not in canonical /media/{file_id} form', 'mapping_source' => 'files_service'];
                }
                if ($sourceHost !== '' && $hostNorm !== $sourceHost) {
                    return ['reason' => 'external URL / other domain', 'mapping_source' => 'external'];
                }
            }

            if (!self::isAllowedSourceNeedle($candidate)
                && !preg_match('#(?:uploads/|media/view/)#i', $candidate)
            ) {
                return ['reason' => 'unrelated text or unsupported URL shape', 'mapping_source' => 'none'];
            }

            // Local-looking but unmapped / broken mapping.
            foreach ($maps as $map) {
                foreach (self::referenceNeedles($map) as $needle) {
                    if ($needle === $candidate || str_contains($candidate, $needle) || str_contains($needle, $candidate)) {
                        $mediaUrl = self::mappingMediaUrl($map);
                        if (!self::isCanonicalMediaUrl($mediaUrl)) {
                            return [
                                'reason' => 'broken mapping (non-canonical target)',
                                'mapping_source' => 'map#' . (int) ($map['id'] ?? 0),
                            ];
                        }
                    }
                }
            }

            if (preg_match('#(?:uploads/|media/view/)#i', $candidate)) {
                return ['reason' => 'unmapped media / unknown local file', 'mapping_source' => 'none'];
            }

            return ['reason' => 'unsupported or unsafe reference', 'mapping_source' => 'none'];
        }

        public static function storePreview(array $payload): void
        {
            if (!function_exists('fs_connector_set_option')) {
                return;
            }
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return;
            }
            fs_connector_set_option('fs_conn_rewrite_preview', $json);
            fs_connector_set_option('fs_conn_rewrite_preview_token', (string) ($payload['preview_token'] ?? ''));
            fs_connector_set_option('fs_conn_rewrite_preview_at', (string) ($payload['created_at'] ?? date('Y-m-d H:i:s')));
        }

        public static function clearStoredPreview(): void
        {
            if (!function_exists('fs_connector_set_option')) {
                return;
            }
            fs_connector_set_option('fs_conn_rewrite_preview', '');
            fs_connector_set_option('fs_conn_rewrite_preview_token', '');
            fs_connector_set_option('fs_conn_rewrite_preview_at', '');
        }

        public static function getStoredPreview(): ?array
        {
            if (!function_exists('fs_connector_option')) {
                return null;
            }
            $raw = trim((string) fs_connector_option('fs_conn_rewrite_preview', ''));
            if ($raw === '') {
                return null;
            }
            $data = json_decode($raw, true);
            if (!is_array($data) || empty($data['preview_token']) || !is_array($data['items'] ?? null)) {
                return null;
            }
            return $data;
        }

        public static function previewStatus(): array
        {
            $preview = self::getStoredPreview();
            if ($preview === null) {
                return [
                    'exists' => false,
                    'valid' => false,
                    'stale' => false,
                    'preview_token' => '',
                    'created_at' => '',
                    'replacement_count' => 0,
                    'record_count' => 0,
                    'tables' => [],
                    'columns' => [],
                    'has_unsafe' => false,
                    'message' => 'No preview batch stored.',
                ];
            }

            $staleReason = self::previewStaleness($preview);
            return [
                'exists' => true,
                'valid' => $staleReason === null && empty($preview['has_unsafe']) && (int) ($preview['replacement_count'] ?? 0) >= 0,
                'stale' => $staleReason !== null,
                'stale_reason' => $staleReason,
                'preview_token' => (string) ($preview['preview_token'] ?? ''),
                'created_at' => (string) ($preview['created_at'] ?? ''),
                'replacement_count' => (int) ($preview['replacement_count'] ?? 0),
                'record_count' => (int) ($preview['record_count'] ?? 0),
                'tables' => $preview['tables'] ?? [],
                'columns' => $preview['columns'] ?? [],
                'has_unsafe' => !empty($preview['has_unsafe']),
                'message' => $staleReason ?? 'Preview batch ready for commit review.',
            ];
        }

        public static function previewStaleness(array $preview): ?string
        {
            $createdTs = (int) ($preview['created_ts'] ?? 0);
            if ($createdTs < 1) {
                $createdAt = (string) ($preview['created_at'] ?? '');
                $parsed = $createdAt !== '' ? strtotime($createdAt) : false;
                $createdTs = $parsed !== false ? (int) $parsed : 0;
            }
            if ($createdTs < 1) {
                return 'Stored preview has no valid timestamp. Re-run preview.';
            }
            if ((time() - $createdTs) > self::PREVIEW_MAX_AGE_SECONDS) {
                return 'Stored preview is older than 24 hours. Re-run Preview Reference Replacements.';
            }

            // Light content fingerprint check for safe items.
            foreach (($preview['items'] ?? []) as $plan) {
                $table = (string) ($plan['table'] ?? '');
                $column = (string) ($plan['column'] ?? '');
                $rowId = (int) ($plan['row_id'] ?? 0);
                $expectedHash = (string) ($plan['content_hash'] ?? '');
                if ($expectedHash === '' || $rowId < 1 || $table === '' || $column === '' || !Database::tableExists($table)) {
                    continue;
                }
                if (!in_array($column, ['content', 'featured_image', 'url'], true)) {
                    continue;
                }
                $row = Database::selectOne("SELECT `{$column}` AS value FROM `" . Database::prefix($table) . "` WHERE id = ? LIMIT 1", [$rowId]);
                if (!$row) {
                    return "Preview is stale: {$table}#{$rowId} no longer exists.";
                }
                if (!hash_equals($expectedHash, hash('sha256', (string) ($row['value'] ?? '')))) {
                    return "Preview is stale: {$table}#{$rowId}.{$column} changed after preview.";
                }
            }

            return null;
        }

        public static function verifyFrontendReferences(): array
        {
            $stats = [
                'success' => true,
                'scanned_fields' => 0,
                'total_media_references' => 0,
                'files_service_media_urls' => 0,
                'local_fallback_urls' => 0,
                'broken_looking_references' => 0,
                'external_urls_skipped' => 0,
                'mixed_local_remote_records' => 0,
                'legacy_files_view_urls' => 0,
                'canonical_media_format_ok' => true,
                'samples' => [],
            ];

            $sourceHost = self::sourceCmsHostname();
            $filesHost = fs_connector_normalize_hostname(defined('FS_CONNECTOR_FILES_URL') ? FS_CONNECTOR_FILES_URL : 'https://files.soi.co.in');

            foreach (self::frontendVerifyTargets() as $target) {
                if (!Database::tableExists($target['table'])) {
                    continue;
                }
                $rows = Database::select("SELECT * FROM `" . Database::prefix($target['table']) . "` ORDER BY id ASC");
                foreach ($rows as $row) {
                    $rowId = (int) ($row['id'] ?? 0);
                    foreach ($target['columns'] as $column) {
                        $value = (string) ($row[$column] ?? '');
                        if (trim($value) === '') {
                            continue;
                        }
                        $stats['scanned_fields']++;

                        $hasLocal = false;
                        $hasRemote = false;
                        $refs = self::extractMediaLikeReferences($value);
                        foreach ($refs as $ref) {
                            $stats['total_media_references']++;
                            $lower = strtolower($ref);

                            if (str_starts_with($lower, 'data:')) {
                                continue;
                            }

                            if (preg_match('#https?://files\.soi\.co\.in/files/view/#i', $ref) || preg_match('#(?<![A-Za-z0-9:])/files/view/#i', $ref)) {
                                $stats['legacy_files_view_urls']++;
                                $stats['broken_looking_references']++;
                                $stats['canonical_media_format_ok'] = false;
                                $stats['samples'][] = [
                                    'table' => $target['table'],
                                    'row_id' => $rowId,
                                    'column' => $column,
                                    'ref' => $ref,
                                    'class' => 'legacy_files_view',
                                ];
                                continue;
                            }

                            if (preg_match('#^https://files\.soi\.co\.in/media/[^/\s]+$#i', $ref)) {
                                $stats['files_service_media_urls']++;
                                $hasRemote = true;
                                continue;
                            }

                            if (preg_match('#https?://files\.soi\.co\.in/#i', $ref)) {
                                $stats['broken_looking_references']++;
                                $stats['canonical_media_format_ok'] = false;
                                $stats['samples'][] = [
                                    'table' => $target['table'],
                                    'row_id' => $rowId,
                                    'column' => $column,
                                    'ref' => $ref,
                                    'class' => 'non_canonical_files_service',
                                ];
                                continue;
                            }

                            $host = parse_url($ref, PHP_URL_HOST);
                            if (is_string($host) && $host !== '' && strtolower($host) !== $sourceHost && strtolower($host) !== $filesHost) {
                                $stats['external_urls_skipped']++;
                                continue;
                            }

                            if (preg_match('#(?:uploads/|media/view/)#i', $ref)) {
                                $stats['local_fallback_urls']++;
                                $hasLocal = true;
                                continue;
                            }

                            // Absolute source-host URLs that are not recognized media shapes.
                            if (is_string($host) && strtolower($host) === $sourceHost) {
                                $stats['broken_looking_references']++;
                                $stats['samples'][] = [
                                    'table' => $target['table'],
                                    'row_id' => $rowId,
                                    'column' => $column,
                                    'ref' => $ref,
                                    'class' => 'broken_looking',
                                ];
                            }
                        }

                        if ($hasLocal && $hasRemote) {
                            $stats['mixed_local_remote_records']++;
                        }
                    }
                }
            }

            $stats['samples'] = array_slice($stats['samples'], 0, 40);
            $stats['message'] = sprintf(
                'Scanned %d field(s), %d media reference(s): %d Files Service /media, %d local fallback, %d legacy /files/view, %d external skipped, %d mixed records.',
                $stats['scanned_fields'],
                $stats['total_media_references'],
                $stats['files_service_media_urls'],
                $stats['local_fallback_urls'],
                $stats['legacy_files_view_urls'],
                $stats['external_urls_skipped'],
                $stats['mixed_local_remote_records']
            );

            if (function_exists('fs_connector_set_option')) {
                fs_connector_set_option('fs_conn_last_frontend_verify_at', date('Y-m-d H:i:s'));
                fs_connector_set_option(
                    'fs_conn_last_frontend_verify_result',
                    json_encode([
                        'total_media_references' => $stats['total_media_references'],
                        'files_service_media_urls' => $stats['files_service_media_urls'],
                        'local_fallback_urls' => $stats['local_fallback_urls'],
                        'legacy_files_view_urls' => $stats['legacy_files_view_urls'],
                        'canonical_media_format_ok' => $stats['canonical_media_format_ok'],
                        'timestamp' => date('Y-m-d H:i:s'),
                    ], JSON_UNESCAPED_SLASHES) ?: ''
                );
            }

            return $stats;
        }

        public static function referenceStats(): array
        {
            if (!Database::tableExists('pages') && !Database::tableExists('posts') && !Database::tableExists('media')) {
                return ['references_found' => 0, 'replaceable' => 0, 'unmapped' => 0];
            }

            // Dry scan without overwriting the stored preview batch.
            $scan = self::scanReferenceReplacements(false, 100000);
            $replaceableCount = 0;
            foreach (($scan['items'] ?? []) as $item) {
                if (!empty($item['safe']) && empty($item['will_skip'])) {
                    $replaceableCount += (int) ($item['replacement_count'] ?? 0);
                }
            }

            $allLocalReferences = self::countLocalMediaReferences();
            return [
                'references_found' => $allLocalReferences,
                'replaceable' => $replaceableCount,
                'unmapped' => max(0, $allLocalReferences - $replaceableCount),
            ];
        }

        private static function countLocalMediaReferences(): int
        {
            $count = 0;
            foreach (self::rewriteTargets() as $target) {
                if (!Database::tableExists($target['table'])) {
                    continue;
                }
                $rows = Database::select("SELECT * FROM `" . Database::prefix($target['table']) . "` ORDER BY id ASC");
                foreach ($rows as $row) {
                    foreach ($target['columns'] as $column) {
                        $value = (string) ($row[$column] ?? '');
                        if ($value === '') {
                            continue;
                        }
                        $domain = self::sourceCmsHostname();
                        $prefix = $domain !== '' ? '(?:https?://' . preg_quote($domain, '~') . '/)?' : '';
                        $count += preg_match_all('~(?<![A-Za-z0-9:/])' . $prefix . '/?(?:uploads/[^\\s"\'<>\\)]+|media/view/[A-Za-z0-9._-]+)~i', $value) ?: 0;
                    }
                }
            }
            return $count;
        }

        private static function referenceNeedles(array $map): array
        {
            $needles = [];
            $value = trim((string) ($map['local_url'] ?? ''));
            if ($value !== '' && !str_starts_with($value, 'data:')) {
                $host = parse_url($value, PHP_URL_HOST);
                $sourceHost = self::sourceCmsHostname();
                if ($host === null || $host === '' || ($sourceHost !== '' && strtolower((string) $host) === $sourceHost)) {
                    $needles[] = $value;
                    $path = parse_url($value, PHP_URL_PATH);
                    if (is_string($path) && $path !== '') {
                        $needles[] = $path;
                        $needles[] = ltrim($path, '/');
                    } elseif (str_starts_with($value, 'uploads/') || str_starts_with($value, 'media/view/')) {
                        $needles[] = '/' . $value;
                    }
                }
            }

            $localPath = (string) ($map['local_path'] ?? '');
            $uploadsRoot = defined('SOI_ROOT') ? realpath(SOI_ROOT . '/uploads') : false;
            $realPath = $localPath !== '' ? realpath($localPath) : false;
            if ($uploadsRoot && $realPath && str_starts_with($realPath, $uploadsRoot . DIRECTORY_SEPARATOR)) {
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($realPath, strlen($uploadsRoot) + 1));
                $needles[] = '/uploads/' . $relative;
                $needles[] = 'uploads/' . $relative;
                if (defined('SOI_HOME_URL')) {
                    $needles[] = rtrim(SOI_HOME_URL, '/') . '/uploads/' . $relative;
                }
            }

            if (!empty($map['local_url']) && str_contains((string) $map['local_url'], '/media/view/')) {
                $path = parse_url((string) $map['local_url'], PHP_URL_PATH);
                if (is_string($path) && $path !== '') {
                    $needles[] = $path;
                    $needles[] = ltrim($path, '/');
                }
            }

            $needles = array_values(array_unique(array_filter($needles, static function ($needle) {
                return is_string($needle) && self::isAllowedSourceNeedle($needle);
            })));

            return $needles;
        }

        private static function backupContentValue(string $table, int $rowId, string $column, string $original, int $count, string $batchToken): void
        {
            $hash = hash('sha256', $original);
            $existing = Database::selectOne(
                "SELECT id FROM `" . Database::prefix('files_service_content_backup') . "` WHERE table_name = ? AND row_id = ? AND column_name = ? AND original_value_hash = ? LIMIT 1",
                [$table, $rowId, $column, $hash]
            );

            if ($existing) {
                return;
            }

            Database::insert('files_service_content_backup', [
                'table_name' => $table,
                'row_id' => (string) $rowId,
                'column_name' => $column,
                'original_value_hash' => $hash,
                'original_value' => $original,
                'replacement_count' => (string) $count,
                'batch_token' => $batchToken,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        public static function latestBackupBatch(): string
        {
            $info = self::latestBackupBatchInfo();
            return (string) ($info['batch_token'] ?? '');
        }

        public static function latestBackupBatchInfo(): array
        {
            $empty = [
                'batch_token' => '',
                'replacement_count' => 0,
                'field_count' => 0,
                'tables_affected' => [],
                'created_at' => '',
                'rollback_available' => false,
                'rollback_status' => 'none',
            ];

            if (!self::backupTableExists()) {
                $empty['rollback_status'] = 'backup_table_missing';
                return $empty;
            }

            $latest = Database::selectOne("SELECT batch_token, created_at FROM `" . Database::prefix('files_service_content_backup') . "` ORDER BY id DESC LIMIT 1");
            $batchToken = trim((string) ($latest['batch_token'] ?? ''));
            if ($batchToken === '') {
                return $empty;
            }

            $rows = Database::select(
                "SELECT table_name, replacement_count, created_at FROM `" . Database::prefix('files_service_content_backup') . "` WHERE batch_token = ?",
                [$batchToken]
            );
            $tables = [];
            $replacementCount = 0;
            $createdAt = (string) ($latest['created_at'] ?? '');
            foreach ($rows as $row) {
                $tables[(string) ($row['table_name'] ?? '')] = true;
                $replacementCount += (int) ($row['replacement_count'] ?? 0);
                if ($createdAt === '' && !empty($row['created_at'])) {
                    $createdAt = (string) $row['created_at'];
                }
            }

            $optionBatch = function_exists('fs_connector_option')
                ? trim((string) fs_connector_option('fs_conn_last_rewrite_batch', ''))
                : '';
            $status = 'available';
            if ($optionBatch !== '' && $optionBatch !== $batchToken) {
                $status = 'available_latest_backup_differs_from_option';
            }

            return [
                'batch_token' => $batchToken,
                'replacement_count' => $replacementCount,
                'field_count' => count($rows),
                'tables_affected' => array_values(array_filter(array_keys($tables))),
                'created_at' => $createdAt,
                'rollback_available' => true,
                'rollback_status' => $status,
            ];
        }

        public static function rollbackBatch(string $batchToken): array
        {
            if (!self::backupTableExists()) {
                return [
                    'success' => false,
                    'error' => 'Content backup table missing.',
                    'restored' => 0,
                    'failed' => 0,
                    'batch_token' => '',
                ];
            }

            $batchToken = trim($batchToken);
            if ($batchToken === '') {
                return [
                    'success' => false,
                    'error' => 'No rollback batch token selected.',
                    'restored' => 0,
                    'failed' => 0,
                    'batch_token' => '',
                ];
            }

            $latest = self::latestBackupBatch();
            if ($latest !== '' && !hash_equals($latest, $batchToken)) {
                return [
                    'success' => false,
                    'error' => 'Rollback is restricted to the latest committed replacement batch only.',
                    'restored' => 0,
                    'failed' => 0,
                    'batch_token' => $batchToken,
                ];
            }

            $backups = Database::select(
                "SELECT * FROM `" . Database::prefix('files_service_content_backup') . "` WHERE batch_token = ? ORDER BY id DESC",
                [$batchToken]
            );
            if ($backups === []) {
                return [
                    'success' => false,
                    'error' => 'No backup rows found for that batch token.',
                    'restored' => 0,
                    'failed' => 0,
                    'batch_token' => $batchToken,
                ];
            }

            $restored = 0;
            $failed = 0;
            $errors = [];
            foreach ($backups as $backup) {
                $table = (string) ($backup['table_name'] ?? '');
                $column = (string) ($backup['column_name'] ?? '');
                $rowId = (int) ($backup['row_id'] ?? 0);
                if (!in_array($table, ['pages', 'posts', 'media'], true)
                    || !in_array($column, ['content', 'featured_image', 'url'], true)
                    || $rowId < 1
                ) {
                    $failed++;
                    $errors[] = "Skipped invalid backup target {$table}#{$rowId}.{$column}";
                    continue;
                }
                if (!Database::tableExists($table)) {
                    $failed++;
                    $errors[] = "Table missing: {$table}";
                    continue;
                }
                try {
                    Database::update($table, [$column => (string) ($backup['original_value'] ?? '')], 'id = ?', [$rowId]);
                    $restored++;
                } catch (Throwable $e) {
                    $failed++;
                    $errors[] = "Failed restoring {$table}#{$rowId}.{$column}";
                }
            }

            // Rollback does not delete local files or Files Service mappings.
            if (function_exists('fs_connector_set_option') && $restored > 0) {
                fs_connector_set_option('fs_conn_last_rollback_at', date('Y-m-d H:i:s'));
                fs_connector_set_option('fs_conn_last_rollback_batch', $batchToken);
            }

            return [
                'success' => $failed === 0 && $restored > 0,
                'restored' => $restored,
                'failed' => $failed,
                'batch_token' => $batchToken,
                'error' => $errors === [] ? '' : implode(' ', array_slice($errors, 0, 5)),
                'message' => "Rollback restored {$restored} field(s), failed {$failed}. Local files and Files Service mappings were not modified.",
            ];
        }

        public static function workflowStepStatus(): array
        {
            $lastResultRaw = function_exists('fs_connector_option')
                ? (string) fs_connector_option('fs_conn_last_migration_result', '')
                : '';
            $lastResult = $lastResultRaw !== '' ? json_decode($lastResultRaw, true) : null;
            if (!is_array($lastResult)) {
                $lastResult = [];
            }

            $preview = self::previewStatus();
            $backup = self::latestBackupBatchInfo();
            $verifyRaw = function_exists('fs_connector_option')
                ? (string) fs_connector_option('fs_conn_last_frontend_verify_result', '')
                : '';
            $verify = $verifyRaw !== '' ? json_decode($verifyRaw, true) : null;
            if (!is_array($verify)) {
                $verify = [];
            }

            $lastType = (string) ($lastResult['type'] ?? '');
            $lastAt = function_exists('fs_connector_option')
                ? (string) fs_connector_option('fs_conn_last_migration_run', '')
                : '';

            return [
                'dry_run' => [
                    'status' => $lastType === 'dry_run' ? 'completed' : ($lastAt !== '' ? 'idle' : 'not_run'),
                    'last_run' => $lastType === 'dry_run' ? $lastAt : '',
                    'count' => $lastType === 'dry_run' ? (int) ($lastResult['processed'] ?? 0) : null,
                    'dangerous' => false,
                ],
                'upload_map' => [
                    'status' => $lastType === 'upload_map_batch' ? (($lastResult['success'] ?? false) ? 'completed' : 'failed') : 'idle',
                    'last_run' => $lastType === 'upload_map_batch' ? $lastAt : '',
                    'count' => $lastType === 'upload_map_batch' ? (int) ($lastResult['uploaded'] ?? 0) : null,
                    'dangerous' => false,
                ],
                'preview' => [
                    'status' => $preview['exists'] ? ($preview['valid'] ? 'ready' : 'stale') : ($lastType === 'rewrite_preview' ? 'completed' : 'not_run'),
                    'last_run' => (string) ($preview['created_at'] ?: (function_exists('fs_connector_option') ? fs_connector_option('fs_conn_rewrite_preview_at', '') : '')),
                    'count' => (int) ($preview['replacement_count'] ?? 0),
                    'dangerous' => false,
                ],
                'commit' => [
                    'status' => $lastType === 'rewrite_commit' ? (($lastResult['success'] ?? false) ? 'completed' : 'failed') : 'idle',
                    'last_run' => function_exists('fs_connector_option') ? (string) fs_connector_option('fs_conn_last_rewrite_commit_at', '') : '',
                    'count' => function_exists('fs_connector_option') ? (int) fs_connector_option('fs_conn_last_rewrite_commit_count', '0') : 0,
                    'dangerous' => true,
                ],
                'verify' => [
                    'status' => $verify === [] ? 'not_run' : 'completed',
                    'last_run' => function_exists('fs_connector_option') ? (string) fs_connector_option('fs_conn_last_frontend_verify_at', '') : '',
                    'count' => (int) ($verify['total_media_references'] ?? 0),
                    'dangerous' => false,
                ],
                'rollback' => [
                    'status' => $backup['rollback_available'] ? $backup['rollback_status'] : 'none',
                    'last_run' => function_exists('fs_connector_option') ? (string) fs_connector_option('fs_conn_last_rollback_at', '') : '',
                    'count' => (int) ($backup['replacement_count'] ?? 0),
                    'dangerous' => true,
                    'batch_token' => (string) ($backup['batch_token'] ?? ''),
                    'tables_affected' => $backup['tables_affected'] ?? [],
                    'created_at' => (string) ($backup['created_at'] ?? ''),
                    'field_count' => (int) ($backup['field_count'] ?? 0),
                ],
            ];
        }
    }
}
