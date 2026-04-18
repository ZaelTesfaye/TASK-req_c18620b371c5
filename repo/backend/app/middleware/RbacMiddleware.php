<?php
namespace app\middleware;

use app\logging\Logger;

/**
 * RbacMiddleware - Enforces role-based access control at route level.
 * Configured per-route with allowed roles.
 */
class RbacMiddleware
{
    /**
     * Allowed roles are passed as middleware parameter
     * Usage in route: ->middleware('rbac', ['roles' => ['administrator', 'front_desk']])
     */
    public function handle($request, \Closure $next, array $config = [])
    {
        $userContext = $request->userContext ?? null;

        if (!$userContext) {
            return json([
                'success' => false,
                'error_code' => 'UNAUTHORIZED',
                'message' => 'Authentication required',
                'request_id' => bin2hex(random_bytes(16)),
            ], 401);
        }

        $allowedRoles = $config['roles'] ?? [];
        $userRoles = $userContext['roles'] ?? [];

        if (!empty($allowedRoles) && empty(array_intersect($userRoles, $allowedRoles))) {
            Logger::security('access_denied', 'Role-based access denied', [
                'user_id' => $userContext['user_id'],
                'user_roles' => $userRoles,
                'required_roles' => $allowedRoles,
                'path' => $request->pathinfo(),
                'method' => $request->method(),
            ]);

            return json([
                'success' => false,
                'error_code' => 'FORBIDDEN',
                'message' => 'You do not have permission to access this resource',
                'request_id' => bin2hex(random_bytes(16)),
            ], 403);
        }

        return $next($request);
    }
}
