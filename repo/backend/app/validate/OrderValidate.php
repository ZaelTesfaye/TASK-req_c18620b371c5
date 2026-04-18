<?php
namespace app\validate;

/**
 * OrderValidate - Validation rules for order operations.
 */
class OrderValidate
{
    public static function validateCreate(array $data): array
    {
        $errors = [];

        if (empty($data['customer_name'])) {
            $errors['customer_name'] = 'Customer name is required';
        }

        if (empty($data['items']) || !is_array($data['items'])) {
            $errors['items'] = 'At least one service item is required';
        } else {
            foreach ($data['items'] as $index => $item) {
                if (empty($item['service_code'])) {
                    $errors["items.{$index}.service_code"] = 'Service code is required';
                }
                if (empty($item['service_name'])) {
                    $errors["items.{$index}.service_name"] = 'Service name is required';
                }
                if (!isset($item['qty']) || $item['qty'] < 1) {
                    $errors["items.{$index}.qty"] = 'Quantity must be at least 1';
                }
                if (!isset($item['unit_price']) || $item['unit_price'] < 0) {
                    $errors["items.{$index}.unit_price"] = 'Unit price must be non-negative';
                }
            }
        }

        // Invoice validation - conditional required fields
        if (!empty($data['invoice_requested'])) {
            if (empty($data['invoice_taxpayer_id'])) {
                $errors['invoice_taxpayer_id'] = 'Taxpayer ID is required when invoice is requested';
            }
            if (empty($data['invoice_entity_name'])) {
                $errors['invoice_entity_name'] = 'Legal entity name is required when invoice is requested';
            }
            if (empty($data['invoice_identifier'])) {
                $errors['invoice_identifier'] = 'Invoice identifier is required when invoice is requested';
            }
        }

        if (!empty($data['channel']) && !in_array($data['channel'], ['kiosk', 'front_desk'])) {
            $errors['channel'] = 'Channel must be kiosk or front_desk';
        }

        return $errors;
    }

    public static function validateCancel(array $data): array
    {
        $errors = [];
        if (empty(trim($data['reason'] ?? ''))) {
            $errors['reason'] = 'Cancellation reason is required';
        }
        return $errors;
    }

    public static function validateAssignTechnician(array $data): array
    {
        $errors = [];
        if (empty($data['technician_id'])) {
            $errors['technician_id'] = 'Technician ID is required';
        }
        return $errors;
    }

    public static function validateWorkNote(array $data): array
    {
        $errors = [];
        if (empty(trim($data['note'] ?? ''))) {
            $errors['note'] = 'Work note text is required';
        }
        return $errors;
    }
}
