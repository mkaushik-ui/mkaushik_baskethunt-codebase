<?php
/**
 * Physical entrypoint: /soi-central/denied
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/_bootstrap.php';

use SOI\Core\SoiCentralAuth;

// Use front-controller route handler (keeps renderAccessDenied private).
SoiCentralAuth::handleRoute('soi-central/denied', 'GET');
