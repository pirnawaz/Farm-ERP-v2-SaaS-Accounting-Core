<?php

/**
 * PHPUnit bootstrap: ensure the Laravel app is resolved from this API app directory
 * so migrations (database/migrations) and config are always loaded from apps/api.
 */
$apiBasePath = realpath(dirname(__DIR__));
if ($apiBasePath === false) {
    $apiBasePath = dirname(__DIR__);
}
putenv('APP_BASE_PATH=' . $apiBasePath);
$_ENV['APP_BASE_PATH'] = $apiBasePath;

require dirname(__DIR__) . '/vendor/autoload.php';
