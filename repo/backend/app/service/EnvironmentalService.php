<?php
namespace app\service;

use app\common\AppConfig;
use app\logging\Logger;
use think\facade\Db;

/**
 * EnvironmentalService - Sensor/CSV ingestion, time alignment, fusion,
 * confidence labels, comfort index, derived metrics with lineage.
 */
class EnvironmentalService
{
    /**
     * Import CSV environmental data.
     */
    public static function importCsv(array $records, int $sourceId, array $userContext): array
    {
        $source = Db::table('sensor_sources')->where('id', $sourceId)->find();
        if (!$source) {
            return ['success' => false, 'error_code' => 'NOT_FOUND', 'message' => 'Source not found'];
        }

        $batchId = time();
        $imported = 0;

        foreach ($records as $record) {
            Db::table('sensor_raw_records')->insert([
                'source_id'    => $sourceId,
                'zone_id'      => $record['zone_id'] ?? $source['zone_id'],
                'metric_type'  => $record['metric_type'],
                'metric_value' => $record['metric_value'],
                'observed_at'  => $record['observed_at'],
                'ingested_at'  => date('Y-m-d H:i:s'),
                'batch_id'     => $batchId,
            ]);
            $imported++;
        }

        Logger::info('environment', 'import_csv', "CSV import completed: {$imported} records", [
            'source_id' => $sourceId,
            'batch_id' => $batchId,
        ]);

        return ['success' => true, 'data' => ['imported' => $imported, 'batch_id' => $batchId]];
    }

    /**
     * Import sensor feed data.
     */
    public static function importSensorFeed(array $records, int $sourceId): array
    {
        $source = Db::table('sensor_sources')->where('id', $sourceId)->find();
        if (!$source) {
            return ['success' => false, 'error_code' => 'NOT_FOUND', 'message' => 'Source not found'];
        }

        $imported = 0;
        foreach ($records as $record) {
            Db::table('sensor_raw_records')->insert([
                'source_id'    => $sourceId,
                'zone_id'      => $record['zone_id'] ?? $source['zone_id'],
                'metric_type'  => $record['metric_type'],
                'metric_value' => $record['metric_value'],
                'observed_at'  => $record['observed_at'],
                'ingested_at'  => date('Y-m-d H:i:s'),
            ]);
            $imported++;
        }

        return ['success' => true, 'data' => ['imported' => $imported]];
    }

