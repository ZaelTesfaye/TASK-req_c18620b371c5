<?php
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\validate\AuthValidate;

/**
 * AuthValidateTest - Tests authentication validation rules.
 * Covers: login validation, password reset validation.
 */
class AuthValidateTest extends TestCase
{
    // Login validation

    public function testValidLoginDataReturnsNoErrors(): void
    {
        $data = [
            'username' => 'admin',
            'password' => 'Demo12345678!',
            'store_id' => 1,
            'workstation_id' => 1,
        ];
        $errors = AuthValidate::validateLogin($data);
        $this->assertEmpty($errors);
    }

    public function testMissingUsernameReturnsError(): void
    {
        $data = ['password' => 'Pass', 'store_id' => 1, 'workstation_id' => 1];
        $errors = AuthValidate::validateLogin($data);
        $this->assertArrayHasKey('username', $errors);
    }

    public function testMissingPasswordReturnsError(): void
    {
        $data = ['username' => 'admin', 'store_id' => 1, 'workstation_id' => 1];
        $errors = AuthValidate::validateLogin($data);
        $this->assertArrayHasKey('password', $errors);
    }

    public function testMissingStoreIdReturnsError(): void
    {
        $data = ['username' => 'admin', 'password' => 'Pass', 'workstation_id' => 1];
        $errors = AuthValidate::validateLogin($data);
        $this->assertArrayHasKey('store_id', $errors);
    }

    public function testMissingWorkstationIdReturnsError(): void
    {
        $data = ['username' => 'admin', 'password' => 'Pass', 'store_id' => 1];
        $errors = AuthValidate::validateLogin($data);
        $this->assertArrayHasKey('workstation_id', $errors);
    }

    public function testAllFieldsMissingReturnsAllErrors(): void
    {
        $errors = AuthValidate::validateLogin([]);
        $this->assertCount(4, $errors);
    }

    public function testEmptyStringFieldsReturnErrors(): void
    {
        $data = ['username' => '', 'password' => '', 'store_id' => '', 'workstation_id' => ''];
        $errors = AuthValidate::validateLogin($data);
        $this->assertCount(4, $errors);
    }

    // Password reset validation

    public function testValidPasswordResetReturnsNoErrors(): void
    {
        $data = ['old_password' => 'OldPass123!@#', 'new_password' => 'NewPass456!@#'];
        $errors = AuthValidate::validatePasswordReset($data);
        $this->assertEmpty($errors);
    }

    public function testMissingOldPasswordReturnsError(): void
    {
        $data = ['new_password' => 'NewPass456!@#'];
        $errors = AuthValidate::validatePasswordReset($data);
        $this->assertArrayHasKey('old_password', $errors);
    }

    public function testMissingNewPasswordReturnsError(): void
    {
        $data = ['old_password' => 'OldPass123!@#'];
        $errors = AuthValidate::validatePasswordReset($data);
        $this->assertArrayHasKey('new_password', $errors);
    }

    public function testBothPasswordsMissingReturnsAllErrors(): void
    {
        $errors = AuthValidate::validatePasswordReset([]);
        $this->assertCount(2, $errors);
    }
}
