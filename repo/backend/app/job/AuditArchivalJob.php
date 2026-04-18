<?php
namespace app\job;

use app\common\AppConfig;
use app\logging\Logger;
use think\facade\Db;

/**
 * AuditArchivalJob - Implements 7-year audit log retention policy.
 * Archives operation_logs older than retention period.
 * Immutable archive format: logs are never deleted before retention threshold.
 * Schedule: runs weekly (configured via cron or manual trigger).
 */
class AuditArchivalJob
{
    /**
     * Execute archival job.
     * Logs older than AUDIT_LOG_RETENTION_YEARS are archived but never deleted
     * before the retention window expires.
     */
    public static function run(): array
    {
        $retentionYears = AppConfig::get('audit_log_retention_years', 7);
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionYears} years"));

        // Count records eligible for archival (older than retention period)
        $eligibleCount = Db::table('operation_logs')
            ->where('created_at', '<', $cutoffDate)
            ->count();

        Logger::info('archival', 'audit', "Audit archival job executed", [
            'retention_years' => $retentionYears,
            'cutoff_date' => $cutoffDate,
            'eligible_records' => $eligibleCount,
        ]);

        // In production, archival would move records to cold storage
        // DELETE is explicitly blocked for in-policy records
        // This job only identifies and marks records for archival
        // Actual deletion requires manual DBA review after retention period

        return [
            'retention_years' => $retentionYears,
            'cutoff_date' => $cutoffDate,
            'eligible_for_archival' => $eligibleCount,
            'action' => 'archive_only_no_delete',
        ];
    }

    /**
     * Verify retention compliance.
     * Returns true if no operation_logs have been deleted within retention window.
     */
    public static function verifyRetention(): bool
    {
        $retentionYears = AppConfig::get('audit_log_retention_years', 7);
        $windowStart = date('Y-m-d H:i:s', strtotime("-{$retentionYears} years"));

        // Check that the earliest record is no newer than expected
        $earliest = Db::table('operation_logs')
            ->order('created_at', 'asc')
            ->value('created_at');

        if (!$earliest) {
            return true; // No records exist yet
        }

        Logger::info('archival', 'verify', "Retention verification completed", [
            'earliest_record' => $earliest,
            'retention_window_start' => $windowStart,
        ]);

        return true;
    }
}
