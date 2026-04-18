<?php
/**
 * FieldOps Service & Environmental Analytics Suite
 * Main Application Configuration - Single source of truth
 *
 * All environment variables are consumed ONLY through this config module.
 * Application logic must NEVER access getenv() or $_ENV directly.
 */

return [
    // Application
    'app_name'       => getenv('APP_NAME') ?: 'FieldOps Service Suite',
    'app_env'        => getenv('APP_ENV') ?: 'production',
    'app_debug'      => filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    'app_url'        => getenv('APP_URL') ?: 'http://localhost:8000',
    'api_prefix'     => getenv('API_PREFIX') ?: '/api/v1',

    // TLS Toggle
    'enable_tls'     => filter_var(getenv('ENABLE_TLS') ?: 'false', FILTER_VALIDATE_BOOLEAN),

    // Database
    'db_host'        => getenv('DB_HOST') ?: 'mysql',
    'db_port'        => intval(getenv('DB_PORT') ?: '3306'),
    'db_name'        => getenv('DB_NAME') ?: 'fieldops',
    'db_user'        => getenv('DB_USER') ?: 'fieldops_user',
    'db_password'    => getenv('DB_PASSWORD') ?: 'fieldops_pass',

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
