<?php
namespace app\common;

/**
 * Standardized API response helper
 * Success: { "success": true, "data": ..., "request_id": "..." }
 * Error: { "success": false, "error_code": "...", "message": "...", "fields": {...}, "request_id": "..." }
 * Pagination: include page, page_size, total, items
 */
class ResponseHelper
{
    public static function success(mixed $data = null, int $statusCode = 200): array
    {
        return [
            'code'       => $statusCode,
            'data'       => [
                'success'    => true,
                'data'       => $data,
                'request_id' => self::getRequestId(),
            ],
        ];
    }

    public static function paginated(array $items, int $total, int $page, int $pageSize): array
    {
        return [
            'code' => 200,
            'data' => [
                'success'    => true,
                'data'       => [
                    'items'     => $items,
                    'total'     => $total,
                    'page'      => $page,
                    'page_size' => $pageSize,
                ],
                'request_id' => self::getRequestId(),
            ],
        ];
    }

    public static function error(string $errorCode, string $message, int $statusCode = 400, array $fields = []): array
    {
        $data = [
            'success'    => false,
            'error_code' => $errorCode,
            'message'    => $message,
            'request_id' => self::getRequestId(),
        ];
        if (!empty($fields)) {
            $data['fields'] = $fields;
        }
        return [
            'code' => $statusCode,
            'data' => $data,
        ];
    }

    public static function validationError(string $message, array $fields = []): array
    {
        return self::error('VALIDATION_ERROR', $message, 400, $fields);
    }

    public static function unauthorized(string $message = 'Authentication required'): array
    {
        return self::error('UNAUTHORIZED', $message, 401);
    }

    public static function forbidden(string $message = 'Access denied'): array
    {
        return self::error('FORBIDDEN', $message, 403);
    }

    public static function notFound(string $message = 'Resource not found'): array
    {
        return self::error('NOT_FOUND', $message, 404);
    }

    public static function conflict(string $message = 'Conflict'): array
    {
        return self::error('CONFLICT', $message, 409);
    }

    public static function internalError(string $message = 'Internal server error'): array
    {
        return self::error('INTERNAL_ERROR', $message, 500);
    }

    private static function getRequestId(): string
    {
        static $requestId = null;
        if ($requestId === null) {
            $requestId = bin2hex(random_bytes(16));
        }
        return $requestId;
    }
}
