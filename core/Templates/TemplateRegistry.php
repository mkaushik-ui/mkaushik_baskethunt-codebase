<?php
declare(strict_types=1);

namespace SOI\Core\Templates;

use InvalidArgumentException;
use SOI\Core\Content\Document;
use SOI\Core\Content\Html;

/**
 * Task A3-T19: Starter Document Templates
 * 
 * Predefined starter document template registry providing pre-formatted document
 * structures (Blank, Procedure, API Reference, Policy, FAQ, Release Notes).
 * Applying any template creates an independent document canvas with fresh stable unique IDs.
 * 
 * @author Saurabh
 */
class TemplateRegistry
{
    /**
     * In-memory custom registered templates.
     * 
     * @var array<string, array<string, mixed>>
     */
    private static array $customTemplates = [];

    /**
     * Retrieve all available starter document templates.
     *
     * @return list<array{id: string, name: string, title: string, description: string, category: string, icon?: string, document: array{schemaVersion: int, blocks: list<array<string, mixed>>}}>
     */
    public static function all(): array
    {
        $templates = self::defaults();
        foreach (self::$customTemplates as $id => $template) {
            $templates[$id] = $template;
        }
        return array_values($templates);
    }

    /**
     * Retrieve a raw template definition by ID.
     *
     * @param string $id
     * @return array<string, mixed>|null
     */
    public static function get(string $id): ?array
    {
        $id = trim($id);
        if (isset(self::$customTemplates[$id])) {
            return self::$customTemplates[$id];
        }

        $defaults = self::defaults();
        return $defaults[$id] ?? null;
    }

    /**
     * Check if a template exists by ID.
     *
     * @param string $id
     * @return bool
     */
    public static function has(string $id): bool
    {
        return self::get($id) !== null;
    }

    /**
     * Register a new or custom starter template definition.
     *
     * @param array{id: string, name?: string, title?: string, description?: string, category?: string, icon?: string, document: array{schemaVersion?: int, blocks: list<array<string, mixed>>}} $template
     * @throws InvalidArgumentException
     */
    public static function register(array $template): void
    {
        $id = trim((string) ($template['id'] ?? ''));
        if ($id === '') {
            throw new InvalidArgumentException('Template must specify a non-empty unique string ID.');
        }

        if (!isset($template['document']) || !is_array($template['document']) || !isset($template['document']['blocks'])) {
            throw new InvalidArgumentException('Template must contain a valid document structure with blocks.');
        }

        $name = trim((string) ($template['name'] ?? $template['title'] ?? $id));
        $description = trim((string) ($template['description'] ?? ''));
        $category = trim((string) ($template['category'] ?? 'General'));
        $icon = trim((string) ($template['icon'] ?? '📄'));

        self::$customTemplates[$id] = [
            'id' => $id,
            'name' => $name,
            'title' => $name,
            'description' => $description,
            'category' => $category,
            'icon' => $icon,
            'document' => $template['document'],
        ];
    }

    /**
     * Apply a starter template to generate a fresh document canvas structure.
     * Deep-copies all blocks and generates fresh, stable unique IDs for all blocks.
     *
     * @param string $id Template identifier (blank, procedure, api_reference, policy, faq, release_notes)
     * @param array<string, mixed> $overrides Optional custom data overrides
     * @return array{schemaVersion: int, blocks: list<array{id: string, type: string, data: array<string, mixed>}>}
     * @throws InvalidArgumentException
     */
    public static function apply(string $id, array $overrides = []): array
    {
        $template = self::get($id);
        if (!$template) {
            throw new InvalidArgumentException("Template with ID '$id' does not exist.");
        }

        $docData = $template['document'] ?? [];
        $schemaVersion = (int) ($docData['schemaVersion'] ?? 1);
        $rawBlocks = is_array($docData['blocks'] ?? null) ? $docData['blocks'] : [];

        $freshBlocks = self::freshBlocks($rawBlocks);

        return [
            'schemaVersion' => $schemaVersion,
            'blocks' => $freshBlocks,
        ];
    }

