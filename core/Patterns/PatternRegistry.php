<?php
declare(strict_types=1);

namespace SOI\Core\Patterns;

use InvalidArgumentException;
use SOI\Core\Content\Document;
use SOI\Core\Content\Html;

/**
 * Task A3-T18: Pre-Built Pattern System
 * 
 * Pre-built multi-block pattern library allowing users to insert structured
 * templates into documents. Every inserted pattern produces an independent
 * deep copy with fresh, stable unique block IDs.
 * 
 * @author Saurabh
 */
class PatternRegistry
{
    /**
     * In-memory custom registered patterns.
     * 
     * @var array<string, array<string, mixed>>
     */
    private static array $customPatterns = [];

    /**
     * Retrieve all available built-in and registered patterns.
     *
     * @return list<array{id: string, name: string, title: string, description: string, category: string, icon?: string, blocks: list<array<string, mixed>>}>
     */
    public static function all(): array
    {
        $patterns = self::defaults();
        foreach (self::$customPatterns as $id => $pattern) {
            $patterns[$id] = $pattern;
        }
        return array_values($patterns);
    }

    /**
     * Retrieve raw pattern definition by ID.
     *
     * @param string $id
     * @return array<string, mixed>|null
     */
    public static function get(string $id): ?array
    {
        $id = trim($id);
        if (isset(self::$customPatterns[$id])) {
            return self::$customPatterns[$id];
        }

        $defaults = self::defaults();
        return $defaults[$id] ?? null;
    }

    /**
     * Check if a pattern exists by ID.
     *
     * @param string $id
     * @return bool
     */
    public static function has(string $id): bool
    {
        return self::get($id) !== null;
    }

    /**
     * Register a new or custom pattern definition.
     *
     * @param array{id: string, name?: string, title?: string, description?: string, category?: string, icon?: string, blocks: list<array<string, mixed>>} $pattern
     * @throws InvalidArgumentException
     */
    public static function register(array $pattern): void
    {
        $id = trim((string) ($pattern['id'] ?? ''));
        if ($id === '') {
            throw new InvalidArgumentException('Pattern must specify a non-empty unique string ID.');
        }

        if (!isset($pattern['blocks']) || !is_array($pattern['blocks'])) {
            throw new InvalidArgumentException('Pattern must specify a valid blocks array.');
        }

        $name = trim((string) ($pattern['name'] ?? $pattern['title'] ?? $id));
        $description = trim((string) ($pattern['description'] ?? ''));
        $category = trim((string) ($pattern['category'] ?? 'General'));
        $icon = trim((string) ($pattern['icon'] ?? '📋'));

        self::$customPatterns[$id] = [
            'id' => $id,
            'name' => $name,
            'title' => $name,
            'description' => $description,
            'category' => $category,
            'icon' => $icon,
            'blocks' => $pattern['blocks'],
        ];
    }

    /**
     * Instantiate a pattern for insertion into a document.
     * Deep-copies all blocks and generates fresh, stable unique IDs for every block.
     *
     * @param string $id
     * @return array{id: string, name: string, title: string, description: string, category: string, blocks: list<array{id: string, type: string, data: array<string, mixed>}>}
     * @throws InvalidArgumentException
     */
    public static function instantiate(string $id): array
    {
        $pattern = self::get($id);
        if (!$pattern) {
            throw new InvalidArgumentException("Pattern with ID '$id' does not exist.");
        }

        $freshBlocks = self::freshBlocks($pattern['blocks'] ?? []);

        return [
            'id' => $pattern['id'],
            'name' => $pattern['name'] ?? $pattern['title'] ?? $pattern['id'],
            'title' => $pattern['title'] ?? $pattern['name'] ?? $pattern['id'],
            'description' => $pattern['description'] ?? '',
            'category' => $pattern['category'] ?? 'General',
            'icon' => $pattern['icon'] ?? '📋',
            'blocks' => $freshBlocks,
        ];
    }

