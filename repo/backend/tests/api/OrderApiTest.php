<?php
namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * OrderApiTest - API tests for order lifecycle endpoints.
 * Tests: CRUD, state transitions, role permissions, cancellation, assignment.
 */
class OrderApiTest extends TestCase
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

    private function loginAs(string $username): ?string
    {
        $storeId = 1;
        $wsId = 1;
        if ($username === 'tech1') { $wsId = 2; }
        if ($username === 'customer1') { $wsId = 3; }
        if ($username === 'tech2') { $storeId = 2; $wsId = 5; }
        if ($username === 'frontdesk2') { $storeId = 2; $wsId = 4; }

        $response = $this->request('POST', 'auth/login', [
            'username' => $username,
            'password' => 'Demo12345678!',
            'store_id' => $storeId,
            'workstation_id' => $wsId,
        ]);
        return $response['body']['data']['token'] ?? null;
    }

    public function testCreateOrderAsFrontDesk(): void
    {
        $token = $this->loginAs('frontdesk1');
        $this->assertNotNull($token);

        $response = $this->request('POST', 'orders', [
            'customer_name' => 'John Doe',
            'channel' => 'front_desk',
            'items' => [
                ['service_code' => 'SVC-001', 'service_name' => 'Oil Change', 'qty' => 1, 'unit_price' => 49.99],
            ],
        ], $token);

        $this->assertEquals(201, $response['status']);
        $this->assertTrue($response['body']['success']);
        // Verify order data in response
        $order = $response['body']['data'];
        $this->assertStringStartsWith('ORD-', $order['order_no']);
        $this->assertEquals('draft', $order['status']);
        $this->assertEquals('John Doe', $order['customer_name']);
        $this->assertEquals(49.99, $order['subtotal_amount']);
        // Tax = 49.99 * 0.08 = 4.00 (rounded)
        $this->assertEquals(4.00, $order['tax_amount']);
        $this->assertEquals(53.99, $order['total_amount']);
        $this->assertEquals(53.99, $order['amount_due']);
    }

    public function testCreateOrderAsCustomer(): void
    {
        $token = $this->loginAs('customer1');
        $this->assertNotNull($token);

        $response = $this->request('POST', 'orders', [
            'customer_name' => 'Jane Customer',
            'channel' => 'kiosk',
            'items' => [
                ['service_code' => 'SVC-002', 'service_name' => 'Tire Rotation', 'qty' => 1, 'unit_price' => 29.99],
            ],
        ], $token);

        $this->assertEquals(201, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertEquals('kiosk', $response['body']['data']['channel']);
        $this->assertEquals(29.99, $response['body']['data']['subtotal_amount']);
    }

    public function testTechnicianCannotCreateOrder(): void
    {
        $token = $this->loginAs('tech1');
        $this->assertNotNull($token);

        $response = $this->request('POST', 'orders', [
            'customer_name' => 'Test',
            'items' => [
                ['service_code' => 'SVC-001', 'service_name' => 'Test', 'qty' => 1, 'unit_price' => 10.00],
            ],
        ], $token);

        $this->assertEquals(403, $response['status']);
        $this->assertFalse($response['body']['success']);
        $this->assertEquals('FORBIDDEN', $response['body']['error_code']);
    }

    public function testListOrdersWithPagination(): void
    {
        $token = $this->loginAs('frontdesk1');
        $response = $this->request('GET', 'orders', ['page' => 1, 'page_size' => 10], $token);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $data = $response['body']['data'];
        $this->assertArrayHasKey('items', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('page', $data);
        $this->assertArrayHasKey('page_size', $data);
        $this->assertIsArray($data['items']);
        $this->assertIsInt($data['total']);
    }

    public function testGetOrderNotFound(): void
    {
        $token = $this->loginAs('frontdesk1');
        $response = $this->request('GET', 'orders/99999', [], $token);

        $this->assertEquals(404, $response['status']);
        $this->assertFalse($response['body']['success']);
    }

    public function testCancelOrderRequiresReason(): void
    {
        $token = $this->loginAs('frontdesk1');

        // Create an order first
        $createResponse = $this->request('POST', 'orders', [
            'customer_name' => 'Cancel Test',
            'items' => [['service_code' => 'SVC-001', 'service_name' => 'Test', 'qty' => 1, 'unit_price' => 10.00]],
        ], $token);

        $orderId = $createResponse['body']['data']['id'] ?? null;
        if ($orderId) {
            // Try to cancel without reason
            $response = $this->request('POST', "orders/{$orderId}/cancel", ['reason' => ''], $token);
            $this->assertEquals(400, $response['status']);
            $this->assertFalse($response['body']['success'] ?? true);
        }
    }

    public function testTechnicianCannotAlterPricing(): void
    {
        $token = $this->loginAs('tech1');

        $response = $this->request('PATCH', 'orders/1', [
            'subtotal_amount' => 9999.99,
        ], $token);

        // Technician can PATCH but pricing fields are silently stripped by service layer
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        // Verify pricing was NOT changed to the value technician sent
        $this->assertNotEquals(9999.99, $response['body']['data']['subtotal_amount'] ?? 0);
    }

    public function testCrossStoreAccessDenied(): void
    {
        // Tech2 is in Store 2 - should not see Store 1 orders
        $token = $this->loginAs('tech2');
        $response = $this->request('GET', 'orders/1', [], $token);

        // Returns 404 because technician scope filter hides cross-store orders
        $this->assertEquals(404, $response['status']);
    }

    public function testOrderFullLifecycle(): void
    {
        $token = $this->loginAs('frontdesk1');
        $this->assertNotNull($token);

        // Create
        $createResponse = $this->request('POST', 'orders', [
            'customer_name' => 'Lifecycle Test',
            'items' => [['service_code' => 'SVC-001', 'service_name' => 'Full Service', 'qty' => 1, 'unit_price' => 100.00]],
        ], $token);
        $this->assertTrue($createResponse['body']['success'] ?? false);
        $orderId = $createResponse['body']['data']['id'] ?? null;
        $this->assertNotNull($orderId);

        // Confirm
        $confirmResponse = $this->request('POST', "orders/{$orderId}/confirm", [], $token);
        $this->assertTrue($confirmResponse['body']['success'] ?? false);

        // Assign technician
        $assignResponse = $this->request('POST', "orders/{$orderId}/assign-technician", [
            'technician_id' => 3, // tech1
        ], $token);
        $this->assertTrue($assignResponse['body']['success'] ?? false);

        // Accept as technician
        $techToken = $this->loginAs('tech1');
        $acceptResponse = $this->request('POST', "orders/{$orderId}/accept", [], $techToken);
        $this->assertTrue($acceptResponse['body']['success'] ?? false);

        // Complete
        $completeResponse = $this->request('POST', "orders/{$orderId}/complete", [], $techToken);
        $this->assertTrue($completeResponse['body']['success'] ?? false);
    }

    public function testInvalidStateTransition(): void
    {
        $token = $this->loginAs('frontdesk1');

        // Create order (draft)
        $createResponse = $this->request('POST', 'orders', [
            'customer_name' => 'Transition Test',
            'items' => [['service_code' => 'SVC-001', 'service_name' => 'Test', 'qty' => 1, 'unit_price' => 10.00]],
        ], $token);
        $orderId = $createResponse['body']['data']['id'] ?? null;

        if ($orderId) {
            // Try to complete directly from draft (should fail with 409)
            $response = $this->request('POST', "orders/{$orderId}/complete", [], $token);
            $this->assertEquals(409, $response['status']);
        }
    }

    // POST /orders/:id/apply-coupon
    //
    // The matching frontend flow at frontend/tests/e2e/kioskCouponFlow.test.js
    // uses a mocked fetch; these are real HTTP tests against the backend.

    public function testApplyCouponOnForeignOrderReturns403(): void
    {
        // F-005: the apply-coupon ownership guard is a separate concern
        // from the valid-coupon pricing path covered by
        // testApplyValidCouponDiscountsOrder. A caller pointing at another
        // store's order_id must be blocked before any coupon lookup or
        // redemption row is written — a bad code would otherwise masquerade
        // as a 400 COUPON_INVALID and hide the real authorization failure.
        $store1Token = $this->loginAs('frontdesk1');
        $this->assertNotNull($store1Token);

        $createResp = $this->request('POST', 'orders', [
            'customer_name' => 'Foreign coupon ownership target',
            'items' => [['service_code' => 'SVC-001', 'service_name' => 'Service', 'qty' => 1, 'unit_price' => 100.00]],
        ], $store1Token);
        $foreignOrderId = $createResp['body']['data']['id'] ?? null;
        $this->assertNotNull($foreignOrderId);

        $store2Token = $this->loginAs('frontdesk2', 2, 4);
        if (!$store2Token) { $this->markTestSkipped('frontdesk2 not available'); }

        $resp = $this->request('POST', "orders/{$foreignOrderId}/apply-coupon", [
            'code' => 'WELCOME10',
        ], $store2Token);
        $this->assertEquals(403, $resp['status'],
            'Apply-coupon against a foreign order_id must return 403, not 400 COUPON_INVALID');
        $this->assertEquals('FORBIDDEN', $resp['body']['error_code'] ?? '',
            'Error code must be FORBIDDEN so clients can distinguish ownership failure from coupon-invalid');
    }

    public function testApplyValidCouponDiscountsOrder(): void
    {
        $token = $this->loginAs('frontdesk1');
        $this->assertNotNull($token);

        // Seed coupon WELCOME10 = 10% off, min spend $50, scoped to store_id=1
        $createResp = $this->request('POST', 'orders', [
            'customer_name' => 'Coupon Apply Test',
            'items' => [['service_code' => 'SVC-001', 'service_name' => 'Service', 'qty' => 1, 'unit_price' => 100.00]],
        ], $token);
        $this->assertEquals(201, $createResp['status']);
        $orderId = $createResp['body']['data']['id'] ?? null;
        $this->assertNotNull($orderId);
        $originalTotal = (float) $createResp['body']['data']['total_amount'];

        $applyResp = $this->request('POST', "orders/{$orderId}/apply-coupon", [
            'code' => 'WELCOME10',
        ], $token);
        $this->assertEquals(200, $applyResp['status']);
        $this->assertTrue($applyResp['body']['success'] ?? false);

        $readResp = $this->request('GET', "orders/{$orderId}", [], $token);
        $this->assertEquals(200, $readResp['status']);
        $updatedTotal = (float) $readResp['body']['data']['total_amount'];
        $updatedDiscount = (float) $readResp['body']['data']['discount_amount'];

        // A 10% coupon on $100 subtotal must produce a non-zero discount and
        // reduce the total from the un-couponed original.
        $this->assertGreaterThan(0, $updatedDiscount, 'Discount amount must be > 0 after applying WELCOME10');
        $this->assertLessThan($originalTotal, $updatedTotal, 'Total must be reduced after coupon applied');
    }

    public function testApplyInvalidCouponIsRejected(): void
    {
        $token = $this->loginAs('frontdesk1');
        $this->assertNotNull($token);

        $createResp = $this->request('POST', 'orders', [
            'customer_name' => 'Coupon Invalid Test',
            'items' => [['service_code' => 'SVC-001', 'service_name' => 'Service', 'qty' => 1, 'unit_price' => 100.00]],
        ], $token);
        $orderId = $createResp['body']['data']['id'] ?? null;
        $this->assertNotNull($orderId);

        $applyResp = $this->request('POST', "orders/{$orderId}/apply-coupon", [
            'code' => 'DOES_NOT_EXIST_' . time(),
        ], $token);
        $this->assertEquals(400, $applyResp['status']);
        $this->assertFalse($applyResp['body']['success'] ?? true);
    }

    public function testApplyCouponMissingCodeFails(): void
    {
        $token = $this->loginAs('frontdesk1');
        $this->assertNotNull($token);

        $createResp = $this->request('POST', 'orders', [
            'customer_name' => 'Coupon Missing Test',
            'items' => [['service_code' => 'SVC-001', 'service_name' => 'Service', 'qty' => 1, 'unit_price' => 100.00]],
        ], $token);
        $orderId = $createResp['body']['data']['id'] ?? null;
        $this->assertNotNull($orderId);

        $applyResp = $this->request('POST', "orders/{$orderId}/apply-coupon", [
            'code' => '',
        ], $token);
        $this->assertEquals(400, $applyResp['status']);
        $this->assertEquals('VALIDATION_ERROR', $applyResp['body']['error_code']);
    }
}