    /**
     * Create a complete initialized document record from a starter template.
     *
     * @param string $id Template identifier
     * @param string $title Optional document title override
     * @return array{title: string, schemaVersion: int, blocks: list<array{id: string, type: string, data: array<string, mixed>}>}
     */
    public static function createDocument(string $id, string $title = ''): array
    {
        $template = self::get($id);
        if (!$template) {
            throw new InvalidArgumentException("Template with ID '$id' does not exist.");
        }

        $docTitle = trim($title) !== '' ? trim($title) : ($template['title'] ?? $template['name'] ?? 'Untitled Document');
        $document = self::apply($id);

        return [
            'title' => $docTitle,
            'schemaVersion' => $document['schemaVersion'],
            'blocks' => $document['blocks'],
        ];
    }

    /**
     * Get fresh independent block list with newly generated IDs for a template.
     *
     * @param string $id
     * @return list<array{id: string, type: string, data: array<string, mixed>}>
     */
    public static function getBlocks(string $id): array
    {
        $doc = self::apply($id);
        return $doc['blocks'];
    }

    /**
     * Deep-copy blocks and assign fresh, stable unique IDs to every block.
     *
     * @param list<array<string, mixed>> $blocks
     * @return list<array{id: string, type: string, data: array<string, mixed>}>
     */
    public static function freshBlocks(array $blocks): array
    {
        $fresh = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $type = (string) ($block['type'] ?? 'paragraph');
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];

            $deepCopiedData = self::deepCopyArray($data);

