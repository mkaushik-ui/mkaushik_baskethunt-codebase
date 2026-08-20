<?php
/**
 * Physical entrypoint: /saml/metadata
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/_bootstrap.php';

use SOI\Core\SoiCentralAuth;

SoiCentralAuth::dispatchSamlMetadata();
