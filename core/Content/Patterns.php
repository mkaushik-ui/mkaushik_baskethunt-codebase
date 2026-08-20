<?php
declare(strict_types=1);

namespace SOI\Core\Content;

/**
 * Built-in enterprise content patterns (1.1.0).
 */
final class Patterns
{
    public static function all(): array
    {
        return [
            [
                'id' => 'procedure_steps',
                'title' => 'Standard Procedure (3 Steps)',
                'description' => 'Numbered 3-step action workflow.',
                'category' => 'Procedures',
                'icon' => '1.',
                'blocks' => [
                    [
                        'id' => Document::newId(),
                        'type' => 'steps',
                        'data' => [
                            'items' => [
                                ['title' => 'Step 1: Preparation', 'content' => 'Review the requirements and verify prerequisites.'],
                                ['title' => 'Step 2: Execution', 'content' => 'Execute the primary procedure steps.'],
                                ['title' => 'Step 3: Verification', 'content' => 'Confirm desired results and document in logs.'],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id' => 'callout_warning_box',
                'title' => 'Security & Compliance Alert',
                'description' => 'Prominent warning callout box with security badge.',
                'category' => 'Notices',
                'icon' => '⚠️',
                'blocks' => [
                    [
                        'id' => Document::newId(),
                        'type' => 'callout',
                        'data' => [
                            'tone' => 'warning',
                            'title' => 'Security & Compliance Precaution',
                            'text' => 'All credential changes must comply with the SOI security policy. Do not share access keys across departments.',
                        ],
                    ],
                ],
            ],
            [
                'id' => 'two_col_comparison',
                'title' => 'Two-Column Comparison',
                'description' => 'Side-by-side 50/50 comparison columns.',
                'category' => 'Layout',
                'icon' => '▥',
                'blocks' => [
                    [
                        'id' => Document::newId(),
                        'type' => 'columns',
                        'data' => [
                            'layout' => '50-50',
                            'columns' => [
                                ['content' => "<strong>Option A: Cloud Gateway</strong>\n<p>Fast setup, managed SLA, high availability.</p>"],
                                ['content' => "<strong>Option B: Dedicated Node</strong>\n<p>Full control, customized tuning, isolated tenancy.</p>"],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id' => 'api_code_sample',
                'title' => 'Multi-Language API Code Sample',
                'description' => 'Code group tabs for cURL, PHP, and JavaScript.',
                'category' => 'Technical',
                'icon' => '{ }',
                'blocks' => [
                    [
                        'id' => Document::newId(),
                        'type' => 'codeGroup',
                        'data' => [
                            'items' => [
                                ['label' => 'cURL', 'language' => 'bash', 'code' => "curl -X POST https://kc.soi.co.in/api/v1/action \\\n  -H \"Content-Type: application/json\" \\\n  -d '{\"action\":\"execute\"}'\n", 'caption' => ''],
                                ['label' => 'PHP', 'language' => 'php', 'code' => "\$res = \$client->post('/api/v1/action', ['json' => ['action' => 'execute']]);\n", 'caption' => ''],
                                ['label' => 'JavaScript', 'language' => 'javascript', 'code' => "const res = await fetch('/api/v1/action', {\n  method: 'POST',\n  body: JSON.stringify({ action: 'execute' })\n});\n", 'caption' => ''],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id' => 'faq_accordion',
                'title' => 'FAQ Knowledge Section',
                'description' => 'Structured FAQ section with Schema.org markup.',
                'category' => 'Support',
                'icon' => '?',
                'blocks' => [
                    [
                        'id' => Document::newId(),
                        'type' => 'faq',
                        'data' => [
                            'items' => [
                                ['question' => 'How do I request access to this system?', 'answer' => 'Submit a ticket through the IT Service Portal with manager approval.'],
                                ['question' => 'What is the standard SLA for resolution?', 'answer' => 'Standard requests are addressed within 2 business days.'],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id' => 'spec_reference_sheet',
                'title' => 'Specification Reference Sheet',
                'description' => 'Key-value reference specification grid.',
                'category' => 'Technical',
                'icon' => '☷',
                'blocks' => [
                    [
                        'id' => Document::newId(),
                        'type' => 'keyValues',
                        'data' => [
                            'title' => 'Technical Specifications',
                            'items' => [
                                ['key' => 'Module Name', 'value' => 'Enterprise Authoring Suite'],
                                ['key' => 'Version', 'value' => '1.1.0'],
                                ['key' => 'Engine', 'value' => 'Editor.js 2.30.8'],
                                ['key' => 'Status', 'value' => 'Production Ready'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function get(string $id): ?array
    {
        foreach (self::all() as $p) {
            if ($p['id'] === $id) return $p;
        }
        return null;
    }
}
