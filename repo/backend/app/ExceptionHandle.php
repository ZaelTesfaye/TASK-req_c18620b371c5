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

        $status = method_exists($e, 'getStatusCode') ? (int) $e->getStatusCode() : 500;
        if ($status < 400 || $status > 599) {
            $status = 500;
        }

        // Differentiate HTTP-protocol errors (404/405/etc.) from genuine
        // internal failures. Without this every HttpException came back
        // with error_code=INTERNAL_ERROR even when the real cause was
        // "route not found", which made the login dropdown look like
        // a server crash instead of a routing miss.
        $errorCode = match ($status) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHORIZED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            409 => 'CONFLICT',
            422 => 'VALIDATION_ERROR',
            default => 'INTERNAL_ERROR',
        };

        $defaultMessage = match ($status) {
            404 => 'Resource not found',
            405 => 'Method not allowed',
            default => 'An internal error occurred',
        };

        $payload = [
            'success'    => false,
            'error_code' => $errorCode,
            'message'    => $isDebug ? $e->getMessage() : $defaultMessage,
            'request_id' => $requestId,
        ];

        // For 404s, surface the requested path so a misrouted call from
        // the frontend (wrong prefix, missing trailing segment, etc.) is
        // immediately diagnosable from the network panel instead of
        // requiring server log access. Safe to expose: the URL is
        // already known to the caller.
        if ($status === 404 && $request) {
            $payload['path'] = $request->pathinfo();
            $payload['method'] = $request->method();
        }

        if ($isDebug) {
            $payload['debug'] = [
                'exception' => get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ];
        }

        return Response::create($payload, 'json', $status);
    }
}
