<?php
/**
 * Global Middleware Configuration
 */
return [
    'global' => [
        \app\middleware\CorsMiddleware::class,
        \app\middleware\RequestLogMiddleware::class,
    ],
    'alias' => [
        'auth'  => \app\middleware\AuthMiddleware::class,
        'rbac'  => \app\middleware\RbacMiddleware::class,
        'audit' => \app\middleware\AuditMiddleware::class,
    ],
];
