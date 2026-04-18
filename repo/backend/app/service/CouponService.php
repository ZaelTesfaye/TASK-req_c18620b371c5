<?php
namespace app\service;

use app\logging\Logger;
use think\facade\Db;

/**
 * CouponService - Coupon validation and redemption.
 * One coupon per order, validated by active window, store scope, min spend, usage limits.
 */
class CouponService
{
    public static function validateCoupon(string $code, int $orderId, array $userContext): array
    {
        // Store-scoped ownership check must run BEFORE any coupon logic so a
        // cross-store order_id never leaks validation details (e.g., "coupon
        // not found" vs "min spend not met") about an order the caller does
        // not own. Mirrors OrderService::updateOrder ownership guard.
        $ownership = self::enforceOrderOwnership($orderId, $userContext);
        if ($ownership !== null) {
            return $ownership;
        }

        $coupon = Db::table('coupons')->where('code', $code)->find();

        if (!$coupon) {
            return ['valid' => false, 'reason' => 'Coupon code not found'];
        }

        if (!$coupon['active']) {
            return ['valid' => false, 'reason' => 'Coupon is not active'];
        }

        $now = date('Y-m-d H:i:s');
        if ($now < $coupon['valid_from'] || $now > $coupon['valid_to']) {
            return ['valid' => false, 'reason' => 'Coupon is not within valid date range'];
        }

        // Store scope check
        if ($coupon['store_id'] !== null && $coupon['store_id'] != $userContext['store_id']) {
            return ['valid' => false, 'reason' => 'Coupon is not valid for this store'];
        }

        // Check if order already has a coupon
        $existingRedemption = Db::table('coupon_redemptions')
            ->where('order_id', $orderId)
            ->whereNull('rejection_reason')
            ->find();
        if ($existingRedemption) {
            return ['valid' => false, 'reason' => 'Order already has a coupon applied (one coupon per order)'];
        }

        $order = Db::table('orders')->where('id', $orderId)->find();
        if (!$order) {
            return ['valid' => false, 'reason' => 'Order not found'];
        }

        // Min spend check
        if ($order['subtotal_amount'] < $coupon['min_spend']) {
            return [
                'valid' => false,
                'reason' => 'Order subtotal ($' . number_format($order['subtotal_amount'], 2) .
                    ') does not meet minimum spend of $' . number_format($coupon['min_spend'], 2),
            ];
        }

        // Total usage limit
        if ($coupon['usage_limit_total'] !== null) {
            $totalUsed = Db::table('coupon_redemptions')
                ->where('coupon_id', $coupon['id'])
                ->whereNull('rejection_reason')
                ->count();
            if ($totalUsed >= $coupon['usage_limit_total']) {
                return ['valid' => false, 'reason' => 'Coupon has reached its total usage limit'];
            }
        }

        // Per-user usage limit
        if ($coupon['usage_limit_per_user'] !== null) {
            $userUsed = Db::table('coupon_redemptions')
                ->where('coupon_id', $coupon['id'])
                ->where('redeemed_by', $userContext['user_id'])
                ->whereNull('rejection_reason')
                ->count();
            if ($userUsed >= $coupon['usage_limit_per_user']) {
                return ['valid' => false, 'reason' => 'You have reached the per-user usage limit for this coupon'];
            }
        }

        // Calculate discount
        $discount = self::calculateDiscount($coupon, $order['subtotal_amount']);

        return [
            'valid' => true,
            'coupon' => $coupon,
            'discount_amount' => $discount,
        ];
    }

