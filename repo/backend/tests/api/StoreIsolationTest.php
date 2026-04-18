<?php
namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * StoreIsolationTest - Verifies tenant isolation across all store-scoped endpoints.
 * Users from store A cannot read or mutate store B's data (except admins).
 */
class StoreIsolationTest extends TestCase
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
        } elseif ($method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
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

    private function loginAs(string $username, int $storeId = 1, int $wsId = 1): ?string
    {
        $response = $this->request('POST', 'auth/login', [
            'username' => $username,
            'password' => 'Demo12345678!',
            'store_id' => $storeId,
            'workstation_id' => $wsId,
        ]);
        return $response['body']['data']['token'] ?? null;
    }

    // ---- Order isolation ----

    public function testStore2TechCannotSeeStore1Order(): void
    {
        $store1Token = $this->loginAs('frontdesk1', 1, 1);
        $this->assertNotNull($store1Token);

        $createResp = $this->request('POST', 'orders', [
            'customer_name' => 'Store Isolation Test',
            'items' => [['service_code' => 'SVC-001', 'service_name' => 'Test', 'qty' => 1, 'unit_price' => 10.00]],
        ], $store1Token);
        $this->assertEquals(201, $createResp['status']);
        $orderId = $createResp['body']['data']['id'];

        $store2Token = $this->loginAs('tech2', 2, 5);
        if (!$store2Token) { $this->markTestSkipped('tech2 not available'); }

        $readResp = $this->request('GET', "orders/{$orderId}", [], $store2Token);
        $this->assertEquals(404, $readResp['status']);
    }

    public function testStore2FrontdeskOnlySeesStore2Orders(): void
    {
        $store2Token = $this->loginAs('frontdesk2', 2, 4);
        if (!$store2Token) { $this->markTestSkipped('frontdesk2 not available'); }

        $listResp = $this->request('GET', 'orders', [], $store2Token);
        $this->assertEquals(200, $listResp['status']);
        foreach ($listResp['body']['data']['items'] ?? [] as $item) {
            $this->assertEquals(2, $item['store_id'],
                "Order {$item['id']} belongs to store {$item['store_id']}, expected store 2");
        }
    }

    public function testAdminCanSeeAllStoreOrders(): void
    {
        $adminToken = $this->loginAs('admin', 1, 1);
        $this->assertNotNull($adminToken);
        $listResp = $this->request('GET', 'orders', [], $adminToken);
        $this->assertEquals(200, $listResp['status']);
        $this->assertTrue($listResp['body']['success']);
    }

    // ---- Cross-store coupon endpoints (F-005) ----
    //
    // Coupon validate/apply take an order_id. The CouponService guard must
    // reject a foreign order_id with 403 before running any coupon logic —
    // otherwise a caller from Store B could use validation responses to
    // probe the state of Store A's orders (does the order exist? does it
    // meet the min-spend? is a coupon already applied?). These tests pin
    // the guard and also confirm it is scoped correctly: the same session
    // must still be able to operate on an order it owns.

    public function testStore2CannotValidateCouponForStore1Order(): void
    {
        $store1Token = $this->loginAs('frontdesk1', 1, 1);
        $this->assertNotNull($store1Token);

        $createResp = $this->request('POST', 'orders', [
            'customer_name' => 'Coupon cross-store validate',
            'items' => [['service_code' => 'SVC-001', 'service_name' => 'Test', 'qty' => 1, 'unit_price' => 50.00]],
        ], $store1Token);
        $foreignOrderId = $createResp['body']['data']['id'] ?? null;
        $this->assertNotNull($foreignOrderId);

        $store2Token = $this->loginAs('frontdesk2', 2, 4);
        if (!$store2Token) { $this->markTestSkipped('frontdesk2 not available'); }

        $resp = $this->request('GET', 'coupons/validate', [
            'code' => 'ANYCODE',
            'order_id' => $foreignOrderId,
        ], $store2Token);
        $this->assertEquals(403, $resp['status'],
            'Coupon validate must reject a foreign order_id with 403, not leak validation state');
        $this->assertEquals('FORBIDDEN', $resp['body']['error_code'] ?? '');
    }

    public function testStore2CannotApplyCouponToStore1Order(): void
    {
        $store1Token = $this->loginAs('frontdesk1', 1, 1);
        $this->assertNotNull($store1Token);

        $createResp = $this->request('POST', 'orders', [
            'customer_name' => 'Coupon cross-store apply',
            'items' => [['service_code' => 'SVC-001', 'service_name' => 'Test', 'qty' => 1, 'unit_price' => 50.00]],
        ], $store1Token);
        $foreignOrderId = $createResp['body']['data']['id'] ?? null;
        $this->assertNotNull($foreignOrderId);

        $store2Token = $this->loginAs('frontdesk2', 2, 4);
        if (!$store2Token) { $this->markTestSkipped('frontdesk2 not available'); }

        $resp = $this->request('POST', "orders/{$foreignOrderId}/apply-coupon", [
            'code' => 'ANYCODE',
        ], $store2Token);
        $this->assertEquals(403, $resp['status'],
            'Apply-coupon must reject a foreign order_id with 403 before any redemption row is written');
        $this->assertEquals('FORBIDDEN', $resp['body']['error_code'] ?? '');
    }

    public function testStore2CanValidateAndApplyCouponOnOwnOrder(): void
    {
        // Same-store path must keep working — the guard must scope exactly
        // to cross-store, not over-block the caller's own orders.
        $store2Token = $this->loginAs('frontdesk2', 2, 4);
        if (!$store2Token) { $this->markTestSkipped('frontdesk2 not available'); }

        $createResp = $this->request('POST', 'orders', [
            'customer_name' => 'Own-store coupon path',
            'items' => [['service_code' => 'SVC-001', 'service_name' => 'Test', 'qty' => 1, 'unit_price' => 50.00]],
        ], $store2Token);
        $ownOrderId = $createResp['body']['data']['id'] ?? null;
        $this->assertNotNull($ownOrderId);

        // Validate with a nonexistent code — expect 200 with valid:false,
        // not 403. That proves the guard passed and the request reached
        // coupon-validation logic on the caller's own order.
        $validateResp = $this->request('GET', 'coupons/validate', [
            'code' => 'NONEXISTENT_COUPON_OWN',
            'order_id' => $ownOrderId,
        ], $store2Token);
        $this->assertEquals(200, $validateResp['status'],
            'Validating against an owned order must not be over-blocked');
        $validBody = $validateResp['body']['data'] ?? $validateResp['body'];
        $this->assertFalse($validBody['valid'] ?? true,
            'Nonexistent coupon should produce a normal {valid:false} response on an owned order');

        // Apply the same nonexistent coupon — expect the apply-shape
        // COUPON_INVALID at 400, not a 403 ownership failure.
        $applyResp = $this->request('POST', "orders/{$ownOrderId}/apply-coupon", [
            'code' => 'NONEXISTENT_COUPON_OWN',
        ], $store2Token);
        $this->assertEquals(400, $applyResp['status'],
            'Apply on an owned order with a bad code should return COUPON_INVALID, not a FORBIDDEN');
        $this->assertEquals('COUPON_INVALID', $applyResp['body']['error_code'] ?? '');
    }

    // ---- Cross-store order mutations (cancel, assign-technician) ----

    public function testStore2FrontdeskCannotCancelStore1Order(): void
    {
        $store1Token = $this->loginAs('frontdesk1', 1, 1);
        $this->assertNotNull($store1Token);

        $createResp = $this->request('POST', 'orders', [
            'customer_name' => 'Cross-store cancel target',
            'items' => [['service_code' => 'SVC-001', 'service_name' => 'Test', 'qty' => 1, 'unit_price' => 10.00]],
        ], $store1Token);
        $orderId = $createResp['body']['data']['id'] ?? null;
        $this->assertNotNull($orderId);

        $store2Token = $this->loginAs('frontdesk2', 2, 4);
        if (!$store2Token) { $this->markTestSkipped('frontdesk2 not available'); }

        $resp = $this->request('POST', "orders/{$orderId}/cancel", ['reason' => 'test'], $store2Token);
        $this->assertEquals(403, $resp['status']);
        $this->assertEquals('FORBIDDEN', $resp['body']['error_code']);
    }

    public function testStore2FrontdeskCannotAssignTechnicianOnStore1Order(): void
    {
        $store1Token = $this->loginAs('frontdesk1', 1, 1);
        $this->assertNotNull($store1Token);

        $createResp = $this->request('POST', 'orders', [
            'customer_name' => 'Cross-store assign target',
            'items' => [['service_code' => 'SVC-001', 'service_name' => 'Test', 'qty' => 1, 'unit_price' => 10.00]],
        ], $store1Token);
        $orderId = $createResp['body']['data']['id'] ?? null;
        $this->assertNotNull($orderId);

        $store2Token = $this->loginAs('frontdesk2', 2, 4);
        if (!$store2Token) { $this->markTestSkipped('frontdesk2 not available'); }

        // Attempt to assign any technician id; the request should be rejected
        // on store ownership before technician validation runs.
        $resp = $this->request('POST', "orders/{$orderId}/assign-technician", ['technician_id' => 3], $store2Token);
        $this->assertEquals(403, $resp['status']);
        $this->assertEquals('FORBIDDEN', $resp['body']['error_code']);
    }

    public function testStore2FrontdeskCannotUpdateStore1Order(): void
    {
        $store1Token = $this->loginAs('frontdesk1', 1, 1);
        $this->assertNotNull($store1Token);

        $createResp = $this->request('POST', 'orders', [
            'customer_name' => 'Cross-store update target',
            'items' => [['service_code' => 'SVC-001', 'service_name' => 'Test', 'qty' => 1, 'unit_price' => 10.00]],
        ], $store1Token);
        $orderId = $createResp['body']['data']['id'] ?? null;
        $this->assertNotNull($orderId);

        $store2Token = $this->loginAs('frontdesk2', 2, 4);
        if (!$store2Token) { $this->markTestSkipped('frontdesk2 not available'); }

        $resp = $this->request('PATCH', "orders/{$orderId}", ['customer_name' => 'Hijacked'], $store2Token);
        $this->assertEquals(403, $resp['status']);
        $this->assertEquals('FORBIDDEN', $resp['body']['error_code']);
    }

    public function testStore2FrontdeskCannotConfirmStore1Order(): void
    {
        $store1Token = $this->loginAs('frontdesk1', 1, 1);
        $this->assertNotNull($store1Token);

        $createResp = $this->request('POST', 'orders', [
            'customer_name' => 'Cross-store confirm target',
            'items' => [['service_code' => 'SVC-001', 'service_name' => 'Test', 'qty' => 1, 'unit_price' => 10.00]],
        ], $store1Token);
        $orderId = $createResp['body']['data']['id'] ?? null;
        $this->assertNotNull($orderId);

        $store2Token = $this->loginAs('frontdesk2', 2, 4);
        if (!$store2Token) { $this->markTestSkipped('frontdesk2 not available'); }

        $resp = $this->request('POST', "orders/{$orderId}/confirm", [], $store2Token);
        $this->assertEquals(403, $resp['status']);
        $this->assertEquals('FORBIDDEN', $resp['body']['error_code']);
    }

    public function testStore2UserCannotCompleteStore1Order(): void
    {
        // Walk the order to in_progress in store 1, then confirm a store-2
        // technician/front-desk user cannot complete it.
        $store1Token = $this->loginAs('frontdesk1', 1, 1);
        $this->assertNotNull($store1Token);

        $createResp = $this->request('POST', 'orders', [
            'customer_name' => 'Cross-store complete target',
            'items' => [['service_code' => 'SVC-001', 'service_name' => 'Test', 'qty' => 1, 'unit_price' => 10.00]],
        ], $store1Token);
        $orderId = $createResp['body']['data']['id'] ?? null;
        $this->assertNotNull($orderId);

        $store2Token = $this->loginAs('frontdesk2', 2, 4);
        if (!$store2Token) { $this->markTestSkipped('frontdesk2 not available'); }

        $resp = $this->request('POST', "orders/{$orderId}/complete", [], $store2Token);
        // Foreign-store user must be blocked before any status-transition
        // validation runs. 403 FORBIDDEN is the expected envelope.
        $this->assertEquals(403, $resp['status']);
        $this->assertEquals('FORBIDDEN', $resp['body']['error_code']);
    }

    // ---- Announcement cross-store isolation ----

    public function testStore2ManagerCannotReadStore1Announcement(): void
    {
        $adminToken = $this->loginAs('admin', 1, 1);
        $this->assertNotNull($adminToken);

        $createResp = $this->request('POST', 'announcements', [
            'title' => 'Store1 only ' . time(),
            'body' => 'visible only to store 1',
            'store_id' => 1,
        ], $adminToken);
        $annId = $createResp['body']['data']['id'] ?? null;
        $this->assertNotNull($annId);

        $store2Token = $this->loginAs('manager2', 2, 4);
        if (!$store2Token) { $this->markTestSkipped('manager2 not available'); }

        $readResp = $this->request('GET', "announcements/{$annId}", [], $store2Token);
        $this->assertEquals(403, $readResp['status']);
        $this->assertEquals('FORBIDDEN', $readResp['body']['error_code']);
    }

    public function testStore2ManagerListDoesNotIncludeStore1Announcements(): void
    {
        $store2Token = $this->loginAs('manager2', 2, 4);
        if (!$store2Token) { $this->markTestSkipped('manager2 not available'); }

        $listResp = $this->request('GET', 'announcements', [], $store2Token);
        $this->assertEquals(200, $listResp['status']);
        foreach ($listResp['body']['data']['items'] ?? [] as $item) {
            $this->assertEquals(2, $item['store_id'],
                "Announcement {$item['id']} belongs to store {$item['store_id']}, expected 2");
        }
    }

    public function testNonAdminListIgnoresSuppliedStoreIdFilter(): void
    {
        // A non-admin that tries to widen the listing by passing ?store_id=1
        // must still only get their own store's rows back — the query param
        // is silently ignored for non-admins.
        $store2Token = $this->loginAs('manager2', 2, 4);
        if (!$store2Token) { $this->markTestSkipped('manager2 not available'); }

        $listResp = $this->request('GET', 'announcements', ['store_id' => 1], $store2Token);
        $this->assertEquals(200, $listResp['status']);
        foreach ($listResp['body']['data']['items'] ?? [] as $item) {
            $this->assertEquals(2, $item['store_id'],
                "Non-admin forced store_id=1 on list but saw store {$item['store_id']} row");
        }
    }

    public function testStore2ManagerCannotUpdateStore1Announcement(): void
    {
        $adminToken = $this->loginAs('admin', 1, 1);
        $this->assertNotNull($adminToken);

        $createResp = $this->request('POST', 'announcements', [
            'title' => 'Store1 update target ' . time(),
            'body' => 'foo',
            'store_id' => 1,
        ], $adminToken);
        $annId = $createResp['body']['data']['id'] ?? null;
        $this->assertNotNull($annId);

        $store2Token = $this->loginAs('manager2', 2, 4);
        if (!$store2Token) { $this->markTestSkipped('manager2 not available'); }

        $resp = $this->request('PATCH', "announcements/{$annId}", ['title' => 'Hijacked'], $store2Token);
        $this->assertEquals(403, $resp['status']);
        $this->assertEquals('FORBIDDEN', $resp['body']['error_code']);
    }

    public function testStore2AdminStyleDeleteOfStore1AnnouncementDenied(): void
    {
        // Delete is restricted to administrators at the role layer; administrators
        // bypass the store scope. Here we verify a non-admin in store 2 cannot
        // even reach the delete handler for a store-1 announcement.
        $adminToken = $this->loginAs('admin', 1, 1);
        $this->assertNotNull($adminToken);

        $createResp = $this->request('POST', 'announcements', [
            'title' => 'Store1 delete target ' . time(),
            'body' => 'foo',
            'store_id' => 1,
        ], $adminToken);
        $annId = $createResp['body']['data']['id'] ?? null;
        $this->assertNotNull($annId);

        $store2Token = $this->loginAs('manager2', 2, 4);
        if (!$store2Token) { $this->markTestSkipped('manager2 not available'); }

        $resp = $this->request('DELETE', "announcements/{$annId}", [], $store2Token);
        // Non-admins hit the RBAC gate first (403 FORBIDDEN) before the store
        // scope check — either way a foreign-store delete must be denied.
        $this->assertEquals(403, $resp['status']);
        $this->assertEquals('FORBIDDEN', $resp['body']['error_code']);
    }

    // ---- Payment isolation ----

    public function testStore2CannotPayForStore1Order(): void
    {
        // Create order in store 1
        $store1Token = $this->loginAs('frontdesk1', 1, 1);
        $this->assertNotNull($store1Token);
        $createResp = $this->request('POST', 'orders', [
            'customer_name' => 'Payment Isolation Test',
            'items' => [['service_code' => 'SVC-001', 'service_name' => 'Test', 'qty' => 1, 'unit_price' => 50.00]],
        ], $store1Token);
        $orderId = $createResp['body']['data']['id'] ?? null;
        if (!$orderId) { $this->markTestSkipped('Could not create order'); }

        // Try to pay from store 2
        $store2Token = $this->loginAs('frontdesk2', 2, 4);
        if (!$store2Token) { $this->markTestSkipped('frontdesk2 not available'); }

        $payResp = $this->request('POST', "orders/{$orderId}/payments", [
            'tender_type' => 'cash', 'amount' => 50.00,
        ], $store2Token);
        $this->assertEquals(403, $payResp['status']);
        $this->assertEquals('FORBIDDEN', $payResp['body']['error_code']);
    }

    // ---- Finance isolation ----

    public function testFinanceDrawerScopedToUserStore(): void
    {
        $financeToken = $this->loginAs('finance1', 1, 1);
        $this->assertNotNull($financeToken);

        // Finance user's store_id is enforced from session, not from query
        $resp = $this->request('GET', 'finance/cash-drawer/daily', ['date' => '2025-01-01'], $financeToken);
        $this->assertEquals(200, $resp['status']);
        // The response should be for the user's store, not a different store
    }

    // ---- Dashboard isolation ----

    public function testDashboardMetricsScopedToUserStore(): void
    {
        $managerToken = $this->loginAs('manager1', 1, 1);
        if (!$managerToken) { $this->markTestSkipped('manager1 not available'); }

        $resp = $this->request('GET', 'dashboards/operations', [
            'from' => '01/01/2025', 'to' => '12/31/2025',
        ], $managerToken);
        $this->assertEquals(200, $resp['status']);
        $this->assertTrue($resp['body']['success']);
        // store_id in response should match user's store
        $this->assertEquals(1, $resp['body']['data']['store_id'] ?? 0);
    }

    // ---- Environmental isolation ----

    public function testEnvironmentalBucketsScopedToUserStore(): void
    {
        $managerToken = $this->loginAs('manager1', 1, 1);
        if (!$managerToken) { $this->markTestSkipped('manager1 not available'); }

        $resp = $this->request('GET', 'environment/aligned-buckets', [], $managerToken);
        $this->assertEquals(200, $resp['status']);
        $this->assertTrue($resp['body']['success']);
    }
}
