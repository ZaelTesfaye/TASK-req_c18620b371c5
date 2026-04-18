<?php
namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * EnvironmentalApiTest - API tests for environmental sensor endpoints.
 * Routes: environment/import/csv, environment/import/sensor-feed,
 * environment/aligned-buckets, environment/derived-metrics, environment/lineage/:id,
 * environment/formulas
 */
class EnvironmentalApiTest extends TestCase
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
        $response = $this->request('POST', 'auth/login', [
            'username' => $username,
            'password' => 'Demo12345678!',
            'store_id' => 1,
            'workstation_id' => 1,
        ]);
        return $response['body']['data']['token'] ?? null;
    }

    // Auth guard tests

    public function testImportCsvRequiresAuth(): void
    {
        $response = $this->request('POST', 'environment/import/csv');
        $this->assertEquals(401, $response['status']);
    }

    public function testSensorFeedRequiresAuth(): void
    {
        $response = $this->request('POST', 'environment/import/sensor-feed');
        $this->assertEquals(401, $response['status']);
    }

    public function testAlignedBucketsRequiresAuth(): void
    {
        $response = $this->request('GET', 'environment/aligned-buckets');
        $this->assertEquals(401, $response['status']);
    }

    public function testDerivedMetricsRequiresAuth(): void
    {
        $response = $this->request('GET', 'environment/derived-metrics');
        $this->assertEquals(401, $response['status']);
    }

    public function testLineageRequiresAuth(): void
    {
        $response = $this->request('GET', 'environment/lineage/1');
        $this->assertEquals(401, $response['status']);
    }

    public function testFormulasRequiresAuth(): void
    {
        $response = $this->request('GET', 'environment/formulas');
        $this->assertEquals(401, $response['status']);
    }

    // RBAC tests

    public function testCustomerCannotImportCsv(): void
    {
        $token = $this->loginAs('customer1');
        if (!$token) { $this->markTestSkipped('customer1 not available'); }

        $response = $this->request('POST', 'environment/import/csv', [
            'source_id' => 1,
            'records' => [['metric_type' => 'temperature', 'metric_value' => 72.5, 'observed_at' => '2025-01-15 10:00:00']],
        ], $token);
        $this->assertEquals(403, $response['status']);
        $this->assertFalse($response['body']['success'] ?? true);
        $this->assertEquals('FORBIDDEN', $response['body']['error_code'] ?? '');
    }

    public function testAdminCanImportCsv(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $response = $this->request('POST', 'environment/import/csv', [
            'source_id' => 1,
            'records' => [
                ['metric_type' => 'temperature', 'metric_value' => 72.5, 'observed_at' => '2025-01-15 10:00:00'],
            ],
        ], $token);
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success'] ?? false);
        $this->assertArrayHasKey('imported', $response['body']['data'] ?? []);
        $this->assertEquals(1, $response['body']['data']['imported']);
    }

    public function testAdminCanViewAlignedBuckets(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $response = $this->request('GET', 'environment/aligned-buckets', [], $token);
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $data = $response['body']['data'];
        $this->assertArrayHasKey('items', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertIsArray($data['items']);
    }

    public function testAdminCanViewDerivedMetrics(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $response = $this->request('GET', 'environment/derived-metrics', [], $token);
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $data = $response['body']['data'];
        $this->assertArrayHasKey('items', $data);
        $this->assertArrayHasKey('total', $data);
    }

    public function testAdminCanViewFormulas(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $response = $this->request('GET', 'environment/formulas', [], $token);
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
    }

    public function testLineageNotFoundReturns404(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $response = $this->request('GET', 'environment/lineage/99999', [], $token);
        $this->assertEquals(404, $response['status']);
        $this->assertFalse($response['body']['success']);
    }

    // POST /environment/formulas

    public function testCreateFormulaShowsUpInListing(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $formulaKey = 'test_formula_' . time() . '_' . rand(1000, 9999);
        $createResp = $this->request('POST', 'environment/formulas', [
            'formula_key' => $formulaKey,
            'formula_expression' => 'avg(temperature) * 1.0',
            'thresholds' => ['warn' => 75, 'alert' => 90],
        ], $token);
        $this->assertEquals(201, $createResp['status']);
        $this->assertTrue($createResp['body']['success'] ?? false);
        $formulaId = $createResp['body']['data']['id'] ?? null;
        $this->assertNotNull($formulaId);
        $this->assertEquals($formulaKey, $createResp['body']['data']['formula_key']);

        // Verify it appears in the subsequent GET
        $listResp = $this->request('GET', 'environment/formulas', [], $token);
        $this->assertEquals(200, $listResp['status']);
        $items = $listResp['body']['data'] ?? [];
        $found = false;
        foreach ($items as $item) {
            if (($item['id'] ?? null) === $formulaId) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "Newly created formula {$formulaKey} was not found in GET /environment/formulas");
    }

    public function testCreateFormulaValidationError(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $resp = $this->request('POST', 'environment/formulas', [
            'formula_key' => '', // missing
            'formula_expression' => '',
        ], $token);
        $this->assertEquals(400, $resp['status']);
        $this->assertEquals('VALIDATION_ERROR', $resp['body']['error_code']);
    }

    // PATCH /environment/formulas/:id

    public function testPatchFormulaUpdatesAndPersists(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $formulaKey = 'patch_formula_' . time() . '_' . rand(1000, 9999);
        $createResp = $this->request('POST', 'environment/formulas', [
            'formula_key' => $formulaKey,
            'formula_expression' => 'avg(temperature)',
            'thresholds' => ['warn' => 70],
        ], $token);
        $formulaId = $createResp['body']['data']['id'] ?? null;
        $this->assertNotNull($formulaId);

        $patchResp = $this->request('PATCH', "environment/formulas/{$formulaId}", [
            'formula_expression' => 'avg(temperature) * 0.9',
            'thresholds' => ['warn' => 80, 'alert' => 95],
        ], $token);
        $this->assertEquals(200, $patchResp['status']);
        $this->assertTrue($patchResp['body']['success'] ?? false);
        // The PATCH response reflects the post-update row read back from the DB,
        // so asserting against its body verifies the change was persisted.
        $this->assertEquals('avg(temperature) * 0.9', $patchResp['body']['data']['formula_expression']);
        $thresholds = json_decode($patchResp['body']['data']['threshold_json'] ?? '{}', true);
        $this->assertEquals(80, $thresholds['warn'] ?? null);
        $this->assertEquals(95, $thresholds['alert'] ?? null);

        // Cross-check via the listing endpoint as an independent read path.
        $listResp = $this->request('GET', 'environment/formulas', [], $token);
        $found = null;
        foreach ($listResp['body']['data'] ?? [] as $f) {
            if (($f['id'] ?? null) === $formulaId) { $found = $f; break; }
        }
        $this->assertNotNull($found, 'Updated formula must still be present in the listing');
        $this->assertEquals('avg(temperature) * 0.9', $found['formula_expression']);
    }

    // GET /environment/formulas/:id

    public function testReadFormulaByIdReturnsPersistedRow(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $formulaKey = 'read_by_id_' . time() . '_' . rand(1000, 9999);
        $createResp = $this->request('POST', 'environment/formulas', [
            'formula_key' => $formulaKey,
            'formula_expression' => 'avg(humidity)',
            'thresholds' => ['warn' => 60],
        ], $token);
        $formulaId = $createResp['body']['data']['id'] ?? null;
        $this->assertNotNull($formulaId);

        $readResp = $this->request('GET', "environment/formulas/{$formulaId}", [], $token);
        $this->assertEquals(200, $readResp['status']);
        $this->assertTrue($readResp['body']['success'] ?? false);
        $this->assertEquals($formulaId, $readResp['body']['data']['id']);
        $this->assertEquals($formulaKey, $readResp['body']['data']['formula_key']);
        $this->assertEquals('avg(humidity)', $readResp['body']['data']['formula_expression']);
    }

    public function testReadFormulaByIdReturns404WhenMissing(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $resp = $this->request('GET', 'environment/formulas/99999999', [], $token);
        $this->assertEquals(404, $resp['status']);
        $this->assertEquals('NOT_FOUND', $resp['body']['error_code']);
    }
}
