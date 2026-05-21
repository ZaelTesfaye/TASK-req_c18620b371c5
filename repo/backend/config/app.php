<?php
/**
 * FieldOps Service & Environmental Analytics Suite
 * Main Application Configuration - Single source of truth
 *
 * All environment variables are consumed ONLY through this config module.
 * Application logic must NEVER access getenv() or $_ENV directly.
 *
 * Credential resolution for DB_USER and DB_PASSWORD:
 *   - NO hardcoded fallback. The previous `?: 'fieldops_user'` /
 *     `?: 'fieldops_pass'` defaults were removed so a misconfigured
 *     deployment cannot silently substitute a committed dev password
 *     for a real one.
 *   - On a fresh `docker compose up`, env_file pulls both keys from
 *     backend/.env.example into the container's process env before
 *     any PHP code runs. The Dockerfile additionally copies
 *     .env.example to /app/.env at build time, and loadEnvFile()
 *     below reads it - so even running the image outside compose
 *     works.
 *   - CLI entry points (bootstrap-db.php, reseed.php,
 *     seed-passwords.php, tests/bootstrap.php) use requireEnv() to
 *     exit 78 on missing credentials, which is appropriate for a
 *     script. The runtime web path here does NOT throw - see the
 *     long-form comment above the `return` statement for why.
 */

require_once __DIR__ . '/../scripts/load-env.php';
// Defensive: also load /app/.env directly. ThinkPHP's framework path
// already does this via \think\Env::load(), but config files can be
// evaluated by tooling (artisan-style scripts, IDE inspectors) that
// skips that bootstrap. loadEnvFile is a no-op when the file is
// absent and never overrides values already in the process env.
loadEnvFile(dirname(__DIR__) . '/.env');

// NOTE: do NOT throw from this file on missing DB_USER / DB_PASSWORD.
// config/app.php is evaluated during ThinkPHP's App::initialize(),
// which runs BEFORE the framework's ExceptionHandler middleware is
// wired up. A throw here bypasses the JSON-envelope error path and
// every request - including the unauthenticated
// /auth/bootstrap/stores call the login page fires on first paint -
// returns PHP's default 500 page. The login page's `.catch` then
// renders "Failed to load stores" even though the real cause is the
// credential miss. CLI scripts (bootstrap-db.php, reseed.php,
// seed-passwords.php, tests/bootstrap.php) DO use requireEnv() to
// fail loud, which is appropriate there - they exit 78 to the
// caller. At runtime, the bare getenv() below lets the framework
// boot; if the credential is genuinely missing, PDO surfaces it
// through the proper exception path with a readable JSON error.

return [
    // Application
    'app_name'       => getenv('APP_NAME') ?: 'FieldOps Service Suite',
    'app_env'        => getenv('APP_ENV') ?: 'production',
    'app_debug'      => filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    'app_url'        => getenv('APP_URL') ?: 'http://localhost:8000',
    'api_prefix'     => getenv('API_PREFIX') ?: '/api/v1',

    // TLS Toggle
    'enable_tls'     => filter_var(getenv('ENABLE_TLS') ?: 'false', FILTER_VALIDATE_BOOLEAN),

    // Database. Host/port/name keep operational defaults that match
    // the committed compose topology; user/password do NOT have any
    // hardcoded fallback - the values come from backend/.env.example
    // (loaded via compose env_file and/or /app/.env baked by the
    // Dockerfile). Bare getenv() returns false when truly missing,
    // which PDO then surfaces as a connection error through the
    // framework's normal exception path - no committed dev credential
    // ever substitutes for a real one.
    'db_host'        => getenv('DB_HOST') ?: 'mysql',
    'db_port'        => intval(getenv('DB_PORT') ?: '3306'),
    'db_name'        => getenv('DB_NAME') ?: 'fieldops',
    'db_user'        => getenv('DB_USER') ?: '',
    'db_password'    => getenv('DB_PASSWORD') ?: '',

    // Session
    'session_ttl_minutes' => intval(getenv('SESSION_TTL_MINUTES') ?: '480'),

    // Security - Lockout
    'lockout_max_attempts'     => intval(getenv('LOCKOUT_MAX_ATTEMPTS') ?: '5'),
    'lockout_duration_minutes' => intval(getenv('LOCKOUT_DURATION_MINUTES') ?: '15'),

    // Password Policy
    'password_min_length' => intval(getenv('PASSWORD_MIN_LENGTH') ?: '12'),

    // Tax
    'default_tax_rate' => floatval(getenv('DEFAULT_TAX_RATE') ?: '0.08'),

    // Audit
    'audit_log_retention_years' => intval(getenv('AUDIT_LOG_RETENTION_YEARS') ?: '7'),

    // Finance
    'discrepancy_threshold_usd' => floatval(getenv('DISCREPANCY_THRESHOLD_USD') ?: '1.00'),

    // Environmental
    'default_time_bucket_minutes'    => intval(getenv('DEFAULT_TIME_BUCKET_MINUTES') ?: '1'),
    'late_arrival_tolerance_minutes' => intval(getenv('LATE_ARRIVAL_TOLERANCE_MINUTES') ?: '5'),

    // Encryption
    'encryption_active_key_version' => intval(getenv('ENCRYPTION_ACTIVE_KEY_VERSION') ?: '1'),
    'encryption_keys_file_path'     => getenv('ENCRYPTION_KEYS_FILE_PATH') ?: '/app/storage/keys/encryption.key',

    // Export
    'csv_export_encoding' => getenv('CSV_EXPORT_ENCODING') ?: 'UTF-8',

    // Experiments
    'enable_experiments' => filter_var(getenv('ENABLE_EXPERIMENTS') ?: 'true', FILTER_VALIDATE_BOOLEAN),

    // CORS - explicit allowlist of trusted origins. Wildcard is rejected so a
    // typo or missing env doesn't accidentally open the API to the world.
    'cors_allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', getenv('CORS_ALLOWED_ORIGINS') ?: 'http://localhost:8080,http://localhost:3000,http://localhost:8000'))
    )),
];
