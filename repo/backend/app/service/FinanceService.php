<?php
namespace app\service;

use app\common\AppConfig;
use app\logging\Logger;
use think\facade\Db;

/**
 * FinanceService - Cash drawer, daily reconciliation, discrepancy flagging.
 * Finance closes; Administrator reopens with mandatory reason.
 * Discrepancy flagged when abs(expected - counted) > $1.00.
 */
class FinanceService
{
    public static function getDailyDrawer(int $storeId, string $businessDate): ?array
    {
        return Db::table('cash_drawer_daily')
            ->where('store_id', $storeId)
            ->where('business_date', $businessDate)
            ->find();
    }

    public static function openDrawer(int $storeId, string $businessDate, float $openAmount, array $userContext): array
    {
        $existing = self::getDailyDrawer($storeId, $businessDate);
        if ($existing) {
            return ['success' => false, 'error_code' => 'CONFLICT', 'message' => 'Cash drawer already exists for this date', 'status' => 409];
        }

        $drawerId = Db::table('cash_drawer_daily')->insertGetId([
            'store_id'      => $storeId,
            'business_date' => $businessDate,
            'opened_by'     => $userContext['user_id'],
            'open_amount'   => $openAmount,
            'expected_total' => $openAmount,
            'status'        => 'open',
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        return ['success' => true, 'data' => ['id' => $drawerId]];
    }

    public static function closeDrawer(int $drawerId, float $countedTotal, array $userContext): array
    {
        $drawer = Db::table('cash_drawer_daily')->where('id', $drawerId)->find();
        if (!$drawer) {
            return ['success' => false, 'error_code' => 'NOT_FOUND', 'message' => 'Cash drawer not found', 'status' => 404];
        }

        if ($drawer['status'] === 'closed') {
            return ['success' => false, 'error_code' => 'CONFLICT', 'message' => 'Cash drawer is already closed', 'status' => 409];
        }

        // Calculate expected total = open_amount + all payments for this store/date
        $dayPayments = Db::table('payments')
            ->alias('p')
            ->join('orders o', 'p.order_id = o.id')
            ->where('o.store_id', $drawer['store_id'])
            ->where('p.recorded_at', '>=', $drawer['business_date'] . ' 00:00:00')
            ->where('p.recorded_at', '<=', $drawer['business_date'] . ' 23:59:59')
            ->sum('p.amount');

        $dayRefunds = Db::table('refunds')
            ->alias('r')
            ->join('orders o', 'r.order_id = o.id')
            ->where('o.store_id', $drawer['store_id'])
            ->where('r.processed_at', '>=', $drawer['business_date'] . ' 00:00:00')
            ->where('r.processed_at', '<=', $drawer['business_date'] . ' 23:59:59')
            ->where('r.status', 'processed')
            ->sum('r.amount');

        $expectedTotal = round($drawer['open_amount'] + $dayPayments - $dayRefunds, 2);
        $variance = round($expectedTotal - $countedTotal, 2);
        $discrepancyThreshold = AppConfig::get('discrepancy_threshold_usd', 1.00);
        $discrepancyFlag = abs($variance) > $discrepancyThreshold ? 1 : 0;

        $before = $drawer;

        Db::startTrans();
        try {
            Db::table('cash_drawer_daily')->where('id', $drawerId)->update([
                'expected_total'  => $expectedTotal,
                'counted_total'   => $countedTotal,
                'variance'        => $variance,
                'discrepancy_flag' => $discrepancyFlag,
                'status'          => 'closed',
                'closed_by'       => $userContext['user_id'],
                'closed_at'       => date('Y-m-d H:i:s'),
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);

            // Record reconciliation action
            Db::table('reconciliation_actions')->insert([
                'cash_drawer_daily_id' => $drawerId,
                'action_type'          => 'close',
                'reason'               => 'End-of-day close',
                'acted_by'             => $userContext['user_id'],
                'acted_at'             => date('Y-m-d H:i:s'),
            ]);

            // Generate immutable reconciliation statement
            $statementJson = json_encode([
                'store_id'       => $drawer['store_id'],
                'business_date'  => $drawer['business_date'],
                'open_amount'    => $drawer['open_amount'],
                'day_payments'   => $dayPayments,
                'day_refunds'    => $dayRefunds,
                'expected_total' => $expectedTotal,
                'counted_total'  => $countedTotal,
                'variance'       => $variance,
                'discrepancy'    => $discrepancyFlag ? true : false,
                'closed_by'      => $userContext['user_id'],
                'closed_at'      => date('Y-m-d H:i:s'),
            ]);

            Db::table('reconciliation_statements')->insert([
                'cash_drawer_daily_id' => $drawerId,
                'store_id'             => $drawer['store_id'],
                'business_date'        => $drawer['business_date'],
                'expected_total'       => $expectedTotal,
                'counted_total'        => $countedTotal,
                'variance'             => $variance,
                'discrepancy_flag'     => $discrepancyFlag,
                'statement_json'       => $statementJson,
                'generated_by'         => $userContext['user_id'],
                'generated_at'         => date('Y-m-d H:i:s'),
            ]);

            Db::commit();

            if ($discrepancyFlag) {
                Logger::warning('finance', 'discrepancy', "Discrepancy detected: variance $" . number_format(abs($variance), 2), [
                    'drawer_id' => $drawerId,
                    'expected' => $expectedTotal,
                    'counted' => $countedTotal,
                ]);
            }

            $updated = Db::table('cash_drawer_daily')->where('id', $drawerId)->find();
            return ['success' => true, 'data' => $updated, 'before' => $before];
        } catch (\Throwable $e) {
            Db::rollback();
            Logger::error('finance', 'close', 'Failed to close drawer: ' . $e->getMessage());
            return ['success' => false, 'error_code' => 'CLOSE_FAILED', 'message' => 'Failed to close cash drawer'];
        }
    }

    public static function reopenDrawer(int $drawerId, string $reason, array $userContext): array
    {
        // Only Administrator can reopen
        if (!in_array('administrator', $userContext['roles'])) {
            return ['success' => false, 'error_code' => 'FORBIDDEN', 'message' => 'Only Administrator can reopen closed reconciliation', 'status' => 403];
        }

        if (empty(trim($reason))) {
            return ['success' => false, 'error_code' => 'VALIDATION_ERROR', 'message' => 'Reason is required to reopen', 'status' => 400];
        }

        $drawer = Db::table('cash_drawer_daily')->where('id', $drawerId)->find();
        if (!$drawer) {
            return ['success' => false, 'error_code' => 'NOT_FOUND', 'message' => 'Cash drawer not found', 'status' => 404];
        }

        if ($drawer['status'] !== 'closed') {
            return ['success' => false, 'error_code' => 'CONFLICT', 'message' => 'Cash drawer is not in closed status', 'status' => 409];
        }

        $before = $drawer;

        Db::table('cash_drawer_daily')->where('id', $drawerId)->update([
            'status'     => 'reopened',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        Db::table('reconciliation_actions')->insert([
            'cash_drawer_daily_id' => $drawerId,
            'action_type'          => 'reopen',
            'reason'               => $reason,
            'acted_by'             => $userContext['user_id'],
            'acted_at'             => date('Y-m-d H:i:s'),
        ]);

        Logger::security('reconciliation_reopen', 'Cash drawer reopened', [
            'drawer_id' => $drawerId,
            'reason'    => $reason,
            'admin_id'  => $userContext['user_id'],
        ]);

        $updated = Db::table('cash_drawer_daily')->where('id', $drawerId)->find();
        return ['success' => true, 'data' => $updated, 'before' => $before];
    }

    public static function getReconciliationStatement(int $drawerId): ?array
    {
        return Db::table('reconciliation_statements')
            ->where('cash_drawer_daily_id', $drawerId)
            ->order('generated_at', 'desc')
            ->find();
    }

    public static function getReconciliationStatementCsv(int $drawerId): ?string
    {
        $statement = self::getReconciliationStatement($drawerId);
        if (!$statement) {
            return null;
        }

        $data = json_decode($statement['statement_json'], true);
        $lines = [];
        $lines[] = implode(',', ['Field', 'Value']);
        $lines[] = implode(',', ['Store ID', $data['store_id']]);
        $lines[] = implode(',', ['Business Date', date('m/d/Y', strtotime($data['business_date']))]);
        $lines[] = implode(',', ['Open Amount', '$' . number_format($data['open_amount'], 2)]);
        $lines[] = implode(',', ['Day Payments', '$' . number_format($data['day_payments'], 2)]);
        $lines[] = implode(',', ['Day Refunds', '$' . number_format($data['day_refunds'], 2)]);
        $lines[] = implode(',', ['Expected Total', '$' . number_format($data['expected_total'], 2)]);
        $lines[] = implode(',', ['Counted Total', '$' . number_format($data['counted_total'], 2)]);
        $lines[] = implode(',', ['Variance', '$' . number_format($data['variance'], 2)]);
        $lines[] = implode(',', ['Discrepancy', $data['discrepancy'] ? 'YES' : 'NO']);
        $lines[] = implode(',', ['Closed At', $data['closed_at']]);

        return implode("\n", $lines);
    }

    public static function getExceptions(int $storeId): array
    {
        return Db::table('cash_drawer_daily')
            ->where('store_id', $storeId)
            ->where('discrepancy_flag', 1)
            ->order('business_date', 'desc')
            ->select()
            ->toArray();
    }
}
