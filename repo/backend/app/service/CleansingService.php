<?php
namespace app\service;

use app\logging\Logger;
use think\facade\Db;

/**
 * CleansingService - Data cleansing & standardization pipeline.
 * Deterministic parsing, denoising, deduplication, entity alignment.
 * Company normalization and similar-role merging.
 * Batch approval/rollback governance by Administrator only.
 */
class CleansingService
{
    /**
     * Import and process a cleansing batch.
     */
    public static function importBatch(array $data, array $userContext): array
    {
        $batchNo = 'CLN-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2)));

        Db::startTrans();
        try {
            $batchId = Db::table('cleansing_batches')->insertGetId([
                'batch_no'        => $batchNo,
                'source_name'     => $data['source_name'],
                'dataset_profile' => $data['dataset_profile'] ?? 'customer_entered',
                'status'          => 'pending_review',
                'store_id'        => $userContext['store_id'],
                'submitted_by'    => $userContext['user_id'],
                'created_at'      => date('Y-m-d H:i:s'),
            ]);

            $rows = $data['rows'] ?? [];
            $rowNo = 0;
            foreach ($rows as $row) {
                $rowNo++;
                $rawRowId = Db::table('cleansing_raw_rows')->insertGetId([
                    'batch_id'         => $batchId,
                    'raw_payload_json' => json_encode($row),
                    'row_no'           => $rowNo,
                ]);

                // Run deterministic cleansing
                $normalized = self::normalizeRow($row, $data['dataset_profile'] ?? 'customer_entered');

                // Deduplication key
                $dedupeKey = self::generateDedupeKey($normalized);

                // Alignment confidence
                $confidence = self::computeAlignmentConfidence($normalized);
                $reviewRequired = $confidence < 0.7 ? 1 : 0;

                Db::table('cleansing_results')->insert([
                    'batch_id'              => $batchId,
                    'raw_row_id'            => $rawRowId,
                    'normalized_job_title'  => $normalized['job_title'] ?? null,
                    'normalized_company'    => $normalized['company'] ?? null,
                    'normalized_city'       => $normalized['city'] ?? null,
                    'normalized_salary'     => $normalized['salary'] ?? null,
                    'normalized_education'  => $normalized['education'] ?? null,
                    'normalized_experience' => $normalized['experience'] ?? null,
                    'dedupe_key'            => $dedupeKey,
                    'alignment_confidence'  => $confidence,
                    'review_required_flag'  => $reviewRequired,
                    'status'                => 'proposed',
                ]);

                // Queue for manual review if low confidence
                if ($reviewRequired) {
                    Db::table('manual_review_queue')->insert([
                        'batch_id'    => $batchId,
                        'row_id'      => $rawRowId,
                        'reason_code' => $confidence < 0.4 ? 'low_confidence' : 'ambiguous_match',
                        'queued_at'   => date('Y-m-d H:i:s'),
                    ]);
                }

                // Record change journal
                Db::table('cleansing_change_journal')->insert([
                    'batch_id'    => $batchId,
                    'entity_type' => 'raw_row',
                    'entity_id'   => $rawRowId,
                    'before_json' => json_encode($row),
                    'after_json'  => json_encode($normalized),
                    'changed_at'  => date('Y-m-d H:i:s'),
                ]);
            }

            Db::commit();

            Logger::info('cleansing', 'import', "Batch imported: {$batchNo}", [
                'batch_id' => $batchId,
                'rows' => $rowNo,
            ]);

            return ['success' => true, 'data' => ['batch_id' => $batchId, 'batch_no' => $batchNo, 'rows' => $rowNo]];
        } catch (\Throwable $e) {
            Db::rollback();
            Logger::error('cleansing', 'import', 'Batch import failed: ' . $e->getMessage());
            return ['success' => false, 'error_code' => 'IMPORT_FAILED', 'message' => 'Failed to import batch'];
        }
    }

