<?php
namespace app\validate;

/**
 * AuthValidate - Validation rules for authentication operations.
 */
class AuthValidate
{
    public static function validateLogin(array $data): array
    {
        $errors = [];

        if (empty($data['username'])) {
            $errors['username'] = 'Username is required';
        }

        if (empty($data['password'])) {
            $errors['password'] = 'Password is required';
        }

        if (empty($data['store_id'])) {
            $errors['store_id'] = 'Store selection is required';
        }

        if (empty($data['workstation_id'])) {
            $errors['workstation_id'] = 'Workstation selection is required';
        }

        return $errors;
    }

    public static function validatePasswordReset(array $data): array
    {
        $errors = [];

        if (empty($data['old_password'])) {
            $errors['old_password'] = 'Current password is required';
        }

        if (empty($data['new_password'])) {
            $errors['new_password'] = 'New password is required';
        }

        return $errors;
    }
}
