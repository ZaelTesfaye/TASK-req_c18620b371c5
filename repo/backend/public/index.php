<?php
/**
 * FieldOps Service & Environmental Analytics Suite
 * Application entry point
 */
namespace think;

// Start output buffering BEFORE anything else (including the
// autoloader) so any stray output - PHP startup notices, accidental
// whitespace from an included vendor file, a forgotten var_dump - is
// captured in the buffer and discarded before ThinkPHP writes its
// JSON response. Without this guard, that stray content gets sent to
// the client as the response prefix, and the browser's response.json()
// chokes with "Unexpected token ' '... is not valid JSON" - exactly
// the symptom that broke the login page's store dropdown.
ob_start();

// Errors must never appear in the response body. log_errors + a file
// destination is already set on the php -S command line (see
// backend/Dockerfile CMD); this is the belt-and-braces inline form so
// the guarantee holds even when launched outside that CMD.
ini_set('display_errors', 'stderr');
ini_set('display_startup_errors', 'stderr');

require __DIR__ . '/../vendor/autoload.php';

$http = (new App())->http;
$response = $http->run();

// Discard whatever stray bytes accumulated in the output buffer
// between ob_start() and now. The framework's Response::send() below
// emits the real, correctly-formed JSON body on its own.
if (ob_get_level() > 0) {
    ob_end_clean();
}

$response->send();
$http->end($response);
