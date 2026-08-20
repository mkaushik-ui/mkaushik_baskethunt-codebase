<?php
/**
 * Physical entrypoint: /soi-central/saml/login (legacy + clean URL fallback)
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/_bootstrap.php';

use SOI\Core\SoiCentralAuth;

$returnTo = $_GET['return'] ?? $_GET['redirect'] ?? '';
SoiCentralAuth::dispatchSamlLogin(is_string($returnTo) ? $returnTo : '');
