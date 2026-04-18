<?php
namespace app\middleware;

use app\service\AuthService;
use app\logging\Logger;

/**
 * AuthMiddleware - Validates session token on protected routes.
 * Extracts user context and injects into request for downstream use.
 */
class AuthMiddleware
{
    public function handle($request, \Closure $next)
    {
        $authHeader = $request->header('Authorization', '');
        $token = '';

        if (str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
        }

        if (empty($token)) {
            Logger::warning('auth', 'missing_token', 'No auth token provided', [
                'path' => $request->pathinfo(),
                'method' => $request->method(),
            ]);

            return json([
                'success' => false,
                'error_code' => 'UNAUTHORIZED',
                'message' => 'Authentication required',
                'request_id' => bin2hex(random_bytes(16)),
            ], 401);
        }

        $session = AuthService::validateSession($token);

        if (!$session) {
            Logger::warning('auth', 'invalid_session', 'Invalid or expired session token', [
                'path' => $request->pathinfo(),
            ]);

            return json([
                'success' => false,
                'error_code' => 'UNAUTHORIZED',
                'message' => 'Invalid or expired session',
                'request_id' => bin2hex(random_bytes(16)),
            ], 401);
        }

        // Inject session context into request
        $request->userContext = $session;

        return $next($request);
    }
}
