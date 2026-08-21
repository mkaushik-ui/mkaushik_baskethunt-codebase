<?php
declare(strict_types=1);

namespace SOI\Core\Content\Validation;

/**
 * Task T8: JSON Schema Structural Validator (Skeleton).
 */
class SchemaValidator
{
    /**
     * Validate raw document structure against canonical schema rules.
     *
     * @param array<string, mixed> $document
     * @return list<string> Validation error messages
     */
    public function validateStructure(array $document): array
    {
        $errors = [];
        if (!isset($document['schemaVersion'])) {
            $errors[] = 'Missing schemaVersion property.';
        }
        if (!isset($document['blocks']) || !is_array($document['blocks'])) {
            $errors[] = 'Missing or invalid blocks array.';
        }
        return $errors;
    }
}
