<?php
namespace tests\api;

use PHPUnit\Framework\TestCase;
use tests\StatusCodes;

/**
 * FinanceApiTest - API tests for finance and reconciliation endpoints.
 * Routes: finance/cash-drawer (POST), finance/cash-drawer/daily (GET),
 * finance/cash-drawer/:id/close (POST), finance/cash-drawer/:id/reopen (POST),
 * finance/reconciliation/exceptions (GET), finance/reconciliation/:id/statement (GET)
 */
class FinanceApiTest extends TestCase
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
        $response = $this->request('POST', 'auth/login', [
            'username' => $username,
            'password' => 'Demo12345678!',
            'store_id' => 1,
            'workstation_id' => 1,
        ]);
        return $response['body']['data']['token'] ?? null;
    }

    private function createOrderAndPay(string $token): ?int
    {
        $orderResp = $this->request('POST', 'orders', [
            'customer_name' => 'Finance Test ' . time(),
            'items' => [['service_code' => 'SVC-001', 'service_name' => 'Service', 'qty' => 1, 'unit_price' => 50.00]],
        ], $token);
        $orderId = $orderResp['body']['data']['id'] ?? null;
        if ($orderId) {
            $this->request('POST', "orders/{$orderId}/payments", [
                'tender_type' => 'cash', 'amount' => 50.00,
            ], $token);
        }
        return $orderId;
    }

    // Auth guard

    public function testOpenDrawerRequiresAuth(): void
    {
        $response = $this->request('POST', 'finance/cash-drawer');
        $this->assertEquals(401, $response['status']);
    }

    public function testDailyDrawerRequiresAuth(): void
    {
        $response = $this->request('GET', 'finance/cash-drawer/daily');
        $this->assertEquals(401, $response['status']);
    }

    public function testExceptionsRequiresAuth(): void
    {
        $response = $this->request('GET', 'finance/reconciliation/exceptions');
        $this->assertEquals(401, $response['status']);
    }

    // RBAC

    public function testReopenDeniedForFinanceRole(): void
    {
        $financeToken = $this->loginAs('finance1');
        $this->assertNotNull($financeToken);

        $response = $this->request('POST', 'finance/cash-drawer/1/reopen', [
            'reason' => 'Error in count',
        ], $financeToken);
        $this->assertEquals(403, $response['status']);
        $this->assertFalse($response['body']['success'] ?? true);
        $this->assertEquals('FORBIDDEN', $response['body']['error_code'] ?? '');
    }

    // Cash drawer open

    public function testOpenDrawerWithUniqueDate(): void
    {
        $token = $this->loginAs('finance1');
        $this->assertNotNull($token);
        // Use a unique past date unlikely to conflict
        $date = '2020-01-' . str_pad(rand(1, 28), 2, '0', STR_PAD_LEFT);

        $response = $this->request('POST', 'finance/cash-drawer', [
            'store_id' => 1,
            'business_date' => $date,
            'open_amount' => 100.00,
        ], $token);
        // 201 on first call, 409 if date already taken
        if ($response['status'] === StatusCodes::DRAWER_OPENED) {
            $this->assertTrue($response['body']['success'] ?? false);
            $this->assertArrayHasKey('id', $response['body']['data'] ?? []);
        } else {
            $this->assertEquals(StatusCodes::CONFLICT, $response['status']);
            $this->assertEquals('CONFLICT', $response['body']['error_code'] ?? '');
        }
    }

    // Reconciliation exceptions

    public function testGetReconciliationExceptions(): void
    {
        $token = $this->loginAs('finance1');
        $this->assertNotNull($token);

        $response = $this->request('GET', 'finance/reconciliation/exceptions', ['store_id' => 1], $token);
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success'] ?? false);
    }

    // Cash drawer close and reopen lifecycle

    public function testCloseAndReopenLifecycle(): void
    {
        $adminToken = $this->loginAs('admin');
        $this->assertNotNull($adminToken);

        // Open a drawer with a unique date
        $date = '2019-06-' . str_pad(rand(1, 28), 2, '0', STR_PAD_LEFT);
        $openResp = $this->request('POST', 'finance/cash-drawer', [
            'store_id' => 1,
            'business_date' => $date,
            'open_amount' => 200.00,
        ], $adminToken);

        if ($openResp['status'] !== StatusCodes::DRAWER_OPENED) {
            $this->markTestSkipped('Could not open drawer for lifecycle test');
        }

        $drawerId = $openResp['body']['data']['id'] ?? null;
        $this->assertNotNull($drawerId);

        // Close it
        $closeResp = $this->request('POST', "finance/cash-drawer/{$drawerId}/close", [
            'counted_total' => 200.00,
        ], $adminToken);
        $this->assertEquals(200, $closeResp['status']);
        $this->assertTrue($closeResp['body']['success'] ?? false);
        $this->assertEquals(0, $closeResp['body']['data']['discrepancy_flag'] ?? -1);

        // Reopen it (admin only, with reason)
        $reopenResp = $this->request('POST', "finance/cash-drawer/{$drawerId}/reopen", [
            'reason' => 'Found missing receipt',
        ], $adminToken);
        $this->assertEquals(200, $reopenResp['status']);
        $this->assertTrue($reopenResp['body']['success'] ?? false);
        $this->assertEquals('reopened', $reopenResp['body']['data']['status'] ?? '');
    }

    // Reopen validation

    public function testReopenWithEmptyReasonRejected(): void
    {
        $adminToken = $this->loginAs('admin');
        $this->assertNotNull($adminToken);

        // Open + close a drawer
        $date = '2019-07-' . str_pad(rand(1, 28), 2, '0', STR_PAD_LEFT);
        $openResp = $this->request('POST', 'finance/cash-drawer', [
            'store_id' => 1, 'business_date' => $date, 'open_amount' => 100.00,
        ], $adminToken);

        if ($openResp['status'] !== StatusCodes::DRAWER_OPENED) {
            $this->markTestSkipped('Could not open drawer');
        }
        $drawerId = $openResp['body']['data']['id'];

        $this->request('POST', "finance/cash-drawer/{$drawerId}/close", [
            'counted_total' => 100.00,
        ], $adminToken);

        // Try reopening with empty reason
        $response = $this->request('POST', "finance/cash-drawer/{$drawerId}/reopen", [
            'reason' => '',
        ], $adminToken);
        $this->assertEquals(400, $response['status']);
        $this->assertFalse($response['body']['success'] ?? true);
    }

    // Discrepancy detection

    public function testCloseWithDiscrepancy(): void
    {
        $adminToken = $this->loginAs('admin');
        $this->assertNotNull($adminToken);

        $date = '2019-08-' . str_pad(rand(1, 28), 2, '0', STR_PAD_LEFT);
        $openResp = $this->request('POST', 'finance/cash-drawer', [
            'store_id' => 1, 'business_date' => $date, 'open_amount' => 500.00,
        ], $adminToken);

        if ($openResp['status'] !== StatusCodes::DRAWER_OPENED) {
            $this->markTestSkipped('Could not open drawer');
        }
        $drawerId = $openResp['body']['data']['id'];

        // Close with large variance (counted much less than expected)
        $closeResp = $this->request('POST', "finance/cash-drawer/{$drawerId}/close", [
            'counted_total' => 490.00,
        ], $adminToken);
        $this->assertEquals(200, $closeResp['status']);
        // Variance = 500.00 - 490.00 = 10.00 > 1.00 threshold
        $this->assertEquals(1, $closeResp['body']['data']['discrepancy_flag'] ?? 0);
    }

    // Statement retrieval

    public function testGetStatementAfterClose(): void
    {
        $adminToken = $this->loginAs('admin');
        $this->assertNotNull($adminToken);

        $date = '2019-09-' . str_pad(rand(1, 28), 2, '0', STR_PAD_LEFT);
        $openResp = $this->request('POST', 'finance/cash-drawer', [
            'store_id' => 1, 'business_date' => $date, 'open_amount' => 100.00,
        ], $adminToken);

        if ($openResp['status'] !== StatusCodes::DRAWER_OPENED) {
            $this->markTestSkipped('Could not open drawer');
        }
        $drawerId = $openResp['body']['data']['id'];

        $this->request('POST', "finance/cash-drawer/{$drawerId}/close", [
            'counted_total' => 100.00,
        ], $adminToken);

        $stmtResp = $this->request('GET', "finance/reconciliation/{$drawerId}/statement", [], $adminToken);
        $this->assertEquals(200, $stmtResp['status']);
        $this->assertTrue($stmtResp['body']['success'] ?? false);
    }

    // CSV statement export — verifies content-type and body are actual CSV,
    // not a JSON envelope.

    private function requestRaw(string $method, string $path, array $data = [], ?string $token = null): array
    {
        $url = $this->baseUrl . '/api/v1/' . ltrim($path, '/');
        if ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $headers = ['Accept: text/csv'];
        if ($token) { $headers[] = "Authorization: Bearer {$token}"; }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        $contentType = '';
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (stripos($line, 'Content-Type:') === 0) {
                $contentType = trim(substr($line, strlen('Content-Type:')));
            }
        }
        return ['status' => $status, 'body' => $body, 'content_type' => $contentType];
    }

    public function testReconciliationStatementCsvExport(): void
    {
        $adminToken = $this->loginAs('admin');
        $this->assertNotNull($adminToken);

        $date = '2019-10-' . str_pad(rand(1, 28), 2, '0', STR_PAD_LEFT);
        $openResp = $this->request('POST', 'finance/cash-drawer', [
            'store_id' => 1, 'business_date' => $date, 'open_amount' => 100.00,
        ], $adminToken);
        if ($openResp['status'] !== StatusCodes::DRAWER_OPENED) {
            $this->markTestSkipped('Could not open drawer');
        }
        $drawerId = $openResp['body']['data']['id'];
        $this->request('POST', "finance/cash-drawer/{$drawerId}/close", [
            'counted_total' => 100.00,
        ], $adminToken);

        $csvResp = $this->requestRaw('GET', "finance/reconciliation/{$drawerId}/statement.csv", [], $adminToken);
        $this->assertEquals(200, $csvResp['status']);
        $this->assertStringContainsString('text/csv', strtolower($csvResp['content_type']));
        $this->assertNotEmpty($csvResp['body'], 'CSV body must not be empty');
        // A valid CSV export contains at least one header row terminated by a newline.
        $this->assertStringContainsString(',', $csvResp['body']);
        $this->assertStringContainsString("\n", $csvResp['body']);
    }

    public function testReconciliationStatementCsvRequiresAuth(): void
    {
        $resp = $this->requestRaw('GET', 'finance/reconciliation/1/statement.csv');
        $this->assertEquals(401, $resp['status']);
    }
}
