<?php
namespace app\controller;

use app\common\ResponseHelper;
use app\service\AuditService;
use think\facade\Db;
use think\Request;

/**
 * AuditController - Read-only access to operation audit logs and security events.
 */
class AuditController
{
    /**
     * GET /audit/logs
     * Searchable audit log listing with filters and pagination.
     */
    public function logs(Request $request)
    {
        $userContext = $request->userContext;

        // Non-admin users can only see audit logs for their own store
        if (in_array('administrator', $userContext['roles'] ?? []) && $request->get('store_id')) {
            $storeId = $request->get('store_id');
        } else {
            $storeId = $userContext['store_id'];
        }

        $filters = [
            'user_id'        => $request->get('user_id'),
            'role_code'      => $request->get('role_code'),
            'store_id'       => $storeId,
            'workstation_id' => $request->get('workstation_id'),
            'action'         => $request->get('action'),
            'entity_type'    => $request->get('entity_type'),
            'entity_id'      => $request->get('entity_id'),
            'from'           => $request->get('from'),
            'to'             => $request->get('to'),
        ];

        $page = max(1, (int) $request->get('page', 1));
        $pageSize = max(1, min(100, (int) $request->get('page_size', 20)));

        $result = AuditService::search($filters, $page, $pageSize);

        $resp = ResponseHelper::paginated($result['items'], $result['total'], $result['page'], $result['page_size']);
        return json($resp['data'], $resp['code']);
    }

    /**
     * GET /security/events
     * List security events with optional filters.
     */
    public function securityEvents(Request $request)
    {
        $page = max(1, (int) $request->get('page', 1));
        $pageSize = max(1, min(100, (int) $request->get('page_size', 20)));
        $eventType = $request->get('event_type');
        $userId = $request->get('user_id');
        $from = $request->get('from');
        $to = $request->get('to');

        $query = Db::table('security_events');

        if ($eventType) {
            $query->where('event_type', $eventType);
        }
        if ($userId) {
            $query->where('user_id', (int) $userId);
        }
        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to);
        }

        $total = (clone $query)->count();
        $items = $query->order('created_at', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $resp = ResponseHelper::paginated($items, $total, $page, $pageSize);
        return json($resp['data'], $resp['code']);
    }
}
