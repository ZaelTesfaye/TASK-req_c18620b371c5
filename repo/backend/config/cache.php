<?php
/**
 * Cache Configuration
 *
 * ThinkPHP 6's `think\Manager` base class resolves a cache driver by
 * reading `default` from this file. If the file is missing (or `default`
 * is null), any framework component that touches the cache subsystem —
 * including some internal ORM paths — throws
 *   [InvalidArgumentException] Unable to resolve NULL driver for [think\Cache]
 * during bootstrap, killing the PHP worker before a single request runs.
 *
 * No application code uses the cache directly; this config just ensures
 * the framework's default path resolves to a safe file-backed store
 * under runtime/cache (created by the Dockerfile). Swap `default` to
 * `redis` and add a store entry below when we move to a multi-worker
 * deployment.
 */

return [
    'default' => 'file',

    'stores' => [
        'file' => [
            'type'      => 'File',
            'path'      => '',
            'prefix'    => '',
            'expire'    => 0,
            'serialize' => [],
        ],
    ],
];
