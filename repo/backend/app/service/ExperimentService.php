<?php
namespace app\service;

use app\logging\Logger;
use think\facade\Db;

/**
 * ExperimentService - A/B experiment management with holdout groups.
 * Sticky assignment per user/session key for experiment duration.
 * Admin can define events, start/stop experiments.
 */
class ExperimentService
{
    public static function create(array $data, array $userContext): array
    {
        $experimentId = Db::table('experiments')->insertGetId([
            'key'               => $data['key'],
            'name'              => $data['name'],
            'status'            => 'draft',
            'holdout_percent'   => $data['holdout_percent'] ?? 10.00,
            'randomization_unit' => $data['randomization_unit'] ?? 'user',
            'created_by'        => $userContext['user_id'],
            'created_at'        => date('Y-m-d H:i:s'),
        ]);

        // Create variants
        if (!empty($data['variants'])) {
            foreach ($data['variants'] as $variant) {
                Db::table('experiment_variants')->insert([
                    'experiment_id'          => $experimentId,
                    'variant_key'            => $variant['variant_key'],
                    'ui_copy_json'           => isset($variant['ui_copy']) ? json_encode($variant['ui_copy']) : null,
                    'coupon_presentation_json' => isset($variant['coupon_presentation']) ? json_encode($variant['coupon_presentation']) : null,
                    'traffic_percent'        => $variant['traffic_percent'] ?? 50.00,
                ]);
            }
        }

        Logger::info('experiment', 'create', "Experiment created: {$data['key']}", ['id' => $experimentId]);

        return ['success' => true, 'data' => ['id' => $experimentId]];
    }

    public static function start(int $experimentId, array $userContext): array
    {
        $experiment = Db::table('experiments')->where('id', $experimentId)->find();
        if (!$experiment) {
            return ['success' => false, 'error_code' => 'NOT_FOUND', 'message' => 'Experiment not found', 'status' => 404];
        }

        if ($experiment['status'] !== 'draft') {
            return ['success' => false, 'error_code' => 'CONFLICT', 'message' => 'Experiment can only be started from draft status', 'status' => 409];
        }

        $startAt = date('Y-m-d H:i:s');
        $endAt = date('Y-m-d H:i:s', strtotime('+14 days'));

        Db::table('experiments')->where('id', $experimentId)->update([
            'status'   => 'running',
            'start_at' => $startAt,
            'end_at'   => $endAt,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        Logger::info('experiment', 'start', "Experiment started: {$experiment['key']}", [
            'id' => $experimentId,
            'start_at' => $startAt,
            'end_at' => $endAt,
        ]);

        return ['success' => true, 'data' => ['start_at' => $startAt, 'end_at' => $endAt]];
    }

    public static function stop(int $experimentId, array $userContext): array
    {
        $experiment = Db::table('experiments')->where('id', $experimentId)->find();
        if (!$experiment) {
            return ['success' => false, 'error_code' => 'NOT_FOUND', 'message' => 'Experiment not found', 'status' => 404];
        }

        if ($experiment['status'] !== 'running') {
            return ['success' => false, 'error_code' => 'CONFLICT', 'message' => 'Experiment is not running', 'status' => 409];
        }

        $before = $experiment;

        Db::table('experiments')->where('id', $experimentId)->update([
            'status'     => 'stopped',
            'stopped_by' => $userContext['user_id'],
            'stopped_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        Logger::info('experiment', 'stop', "Experiment stopped: {$experiment['key']}", [
            'id' => $experimentId,
            'stopped_by' => $userContext['user_id'],
        ]);

        return ['success' => true, 'data' => Db::table('experiments')->where('id', $experimentId)->find(), 'before' => $before];
    }

    /**
     * Get or create sticky assignment for a user/session in an experiment.
     * Assignment is deterministic and immutable for the experiment duration.
     */
    public static function getAssignment(int $experimentId, string $stickyKey): array
    {
        $experiment = Db::table('experiments')->where('id', $experimentId)->find();
        if (!$experiment) {
            return ['variant' => null, 'is_holdout' => false];
        }

        // If experiment is stopped, return control (fallback)
        if ($experiment['status'] === 'stopped' || $experiment['status'] === 'completed') {
            return ['variant' => 'control', 'is_holdout' => false, 'experiment_status' => $experiment['status']];
        }

        if ($experiment['status'] !== 'running') {
            return ['variant' => null, 'is_holdout' => false, 'experiment_status' => $experiment['status']];
        }

        // Check existing assignment (sticky)
        $existing = Db::table('experiment_assignments')
            ->where('experiment_id', $experimentId)
            ->where('sticky_key', $stickyKey)
            ->find();

        if ($existing) {
            return [
                'variant' => $existing['assigned_variant'],
                'is_holdout' => (bool) $existing['is_holdout'],
                'assignment_id' => $existing['id'],
            ];
        }

        // Deterministic bucket assignment based on hash
        $hash = crc32($experimentId . ':' . $stickyKey);
        $bucket = abs($hash) % 10000;
        $holdoutThreshold = $experiment['holdout_percent'] * 100;

        $isHoldout = $bucket < $holdoutThreshold;
        $assignedVariant = null;

        if (!$isHoldout) {
            $variants = Db::table('experiment_variants')
                ->where('experiment_id', $experimentId)
                ->order('id', 'asc')
                ->select()
                ->toArray();

            if (!empty($variants)) {
                $remainingBucket = $bucket - $holdoutThreshold;
                $cumulative = 0;
                foreach ($variants as $variant) {
                    $cumulative += $variant['traffic_percent'] * 100;
                    if ($remainingBucket < $cumulative) {
                        $assignedVariant = $variant['variant_key'];
                        break;
                    }
                }
                if (!$assignedVariant) {
                    $assignedVariant = $variants[0]['variant_key'];
                }
            }
        }

        // Persist immutable assignment
        $assignmentId = Db::table('experiment_assignments')->insertGetId([
            'experiment_id'    => $experimentId,
            'sticky_key'       => $stickyKey,
            'assigned_variant' => $assignedVariant,
            'is_holdout'       => $isHoldout ? 1 : 0,
            'assigned_at'      => date('Y-m-d H:i:s'),
        ]);

        return [
            'variant' => $assignedVariant,
            'is_holdout' => $isHoldout,
            'assignment_id' => $assignmentId,
        ];
    }

    public static function getAssignments(int $experimentId, int $page = 1, int $pageSize = 20): array
    {
        $query = Db::table('experiment_assignments')->where('experiment_id', $experimentId);
        $total = (clone $query)->count();
        $items = $query->order('assigned_at', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return ['items' => $items, 'total' => $total, 'page' => $page, 'page_size' => $pageSize];
    }
}
