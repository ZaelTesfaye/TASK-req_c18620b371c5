<?php
namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * CleansingApiTest - API tests for data cleansing endpoints.
 * Routes: cleansing/import (POST), cleansing/batches (GET),
 * cleansing/batches/:id/preview (GET), cleansing/batches/:id/approve (POST),
 * cleansing/batches/:id/rollback (POST), cleansing/manual-review-queue (GET)
 */
class CleansingApiTest extends TestCase
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

        $response = $this->request('POST', 'auth/login', [
            'username' => $username,
            'password' => 'Demo12345678!',
            'store_id' => $storeId,
            'workstation_id' => $wsId,
        ]);
        return $response['body']['data']['token'] ?? null;
    }

    // Auth guard

    public function testBatchImportRequiresAuth(): void
    {
        $response = $this->request('POST', 'cleansing/import');
        $this->assertEquals(401, $response['status']);
    }

    public function testListBatchesRequiresAuth(): void
    {
        $response = $this->request('GET', 'cleansing/batches');
        $this->assertEquals(401, $response['status']);
    }

    public function testManualReviewQueueRequiresAuth(): void
    {
        $response = $this->request('GET', 'cleansing/manual-review-queue');
        $this->assertEquals(401, $response['status']);
    }

    // RBAC

    public function testAdminCanImportBatch(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $response = $this->request('POST', 'cleansing/import', [
            'source_name' => 'test_source_' . time(),
            'rows' => [
                ['job_title' => 'Sr. Dev', 'company' => 'Acme Inc', 'city' => 'NYC', 'salary' => '$75k', 'education' => 'BS', 'experience' => '5 years'],
            ],
        ], $token);
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success'] ?? false);
        $this->assertArrayHasKey('batch_id', $response['body']['data'] ?? []);
    }

    public function testAdminCanListBatches(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $response = $this->request('GET', 'cleansing/batches', [], $token);
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success'] ?? false);
    }

    public function testAdminCanViewManualReviewQueue(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $response = $this->request('GET', 'cleansing/manual-review-queue', [], $token);
        $this->assertEquals(200, $response['status']);
    }

    // Approve/rollback

    public function testApproveNonExistentBatchReturns404(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $response = $this->request('POST', 'cleansing/batches/99999/approve', [], $token);
        $this->assertEquals(404, $response['status']);
    }

    public function testRollbackNonExistentBatchReturns404(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $response = $this->request('POST', 'cleansing/batches/99999/rollback', [], $token);
        $this->assertEquals(404, $response['status']);
    }

    public function testManagerCannotApproveBatch(): void
    {
        $token = $this->loginAs('manager1');
        if (!$token) { $this->markTestSkipped('manager1 not available'); }

        $response = $this->request('POST', 'cleansing/batches/1/approve', [], $token);
        $this->assertEquals(403, $response['status']);
    }

    // Import-approve lifecycle

    public function testImportAndApproveBatchLifecycle(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        // Import
        $importResp = $this->request('POST', 'cleansing/import', [
            'source_name' => 'lifecycle_test_' . time(),
            'rows' => [
                ['job_title' => 'Engineer', 'company' => 'Google', 'city' => 'SF', 'salary' => '150000', 'education' => 'MS', 'experience' => '5 years'],
            ],
        ], $token);
        $this->assertEquals(200, $importResp['status']);
        $batchId = $importResp['body']['data']['batch_id'] ?? null;
        $this->assertNotNull($batchId);

        // Preview
        $previewResp = $this->request('GET', "cleansing/batches/{$batchId}/preview", [], $token);
        $this->assertEquals(200, $previewResp['status']);

        // Approve
        $approveResp = $this->request('POST', "cleansing/batches/{$batchId}/approve", [], $token);
        $this->assertEquals(200, $approveResp['status']);
        $this->assertTrue($approveResp['body']['success'] ?? false);
        $this->assertEquals('approved', $approveResp['body']['data']['status'] ?? '');
    }

    // ---- Rollback invariants ----
    //
    // After rolling back a batch we assert:
    //   1. the batch ends in status `rolled_back`
    //   2. every cleansing_results row for the batch is marked `rejected`
    //   3. the change journal is preserved (rollback reads from it, never wipes it)
    // If any of these drift we want the suite to fail, because a silent
    // partial rollback leaves the canonical dataset in an inconsistent state.

    public function testRollbackRestoresJournalInvariants(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        // Capture the "pre" snapshot of the dashboards we expect to remain
        // stable through approve→rollback. The rollback must not leave
        // dashboard metrics reflecting the approved batch — any metric that
        // drifted up under approve should come back down under rollback.
        $opsBefore = $this->request('GET', 'dashboards/operations', [
            'from' => '01/01/2025', 'to' => '12/31/2025',
        ], $token);
        $this->assertEquals(200, $opsBefore['status']);
        $totalOrdersBefore = $opsBefore['body']['data']['total_orders'] ?? 0;

        // Import a small batch so we have journal entries to restore from.
        $importResp = $this->request('POST', 'cleansing/import', [
            'source_name' => 'rollback_invariant_' . time(),
            'rows' => [
                ['job_title' => 'Engineer', 'company' => 'Google', 'city' => 'SF', 'salary' => '150000', 'education' => 'MS', 'experience' => '5 years'],
                ['job_title' => 'Manager', 'company' => 'Apple', 'city' => 'Cupertino', 'salary' => '200000', 'education' => 'MBA', 'experience' => '10 years'],
            ],
        ], $token);
        $this->assertEquals(200, $importResp['status']);
        $batchId = $importResp['body']['data']['batch_id'] ?? null;
        $this->assertNotNull($batchId);

        // Approve before rollback to exercise the approved→rolled_back path
        $approveResp = $this->request('POST', "cleansing/batches/{$batchId}/approve", [], $token);
        $this->assertEquals(200, $approveResp['status']);

        // Rollback
        $rbResp = $this->request('POST', "cleansing/batches/{$batchId}/rollback", [], $token);
        $this->assertEquals(200, $rbResp['status']);
        $this->assertTrue($rbResp['body']['success'] ?? false);
        $this->assertEquals('rolled_back', $rbResp['body']['data']['status'] ?? '');

        // 1. Batch status in the authoritative listing must now be `rolled_back`.
        $listResp = $this->request('GET', 'cleansing/batches', ['status' => 'rolled_back'], $token);
        $this->assertEquals(200, $listResp['status']);
        $found = null;
        foreach ($listResp['body']['data']['items'] ?? [] as $row) {
            if (($row['id'] ?? null) === $batchId) { $found = $row; break; }
        }
        $this->assertNotNull($found, 'Rolled-back batch must show up in the status=rolled_back listing');
        $this->assertEquals('rolled_back', $found['status']);

        // 2. All cleansing_results rows for the batch must be back to
        //    `rejected`, not `approved`. Anything else means the journal
        //    restore missed rows and the dataset is in a torn state.
        $previewResp = $this->request('GET', "cleansing/batches/{$batchId}/preview", [], $token);
        $this->assertEquals(200, $previewResp['status']);
        $results = $previewResp['body']['data']['results'] ?? [];
        $this->assertNotEmpty($results, 'Preview must still return journal rows after rollback (journal is preserved)');
        // We imported exactly 2 rows; the rollback must preserve both.
        $this->assertCount(2, $results,
            'Rollback must preserve every cleansing_results row — the change journal is read-only through rollback');
        foreach ($results as $row) {
            $this->assertEquals('rejected', $row['status'],
                "cleansing_results row {$row['id']} should be rejected post-rollback, got {$row['status']}");
            // The normalized payload and raw_row_id link are what the
            // change journal is responsible for keeping intact. If either
            // is wiped on rollback, a subsequent re-import would orphan
            // or silently overwrite history — assert both explicitly.
            $this->assertArrayHasKey('raw_row_id', $row);
            $this->assertNotNull($row['raw_row_id'],
                'raw_row_id link from journal must be preserved through rollback');
            $this->assertArrayHasKey('normalized_job_title', $row,
                'Normalized fields must still be present post-rollback (journal content is preserved, not wiped)');
            $this->assertNotEmpty(
                $row['normalized_job_title'] ?? $row['normalized_company'] ?? '',
                'At least one normalized field must remain populated — journal content should not be blanked on rollback'
            );
        }

        // 3. Dashboard metrics that were not contracted to be affected by
        //    a cleansing rollback must still return, and the pre-approve
        //    baseline for total_orders must not have drifted downward —
        //    i.e., rollback didn't corrupt unrelated operational counts.
        $opsAfter = $this->request('GET', 'dashboards/operations', [
            'from' => '01/01/2025', 'to' => '12/31/2025',
        ], $token);
        $this->assertEquals(200, $opsAfter['status']);
        $totalOrdersAfter = $opsAfter['body']['data']['total_orders'] ?? 0;
        $this->assertGreaterThanOrEqual($totalOrdersBefore, $totalOrdersAfter,
            'total_orders must not regress across a cleansing rollback (unrelated dataset)');

        // The analytics dashboard must also continue to answer cleanly.
        $analyticsAfter = $this->request('GET', 'dashboards/analytics', [
            'from' => '01/01/2025', 'to' => '12/31/2025',
        ], $token);
        $this->assertEquals(200, $analyticsAfter['status']);
        $this->assertTrue($analyticsAfter['body']['success'] ?? false);
    }

    public function testRollbackFromTerminalStateIsRejected(): void
    {
        // Re-rolling-back an already rolled_back batch must 409 rather than
        // silently succeeding and further corrupting results.
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $importResp = $this->request('POST', 'cleansing/import', [
            'source_name' => 'double_rollback_' . time(),
            'rows' => [
                ['job_title' => 'Engineer', 'company' => 'X', 'city' => 'Y', 'salary' => '100000', 'education' => 'BS', 'experience' => '3 years'],
            ],
        ], $token);
        $batchId = $importResp['body']['data']['batch_id'] ?? null;
        $this->assertNotNull($batchId);

        $this->request('POST', "cleansing/batches/{$batchId}/approve", [], $token);
        $first = $this->request('POST', "cleansing/batches/{$batchId}/rollback", [], $token);
        $this->assertEquals(200, $first['status']);

        $second = $this->request('POST', "cleansing/batches/{$batchId}/rollback", [], $token);
        $this->assertEquals(409, $second['status']);
        $this->assertEquals('CONFLICT', $second['body']['error_code']);
    }
}
