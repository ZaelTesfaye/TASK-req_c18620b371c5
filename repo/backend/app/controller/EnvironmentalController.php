<?php
namespace app\controller;

use app\common\ResponseHelper;
use app\service\EnvironmentalService;
use think\facade\Db;
use think\Request;

/**
 * EnvironmentalController - Sensor data ingestion (CSV and feed), time-aligned buckets,
 * derived metrics, lineage tracing, and formula version CRUD.
 */
class EnvironmentalController
{
    /**
     * POST /environmental/import-csv
     */
    public function importCsv(Request $request)
    {
        $userContext = $request->userContext;
        $sourceId = (int) $request->post('source_id', 0);
        $records = $request->post('records', []);

        if ($sourceId <= 0 || empty($records)) {
            $resp = ResponseHelper::validationError('source_id and records are required');
            return json($resp['data'], $resp['code']);
        }

        // Verify source belongs to caller's store (non-admin)
        if (!in_array('administrator', $userContext['roles'] ?? [])) {
            $source = Db::table('sensor_sources')->where('id', $sourceId)->find();
            if ($source && $source['store_id'] != $userContext['store_id']) {
                $resp = ResponseHelper::forbidden('Access denied: source belongs to a different store');
                return json($resp['data'], $resp['code']);
            }
        }

        $result = EnvironmentalService::importCsv($records, $sourceId, $userContext);

        if (!$result['success']) {
            $status = 400;
            if (($result['error_code'] ?? '') === 'NOT_FOUND') {
                $status = 404;
            }
            $resp = ResponseHelper::error($result['error_code'], $result['message'], $status);
            return json($resp['data'], $resp['code']);
        }

        $request->auditData = [
            'action'      => 'environmental.import_csv',
            'entity_type' => 'sensor_batch',
            'entity_id'   => $result['data']['batch_id'],
            'before'      => null,
            'after'       => $result['data'],
        ];

        $resp = ResponseHelper::success($result['data'], 201);
        return json($resp['data'], $resp['code']);
    }

    /**
     * POST /environmental/sensor-feed
     */
    public function sensorFeed(Request $request)
    {
        $sourceId = (int) $request->post('source_id', 0);
        $records = $request->post('records', []);

        if ($sourceId <= 0 || empty($records)) {
            $resp = ResponseHelper::validationError('source_id and records are required');
            return json($resp['data'], $resp['code']);
        }

        $result = EnvironmentalService::importSensorFeed($records, $sourceId);

        if (!$result['success']) {
            $status = 400;
            if (($result['error_code'] ?? '') === 'NOT_FOUND') {
                $status = 404;
            }
            $resp = ResponseHelper::error($result['error_code'], $result['message'], $status);
            return json($resp['data'], $resp['code']);
        }

        $request->auditData = [
            'action'      => 'environmental.sensor_feed',
            'entity_type' => 'sensor_feed',
            'entity_id'   => $sourceId,
            'before'      => null,
            'after'       => $result['data'],
        ];

        $resp = ResponseHelper::success($result['data'], 201);
        return json($resp['data'], $resp['code']);
    }

    /**
     * GET /environmental/aligned-buckets
     */
    public function getAlignedBuckets(Request $request)
    {
        $userContext = $request->userContext;
        // Admins may override store_id; all others are scoped to their own store
        if (in_array('administrator', $userContext['roles'] ?? []) && $request->get('store_id')) {
            $storeId = (int) $request->get('store_id');
        } else {
            $storeId = (int) $userContext['store_id'];
        }
        $zoneId = $request->get('zone_id') ? (int) $request->get('zone_id') : null;
        $page = max(1, (int) $request->get('page', 1));
        $pageSize = max(1, min(100, (int) $request->get('page_size', 20)));

        $result = EnvironmentalService::getAlignedBuckets($storeId, $zoneId, $page, $pageSize);

        $resp = ResponseHelper::paginated($result['items'], $result['total'], $result['page'], $result['page_size']);
        return json($resp['data'], $resp['code']);
    }

