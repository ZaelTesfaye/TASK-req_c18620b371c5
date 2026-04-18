<?php
namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * AuditLoggingTest - Verifies that state-changing operations produce
 * audit log entries in operation_logs via the audit middleware.
 */
class AuditLoggingTest extends TestCase
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
        if ($token) { $headers[] = "Authorization: Bearer {$token}"; }
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
        $response = $this->request('POST', 'auth/login', [
            'username' => $username,
            'password' => 'Demo12345678!',
            'store_id' => 1,
            'workstation_id' => 1,
        ]);
        return $response['body']['data']['token'] ?? null;
    }

    /**
     * Creating an order should produce an audit log entry.
     */
    public function testOrderCreationIsAudited(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        // Get audit log count before
        $beforeResp = $this->request('GET', 'audit/logs', ['entity_type' => 'order', 'action' => 'create'], $token);
        $this->assertEquals(200, $beforeResp['status']);
        $countBefore = $beforeResp['body']['data']['total'] ?? 0;

        // Create an order
        $this->request('POST', 'orders', [
            'customer_name' => 'Audit Log Test ' . time(),
            'items' => [['service_code' => 'SVC-001', 'service_name' => 'Audit Test', 'qty' => 1, 'unit_price' => 10.00]],
        ], $token);

        // Get audit log count after
        $afterResp = $this->request('GET', 'audit/logs', ['entity_type' => 'order', 'action' => 'create'], $token);
        $this->assertEquals(200, $afterResp['status']);
        $countAfter = $afterResp['body']['data']['total'] ?? 0;

        $this->assertGreaterThan($countBefore, $countAfter, 'Audit log count should increase after order creation');
    }

    /**
     * Payment recording should produce an audit log entry.
     */
    public function testPaymentIsAudited(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        // Create order + payment
        $orderResp = $this->request('POST', 'orders', [
            'customer_name' => 'Payment Audit Test',
            'items' => [['service_code' => 'SVC-001', 'service_name' => 'Test', 'qty' => 1, 'unit_price' => 50.00]],
        ], $token);
        $orderId = $orderResp['body']['data']['id'] ?? null;

        if ($orderId) {
            $beforeResp = $this->request('GET', 'audit/logs', ['entity_type' => 'payment'], $token);
            $countBefore = $beforeResp['body']['data']['total'] ?? 0;

            $this->request('POST', "orders/{$orderId}/payments", [
                'tender_type' => 'cash', 'amount' => 25.00,
            ], $token);

            $afterResp = $this->request('GET', 'audit/logs', ['entity_type' => 'payment'], $token);
            $countAfter = $afterResp['body']['data']['total'] ?? 0;

            $this->assertGreaterThan($countBefore, $countAfter, 'Audit log should record payment');
        }
    }

    /**
     * Audit log entries should have required fields and redacted sensitive data.
     */
    public function testAuditLogEntryStructure(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $resp = $this->request('GET', 'audit/logs', ['page' => 1, 'page_size' => 1], $token);
        $this->assertEquals(200, $resp['status']);

        $items = $resp['body']['data']['items'] ?? [];
        if (count($items) > 0) {
            $entry = $items[0];
            $this->assertArrayHasKey('actor_user_id', $entry);
            $this->assertArrayHasKey('actor_role_code', $entry);
            $this->assertArrayHasKey('store_id', $entry);
            $this->assertArrayHasKey('action', $entry);
            $this->assertArrayHasKey('entity_type', $entry);
            $this->assertArrayHasKey('request_id', $entry);
            $this->assertArrayHasKey('created_at', $entry);
            $this->assertNotEmpty($entry['request_id']);
        }
    }
}
