<?php
if (!defined('SOI_ROOT')) define('SOI_ROOT', dirname(__DIR__));
require_once SOI_ROOT . '/config/config.php';
require_once SOI_ROOT . '/core/helpers.php';
spl_autoload_register(fn($c) => (fn($f) => file_exists($f) && require_once $f)(SOI_ROOT.'/core/'.str_replace(['SOI\\Core\\','\\'],['','/'],$c).'.php'));
use SOI\Core\{Database, Auth, SoiCentralAuth};
Database::connect(['host'=>SOI_DB_HOST,'name'=>SOI_DB_NAME,'user'=>SOI_DB_USER,'pass'=>SOI_DB_PASS,'port'=>SOI_DB_PORT,'prefix'=>SOI_DB_PREFIX]);
Auth::init();
SoiCentralAuth::install();

$returnTo = SoiCentralAuth::cmsLoginReturnUrl();

if (SoiCentralAuth::shouldUseCentralLogout()) {
    SoiCentralAuth::redirectToCentralLogout($returnTo);
}

Auth::logout();
header('Location: ' . $returnTo);
exit;