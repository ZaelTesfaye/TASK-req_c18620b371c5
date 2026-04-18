<?php
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\validate\PaymentValidate;

/**
 * PaymentValidateTest - Tests payment and refund validation rules.
 */
class PaymentValidateTest extends TestCase
{
    // Payment validation

    public function testValidPaymentReturnsNoErrors(): void
    {
        $data = ['tender_type' => 'cash', 'amount' => 50.00];
        $errors = PaymentValidate::validatePayment($data);
        $this->assertEmpty($errors);
    }

    public function testMissingTenderTypeReturnsError(): void
    {
        $data = ['amount' => 50.00];
        $errors = PaymentValidate::validatePayment($data);
        $this->assertArrayHasKey('tender_type', $errors);
    }

    public function testInvalidTenderTypeReturnsError(): void
    {
        $data = ['tender_type' => 'bitcoin', 'amount' => 50.00];
        $errors = PaymentValidate::validatePayment($data);
        $this->assertArrayHasKey('tender_type', $errors);
    }

    public function testCashTenderAccepted(): void
    {
        $data = ['tender_type' => 'cash', 'amount' => 10.00];
        $errors = PaymentValidate::validatePayment($data);
        $this->assertArrayNotHasKey('tender_type', $errors);
    }

    public function testCardPresentRecordedAccepted(): void
    {
        $data = ['tender_type' => 'card_present_recorded', 'amount' => 10.00];
        $errors = PaymentValidate::validatePayment($data);
        $this->assertArrayNotHasKey('tender_type', $errors);
    }

    public function testHouseAccountAccepted(): void
    {
        $data = ['tender_type' => 'house_account', 'amount' => 10.00];
        $errors = PaymentValidate::validatePayment($data);
        $this->assertArrayNotHasKey('tender_type', $errors);
    }

    public function testZeroAmountReturnsError(): void
    {
        $data = ['tender_type' => 'cash', 'amount' => 0];
        $errors = PaymentValidate::validatePayment($data);
        $this->assertArrayHasKey('amount', $errors);
    }

    public function testNegativeAmountReturnsError(): void
    {
        $data = ['tender_type' => 'cash', 'amount' => -10.00];
        $errors = PaymentValidate::validatePayment($data);
        $this->assertArrayHasKey('amount', $errors);
    }

    public function testNonNumericAmountReturnsError(): void
    {
        $data = ['tender_type' => 'cash', 'amount' => 'abc'];
        $errors = PaymentValidate::validatePayment($data);
        $this->assertArrayHasKey('amount', $errors);
    }

    public function testMissingAmountReturnsError(): void
    {
        $data = ['tender_type' => 'cash'];
        $errors = PaymentValidate::validatePayment($data);
        $this->assertArrayHasKey('amount', $errors);
    }

    // Refund validation

    public function testValidRefundReturnsNoErrors(): void
    {
        $data = [
            'original_payment_id' => 1,
            'amount' => 25.00,
            'reason' => 'Customer dissatisfied',
        ];
        $errors = PaymentValidate::validateRefund($data);
        $this->assertEmpty($errors);
    }

    public function testMissingOriginalPaymentIdReturnsError(): void
    {
        $data = ['amount' => 25.00, 'reason' => 'Reason'];
        $errors = PaymentValidate::validateRefund($data);
        $this->assertArrayHasKey('original_payment_id', $errors);
    }

    public function testMissingRefundAmountReturnsError(): void
    {
        $data = ['original_payment_id' => 1, 'reason' => 'Reason'];
        $errors = PaymentValidate::validateRefund($data);
        $this->assertArrayHasKey('amount', $errors);
    }

    public function testMissingRefundReasonReturnsError(): void
    {
        $data = ['original_payment_id' => 1, 'amount' => 25.00];
        $errors = PaymentValidate::validateRefund($data);
        $this->assertArrayHasKey('reason', $errors);
    }

    public function testWhitespaceOnlyReasonReturnsError(): void
    {
        $data = ['original_payment_id' => 1, 'amount' => 25.00, 'reason' => '   '];
        $errors = PaymentValidate::validateRefund($data);
        $this->assertArrayHasKey('reason', $errors);
    }

    public function testAllRefundFieldsMissingReturnsAllErrors(): void
    {
        $errors = PaymentValidate::validateRefund([]);
        $this->assertCount(3, $errors);
    }
}
