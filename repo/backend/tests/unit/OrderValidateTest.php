<?php
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\validate\OrderValidate;

/**
 * OrderValidateTest - Tests order validation rules.
 * Covers: create validation, cancel validation, assign technician, work note.
 */
class OrderValidateTest extends TestCase
{
    // Create validation

    public function testValidCreateDataReturnsNoErrors(): void
    {
        $data = [
            'customer_name' => 'John Doe',
            'items' => [
                ['service_code' => 'SVC-001', 'service_name' => 'Oil Change', 'qty' => 1, 'unit_price' => 49.99],
            ],
        ];
        $errors = OrderValidate::validateCreate($data);
        $this->assertEmpty($errors);
    }

    public function testMissingCustomerNameReturnsError(): void
    {
        $data = [
            'items' => [
                ['service_code' => 'SVC-001', 'service_name' => 'Test', 'qty' => 1, 'unit_price' => 10],
            ],
        ];
        $errors = OrderValidate::validateCreate($data);
        $this->assertArrayHasKey('customer_name', $errors);
    }

    public function testEmptyItemsArrayReturnsError(): void
    {
        $data = ['customer_name' => 'John', 'items' => []];
        $errors = OrderValidate::validateCreate($data);
        $this->assertArrayHasKey('items', $errors);
    }

    public function testMissingItemsFieldReturnsError(): void
    {
        $data = ['customer_name' => 'John'];
        $errors = OrderValidate::validateCreate($data);
        $this->assertArrayHasKey('items', $errors);
    }

    public function testItemMissingServiceCodeReturnsError(): void
    {
        $data = [
            'customer_name' => 'John',
            'items' => [
                ['service_name' => 'Test', 'qty' => 1, 'unit_price' => 10],
            ],
        ];
        $errors = OrderValidate::validateCreate($data);
        $this->assertArrayHasKey('items.0.service_code', $errors);
    }

    public function testItemMissingServiceNameReturnsError(): void
    {
        $data = [
            'customer_name' => 'John',
            'items' => [
                ['service_code' => 'SVC-001', 'qty' => 1, 'unit_price' => 10],
            ],
        ];
        $errors = OrderValidate::validateCreate($data);
        $this->assertArrayHasKey('items.0.service_name', $errors);
    }

    public function testItemZeroQtyReturnsError(): void
    {
        $data = [
            'customer_name' => 'John',
            'items' => [
                ['service_code' => 'SVC-001', 'service_name' => 'Test', 'qty' => 0, 'unit_price' => 10],
            ],
        ];
        $errors = OrderValidate::validateCreate($data);
        $this->assertArrayHasKey('items.0.qty', $errors);
    }

    public function testItemNegativePriceReturnsError(): void
    {
        $data = [
            'customer_name' => 'John',
            'items' => [
                ['service_code' => 'SVC-001', 'service_name' => 'Test', 'qty' => 1, 'unit_price' => -5],
            ],
        ];
        $errors = OrderValidate::validateCreate($data);
        $this->assertArrayHasKey('items.0.unit_price', $errors);
    }

    public function testInvoiceRequestedRequiresAllInvoiceFields(): void
    {
        $data = [
            'customer_name' => 'John',
            'items' => [
                ['service_code' => 'SVC-001', 'service_name' => 'Test', 'qty' => 1, 'unit_price' => 10],
            ],
            'invoice_requested' => true,
        ];
        $errors = OrderValidate::validateCreate($data);
        $this->assertArrayHasKey('invoice_taxpayer_id', $errors);
        $this->assertArrayHasKey('invoice_entity_name', $errors);
        $this->assertArrayHasKey('invoice_identifier', $errors);
    }

    public function testInvoiceNotRequestedDoesNotRequireInvoiceFields(): void
    {
        $data = [
            'customer_name' => 'John',
            'items' => [
                ['service_code' => 'SVC-001', 'service_name' => 'Test', 'qty' => 1, 'unit_price' => 10],
            ],
        ];
        $errors = OrderValidate::validateCreate($data);
        $this->assertArrayNotHasKey('invoice_taxpayer_id', $errors);
    }

    public function testInvalidChannelReturnsError(): void
    {
        $data = [
            'customer_name' => 'John',
            'items' => [
                ['service_code' => 'SVC-001', 'service_name' => 'Test', 'qty' => 1, 'unit_price' => 10],
            ],
            'channel' => 'invalid_channel',
        ];
        $errors = OrderValidate::validateCreate($data);
        $this->assertArrayHasKey('channel', $errors);
    }

    public function testValidChannelKioskAccepted(): void
    {
        $data = [
            'customer_name' => 'John',
            'items' => [
                ['service_code' => 'SVC-001', 'service_name' => 'Test', 'qty' => 1, 'unit_price' => 10],
            ],
            'channel' => 'kiosk',
        ];
        $errors = OrderValidate::validateCreate($data);
        $this->assertArrayNotHasKey('channel', $errors);
    }

    // Cancel validation

    public function testValidCancelReturnsNoErrors(): void
    {
        $errors = OrderValidate::validateCancel(['reason' => 'Customer changed mind']);
        $this->assertEmpty($errors);
    }

    public function testEmptyReasonReturnsError(): void
    {
        $errors = OrderValidate::validateCancel(['reason' => '']);
        $this->assertArrayHasKey('reason', $errors);
    }

    public function testWhitespaceOnlyReasonReturnsError(): void
    {
        $errors = OrderValidate::validateCancel(['reason' => '   ']);
        $this->assertArrayHasKey('reason', $errors);
    }

    // Assign technician validation

    public function testValidAssignTechnicianReturnsNoErrors(): void
    {
        $errors = OrderValidate::validateAssignTechnician(['technician_id' => 5]);
        $this->assertEmpty($errors);
    }

    public function testMissingTechnicianIdReturnsError(): void
    {
        $errors = OrderValidate::validateAssignTechnician([]);
        $this->assertArrayHasKey('technician_id', $errors);
    }

    // Work note validation

    public function testValidWorkNoteReturnsNoErrors(): void
    {
        $errors = OrderValidate::validateWorkNote(['note' => 'Replaced brake pads']);
        $this->assertEmpty($errors);
    }

    public function testEmptyWorkNoteReturnsError(): void
    {
        $errors = OrderValidate::validateWorkNote(['note' => '']);
        $this->assertArrayHasKey('note', $errors);
    }
}
