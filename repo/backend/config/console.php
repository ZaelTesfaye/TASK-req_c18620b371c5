<?php
/**
 * ThinkPHP console command registration.
 *
 * Each entry maps a `php think <name>` invocation to a Command class. The
 * audit:archive command is how the scheduler triggers the 7-year audit log
 * retention job — see backend/config/schedule.php for the declarative
 * schedule that the deployment cron picks up.
 */
return [
    'commands' => [
        'audit:archive' => \app\command\AuditArchivalCommand::class,
    ],
];
