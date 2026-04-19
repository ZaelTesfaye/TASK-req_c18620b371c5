<?php
namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * DashboardApiTest - API tests for dashboard endpoints.
 * Routes: dashboards/operations (GET), dashboards/analytics (GET),
 * dashboards/operations/export.csv (GET)
 */
class DashboardApiTest extends TestCase
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

    // Auth guard

    public function testOperationsRequiresAuth(): void
    {
        $response = $this->request('GET', 'dashboards/operations');
        $this->assertEquals(401, $response['status']);
    }

    public function testAnalyticsRequiresAuth(): void
    {
        $response = $this->request('GET', 'dashboards/analytics');
        $this->assertEquals(401, $response['status']);
    }

    public function testCsvExportRequiresAuth(): void
    {
        $response = $this->request('GET', 'dashboards/operations/export.csv');
        $this->assertEquals(401, $response['status']);
    }

    // RBAC

    public function testCustomerCannotAccessOperations(): void
    {
        $token = $this->loginAs('customer1');
        if (!$token) { $this->markTestSkipped('customer1 not available'); }

        $response = $this->request('GET', 'dashboards/operations', [
            'from' => '01/01/2025', 'to' => '12/31/2025',
        ], $token);
        $this->assertEquals(403, $response['status']);
    }

    public function testAdminCanAccessOperations(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $response = $this->request('GET', 'dashboards/operations', [
            'from' => '01/01/2025', 'to' => '12/31/2025',
        ], $token);
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $data = $response['body']['data'];
        // Verify all expected operations metric keys and types
        $this->assertArrayHasKey('transaction_volume', $data);
        $this->assertArrayHasKey('avg_fulfillment_time', $data);
        $this->assertArrayHasKey('cancellation_rate', $data);
        $this->assertArrayHasKey('complaint_rate', $data);
        $this->assertArrayHasKey('total_orders', $data);
        $this->assertArrayHasKey('store_id', $data);
        $this->assertIsInt($data['transaction_volume']);
        $this->assertIsNumeric($data['cancellation_rate']);
        $this->assertGreaterThanOrEqual(0, $data['cancellation_rate']);
        $this->assertLessThanOrEqual(1, $data['cancellation_rate']);
    }

    public function testManagerCanAccessOperations(): void
    {
        $token = $this->loginAs('manager1');
        if (!$token) { $this->markTestSkipped('manager1 not available'); }

        $response = $this->request('GET', 'dashboards/operations', [
            'from' => '01/01/2025', 'to' => '12/31/2025',
        ], $token);
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertArrayHasKey('transaction_volume', $response['body']['data']);
    }

    public function testAdminCanAccessAnalytics(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $response = $this->request('GET', 'dashboards/analytics', [
            'from' => '01/01/2025', 'to' => '12/31/2025',
        ], $token);
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $data = $response['body']['data'];
        // Verify all analytics metric keys and types
        $this->assertArrayHasKey('activity', $data);
        $this->assertArrayHasKey('conversion', $data);
        $this->assertArrayHasKey('retention', $data);
        $this->assertArrayHasKey('content_quality', $data);
        $this->assertArrayHasKey('zero_result_search_rate', $data);
        $this->assertIsNumeric($data['activity']);
        $this->assertIsNumeric($data['conversion']);
    }

    public function testCsvExportReturnsData(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $resp = $this->requestCsvRaw('dashboards/operations/export.csv', [
            'from' => '01/01/2025', 'to' => '12/31/2025',
        ], $token);
        $this->assertEquals(200, $resp['status']);

        // Pin the content-type AND that the file contains the canonical
        // header row + at least one data row. A 200 response alone does not
        // prove the export actually emitted CSV — an empty or HTML-wrapped
        // body would otherwise slip through.
        $this->assertStringContainsString('text/csv', strtolower($resp['content_type']));
        $lines = preg_split('/\r?\n/', trim($resp['body']));
        $this->assertEquals('Metric,Value', $lines[0]);
        $this->assertGreaterThan(1, count($lines),
            'CSV export should include at least one metric row beyond the header');
    }

    // ---- CSV export schema assertions ----
    //
    // The CSV produced by DashboardService::exportOperationsCsv is a vertical
    // "Metric,Value" layout. These tests pin the Content-Type, the header
    // row, and every metric name the export is contracted to produce. If
    // someone renames or drops a metric the suite breaks, so downstream
    // consumers (BI imports, spreadsheet macros) don't silently diverge.

    private function requestCsvRaw(string $path, array $data, ?string $token): array
    {
        $url = $this->baseUrl . '/api/v1/' . ltrim($path, '/');
        if (!empty($data)) {
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

    public function testOperationsCsvExportHasExpectedContentTypeAndSchema(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $resp = $this->requestCsvRaw('dashboards/operations/export.csv', [
            'from' => '01/01/2025', 'to' => '12/31/2025',
        ], $token);
        $this->assertEquals(200, $resp['status']);
        $this->assertStringContainsString('text/csv', strtolower($resp['content_type']),
            'CSV export must be served with Content-Type: text/csv');
        $this->assertNotEmpty($resp['body'], 'CSV export body must not be empty');

        $lines = preg_split('/\r?\n/', trim($resp['body']));
        $this->assertGreaterThan(1, count($lines), 'CSV export should have more than just a header row');

        // Header row is fixed
        $this->assertEquals('Metric,Value', $lines[0],
            'CSV header row must be "Metric,Value"');

        // Every line past the header must be a comma-separated pair
        foreach (array_slice($lines, 1) as $i => $line) {
            $parts = explode(',', $line, 2);
            $this->assertCount(2, $parts,
                "CSV row " . ($i + 2) . " does not have exactly 2 columns: '{$line}'");
        }

        // Pin the set of metrics the export is contracted to produce so
        // silent drift (removed or renamed metric rows) is a test failure.
        $requiredMetrics = [
            'Store ID',
            'Date Range',
            'Transaction Volume',
            'Avg Fulfillment Time (min)',
            'Cancellation Rate',
            'Complaint Rate',
            'Total Orders',
            'Cancelled Orders',
            'Completed Orders',
            'Complaint Orders',
        ];
        foreach ($requiredMetrics as $name) {
            $this->assertTrue(
                (bool) preg_match('/^' . preg_quote($name, '/') . ',/m', $resp['body']),
                "CSV export is missing required metric row: {$name}"
            );
        }
    }

    public function testOperationsCsvExportRejectsNonPrivilegedRoles(): void
    {
        // RBAC must apply to the CSV flavour as tightly as the JSON flavour.
        $token = $this->loginAs('customer1');
        if (!$token) { $this->markTestSkipped('customer1 not available'); }

        $resp = $this->requestCsvRaw('dashboards/operations/export.csv', [
            'from' => '01/01/2025', 'to' => '12/31/2025',
        ], $token);
        $this->assertEquals(403, $resp['status']);
    }

    // Invalid date handling

    public function testOperationsWithInvalidDateReturnsError(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $response = $this->request('GET', 'dashboards/operations', [
            'from' => 'not-a-date', 'to' => 'also-invalid',
        ], $token);
        $this->assertEquals(400, $response['status']);
    }
}
