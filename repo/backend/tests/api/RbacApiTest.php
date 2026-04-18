<?php
namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * RbacApiTest - Tests role-based access control across all major endpoints.
 * Verifies 403 FORBIDDEN responses with correct error_code for unauthorized access,
 * and 200 with success=true for authorized access.
 */
class RbacApiTest extends TestCase
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
        if ($username === 'tech1') { $wsId = 2; }
        if ($username === 'customer1') { $wsId = 3; }

        $response = $this->request('POST', 'auth/login', [
            'username' => $username,
            'password' => 'Demo12345678!',
            'store_id' => $storeId,
            'workstation_id' => $wsId,
        ]);
        return $response['body']['data']['token'] ?? null;
    }

    private function assertForbidden(array $response): void
    {
        $this->assertEquals(403, $response['status']);
        $this->assertFalse($response['body']['success'] ?? true);
        $this->assertEquals('FORBIDDEN', $response['body']['error_code'] ?? '');
        $this->assertNotEmpty($response['body']['message'] ?? '');
    }

    private function assertAuthorized(array $response): void
    {
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success'] ?? false);
    }

    // Admin-only: GET admin/users

    public function testCustomerCannotAccessAdmin(): void
    {
        $token = $this->loginAs('customer1');
        $this->assertForbidden($this->request('GET', 'admin/users', [], $token));
    }

    public function testTechnicianCannotAccessAdmin(): void
    {
        $token = $this->loginAs('tech1');
        $this->assertForbidden($this->request('GET', 'admin/users', [], $token));
    }

    public function testFrontDeskCannotAccessAdmin(): void
    {
        $token = $this->loginAs('frontdesk1');
        $this->assertForbidden($this->request('GET', 'admin/users', [], $token));
    }

    public function testAdminCanAccessAdmin(): void
    {
        $token = $this->loginAs('admin');
        $response = $this->request('GET', 'admin/users', [], $token);
        $this->assertAuthorized($response);
        $this->assertIsArray($response['body']['data']);
    }

    // Finance: GET finance/cash-drawer/daily

    public function testCustomerCannotAccessFinance(): void
    {
        $token = $this->loginAs('customer1');
        $this->assertForbidden($this->request('GET', 'finance/cash-drawer/daily', ['store_id' => 1, 'date' => '2025-01-01'], $token));
    }

    public function testTechnicianCannotAccessFinance(): void
    {
        $token = $this->loginAs('tech1');
        $this->assertForbidden($this->request('GET', 'finance/cash-drawer/daily', ['store_id' => 1, 'date' => '2025-01-01'], $token));
    }

    public function testFinanceCanAccessFinance(): void
    {
        $token = $this->loginAs('finance1');
        $response = $this->request('GET', 'finance/cash-drawer/daily', ['store_id' => 1, 'date' => '2025-01-01'], $token);
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success'] ?? false);
    }

    // Dashboard: GET dashboards/operations

    public function testCustomerCannotAccessDashboard(): void
    {
        $token = $this->loginAs('customer1');
        $this->assertForbidden($this->request('GET', 'dashboards/operations', ['from' => '01/01/2025', 'to' => '01/31/2025'], $token));
    }

    public function testManagerCanAccessDashboard(): void
    {
        $token = $this->loginAs('manager1');
        $response = $this->request('GET', 'dashboards/operations', ['from' => '01/01/2025', 'to' => '01/31/2025'], $token);
        $this->assertAuthorized($response);
        $this->assertArrayHasKey('transaction_volume', $response['body']['data']);
        $this->assertArrayHasKey('cancellation_rate', $response['body']['data']);
    }

    // Experiments: GET experiments (admin only)

    public function testNonAdminCannotManageExperiments(): void
    {
        $token = $this->loginAs('manager1');
        $this->assertForbidden($this->request('GET', 'experiments', [], $token));
    }

    public function testAdminCanManageExperiments(): void
    {
        $token = $this->loginAs('admin');
        $response = $this->request('GET', 'experiments', [], $token);
        $this->assertAuthorized($response);
    }

    // Cleansing approve: POST cleansing/batches/:id/approve (admin only)

    public function testManagerCannotApproveBatch(): void
    {
        $token = $this->loginAs('manager1');
        $this->assertForbidden($this->request('POST', 'cleansing/batches/1/approve', [], $token));
    }

    // Reconciliation reopen: POST finance/cash-drawer/:id/reopen (admin only)

    public function testFinanceCannotReopenDrawer(): void
    {
        $token = $this->loginAs('finance1');
        $this->assertForbidden($this->request('POST', 'finance/cash-drawer/1/reopen', ['reason' => 'test'], $token));
    }

    // Security events: GET security/events (admin only)

    public function testNonAdminCannotViewSecurityEvents(): void
    {
        $token = $this->loginAs('manager1');
        $this->assertForbidden($this->request('GET', 'security/events', [], $token));
    }

    public function testAdminCanViewSecurityEvents(): void
    {
        $token = $this->loginAs('admin');
        $response = $this->request('GET', 'security/events', [], $token);
        $this->assertAuthorized($response);
    }

    // Audit logs: GET audit/logs (store_manager + admin)

    public function testManagerCanViewAuditLogs(): void
    {
        $token = $this->loginAs('manager1');
        $response = $this->request('GET', 'audit/logs', [], $token);
        $this->assertAuthorized($response);
        $this->assertArrayHasKey('items', $response['body']['data']);
        $this->assertArrayHasKey('total', $response['body']['data']);
    }

    public function testFrontDeskCannotViewAuditLogs(): void
    {
        $token = $this->loginAs('frontdesk1');
        $this->assertForbidden($this->request('GET', 'audit/logs', [], $token));
    }

    // Environmental: GET environment/aligned-buckets (store_manager + admin)

    public function testCustomerCannotAccessEnvironmental(): void
    {
        $token = $this->loginAs('customer1');
        $this->assertForbidden($this->request('GET', 'environment/aligned-buckets', [], $token));
    }

    public function testAdminCanAccessEnvironmental(): void
    {
        $token = $this->loginAs('admin');
        $response = $this->request('GET', 'environment/aligned-buckets', [], $token);
        $this->assertAuthorized($response);
    }

    // Orders: POST orders (customer, front_desk, admin only - NOT technician)

    public function testTechnicianCannotCreateOrders(): void
    {
        $token = $this->loginAs('tech1');
        $response = $this->request('POST', 'orders', [
            'customer_name' => 'Test',
            'items' => [['service_code' => 'X', 'service_name' => 'X', 'qty' => 1, 'unit_price' => 10]],
        ], $token);
        $this->assertForbidden($response);
    }
}
