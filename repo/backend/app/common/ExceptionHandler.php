<?php
namespace app\common;

use app\logging\Logger;

/**
 * ExceptionHandler - Global exception handler for unhandled errors.
 * Returns standardized error responses; never exposes stack traces in production.
 */
class ExceptionHandler
{
    public static function handle(\Throwable $e): array
    {
        $requestId = bin2hex(random_bytes(16));

        Logger::error('exception', 'unhandled', $e->getMessage(), [
            'request_id' => $requestId,
            'exception_class' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        $isDebug = AppConfig::isDebug();

        $response = [
            'success' => false,
            'error_code' => 'INTERNAL_ERROR',
            'message' => $isDebug ? $e->getMessage() : 'An internal error occurred',
            'request_id' => $requestId,
        ];

        if ($isDebug) {
            $response['debug'] = [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];
        }

        return $response;
    }

    public static function register(): void
    {
        set_exception_handler(function (\Throwable $e) {
            $response = self::handle($e);
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode($response);
            exit;
        });

        set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) {
            Logger::error('php_error', 'handler', $errstr, [
                'errno' => $errno,
                'file' => $errfile,
                'line' => $errline,
            ]);
            return true;
        });
    }
}
