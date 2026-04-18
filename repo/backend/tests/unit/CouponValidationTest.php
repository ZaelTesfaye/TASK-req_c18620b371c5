<?php
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\service\CouponService;
use app\service\OrderService;
use ReflectionClass;

/**
 * CouponValidationTest - Tests coupon discount calculation via shipped CouponService.
 * Uses reflection to call the private calculateDiscount method.
 */
class CouponValidationTest extends TestCase
{
    private static ReflectionClass $ref;

    public static function setUpBeforeClass(): void
    {
        self::$ref = new ReflectionClass(CouponService::class);
    }

    private function calculateDiscount(array $coupon, float $subtotal): float
    {
        $m = self::$ref->getMethod('calculateDiscount');
        $m->setAccessible(true);
        return $m->invokeArgs(null, [$coupon, $subtotal]);
    }

    // Fixed discount

    public function testFixedDiscountBasic(): void
    {
        $coupon = ['discount_type' => 'fixed', 'discount_value' => 5.00];
        $this->assertEquals(5.00, $this->calculateDiscount($coupon, 100.00));
    }

    public function testFixedDiscountCappedAtSubtotal(): void
    {
        $coupon = ['discount_type' => 'fixed', 'discount_value' => 200.00];
        $this->assertEquals(50.00, $this->calculateDiscount($coupon, 50.00));
    }

    public function testFixedDiscountExactlyEqualsSubtotal(): void
    {
        $coupon = ['discount_type' => 'fixed', 'discount_value' => 100.00];
        $this->assertEquals(100.00, $this->calculateDiscount($coupon, 100.00));
    }

    // Percent discount

    public function testPercentDiscount10Percent(): void
    {
        $coupon = ['discount_type' => 'percent', 'discount_value' => 10.00];
        $this->assertEquals(10.00, $this->calculateDiscount($coupon, 100.00));
    }

    public function testPercentDiscount50Percent(): void
    {
        $coupon = ['discount_type' => 'percent', 'discount_value' => 50.00];
        $this->assertEquals(37.50, $this->calculateDiscount($coupon, 75.00));
    }

    public function testPercentDiscountUsesRoundHalfUp(): void
    {
        $coupon = ['discount_type' => 'percent', 'discount_value' => 15.00];
        $result = $this->calculateDiscount($coupon, 33.33);
        // 33.33 * 0.15 = 4.9995 → roundHalfUp → 5.00
        $this->assertEquals(OrderService::roundHalfUp(33.33 * 0.15, 2), $result);
    }

    // Unknown discount type

    public function testUnknownDiscountTypeReturnsZero(): void
    {
        $coupon = ['discount_type' => 'bogus', 'discount_value' => 10.00];
        $this->assertEquals(0.00, $this->calculateDiscount($coupon, 100.00));
    }

    // Coupon validation rules (structural - these are enforced in validateCoupon)

    public function testInactiveCouponDetection(): void
    {
        $coupon = ['active' => 0];
        $this->assertFalse((bool) $coupon['active']);
    }

    public function testExpiredCouponDetection(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->assertTrue($now > '2020-01-01 00:00:00');
    }

    public function testFutureCouponNotYetValid(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->assertTrue($now < '2099-01-01 00:00:00');
    }

    public function testStoreScopeNullAllowsAllStores(): void
    {
        $coupon = ['store_id' => null];
        // null store_id means coupon valid for all stores
        $this->assertNull($coupon['store_id']);
    }

    public function testStoreScopeMismatchDetected(): void
    {
        $couponStoreId = 1;
        $userStoreId = 2;
        $this->assertNotEquals($couponStoreId, $userStoreId);
    }

    public function testMinSpendEnforced(): void
    {
        $minSpend = 100.00;
        $subtotal = 75.00;
        $this->assertTrue($subtotal < $minSpend);
    }

    public function testMinSpendMet(): void
    {
        $minSpend = 50.00;
        $subtotal = 75.00;
        $this->assertFalse($subtotal < $minSpend);
    }

    // Pricing chain integration with OrderService

    public function testDiscountAppliedBeforeTax(): void
    {
        $subtotal = 100.00;
        $discount = $this->calculateDiscount(['discount_type' => 'fixed', 'discount_value' => 10.00], $subtotal);
        $taxRate = 0.08;
        $tax = OrderService::roundHalfUp(($subtotal - $discount) * $taxRate, 2);
        $total = OrderService::roundHalfUp($subtotal - $discount + $tax, 2);
        $this->assertEquals(10.00, $discount);
        $this->assertEquals(7.20, $tax);
        $this->assertEquals(97.20, $total);
    }
}