            $fresh[] = [
                'id' => self::newId(),
                'type' => $type,
                'data' => $deepCopiedData,
            ];
        }
        return $fresh;
    }

    /**
     * Generate a fresh, stable unique block ID.
     *
     * @return string
     */
    public static function newId(): string
    {
        if (class_exists(Document::class) && method_exists(Document::class, 'newId')) {
            return Document::newId();
        }

        try {
            return 'blk_' . bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            return 'blk_' . substr(hash('sha256', uniqid((string) mt_rand(), true)), 0, 16);
        }
    }

    /**
     * Recursive deep-copy of array structures.
     *
     * @param array<string, mixed> $array
     * @return array<string, mixed>
     */
    private static function deepCopyArray(array $array): array
    {
        $copy = [];
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $copy[$key] = self::deepCopyArray($value);
            } elseif (is_object($value)) {
                $copy[$key] = clone $value;
            } else {
                $copy[$key] = $value;
            }
        }
        return $copy;
    }

    /**
     * Default pre-defined starter document templates.
     * 1. Blank
     * 2. Procedure
     * 3. API Reference
     * 4. Policy
     * 5. FAQ
     * 6. Release Notes
     *
     * @return array<string, array<string, mixed>>
     */
    private static function defaults(): array
    {
        return [
            // 1. Blank Template
            'blank' => [
                'id' => 'blank',
                'name' => 'Blank Document',
                'title' => 'Blank Document',
                'description' => 'Start with an empty document canvas.',
                'icon' => '📄',
                'category' => 'General',
                'document' => [
                    'schemaVersion' => 1,
                    'blocks' => [
                        ['type' => 'paragraph', 'data' => ['text' => '']],
                    ],
                ],
            ],

            // 2. Procedure Template
            'procedure' => [
                'id' => 'procedure',
                'name' => 'Step-by-Step Procedure',
                'title' => 'Step-by-Step Procedure',
                'description' => 'Standard Operating Procedure (SOP) with overview, prerequisites, steps, and expected results.',
                'icon' => '🔢',
                'category' => 'Procedures',
                'document' => [
                    'schemaVersion' => 1,
                    'blocks' => [
                        ['type' => 'heading', 'data' => ['text' => 'Standard Operating Procedure', 'level' => 1]],
                        ['type' => 'heading', 'data' => ['text' => 'Overview & Purpose', 'level' => 2]],
                        ['type' => 'paragraph', 'data' => ['text' => 'This procedure defines the standardized, authorized steps required to execute this operational workflow.']],
                        ['type' => 'heading', 'data' => ['text' => 'Prerequisites', 'level' => 2]],
                        ['type' => 'callout', 'data' => [
                            'tone' => 'tip',
                            'title' => 'Required Permissions & Tooling',
                            'text' => 'Ensure you have authenticated credentials and appropriate environment permissions before initiating execution.',
                        ]],
                        ['type' => 'heading', 'data' => ['text' => 'Procedure Steps', 'level' => 2]],
                        ['type' => 'steps', 'data' => [
                            'items' => [
                                ['title' => '1. Environment Validation', 'content' => 'Verify target host health and backup current configuration parameters.'],
                                ['title' => '2. Configuration Deployment', 'content' => 'Apply updated settings via the secure deployment console.'],
                                ['title' => '3. Post-Execution Audit', 'content' => 'Execute validation test suite and verify system telemetry metrics.'],
                            ],
                        ]],
                        ['type' => 'heading', 'data' => ['text' => 'Expected Result & Sign-Off', 'level' => 2]],
                        ['type' => 'callout', 'data' => [
                            'tone' => 'info',
                            'title' => 'Success Criteria',
                            'text' => 'System status must report 100% operational without error logs. Document completion timestamp in operational logs.',
                        ]],
                    ],
                ],
            ],

            // 3. API Reference Template
            'api_reference' => [
                'id' => 'api_reference',
                'name' => 'API Reference Specification',
                'title' => 'API Reference Specification',
                'description' => 'Comprehensive technical API reference with authentication, endpoints, parameters, and code examples.',
                'icon' => '⚡',
                'category' => 'Technical',
                'document' => [
                    'schemaVersion' => 1,
                    'blocks' => [
                        ['type' => 'heading', 'data' => ['text' => 'API Reference Specification', 'level' => 1]],
                        ['type' => 'statusBadge', 'data' => ['status' => 'stable', 'label' => 'REST API v1 • Stable']],
                        ['type' => 'heading', 'data' => ['text' => 'Overview', 'level' => 2]],
                        ['type' => 'paragraph', 'data' => ['text' => 'Comprehensive technical specification for integrating and interacting with the REST API endpoints.']],
                        ['type' => 'heading', 'data' => ['text' => 'Authentication', 'level' => 2]],
                        ['type' => 'callout', 'data' => [
                            'tone' => 'note',
                            'title' => 'Bearer Token Authentication',
                            'text' => 'All requests require a valid JWT passed in the HTTP Authorization header: `Authorization: Bearer <API_TOKEN>`.',
                        ]],
                        ['type' => 'heading', 'data' => ['text' => 'Endpoint Specification', 'level' => 2]],
                        ['type' => 'apiEndpoint', 'data' => [
                            'method' => 'POST',
                            'endpoint' => '/api/v1/documents',
                            'title' => 'Create New Document',
                            'description' => 'Creates a new structured document with specified blocks payload.',
                            'auth' => 'Bearer Token',
                            'parameters' => [
                                ['name' => 'title', 'type' => 'string', 'required' => true, 'description' => 'Document headline title'],
                                ['name' => 'category', 'type' => 'string', 'required' => false, 'description' => 'Organizational category'],
                                ['name' => 'blocks', 'type' => 'array', 'required' => true, 'description' => 'Structured blocks payload array'],
                            ],
                            'requestBody' => "{\n  \"title\": \"New Guide\",\n  \"category\": \"Technical\",\n  \"blocks\": []\n}",
                            'responseBody' => "{\n  \"ok\": true,\n  \"id\": 108,\n  \"status\": \"created\"\n}",
                        ]],
                        ['type' => 'heading', 'data' => ['text' => 'Request Parameters & Response Codes', 'level' => 2]],
                        ['type' => 'table', 'data' => [
                            'withHeadings' => true,
                            'content' => [
                                ['HTTP Status', 'Code', 'Description'],
                                ['201 Created', 'SUCCESS', 'Document was successfully created.'],
                                ['400 Bad Request', 'INVALID_PAYLOAD', 'Payload validation error.'],
                                ['401 Unauthorized', 'AUTH_REQUIRED', 'Missing or invalid authentication token.'],
                            ],
                        ]],
                        ['type' => 'heading', 'data' => ['text' => 'Code Examples', 'level' => 2]],
                        ['type' => 'codeGroup', 'data' => [
                            'items' => [
                                ['label' => 'cURL', 'language' => 'bash', 'code' => "curl -X POST https://api.soi.co.in/v1/documents \\\n  -H \"Authorization: Bearer \$TOKEN\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '{\"title\":\"New Guide\",\"blocks\":[]}'\n", 'caption' => ''],
                                ['label' => 'PHP', 'language' => 'php', 'code' => "\$response = \$client->post('/v1/documents', [\n  'headers' => ['Authorization' => \"Bearer \$token\"],\n  'json' => ['title' => 'New Guide', 'blocks' => []]\n]);\n", 'caption' => ''],
                                ['label' => 'JavaScript', 'language' => 'javascript', 'code' => "const response = await fetch('https://api.soi.co.in/v1/documents', {\n  method: 'POST',\n  headers: { 'Authorization': `Bearer \${token}`, 'Content-Type': 'application/json' },\n  body: JSON.stringify({ title: 'New Guide', blocks: [] })\n});\n", 'caption' => ''],
                            ],
                        ]],
                    ],
                ],
            ],

            // 4. Policy Template
            'policy' => [
                'id' => 'policy',
                'name' => 'Corporate Policy & Governance',
                'title' => 'Corporate Policy & Governance',
                'description' => 'Formal enterprise policy document with purpose, scope, responsibilities, exceptions, and review timeline.',
                'icon' => '⚖️',
                'category' => 'Governance',
                'document' => [
                    'schemaVersion' => 1,
                    'blocks' => [
                        ['type' => 'heading', 'data' => ['text' => 'Corporate Information Security Policy', 'level' => 1]],
                        ['type' => 'heading', 'data' => ['text' => '1. Purpose', 'level' => 2]],
                        ['type' => 'paragraph', 'data' => ['text' => 'The purpose of this policy is to establish clear organizational standards for safeguarding enterprise digital assets, customer data, and computing infrastructure.']],
                        ['type' => 'heading', 'data' => ['text' => '2. Scope', 'level' => 2]],
                        ['type' => 'paragraph', 'data' => ['text' => 'This policy applies unconditionally to all employees, contractors, consultants, and third-party vendors accessing organizational networks or systems.']],
                        ['type' => 'heading', 'data' => ['text' => '3. Policy Requirements & Directives', 'level' => 2]],
                        ['type' => 'list', 'data' => [
                            'style' => 'unordered',
                            'items' => [
                                ['text' => 'Multi-factor authentication (MFA) must be enforced across all corporate accounts and production consoles.'],
                                ['text' => 'Production database access requires encrypted VPN connection and role-based access control (RBAC).'],
                                ['text' => 'All employee workstations must have automated full-disk encryption and endpoint protection enabled.'],
                            ],
                        ]],
                        ['type' => 'heading', 'data' => ['text' => '4. Roles & Responsibilities', 'level' => 2]],
                        ['type' => 'keyValues', 'data' => [
                            'title' => 'Governance Matrix',
                            'items' => [
                                ['key' => 'Policy Owner', 'value' => 'Chief Information Security Officer (CISO)'],
                                ['key' => 'Enforcement Entity', 'value' => 'Security Operations & Compliance Team'],
                                ['key' => 'Auditing Schedule', 'value' => 'Quarterly Automated Audit'],
                            ],
                        ]],
                        ['type' => 'heading', 'data' => ['text' => '5. Exceptions & Non-Compliance', 'level' => 2]],
                        ['type' => 'callout', 'data' => [
                            'tone' => 'warning',
                            'title' => 'Strict Non-Compliance Policy',
                            'text' => 'Violations of this policy may result in immediate suspension of credentials and disciplinary review.',
                        ]],
                        ['type' => 'heading', 'data' => ['text' => '6. Review & Revision Schedule', 'level' => 2]],
                        ['type' => 'paragraph', 'data' => ['text' => 'This document is reviewed and re-authorized annually. Next scheduled review date: December 31, 2026.']],
                    ],
                ],
            ],

            // 5. FAQ Template
            'faq' => [
                'id' => 'faq',
                'name' => 'Frequently Asked Questions (FAQ)',
                'title' => 'Frequently Asked Questions (FAQ)',
                'description' => 'Structured FAQ knowledge document generating Schema.org FAQPage microdata.',
                'icon' => '❓',
                'category' => 'Support',
                'document' => [
                    'schemaVersion' => 1,
                    'blocks' => [
                        ['type' => 'heading', 'data' => ['text' => 'Frequently Asked Questions', 'level' => 1]],
                        ['type' => 'paragraph', 'data' => ['text' => 'Find quick answers to the most common questions regarding platform features, access, and support.']],
                        ['type' => 'faq', 'data' => [
                            'items' => [
                                ['question' => 'How do I provision access for new team members?', 'answer' => 'Administrators can invite team members via the Admin Console under Settings > User Management.'],
                                ['question' => 'What browsers and client environments are supported?', 'answer' => 'All modern Chromium-based browsers, Mozilla Firefox, Apple Safari, and Edge are fully supported.'],
                                ['question' => 'How are reusable blocks synchronized across documents?', 'answer' => 'Reusable blocks store a single live reference ID. Modifying the master block instantly updates all referencing pages in real time.'],
                            ],
                        ]],
                        ['type' => 'callout', 'data' => [
                            'tone' => 'note',
                            'title' => 'Need Additional Assistance?',
                            'text' => 'If your inquiry is not addressed above, reach out directly to the Help Desk via support@soi.co.in.',
                        ]],
                    ],
                ],
            ],

            // 6. Release Notes Template
            'release_notes' => [
                'id' => 'release_notes',
                'name' => 'Product Release Notes',
                'title' => 'Product Release Notes',
                'description' => 'Structured release notes announcement covering new features, improvements, fixes, and known issues.',
                'icon' => '🚀',
                'category' => 'Announcements',
                'document' => [
                    'schemaVersion' => 1,
                    'blocks' => [
                        ['type' => 'heading', 'data' => ['text' => 'Release Notes — Version 1.1.0', 'level' => 1]],
                        ['type' => 'statusBadge', 'data' => ['status' => 'stable', 'label' => 'v1.1.0 • General Availability']],
                        ['type' => 'paragraph', 'data' => ['text' => 'We are excited to announce the general availability of Version 1.1.0, featuring major authoring enhancements, pattern libraries, and live synced reusable blocks.']],
                        ['type' => 'heading', 'data' => ['text' => '✨ New Features & Capabilities', 'level' => 2]],
                        ['type' => 'list', 'data' => [
                            'style' => 'checklist',
                            'items' => [
                                ['text' => 'Pre-built Pattern Registry with deep-copy and stable ID generation.', 'checked' => true],
                                ['text' => 'Starter Document Templates for instant structured canvas creation.', 'checked' => true],
                                ['text' => 'Live Synchronized Reusable Blocks Subsystem.', 'checked' => true],
                                ['text' => 'Accessible WAI-ARIA Tabs Block and Schema.org FAQ Block.', 'checked' => true],
                            ],
                        ]],
                        ['type' => 'heading', 'data' => ['text' => '⚡ Performance & Improvements', 'level' => 2]],
                        ['type' => 'list', 'data' => [
                            'style' => 'unordered',
                            'items' => [
                                ['text' => 'Zero Editor.js dependency for public client rendering runtime.'],
                                ['text' => 'Optimized memory consumption during recursive document parsing.'],
                                ['text' => 'Strict type safety and comprehensive XSS sanitization enforcement.'],
                            ],
                        ]],
                        ['type' => 'heading', 'data' => ['text' => '🐛 Bug Fixes', 'level' => 2]],
                        ['type' => 'list', 'data' => [
                            'style' => 'unordered',
                            'items' => [
                                ['text' => 'Resolved XSS sanitization leak when rendering unsanitized payload items.'],
                                ['text' => 'Fixed keyboard focus trap when navigating multi-group tablists.'],
                            ],
                        ]],
                        ['type' => 'heading', 'data' => ['text' => '⚠️ Known Issues & Workarounds', 'level' => 2]],
                        ['type' => 'callout', 'data' => [
                            'tone' => 'info',
                            'title' => 'Browser Compatibility Note',
                            'text' => 'Ensure JavaScript is enabled in browser client to support live client-side tab switching.',
                        ]],
                    ],
                ],
            ],
        ];
    }
}
