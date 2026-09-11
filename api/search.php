<?php
declare(strict_types=1);

/**
 * SOI Knowledge Center — Global Discovery & Search API Endpoint
 * Location: api/search.php
 * Domain: kc.soi.co.in
 *
 * RESTful JSON search endpoint for ⌘K Global Search Modal and client search integrations.
 * Evaluates Audience Subject Context (WD-01) and filters results fail-closed (WD-02/DS-02).
 */

if (!defined('SOI_ROOT')) {
    define('SOI_ROOT', dirname(__DIR__));
}

require_once SOI_ROOT . '/core/helpers.php';

spl_autoload_register(static function (string $class): void {
    $file = SOI_ROOT . '/core/' . str_replace(['SOI\\Core\\', '\\'], ['', '/'], $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use SOI\Core\Search\SearchEngine;
use SOI\Core\Spaces\Audience\AudienceSubjectContext;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

try {
    $query = trim((string) ($_GET['q'] ?? ''));
    if ($query === '') {
        echo json_encode([
            'status' => 'success',
            'query' => '',
            'count' => 0,
            'results' => []
        ]);
        exit;
    }

    $filters = [
        'space' => trim((string) ($_GET['space'] ?? '')),
        'category' => trim((string) ($_GET['category'] ?? '')),
        'version' => trim((string) ($_GET['v'] ?? '')),
        'status' => 'published'
    ];

    $subject = AudienceSubjectContext::fromCurrentSession();
    $results = SearchEngine::search($query, $filters, $subject);

    echo json_encode([
        'status' => 'success',
        'query' => $query,
        'count' => count($results),
        'results' => $results
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'An error occurred while executing search: ' . $e->getMessage()
    ]);
}
