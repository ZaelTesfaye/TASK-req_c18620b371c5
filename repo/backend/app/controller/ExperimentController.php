<?php
namespace app\controller;

use app\common\ResponseHelper;
use app\service\ExperimentService;
use think\facade\Db;
use think\Request;

/**
 * ExperimentController - A/B experiment CRUD, start/stop lifecycle, and assignment retrieval.
 */
class ExperimentController
{
    /**
     * POST /experiments
     */
    public function create(Request $request)
    {
        $userContext = $request->userContext;
        $data = $request->post();

        if (empty($data['key']) || empty($data['name'])) {
            $resp = ResponseHelper::validationError('key and name are required');
            return json($resp['data'], $resp['code']);
        }

        $result = ExperimentService::create($data, $userContext);

        if (!$result['success']) {
            $resp = ResponseHelper::error($result['error_code'], $result['message'], 500);
            return json($resp['data'], $resp['code']);
        }

        $request->auditData = [
            'action'      => 'experiment.create',
            'entity_type' => 'experiment',
            'entity_id'   => $result['data']['id'],
            'before'      => null,
            'after'       => $data,
        ];

        $resp = ResponseHelper::success($result['data'], 201);
        return json($resp['data'], $resp['code']);
    }

    /**
     * GET /experiments
     */
    public function list(Request $request)
    {
        $page = max(1, (int) $request->get('page', 1));
        $pageSize = max(1, min(100, (int) $request->get('page_size', 20)));
        $status = $request->get('status');

        $query = Db::table('experiments');
        if ($status) {
            $query->where('status', $status);
        }

        $total = (clone $query)->count();
        $items = $query->order('created_at', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $resp = ResponseHelper::paginated($items, $total, $page, $pageSize);
        return json($resp['data'], $resp['code']);
    }

    /**
     * GET /experiments/{id}
     */
    public function read(Request $request, int $id)
    {
        $experiment = Db::table('experiments')->where('id', $id)->find();

        if (!$experiment) {
            $resp = ResponseHelper::notFound('Experiment not found');
            return json($resp['data'], $resp['code']);
        }

        $experiment['variants'] = Db::table('experiment_variants')
            ->where('experiment_id', $id)
            ->select()
            ->toArray();

        $resp = ResponseHelper::success($experiment);
        return json($resp['data'], $resp['code']);
    }

    /**
     * PATCH /experiments/{id}
     */
    public function update(Request $request, int $id)
    {
        $experiment = Db::table('experiments')->where('id', $id)->find();

        if (!$experiment) {
            $resp = ResponseHelper::notFound('Experiment not found');
            return json($resp['data'], $resp['code']);
        }

        if ($experiment['status'] !== 'draft') {
            $resp = ResponseHelper::conflict('Only draft experiments can be updated');
            return json($resp['data'], $resp['code']);
        }

        $before = $experiment;
        $data = $request->put();
        $updateData = [];

        $allowedFields = ['name', 'holdout_percent', 'randomization_unit'];
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        if (!empty($updateData)) {
            $updateData['updated_at'] = date('Y-m-d H:i:s');
            Db::table('experiments')->where('id', $id)->update($updateData);
        }

        $after = Db::table('experiments')->where('id', $id)->find();

        $request->auditData = [
            'action'      => 'experiment.update',
            'entity_type' => 'experiment',
            'entity_id'   => $id,
            'before'      => $before,
            'after'       => $after,
        ];

        $resp = ResponseHelper::success($after);
        return json($resp['data'], $resp['code']);
    }

    /**
     * DELETE /experiments/{id}
     */
    public function delete(Request $request, int $id)
    {
        $experiment = Db::table('experiments')->where('id', $id)->find();

        if (!$experiment) {
            $resp = ResponseHelper::notFound('Experiment not found');
            return json($resp['data'], $resp['code']);
        }

        if ($experiment['status'] === 'running') {
            $resp = ResponseHelper::conflict('Cannot delete a running experiment; stop it first');
            return json($resp['data'], $resp['code']);
        }

        Db::table('experiment_variants')->where('experiment_id', $id)->delete();
        Db::table('experiments')->where('id', $id)->delete();

        $request->auditData = [
            'action'      => 'experiment.delete',
            'entity_type' => 'experiment',
            'entity_id'   => $id,
            'before'      => $experiment,
            'after'       => null,
        ];

        $resp = ResponseHelper::success(['message' => 'Experiment deleted']);
        return json($resp['data'], $resp['code']);
    }

    /**
     * POST /experiments/{id}/start
     */
    public function start(Request $request, int $id)
    {
        $userContext = $request->userContext;
        $result = ExperimentService::start($id, $userContext);

        if (!$result['success']) {
            $status = $result['status'] ?? 400;
            $resp = ResponseHelper::error($result['error_code'], $result['message'], $status);
            return json($resp['data'], $resp['code']);
        }

        $request->auditData = [
            'action'      => 'experiment.start',
            'entity_type' => 'experiment',
            'entity_id'   => $id,
            'before'      => ['status' => 'draft'],
            'after'       => $result['data'],
        ];

        $resp = ResponseHelper::success($result['data']);
        return json($resp['data'], $resp['code']);
    }

    /**
     * POST /experiments/{id}/stop
     */
    public function stop(Request $request, int $id)
    {
        $userContext = $request->userContext;
        $result = ExperimentService::stop($id, $userContext);

        if (!$result['success']) {
            $status = $result['status'] ?? 400;
            $resp = ResponseHelper::error($result['error_code'], $result['message'], $status);
            return json($resp['data'], $resp['code']);
        }

        $request->auditData = [
            'action'      => 'experiment.stop',
            'entity_type' => 'experiment',
            'entity_id'   => $id,
            'before'      => $result['before'] ?? null,
            'after'       => $result['data'],
        ];

        $resp = ResponseHelper::success($result['data']);
        return json($resp['data'], $resp['code']);
    }

    /**
     * GET /experiments/{id}/assignments
     */
    public function assignments(Request $request, int $id)
    {
        $experiment = Db::table('experiments')->where('id', $id)->find();
        if (!$experiment) {
            $resp = ResponseHelper::notFound('Experiment not found');
            return json($resp['data'], $resp['code']);
        }

        $page = max(1, (int) $request->get('page', 1));
        $pageSize = max(1, min(100, (int) $request->get('page_size', 20)));

        $result = ExperimentService::getAssignments($id, $page, $pageSize);

        $resp = ResponseHelper::paginated($result['items'], $result['total'], $result['page'], $result['page_size']);
        return json($resp['data'], $resp['code']);
    }

    /**
     * GET /experiments/{id}/assignment
     * Get or create a sticky assignment for the current user/session.
     */
    public function getAssignment(Request $request, int $id)
    {
        $userContext = $request->userContext;
        $stickyKey = $request->get('sticky_key', 'user:' . $userContext['user_id']);

        $result = ExperimentService::getAssignment($id, $stickyKey);

        $resp = ResponseHelper::success($result);
        return json($resp['data'], $resp['code']);
    }
}
