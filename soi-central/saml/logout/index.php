<?php
/**
 * Physical entrypoint: /soi-central/saml/logout
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/_bootstrap.php';

use SOI\Core\SoiCentralAuth;

$returnTo = trim((string) ($_GET['return'] ?? ''));
SoiCentralAuth::redirectToCentralLogout($returnTo !== '' ? $returnTo : SoiCentralAuth::cmsLoginReturnUrl());
