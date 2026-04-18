<?php
namespace app\service;

use app\logging\Logger;
use think\facade\Db;

/**
 * AuditService - Immutable, append-only operation logging.
 * Logs are never updated or deleted at the application layer.
 * Captures: user, role, store/workstation, timestamp, action, before/after values.
 * Retention: >= 7 years.
 */
class AuditService
{
    /**
     * Write an immutable audit log entry.
     */
    public static function log(
        ?int $actorUserId,
        string $actorRoleCode,
        int $storeId,
        int $workstationId,
        string $action,
        string $entityType,
        ?string $entityId = null,
        ?array $before = null,
        ?array $after = null,
        $request = null
    ): int {
        $redactedBefore = $before ? Logger::redactSensitive($before) : null;
        $redactedAfter = $after ? Logger::redactSensitive($after) : null;

        $requestId = '';
        $ip = '';
        $userAgent = '';

        if ($request) {
            $requestId = $request->header('X-Request-Id', bin2hex(random_bytes(16)));
            $ip = $request->ip() ?? '';
            $userAgent = $request->header('User-Agent', '');
        } else {
            $requestId = bin2hex(random_bytes(16));
        }

        $id = Db::table('operation_logs')->insertGetId([
            'actor_user_id'  => $actorUserId,
            'actor_role_code' => $actorRoleCode,
            'store_id'       => $storeId,
            'workstation_id' => $workstationId,
            'action'         => $action,
            'entity_type'    => $entityType,
            'entity_id'      => $entityId,
            'before_json'    => $redactedBefore ? json_encode($redactedBefore) : null,
            'after_json'     => $redactedAfter ? json_encode($redactedAfter) : null,
            'request_id'     => $requestId,
            'ip'             => $ip,
            'user_agent'     => $userAgent,
            'created_at'     => date('Y-m-d H:i:s'),
        ]);

        Logger::audit('operation', "Audit log created: {$action} on {$entityType}", [
            'audit_log_id' => $id,
            'action'       => $action,
            'entity_type'  => $entityType,
            'entity_id'    => $entityId,
        ]);

        return $id;
    }

    /**
     * Search audit logs with filters and pagination.
     */
    public static function search(array $filters, int $page = 1, int $pageSize = 20): array
    {
        $query = Db::table('operation_logs');

        if (!empty($filters['user_id'])) {
            $query->where('actor_user_id', $filters['user_id']);
        }
        if (!empty($filters['role_code'])) {
            $query->where('actor_role_code', $filters['role_code']);
        }
        if (!empty($filters['store_id'])) {
            $query->where('store_id', $filters['store_id']);
        }
        if (!empty($filters['workstation_id'])) {
            $query->where('workstation_id', $filters['workstation_id']);
        }
        if (!empty($filters['action'])) {
            $query->where('action', 'like', '%' . $filters['action'] . '%');
        }
        if (!empty($filters['entity_type'])) {
            $query->where('entity_type', $filters['entity_type']);
        }
        if (!empty($filters['entity_id'])) {
            $query->where('entity_id', $filters['entity_id']);
        }
        if (!empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        $total = (clone $query)->count();
        $items = $query->order('created_at', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return [
            'items'     => $items,
            'total'     => $total,
            'page'      => $page,
            'page_size' => $pageSize,
        ];
    }
}
