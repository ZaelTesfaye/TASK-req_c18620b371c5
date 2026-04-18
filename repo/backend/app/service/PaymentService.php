<?php
namespace app\service;

use app\common\AppConfig;
use app\logging\Logger;
use think\facade\Db;

/**
 * PaymentService - Offline tender recording, refund processing.
 * Supports: cash, card_present_recorded, house_account.
 * No external payment gateway integration (all mocked as offline tenders).
 */
class PaymentService
{
    /**
     * Record a payment for an order.
     * Mocking Payment Gateway response for audit stability -
     * all payments are recorded as offline tenders without external gateway calls.
     */
    public static function recordPayment(int $orderId, array $data, array $userContext): array
    {
        $order = Db::table('orders')->where('id', $orderId)->find();
        if (!$order) {
            return ['success' => false, 'error_code' => 'NOT_FOUND', 'message' => 'Order not found', 'status' => 404];
        }

        // Store isolation: non-admin users can only pay for their store's orders
        if (!in_array('administrator', $userContext['roles'] ?? [])) {
            if ($order['store_id'] != $userContext['store_id']) {
                return ['success' => false, 'error_code' => 'FORBIDDEN', 'message' => 'Access denied: order belongs to a different store', 'status' => 403];
            }
        }

        if (!in_array($data['tender_type'], ['cash', 'card_present_recorded', 'house_account'])) {
            return ['success' => false, 'error_code' => 'VALIDATION_ERROR', 'message' => 'Invalid tender type', 'status' => 400];
        }

        if ($data['amount'] <= 0) {
            return ['success' => false, 'error_code' => 'VALIDATION_ERROR', 'message' => 'Payment amount must be positive', 'status' => 400];
        }

        $before = $order;

        $paymentId = Db::table('payments')->insertGetId([
            'order_id'      => $orderId,
            'tender_type'   => $data['tender_type'],
            'amount'        => $data['amount'],
            'currency'      => 'USD',
            'recorded_by'   => $userContext['user_id'],
            'recorded_at'   => date('Y-m-d H:i:s'),
            'reference_note' => $data['reference_note'] ?? null,
        ]);

        // Update amount due
        $paidAmount = OrderService::getPaidAmount($orderId);
        $amountDue = max($order['total_amount'] - $paidAmount, 0.00);
        Db::table('orders')->where('id', $orderId)->update([
            'amount_due'  => $amountDue,
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        Logger::info('payment', 'record', "Payment recorded for order {$orderId}", [
            'payment_id' => $paymentId,
            'amount' => $data['amount'],
            'tender_type' => $data['tender_type'],
        ]);

        return [
            'success' => true,
            'data' => [
                'payment_id' => $paymentId,
                'amount' => $data['amount'],
                'amount_due' => $amountDue,
            ],
            'before' => $before,
        ];
    }

    /**
     * Process a refund.
     * Both full and partial refunds supported; linked to original order and tender.
     */
    public static function processRefund(int $orderId, array $data, array $userContext): array
    {
        $order = Db::table('orders')->where('id', $orderId)->find();
        if (!$order) {
            return ['success' => false, 'error_code' => 'NOT_FOUND', 'message' => 'Order not found', 'status' => 404];
        }

        // Store isolation: non-admin users can only refund their store's orders
        if (!in_array('administrator', $userContext['roles'] ?? [])) {
            if ($order['store_id'] != $userContext['store_id']) {
                return ['success' => false, 'error_code' => 'FORBIDDEN', 'message' => 'Access denied: order belongs to a different store', 'status' => 403];
            }
        }

        $originalPayment = Db::table('payments')->where('id', $data['original_payment_id'])->find();
        if (!$originalPayment || $originalPayment['order_id'] != $orderId) {
            return ['success' => false, 'error_code' => 'VALIDATION_ERROR', 'message' => 'Invalid original payment', 'status' => 400];
        }

        // Calculate refundable amount
        $totalRefunded = Db::table('refunds')
            ->where('original_payment_id', $data['original_payment_id'])
            ->whereIn('status', ['pending', 'approved', 'processed'])
            ->sum('amount');
        $refundableAmount = round($originalPayment['amount'] - $totalRefunded, 2);

        if ($data['amount'] > $refundableAmount) {
            return [
                'success' => false,
                'error_code' => 'REFUND_EXCEEDS_LIMIT',
                'message' => "Refund amount ($" . number_format($data['amount'], 2) .
                    ") exceeds refundable balance ($" . number_format($refundableAmount, 2) . ")",
                'status' => 400,
            ];
        }

        $refundNo = 'REF-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2)));
        $refundType = ($data['amount'] >= $originalPayment['amount']) ? 'full' : 'partial';

        $refundId = Db::table('refunds')->insertGetId([
            'refund_no'           => $refundNo,
            'order_id'            => $orderId,
            'original_payment_id' => $data['original_payment_id'],
            'refund_type'         => $refundType,
            'amount'              => $data['amount'],
            'reason'              => $data['reason'],
            'status'              => 'processed',
            'initiated_by'        => $userContext['user_id'],
            'processed_at'        => date('Y-m-d H:i:s'),
            'created_at'          => date('Y-m-d H:i:s'),
        ]);

        // Update order amount due
        $paidAmount = OrderService::getPaidAmount($orderId);
        $amountDue = max($order['total_amount'] - $paidAmount, 0.00);
        Db::table('orders')->where('id', $orderId)->update([
            'amount_due' => $amountDue,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        Logger::info('payment', 'refund', "Refund processed: {$refundNo}", [
            'refund_id' => $refundId,
            'order_id' => $orderId,
            'amount' => $data['amount'],
        ]);

        return [
            'success' => true,
            'data' => [
                'refund_id' => $refundId,
                'refund_no' => $refundNo,
                'amount' => $data['amount'],
                'amount_due' => $amountDue,
            ],
        ];
    }
}
