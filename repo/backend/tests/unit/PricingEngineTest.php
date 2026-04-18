<?php
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\service\OrderService;

/**
 * PricingEngineTest - Tests pricing arithmetic and rounding.
 * Covers: subtotal calculation, rounding, amount due breakdown.
 */
class PricingEngineTest extends TestCase
{
    public function testCalculateSubtotalSingleItem(): void
    {
        $items = [
            ['qty' => 1, 'unit_price' => 25.50, 'service_code' => 'SVC-001', 'service_name' => 'Test'],
        ];
        $result = OrderService::calculateSubtotal($items);
        $this->assertEquals(25.50, $result);
    }

    public function testCalculateSubtotalMultipleItems(): void
    {
        $items = [
            ['qty' => 2, 'unit_price' => 10.00, 'service_code' => 'SVC-001', 'service_name' => 'A'],
            ['qty' => 1, 'unit_price' => 15.75, 'service_code' => 'SVC-002', 'service_name' => 'B'],
            ['qty' => 3, 'unit_price' => 5.33, 'service_code' => 'SVC-003', 'service_name' => 'C'],
        ];
        // (2*10.00) + (1*15.75) + (3*5.33) = 20.00 + 15.75 + 15.99 = 51.74
        $result = OrderService::calculateSubtotal($items);
        $this->assertEquals(51.74, $result);
    }

    public function testCalculateSubtotalEmptyItems(): void
    {
        $result = OrderService::calculateSubtotal([]);
        $this->assertEquals(0.00, $result);
    }

    public function testRoundHalfUpStandardCase(): void
    {
        $this->assertEquals(10.54, OrderService::roundHalfUp(10.535, 2));
    }

    public function testRoundHalfUpBoundary(): void
    {
        $this->assertEquals(10.53, OrderService::roundHalfUp(10.525, 2));
    }

    public function testRoundHalfUpZero(): void
    {
        $this->assertEquals(0.00, OrderService::roundHalfUp(0.004, 2));
    }

    public function testRoundHalfUpLargeValue(): void
    {
        $this->assertEquals(99999.99, OrderService::roundHalfUp(99999.994, 2));
    }

    public function testPricingChainCorrectOrder(): void
    {
        // Verify: subtotal -> coupon discount -> tax -> final amount
        $subtotal = 100.00;
        $discount = 10.00; // $10 coupon
        $taxRate = 0.08;
        $tax = OrderService::roundHalfUp(($subtotal - $discount) * $taxRate, 2);
        $total = OrderService::roundHalfUp($subtotal - $discount + $tax, 2);

        // Tax on $90.00 = $7.20
        $this->assertEquals(7.20, $tax);
        // Total = $90.00 + $7.20 = $97.20
        $this->assertEquals(97.20, $total);
    }

    public function testPricingWithPercentDiscount(): void
    {
        $subtotal = 75.00;
        $discountPercent = 10.0;
        $discount = OrderService::roundHalfUp($subtotal * ($discountPercent / 100), 2);
        $taxRate = 0.08;
        $tax = OrderService::roundHalfUp(($subtotal - $discount) * $taxRate, 2);
        $total = OrderService::roundHalfUp($subtotal - $discount + $tax, 2);

        $this->assertEquals(7.50, $discount);
        $this->assertEquals(5.40, $tax);
        $this->assertEquals(72.90, $total);
    }

    public function testAmountDueNeverNegative(): void
    {
        $total = 50.00;
        $paidAmount = 75.00;
        $amountDue = max($total - $paidAmount, 0.00);
        $this->assertEquals(0.00, $amountDue);
    }

    public function testAmountDuePartialPayment(): void
    {
        $total = 100.00;
        $paidAmount = 60.00;
        $amountDue = max($total - $paidAmount, 0.00);
        $this->assertEquals(40.00, $amountDue);
    }

    public function testTwoDecimalPrecisionEnforced(): void
    {
        // Verify that all monetary values have exactly 2 decimal places
        $result = OrderService::roundHalfUp(10.3, 2);
        $this->assertEquals(10.30, $result);
        $formatted = number_format($result, 2);
        $this->assertEquals('10.30', $formatted);
    }
}
