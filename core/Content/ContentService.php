<?php
declare(strict_types=1);

namespace SOI\Core\Content;

use SOI\Core\Cache;

/**
 * Unified application content service.
 * Coordinates document validation, optimistic concurrency control,
 * save/autosave operations, reusable components, and persistence via ContentStore.
 */
final class ContentService
{
    /**
     * Save or autosave a document (structured or legacy).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function saveDocument(array $payload, bool $autosave = false): array
    {
        $entity = (string) ($payload['entity'] ?? 'page');
        $id = (int) ($payload['id'] ?? 0);
        ContentStore::assertEntityAllowed($entity);

        $mode = (string) ($payload['mode'] ?? 'structured');
        $fields = [
            'title' => (string) ($payload['title'] ?? ''),
            'slug' => (string) ($payload['slug'] ?? ''),
            'status' => (string) ($payload['status'] ?? 'draft'),
            'space_id' => isset($payload['space_id']) ? (int) $payload['space_id'] : 0,
            'section_id' => isset($payload['section_id']) ? (int) $payload['section_id'] : 0,
            'doc_version' => (string) ($payload['doc_version'] ?? 'v1.0'),
            'meta_title' => (string) ($payload['meta_title'] ?? ''),
            'meta_desc' => (string) ($payload['meta_desc'] ?? ''),
            'excerpt' => (string) ($payload['excerpt'] ?? ''),
            'categories' => $payload['categories'] ?? [],
            'expected_updated_at' => (string) ($payload['expected_updated_at'] ?? ''),
        ];

        if ($autosave && $id > 0) {
            $existing = ContentStore::find($entity, $id);
            if ($existing) {
                if ($fields['title'] === '') {
                    $fields['title'] = (string) ($existing['title'] ?? '');
                }
                if ($fields['status'] === 'draft' && isset($existing['status'])) {
                    $fields['status'] = (string) $existing['status'];
                }
            }
        }

        if ($mode === 'legacy') {
            $result = ContentStore::saveLegacy($entity, $id, $fields, (string) ($payload['legacy_html'] ?? ''));
        } else {
            $document = Document::parse($payload['document'] ?? null);
            $result = ContentStore::save($entity, $id, $fields, $document);
        }

        if (class_exists(Cache::class)) {
            Cache::purgeAll();
        }

        $record = $result['record'];
        return [
            'ok' => true,
            'autosave' => $autosave,
            'id' => $result['id'],
            'slug' => $record['slug'] ?? '',
            'status' => $record['status'] ?? 'draft',
            'space_id' => (int) ($record['space_id'] ?? 0),
            'section_id' => (int) ($record['section_id'] ?? 0),
            'doc_version' => (string) ($record['doc_version'] ?? 'v1.0'),
            'updated_at' => $record['updated_at'] ?? '',
            'editor_format' => $record['editor_format'] ?? EditorSchema::FORMAT_STRUCTURED,
            'view_url' => self::viewUrl((string) ($record['slug'] ?? '')),
        ];
    }

    /**
     * Render preview payload without persisting to storage.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function previewDocument(array $payload): array
    {
        $entity = (string) ($payload['entity'] ?? 'page');
        ContentStore::assertEntityAllowed($entity);

        $document = Document::parse($payload['document'] ?? null);
        $errors = Document::validate($document);
        if ($errors !== []) {
            throw new DocumentException(implode(' ', $errors));
        }

        $html = DocumentRenderer::render($document, true);
        $headings = DocumentRenderer::extractHeadings($document);

        return [
            'ok' => true,
            'html' => $html,
            'headings' => $headings,
        ];
    }

    private static function viewUrl(string $slug): string
    {
        if ($slug === '') {
            return '';
        }
        $home = defined('SOI_HOME_URL') ? SOI_HOME_URL : '/';
        return rtrim($home, '/') . '/' . ltrim($slug, '/');
    }
}
