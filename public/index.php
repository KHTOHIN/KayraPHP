<?php

use Kayra\Foundation\Application;
use Kayra\Http\Request;
use Kayra\Http\Response;

// -------------------------------------------------------
// 1. Autoload
// -------------------------------------------------------
require __DIR__ . '/../vendor/autoload.php';

// -------------------------------------------------------
// 2. Load Helpers
// -------------------------------------------------------
require __DIR__ . '/../core/Utils/helpers.php';

// -------------------------------------------------------
// 3. Set Error Reporting Based on Environment
// -------------------------------------------------------
if (is_dev()) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

// -------------------------------------------------------
// 4. Bootstrap Application
// -------------------------------------------------------
$app = require __DIR__ . '/../bootstrap/app.php';
$app->boot();

// -------------------------------------------------------
// 5. Prepare Request and Handle It
// -------------------------------------------------------
$request = Request::createFromGlobals();

$kernel = require __DIR__ . '/../bootstrap/kernel.php';

$response = handleRequest($request, $app);

// -------------------------------------------------------
// 6. Send Response
// -------------------------------------------------------
$response->send();