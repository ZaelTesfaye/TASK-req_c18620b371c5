<?php
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\service\AuthService;

/**
 * PasswordPolicyTest - Tests password validation rules.
 * Minimum 12 chars, uppercase, lowercase, digit, special character.
 */
class PasswordPolicyTest extends TestCase
{
    public function testValidPassword(): void
    {
        $result = AuthService::validatePasswordPolicy('Demo12345678!');
        $this->assertTrue($result['valid']);
    }

    public function testTooShort(): void
    {
        $result = AuthService::validatePasswordPolicy('Demo1234!');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('12', $result['message']);
    }

    public function testNoUppercase(): void
    {
        $result = AuthService::validatePasswordPolicy('demo12345678!');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('uppercase', $result['message']);
    }

    public function testNoLowercase(): void
    {
        $result = AuthService::validatePasswordPolicy('DEMO12345678!');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('lowercase', $result['message']);
    }

    public function testNoDigit(): void
    {
        $result = AuthService::validatePasswordPolicy('DemoPassword!!');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('digit', $result['message']);
    }

    public function testNoSpecialChar(): void
    {
        $result = AuthService::validatePasswordPolicy('Demo123456789');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('special', $result['message']);
    }

    public function testExactly12Characters(): void
    {
        $result = AuthService::validatePasswordPolicy('Demo12345!Aa');
        $this->assertTrue($result['valid']);
    }

    public function testEmptyPassword(): void
    {
        $result = AuthService::validatePasswordPolicy('');
        $this->assertFalse($result['valid']);
    }

    public function testPasswordHashAndVerify(): void
    {
        $password = 'SecurePass123!';
        $hash = AuthService::hashPassword($password);
        $this->assertTrue(AuthService::verifyPassword($password, $hash));
        $this->assertFalse(AuthService::verifyPassword('WrongPass123!', $hash));
    }
}
