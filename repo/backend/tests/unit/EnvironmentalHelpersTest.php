<?php
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\service\EnvironmentalService;
use ReflectionClass;

/**
 * EnvironmentalHelpersTest - Tests EnvironmentalService private helper methods
 * via reflection to exercise the actual shipped implementation.
 */
class EnvironmentalHelpersTest extends TestCase
{
    private static ReflectionClass $ref;

    public static function setUpBeforeClass(): void
    {
        self::$ref = new ReflectionClass(EnvironmentalService::class);
    }

    private function invoke(string $method, array $args): mixed
    {
        $m = self::$ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs(null, $args);
    }

    // --- calculateConsistency ---

    public function testConsistencySingleValueReturns1(): void
    {
        $this->assertEquals(1.0, $this->invoke('calculateConsistency', [[72.0]]));
    }

    public function testConsistencyIdenticalValuesReturns1(): void
    {
        $this->assertEquals(1.0, $this->invoke('calculateConsistency', [[72.0, 72.0, 72.0]]));
    }

    public function testConsistencyCloseValuesHighScore(): void
    {
        $result = $this->invoke('calculateConsistency', [[71.0, 72.0, 73.0]]);
        $this->assertGreaterThan(0.98, $result);
    }

    public function testConsistencyWideValuesLowerScore(): void
    {
        $result = $this->invoke('calculateConsistency', [[20.0, 80.0]]);
        $this->assertLessThan(0.6, $result);
    }

    public function testConsistencyZeroMeanReturns1(): void
    {
        $this->assertEquals(1.0, $this->invoke('calculateConsistency', [[0.0, 0.0]]));
    }

    // --- calculateAlignment ---

    public function testAlignmentEmptyRecordsReturns0(): void
    {
        $this->assertEquals(0.0, $this->invoke('calculateAlignment', [[], '2025-01-15 10:00:00', 1]));
    }

    public function testAlignmentPerfectAlignmentReturns1(): void
    {
        $records = [['observed_at' => '2025-01-15 10:00:00']];
        $result = $this->invoke('calculateAlignment', [$records, '2025-01-15 10:00:00', 1]);
        $this->assertEquals(1.0, $result);
    }

    public function testAlignmentHalfBucketDriftReturnsHalf(): void
    {
        $records = [['observed_at' => '2025-01-15 10:00:30']];
        $result = $this->invoke('calculateAlignment', [$records, '2025-01-15 10:00:00', 1]);
        $this->assertEquals(0.5, $result);
    }

    public function testAlignmentBeyondBucketReturns0(): void
    {
        $records = [['observed_at' => '2025-01-15 10:02:00']];
        $result = $this->invoke('calculateAlignment', [$records, '2025-01-15 10:00:00', 1]);
        $this->assertEquals(0.0, $result);
    }

    // --- weightedMedian ---

    public function testWeightedMedianOddCount(): void
    {
        $this->assertEquals(72.0, $this->invoke('weightedMedian', [[71.0, 72.0, 73.0]]));
    }

    public function testWeightedMedianEvenCount(): void
    {
        $this->assertEquals(72.5, $this->invoke('weightedMedian', [[71.0, 72.0, 73.0, 74.0]]));
    }

    public function testWeightedMedianSingleValue(): void
    {
        $this->assertEquals(42.0, $this->invoke('weightedMedian', [[42.0]]));
    }

    public function testWeightedMedianTwoValues(): void
    {
        $this->assertEquals(55.0, $this->invoke('weightedMedian', [[50.0, 60.0]]));
    }

    public function testWeightedMedianUnsortedInput(): void
    {
        $this->assertEquals(72.0, $this->invoke('weightedMedian', [[73.0, 71.0, 72.0]]));
    }

    // --- getConfidenceLabel ---

    public function testConfidenceLabelHigh(): void
    {
        $this->assertEquals('High', $this->invoke('getConfidenceLabel', [0.85]));
        $this->assertEquals('High', $this->invoke('getConfidenceLabel', [0.90]));
        $this->assertEquals('High', $this->invoke('getConfidenceLabel', [1.00]));
    }

    public function testConfidenceLabelMedium(): void
    {
        $this->assertEquals('Medium', $this->invoke('getConfidenceLabel', [0.60]));
        $this->assertEquals('Medium', $this->invoke('getConfidenceLabel', [0.75]));
        $this->assertEquals('Medium', $this->invoke('getConfidenceLabel', [0.849]));
    }

    public function testConfidenceLabelLow(): void
    {
        $this->assertEquals('Low', $this->invoke('getConfidenceLabel', [0.00]));
        $this->assertEquals('Low', $this->invoke('getConfidenceLabel', [0.30]));
        $this->assertEquals('Low', $this->invoke('getConfidenceLabel', [0.599]));
    }

