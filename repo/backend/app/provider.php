<?php
/**
 * Application container bindings. ThinkPHP 6 auto-loads this file during
 * application initialization (see think\App::load()), so any class
 * substitutions registered here apply before the request pipeline runs.
 *
 * We rebind think\exception\Handle to our app\ExceptionHandle so every
 * unhandled throwable — including ones that escape the middleware chain
 * (e.g., RequestLogMiddleware's Logger::info call sits outside its own
 * try/catch) — produces a clean English JSON envelope instead of the
 * framework's localized HTML diagnostic page.
 */

use app\ExceptionHandle;
use think\exception\Handle;

return [
    Handle::class => ExceptionHandle::class,
    'think\exception\Handle' => ExceptionHandle::class,
];
