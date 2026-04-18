<?php
namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * PaymentApiTest - API tests for payment and refund endpoints.
 * Routes: orders/:id/payments (POST), orders/:id/refunds (POST)
 */
class PaymentApiTest extends TestCase
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
        if ($username === 'customer1') { $wsId = 3; }
        if ($username === 'tech1') { $wsId = 2; }

        $response = $this->request('POST', 'auth/login', [
            'username' => $username,
            'password' => 'Demo12345678!',
            'store_id' => $storeId,
            'workstation_id' => $wsId,
        ]);
        return $response['body']['data']['token'] ?? null;
    }

    private function createOrder(string $token): ?int
    {
        $resp = $this->request('POST', 'orders', [
            'customer_name' => 'Payment Test ' . time(),
            'items' => [['service_code' => 'SVC-001', 'service_name' => 'Service', 'qty' => 1, 'unit_price' => 100.00]],
        ], $token);
        return $resp['body']['data']['id'] ?? null;
    }

    // Auth guard

    public function testRecordPaymentRequiresAuth(): void
    {
        $response = $this->request('POST', 'orders/1/payments');
        $this->assertEquals(401, $response['status']);
    }

    public function testProcessRefundRequiresAuth(): void
    {
        $response = $this->request('POST', 'orders/1/refunds');
        $this->assertEquals(401, $response['status']);
    }

    // RBAC

    public function testCustomerCannotRecordPayment(): void
    {
        $token = $this->loginAs('customer1');
        if (!$token) { $this->markTestSkipped('customer1 not available'); }

        $response = $this->request('POST', 'orders/1/payments', [
            'tender_type' => 'cash',
            'amount' => 50.00,
        ], $token);
        $this->assertEquals(403, $response['status']);
    }

    public function testTechnicianCannotRecordPayment(): void
    {
        $token = $this->loginAs('tech1');
        if (!$token) { $this->markTestSkipped('tech1 not available'); }

        $response = $this->request('POST', 'orders/1/payments', [
            'tender_type' => 'cash',
            'amount' => 50.00,
        ], $token);
        $this->assertEquals(403, $response['status']);
    }

    // Payment recording

    public function testRecordCashPaymentSuccess(): void
    {
        $token = $this->loginAs('frontdesk1');
        $this->assertNotNull($token);
        $orderId = $this->createOrder($token);
        $this->assertNotNull($orderId);

        $response = $this->request('POST', "orders/{$orderId}/payments", [
            'tender_type' => 'cash',
            'amount' => 50.00,
        ], $token);
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success'] ?? false);
        $this->assertArrayHasKey('payment_id', $response['body']['data'] ?? []);
        $this->assertEquals(50.00, $response['body']['data']['amount'] ?? 0);
    }

    public function testRecordCardPresentPayment(): void
    {
        $token = $this->loginAs('frontdesk1');
        $this->assertNotNull($token);
        $orderId = $this->createOrder($token);
        $this->assertNotNull($orderId);

        $response = $this->request('POST', "orders/{$orderId}/payments", [
            'tender_type' => 'card_present_recorded',
            'amount' => 75.00,
        ], $token);
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success'] ?? false);
    }

    public function testRecordHouseAccountPayment(): void
    {
        $token = $this->loginAs('frontdesk1');
        $this->assertNotNull($token);
        $orderId = $this->createOrder($token);
        $this->assertNotNull($orderId);

        $response = $this->request('POST', "orders/{$orderId}/payments", [
            'tender_type' => 'house_account',
            'amount' => 25.00,
        ], $token);
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success'] ?? false);
    }

    // Validation

    public function testInvalidTenderTypeRejected(): void
    {
        $token = $this->loginAs('frontdesk1');
        $this->assertNotNull($token);
        $orderId = $this->createOrder($token);
        $this->assertNotNull($orderId);

        $response = $this->request('POST', "orders/{$orderId}/payments", [
            'tender_type' => 'bitcoin',
            'amount' => 50.00,
        ], $token);
        $this->assertEquals(400, $response['status']);
        $this->assertFalse($response['body']['success'] ?? true);
    }

    public function testZeroAmountRejected(): void
    {
        $token = $this->loginAs('frontdesk1');
        $this->assertNotNull($token);
        $orderId = $this->createOrder($token);
        $this->assertNotNull($orderId);

        $response = $this->request('POST', "orders/{$orderId}/payments", [
            'tender_type' => 'cash',
            'amount' => 0,
        ], $token);
        $this->assertEquals(400, $response['status']);
    }

    public function testNegativeAmountRejected(): void
    {
        $token = $this->loginAs('frontdesk1');
        $this->assertNotNull($token);
        $orderId = $this->createOrder($token);
        $this->assertNotNull($orderId);

        $response = $this->request('POST', "orders/{$orderId}/payments", [
            'tender_type' => 'cash',
            'amount' => -10.00,
        ], $token);
        $this->assertEquals(400, $response['status']);
    }

    public function testPaymentOnNonExistentOrderReturns404(): void
    {
        $token = $this->loginAs('frontdesk1');
        $this->assertNotNull($token);

        $response = $this->request('POST', 'orders/99999/payments', [
            'tender_type' => 'cash',
            'amount' => 50.00,
        ], $token);
        $this->assertEquals(404, $response['status']);
    }

    // Refund

    public function testRefundWithInvalidPaymentIdRejected(): void
    {
        $token = $this->loginAs('frontdesk1');
        $this->assertNotNull($token);
        $orderId = $this->createOrder($token);
        $this->assertNotNull($orderId);

        $response = $this->request('POST', "orders/{$orderId}/refunds", [
            'original_payment_id' => 99999,
            'amount' => 10.00,
            'reason' => 'Test refund',
        ], $token);
        $this->assertEquals(400, $response['status']);
        $this->assertFalse($response['body']['success'] ?? true);
    }

    public function testRefundOnNonExistentOrderReturns404(): void
    {
        $token = $this->loginAs('frontdesk1');
        $this->assertNotNull($token);

        $response = $this->request('POST', 'orders/99999/refunds', [
            'original_payment_id' => 1,
            'amount' => 10.00,
            'reason' => 'Test',
        ], $token);
        $this->assertEquals(404, $response['status']);
    }

    // Amount due tracking

    public function testAmountDueDecreasesAfterPayment(): void
    {
        $token = $this->loginAs('frontdesk1');
        $this->assertNotNull($token);
        $orderId = $this->createOrder($token);
        $this->assertNotNull($orderId);

        $response = $this->request('POST', "orders/{$orderId}/payments", [
            'tender_type' => 'cash',
            'amount' => 30.00,
        ], $token);
        $this->assertEquals(200, $response['status']);
        $amountDue = $response['body']['data']['amount_due'] ?? null;
        $this->assertNotNull($amountDue);
        // Order total is ~108.00 (100 + 8% tax), after 30 payment → ~78
        $this->assertLessThan(108.00, $amountDue);
    }
}
