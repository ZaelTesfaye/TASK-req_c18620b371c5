<?php
/**
 * Log Configuration (ThinkPHP framework log channel).
 *
 * Same shape as config/cache.php: `think\Manager` resolves a driver by
 * reading `default` from this file. Without it, ANY internal component
 * that touches the framework log — most importantly `think-orm`'s
 * DbManager, which logs every SQL query via `Log::record()` — throws
 *   [InvalidArgumentException] Unable to resolve NULL driver for [think\Log]
 * mid-query, which surfaces as a 500 on every DB-backed endpoint
 * (login, admin/users, bootstrap/stores, everything).
 *
 * This is separate from our application-level `app\logging\Logger`,
 * which writes human-readable structured lines to
 * `storage/logs/app_<date>.log`. The framework channel below is only
 * used by internal ThinkPHP code; point it at the same runtime/log
 * directory so stray framework messages are easy to find if they fire.
 */

return [
    'default' => 'file',

    'channels' => [
        'file' => [
            'type'        => 'File',
            'path'        => '',
            'apart_level' => [],
            'max_files'   => 7,
            'json'        => false,
            'level'       => [],
        ],
    ],
];