    /**
     * POST /environmental/align-buckets
     */
    public function alignBuckets(Request $request)
    {
        $userContext = $request->userContext;
        // Force store_id from session for non-admins; reject foreign store_id
        if (in_array('administrator', $userContext['roles'] ?? []) && $request->post('store_id')) {
            $storeId = (int) $request->post('store_id');
        } else {
            $storeId = (int) $userContext['store_id'];
            if ($request->post('store_id') && (int) $request->post('store_id') !== $storeId) {
                $resp = ResponseHelper::forbidden('Access denied: cannot operate on another store');
                return json($resp['data'], $resp['code']);
            }
        }
        $zoneId = $request->post('zone_id') ? (int) $request->post('zone_id') : null;
        $from = $request->post('from', null);
        $to = $request->post('to', null);

        $result = EnvironmentalService::alignBuckets($storeId, $zoneId, $from, $to);

        if (!$result['success']) {
            $resp = ResponseHelper::error($result['error_code'] ?? 'ALIGN_FAILED', $result['message'] ?? 'Alignment failed', 500);
            return json($resp['data'], $resp['code']);
        }

        $request->auditData = [
            'action'      => 'environmental.align_buckets',
            'entity_type' => 'sensor_aligned_buckets',
            'entity_id'   => $storeId,
            'before'      => null,
            'after'       => $result['data'],
        ];

        $resp = ResponseHelper::success($result['data']);
        return json($resp['data'], $resp['code']);
    }

    /**
     * GET /environmental/derived-metrics
     */
    public function getDerivedMetrics(Request $request)
    {
        $userContext = $request->userContext;
        if (in_array('administrator', $userContext['roles'] ?? []) && $request->get('store_id')) {
            $storeId = (int) $request->get('store_id');
        } else {
            $storeId = (int) $userContext['store_id'];
        }
        $zoneId = $request->get('zone_id') ? (int) $request->get('zone_id') : null;
        $page = max(1, (int) $request->get('page', 1));
        $pageSize = max(1, min(100, (int) $request->get('page_size', 20)));

        $result = EnvironmentalService::getDerivedMetrics($storeId, $zoneId, $page, $pageSize);

        $resp = ResponseHelper::paginated($result['items'], $result['total'], $result['page'], $result['page_size']);
        return json($resp['data'], $resp['code']);
    }

    /**
     * POST /environmental/derived-metrics/compute
     */
    public function computeDerivedMetrics(Request $request)
    {
        $userContext = $request->userContext;
        // Force store_id from session for non-admins; reject foreign store_id
        if (in_array('administrator', $userContext['roles'] ?? []) && $request->post('store_id')) {
            $storeId = (int) $request->post('store_id');
        } else {
            $storeId = (int) $userContext['store_id'];
            if ($request->post('store_id') && (int) $request->post('store_id') !== $storeId) {
                $resp = ResponseHelper::forbidden('Access denied: cannot operate on another store');
                return json($resp['data'], $resp['code']);
            }
        }
        $zoneId = $request->post('zone_id') ? (int) $request->post('zone_id') : null;

        $result = EnvironmentalService::computeDerivedMetrics($storeId, $zoneId);

        if (!$result['success']) {
            $resp = ResponseHelper::error($result['error_code'] ?? 'COMPUTE_FAILED', $result['message'] ?? 'Computation failed', 500);
            return json($resp['data'], $resp['code']);
        }

        $request->auditData = [
            'action'      => 'environmental.compute_derived',
            'entity_type' => 'derived_metrics',
            'entity_id'   => $storeId,
            'before'      => null,
            'after'       => $result['data'],
        ];

        $resp = ResponseHelper::success($result['data']);
        return json($resp['data'], $resp['code']);
    }

    /**
     * GET /environmental/lineage/{id}
     */
    public function getLineage(Request $request, int $id)
    {
        $userContext = $request->userContext;

        // Verify the derived metric belongs to the user's store
        $derivedMetric = Db::table('derived_metrics')->where('id', $id)->find();
        if ($derivedMetric && !in_array('administrator', $userContext['roles'] ?? [])
            && $derivedMetric['store_id'] != $userContext['store_id']) {
            $resp = ResponseHelper::forbidden('Access denied: metric belongs to a different store');
            return json($resp['data'], $resp['code']);
        }

        $lineage = EnvironmentalService::getLineage($id);

        if (!$lineage) {
            $resp = ResponseHelper::notFound('Lineage record not found');
            return json($resp['data'], $resp['code']);
        }

        $resp = ResponseHelper::success($lineage);
        return json($resp['data'], $resp['code']);
    }

