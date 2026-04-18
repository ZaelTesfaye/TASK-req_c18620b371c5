<?php
namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * AuthApiTest - API tests for authentication endpoints.
 * Routes: auth/login (POST), auth/logout (POST), auth/me (GET), auth/password/reset (POST)
 */
class AuthApiTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('APP_URL') ?: 'http://localhost:8000';
    }

    private function request(string $method, string $path, array $data = [], ?string $token = null): array
    {
        $url = $this->baseUrl . '/api/v1/' . ltrim($path, '/');
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($token) {
            $headers[] = "Authorization: Bearer {$token}";
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
            curl_setopt($ch, CURLOPT_URL, $url);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status' => $httpCode,
            'body' => json_decode($response, true) ?? [],
        ];
    }

    private function loginAsAdmin(): ?string
    {
        $response = $this->request('POST', 'auth/login', [
            'username' => 'admin',
            'password' => 'Demo12345678!',
            'store_id' => 1,
            'workstation_id' => 1,
        ]);
        return $response['body']['data']['token'] ?? null;
    }

    public function testLoginSuccess(): void
    {
        $response = $this->request('POST', 'auth/login', [
            'username' => 'admin',
            'password' => 'Demo12345678!',
            'store_id' => 1,
            'workstation_id' => 1,
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertNotEmpty($response['body']['data']['token']);
        $this->assertNotEmpty($response['body']['data']['user']['roles']);
        $this->assertEquals('admin', $response['body']['data']['user']['username']);
        $this->assertEquals(1, $response['body']['data']['user']['store_id']);
        $this->assertEquals(1, $response['body']['data']['user']['workstation_id']);
    }

    public function testLoginInvalidCredentials(): void
    {
        $response = $this->request('POST', 'auth/login', [
            'username' => 'admin',
            'password' => 'WrongPassword123!',
            'store_id' => 1,
            'workstation_id' => 1,
        ]);

        $this->assertEquals(401, $response['status']);
        $this->assertFalse($response['body']['success']);
        $this->assertEquals('INVALID_CREDENTIALS', $response['body']['error_code']);
        $this->assertArrayNotHasKey('token', $response['body']['data'] ?? []);
    }

    public function testLoginMissingFields(): void
    {
        $response = $this->request('POST', 'auth/login', [
            'username' => 'admin',
        ]);

        $this->assertEquals(400, $response['status']);
        $this->assertFalse($response['body']['success']);
    }

    public function testLogoutInvalidatesSession(): void
    {
        $token = $this->loginAsAdmin();
        $this->assertNotNull($token);

        // Logout
        $logoutResp = $this->request('POST', 'auth/logout', [], $token);
        $this->assertEquals(200, $logoutResp['status']);

        // Verify token no longer works
        $meResp = $this->request('GET', 'auth/me', [], $token);
        $this->assertEquals(401, $meResp['status']);
        $this->assertEquals('UNAUTHORIZED', $meResp['body']['error_code']);
    }

    public function testGetMeReturnsUserProfile(): void
    {
        $token = $this->loginAsAdmin();
        $this->assertNotNull($token);

        $response = $this->request('GET', 'auth/me', [], $token);
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertNotEmpty($response['body']['data']['username'] ?? $response['body']['data']['user']['username'] ?? '');
    }

    public function testGetMeUnauthenticated(): void
    {
        $response = $this->request('GET', 'auth/me');
        $this->assertEquals(401, $response['status']);
        $this->assertFalse($response['body']['success']);
        $this->assertEquals('UNAUTHORIZED', $response['body']['error_code']);
    }

    public function testAccessProtectedRouteWithoutToken(): void
    {
        $response = $this->request('GET', 'orders');
        $this->assertEquals(401, $response['status']);
        $this->assertFalse($response['body']['success']);
        $this->assertEquals('UNAUTHORIZED', $response['body']['error_code']);
        $this->assertNotEmpty($response['body']['request_id']);
    }

    public function testAccessProtectedRouteWithInvalidToken(): void
    {
        $response = $this->request('GET', 'orders', [], 'invalid_token_here');
        $this->assertEquals(401, $response['status']);
        $this->assertFalse($response['body']['success']);
        $this->assertEquals('UNAUTHORIZED', $response['body']['error_code']);
    }

    public function testInvalidStoreWorkstationBinding(): void
    {
        $response = $this->request('POST', 'auth/login', [
            'username' => 'admin',
            'password' => 'Demo12345678!',
            'store_id' => 999,
            'workstation_id' => 999,
        ]);

        $this->assertFalse($response['body']['success']);
        $this->assertEquals('INVALID_BINDING', $response['body']['error_code']);
    }

    // Public bootstrap endpoints (used by the login page without a token)

    public function testBootstrapStoresIsPublic(): void
    {
        $response = $this->request('GET', 'auth/bootstrap/stores');
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertIsArray($response['body']['data']);
        foreach ($response['body']['data'] as $store) {
            // Only id and name should be exposed to unauthenticated callers
            $this->assertArrayHasKey('id', $store);
            $this->assertArrayHasKey('name', $store);
            $this->assertCount(2, $store);
        }
    }

    // POST /auth/password/reset — requires an authenticated session; the caller
    // supplies old+new password for their own account.

    public function testPasswordResetRequiresAuth(): void
    {
        $response = $this->request('POST', 'auth/password/reset', [
            'old_password' => 'Demo12345678!',
            'new_password' => 'NewStrongPass456!',
        ]);
        $this->assertEquals(401, $response['status']);
        $this->assertEquals('UNAUTHORIZED', $response['body']['error_code']);
    }

    public function testPasswordResetRejectsWrongOldPassword(): void
    {
        $token = $this->loginAsAdmin();
        $this->assertNotNull($token);

        $response = $this->request('POST', 'auth/password/reset', [
            'old_password' => 'WrongOldPassword!',
            'new_password' => 'NewStrongPass456!',
        ], $token);
        $this->assertEquals(400, $response['status']);
        $this->assertFalse($response['body']['success']);
        $this->assertEquals('INVALID_PASSWORD', $response['body']['error_code']);
    }

    public function testPasswordResetRejectsWeakNewPassword(): void
    {
        $token = $this->loginAsAdmin();
        $this->assertNotNull($token);

        $response = $this->request('POST', 'auth/password/reset', [
            'old_password' => 'Demo12345678!',
            'new_password' => 'weak',
        ], $token);
        $this->assertEquals(400, $response['status']);
        $this->assertFalse($response['body']['success']);
        $this->assertEquals('WEAK_PASSWORD', $response['body']['error_code']);
    }

    public function testPasswordResetMissingFields(): void
    {
        $token = $this->loginAsAdmin();
        $this->assertNotNull($token);

        $response = $this->request('POST', 'auth/password/reset', [
            'old_password' => '',
        ], $token);
        $this->assertEquals(400, $response['status']);
        $this->assertEquals('VALIDATION_ERROR', $response['body']['error_code']);
    }

    public function testBootstrapWorkstationsIsPublic(): void
    {
        $response = $this->request('GET', 'auth/bootstrap/workstations', ['store_id' => 1]);
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertIsArray($response['body']['data']);
        foreach ($response['body']['data'] as $ws) {
            $this->assertArrayHasKey('id', $ws);
            $this->assertArrayHasKey('name', $ws);
            $this->assertArrayHasKey('store_id', $ws);
            $this->assertCount(3, $ws);
        }
    }
}