    public static function getBatches(array $filters, int $page = 1, int $pageSize = 20): array
    {
        $query = Db::table('cleansing_batches');
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['store_id'])) {
            $query->where('store_id', $filters['store_id']);
        }
        $total = (clone $query)->count();
        $items = $query->order('created_at', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return ['items' => $items, 'total' => $total, 'page' => $page, 'page_size' => $pageSize];
    }

    public static function getBatchPreview(int $batchId): array
    {
        $results = Db::table('cleansing_results')
            ->where('batch_id', $batchId)
            ->select()
            ->toArray();

        $reviewQueue = Db::table('manual_review_queue')
            ->where('batch_id', $batchId)
            ->select()
            ->toArray();

        return ['results' => $results, 'review_queue' => $reviewQueue];
    }

    public static function approveBatch(int $batchId, array $userContext): array
    {
        // Only Administrator can approve
        if (!in_array('administrator', $userContext['roles'])) {
            return ['success' => false, 'error_code' => 'FORBIDDEN', 'message' => 'Only Administrator can approve batches', 'status' => 403];
        }

        $batch = Db::table('cleansing_batches')->where('id', $batchId)->find();
        if (!$batch) {
            return ['success' => false, 'error_code' => 'NOT_FOUND', 'message' => 'Batch not found', 'status' => 404];
        }

        if ($batch['status'] !== 'pending_review') {
            return ['success' => false, 'error_code' => 'CONFLICT', 'message' => 'Batch is not in pending_review status', 'status' => 409];
        }

        Db::table('cleansing_batches')->where('id', $batchId)->update([
            'status'      => 'approved',
            'reviewed_by' => $userContext['user_id'],
            'reviewed_at' => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        Db::table('cleansing_results')->where('batch_id', $batchId)->update([
            'status'     => 'approved',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        Logger::info('cleansing', 'approve', "Batch approved: {$batch['batch_no']}", [
            'batch_id' => $batchId,
            'approved_by' => $userContext['user_id'],
        ]);

        return ['success' => true, 'data' => ['batch_id' => $batchId, 'status' => 'approved']];
    }

    public static function rollbackBatch(int $batchId, array $userContext): array
    {
        // Only Administrator can rollback
        if (!in_array('administrator', $userContext['roles'])) {
            return ['success' => false, 'error_code' => 'FORBIDDEN', 'message' => 'Only Administrator can rollback batches', 'status' => 403];
        }

        $batch = Db::table('cleansing_batches')->where('id', $batchId)->find();
        if (!$batch) {
            return ['success' => false, 'error_code' => 'NOT_FOUND', 'message' => 'Batch not found', 'status' => 404];
        }

        if (!in_array($batch['status'], ['approved', 'pending_review'])) {
            return ['success' => false, 'error_code' => 'CONFLICT', 'message' => 'Batch cannot be rolled back from current status', 'status' => 409];
        }

        // Restore previous canonical values using change journal
        $journal = Db::table('cleansing_change_journal')
            ->where('batch_id', $batchId)
            ->select()
            ->toArray();

        Db::startTrans();
        try {
            Db::table('cleansing_results')->where('batch_id', $batchId)->update([
                'status'     => 'rejected',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            Db::table('cleansing_batches')->where('id', $batchId)->update([
                'status'      => 'rolled_back',
                'rollback_by' => $userContext['user_id'],
                'rollback_at' => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);

            Db::commit();

            Logger::info('cleansing', 'rollback', "Batch rolled back: {$batch['batch_no']}", [
                'batch_id' => $batchId,
                'rollback_by' => $userContext['user_id'],
                'journal_entries' => count($journal),
            ]);

            return ['success' => true, 'data' => ['batch_id' => $batchId, 'status' => 'rolled_back']];
        } catch (\Throwable $e) {
            Db::rollback();
            Logger::error('cleansing', 'rollback', 'Rollback failed: ' . $e->getMessage());
            return ['success' => false, 'error_code' => 'ROLLBACK_FAILED', 'message' => 'Failed to rollback batch'];
        }
    }

    public static function getManualReviewQueue(int $page = 1, int $pageSize = 20, ?int $storeId = null): array
    {
        $query = Db::table('manual_review_queue')
            ->alias('mrq')
            ->whereNull('mrq.resolved_at');

        // Filter by store if provided (join through batch)
        if ($storeId !== null) {
            $query->join('cleansing_batches cb', 'mrq.batch_id = cb.id')
                ->where('cb.store_id', $storeId);
        }

        $total = (clone $query)->count();
        $items = $query->order('mrq.queued_at', 'desc')
            ->page($page, $pageSize)
            ->field('mrq.*')
            ->select()
            ->toArray();

        return ['items' => $items, 'total' => $total, 'page' => $page, 'page_size' => $pageSize];
    }

    // Deterministic normalization methods
    private static function normalizeRow(array $row, string $profile): array
    {
        return [
            'job_title'  => self::normalizeJobTitle($row['job_title'] ?? ''),
            'company'    => self::normalizeCompany($row['company'] ?? ''),
            'city'       => self::normalizeCity($row['city'] ?? ''),
            'salary'     => self::normalizeSalary($row['salary'] ?? ''),
            'education'  => self::normalizeEducation($row['education'] ?? ''),
            'experience' => self::normalizeExperience($row['experience'] ?? ''),
        ];
    }

    private static function normalizeJobTitle(string $title): string
    {
        $title = trim(mb_strtolower($title));
        // Merge similar roles
        $mergeMap = [
            'sr.' => 'senior', 'sr ' => 'senior ', 'jr.' => 'junior', 'jr ' => 'junior ',
            'dev' => 'developer', 'eng' => 'engineer', 'mgr' => 'manager', 'admin' => 'administrator',
            'swe' => 'software engineer', 'sde' => 'software development engineer',
        ];
        foreach ($mergeMap as $abbr => $full) {
            $title = str_replace($abbr, $full, $title);
        }
        return ucwords(trim(preg_replace('/\s+/', ' ', $title)));
    }

    private static function normalizeCompany(string $company): string
    {
        $company = trim($company);
        // Remove common suffixes for normalization
        $company = preg_replace('/\s*(Inc\.?|LLC|Ltd\.?|Corp\.?|Co\.?|GmbH|PLC)\s*$/i', '', $company);
        return ucwords(trim(mb_strtolower($company)));
    }

    private static function normalizeCity(string $city): string
    {
        return ucwords(trim(mb_strtolower(preg_replace('/\s+/', ' ', $city))));
    }

    private static function normalizeSalary(string $salary): string
    {
        $salary = preg_replace('/[^0-9.\-kKmM]/', '', $salary);
        if (preg_match('/(\d+\.?\d*)k/i', $salary, $m)) {
            return strval(floatval($m[1]) * 1000);
        }
        if (preg_match('/(\d+\.?\d*)m/i', $salary, $m)) {
            return strval(floatval($m[1]) * 1000000);
        }
        return $salary;
    }

    private static function normalizeEducation(string $edu): string
    {
        $edu = trim(mb_strtolower($edu));
        $map = [
            'bs' => "Bachelor's", 'ba' => "Bachelor's", "bachelor's" => "Bachelor's", 'bachelors' => "Bachelor's",
            'ms' => "Master's", 'ma' => "Master's", "master's" => "Master's", 'masters' => "Master's",
            'phd' => 'PhD', 'doctorate' => 'PhD',
            'hs' => 'High School', 'high school' => 'High School', 'ged' => 'High School',
        ];
        return $map[$edu] ?? ucwords($edu);
    }

    private static function normalizeExperience(string $exp): string
    {
        $exp = trim(mb_strtolower($exp));
        if (preg_match('/(\d+)\s*\+?\s*(?:years?|yrs?)/i', $exp, $m)) {
            return $m[1] . '+ years';
        }
        if (preg_match('/(\d+)\s*-\s*(\d+)\s*(?:years?|yrs?)/i', $exp, $m)) {
            return $m[1] . '-' . $m[2] . ' years';
        }
        return $exp;
    }

    private static function generateDedupeKey(array $normalized): string
    {
        $parts = [
            mb_strtolower($normalized['job_title'] ?? ''),
            mb_strtolower($normalized['company'] ?? ''),
            mb_strtolower($normalized['city'] ?? ''),
        ];
        return hash('sha256', implode('|', $parts));
    }

    private static function computeAlignmentConfidence(array $normalized): float
    {
        $score = 0.0;
        $fields = ['job_title', 'company', 'city', 'salary', 'education', 'experience'];
        $filled = 0;
        foreach ($fields as $field) {
            if (!empty($normalized[$field])) {
                $filled++;
            }
        }
        $completeness = $filled / count($fields);

        // Check if values look valid
        $validity = 1.0;
        if (!empty($normalized['salary']) && !is_numeric($normalized['salary'])) {
            $validity -= 0.2;
        }

        return round($completeness * 0.6 + $validity * 0.4, 4);
    }
}
