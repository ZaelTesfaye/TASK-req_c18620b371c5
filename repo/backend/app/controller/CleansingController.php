<?php
namespace app\controller;

use app\common\ResponseHelper;
use app\service\CleansingService;
use think\Request;

/**
 * CleansingController - Data cleansing pipeline: import, list batches, preview,
 * approve, rollback, and manual review queue.
 */
class CleansingController
{
    /**
     * POST /cleansing/import
     */
    public function import(Request $request)
    {
        $userContext = $request->userContext;
        $data = $request->post();

        if (empty($data['source_name']) || empty($data['rows'])) {
            $resp = ResponseHelper::validationError('source_name and rows are required');
            return json($resp['data'], $resp['code']);
        }

        $result = CleansingService::importBatch($data, $userContext);

        if (!$result['success']) {
            $resp = ResponseHelper::error($result['error_code'], $result['message'], 500);
            return json($resp['data'], $resp['code']);
        }

        $request->auditData = [
            'action'      => 'cleansing.import',
            'entity_type' => 'cleansing_batch',
            'entity_id'   => $result['data']['batch_id'],
            'before'      => null,
            'after'       => $result['data'],
        ];

        $resp = ResponseHelper::success($result['data'], 201);
        return json($resp['data'], $resp['code']);
    }

    /**
     * GET /cleansing/batches
     */
    public function listBatches(Request $request)
    {
        $userContext = $request->userContext;
        $page = max(1, (int) $request->get('page', 1));
        $pageSize = max(1, min(100, (int) $request->get('page_size', 20)));

        // Non-admin users can only see their store's batches
        $storeId = null;
        if (!in_array('administrator', $userContext['roles'] ?? [])) {
            $storeId = (int) $userContext['store_id'];
        }

        $filters = [
            'status' => $request->get('status', ''),
            'store_id' => $storeId,
        ];

        $result = CleansingService::getBatches($filters, $page, $pageSize);

        $resp = ResponseHelper::paginated($result['items'], $result['total'], $result['page'], $result['page_size']);
        return json($resp['data'], $resp['code']);
    }

    /**
     * GET /cleansing/batches/{id}/preview
     */
    public function preview(Request $request, int $id)
    {
        $userContext = $request->userContext;

        // Verify batch belongs to user's store
        $batch = \think\facade\Db::table('cleansing_batches')->where('id', $id)->find();
        if ($batch && !in_array('administrator', $userContext['roles'] ?? [])
            && $batch['store_id'] != $userContext['store_id']) {
            $resp = ResponseHelper::forbidden('Access denied: batch belongs to a different store');
            return json($resp['data'], $resp['code']);
        }

        $preview = CleansingService::getBatchPreview($id);

        $resp = ResponseHelper::success($preview);
        return json($resp['data'], $resp['code']);
    }

    /**
     * POST /cleansing/batches/{id}/approve
     */
    public function approve(Request $request, int $id)
    {
        $userContext = $request->userContext;
        $result = CleansingService::approveBatch($id, $userContext);

        if (!$result['success']) {
            $status = $result['status'] ?? 400;
            $resp = ResponseHelper::error($result['error_code'], $result['message'], $status);
            return json($resp['data'], $resp['code']);
        }

        $request->auditData = [
            'action'      => 'cleansing.approve',
            'entity_type' => 'cleansing_batch',
            'entity_id'   => $id,
            'before'      => ['status' => 'pending_review'],
            'after'       => $result['data'],
        ];

        $resp = ResponseHelper::success($result['data']);
        return json($resp['data'], $resp['code']);
    }

    /**
     * POST /cleansing/batches/{id}/rollback
     */
    public function rollback(Request $request, int $id)
    {
        $userContext = $request->userContext;
        $result = CleansingService::rollbackBatch($id, $userContext);

        if (!$result['success']) {
            $status = $result['status'] ?? 400;
            $resp = ResponseHelper::error($result['error_code'], $result['message'], $status);
            return json($resp['data'], $resp['code']);
        }

        $request->auditData = [
            'action'      => 'cleansing.rollback',
            'entity_type' => 'cleansing_batch',
            'entity_id'   => $id,
            'before'      => null,
            'after'       => $result['data'],
        ];

        $resp = ResponseHelper::success($result['data']);
        return json($resp['data'], $resp['code']);
    }

    /**
     * GET /cleansing/review-queue
     */
    public function reviewQueue(Request $request)
    {
        $userContext = $request->userContext;
        $page = max(1, (int) $request->get('page', 1));
        $pageSize = max(1, min(100, (int) $request->get('page_size', 20)));

        // Non-admin users can only see review items for their store's batches
        $storeId = null;
        if (!in_array('administrator', $userContext['roles'] ?? [])) {
            $storeId = (int) $userContext['store_id'];
        }

        $result = CleansingService::getManualReviewQueue($page, $pageSize, $storeId);

        $resp = ResponseHelper::paginated($result['items'], $result['total'], $result['page'], $result['page_size']);
        return json($resp['data'], $resp['code']);
    }
}
