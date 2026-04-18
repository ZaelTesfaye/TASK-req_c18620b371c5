<?php
/**
 * PHPUnit Bootstrap for FieldOps Backend Tests
 */
require_once __DIR__ . '/../vendor/autoload.php';

// Set test environment
putenv('APP_ENV=testing');
putenv('APP_DEBUG=true');
putenv('DB_HOST=' . (getenv('DB_HOST') ?: 'mysql'));
putenv('DB_PORT=' . (getenv('DB_PORT') ?: '3306'));
putenv('DB_NAME=' . (getenv('DB_NAME') ?: 'fieldops'));
putenv('DB_USER=' . (getenv('DB_USER') ?: 'fieldops_user'));
putenv('DB_PASSWORD=' . (getenv('DB_PASSWORD') ?: 'fieldops_pass'));
