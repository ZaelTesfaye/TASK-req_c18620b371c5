<?php
namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * AdminApiTest - API tests for admin management endpoints.
 * Routes: admin/users (GET/POST), admin/users/:id/roles (PATCH),
 * admin/bindings/reassign-store-workstation (POST),
 * admin/encryption/keys/rotate (POST), admin/stores, admin/workstations
 */
class AdminApiTest extends TestCase
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

        return ['status' => $httpCode, 'body' => json_decode($response, true) ?? []];
    }

    private function loginAs(string $username): ?string
    {
        $storeId = 1;
        $wsId = 1;
        if ($username === 'customer1') { $wsId = 3; }

        $response = $this->request('POST', 'auth/login', [
            'username' => $username,
            'password' => 'Demo12345678!',
            'store_id' => $storeId,
            'workstation_id' => $wsId,
        ]);
        return $response['body']['data']['token'] ?? null;
    }

    // Auth guard tests

    public function testListUsersRequiresAuth(): void
    {
        $response = $this->request('GET', 'admin/users');
        $this->assertEquals(401, $response['status']);
    }

    public function testCreateUserRequiresAuth(): void
    {
        $response = $this->request('POST', 'admin/users');
        $this->assertEquals(401, $response['status']);
    }

    public function testKeyRotationRequiresAuth(): void
    {
        $response = $this->request('POST', 'admin/encryption/keys/rotate');
        $this->assertEquals(401, $response['status']);
    }

    public function testBindingReassignRequiresAuth(): void
    {
        $response = $this->request('POST', 'admin/bindings/reassign-store-workstation');
        $this->assertEquals(401, $response['status']);
    }

    public function testRoleUpdateRequiresAuth(): void
    {
        $response = $this->request('PATCH', 'admin/users/1/roles');
        $this->assertEquals(401, $response['status']);
    }

    // RBAC tests

    public function testAdminCanListUsers(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $response = $this->request('GET', 'admin/users', [], $token);
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success'] ?? false);
    }

    public function testNonAdminCannotListUsers(): void
    {
        $token = $this->loginAs('frontdesk1');
        if (!$token) { $this->markTestSkipped('frontdesk1 not available'); }

        $response = $this->request('GET', 'admin/users', [], $token);
        $this->assertEquals(403, $response['status']);
    }

    public function testCustomerCannotListUsers(): void
    {
        $token = $this->loginAs('customer1');
        if (!$token) { $this->markTestSkipped('customer1 not available'); }

        $response = $this->request('GET', 'admin/users', [], $token);
        $this->assertEquals(403, $response['status']);
    }

    // Create user validation

    public function testCreateUserWithWeakPasswordFails(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $response = $this->request('POST', 'admin/users', [
            'username' => 'testuser_' . time(),
            'password' => 'weak',
            'roles' => ['front_desk'],
        ], $token);
        $this->assertEquals(400, $response['status']);
        $this->assertFalse($response['body']['success']);
        $this->assertEquals('WEAK_PASSWORD', $response['body']['error_code']);
    }

    public function testCreateUserWithStrongPassword(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $username = 'testuser_' . time();
        $response = $this->request('POST', 'admin/users', [
            'username' => $username,
            'password' => 'StrongPass123!@#',
            'roles' => ['front_desk'],
        ], $token);
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);

        // Verify user is retrievable via user list
        $listResp = $this->request('GET', 'admin/users', [], $token);
        $this->assertEquals(200, $listResp['status']);
        $found = false;
        foreach ($listResp['body']['data'] as $user) {
            if (($user['username'] ?? '') === $username) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "Created user '{$username}' should appear in user list");
    }

    // Key rotation

    public function testKeyRotationAdminOnly(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $response = $this->request('POST', 'admin/encryption/keys/rotate', [
            'new_version' => 99,
        ], $token);
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
    }

    // Stores and workstations

    public function testListStoresRequiresAuth(): void
    {
        $response = $this->request('GET', 'admin/stores');
        $this->assertEquals(401, $response['status']);
    }

    public function testAdminCanListStores(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $response = $this->request('GET', 'admin/stores', [], $token);
        $this->assertEquals(200, $response['status']);
    }

    public function testAdminCanListWorkstations(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $response = $this->request('GET', 'admin/workstations', [], $token);
        $this->assertEquals(200, $response['status']);
    }
}
