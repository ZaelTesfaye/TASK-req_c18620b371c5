<?php
namespace app\controller;

use app\common\ResponseHelper;
use app\service\DashboardService;
use think\Request;

/**
 * DashboardController - Operational and analytics dashboards with CSV export.
 */
class DashboardController
{
    /**
     * Resolve store_id: admins may override from request, others are scoped to their session store.
     */
    private function resolveStoreId(Request $request): int
    {
        $userContext = $request->userContext;
        if (in_array('administrator', $userContext['roles'] ?? []) && $request->get('store_id')) {
            return (int) $request->get('store_id');
        }
        return (int) $userContext['store_id'];
    }

    /**
     * GET /dashboards/operations
     */
    public function operations(Request $request)
    {
        $storeId = $this->resolveStoreId($request);
        $from = $request->get('from', '');
        $to = $request->get('to', '');

        if (empty($from) || empty($to)) {
            $resp = ResponseHelper::validationError('from and to date parameters are required (MM/DD/YYYY)');
            return json($resp['data'], $resp['code']);
        }

        $metrics = DashboardService::getOperationsMetrics($storeId, $from, $to);

        if (isset($metrics['error'])) {
            $resp = ResponseHelper::validationError($metrics['error']);
            return json($resp['data'], $resp['code']);
        }

        $resp = ResponseHelper::success($metrics);
        return json($resp['data'], $resp['code']);
    }

    /**
     * GET /dashboards/operations/export.csv
     */
    public function exportOperationsCsv(Request $request)
    {
        $storeId = $this->resolveStoreId($request);
        $from = $request->get('from', '');
        $to = $request->get('to', '');

        if (empty($from) || empty($to)) {
            $resp = ResponseHelper::validationError('from and to date parameters are required (MM/DD/YYYY)');
            return json($resp['data'], $resp['code']);
        }

        $csv = DashboardService::exportOperationsCsv($storeId, $from, $to);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="operations_dashboard.csv"',
        ]);
    }

    /**
     * GET /dashboards/analytics
     */
    public function analytics(Request $request)
    {
        $storeId = $this->resolveStoreId($request);
        $from = $request->get('from', '');
        $to = $request->get('to', '');

        if (empty($from) || empty($to)) {
            $resp = ResponseHelper::validationError('from and to date parameters are required (MM/DD/YYYY)');
            return json($resp['data'], $resp['code']);
        }

        $metrics = DashboardService::getAnalyticsMetrics($storeId, $from, $to);

        if (isset($metrics['error'])) {
            $resp = ResponseHelper::validationError($metrics['error']);
            return json($resp['data'], $resp['code']);
        }

        $resp = ResponseHelper::success($metrics);
        return json($resp['data'], $resp['code']);
    }
}
