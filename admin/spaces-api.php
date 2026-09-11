<?php
declare(strict_types=1);

/**
 * Admin — Knowledge Spaces AJAX & REST API Handler
 */
header('Content-Type: application/json; charset=UTF-8');

if (!defined('SOI_ROOT')) {
    define('SOI_ROOT', dirname(__DIR__));
}

if (file_exists(SOI_ROOT . '/config/config.php')) {
    require_once SOI_ROOT . '/config/config.php';
}
if (file_exists(SOI_ROOT . '/core/helpers.php')) {
    require_once SOI_ROOT . '/core/helpers.php';
}

spl_autoload_register(function (string $class) {
    $file = SOI_ROOT . '/core/' . str_replace(['SOI\\Core\\', '\\'], ['', '/'], $class) . '.php';
    if (file_exists($file)) require_once $file;
});

use SOI\Core\{Database, Auth, Cache};
use SOI\Core\Spaces\{KnowledgeSpaceService, SpaceSchema};

try {
    if (defined('SOI_DB_HOST') && class_exists(Database::class) && !Database::isConnected()) {
        Database::connect([
            'host'   => SOI_DB_HOST,
            'name'   => SOI_DB_NAME,
            'user'   => SOI_DB_USER,
            'pass'   => SOI_DB_PASS,
            'port'   => SOI_DB_PORT,
            'prefix' => SOI_DB_PREFIX,
        ]);
    }

    if (class_exists(Auth::class) && method_exists(Auth::class, 'init')) {
        Auth::init();
        if (method_exists(Auth::class, 'check') && !Auth::check()) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Authentication required.']);
            exit;
        }
    }

    $service = new KnowledgeSpaceService();
    $action = (string) (function_exists('soi_get') ? soi_get('action', $_POST['action'] ?? $_GET['action'] ?? '') : ($_POST['action'] ?? $_GET['action'] ?? ''));

    // 1. Get Space Details
    if ($action === 'get_space') {
        $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Invalid space ID.']);
            exit;
        }

        $space = $service->getSpace($id);
        if (!$space) {
            echo json_encode(['ok' => false, 'error' => 'Space not found.']);
            exit;
        }

        echo json_encode(['ok' => true, 'space' => $space]);
        exit;
    }

    // 2. Real-time Slug Availability Check
    if ($action === 'validate_slug') {
        $slug = trim((string) ($_GET['slug'] ?? $_POST['slug'] ?? ''));
        $excludeId = (int) ($_GET['exclude_id'] ?? $_POST['exclude_id'] ?? 0);

        if ($slug === '') {
            echo json_encode(['ok' => true, 'available' => false, 'message' => 'Slug cannot be empty.']);
            exit;
        }

        $available = $service->validateSlug($slug, $excludeId > 0 ? $excludeId : null);
        echo json_encode([
            'ok'        => true,
            'slug'      => $slug,
            'available' => $available,
            'message'   => $available ? 'Slug is available.' : 'Slug is already taken or reserved.',
        ]);
        exit;
    }

    // 3. List Spaces
    if ($action === 'list_spaces') {
        $type = (string) ($_GET['type'] ?? '');
        $status = (string) ($_GET['status'] ?? '');
        $visibility = (string) ($_GET['visibility'] ?? '');
        $q = (string) ($_GET['q'] ?? '');

        $filter = [];
        if ($type !== '') $filter['type'] = $type;
        if ($status !== '') $filter['status'] = $status;
        if ($visibility !== '') $filter['visibility'] = $visibility;
        if ($q !== '') $filter['q'] = $q;

        $spaces = $service->listSpaces($filter);
        echo json_encode(['ok' => true, 'spaces' => $spaces, 'count' => count($spaces)]);
        exit;
    }

    // 4. Save Space (AJAX POST)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'save_space' || $action === 'savespace')) {
        $csrfToken = (string) ($_POST['_csrf'] ?? $_POST['csrf_token'] ?? '');
        if (class_exists(Auth::class) && method_exists(Auth::class, 'verifyCsrf')) {
            if (!Auth::verifyCsrf($csrfToken)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'CSRF verification failed.']);
                exit;
            }
        }

        $editId = (int) ($_POST['id'] ?? 0);
        $subject = \SOI\Core\Spaces\Audience\AudienceSubjectContext::fromCurrentSession();
        $policyService = new \SOI\Core\Spaces\Audience\AudiencePolicyService();

        if ($editId > 0) {
            $existingSpace = $service->getSpace($editId);
            if (!$existingSpace || (!$policyService->canManageSpace($existingSpace, $subject) && !$subject->isEditor())) {
                if (class_exists(Auth::class) && method_exists(Auth::class, 'requireAuth')) {
                    Auth::requireAuth('editor');
                }
            }
        } else {
            if (class_exists(Auth::class) && method_exists(Auth::class, 'requireAuth')) {
                Auth::requireAuth('editor');
            }
        }

        $visibility = (string) ($_POST['visibility'] ?? SpaceSchema::VISIBILITY_PUBLIC);
        $data = [
            'title'       => trim((string) ($_POST['title'] ?? '')),
            'slug'        => trim((string) ($_POST['slug'] ?? '')),
            'type'        => (string) ($_POST['type'] ?? SpaceSchema::TYPE_GENERALDOCS),
            'icon'        => trim((string) ($_POST['icon'] ?? '')),
            'description' => trim((string) ($_POST['description'] ?? '')),
            'visibility'  => $visibility,
            'status'      => (string) ($_POST['status'] ?? SpaceSchema::STATUS_PUBLISHED),
            'sort_order'  => (int) ($_POST['sort_order'] ?? $_POST['sortorder'] ?? 0),
            'sortorder'   => (int) ($_POST['sort_order'] ?? $_POST['sortorder'] ?? 0),
        ];

        // Process Audience Policy (WD-05)
        $rawPolicy = $_POST['audience_policy'] ?? null;
        if (is_array($rawPolicy)) {
            $splitCsv = static function ($val): array {
                if (empty($val)) return [];
                $items = is_array($val) ? $val : explode(',', (string) $val);
                return array_values(array_unique(array_filter(array_map('trim', $items))));
            };

            $policyData = [
                'visibility'    => $visibility,
                'mode'          => (string) ($rawPolicy['mode'] ?? 'any'),
                'departments'   => $splitCsv($rawPolicy['departments'] ?? ''),
                'teams'         => $splitCsv($rawPolicy['teams'] ?? ''),
                'groups'        => $splitCsv($rawPolicy['groups'] ?? ''),
                'allowed_users' => $splitCsv($rawPolicy['allowed_users'] ?? ''),
                'space_owners'  => $splitCsv($rawPolicy['space_owners'] ?? ''),
            ];
            $data['audience_policy'] = json_encode($policyData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } elseif (is_string($rawPolicy) && trim($rawPolicy) !== '') {
            $data['audience_policy'] = $rawPolicy;
        }

        if ($editId > 0) {
            $service->updateSpace($editId, $data);
            $savedSpace = $service->getSpace($editId);
            echo json_encode(['ok' => true, 'action' => 'updated', 'space' => $savedSpace]);
        } else {
            $newId = $service->createSpace($data);
            $savedSpace = $service->getSpace($newId);
            echo json_encode(['ok' => true, 'action' => 'created', 'id' => $newId, 'space' => $savedSpace]);
        }
        exit;
    }

    // 5. Delete Space (AJAX POST)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'delete_space' || $action === 'deletespace')) {
        $csrfToken = (string) ($_POST['_csrf'] ?? $_POST['csrf_token'] ?? '');
        if (class_exists(Auth::class) && method_exists(Auth::class, 'verifyCsrf')) {
            if (!Auth::verifyCsrf($csrfToken)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'CSRF verification failed.']);
                exit;
            }
        }

        if (class_exists(Auth::class) && method_exists(Auth::class, 'requireAuth')) {
            Auth::requireAuth('admin');
        }

        $delId = (int) ($_POST['id'] ?? 0);
        if ($delId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Invalid space ID.']);
            exit;
        }

        $space = $service->getSpace($delId);
        if (!$space) {
            echo json_encode(['ok' => false, 'error' => 'Space not found.']);
            exit;
        }

        $success = $service->deleteSpace($delId);
        echo json_encode(['ok' => $success, 'action' => 'deleted', 'id' => $delId]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
