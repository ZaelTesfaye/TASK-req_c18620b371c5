<?php
namespace tests\api;

use PHPUnit\Framework\TestCase;
use tests\StatusCodes;

/**
 * LockoutProgressionTest - Steps through failed login attempts deterministically
 * and verifies the account locks at the correct threshold (5 attempts).
 */
class LockoutProgressionTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('APP_URL') ?: 'http://localhost:8000';
    }

    private function request(string $method, string $path, array $data = []): array
    {
        $url = $this->baseUrl . '/api/v1/' . ltrim($path, '/');
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $httpCode, 'body' => json_decode($response, true) ?? []];
    }

    /**
     * Test the full lockout progression: 5 wrong attempts → lock → correct password rejected.
     * Uses a dedicated test user to avoid interfering with other tests.
     */
    public function testLockoutAfterFiveFailedAttempts(): void
    {
        // First, create a test user via admin (to avoid locking a shared user)
        $adminLogin = $this->request('POST', 'auth/login', [
            'username' => 'admin',
            'password' => 'Demo12345678!',
            'store_id' => 1,
            'workstation_id' => 1,
        ]);
        $adminToken = $adminLogin['body']['data']['token'] ?? null;
        if (!$adminToken) {
            $this->markTestSkipped('Admin login not available');
        }

        $testUser = 'lockout_test_' . time();

        // Create test user
        $url = $this->baseUrl . '/api/v1/admin/users';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $adminToken,
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'username' => $testUser,
            'password' => 'StrongPass123!@#',
            'role_codes' => ['front_desk'],
            'bindings' => [['store_id' => 1, 'workstation_id' => 1]],
        ]));
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 201 && $code !== 200) {
            $this->markTestSkipped('Could not create test user for lockout test');
        }

        // Attempt 1-4: wrong password, should get 401 INVALID_CREDENTIALS
        for ($i = 1; $i <= 4; $i++) {
            $resp = $this->request('POST', 'auth/login', [
                'username' => $testUser,
                'password' => 'WrongPassword!',
                'store_id' => 1,
                'workstation_id' => 1,
            ]);
            $this->assertFalse($resp['body']['success'], "Attempt {$i} should fail");
            $this->assertEquals(StatusCodes::INVALID_CREDENTIALS, $resp['status'],
                "Attempt {$i} should return HTTP 401 for invalid credentials");
            $this->assertEquals('INVALID_CREDENTIALS', $resp['body']['error_code'],
                "Attempt {$i} should return INVALID_CREDENTIALS, not ACCOUNT_LOCKED");
        }

        // Attempt 4 should show remaining attempts warning
        $this->assertStringContainsString('remaining', $resp['body']['message'] ?? '');

        // Attempt 5: triggers lockout
        $resp5 = $this->request('POST', 'auth/login', [
            'username' => $testUser,
            'password' => 'WrongPassword!',
            'store_id' => 1,
            'workstation_id' => 1,
        ]);
        $this->assertFalse($resp5['body']['success']);
        // After 5th attempt, message should mention "locked"
        $this->assertStringContainsString('locked', strtolower($resp5['body']['message'] ?? ''));

        // Attempt 6: even correct password should be rejected (locked)
        $resp6 = $this->request('POST', 'auth/login', [
            'username' => $testUser,
            'password' => 'StrongPass123!@#',
            'store_id' => 1,
            'workstation_id' => 1,
        ]);
        $this->assertFalse($resp6['body']['success']);
        $this->assertEquals('ACCOUNT_LOCKED', $resp6['body']['error_code']);
        // AuthController maps ACCOUNT_LOCKED to HTTP 403 — assert the status
        // explicitly so a controller-side remap doesn't silently pass this test.
        $this->assertEquals(StatusCodes::ACCOUNT_LOCKED, $resp6['status'],
            'Locked account should return HTTP 403, not just the ACCOUNT_LOCKED body code');
    }
}
