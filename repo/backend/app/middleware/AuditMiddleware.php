<?php
namespace app\middleware;

use app\service\AuditService;
use app\logging\Logger;

/**
 * AuditMiddleware - Logs immutable audit records for state-changing operations.
 * Captures actor, role, store/workstation, action, before/after values.
 */
class AuditMiddleware
{
    public function handle($request, \Closure $next)
    {
        $response = $next($request);

        // Only audit state-changing methods
        $method = strtoupper($request->method());
        if (!in_array($method, ['POST', 'PATCH', 'PUT', 'DELETE'])) {
            return $response;
        }

        try {
            $userContext = $request->userContext ?? null;
            $auditData = $request->auditData ?? null;

            if ($userContext && $auditData) {
                AuditService::log(
                    $userContext['user_id'] ?? null,
                    $userContext['roles'][0] ?? 'unknown',
                    $userContext['store_id'] ?? 0,
                    $userContext['workstation_id'] ?? 0,
                    $auditData['action'] ?? $request->pathinfo(),
                    $auditData['entity_type'] ?? 'unknown',
                    $auditData['entity_id'] ?? null,
                    $auditData['before'] ?? null,
                    $auditData['after'] ?? null,
                    $request
                );
            }
        } catch (\Throwable $e) {
            Logger::error('audit', 'middleware', 'Failed to write audit log: ' . $e->getMessage());
        }

        return $response;
    }
}
