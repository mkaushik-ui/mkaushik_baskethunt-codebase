<?php
/**
 * Admin deep-search JSON endpoint.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

ini_set('display_errors', '0');
ini_set('log_errors', '1');

function admin_search_respond(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

try {
    if (!defined('SOI_ROOT')) define('SOI_ROOT', dirname(__DIR__));
    if (!defined('SOI_JSON_REQUEST')) define('SOI_JSON_REQUEST', true);

    require_once SOI_ROOT . '/config/config.php';
    spl_autoload_register(function (string $class): void {
        $file = SOI_ROOT . '/core/' . str_replace(['SOI\\Core\\', '\\'], ['', '/'], $class) . '.php';
        if (is_file($file)) require_once $file;
    });

    \SOI\Core\Auth::init();
    $sessionUser = \SOI\Core\Auth::user();
    $requestToken = trim((string)($_SERVER['HTTP_X_SOI_SEARCH_TOKEN'] ?? ''));

    if (!$sessionUser && $requestToken === '') {
        admin_search_respond([
            'ok' => false,
            'code' => 'AUTH_REQUIRED',
            'message' => 'Your Admin Center session has expired. Refresh the page and sign in again.',
        ], 401);
    }

    \SOI\Core\Database::connect([
        'host' => SOI_DB_HOST,
        'name' => SOI_DB_NAME,
        'user' => SOI_DB_USER,
        'pass' => SOI_DB_PASS,
        'port' => SOI_DB_PORT,
        'prefix' => SOI_DB_PREFIX,
    ]);

    if ($sessionUser) {
        $authorizedUserId = (int)($sessionUser['id'] ?? 0);
    } else {
        $claims = \SOI\Core\AdminSearch::validateToken($requestToken);
        if (!$claims) {
            admin_search_respond([
                'ok' => false,
                'code' => 'AUTH_REQUIRED',
                'message' => 'Search authorization expired. Refresh the Admin Center and try again.',
            ], 401);
        }

        $authorizedUserId = (int)$claims['uid'];
    }

    $usersTable = \SOI\Core\Database::prefix('users');
    $authorizedUser = $authorizedUserId > 0
        ? \SOI\Core\Database::selectOne(
            "SELECT id, role, status FROM `$usersTable` WHERE id = ? LIMIT 1",
            [$authorizedUserId]
        )
        : null;
    if (!$authorizedUser || (int)($authorizedUser['status'] ?? 0) !== 1) {
        admin_search_respond([
            'ok' => false,
            'code' => 'AUTH_REQUIRED',
            'message' => 'This Admin Center account is no longer active.',
        ], 401);
    }
    $role = (string)($authorizedUser['role'] ?? '');

    if (!\SOI\Core\AdminSearch::roleAtLeast($role, 'author')) {
        admin_search_respond([
            'ok' => false,
            'code' => 'FORBIDDEN',
            'message' => 'Your account does not have permission to use Admin Center search.',
        ], 403);
    }

    $query = trim((string)($_GET['q'] ?? ''));
    if (strlen($query) < 2) {
        admin_search_respond(['ok' => true, 'query' => $query, 'count' => 0, 'results' => []]);
    }
    if (strlen($query) > 100) {
        admin_search_respond([
            'ok' => false,
            'code' => 'QUERY_TOO_LONG',
            'message' => 'Search queries must be 100 characters or fewer.',
        ], 422);
    }

    $search = \SOI\Core\AdminSearch::search($query, $role);
    admin_search_respond([
        'ok' => true,
        'query' => $query,
        'count' => $search['count'],
        'results' => $search['results'],
        'degraded' => $search['degraded'],
        'message' => $search['degraded'] ? 'Some search sources are temporarily unavailable.' : '',
    ]);
} catch (Throwable $error) {
    $reference = strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));
    error_log('[Admin Search][' . $reference . '] ' . $error::class . ': ' . $error->getMessage());
    admin_search_respond([
        'ok' => false,
        'code' => 'SEARCH_ERROR',
        'message' => 'Search could not be completed. Try again or contact an administrator. Reference: ' . $reference,
    ], 500);
}
