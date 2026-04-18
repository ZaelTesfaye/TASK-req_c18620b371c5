<?php
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\service\OrderService;

/**
 * DateParsingTest - Tests MM/DD/YYYY date parsing and edge cases.
 */
class DateParsingTest extends TestCase
{
    public function testValidDateParsing(): void
    {
        $this->assertEquals('2025-01-15', OrderService::parseMMDDYYYY('01/15/2025'));
    }

    public function testLeapYearDate(): void
    {
        $this->assertEquals('2024-02-29', OrderService::parseMMDDYYYY('02/29/2024'));
    }

    public function testInvalidLeapYear(): void
    {
        $this->assertNull(OrderService::parseMMDDYYYY('02/29/2025'));
    }

    public function testInvalidFormat(): void
    {
        $this->assertNull(OrderService::parseMMDDYYYY('2025-01-15'));
    }

    public function testInvalidMonth(): void
    {
        $this->assertNull(OrderService::parseMMDDYYYY('13/01/2025'));
    }

    public function testInvalidDay(): void
    {
        $this->assertNull(OrderService::parseMMDDYYYY('01/32/2025'));
    }

    public function testSingleDigitMonthDay(): void
    {
        $this->assertEquals('2025-01-05', OrderService::parseMMDDYYYY('1/5/2025'));
    }

    public function testEndOfMonth(): void
    {
        $this->assertEquals('2025-12-31', OrderService::parseMMDDYYYY('12/31/2025'));
    }

    public function testEmptyString(): void
    {
        $this->assertNull(OrderService::parseMMDDYYYY(''));
    }
}
