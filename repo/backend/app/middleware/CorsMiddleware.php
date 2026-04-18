<?php
namespace app\middleware;

use app\common\AppConfig;

/**
 * CorsMiddleware - Handles Cross-Origin Resource Sharing headers.
 *
 * The allowed origins list is read from AppConfig (cors_allowed_origins).
 * Origins not on the allowlist get no Access-Control-Allow-Origin header,
 * which causes the browser to block the response. Wildcard * is intentionally
 * not supported.
 */
class CorsMiddleware
{
    private const ALLOWED_METHODS = 'GET, POST, PATCH, PUT, DELETE, OPTIONS';
    private const ALLOWED_HEADERS = 'Content-Type, Authorization, X-Request-Id';

    public function handle($request, \Closure $next)
    {
        $origin = $request->header('origin', '');
        $allowed = $this->allowedOrigins();
        $allowOrigin = in_array($origin, $allowed, true) ? $origin : null;

        if ($request->method(true) === 'OPTIONS') {
            $headers = [
                'Access-Control-Allow-Methods' => self::ALLOWED_METHODS,
                'Access-Control-Allow-Headers' => self::ALLOWED_HEADERS,
                'Access-Control-Max-Age'       => '86400',
                'Vary'                         => 'Origin',
            ];
            if ($allowOrigin !== null) {
                $headers['Access-Control-Allow-Origin'] = $allowOrigin;
            }
            return response('', 204)->header($headers);
        }

        $response = $next($request);

        $headers = [
            'Access-Control-Allow-Methods' => self::ALLOWED_METHODS,
            'Access-Control-Allow-Headers' => self::ALLOWED_HEADERS,
            'Vary'                         => 'Origin',
        ];
        if ($allowOrigin !== null) {
            $headers['Access-Control-Allow-Origin'] = $allowOrigin;
        }

        $response->header($headers);
        return $response;
    }

    private function allowedOrigins(): array
    {
        $origins = AppConfig::get('cors_allowed_origins', []);
        if (!is_array($origins)) {
            return [];
        }
        // Reject wildcards that may have slipped into configuration.
        return array_values(array_filter($origins, function ($o) {
            return is_string($o) && $o !== '' && $o !== '*';
        }));
    }
}
