<?php
namespace app\middleware;

use app\logging\Logger;

/**
 * RequestLogMiddleware - Logs every incoming request and response status.
 */
class RequestLogMiddleware
{
    public function handle($request, \Closure $next)
    {
        $startTime = microtime(true);
        $requestId = $request->header('X-Request-Id', bin2hex(random_bytes(16)));

        Logger::info('access', 'request', 'Incoming request', [
            'request_id' => $requestId,
            'method' => $request->method(),
            'path' => $request->pathinfo(),
            'ip' => $request->ip(),
        ]);

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Logger::error('access', 'exception', 'Unhandled exception: ' . $e->getMessage(), [
                'request_id' => $requestId,
                'path' => $request->pathinfo(),
                'duration_ms' => $duration,
                'exception_class' => get_class($e),
            ]);

            return json([
                'success' => false,
                'error_code' => 'INTERNAL_ERROR',
                'message' => 'An internal error occurred',
                'request_id' => $requestId,
            ], 500);
        }

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        Logger::info('access', 'response', 'Request completed', [
            'request_id' => $requestId,
            'method' => $request->method(),
            'path' => $request->pathinfo(),
            'status' => $response->getCode(),
            'duration_ms' => $duration,
        ]);

        return $response;
    }
}
