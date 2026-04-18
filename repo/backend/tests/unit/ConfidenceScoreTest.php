<?php
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\service\EnvironmentalService;
use ReflectionClass;

/**
 * ConfidenceScoreTest - Tests confidence score and label assignment
 * via reflection on the shipped EnvironmentalService.
 */
class ConfidenceScoreTest extends TestCase
{
    private static ReflectionClass $ref;

    public static function setUpBeforeClass(): void
    {
        self::$ref = new ReflectionClass(EnvironmentalService::class);
    }

    private function getConfidenceLabel(float $score): string
    {
        $m = self::$ref->getMethod('getConfidenceLabel');
        $m->setAccessible(true);
        return $m->invokeArgs(null, [$score]);
    }

    private function calculateConsistency(array $values): float
    {
        $m = self::$ref->getMethod('calculateConsistency');
        $m->setAccessible(true);
        return $m->invokeArgs(null, [$values]);
    }

    public function testHighConfidence(): void
    {
        $this->assertEquals('High', $this->getConfidenceLabel(0.85));
        $this->assertEquals('High', $this->getConfidenceLabel(0.90));
        $this->assertEquals('High', $this->getConfidenceLabel(1.00));
    }

    public function testMediumConfidence(): void
    {
        $this->assertEquals('Medium', $this->getConfidenceLabel(0.60));
        $this->assertEquals('Medium', $this->getConfidenceLabel(0.75));
        $this->assertEquals('Medium', $this->getConfidenceLabel(0.84));
    }

    public function testLowConfidence(): void
    {
        $this->assertEquals('Low', $this->getConfidenceLabel(0.00));
        $this->assertEquals('Low', $this->getConfidenceLabel(0.30));
        $this->assertEquals('Low', $this->getConfidenceLabel(0.59));
    }

    public function testBoundaryExactly085(): void
    {
        $this->assertEquals('High', $this->getConfidenceLabel(0.85));
    }

    public function testBoundaryExactly060(): void
    {
        $this->assertEquals('Medium', $this->getConfidenceLabel(0.60));
    }

    public function testBoundaryJustBelow085(): void
    {
        $this->assertEquals('Medium', $this->getConfidenceLabel(0.849));
    }

    public function testBoundaryJustBelow060(): void
    {
        $this->assertEquals('Low', $this->getConfidenceLabel(0.599));
    }

    public function testWeightedScoreComputation(): void
    {
        $completeness = 1.0;
        $consistency = $this->calculateConsistency([72.0, 72.1, 71.9]);
        $alignment = 0.8;
        $score = round(0.4 * $completeness + 0.35 * $consistency + 0.25 * $alignment, 4);
        $this->assertGreaterThan(0.85, $score);
        $this->assertEquals('High', $this->getConfidenceLabel($score));
    }

    public function testLowConsistencyDrivesLowConfidence(): void
    {
        $completeness = 0.5;
        $consistency = $this->calculateConsistency([20.0, 80.0]);
        $alignment = 0.3;
        $score = round(0.4 * $completeness + 0.35 * $consistency + 0.25 * $alignment, 4);
        $this->assertEquals('Low', $this->getConfidenceLabel($score));
    }
}
