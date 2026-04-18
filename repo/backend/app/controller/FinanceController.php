<?php
namespace app\controller;

use app\common\ResponseHelper;
use app\service\FinanceService;
use think\Request;

/**
 * FinanceController - Cash drawer management, reconciliation statements, and exception reporting.
 */
class FinanceController
{
    /**
     * GET /finance/cash-drawer/daily
     */
    public function getDailyDrawer(Request $request)
    {
        // Enforce store_id from session context - never trust request body
        $storeId = (int) ($request->userContext['store_id'] ?? $request->get('store_id', 0));
        $date = $request->get('date', '');

        if ($storeId <= 0 || empty($date)) {
            $resp = ResponseHelper::validationError('store_id and date are required');
            return json($resp['data'], $resp['code']);
        }

        $drawer = FinanceService::getDailyDrawer($storeId, $date);

        if (!$drawer) {
            $resp = ResponseHelper::notFound('No cash drawer found for the specified store and date');
            return json($resp['data'], $resp['code']);
        }

        $resp = ResponseHelper::success($drawer);
        return json($resp['data'], $resp['code']);
    }

    /**
     * POST /finance/cash-drawer
     */
    public function openDrawer(Request $request)
    {
        $userContext = $request->userContext;
        // Enforce store_id from session context
        $storeId = (int) $userContext['store_id'];
        $businessDate = $request->post('business_date', '');
        $openAmount = (float) $request->post('open_amount', 0);

        if ($storeId <= 0 || empty($businessDate)) {
            $resp = ResponseHelper::validationError('store_id and business_date are required');
            return json($resp['data'], $resp['code']);
        }

        $result = FinanceService::openDrawer($storeId, $businessDate, $openAmount, $userContext);

        if (!$result['success']) {
            $status = $result['status'] ?? 400;
            $resp = ResponseHelper::error($result['error_code'], $result['message'], $status);
            return json($resp['data'], $resp['code']);
        }

        $request->auditData = [
            'action'      => 'finance.open_drawer',
            'entity_type' => 'cash_drawer',
            'entity_id'   => $result['data']['id'],
            'before'      => null,
            'after'       => ['store_id' => $storeId, 'business_date' => $businessDate, 'open_amount' => $openAmount],
        ];

        $resp = ResponseHelper::success($result['data'], 201);
        return json($resp['data'], $resp['code']);
    }

    /**
     * POST /finance/cash-drawer/{id}/close
     */
    public function closeDrawer(Request $request, int $id)
    {
        $userContext = $request->userContext;

        // Store ownership check: verify drawer belongs to user's store
        $drawer = \think\facade\Db::table('cash_drawer_daily')->where('id', $id)->find();
        if ($drawer && !in_array('administrator', $userContext['roles'] ?? [])
            && $drawer['store_id'] != $userContext['store_id']) {
            $resp = ResponseHelper::forbidden('Access denied: drawer belongs to a different store');
            return json($resp['data'], $resp['code']);
        }

        $countedTotal = (float) $request->post('counted_total', 0);

        $result = FinanceService::closeDrawer($id, $countedTotal, $userContext);

        if (!$result['success']) {
            $status = $result['status'] ?? 400;
            $resp = ResponseHelper::error($result['error_code'], $result['message'], $status);
            return json($resp['data'], $resp['code']);
        }

        $request->auditData = [
            'action'      => 'finance.close_drawer',
            'entity_type' => 'cash_drawer',
            'entity_id'   => $id,
            'before'      => $result['before'] ?? null,
            'after'       => $result['data'],
        ];

        $resp = ResponseHelper::success($result['data']);
        return json($resp['data'], $resp['code']);
    }

    /**
     * POST /finance/cash-drawer/{id}/reopen
     */
    public function reopenDrawer(Request $request, int $id)
    {
        $userContext = $request->userContext;

        // Store ownership check for non-admin users
        $drawer = \think\facade\Db::table('cash_drawer_daily')->where('id', $id)->find();
        if ($drawer && !in_array('administrator', $userContext['roles'] ?? [])
            && $drawer['store_id'] != $userContext['store_id']) {
            $resp = ResponseHelper::forbidden('Access denied: drawer belongs to a different store');
            return json($resp['data'], $resp['code']);
        }

        $reason = $request->post('reason', '');

        if (empty(trim($reason))) {
            $resp = ResponseHelper::validationError('reason is required to reopen a closed drawer');
            return json($resp['data'], $resp['code']);
        }

        $result = FinanceService::reopenDrawer($id, $reason, $userContext);

        if (!$result['success']) {
            $status = $result['status'] ?? 400;
            $resp = ResponseHelper::error($result['error_code'], $result['message'], $status);
            return json($resp['data'], $resp['code']);
        }

        $request->auditData = [
            'action'      => 'finance.reopen_drawer',
            'entity_type' => 'cash_drawer',
            'entity_id'   => $id,
            'before'      => $result['before'] ?? null,
            'after'       => $result['data'],
        ];

        $resp = ResponseHelper::success($result['data']);
        return json($resp['data'], $resp['code']);
    }

    /**
     * GET /finance/reconciliation/exceptions
     */
    public function getExceptions(Request $request)
    {
        $userContext = $request->userContext;
        // Enforce store_id from session; admins may override
        if (in_array('administrator', $userContext['roles'] ?? []) && $request->get('store_id')) {
            $storeId = (int) $request->get('store_id');
        } else {
            $storeId = (int) $userContext['store_id'];
        }

        if ($storeId <= 0) {
            $resp = ResponseHelper::validationError('store_id is required');
            return json($resp['data'], $resp['code']);
        }

        $exceptions = FinanceService::getExceptions($storeId);

        $resp = ResponseHelper::success($exceptions);
        return json($resp['data'], $resp['code']);
    }

    /**
     * GET /finance/reconciliation/{id}/statement
     */
    public function getReconciliationStatement(Request $request, int $id)
    {
        $userContext = $request->userContext;

        // Verify drawer belongs to user's store
        $drawer = \think\facade\Db::table('cash_drawer_daily')->where('id', $id)->find();
        if ($drawer && !in_array('administrator', $userContext['roles'] ?? [])
            && $drawer['store_id'] != $userContext['store_id']) {
            $resp = ResponseHelper::forbidden('Access denied: drawer belongs to a different store');
            return json($resp['data'], $resp['code']);
        }

        $statement = FinanceService::getReconciliationStatement($id);

        if (!$statement) {
            $resp = ResponseHelper::notFound('Reconciliation statement not found');
            return json($resp['data'], $resp['code']);
        }

        $resp = ResponseHelper::success($statement);
        return json($resp['data'], $resp['code']);
    }

    /**
     * GET /finance/reconciliation/{id}/statement.csv
     */
    public function getReconciliationStatementCsv(Request $request, int $id)
    {
        $userContext = $request->userContext;

        // Verify drawer belongs to user's store
        $drawer = \think\facade\Db::table('cash_drawer_daily')->where('id', $id)->find();
        if ($drawer && !in_array('administrator', $userContext['roles'] ?? [])
            && $drawer['store_id'] != $userContext['store_id']) {
            $resp = ResponseHelper::forbidden('Access denied: drawer belongs to a different store');
            return json($resp['data'], $resp['code']);
        }

        $csv = FinanceService::getReconciliationStatementCsv($id);

        if ($csv === null) {
            $resp = ResponseHelper::notFound('Reconciliation statement not found');
            return json($resp['data'], $resp['code']);
        }

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="reconciliation_' . $id . '.csv"',
        ]);
    }
}