    /**
     * POST /environmental/formulas
     */
    public function createFormula(Request $request)
    {
        $userContext = $request->userContext;
        $data = $request->post();

        if (empty($data['formula_key']) || empty($data['formula_expression'])) {
            $resp = ResponseHelper::validationError('formula_key and formula_expression are required');
            return json($resp['data'], $resp['code']);
        }

        // Expire current version if any
        $existing = Db::table('formula_versions')
            ->where('formula_key', $data['formula_key'])
            ->whereNull('effective_to')
            ->find();

        if ($existing) {
            Db::table('formula_versions')->where('id', $existing['id'])->update([
                'effective_to' => date('Y-m-d H:i:s'),
            ]);
        }

        // Compute next version number
        $maxVersion = Db::table('formula_versions')
            ->where('formula_key', $data['formula_key'])
            ->max('version_no') ?? 0;

        $id = Db::table('formula_versions')->insertGetId([
            'formula_key'        => $data['formula_key'],
            'version_no'         => $maxVersion + 1,
            'formula_expression' => $data['formula_expression'],
            'threshold_json'     => isset($data['thresholds']) ? json_encode($data['thresholds']) : null,
            'effective_from'     => date('Y-m-d H:i:s'),
            'effective_to'       => null,
            'created_by'         => $userContext['user_id'],
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        $formula = Db::table('formula_versions')->where('id', $id)->find();

        $request->auditData = [
            'action'      => 'environmental.create_formula',
            'entity_type' => 'formula_version',
            'entity_id'   => $id,
            'before'      => $existing,
            'after'       => $formula,
        ];

        $resp = ResponseHelper::success($formula, 201);
        return json($resp['data'], $resp['code']);
    }

    /**
     * GET /environmental/formulas
     */
    public function listFormulas(Request $request)
    {
        $activeOnly = $request->get('active_only', '1');
        $query = Db::table('formula_versions');

        if ($activeOnly === '1') {
            $query->whereNull('effective_to');
        }

        $formulas = $query->order('created_at', 'desc')->select()->toArray();

        $resp = ResponseHelper::success($formulas);
        return json($resp['data'], $resp['code']);
    }

    /**
     * GET /environmental/formulas/{id}
     */
    public function readFormula(Request $request, int $id)
    {
        $formula = Db::table('formula_versions')->where('id', $id)->find();

        if (!$formula) {
            $resp = ResponseHelper::notFound('Formula version not found');
            return json($resp['data'], $resp['code']);
        }

        $resp = ResponseHelper::success($formula);
        return json($resp['data'], $resp['code']);
    }

    /**
     * PATCH /environmental/formulas/{id}
     */
    public function updateFormula(Request $request, int $id)
    {
        $formula = Db::table('formula_versions')->where('id', $id)->find();

        if (!$formula) {
            $resp = ResponseHelper::notFound('Formula version not found');
            return json($resp['data'], $resp['code']);
        }

        if ($formula['effective_to'] !== null) {
            $resp = ResponseHelper::conflict('Cannot update a superseded formula version');
            return json($resp['data'], $resp['code']);
        }

        $before = $formula;
        $data = $request->put();
        $updateData = [];

        if (isset($data['formula_expression'])) {
            $updateData['formula_expression'] = $data['formula_expression'];
        }
        if (isset($data['thresholds'])) {
            $updateData['threshold_json'] = json_encode($data['thresholds']);
        }

        if (!empty($updateData)) {
            Db::table('formula_versions')->where('id', $id)->update($updateData);
        }

        $after = Db::table('formula_versions')->where('id', $id)->find();

        $request->auditData = [
            'action'      => 'environmental.update_formula',
            'entity_type' => 'formula_version',
            'entity_id'   => $id,
            'before'      => $before,
            'after'       => $after,
        ];

        $resp = ResponseHelper::success($after);
        return json($resp['data'], $resp['code']);
    }

    /**
     * DELETE /environmental/formulas/{id}
     */
    public function deleteFormula(Request $request, int $id)
    {
        $formula = Db::table('formula_versions')->where('id', $id)->find();

        if (!$formula) {
            $resp = ResponseHelper::notFound('Formula version not found');
            return json($resp['data'], $resp['code']);
        }

        // Expire instead of hard delete to preserve lineage
        Db::table('formula_versions')->where('id', $id)->update([
            'effective_to' => date('Y-m-d H:i:s'),
        ]);

        $request->auditData = [
            'action'      => 'environmental.delete_formula',
            'entity_type' => 'formula_version',
            'entity_id'   => $id,
            'before'      => $formula,
            'after'       => ['effective_to' => date('Y-m-d H:i:s')],
        ];

        $resp = ResponseHelper::success(['message' => 'Formula version expired']);
        return json($resp['data'], $resp['code']);
    }
}
