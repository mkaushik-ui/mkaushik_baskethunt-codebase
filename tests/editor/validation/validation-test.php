<?php
declare(strict_types=1);

/**
 * Task T8: Validation Pipeline & Security Regression Test (Skeleton).
 */
require_once __DIR__ . '/../../../core/Content/Validation/SchemaValidator.php';
require_once __DIR__ . '/../../../core/Content/Validation/Sanitizer.php';
require_once __DIR__ . '/../../../core/Content/Validation/ValidationPipeline.php';

use SOI\Core\Content\Validation\ValidationPipeline;

$pipeline = new ValidationPipeline();
$testDoc = [
    'schemaVersion' => 1,
    'blocks' => [
        ['type' => 'paragraph', 'data' => ['text' => 'Hello World']],
    ],
];

$result = $pipeline->validate($testDoc);
if ($result['valid']) {
    echo "[PASS] Basic validation pipeline test passed.\n";
} else {
    echo "[FAIL] Validation pipeline test failed.\n";
    exit(1);
}
