<?php
/**
 * Physical entrypoint: /saml/logout
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/_bootstrap.php';

use SOI\Core\SoiCentralAuth;

$returnTo = trim((string) ($_GET['return'] ?? ''));
SoiCentralAuth::redirectToCentralLogout($returnTo !== '' ? $returnTo : SoiCentralAuth::cmsLoginReturnUrl());
