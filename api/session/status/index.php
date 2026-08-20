<?php
declare(strict_types=1);

if (!defined('SOI_ROOT')) {
    define('SOI_ROOT', dirname(__DIR__, 3));
}
if (!is_file(SOI_ROOT . '/config/config.php')) {
    http_response_code(404);
    exit;
}

require_once SOI_ROOT . '/config/config.php';
require_once SOI_ROOT . '/core/helpers.php';
spl_autoload_register(fn($c) => (fn($f) => file_exists($f) && require_once $f)(SOI_ROOT.'/core/'.str_replace(['SOI\\Core\\','\\'],['','/'],$c).'.php'));

use SOI\Core\Auth;
use SOI\Core\Database;

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
    header("Access-Control-Max-Age: 86400");
    exit;
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

try {
    Database::connect(['host'=>SOI_DB_HOST,'name'=>SOI_DB_NAME,'user'=>SOI_DB_USER,'pass'=>SOI_DB_PASS,'port'=>SOI_DB_PORT,'prefix'=>SOI_DB_PREFIX]);
} catch (\Throwable $e) {
    // DB might fail, continue for session
}

Auth::init();

echo json_encode([
    'authenticated' => Auth::check(),
    'user_id' => Auth::user()['id'] ?? null,
    'ts' => time()
], JSON_UNESCAPED_SLASHES);
