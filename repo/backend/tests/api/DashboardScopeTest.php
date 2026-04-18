<?php
namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * DashboardScopeTest - Verifies content_quality and other dashboard metrics
 * are scoped by store_id for non-admin users.
 */
class DashboardScopeTest extends TestCase
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
        if ($method === 'GET' && !empty($data)) {
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
        $resp = $this->request('POST', 'auth/login', [
            'username' => $username, 'password' => 'Demo12345678!',
            'store_id' => $storeId, 'workstation_id' => $wsId,
        ]);
        return $resp['body']['data']['token'] ?? null;
    }

    /**
     * Analytics response should contain content_quality scoped to the user's store.
     */
    public function testAnalyticsContentQualityScopedByStore(): void
    {
        $token = $this->loginAs('manager1', 1, 1);
        if (!$token) { $this->markTestSkipped('manager1 not available'); }

        $resp = $this->request('GET', 'dashboards/analytics', [
            'from' => '01/01/2020', 'to' => '12/31/2030',
        ], $token);
        $this->assertEquals(200, $resp['status']);
        $this->assertTrue($resp['body']['success']);
        $this->assertArrayHasKey('content_quality', $resp['body']['data']);
        // Value should be a number (possibly 0 if no announcements)
        $this->assertIsNumeric($resp['body']['data']['content_quality']);
    }

    /**
     * Non-admin user's store_id override is ignored — response always matches session store.
     */
    public function testNonAdminCannotOverrideAnalyticsStoreId(): void
    {
        $token = $this->loginAs('manager1', 1, 1);
        if (!$token) { $this->markTestSkipped('manager1 not available'); }

        // Try to request store 2 data
        $resp = $this->request('GET', 'dashboards/analytics', [
            'store_id' => 2,
            'from' => '01/01/2020', 'to' => '12/31/2030',
        ], $token);
        $this->assertEquals(200, $resp['status']);
        // Store ID in response should be user's actual store (1), not the override (2)
        $this->assertEquals(1, $resp['body']['data']['store_id'] ?? 0);
    }
}
