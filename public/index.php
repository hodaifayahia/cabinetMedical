<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Packaged desktop resources are read-only. The native supervisor points
// Laravel at a per-install writable storage directory, so the pre-bootstrap
// maintenance check must honor the same trusted process environment value.
$storagePath = $_SERVER['LARAVEL_STORAGE_PATH'] ?? $_ENV['LARAVEL_STORAGE_PATH'] ?? null;
$storagePath = is_string($storagePath) && $storagePath !== ''
    ? rtrim($storagePath, '/\\')
    : __DIR__.'/../storage';

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = $storagePath.'/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
