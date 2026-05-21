<?php
/**
 * PHPUnit Bootstrap for FieldOps Backend Tests
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../scripts/load-env.php';

// Load /app/.env (provisioned by Dockerfile.tests from .env.example).
// Process env vars set by compose env_file always win; the .env file
// only fills gaps. The previous hardcoded `?: 'fieldops_pass'`
// fallbacks have been removed - misconfiguration now fails loud in
// requireEnv() below rather than silently attempting a committed dev
// password against whatever database happens to be reachable.
loadEnvFile(dirname(__DIR__) . '/.env');

// Test-mode overrides. These are not credentials and are hardcoded by
// design so PHPUnit always runs against the deterministic encryption
// key path (see EncryptionService::getKeyMaterial) and surfaces full
// stack traces on 5xx.
putenv('APP_ENV=testing');
putenv('APP_DEBUG=true');
$_ENV['APP_ENV']    = 'testing';
$_ENV['APP_DEBUG']  = 'true';

// Fail loud at bootstrap if any required credential is missing,
// instead of letting PDO produce a confusing "Access denied" downstream.
foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $key) {
    requireEnv($key);
}
