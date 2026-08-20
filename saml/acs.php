<?php
/**
 * Canonical SAML ACS handler file.
 * Served for /saml/acs and /saml/acs/ via parent rewrite or MultiViews — never external redirect.
 * Must preserve HTTP POST body (SAMLResponse).
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use SOI\Core\SoiCentralAuth;

SoiCentralAuth::processSamlAcsRequest();
