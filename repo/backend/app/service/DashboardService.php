<?php
namespace app\service;

use app\logging\Logger;
use think\facade\Db;

/**
 * DashboardService - Operational and Analytics dashboard aggregations.
 * Operations: transaction_volume, avg_fulfillment_time, cancellation_rate, complaint_rate
 * Analytics: activity, conversion, retention, content_quality, zero_result_search_rate
 */
class DashboardService
{
    public static function getOperationsMetrics(int $storeId, string $fromDate, string $toDate): array
    {
        $from = OrderService::parseMMDDYYYY($fromDate);
        $to = OrderService::parseMMDDYYYY($toDate);
        if (!$from || !$to) {
            return ['error' => 'Invalid date format. Use MM/DD/YYYY'];
        }

        $fromDt = $from . ' 00:00:00';
        $toDt = $to . ' 23:59:59';

        // Transaction volume
        $transactionVolume = Db::table('orders')
            ->where('store_id', $storeId)
            ->where('created_at', '>=', $fromDt)
            ->where('created_at', '<=', $toDt)
            ->count();

        // Average fulfillment time (in minutes). ThinkPHP's ->avg() treats
        // its argument as a column identifier and escapes it, so passing
        // the raw SQL expression `TIMESTAMPDIFF(...)` produces a
        // "not support data" error (see think-orm Mysql.php). Use
        // ->fetchSql(false)->field(Db::raw(...)) pattern via a single
        // scalar-returning query so the expression reaches MySQL unquoted.
        $avgRow = Db::table('orders')
            ->where('store_id', $storeId)
            ->where('status', 'completed')
            ->whereNotNull('confirmed_at')
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $fromDt)
            ->where('completed_at', '<=', $toDt)
            ->fieldRaw('AVG(TIMESTAMPDIFF(MINUTE, confirmed_at, completed_at)) AS avg_minutes')
            ->find();
        $avgFulfillment = $avgRow['avg_minutes'] ?? null;

        // Cancellation rate
        $totalOrders = Db::table('orders')
            ->where('store_id', $storeId)
            ->where('created_at', '>=', $fromDt)
            ->where('created_at', '<=', $toDt)
            ->count();

        $cancelledOrders = Db::table('orders')
            ->where('store_id', $storeId)
            ->where('status', 'cancelled')
            ->where('created_at', '>=', $fromDt)
            ->where('created_at', '<=', $toDt)
            ->count();

        $cancellationRate = $totalOrders > 0 ? round($cancelledOrders / $totalOrders, 4) : 0;

        // Complaint rate
        $completedOrders = Db::table('orders')
            ->where('store_id', $storeId)
            ->where('status', 'completed')
            ->where('completed_at', '>=', $fromDt)
            ->where('completed_at', '<=', $toDt)
            ->count();

        $complaintOrders = Db::table('orders')
            ->where('store_id', $storeId)
            ->where('status', 'completed')
            ->where('complaint_flag', 1)
            ->where('completed_at', '>=', $fromDt)
            ->where('completed_at', '<=', $toDt)
            ->count();

        $complaintRate = $completedOrders > 0 ? round($complaintOrders / $completedOrders, 4) : 0;

