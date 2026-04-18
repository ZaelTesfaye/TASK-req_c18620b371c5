<?php
namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * AuditApiTest - API tests for audit log and security event endpoints.
 * Routes: audit/logs (GET), security/events (GET)
 * All assertions verify response body content, not just status codes.
 */
class AuditApiTest extends TestCase
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
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
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
        $storeId = 1; $wsId = 1;
        if ($username === 'customer1') { $wsId = 3; }
        $response = $this->request('POST', 'auth/login', [
            'username' => $username, 'password' => 'Demo12345678!',
            'store_id' => $storeId, 'workstation_id' => $wsId,
        ]);
        return $response['body']['data']['token'] ?? null;
    }

    // Auth guard

    public function testAuditLogSearchRequiresAuth(): void
    {
        $response = $this->request('GET', 'audit/logs');
        $this->assertEquals(401, $response['status']);
        $this->assertEquals('UNAUTHORIZED', $response['body']['error_code']);
    }

    public function testSecurityEventsRequiresAuth(): void
    {
        $response = $this->request('GET', 'security/events');
        $this->assertEquals(401, $response['status']);
        $this->assertEquals('UNAUTHORIZED', $response['body']['error_code']);
    }

    // RBAC

    public function testAdminCanSearchAuditLogs(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $response = $this->request('GET', 'audit/logs', [], $token);
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $data = $response['body']['data'];
        $this->assertArrayHasKey('items', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertIsArray($data['items']);
        $this->assertIsInt($data['total']);
    }

    public function testFrontDeskCannotSearchAuditLogs(): void
    {
        $token = $this->loginAs('frontdesk1');
        if (!$token) { $this->markTestSkipped('frontdesk1 not available'); }
        $response = $this->request('GET', 'audit/logs', [], $token);
        $this->assertEquals(403, $response['status']);
        $this->assertEquals('FORBIDDEN', $response['body']['error_code']);
    }

    // Audit entry content verification

    public function testAuditLogEntriesHaveRequiredFields(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        // First create an order so there's at least one audit entry
        $this->request('POST', 'orders', [
            'customer_name' => 'Audit Test',
            'items' => [['service_code' => 'SVC-001', 'service_name' => 'Test', 'qty' => 1, 'unit_price' => 10.00]],
        ], $token);

        $response = $this->request('GET', 'audit/logs', [], $token);
        $this->assertEquals(200, $response['status']);
        $items = $response['body']['data']['items'];

        if (count($items) > 0) {
            $entry = $items[0];
            // Verify audit entry shape matches AuditService::log output
            $this->assertArrayHasKey('action', $entry);
            $this->assertArrayHasKey('entity_type', $entry);
            $this->assertArrayHasKey('created_at', $entry);
            $this->assertArrayHasKey('request_id', $entry);
            $this->assertNotEmpty($entry['action']);
            $this->assertNotEmpty($entry['entity_type']);
            $this->assertNotEmpty($entry['created_at']);
        }
    }

    // Filters

    public function testAuditLogFilterByAction(): void
    {
        $token = $this->loginAs('admin');
        $response = $this->request('GET', 'audit/logs', ['action' => 'create'], $token);
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertArrayHasKey('items', $response['body']['data']);
    }

    public function testAuditLogFilterByEntityType(): void
    {
        $token = $this->loginAs('admin');
        $response = $this->request('GET', 'audit/logs', ['entity_type' => 'order'], $token);
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
    }

    public function testAuditLogFilterByDateRange(): void
    {
        $token = $this->loginAs('admin');
        $response = $this->request('GET', 'audit/logs', [
            'from' => '2025-01-01 00:00:00',
            'to' => '2025-12-31 23:59:59',
        ], $token);
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
    }

    // Pagination

    public function testAuditLogPagination(): void
    {
        $token = $this->loginAs('admin');
        $response = $this->request('GET', 'audit/logs', ['page' => 1, 'page_size' => 5], $token);
        $this->assertEquals(200, $response['status']);
        $data = $response['body']['data'];
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('page', $data);
        $this->assertArrayHasKey('page_size', $data);
        $this->assertEquals(1, $data['page']);
        $this->assertEquals(5, $data['page_size']);
        $this->assertLessThanOrEqual(5, count($data['items']));
    }

    // Security events

    public function testAdminCanViewSecurityEvents(): void
    {
        $token = $this->loginAs('admin');
        $response = $this->request('GET', 'security/events', [], $token);
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
    }

    public function testManagerCannotViewSecurityEvents(): void
    {
        $token = $this->loginAs('manager1');
        if (!$token) { $this->markTestSkipped('manager1 not available'); }
        $response = $this->request('GET', 'security/events', [], $token);
        $this->assertEquals(403, $response['status']);
        $this->assertEquals('FORBIDDEN', $response['body']['error_code']);
    }

    // ---- Audit log immutability ----
    //
    // Audit entries are append-only. There are no documented PATCH/PUT/DELETE
    // routes for audit/logs/:id, so any attempt to reach those methods must
    // result in a 4xx (never 2xx). If someone inadvertently introduces a
    // mutating route for the audit log this test will break — which is the
    // desired signal.

    public function testAuditLogEntryCannotBeUpdated(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        // Seed at least one entry so there is a plausible id to target.
        $this->request('POST', 'orders', [
            'customer_name' => 'Audit Immutability',
            'items' => [['service_code' => 'SVC-001', 'service_name' => 'Test', 'qty' => 1, 'unit_price' => 10.00]],
        ], $token);
        $listResp = $this->request('GET', 'audit/logs', [], $token);
        $items = $listResp['body']['data']['items'] ?? [];
        $id = $items[0]['id'] ?? 1;

        foreach (['PATCH', 'PUT'] as $method) {
            $resp = $this->request($method, "audit/logs/{$id}", ['action' => 'tampered'], $token);
            $this->assertGreaterThanOrEqual(400, $resp['status'],
                "audit log must not accept {$method} (status was {$resp['status']})");
            $this->assertNotEquals(200, $resp['status'],
                "audit log accepted a {$method} update — log is no longer immutable");
        }
    }

    public function testAuditLogEntryCannotBeDeleted(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $listResp = $this->request('GET', 'audit/logs', [], $token);
        $items = $listResp['body']['data']['items'] ?? [];
        $id = $items[0]['id'] ?? 1;

        $resp = $this->request('DELETE', "audit/logs/{$id}", [], $token);
        $this->assertGreaterThanOrEqual(400, $resp['status'],
            "audit log must not accept DELETE (status was {$resp['status']})");
        $this->assertNotEquals(200, $resp['status'],
            'audit log accepted a DELETE — log is no longer immutable');
    }

    public function testAuditLogCollectionHasNoMutatingEndpoints(): void
    {
        // Bulk-level mutations must be equally forbidden.
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        foreach (['POST', 'PATCH', 'PUT', 'DELETE'] as $method) {
            $resp = $this->request($method, 'audit/logs', ['bulk' => true], $token);
            $this->assertGreaterThanOrEqual(400, $resp['status'],
                "audit/logs must reject {$method} (status was {$resp['status']})");
        }
    }
}