    /**
     * Align raw records into time buckets (default 1-minute).
     * Late arrival tolerance is configurable; gaps marked incomplete.
     */
    public static function alignBuckets(int $storeId, ?int $zoneId = null, ?string $from = null, ?string $to = null): array
    {
        $bucketMinutes = AppConfig::get('default_time_bucket_minutes', 1);
        $toleranceMinutes = AppConfig::get('late_arrival_tolerance_minutes', 5);

        $query = Db::table('sensor_raw_records')
            ->alias('r')
            ->join('sensor_sources s', 'r.source_id = s.id')
            ->where('s.store_id', $storeId);

        if ($zoneId) {
            $query->where('r.zone_id', $zoneId);
        }
        if ($from) {
            $query->where('r.observed_at', '>=', $from);
        }
        if ($to) {
            $query->where('r.observed_at', '<=', $to);
        }

        $rawRecords = $query->field('r.*, s.store_id')
            ->order('r.observed_at', 'asc')
            ->select()
            ->toArray();

        // Group by bucket
        $buckets = [];
        foreach ($rawRecords as $record) {
            $observedTime = strtotime($record['observed_at']);
            $bucketStart = date('Y-m-d H:i:00', $observedTime - ($observedTime % ($bucketMinutes * 60)));
            $key = $storeId . ':' . ($record['zone_id'] ?? 'null') . ':' . $bucketStart;

            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'store_id'     => $storeId,
                    'zone_id'      => $record['zone_id'],
                    'bucket_start' => $bucketStart,
                    'records'      => [],
                    'sources'      => [],
                ];
            }
            $buckets[$key]['records'][] = $record;
            $buckets[$key]['sources'][$record['source_id']] = true;
        }

        $alignedCount = 0;
        foreach ($buckets as $key => $bucket) {
            $sourceCount = count($bucket['sources']);
            $totalSources = Db::table('sensor_sources')
                ->where('store_id', $storeId)
                ->where('active', 1)
                ->when($bucket['zone_id'], function ($q) use ($bucket) {
                    $q->where('zone_id', $bucket['zone_id']);
                })
                ->count();

            $completenessRatio = $totalSources > 0 ? round($sourceCount / $totalSources, 4) : 0;

            // Consistency score: how close values are to each other
            $values = array_column($bucket['records'], 'metric_value');
            $consistencyScore = self::calculateConsistency($values);

            // Alignment score: how well records align to bucket boundary
            $alignmentScore = self::calculateAlignment($bucket['records'], $bucket['bucket_start'], $bucketMinutes);

            // Confidence score and label
            $confidenceScore = round(0.4 * $completenessRatio + 0.35 * $consistencyScore + 0.25 * $alignmentScore, 4);
            $confidenceLabel = self::getConfidenceLabel($confidenceScore);

            Db::table('sensor_aligned_buckets')->insert([
                'store_id'           => $storeId,
                'zone_id'            => $bucket['zone_id'],
                'bucket_start'       => $bucket['bucket_start'],
                'completeness_ratio' => $completenessRatio,
                'consistency_score'  => $consistencyScore,
                'alignment_score'    => $alignmentScore,
                'confidence_label'   => $confidenceLabel,
                'confidence_score'   => $confidenceScore,
                'created_at'         => date('Y-m-d H:i:s'),
            ]);

            // Fusion: combine records per metric type
            $metricGroups = [];
            foreach ($bucket['records'] as $record) {
                $metricGroups[$record['metric_type']][] = $record;
            }

            foreach ($metricGroups as $metricType => $records) {
                $rawRefs = array_map(fn($r) => $r['id'], $records);
                $values = array_column($records, 'metric_value');

                // Fusion method: weighted_median for 2+ sources, direct for single
                if (count($records) >= 2) {
                    $fusedValue = self::weightedMedian($values);
                    $fusionMethod = 'weighted_median';
                } else {
                    $fusedValue = $values[0];
                    $fusionMethod = 'direct_source';
                }

                Db::table('sensor_fusion_records')->insert([
                    'store_id'            => $storeId,
                    'zone_id'             => $bucket['zone_id'],
                    'bucket_start'        => $bucket['bucket_start'],
                    'metric_type'         => $metricType,
                    'fused_value'         => $fusedValue,
                    'source_count'        => count($records),
                    'fusion_method'       => $fusionMethod,
                    'source_priority_json' => json_encode(array_unique(array_column($records, 'source_id'))),
                    'raw_refs_json'       => json_encode($rawRefs),
                    'created_at'          => date('Y-m-d H:i:s'),
                ]);
            }

            $alignedCount++;
        }

        return ['success' => true, 'data' => ['aligned_buckets' => $alignedCount]];
    }

    /**
     * Compute derived metrics (moving average, rate-of-change, comfort index) from fused data.
     */
    public static function computeDerivedMetrics(int $storeId, ?int $zoneId = null): array
    {
        // Get active formula versions
        $formulas = Db::table('formula_versions')
            ->whereNull('effective_to')
            ->select()
            ->toArray();

        $formulaMap = [];
        foreach ($formulas as $f) {
            $formulaMap[$f['formula_key']] = $f;
        }

        // Get fused records ordered by bucket
        $query = Db::table('sensor_fusion_records')
            ->where('store_id', $storeId);
        if ($zoneId) {
            $query->where('zone_id', $zoneId);
        }
        $fusedRecords = $query->order('bucket_start', 'asc')->select()->toArray();

        $metricStreams = [];
        foreach ($fusedRecords as $record) {
            $key = $record['metric_type'];
            $metricStreams[$key][] = $record;
        }

        $derivedCount = 0;

        foreach ($metricStreams as $metricType => $stream) {
            // Moving average
            if (isset($formulaMap['moving_average'])) {
                $formula = $formulaMap['moving_average'];
                $thresholds = json_decode($formula['threshold_json'], true);
                $windowSize = $thresholds['window_size'] ?? 5;

                for ($i = 0; $i < count($stream); $i++) {
                    $windowValues = [];
                    $rawRefs = [];
                    for ($j = max(0, $i - $windowSize + 1); $j <= $i; $j++) {
                        $windowValues[] = $stream[$j]['fused_value'];
                        $refs = json_decode($stream[$j]['raw_refs_json'], true);
                        $rawRefs = array_merge($rawRefs, $refs ?? []);
                    }

                    $maValue = count($windowValues) > 0 ? round(array_sum($windowValues) / count($windowValues), 6) : 0;

                    $derivedId = Db::table('derived_metrics')->insertGetId([
                        'store_id'           => $storeId,
                        'zone_id'            => $zoneId,
                        'bucket_start'       => $stream[$i]['bucket_start'],
                        'metric_key'         => $metricType . '_moving_avg',
                        'metric_value'       => $maValue,
                        'formula_version_id' => $formula['id'],
                        'created_at'         => date('Y-m-d H:i:s'),
                    ]);

                    // Store lineage
                    $lineageId = Db::table('derived_lineage')->insertGetId([
                        'derived_metric_id'       => $derivedId,
                        'raw_record_refs_json'    => json_encode(array_unique($rawRefs)),
                        'transformation_steps_json' => json_encode([
                            'step1' => 'fuse_raw_records',
                            'step2' => 'compute_moving_average',
                            'window_size' => count($windowValues),
                        ]),
                        'formula_version_id'      => $formula['id'],
                        'reproducibility_hash'    => hash('sha256', json_encode($windowValues) . $formula['id']),
                        'created_at'              => date('Y-m-d H:i:s'),
                    ]);

                    Db::table('derived_metrics')->where('id', $derivedId)->update(['lineage_id' => $lineageId]);
                    $derivedCount++;
                }
            }

            // Rate of change
            if (isset($formulaMap['rate_of_change'])) {
                $formula = $formulaMap['rate_of_change'];
                for ($i = 1; $i < count($stream); $i++) {
                    $prev = $stream[$i - 1]['fused_value'];
                    $curr = $stream[$i]['fused_value'];
                    $roc = $prev != 0 ? round(($curr - $prev) / abs($prev), 6) : 0;

                    $rawRefsCurr = json_decode($stream[$i]['raw_refs_json'], true) ?? [];
                    $rawRefsPrev = json_decode($stream[$i - 1]['raw_refs_json'], true) ?? [];

                    $derivedId = Db::table('derived_metrics')->insertGetId([
                        'store_id'           => $storeId,
                        'zone_id'            => $zoneId,
                        'bucket_start'       => $stream[$i]['bucket_start'],
                        'metric_key'         => $metricType . '_rate_of_change',
                        'metric_value'       => $roc,
                        'formula_version_id' => $formula['id'],
                        'created_at'         => date('Y-m-d H:i:s'),
                    ]);

                    $lineageId = Db::table('derived_lineage')->insertGetId([
                        'derived_metric_id'       => $derivedId,
                        'raw_record_refs_json'    => json_encode(array_unique(array_merge($rawRefsCurr, $rawRefsPrev))),
                        'transformation_steps_json' => json_encode([
                            'step1' => 'fuse_raw_records',
                            'step2' => 'compute_rate_of_change',
                            'previous_value' => $prev,
                            'current_value' => $curr,
                        ]),
                        'formula_version_id'      => $formula['id'],
                        'reproducibility_hash'    => hash('sha256', "{$prev}:{$curr}:" . $formula['id']),
                        'created_at'              => date('Y-m-d H:i:s'),
                    ]);

                    Db::table('derived_metrics')->where('id', $derivedId)->update(['lineage_id' => $lineageId]);
                    $derivedCount++;
                }
            }
        }

        // Comfort index computation
        if (isset($formulaMap['comfort_index'])) {
            $formula = $formulaMap['comfort_index'];
            $derivedCount += self::computeComfortIndex($storeId, $zoneId, $formula);
        }

        return ['success' => true, 'data' => ['derived_metrics_computed' => $derivedCount]];
    }

    private static function computeComfortIndex(int $storeId, ?int $zoneId, array $formula): int
    {
        $buckets = Db::table('sensor_fusion_records')
            ->where('store_id', $storeId)
            ->when($zoneId, function ($q) use ($zoneId) {
                $q->where('zone_id', $zoneId);
            })
            ->group('bucket_start')
            ->column('bucket_start');

        $count = 0;
        foreach ($buckets as $bucketStart) {
            $records = Db::table('sensor_fusion_records')
                ->where('store_id', $storeId)
                ->where('bucket_start', $bucketStart)
                ->when($zoneId, function ($q) use ($zoneId) {
                    $q->where('zone_id', $zoneId);
                })
                ->select()
                ->toArray();

            $metricValues = [];
            $allRawRefs = [];
            foreach ($records as $r) {
                $metricValues[$r['metric_type']] = $r['fused_value'];
                $refs = json_decode($r['raw_refs_json'], true);
                $allRawRefs = array_merge($allRawRefs, $refs ?? []);
            }

            // Normalize values to 0-1 range for comfort calculation
            $temp = isset($metricValues['temperature']) ? self::normalizeTemp($metricValues['temperature']) : 0.5;
            $humidity = isset($metricValues['humidity']) ? self::normalizeHumidity($metricValues['humidity']) : 0.5;
            $airQuality = isset($metricValues['air_quality']) ? self::normalizeAirQuality($metricValues['air_quality']) : 0.5;

            $comfortIndex = round(0.4 * $temp + 0.3 * $humidity + 0.3 * $airQuality, 6);

            $derivedId = Db::table('derived_metrics')->insertGetId([
                'store_id'           => $storeId,
                'zone_id'            => $zoneId,
                'bucket_start'       => $bucketStart,
                'metric_key'         => 'comfort_index',
                'metric_value'       => $comfortIndex,
                'formula_version_id' => $formula['id'],
                'created_at'         => date('Y-m-d H:i:s'),
            ]);

            $lineageId = Db::table('derived_lineage')->insertGetId([
                'derived_metric_id'       => $derivedId,
                'raw_record_refs_json'    => json_encode(array_unique($allRawRefs)),
                'transformation_steps_json' => json_encode([
                    'step1' => 'fuse_raw_records',
                    'step2' => 'normalize_metrics',
                    'step3' => 'compute_comfort_index',
                    'weights' => ['temperature' => 0.4, 'humidity' => 0.3, 'air_quality' => 0.3],
                    'normalized_inputs' => ['temperature' => $temp, 'humidity' => $humidity, 'air_quality' => $airQuality],
                ]),
                'formula_version_id'      => $formula['id'],
                'reproducibility_hash'    => hash('sha256', json_encode($metricValues) . $formula['id']),
                'created_at'              => date('Y-m-d H:i:s'),
            ]);

            Db::table('derived_metrics')->where('id', $derivedId)->update(['lineage_id' => $lineageId]);
            $count++;
        }

        return $count;
    }

    public static function getAlignedBuckets(int $storeId, ?int $zoneId = null, int $page = 1, int $pageSize = 20): array
    {
        $query = Db::table('sensor_aligned_buckets')->where('store_id', $storeId);
        if ($zoneId) {
            $query->where('zone_id', $zoneId);
        }
        $total = (clone $query)->count();
        $items = $query->order('bucket_start', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return ['items' => $items, 'total' => $total, 'page' => $page, 'page_size' => $pageSize];
    }

    public static function getDerivedMetrics(int $storeId, ?int $zoneId = null, int $page = 1, int $pageSize = 20): array
    {
        $query = Db::table('derived_metrics')->where('store_id', $storeId);
        if ($zoneId) {
            $query->where('zone_id', $zoneId);
        }
        $total = (clone $query)->count();
        $items = $query->order('bucket_start', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return ['items' => $items, 'total' => $total, 'page' => $page, 'page_size' => $pageSize];
    }

    public static function getLineage(int $derivedMetricId): ?array
    {
        return Db::table('derived_lineage')
            ->where('derived_metric_id', $derivedMetricId)
            ->find();
    }

    // Helper methods
    private static function calculateConsistency(array $values): float
    {
        if (count($values) < 2) return 1.0;
        $mean = array_sum($values) / count($values);
        if ($mean == 0) return 1.0;
        $variance = array_sum(array_map(fn($v) => pow($v - $mean, 2), $values)) / count($values);
        $cv = sqrt($variance) / abs($mean);
        return round(max(0, 1 - $cv), 4);
    }

    private static function calculateAlignment(array $records, string $bucketStart, int $bucketMinutes): float
    {
        if (empty($records)) return 0.0;
        $bucketTime = strtotime($bucketStart);
        $totalDrift = 0;
        foreach ($records as $record) {
            $observedTime = strtotime($record['observed_at']);
            $drift = abs($observedTime - $bucketTime);
            $totalDrift += $drift;
        }
        $avgDrift = $totalDrift / count($records);
        $maxDrift = $bucketMinutes * 60;
        return round(max(0, 1 - ($avgDrift / $maxDrift)), 4);
    }

    private static function weightedMedian(array $values): float
    {
        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);
        if ($count % 2 === 0) {
            return round(($values[$mid - 1] + $values[$mid]) / 2, 6);
        }
        return round($values[$mid], 6);
    }

    private static function getConfidenceLabel(float $score): string
    {
        if ($score >= 0.85) return 'High';
        if ($score >= 0.60) return 'Medium';
        return 'Low';
    }

    private static function normalizeTemp(float $temp): float
    {
        // Comfort range: 68-76F (20-24.4C), assuming Fahrenheit input
        if ($temp >= 68 && $temp <= 76) return 1.0;
        if ($temp < 50 || $temp > 95) return 0.0;
        if ($temp < 68) return round(($temp - 50) / 18, 4);
        return round((95 - $temp) / 19, 4);
    }

    private static function normalizeHumidity(float $humidity): float
    {
        if ($humidity >= 30 && $humidity <= 50) return 1.0;
        if ($humidity < 10 || $humidity > 80) return 0.0;
        if ($humidity < 30) return round(($humidity - 10) / 20, 4);
        return round((80 - $humidity) / 30, 4);
    }

    private static function normalizeAirQuality(float $aqi): float
    {
        if ($aqi <= 50) return 1.0;
        if ($aqi >= 200) return 0.0;
        return round((200 - $aqi) / 150, 4);
    }
}
