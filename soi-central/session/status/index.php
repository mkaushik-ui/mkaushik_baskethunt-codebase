<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

use SOI\Core\SoiCentralAuth;

SoiCentralAuth::dispatchSessionStatus();