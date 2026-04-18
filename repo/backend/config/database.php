<?php
/**
 * Database Configuration
 * Consumes values from the main config module only.
 */

$appConfig = require __DIR__ . '/app.php';

return [
    'default'     => 'mysql',
    'connections'  => [
        'mysql' => [
            'type'     => 'mysql',
            'hostname' => $appConfig['db_host'],
            'database' => $appConfig['db_name'],
            'username' => $appConfig['db_user'],
            'password' => $appConfig['db_password'],
            'hostport' => $appConfig['db_port'],
            'charset'  => 'utf8mb4',
            'prefix'   => '',
            'deploy'   => 0,
            'rw_separate' => false,
            'strict'   => true,
            'break_reconnect' => true,
        ],
    ],
];
