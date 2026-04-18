<?php
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\common\AppConfig;
use app\service\OrderService;

/**
 * DiscrepancyThresholdTest - Tests reconciliation discrepancy boundary
 * using shipped AppConfig for threshold and OrderService for rounding.
 *
 * The discrepancy check in FinanceService::closeDrawer (line 74-75) is:
 *   $variance = round($expectedTotal - $countedTotal, 2);
 *   $discrepancyFlag = abs($variance) > $discrepancyThreshold ? 1 : 0;
 *
 * We replicate this exact formula and verify against the shipped threshold.
 */
class DiscrepancyThresholdTest extends TestCase
{
    private float $threshold;

    protected function setUp(): void
    {
        $this->threshold = AppConfig::get('discrepancy_threshold_usd', 1.00);
    }

    private function computeDiscrepancy(float $openAmount, float $dayPayments, float $dayRefunds, float $countedTotal): array
    {
        $expectedTotal = round($openAmount + $dayPayments - $dayRefunds, 2);
        $variance = round($expectedTotal - $countedTotal, 2);
        $discrepancyFlag = abs($variance) > $this->threshold ? 1 : 0;
        return [
            'expected_total' => $expectedTotal,
            'variance' => $variance,
            'discrepancy_flag' => $discrepancyFlag,
        ];
    }

    public function testDefaultThresholdIs1Dollar(): void
    {
        $this->assertEquals(1.00, $this->threshold);
    }

    public function testNoDiscrepancyWhenEqual(): void
    {
        $result = $this->computeDiscrepancy(100.00, 0, 0, 100.00);
        $this->assertEquals(0.00, $result['variance']);
        $this->assertEquals(0, $result['discrepancy_flag']);
    }

    public function testNoDiscrepancyAtExactBoundary(): void
    {
        $result = $this->computeDiscrepancy(100.00, 0, 0, 99.00);
        $this->assertEquals(1.00, $result['variance']);
        $this->assertEquals(0, $result['discrepancy_flag']); // > not >=
    }

    public function testDiscrepancyJustOverBoundary(): void
    {
        $result = $this->computeDiscrepancy(100.00, 0, 0, 98.99);
        $this->assertEquals(1.01, $result['variance']);
        $this->assertEquals(1, $result['discrepancy_flag']);
    }

    public function testDiscrepancyLargeVariance(): void
    {
        $result = $this->computeDiscrepancy(500.00, 0, 0, 475.00);
        $this->assertEquals(25.00, $result['variance']);
        $this->assertEquals(1, $result['discrepancy_flag']);
    }

    public function testExpectedTotalFormula(): void
    {
        $result = $this->computeDiscrepancy(200.00, 1500.00, 50.00, 1648.50);
        $this->assertEquals(1650.00, $result['expected_total']);
        $this->assertEquals(1.50, $result['variance']);
        $this->assertEquals(1, $result['discrepancy_flag']);
    }

    public function testNegativeVarianceOverCount(): void
    {
        $result = $this->computeDiscrepancy(100.00, 0, 0, 102.00);
        $this->assertEquals(-2.00, $result['variance']);
        $this->assertEquals(1, $result['discrepancy_flag']);
    }

    public function testSmallAmountsFollowSameRule(): void
    {
        $result = $this->computeDiscrepancy(2.00, 0, 0, 1.50);
        $this->assertEquals(0.50, $result['variance']);
        $this->assertEquals(0, $result['discrepancy_flag']);
    }

    public function testRefundsReduceExpected(): void
    {
        $result = $this->computeDiscrepancy(100.00, 500.00, 200.00, 400.00);
        $this->assertEquals(400.00, $result['expected_total']);
        $this->assertEquals(0.00, $result['variance']);
        $this->assertEquals(0, $result['discrepancy_flag']);
    }

    public function testRoundingViaShippedOrderService(): void
    {
        // Verify that the rounding matches the shipped roundHalfUp
        $val = OrderService::roundHalfUp(1650.005, 2);
        $this->assertEquals(1650.01, $val);
    }
}
