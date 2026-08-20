<?php
/**
 * Physical entrypoint: /saml/login
 * Thin wrapper around SoiCentralAuth::dispatchSamlLogin (no duplicate logic).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/_bootstrap.php';

use SOI\Core\SoiCentralAuth;

$returnTo = $_GET['return'] ?? $_GET['redirect'] ?? '';
SoiCentralAuth::dispatchSamlLogin(is_string($returnTo) ? $returnTo : '');
