<?php
/**
 * Legacy ACS path file: /soi-central/saml/acs.php
 * Also used for internal rewrite of /soi-central/saml/acs (no trailing-slash redirect).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/_bootstrap.php';

use SOI\Core\SoiCentralAuth;

SoiCentralAuth::processSamlAcsRequest();
