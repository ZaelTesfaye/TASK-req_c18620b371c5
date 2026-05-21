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
 *     backend/.env.example before this file is evaluated. The
 *     Dockerfile additionally copies .env.example to /app/.env at
 *     build time, and loadEnvFile() below reads it - so even running
 *     the image outside compose works.
 *   - If both sources are absent the foreach guard below throws a
 *     RuntimeException naming the missing key, instead of letting
 *     an opaque "Access denied for user ''@'mysql'" surface three
 *     frames deep inside PDO.
 */

require_once __DIR__ . '/../scripts/load-env.php';
// Defensive: also load /app/.env directly. ThinkPHP's framework path
// already does this via \think\Env::load(), but config files can be
// evaluated by tooling (artisan-style scripts, IDE inspectors) that
// skips that bootstrap. loadEnvFile is a no-op when the file is
// absent and never overrides values already in the process env.
loadEnvFile(dirname(__DIR__) . '/.env');

// DB credentials must be set explicitly - no committed fallback.
// Throwing here (rather than letting them silently become empty
// strings) means a misconfigured deployment surfaces as a clear
// "DB_USER not set" message at framework boot instead of an opaque
// "Access denied for user ''@'mysql'" three frames deep in PDO.
// Both keys ship in backend/.env.example, so on a fresh `docker
// compose up` env_file populates them before this file is evaluated.
foreach (['DB_USER', 'DB_PASSWORD'] as $_required) {
    $_v = getenv($_required);
    if ($_v === false || $_v === '') {
        throw new \RuntimeException(
            "Required env var {$_required} is not set. " .
            "Provision it via docker-compose env_file (loads " .
            "backend/.env.example), via /app/.env (baked from " .
            ".env.example by the Dockerfile), or via your secret " .
            "manager. The previous hardcoded `'fieldops_pass'` " .
            "fallback was removed deliberately - committed dev " .
            "passwords must not silently substitute for production " .
            "credentials."
        );
    }
}
unset($_required, $_v);

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
    // the committed compose topology; user/password do NOT have
    // any fallback - the foreach above guarantees they are set,
    // sourced cleanly from .env.example (via env_file or /app/.env)
    // or from a real secret manager in production.
    'db_host'        => getenv('DB_HOST') ?: 'mysql',
    'db_port'        => intval(getenv('DB_PORT') ?: '3306'),
    'db_name'        => getenv('DB_NAME') ?: 'fieldops',
    'db_user'        => getenv('DB_USER'),
    'db_password'    => getenv('DB_PASSWORD'),

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