        return [
            'store_id'             => $storeId,
            'from'                 => $fromDate,
            'to'                   => $toDate,
            'transaction_volume'   => $transactionVolume,
            'avg_fulfillment_time' => round($avgFulfillment ?? 0, 2),
            'cancellation_rate'    => $cancellationRate,
            'complaint_rate'       => $complaintRate,
            'total_orders'         => $totalOrders,
            'cancelled_orders'     => $cancelledOrders,
            'completed_orders'     => $completedOrders,
            'complaint_orders'     => $complaintOrders,
        ];
    }

    public static function getAnalyticsMetrics(int $storeId, string $fromDate, string $toDate): array
    {
        $from = OrderService::parseMMDDYYYY($fromDate);
        $to = OrderService::parseMMDDYYYY($toDate);
        if (!$from || !$to) {
            return ['error' => 'Invalid date format. Use MM/DD/YYYY'];
        }

        $fromDt = $from . ' 00:00:00';
        $toDt = $to . ' 23:59:59';

        // Activity: active users / total enabled users
        $activeUsers = Db::table('sessions')
            ->where('store_id', $storeId)
            ->where('login_at', '>=', $fromDt)
            ->where('login_at', '<=', $toDt)
            ->count('DISTINCT user_id');

        $totalUsers = Db::table('users')
            ->alias('u')
            ->join('user_store_workstation_bindings b', 'u.id = b.user_id')
            ->where('b.store_id', $storeId)
            ->where('b.active', 1)
            ->where('u.status', 'active')
            ->count('DISTINCT u.id');

        $activity = $totalUsers > 0 ? round($activeUsers / $totalUsers, 4) : 0;

        // Conversion: confirmed orders / created orders
        $createdOrders = Db::table('orders')
            ->where('store_id', $storeId)
            ->where('created_at', '>=', $fromDt)
            ->where('created_at', '<=', $toDt)
            ->count();

        $confirmedOrders = Db::table('orders')
            ->where('store_id', $storeId)
            ->whereNotNull('confirmed_at')
            ->where('created_at', '>=', $fromDt)
            ->where('created_at', '<=', $toDt)
            ->count();

        $conversion = $createdOrders > 0 ? round($confirmedOrders / $createdOrders, 4) : 0;

        // Retention: returning customers / prior period customers
        $periodDays = (strtotime($to) - strtotime($from)) / 86400;
        $priorFrom = date('Y-m-d', strtotime($from) - ($periodDays * 86400));
        $priorTo = date('Y-m-d', strtotime($from) - 86400);

        $priorCustomers = Db::table('orders')
            ->where('store_id', $storeId)
            ->where('created_at', '>=', $priorFrom . ' 00:00:00')
            ->where('created_at', '<=', $priorTo . ' 23:59:59')
            ->count('DISTINCT customer_name');

        $returningCustomers = Db::table('orders')
            ->alias('o1')
            ->where('o1.store_id', $storeId)
            ->where('o1.created_at', '>=', $fromDt)
            ->where('o1.created_at', '<=', $toDt)
            ->whereRaw('o1.customer_name IN (SELECT customer_name FROM orders WHERE store_id = ? AND created_at >= ? AND created_at <= ?)', [$storeId, $priorFrom . ' 00:00:00', $priorTo . ' 23:59:59'])
            ->count('DISTINCT o1.customer_name');

        $retention = $priorCustomers > 0 ? round($returningCustomers / $priorCustomers, 4) : 0;

        // Content quality: avg quality score of announcements scoped by store
        $contentQuality = Db::table('announcements')
            ->where(function ($q) use ($storeId) {
                $q->where('store_id', $storeId)->whereOr('store_id', null);
            })
            ->where('created_at', '>=', $fromDt)
            ->where('created_at', '<=', $toDt)
            ->avg('quality_score') ?? 0;

        // Zero-result search rate
        $totalSearches = Db::table('search_logs')
            ->where('store_id', $storeId)
            ->where('created_at', '>=', $fromDt)
            ->where('created_at', '<=', $toDt)
            ->count();

        $zeroResultSearches = Db::table('search_logs')
            ->where('store_id', $storeId)
            ->where('result_count', 0)
            ->where('created_at', '>=', $fromDt)
            ->where('created_at', '<=', $toDt)
            ->count();

        $zeroResultRate = $totalSearches > 0 ? round($zeroResultSearches / $totalSearches, 4) : 0;

        return [
            'store_id'                => $storeId,
            'from'                    => $fromDate,
            'to'                      => $toDate,
            'activity'                => $activity,
            'conversion'              => $conversion,
            'retention'               => $retention,
            'content_quality'         => round($contentQuality, 2),
            'zero_result_search_rate' => $zeroResultRate,
            'active_users'            => $activeUsers,
            'total_users'             => $totalUsers,
            'created_orders'          => $createdOrders,
            'confirmed_orders'        => $confirmedOrders,
            'total_searches'          => $totalSearches,
            'zero_result_searches'    => $zeroResultSearches,
        ];
    }

    public static function exportOperationsCsv(int $storeId, string $fromDate, string $toDate): string
    {
        $metrics = self::getOperationsMetrics($storeId, $fromDate, $toDate);

        $lines = [];
        $lines[] = 'Metric,Value';
        $lines[] = 'Store ID,' . $metrics['store_id'];
        $lines[] = 'Date Range,' . $metrics['from'] . ' - ' . $metrics['to'];
        $lines[] = 'Transaction Volume,' . $metrics['transaction_volume'];
        $lines[] = 'Avg Fulfillment Time (min),' . $metrics['avg_fulfillment_time'];
        $lines[] = 'Cancellation Rate,' . ($metrics['cancellation_rate'] * 100) . '%';
        $lines[] = 'Complaint Rate,' . ($metrics['complaint_rate'] * 100) . '%';
        $lines[] = 'Total Orders,' . $metrics['total_orders'];
        $lines[] = 'Cancelled Orders,' . $metrics['cancelled_orders'];
        $lines[] = 'Completed Orders,' . $metrics['completed_orders'];
        $lines[] = 'Complaint Orders,' . $metrics['complaint_orders'];

        return implode("\n", $lines);
    }
}
