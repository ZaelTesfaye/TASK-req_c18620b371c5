<?php
namespace app\validate;

/**
 * PaymentValidate - Validation rules for payment and refund operations.
 */
class PaymentValidate
{
    public static function validatePayment(array $data): array
    {
        $errors = [];

        if (empty($data['tender_type'])) {
            $errors['tender_type'] = 'Tender type is required';
        } elseif (!in_array($data['tender_type'], ['cash', 'card_present_recorded', 'house_account'])) {
            $errors['tender_type'] = 'Invalid tender type. Must be cash, card_present_recorded, or house_account';
        }

        if (!isset($data['amount']) || !is_numeric($data['amount']) || $data['amount'] <= 0) {
            $errors['amount'] = 'Payment amount must be a positive number';
        }

        return $errors;
    }

    public static function validateRefund(array $data): array
    {
        $errors = [];

        if (empty($data['original_payment_id'])) {
            $errors['original_payment_id'] = 'Original payment ID is required';
        }

        if (!isset($data['amount']) || !is_numeric($data['amount']) || $data['amount'] <= 0) {
            $errors['amount'] = 'Refund amount must be a positive number';
        }

        if (empty(trim($data['reason'] ?? ''))) {
            $errors['reason'] = 'Refund reason is required';
        }

        return $errors;
    }
}
