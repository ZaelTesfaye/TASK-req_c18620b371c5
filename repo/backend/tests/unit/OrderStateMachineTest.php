<?php
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\service\OrderService;
use ReflectionClass;

/**
 * OrderStateMachineTest - Tests order state transition guards using
 * the shipped TRANSITIONS constant from OrderService via reflection.
 */
class OrderStateMachineTest extends TestCase
{
    private static array $transitions;

    public static function setUpBeforeClass(): void
    {
        $ref = new ReflectionClass(OrderService::class);
        $prop = $ref->getReflectionConstant('TRANSITIONS');
        self::$transitions = $prop->getValue();
    }

    public function testDraftCanTransitionToConfirmed(): void
    {
        $this->assertContains('confirmed', self::$transitions['draft']);
    }

    public function testDraftCanTransitionToCancelled(): void
    {
        $this->assertContains('cancelled', self::$transitions['draft']);
    }

    public function testConfirmedCanTransitionToAssigned(): void
    {
        $this->assertContains('assigned', self::$transitions['confirmed']);
    }

    public function testAssignedCanTransitionToInProgress(): void
    {
        $this->assertContains('in_progress', self::$transitions['assigned']);
    }

    public function testInProgressCanTransitionToCompleted(): void
    {
        $this->assertContains('completed', self::$transitions['in_progress']);
    }

    public function testCompletedIsTerminal(): void
    {
        $this->assertEmpty(self::$transitions['completed']);
    }

    public function testCancelledIsTerminal(): void
    {
        $this->assertEmpty(self::$transitions['cancelled']);
    }

    public function testCannotTransitionFromCompletedToCancelled(): void
    {
        $this->assertNotContains('cancelled', self::$transitions['completed']);
    }

    public function testCannotTransitionFromCancelledToAnything(): void
    {
        $this->assertEmpty(self::$transitions['cancelled']);
    }

    public function testCannotSkipToCompleted(): void
    {
        $this->assertNotContains('completed', self::$transitions['draft']);
        $this->assertNotContains('completed', self::$transitions['confirmed']);
        $this->assertNotContains('completed', self::$transitions['assigned']);
    }

    public function testCannotReverseTransition(): void
    {
        $this->assertNotContains('draft', self::$transitions['confirmed']);
        $this->assertNotContains('confirmed', self::$transitions['assigned']);
        $this->assertNotContains('assigned', self::$transitions['in_progress']);
    }

    public function testAllPreCompletionStatesAllowCancellation(): void
    {
        $preCompletionStates = ['draft', 'confirmed', 'assigned', 'in_progress'];
        foreach ($preCompletionStates as $state) {
            $this->assertContains('cancelled', self::$transitions[$state],
                "State '{$state}' should allow cancellation");
        }
    }

    public function testValidTransitionSequence(): void
    {
        $sequence = ['draft', 'confirmed', 'assigned', 'in_progress', 'completed'];
        for ($i = 0; $i < count($sequence) - 1; $i++) {
            $current = $sequence[$i];
            $next = $sequence[$i + 1];
            $this->assertContains($next, self::$transitions[$current],
                "Should be able to transition from '{$current}' to '{$next}'");
        }
    }

    // Test shipped static helpers

    public function testCalculateSubtotalViaShippedMethod(): void
    {
        $items = [
            ['qty' => 2, 'unit_price' => 10.00, 'service_code' => 'A', 'service_name' => 'A'],
            ['qty' => 1, 'unit_price' => 15.75, 'service_code' => 'B', 'service_name' => 'B'],
        ];
        $this->assertEquals(35.75, OrderService::calculateSubtotal($items));
    }

    public function testRoundHalfUpViaShippedMethod(): void
    {
        $this->assertEquals(10.54, OrderService::roundHalfUp(10.535, 2));
        $this->assertEquals(10.53, OrderService::roundHalfUp(10.525, 2));
    }

    public function testParseMMDDYYYYViaShippedMethod(): void
    {
        $this->assertEquals('2025-01-15', OrderService::parseMMDDYYYY('01/15/2025'));
        $this->assertNull(OrderService::parseMMDDYYYY('13/01/2025'));
        $this->assertNull(OrderService::parseMMDDYYYY('not-a-date'));
    }
}
