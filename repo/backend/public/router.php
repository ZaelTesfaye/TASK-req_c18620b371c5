<?php
/**
 * Router script for PHP's built-in server (`php -S host:port -t public/ router.php`).
 *
 * Without a router script, PHP's dev server treats paths with common static
 * extensions (.csv, .html, .json, etc.) as direct file lookups and returns
 * a 404 *before* falling through to public/index.php. That silently breaks
 * routes like `GET /dashboards/operations/export.csv`, which are defined in
 * route/api.php but never reach ThinkPHP's dispatcher.
 *
 * This router:
 *   1. Serves real static assets (everything that actually exists on disk
 *      under public/) directly, so images/css/js still work.
 *   2. Routes everything else through public/index.php, letting the
 *      ThinkPHP router match the URL regardless of its extension.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path !== '/' && $path !== '' && $path !== null) {
    $candidate = __DIR__ . $path;
    if (is_file($candidate) && basename($candidate) !== 'router.php' && basename($candidate) !== 'index.php') {
        return false; // let the built-in server serve the static file
    }
}

require __DIR__ . '/index.php';