    /**
     * Get fresh independent block list with newly generated IDs for document insertion.
     *
     * @param string $id
     * @return list<array{id: string, type: string, data: array<string, mixed>}>
     * @throws InvalidArgumentException
     */
    public static function getBlocks(string $id): array
    {
        $instance = self::instantiate($id);
        return $instance['blocks'];
    }

    /**
     * Insert fresh pattern blocks into a document structure at a specified position.
     *
     * @param string $id Pattern ID
     * @param array{schemaVersion?: int, blocks?: list<array<string, mixed>>} $document Document array
     * @param int $position Insertion index (default: -1 appends to the end)
     * @return array{schemaVersion: int, blocks: list<array<string, mixed>>}
     */
    public static function insertIntoDocument(string $id, array $document, int $position = -1): array
    {
        $schemaVersion = (int) ($document['schemaVersion'] ?? 1);
        $existingBlocks = is_array($document['blocks'] ?? null) ? array_values($document['blocks']) : [];
        $freshBlocks = self::getBlocks($id);

        if ($position < 0 || $position >= count($existingBlocks)) {
            $merged = array_merge($existingBlocks, $freshBlocks);
        } else {
            $merged = array_merge(
                array_slice($existingBlocks, 0, $position),
                $freshBlocks,
                array_slice($existingBlocks, $position)
            );
        }

        return [
            'schemaVersion' => $schemaVersion,
            'blocks' => $merged,
        ];
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

            // Perform deep copy on data array to prevent shared references
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
     * Default pre-built pattern definitions.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function defaults(): array
    {
        return [
            'two_column_comparison' => [
                'id' => 'two_column_comparison',
                'name' => 'Two-Column Comparison',
                'title' => 'Two-Column Comparison',
                'description' => 'Side-by-side comparison layout for architecture or product options.',
                'category' => 'Layout',
                'icon' => '▥',
                'blocks' => [
                    [
                        'type' => 'heading',
                        'data' => ['text' => 'Architectural Comparison', 'level' => 2],
                    ],
                    [
                        'type' => 'paragraph',
                        'data' => ['text' => 'Evaluate key differences and tradeoffs between the two architecture approaches below.'],
                    ],
                    [
                        'type' => 'columns',
                        'data' => [
                            'layout' => '50-50',
                            'columns' => [
                                ['content' => "<strong>Option A: Managed Cloud Service</strong>\n<p>Automated backups, managed SLA, instant horizontal auto-scaling.</p>"],
                                ['content' => "<strong>Option B: Self-Hosted Cluster</strong>\n<p>Full low-level control, zero vendor lock-in, customized kernel tuning.</p>"],
                            ],
                        ],
                    ],
                ],
            ],
            'api_quickstart' => [
                'id' => 'api_quickstart',
                'name' => 'API Quickstart',
                'title' => 'API Quickstart',
                'description' => 'Quickstart guide with API credentials notice and multi-language code snippets.',
                'category' => 'Technical',
                'icon' => '{ }',
                'blocks' => [
                    [
                        'type' => 'heading',
                        'data' => ['text' => 'Quickstart API Integration', 'level' => 2],
                    ],
                    [
                        'type' => 'callout',
                        'data' => [
                            'tone' => 'info',
                            'title' => 'API Authentication',
                            'text' => 'Obtain your Bearer API token from the Developer Settings dashboard before making requests.',
                        ],
                    ],
                    [
                        'type' => 'paragraph',
                        'data' => ['text' => 'Send your first authenticated request to verify connection: '],
                    ],
                    [
                        'type' => 'codeGroup',
                        'data' => [
                            'items' => [
                                ['label' => 'cURL', 'language' => 'bash', 'code' => "curl -X GET https://api.soi.co.in/v1/health \\\n  -H \"Authorization: Bearer \$API_KEY\"\n", 'caption' => ''],
                                ['label' => 'PHP', 'language' => 'php', 'code' => "\$res = \$client->get('/v1/health', [\n  'headers' => ['Authorization' => \"Bearer \$apiKey\"]\n]);\n", 'caption' => ''],
                                ['label' => 'JavaScript', 'language' => 'javascript', 'code' => "const res = await fetch('https://api.soi.co.in/v1/health', {\n  headers: { 'Authorization': `Bearer \${apiKey}` }\n});\n", 'caption' => ''],
                            ],
                        ],
                    ],
                ],
            ],
            'procedure_steps' => [
                'id' => 'procedure_steps',
                'name' => 'Standard Procedure Workflow',
                'title' => 'Standard Procedure Workflow',
                'description' => 'Structured 3-step action workflow with timeline connectors.',
                'category' => 'Procedures',
                'icon' => '1.',
                'blocks' => [
                    [
                        'type' => 'heading',
                        'data' => ['text' => 'Execution Workflow', 'level' => 2],
                    ],
                    [
                        'type' => 'steps',
                        'data' => [
                            'items' => [
                                ['title' => 'Step 1: Preparation', 'content' => 'Review the environment checklist and verify dependencies.'],
                                ['title' => 'Step 2: Deployment', 'content' => 'Execute release deployment pipeline on staging server.'],
                                ['title' => 'Step 3: Verification', 'content' => 'Run smoke tests and monitor application latency logs.'],
                            ],
                        ],
                    ],
                    [
                        'type' => 'callout',
                        'data' => [
                            'tone' => 'tip',
                            'title' => 'Verification Check',
                            'text' => 'Ensure all health check probes return HTTP 200 OK before signing off.',
                        ],
                    ],
                ],
            ],
            'callout_warning_box' => [
                'id' => 'callout_warning_box',
                'name' => 'Security & Compliance Alert',
                'title' => 'Security & Compliance Alert',
                'description' => 'Prominent alert warning callout box with security guidelines.',
                'category' => 'Notices',
                'icon' => '⚠️',
                'blocks' => [
                    [
                        'type' => 'callout',
                        'data' => [
                            'tone' => 'warning',
                            'title' => 'Security & Compliance Precaution',
                            'text' => 'All credential changes must strictly comply with SOI Security Policy. Do not share access keys across departments.',
                        ],
                    ],
                ],
            ],
            'faq_section' => [
                'id' => 'faq_section',
                'name' => 'FAQ Knowledge Section',
                'title' => 'FAQ Knowledge Section',
                'description' => 'Structured FAQ section generating Schema.org microdata.',
                'category' => 'Support',
                'icon' => '?',
                'blocks' => [
                    [
                        'type' => 'heading',
                        'data' => ['text' => 'Frequently Asked Questions', 'level' => 2],
                    ],
                    [
                        'type' => 'faq',
                        'data' => [
                            'items' => [
                                ['question' => 'How do I request system access?', 'answer' => 'Submit an access ticket via the IT Service Portal with your team manager approval.'],
                                ['question' => 'What is the standard SLA for resolution?', 'answer' => 'Standard priority requests are resolved within 2 business days.'],
                            ],
                        ],
                    ],
                ],
            ],
            'spec_reference_sheet' => [
                'id' => 'spec_reference_sheet',
                'name' => 'Specification Reference Sheet',
                'title' => 'Specification Reference Sheet',
                'description' => 'Key-value specification grid for technical standards.',
                'category' => 'Technical',
                'icon' => '☷',
                'blocks' => [
                    [
                        'type' => 'keyValues',
                        'data' => [
                            'title' => 'Technical Specifications',
                            'items' => [
                                ['key' => 'Module Name', 'value' => 'Enterprise Knowledge Center'],
                                ['key' => 'Version', 'value' => '1.1.0'],
                                ['key' => 'Status', 'value' => 'Production Ready'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
