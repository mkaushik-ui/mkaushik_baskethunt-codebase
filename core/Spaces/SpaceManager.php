<?php
declare(strict_types=1);

namespace SOI\Core\Spaces;

/**
 * Knowledge Space Foundation & Navigation Manager.
 * Handles generic space types (general_docs, libraries, tech), section hierarchies,
 * and document assignment mappings.
 */
class SpaceManager
{
    public const TYPE_GENERAL_DOCS = 'general_docs';
    public const TYPE_LIBRARIES = 'libraries';
    public const TYPE_TECH = 'tech';

    /**
     * Get predefined Knowledge Spaces.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSpaces(): array
    {
        return [
            'files-service' => [
                'id' => 'files-service',
                'slug' => 'files-service',
                'title' => 'Files Service Docs',
                'description' => 'Public post-powered documentation for CMS & developer integration.',
                'type' => self::TYPE_GENERAL_DOCS,
                'icon' => 'FS',
                'badge' => 'Developer Docs',
                'sections' => [
                    [
                        'id' => 'getting-started',
                        'title' => 'Getting Started',
                        'items' => [
                            ['id' => 'overview', 'title' => 'Overview', 'slug' => 'overview', 'type' => 'post'],
                            ['id' => 'core-concepts', 'title' => 'Core Concepts', 'slug' => 'core-concepts', 'type' => 'post'],
                            ['id' => 'architecture', 'title' => 'Architecture', 'slug' => 'architecture', 'type' => 'post'],
                        ]
                    ],
                    [
                        'id' => 'app-registration',
                        'title' => 'App Registration',
                        'items' => [
                            ['id' => 'authentication', 'title' => 'Authentication', 'slug' => 'authentication', 'type' => 'post'],
                            ['id' => 'app-registration', 'title' => 'App Registration', 'slug' => 'app-registration', 'type' => 'post', 'active' => true],
                            ['id' => 'connection-requests', 'title' => 'Connection Requests', 'slug' => 'connection-requests', 'type' => 'post'],
                        ]
                    ],
                    [
                        'id' => 'api-reference',
                        'title' => 'API Reference',
                        'items' => [
                            ['id' => 'upload-api', 'title' => 'Upload API', 'slug' => 'upload-api', 'type' => 'post'],
                            ['id' => 'hmac-signing', 'title' => 'HMAC Signing', 'slug' => 'hmac-signing', 'type' => 'post'],
                            ['id' => 'view-download', 'title' => 'View & Download', 'slug' => 'view-download', 'type' => 'post'],
                        ]
                    ],
                ]
            ],
            'libraries' => [
                'id' => 'libraries',
                'slug' => 'libraries',
                'title' => 'SDK & Component Libraries',
                'description' => 'Client libraries, UI kits, and API wrappers across languages.',
                'type' => self::TYPE_LIBRARIES,
                'icon' => 'LIB',
                'badge' => 'Libraries',
                'sections' => []
            ],
            'tech' => [
                'id' => 'tech',
                'slug' => 'tech',
                'title' => 'Technical Architecture',
                'description' => 'System design, database schemas, and infrastructure specifications.',
                'type' => self::TYPE_TECH,
                'icon' => 'SYS',
                'badge' => 'Tech',
                'sections' => []
            ]
        ];
    }

    /**
     * Get a specific Space configuration by slug.
     */
    public static function getSpace(string $slug): ?array
    {
        $spaces = self::getSpaces();
        return $spaces[$slug] ?? null;
    }
}
