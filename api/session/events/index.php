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

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

try {
    Database::connect(['host'=>SOI_DB_HOST,'name'=>SOI_DB_NAME,'user'=>SOI_DB_USER,'pass'=>SOI_DB_PASS,'port'=>SOI_DB_PORT,'prefix'=>SOI_DB_PREFIX]);
} catch (\Throwable $e) {
    // DB might fail, continue for session
}

Auth::init();

if (!Auth::check()) {
    echo "event: session_invalid\n";
    echo "data: {\"reason\":\"unauthorized\"}\n\n";
    flush();
    exit;
}

session_write_close();

while (true) {
    if (connection_aborted()) break;
    
    session_start(['read_and_close' => true]);
    $loggedIn = Auth::check();
    
    if (!$loggedIn) {
        echo "event: session_invalid\n";
        echo "data: {\"reason\":\"logged_out_centrally\"}\n\n";
        flush();
        break;
    }
    
    echo "event: heartbeat\n";
    echo "data: {\"ts\":" . time() . "}\n\n";
    flush();
    
    sleep(10);
}
