<?php
declare(strict_types=1);

namespace SOI\Core\Content\Templates;

use SOI\Core\Content\Document;

/**
 * Task T7: Document Starter Templates Service (Skeleton).
 */
class TemplateService
{
    /**
     * @return list<array{id:string,title:string,description:string,document:array<string,mixed>}>
     */
    public function getTemplates(): array
    {
        return [
            [
                'id' => 'blank',
                'title' => 'Blank Document',
                'description' => 'Start with an empty clean page.',
                'document' => Document::empty(),
            ],
            [
                'id' => 'api_spec',
                'title' => 'API Specification',
                'description' => 'Scaffold an API documentation page with endpoints and code examples.',
                'document' => [
                    'schemaVersion' => 1,
                    'blocks' => [
                        ['id' => 'blk_h2_api', 'type' => 'heading', 'data' => ['level' => 2, 'text' => 'API Overview']],
                        ['id' => 'blk_p_api', 'type' => 'paragraph', 'data' => ['text' => 'Provide a summary of the API specification.']],
                    ],
                ],
            ],
        ];
    }
}