    // --- normalizeTemp ---

    public function testNormalizeTempComfortRange(): void
    {
        $this->assertEquals(1.0, $this->invoke('normalizeTemp', [68.0]));
        $this->assertEquals(1.0, $this->invoke('normalizeTemp', [72.0]));
        $this->assertEquals(1.0, $this->invoke('normalizeTemp', [76.0]));
    }

    public function testNormalizeTempExtremeHot(): void
    {
        $this->assertEquals(0.0, $this->invoke('normalizeTemp', [96.0]));
    }

    public function testNormalizeTempExtremeCold(): void
    {
        $this->assertEquals(0.0, $this->invoke('normalizeTemp', [49.0]));
    }

    public function testNormalizeTempBelowComfort(): void
    {
        $this->assertEquals(0.5, $this->invoke('normalizeTemp', [59.0]));
    }

    public function testNormalizeTempAboveComfort(): void
    {
        $this->assertEquals(0.5, $this->invoke('normalizeTemp', [85.5]));
    }

    // --- normalizeHumidity ---

    public function testNormalizeHumidityComfortRange(): void
    {
        $this->assertEquals(1.0, $this->invoke('normalizeHumidity', [30.0]));
        $this->assertEquals(1.0, $this->invoke('normalizeHumidity', [40.0]));
        $this->assertEquals(1.0, $this->invoke('normalizeHumidity', [50.0]));
    }

    public function testNormalizeHumidityTooLow(): void
    {
        $this->assertEquals(0.0, $this->invoke('normalizeHumidity', [9.0]));
    }

    public function testNormalizeHumidityTooHigh(): void
    {
        $this->assertEquals(0.0, $this->invoke('normalizeHumidity', [81.0]));
    }

    public function testNormalizeHumidityBelowComfort(): void
    {
        $this->assertEquals(0.5, $this->invoke('normalizeHumidity', [20.0]));
    }

    public function testNormalizeHumidityAboveComfort(): void
    {
        $this->assertEquals(0.5, $this->invoke('normalizeHumidity', [65.0]));
    }

    // --- normalizeAirQuality ---

    public function testNormalizeAirQualityGood(): void
    {
        $this->assertEquals(1.0, $this->invoke('normalizeAirQuality', [30.0]));
        $this->assertEquals(1.0, $this->invoke('normalizeAirQuality', [50.0]));
    }

    public function testNormalizeAirQualityDangerous(): void
    {
        $this->assertEquals(0.0, $this->invoke('normalizeAirQuality', [200.0]));
        $this->assertEquals(0.0, $this->invoke('normalizeAirQuality', [300.0]));
    }

    public function testNormalizeAirQualityModerate(): void
    {
        $this->assertEquals(0.5, $this->invoke('normalizeAirQuality', [125.0]));
    }

    // --- Composite comfort index (using real normalization helpers) ---

    public function testComfortIndexAllPerfect(): void
    {
        $temp = $this->invoke('normalizeTemp', [72.0]);
        $humidity = $this->invoke('normalizeHumidity', [40.0]);
        $airQuality = $this->invoke('normalizeAirQuality', [30.0]);
        $this->assertEquals(1.0, $temp);
        $this->assertEquals(1.0, $humidity);
        $this->assertEquals(1.0, $airQuality);
        $comfort = round(0.4 * $temp + 0.3 * $humidity + 0.3 * $airQuality, 6);
        $this->assertEquals(1.0, $comfort);
    }

    public function testComfortIndexWeightedCorrectly(): void
    {
        $temp = $this->invoke('normalizeTemp', [59.0]);       // 0.5
        $humidity = $this->invoke('normalizeHumidity', [20.0]); // 0.5
        $airQuality = $this->invoke('normalizeAirQuality', [125.0]); // 0.5
        $comfort = round(0.4 * $temp + 0.3 * $humidity + 0.3 * $airQuality, 6);
        $this->assertEquals(0.5, $comfort);
    }

    // --- Confidence score composition ---

    public function testConfidenceScoreHighComposite(): void
    {
        $completeness = 1.0;
        $consistency = $this->invoke('calculateConsistency', [[72.0, 72.1, 71.9]]);
        $alignment = $this->invoke('calculateAlignment', [
            [['observed_at' => '2025-01-15 10:00:02']],
            '2025-01-15 10:00:00', 1,
        ]);
        $score = round(0.4 * $completeness + 0.35 * $consistency + 0.25 * $alignment, 4);
        $this->assertEquals('High', $this->invoke('getConfidenceLabel', [$score]));
    }
}
