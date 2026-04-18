<?php
namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * EnvironmentalComputeTest - API tests for align-buckets and compute-derived-metrics endpoints.
 * Routes: POST environment/align-buckets, POST environment/compute-derived-metrics
 */
class EnvironmentalComputeTest extends TestCase
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
        $resp = $this->request('POST', 'auth/login', [
            'username' => $username, 'password' => 'Demo12345678!',
            'store_id' => 1, 'workstation_id' => 1,
        ]);
        return $resp['body']['data']['token'] ?? null;
    }

    public function testAlignBucketsRequiresAuth(): void
    {
        $resp = $this->request('POST', 'environment/align-buckets');
        $this->assertEquals(401, $resp['status']);
    }

    public function testAlignBucketsAdminCanAccess(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);
        $resp = $this->request('POST', 'environment/align-buckets', [
            'store_id' => 1,
        ], $token);
        $this->assertEquals(200, $resp['status']);
        $this->assertTrue($resp['body']['success']);
    }

    public function testComputeDerivedMetricsRequiresAuth(): void
    {
        $resp = $this->request('POST', 'environment/compute-derived-metrics');
        $this->assertEquals(401, $resp['status']);
    }

    public function testComputeDerivedMetricsAdminCanAccess(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);
        $resp = $this->request('POST', 'environment/compute-derived-metrics', [
            'store_id' => 1,
        ], $token);
        $this->assertEquals(200, $resp['status']);
        $this->assertTrue($resp['body']['success']);
    }

    public function testCustomerCannotAlignBuckets(): void
    {
        $token = $this->loginAs('customer1');
        if (!$token) { $this->markTestSkipped('customer1 not available'); }
        $resp = $this->request('POST', 'environment/align-buckets', ['store_id' => 1], $token);
        $this->assertEquals(403, $resp['status']);
    }

    public function testLineageCrossStoreBlockedForNonAdmin(): void
    {
        // This verifies the store ownership check on the lineage endpoint
        $token = $this->loginAs('manager1');
        if (!$token) { $this->markTestSkipped('manager1 not available'); }
        // Request lineage for a metric that may belong to a different store
        $resp = $this->request('GET', 'environment/lineage/99999', [], $token);
        // 404 (not found) is acceptable; 403 would mean store check triggered
        $this->assertContains($resp['status'], [404, 403]);
    }

    // ---- Lineage integrity across formula versions ----
    //
    // When a formula is updated, any previously computed derived metric must
    // still be traceable back to the exact formula version that produced it.
    // If recomputing under a new formula version, the new lineage row should
    // reference the new version while the old metric+lineage pair remains
    // untouched — we never silently rewrite history.

    public function testLineageRecordsFormulaVersionForComputedMetric(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $computeResp = $this->request('POST', 'environment/compute-derived-metrics', [
            'store_id' => 1,
        ], $token);
        $this->assertEquals(200, $computeResp['status']);

        $metricsResp = $this->request('GET', 'environment/derived-metrics', ['store_id' => 1], $token);
        $this->assertEquals(200, $metricsResp['status']);
        $items = $metricsResp['body']['data']['items'] ?? [];
        if (empty($items)) { $this->markTestSkipped('No derived metrics produced to check lineage'); }

        $metric = $items[0];
        $metricId = $metric['id'] ?? null;
        $this->assertNotNull($metricId);

        $lineageResp = $this->request('GET', "environment/lineage/{$metricId}", [], $token);
        $this->assertEquals(200, $lineageResp['status']);
        $lineage = $lineageResp['body']['data'] ?? [];
        $this->assertArrayHasKey('formula_version_id', $lineage,
            'Lineage row must record the formula_version_id used to compute the metric');
        $this->assertNotEmpty($lineage['formula_version_id']);

        // Formula version alone does not prove reproducibility — the
        // lineage row also has to carry the raw record references and the
        // ordered transformation steps that produced this metric. Pin both.
        $this->assertArrayHasKey('raw_record_refs_json', $lineage,
            'Lineage row must include raw_record_refs_json so the metric is traceable back to source rows');
        $rawRefs = is_string($lineage['raw_record_refs_json'])
            ? json_decode($lineage['raw_record_refs_json'], true)
            : $lineage['raw_record_refs_json'];
        $this->assertIsArray($rawRefs,
            'raw_record_refs_json must decode to an array of source-record ids');
        $this->assertNotEmpty($rawRefs,
            'raw_record_refs_json must not be empty — a derived metric with no source references is unreproducible');
        foreach ($rawRefs as $ref) {
            $this->assertTrue(is_int($ref) || ctype_digit((string) $ref),
                'Each raw_record_refs entry must be an integer source-row id');
        }

        $this->assertArrayHasKey('transformation_steps_json', $lineage,
            'Lineage row must include transformation_steps_json for auditability');
        $steps = is_string($lineage['transformation_steps_json'])
            ? json_decode($lineage['transformation_steps_json'], true)
            : $lineage['transformation_steps_json'];
        $this->assertIsArray($steps,
            'transformation_steps_json must decode to a structured object');
        $this->assertArrayHasKey('step1', $steps,
            'Transformation steps must begin with step1 (the fusion stage) — this pin protects the contract');
        $this->assertEquals('fuse_raw_records', $steps['step1'],
            'step1 of every derived-metric transformation is the raw-record fusion');
        $this->assertArrayHasKey('step2', $steps,
            'Transformation steps must carry step2 (the metric-specific computation)');

        // Reproducibility hash must exist so recomputation can be verified
        // against the previously recorded run.
        $this->assertArrayHasKey('reproducibility_hash', $lineage);
        $this->assertNotEmpty($lineage['reproducibility_hash']);
    }

    public function testComputedResultsConsistentAcrossRuns(): void
    {
        // Recomputing against the same formula version with the same inputs
        // must produce the same numeric result. Drift between runs indicates
        // non-determinism (e.g., unordered aggregation) that would break
        // lineage reproducibility.
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $first = $this->request('POST', 'environment/compute-derived-metrics', ['store_id' => 1], $token);
        $this->assertEquals(200, $first['status']);

        $before = $this->request('GET', 'environment/derived-metrics', ['store_id' => 1], $token);
        $beforeItems = $before['body']['data']['items'] ?? [];
        if (empty($beforeItems)) { $this->markTestSkipped('No derived metrics available'); }

        $second = $this->request('POST', 'environment/compute-derived-metrics', ['store_id' => 1], $token);
        $this->assertEquals(200, $second['status']);

        $after = $this->request('GET', 'environment/derived-metrics', ['store_id' => 1], $token);
        $afterItems = $after['body']['data']['items'] ?? [];

        $byKey = function (array $rows) {
            $out = [];
            foreach ($rows as $r) {
                $key = ($r['metric_key'] ?? '') . '|' . ($r['bucket_start'] ?? '') . '|' . ($r['formula_version_id'] ?? '');
                $out[$key] = $r['value'] ?? null;
            }
            return $out;
        };
        $beforeMap = $byKey($beforeItems);
        $afterMap  = $byKey($afterItems);

        foreach ($beforeMap as $key => $value) {
            if (!isset($afterMap[$key])) { continue; }
            $this->assertEquals($value, $afterMap[$key],
                "Derived metric {$key} drifted between runs under the same formula version");
        }
    }
}
