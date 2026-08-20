<?php
declare(strict_types=1);

namespace SOI\Core\Content;

/**
 * Built-in enterprise document templates (1.1.0).
 */
final class Templates
{
    public static function all(): array
    {
        return [
            [
                'id' => 'blank',
                'title' => 'Blank Document',
                'description' => 'Start with an empty document surface.',
                'icon' => '📄',
                'category' => 'General',
                'document' => [
                    'schemaVersion' => 1,
                    'blocks' => [
                        ['id' => Document::newId(), 'type' => 'paragraph', 'data' => ['text' => '']],
                    ],
                ],
            ],
            [
                'id' => 'general_guide',
                'title' => 'General Guide',
                'description' => 'Structured documentation guide with overview, instructions, and tips.',
                'icon' => '📘',
                'category' => 'Documentation',
                'document' => [
                    'schemaVersion' => 1,
                    'blocks' => [
                        ['id' => Document::newId(), 'type' => 'heading', 'data' => ['text' => 'Overview', 'level' => 2]],
                        ['id' => Document::newId(), 'type' => 'paragraph', 'data' => ['text' => 'This guide explains the key concepts and workflows for this topic.']],
                        ['id' => Document::newId(), 'type' => 'callout', 'data' => ['tone' => 'info', 'title' => 'Important Note', 'text' => 'Make sure you have appropriate access permissions before proceeding.']],
                        ['id' => Document::newId(), 'type' => 'heading', 'data' => ['text' => 'Key Principles', 'level' => 2]],
                        ['id' => Document::newId(), 'type' => 'list', 'data' => ['style' => 'unordered', 'items' => ['Standardize procedures', 'Document changes in real time', 'Maintain audit trail']]],
                    ],
                ],
            ],
            [
                'id' => 'procedure',
                'title' => 'Step-by-Step Procedure',
                'description' => 'Procedural standard operating procedure with numbered steps.',
                'icon' => '🔢',
                'category' => 'Procedures',
                'document' => [
                    'schemaVersion' => 1,
                    'blocks' => [
                        ['id' => Document::newId(), 'type' => 'heading', 'data' => ['text' => 'Purpose & Scope', 'level' => 2]],
                        ['id' => Document::newId(), 'type' => 'paragraph', 'data' => ['text' => 'Follow these instructions to complete the standardized process safely.']],
                        ['id' => Document::newId(), 'type' => 'callout', 'data' => ['tone' => 'tip', 'title' => 'Prerequisite', 'text' => 'Ensure all prerequisite systems are operational before starting.']],
                        ['id' => Document::newId(), 'type' => 'heading', 'data' => ['text' => 'Procedure Steps', 'level' => 2]],
                        ['id' => Document::newId(), 'type' => 'steps', 'data' => [
                            'items' => [
                                ['title' => 'Preparation & Verification', 'content' => 'Verify system status and authenticate to the target environment.'],
                                ['title' => 'Execute Configuration Change', 'content' => 'Apply the approved configuration parameters according to policy.'],
                                ['title' => 'Validation & Verification', 'content' => 'Run the validation check suite to confirm operational readiness.'],
                            ],
                        ]],
                        ['id' => Document::newId(), 'type' => 'heading', 'data' => ['text' => 'Sign-Off & Logging', 'level' => 2]],
                        ['id' => Document::newId(), 'type' => 'paragraph', 'data' => ['text' => 'Record completion in the operations log.']],
                    ],
                ],
            ],
            [
                'id' => 'api_reference',
                'title' => 'API Reference Specification',
                'description' => 'Complete REST API documentation template with parameters and code examples.',
                'icon' => '⚡',
                'category' => 'Technical',
                'document' => [
                    'schemaVersion' => 1,
                    'blocks' => [
                        ['id' => Document::newId(), 'type' => 'heading', 'data' => ['text' => 'Endpoint Summary', 'level' => 2]],
                        ['id' => Document::newId(), 'type' => 'statusBadge', 'data' => ['status' => 'stable', 'label' => 'Production API v1']],
                        ['id' => Document::newId(), 'type' => 'paragraph', 'data' => ['text' => 'Comprehensive technical specification for this API endpoint.']],
                        ['id' => Document::newId(), 'type' => 'apiEndpoint', 'data' => [
                            'method' => 'GET',
                            'endpoint' => '/api/v1/documents/{id}',
                            'title' => 'Get Document Details',
                            'description' => 'Fetches structured document metadata and content by identifier.',
                            'auth' => 'Bearer Token (JWT)',
                            'parameters' => [
                                ['name' => 'id', 'type' => 'integer', 'required' => true, 'description' => 'Unique document ID'],
                                ['name' => 'format', 'type' => 'string', 'required' => false, 'description' => 'Output format: json or html'],
                            ],
                            'requestBody' => '',
                            'responseBody' => "{\n  \"ok\": true,\n  \"id\": 102,\n  \"title\": \"Example Document\",\n  \"status\": \"published\"\n}",
                        ]],
                        ['id' => Document::newId(), 'type' => 'heading', 'data' => ['text' => 'Code Sample', 'level' => 2]],
                        ['id' => Document::newId(), 'type' => 'codeGroup', 'data' => [
                            'items' => [
                                ['label' => 'cURL', 'language' => 'bash', 'code' => "curl -X GET https://kc.soi.co.in/api/v1/documents/102 \\\n  -H \"Authorization: Bearer \$TOKEN\"\n", 'caption' => ''],
                                ['label' => 'PHP', 'language' => 'php', 'code' => "\$res = \$client->get('/api/v1/documents/102');\n", 'caption' => ''],
                                ['label' => 'JavaScript', 'language' => 'javascript', 'code' => "const res = await fetch('/api/v1/documents/102', { headers: { Authorization: `Bearer \${token}` } });\n", 'caption' => ''],
                            ],
                        ]],
                    ],
                ],
            ],
            [
                'id' => 'faq',
                'title' => 'FAQ Knowledge Base',
                'description' => 'Dedicated question and answer knowledge document.',
                'icon' => '❓',
                'category' => 'Support',
                'document' => [
                    'schemaVersion' => 1,
                    'blocks' => [
                        ['id' => Document::newId(), 'type' => 'heading', 'data' => ['text' => 'Frequently Asked Questions', 'level' => 2]],
                        ['id' => Document::newId(), 'type' => 'paragraph', 'data' => ['text' => 'Find instant answers to common questions below.']],
                        ['id' => Document::newId(), 'type' => 'faq', 'data' => [
                            'items' => [
                                ['question' => 'How do I request access to this system?', 'answer' => 'Submit a ticket through the IT Service Portal with manager approval.'],
                                ['question' => 'Where can I find additional documentation?', 'answer' => 'Browse the Knowledge Center repository or search by keyword.'],
                                ['question' => 'What is the standard SLA for resolution?', 'answer' => 'Standard requests are addressed within 2 business days.'],
                            ],
                        ]],
                        ['id' => Document::newId(), 'type' => 'callout', 'data' => ['tone' => 'note', 'title' => 'Need More Help?', 'text' => 'Contact the help desk if your question is not covered in this FAQ.']],
                    ],
                ],
            ],
            [
                'id' => 'troubleshooting',
                'title' => 'Troubleshooting Playbook',
                'description' => 'Diagnostic guide with symptom analysis and resolution steps.',
                'icon' => '🛠',
                'category' => 'Operations',
                'document' => [
                    'schemaVersion' => 1,
                    'blocks' => [
                        ['id' => Document::newId(), 'type' => 'heading', 'data' => ['text' => 'Symptoms & Diagnostics', 'level' => 2]],
                        ['id' => Document::newId(), 'type' => 'callout', 'data' => ['tone' => 'warning', 'title' => 'Operational Alert', 'text' => 'Follow safety precautions before attempting recovery operations.']],
                        ['id' => Document::newId(), 'type' => 'keyValues', 'data' => [
                            'title' => 'System Specifications',
                            'items' => [
                                ['key' => 'Service Name', 'value' => 'Knowledge Center Engine'],
                                ['key' => 'Primary Contact', 'value' => 'Operations Team'],
                                ['key' => 'Severity Level', 'value' => 'Medium'],
                            ],
                        ]],
                        ['id' => Document::newId(), 'type' => 'heading', 'data' => ['text' => 'Resolution Steps', 'level' => 2]],
                        ['id' => Document::newId(), 'type' => 'steps', 'data' => [
                            'items' => [
                                ['title' => 'Identify Failure Point', 'content' => 'Review application logs in `/var/log` for timestamped error entries.'],
                                ['title' => 'Restart Affected Worker', 'content' => 'Execute service restart command.'],
                                ['title' => 'Verify Health Endpoint', 'content' => 'Query the health check API to confirm full operational recovery.'],
                            ],
                        ]],
                    ],
                ],
            ],
        ];
    }

    public static function get(string $id): ?array
    {
        foreach (self::all() as $t) {
            if ($t['id'] === $id) return $t;
        }
        return null;
    }
}
