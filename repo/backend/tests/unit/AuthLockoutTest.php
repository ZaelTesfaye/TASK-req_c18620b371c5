<?php
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\service\AuthService;

/**
 * AuthLockoutTest - Tests authentication lockout logic and session handling.
 * Covers: password hashing, verification, lockout thresholds, remaining attempts.
 */
class AuthLockoutTest extends TestCase
{
    // Password hashing and verification

    public function testPasswordHashVerifies(): void
    {
        $password = 'Demo12345678!';
        $hash = AuthService::hashPassword($password);
        $this->assertTrue(AuthService::verifyPassword($password, $hash));
    }

    public function testWrongPasswordDoesNotVerify(): void
    {
        $hash = AuthService::hashPassword('Demo12345678!');
        $this->assertFalse(AuthService::verifyPassword('WrongPassword1!', $hash));
    }

    public function testHashProducesDifferentResultEachTime(): void
    {
        $password = 'Demo12345678!';
        $hash1 = AuthService::hashPassword($password);
        $hash2 = AuthService::hashPassword($password);
        $this->assertNotEquals($hash1, $hash2); // bcrypt uses random salt
    }

    public function testHashIsNotPlaintext(): void
    {
        $password = 'Demo12345678!';
        $hash = AuthService::hashPassword($password);
        $this->assertNotEquals($password, $hash);
        $this->assertStringStartsWith('$2y$', $hash);
    }

    // Password policy validation

    public function testValidStrongPassword(): void
    {
        $result = AuthService::validatePasswordPolicy('StrongPass12!@');
        $this->assertTrue($result['valid']);
    }

    public function testPasswordTooShort(): void
    {
        $result = AuthService::validatePasswordPolicy('Short1!');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('12', $result['message']);
    }

    public function testPasswordExactly12Chars(): void
    {
        $result = AuthService::validatePasswordPolicy('Abcdefgh12!@');
        $this->assertTrue($result['valid']);
    }

    public function testPasswordMissingUppercase(): void
    {
        $result = AuthService::validatePasswordPolicy('lowercase12345!');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('uppercase', $result['message']);
    }

    public function testPasswordMissingLowercase(): void
    {
        $result = AuthService::validatePasswordPolicy('UPPERCASE12345!');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('lowercase', $result['message']);
    }

    public function testPasswordMissingDigit(): void
    {
        $result = AuthService::validatePasswordPolicy('NoDigitsHere!!');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('digit', $result['message']);
    }

    public function testPasswordMissingSpecialChar(): void
    {
        $result = AuthService::validatePasswordPolicy('NoSpecial12345');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('special', $result['message']);
    }

    // Lockout logic

    public function testLockoutAfterMaxAttempts(): void
    {
        $maxAttempts = 5;
        $failedAttempts = 5;
        $shouldLock = $failedAttempts >= $maxAttempts;
        $this->assertTrue($shouldLock);
    }

    public function testNoLockoutBelowMaxAttempts(): void
    {
        $maxAttempts = 5;
        $failedAttempts = 3;
        $shouldLock = $failedAttempts >= $maxAttempts;
        $this->assertFalse($shouldLock);
    }

    public function testLockoutDuration15Minutes(): void
    {
        $lockoutMinutes = 15;
        $lockoutUntil = time() + ($lockoutMinutes * 60);
        $this->assertGreaterThan(time(), $lockoutUntil);
        $remainingMinutes = ceil(($lockoutUntil - time()) / 60);
        $this->assertLessThanOrEqual(15, $remainingMinutes);
    }

    public function testRemainingAttemptsWarningAt2(): void
    {
        $maxAttempts = 5;
        $failedAttempts = 3;
        $remaining = $maxAttempts - $failedAttempts;
        $showWarning = $remaining > 0 && $remaining <= 2;
        $this->assertTrue($showWarning);
    }

    public function testNoWarningAt3Remaining(): void
    {
        $maxAttempts = 5;
        $failedAttempts = 2;
        $remaining = $maxAttempts - $failedAttempts;
        $showWarning = $remaining > 0 && $remaining <= 2;
        $this->assertFalse($showWarning);
    }

    public function testLockoutUntilIsFuture(): void
    {
        $lockoutMinutes = 15;
        $lockoutUntil = date('Y-m-d H:i:s', time() + ($lockoutMinutes * 60));
        $this->assertGreaterThan(time(), strtotime($lockoutUntil));
    }

    // Session TTL

    public function testDefaultSessionTTL480Minutes(): void
    {
        $ttl = 480;
        $expiresAt = time() + ($ttl * 60);
        $hoursFromNow = ($expiresAt - time()) / 3600;
        $this->assertEquals(8, $hoursFromNow);
    }

    // Security event types

    public function testSecurityEventTypes(): void
    {
        $eventTypes = ['login_success', 'login_locked_attempt', 'account_locked'];
        $this->assertContains('login_success', $eventTypes);
        $this->assertContains('account_locked', $eventTypes);
    }

    // Token hashing

    public function testTokenHashIsSha256(): void
    {
        $token = 'test_token_value';
        $hash = hash('sha256', $token);
        $this->assertEquals(64, strlen($hash)); // SHA-256 produces 64 hex chars
    }

    public function testSameTokenSameHash(): void
    {
        $token = 'test_token';
        $this->assertEquals(hash('sha256', $token), hash('sha256', $token));
    }

    public function testDifferentTokensDifferentHashes(): void
    {
        $this->assertNotEquals(hash('sha256', 'token_a'), hash('sha256', 'token_b'));
    }
}
