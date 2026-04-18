<?php
namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * RbacWriteTest - Full RBAC matrix for state-changing (POST/PATCH/DELETE) endpoints.
 * Verifies unauthorized roles get 403 on all write paths, and that the 403
 * response body carries the expected error envelope (error_code, success=false,
 * non-empty message, request_id). Matching positive tests confirm authorized
 * roles succeed and receive the documented resource fields.
 */
class RbacWriteTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('APP_URL') ?: 'http://localhost:8000';
    }

    private function req(string $method, string $path, array $data = [], ?string $token = null): array
    {
        $url = $this->baseUrl . '/api/v1/' . ltrim($path, '/');
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($token) { $headers[] = "Authorization: Bearer {$token}"; }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data)); }
        elseif ($method === 'PATCH') { curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH'); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data)); }
        elseif ($method === 'DELETE') { curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE'); }
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $httpCode, 'body' => json_decode($response, true) ?? []];
    }

    private function loginAs(string $u, int $s = 1, int $w = 1): ?string
    {
        if ($u === 'customer1') { $w = 3; }
        if ($u === 'tech1') { $w = 2; }
        $r = $this->req('POST', 'auth/login', ['username' => $u, 'password' => 'Demo12345678!', 'store_id' => $s, 'workstation_id' => $w]);
        return $r['body']['data']['token'] ?? null;
    }

    /**
     * Shared assertion: a denied write returns 403 with the canonical error
     * envelope. Tests call this so that every RBAC rejection covers both the
     * status line and the response body.
     */
    private function assertForbiddenResponse(array $r, string $context = ''): void
    {
        $msg = $context !== '' ? " ({$context})" : '';
        $this->assertEquals(403, $r['status'], "Expected 403 status{$msg}");
        $this->assertFalse($r['body']['success'] ?? true, "Expected success=false{$msg}");
        $this->assertEquals('FORBIDDEN', $r['body']['error_code'] ?? null, "Expected FORBIDDEN error_code{$msg}");
        $this->assertNotEmpty($r['body']['message'] ?? '', "Expected non-empty message on 403{$msg}");
        $this->assertArrayHasKey('request_id', $r['body'], "Expected request_id on error envelope{$msg}");
        $this->assertNotEmpty($r['body']['request_id'], "Expected request_id to be non-empty{$msg}");
    }

    // -- Admin write endpoints: only administrator --

    public function testCustomerCannotCreateUser(): void
    {
        $t = $this->loginAs('customer1');
        $r = $this->req('POST', 'admin/users', ['username' => 'x', 'password' => 'StrongPass123!'], $t);
        $this->assertForbiddenResponse($r, 'customer POST admin/users');
    }

    public function testTechCannotRotateKey(): void
    {
        $t = $this->loginAs('tech1');
        $r = $this->req('POST', 'admin/encryption/keys/rotate', ['new_version' => 99], $t);
        $this->assertForbiddenResponse($r, 'tech POST admin/encryption/keys/rotate');
    }

    // -- Experiment write: only administrator --

    public function testManagerCannotCreateExperiment(): void
    {
        $t = $this->loginAs('manager1');
        $r = $this->req('POST', 'experiments', ['key' => 'x', 'name' => 'x'], $t);
        $this->assertForbiddenResponse($r, 'manager POST experiments');
    }

    public function testManagerCannotStartExperiment(): void
    {
        $t = $this->loginAs('manager1');
        $r = $this->req('POST', 'experiments/1/start', [], $t);
        $this->assertForbiddenResponse($r, 'manager POST experiments/1/start');
    }

    // -- Event write: only administrator --

    public function testFinanceCannotCreateEvent(): void
    {
        $t = $this->loginAs('finance1');
        $r = $this->req('POST', 'events', ['event_key' => 'x', 'name' => 'x'], $t);
        $this->assertForbiddenResponse($r, 'finance POST events');
    }

    // -- Announcement write: store_manager + admin --

    public function testFrontDeskCannotCreateAnnouncement(): void
    {
        $t = $this->loginAs('frontdesk1');
        $r = $this->req('POST', 'announcements', ['title' => 'x', 'body' => 'x'], $t);
        $this->assertForbiddenResponse($r, 'frontdesk POST announcements');
    }

    public function testCustomerCannotDeleteAnnouncement(): void
    {
        $t = $this->loginAs('customer1');
        $r = $this->req('DELETE', 'announcements/1', [], $t);
        $this->assertForbiddenResponse($r, 'customer DELETE announcements/1');
    }

    // -- Cleansing approve/rollback: only administrator --

    public function testManagerCannotRollbackBatch(): void
    {
        $t = $this->loginAs('manager1');
        $r = $this->req('POST', 'cleansing/batches/1/rollback', [], $t);
        $this->assertForbiddenResponse($r, 'manager POST cleansing/batches/1/rollback');
    }

    // -- Environmental writes: store_manager + admin --

    public function testCustomerCannotAlignBuckets(): void
    {
        $t = $this->loginAs('customer1');
        $r = $this->req('POST', 'environment/align-buckets', ['store_id' => 1], $t);
        $this->assertForbiddenResponse($r, 'customer POST environment/align-buckets');
    }

    public function testCustomerCannotComputeMetrics(): void
    {
        $t = $this->loginAs('customer1');
        $r = $this->req('POST', 'environment/compute-derived-metrics', ['store_id' => 1], $t);
        $this->assertForbiddenResponse($r, 'customer POST environment/compute-derived-metrics');
    }

    // -- Environmental write tenant isolation --

    public function testManagerCannotAlignForeignStore(): void
    {
        $t = $this->loginAs('manager1');
        if (!$t) { $this->markTestSkipped('manager1 not available'); }
        $r = $this->req('POST', 'environment/align-buckets', ['store_id' => 999], $t);
        $this->assertForbiddenResponse($r, 'manager aligning foreign store 999');
    }

    public function testManagerCannotComputeForeignStore(): void
    {
        $t = $this->loginAs('manager1');
        if (!$t) { $this->markTestSkipped('manager1 not available'); }
        $r = $this->req('POST', 'environment/compute-derived-metrics', ['store_id' => 999], $t);
        $this->assertForbiddenResponse($r, 'manager computing foreign store 999');
    }

    // -- Payment write: front_desk + finance + admin --

    public function testTechCannotRecordPayment(): void
    {
        $t = $this->loginAs('tech1');
        $r = $this->req('POST', 'orders/1/payments', ['tender_type' => 'cash', 'amount' => 10], $t);
        $this->assertForbiddenResponse($r, 'tech POST orders/1/payments');
    }

    // -- Finance reopen: only administrator --

    public function testFinanceCannotReopen(): void
    {
        $t = $this->loginAs('finance1');
        $r = $this->req('POST', 'finance/cash-drawer/1/reopen', ['reason' => 'test'], $t);
        $this->assertForbiddenResponse($r, 'finance POST finance/cash-drawer/1/reopen');
    }

    // ---- Positive counterpart tests: authorized roles succeed AND the
    // response body carries the documented resource fields. These balance the
    // 403 cases so we know we're not regressing authorized paths while
    // tightening unauthorized ones.

    public function testAdminCanCreateAnnouncementAndBodyHasResourceFields(): void
    {
        $t = $this->loginAs('admin');
        $this->assertNotNull($t);
        $r = $this->req('POST', 'announcements', [
            'title' => 'RBAC positive ' . time(),
            'body' => 'Created by admin for RBAC positive test',
        ], $t);
        $this->assertEquals(201, $r['status']);
        $this->assertTrue($r['body']['success'] ?? false);
        $data = $r['body']['data'] ?? [];
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('title', $data);
        $this->assertArrayHasKey('store_id', $data);
        $this->assertArrayHasKey('created_by', $data);
        $this->assertIsInt($data['id']);
        $this->assertNotEmpty($data['title']);
    }

    public function testFrontDeskCanCreateOrderAndBodyHasResourceFields(): void
    {
        $t = $this->loginAs('frontdesk1');
        $this->assertNotNull($t);
        $r = $this->req('POST', 'orders', [
            'customer_name' => 'RBAC Positive',
            'items' => [['service_code' => 'SVC-001', 'service_name' => 'Test', 'qty' => 1, 'unit_price' => 10.00]],
        ], $t);
        $this->assertEquals(201, $r['status']);
        $this->assertTrue($r['body']['success'] ?? false);
        $data = $r['body']['data'] ?? [];
        foreach (['id', 'order_no', 'status', 'subtotal_amount', 'total_amount'] as $field) {
            $this->assertArrayHasKey($field, $data, "Expected field {$field} in order create response");
        }
        $this->assertEquals('draft', $data['status']);
    }
}
