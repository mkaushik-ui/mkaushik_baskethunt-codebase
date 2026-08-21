<?php
declare(strict_types=1);

namespace SOI\Core\Content\Validation;

use SOI\Core\Content\BlockRegistry;

/**
 * Task T8: Document Server-Side Validation Pipeline (Skeleton).
 */
class ValidationPipeline
{
    private SchemaValidator $schemaValidator;
    private Sanitizer $sanitizer;

    public function __construct(?SchemaValidator $schemaValidator = null, ?Sanitizer $sanitizer = null)
    {
        $this->schemaValidator = $schemaValidator ?? new SchemaValidator();
        $this->sanitizer = $sanitizer ?? new Sanitizer();
    }

    /**
     * Run all validation checks across canonical document and individual blocks.
     *
     * @param array<string, mixed> $document
     * @return array{valid:bool,errors:list<string>}
     */
    public function validate(array $document): array
    {
        $errors = $this->schemaValidator->validateStructure($document);
        if (!empty($errors)) {
            return ['valid' => false, 'errors' => $errors];
        }

        foreach ($document['blocks'] ?? [] as $idx => $block) {
            $type = (string)($block['type'] ?? '');
            if (BlockRegistry::has($type)) {
                $provider = BlockRegistry::get($type);
                $blockErrors = $provider->validate((array)($block['data'] ?? []));
                foreach ($blockErrors as $err) {
                    $errors[] = "Block #{$idx} ({$type}): {$err}";
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
}
