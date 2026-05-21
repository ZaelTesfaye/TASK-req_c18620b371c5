<?php
namespace app;

use app\common\AppConfig;
use app\logging\Logger;
use think\Response;
use think\exception\Handle;

/**
 * Application-level exception handler. ThinkPHP 6 picks this class up by
 * convention (class `app\ExceptionHandle`) and routes every unhandled
 * throwable through render() before sending the response.
 *
 * The framework default renders a localized HTML diagnostic page that
 * leaks framework internals and breaks `fetch()` callers expecting
 * JSON. This override forces a clean English JSON envelope so the
 * login page can display a user-facing error string instead of
 * choking on HTML.
 */
class ExceptionHandle extends Handle
{
    /**
     * @param \think\Request    $request
     * @param \Throwable        $e
     */
    public function render($request, \Throwable $e): Response
    {
        $requestId = bin2hex(random_bytes(8));

        try {
            Logger::error('exception', 'unhandled', $e->getMessage(), [
                'request_id' => $requestId,
                'exception_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'path' => $request ? $request->pathinfo() : null,
            ]);
        } catch (\Throwable $logFailure) {
            // Never let logging mask the original error. Falling through
            // to the JSON response below is more important than logging.
        }

        $isDebug = false;
        try {
            $isDebug = AppConfig::isDebug();
        } catch (\Throwable $ignored) {
        }

        $payload = [
            'success'    => false,
            'error_code' => 'INTERNAL_ERROR',
            'message'    => $isDebug ? $e->getMessage() : 'An internal error occurred',
            'request_id' => $requestId,
        ];

        if ($isDebug) {
            $payload['debug'] = [
                'exception' => get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ];
        }

        $status = method_exists($e, 'getStatusCode') ? (int) $e->getStatusCode() : 500;
        if ($status < 400 || $status > 599) {
            $status = 500;
        }

        return Response::create($payload, 'json', $status);
    }
}