    public static function applyCoupon(string $code, int $orderId, array $userContext): array
    {
        // Ownership must be enforced at the apply entry point as well —
        // validateCoupon enforces it too, but we want the apply-shape error
        // ({success, error_code, status}) before any redemption rows get
        // written, and before any rejection row would be logged against
        // another store's order.
        $ownership = self::enforceOrderOwnership($orderId, $userContext);
        if ($ownership !== null) {
            return [
                'success'    => false,
                'error_code' => $ownership['error_code'],
                'message'    => $ownership['reason'],
                'status'     => $ownership['status'],
            ];
        }

        $validation = self::validateCoupon($code, $orderId, $userContext);

        if (!$validation['valid']) {
            // Log rejection
            $coupon = Db::table('coupons')->where('code', $code)->find();
            if ($coupon) {
                Db::table('coupon_redemptions')->insert([
                    'coupon_id'        => $coupon['id'],
                    'order_id'         => $orderId,
                    'redeemed_by'      => $userContext['user_id'],
                    'redeemed_at'      => date('Y-m-d H:i:s'),
                    'rejection_reason' => $validation['reason'],
                ]);
            }

            return ['success' => false, 'error_code' => 'COUPON_INVALID', 'message' => $validation['reason']];
        }

        $coupon = $validation['coupon'];
        $discount = $validation['discount_amount'];

        Db::startTrans();
        try {
            // Record redemption
            Db::table('coupon_redemptions')->insert([
                'coupon_id'   => $coupon['id'],
                'order_id'    => $orderId,
                'redeemed_by' => $userContext['user_id'],
                'redeemed_at' => date('Y-m-d H:i:s'),
            ]);

            // Recalculate order pricing
            $order = Db::table('orders')->where('id', $orderId)->find();
            $subtotal = $order['subtotal_amount'];
            $taxRate = \app\common\AppConfig::get('default_tax_rate', 0.08);
            $taxAmount = OrderService::roundHalfUp(($subtotal - $discount) * $taxRate, 2);
            $totalAmount = OrderService::roundHalfUp($subtotal - $discount + $taxAmount, 2);
            $paidAmount = OrderService::getPaidAmount($orderId);
            $amountDue = max($totalAmount - $paidAmount, 0.00);

            Db::table('orders')->where('id', $orderId)->update([
                'discount_amount' => $discount,
                'tax_amount'      => $taxAmount,
                'total_amount'    => $totalAmount,
                'amount_due'      => $amountDue,
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);

            Db::commit();

            Logger::info('coupon', 'apply', "Coupon {$code} applied to order {$orderId}", [
                'discount' => $discount,
            ]);

            $updated = Db::table('orders')->where('id', $orderId)->find();
            return ['success' => true, 'data' => $updated, 'discount_amount' => $discount];
        } catch (\Throwable $e) {
            Db::rollback();
            Logger::error('coupon', 'apply', 'Failed to apply coupon: ' . $e->getMessage());
            return ['success' => false, 'error_code' => 'APPLY_FAILED', 'message' => 'Failed to apply coupon'];
        }
    }

    /**
     * Returns null when the caller owns the order, or a validate-shape
     * error payload ({valid: false, error_code, status, reason}) when the
     * order does not exist or belongs to a different store.
     *
     * Administrators bypass the store-scope check (mirrors OrderService).
     */
    private static function enforceOrderOwnership(int $orderId, array $userContext): ?array
    {
        $order = Db::table('orders')->where('id', $orderId)->find();
        if (!$order) {
            return [
                'valid'      => false,
                'error_code' => 'NOT_FOUND',
                'status'     => 404,
                'reason'     => 'Order not found',
            ];
        }

        $roles = $userContext['roles'] ?? [];
        if (!in_array('administrator', $roles) && $order['store_id'] != $userContext['store_id']) {
            return [
                'valid'      => false,
                'error_code' => 'FORBIDDEN',
                'status'     => 403,
                'reason'     => 'Access denied',
            ];
        }

        return null;
    }

    private static function calculateDiscount(array $coupon, float $subtotal): float
    {
        if ($coupon['discount_type'] === 'fixed') {
            return min($coupon['discount_value'], $subtotal);
        }

        if ($coupon['discount_type'] === 'percent') {
            return OrderService::roundHalfUp($subtotal * ($coupon['discount_value'] / 100), 2);
        }

        return 0.00;
    }
}
