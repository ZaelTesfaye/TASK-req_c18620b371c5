<?php
namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * ExperimentApiTest - API tests for experiment endpoints.
 * Routes: experiments (GET/POST), experiments/:id (GET/PATCH),
 * experiments/:id/start (POST), experiments/:id/stop (POST),
 * experiments/:id/assignments (GET)
 */
class ExperimentApiTest extends TestCase
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

    public function testListExperimentsRequiresAuth(): void
    {
        $response = $this->request('GET', 'experiments');
        $this->assertEquals(401, $response['status']);
    }

    public function testCreateExperimentRequiresAuth(): void
    {
        $response = $this->request('POST', 'experiments');
        $this->assertEquals(401, $response['status']);
    }

    // RBAC

    public function testAdminCanListExperiments(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $response = $this->request('GET', 'experiments', [], $token);
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success'] ?? false);
    }

    public function testNonAdminCannotListExperiments(): void
    {
        $token = $this->loginAs('manager1');
        if (!$token) { $this->markTestSkipped('manager1 not available'); }

        $response = $this->request('GET', 'experiments', [], $token);
        $this->assertEquals(403, $response['status']);
    }

    // CRUD lifecycle

    public function testCreateAndStartAndStopExperiment(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        // Create
        $createResp = $this->request('POST', 'experiments', [
            'key' => 'lifecycle_test_' . time(),
            'name' => 'Lifecycle Test Experiment',
            'holdout_percent' => 10,
            'variants' => [
                ['variant_key' => 'control', 'traffic_percent' => 45],
                ['variant_key' => 'treatment', 'traffic_percent' => 45],
            ],
        ], $token);
        $this->assertEquals(201, $createResp['status']);
        $this->assertTrue($createResp['body']['success'] ?? false);
        $experimentId = $createResp['body']['data']['id'] ?? null;
        $this->assertNotNull($experimentId);

        // Start
        $startResp = $this->request('POST', "experiments/{$experimentId}/start", [], $token);
        $this->assertEquals(200, $startResp['status']);
        $this->assertTrue($startResp['body']['success'] ?? false);

        // Stop
        $stopResp = $this->request('POST', "experiments/{$experimentId}/stop", [], $token);
        $this->assertEquals(200, $stopResp['status']);
        $this->assertTrue($stopResp['body']['success'] ?? false);
    }

    public function testStartNonExistentExperimentReturns404(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $response = $this->request('POST', 'experiments/99999/start', [], $token);
        $this->assertEquals(404, $response['status']);
    }

    public function testStopNonExistentExperimentReturns404(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $response = $this->request('POST', 'experiments/99999/stop', [], $token);
        $this->assertEquals(404, $response['status']);
    }

    public function testCannotStartRunningExperiment(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        // Create and start
        $createResp = $this->request('POST', 'experiments', [
            'key' => 'double_start_' . time(),
            'name' => 'Double Start Test',
            'holdout_percent' => 10,
            'variants' => [
                ['variant_key' => 'control', 'traffic_percent' => 45],
                ['variant_key' => 'treatment', 'traffic_percent' => 45],
            ],
        ], $token);
        $experimentId = $createResp['body']['data']['id'] ?? null;

        if ($experimentId) {
            $this->request('POST', "experiments/{$experimentId}/start", [], $token);
            // Try starting again
            $response = $this->request('POST', "experiments/{$experimentId}/start", [], $token);
            $this->assertEquals(409, $response['status']);
        }
    }

    // Assignments

    public function testGetAssignmentsRequiresAuth(): void
    {
        $response = $this->request('GET', 'experiments/1/assignments');
        $this->assertEquals(401, $response['status']);
    }

    public function testAdminCanViewAssignmentsForCreatedExperiment(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        // Create experiment so we know it exists
        $createResp = $this->request('POST', 'experiments', [
            'key' => 'assign_view_' . time(),
            'name' => 'Assignment View Test',
            'holdout_percent' => 10,
            'variants' => [
                ['variant_key' => 'control', 'traffic_percent' => 45],
                ['variant_key' => 'treatment', 'traffic_percent' => 45],
            ],
        ], $token);
        $experimentId = $createResp['body']['data']['id'] ?? null;
        $this->assertNotNull($experimentId);

        $response = $this->request('GET', "experiments/{$experimentId}/assignments", [], $token);
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success'] ?? false);
        $this->assertArrayHasKey('items', $response['body']['data'] ?? []);
    }

    // ---- Runtime variant assignment (user-facing path) ----
    //
    // The admin listing above covers /assignments. For the F-002 runtime
    // path the frontend calls /experiments/:id/assignment (singular) which
    // returns the caller's own sticky assignment. These tests pin the
    // response contract so the frontend can rely on the field shape.

    public function testRuntimeAssignmentReturnsVariantPayload(): void
    {
        $adminToken = $this->loginAs('admin');
        $this->assertNotNull($adminToken);

        // Create + start an experiment so there is something to assign to.
        $createResp = $this->request('POST', 'experiments', [
            'key' => 'runtime_assign_' . time(),
            'name' => 'Runtime Assignment Test',
            'holdout_percent' => 10,
            'variants' => [
                ['variant_key' => 'control', 'traffic_percent' => 45],
                ['variant_key' => 'treatment', 'traffic_percent' => 45],
            ],
        ], $adminToken);
        $experimentId = $createResp['body']['data']['id'] ?? null;
        $this->assertNotNull($experimentId);

        $startResp = $this->request('POST', "experiments/{$experimentId}/start", [], $adminToken);
        $this->assertEquals(200, $startResp['status']);

        // Any authenticated user — not just admin — can fetch their own
        // sticky assignment. frontdesk1 stands in for a non-admin runtime
        // caller here.
        $userToken = $this->loginAs('frontdesk1');
        if (!$userToken) { $this->markTestSkipped('frontdesk1 not available'); }

        $resp = $this->request('GET', "experiments/{$experimentId}/assignment", [], $userToken);
        $this->assertEquals(200, $resp['status'],
            'Authenticated non-admin caller must be able to fetch their own runtime assignment');
        $payload = $resp['body']['data'] ?? [];

        // Variant name — for a holdout the service returns null, so the
        // contract is: the key exists, the value is either a string (the
        // variant_key) or null when the caller is in the holdout.
        $this->assertArrayHasKey('variant', $payload,
            'Runtime assignment payload must include a `variant` key so the frontend can branch on it');
        if ($payload['variant'] !== null) {
            $this->assertIsString($payload['variant']);
            $this->assertContains($payload['variant'], ['control', 'treatment'],
                'Assigned variant must be one of the registered variant_keys');
        }

        // Holdout flag — must always be present so the frontend can render
        // the default/control experience without ambiguity.
        $this->assertArrayHasKey('is_holdout', $payload,
            'Runtime assignment payload must include is_holdout');
        $this->assertIsBool($payload['is_holdout']);

        // Assignments are sticky + immutable; a second call must return
        // the same variant for the same user. Pin this so the frontend
        // can cache per page-load without risking a flicker.
        $resp2 = $this->request('GET', "experiments/{$experimentId}/assignment", [], $userToken);
        $this->assertEquals(200, $resp2['status']);
        $this->assertEquals(
            $payload['variant'],
            $resp2['body']['data']['variant'] ?? null,
            'Sticky assignment must return the same variant on repeat calls'
        );
        $this->assertEquals(
            $payload['is_holdout'],
            $resp2['body']['data']['is_holdout'] ?? null
        );
    }

    public function testRuntimeAssignmentForStoppedExperimentFallsBackToControl(): void
    {
        // Not-enrolled / no-longer-running cases must degrade gracefully
        // rather than 404 at the frontend — that mirrors the holdout
        // default-experience contract.
        $adminToken = $this->loginAs('admin');
        $this->assertNotNull($adminToken);

        $createResp = $this->request('POST', 'experiments', [
            'key' => 'runtime_stopped_' . time(),
            'name' => 'Stopped Runtime Test',
            'holdout_percent' => 0,
            'variants' => [
                ['variant_key' => 'control', 'traffic_percent' => 50],
                ['variant_key' => 'treatment', 'traffic_percent' => 50],
            ],
        ], $adminToken);
        $experimentId = $createResp['body']['data']['id'] ?? null;
        $this->assertNotNull($experimentId);

        $this->request('POST', "experiments/{$experimentId}/start", [], $adminToken);
        $this->request('POST', "experiments/{$experimentId}/stop", [], $adminToken);

        $userToken = $this->loginAs('frontdesk1');
        if (!$userToken) { $this->markTestSkipped('frontdesk1 not available'); }

        $resp = $this->request('GET', "experiments/{$experimentId}/assignment", [], $userToken);
        $this->assertEquals(200, $resp['status']);
        $this->assertEquals('control', $resp['body']['data']['variant'] ?? null,
            'Stopped experiments must return control so the frontend renders the default experience');
    }

    public function testRuntimeAssignmentRequiresAuth(): void
    {
        $resp = $this->request('GET', 'experiments/1/assignment');
        $this->assertEquals(401, $resp['status']);
    }

    // GET /experiments/:id

    public function testReadExperimentByIdReturnsFullRecord(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $key = 'read_by_id_' . time();
        $createResp = $this->request('POST', 'experiments', [
            'key' => $key,
            'name' => 'Read By ID Test',
            'holdout_percent' => 10,
            'variants' => [
                ['variant_key' => 'control', 'traffic_percent' => 45],
                ['variant_key' => 'treatment', 'traffic_percent' => 45],
            ],
        ], $token);
        $experimentId = $createResp['body']['data']['id'] ?? null;
        $this->assertNotNull($experimentId);

        $response = $this->request('GET', "experiments/{$experimentId}", [], $token);
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success'] ?? false);
        $experiment = $response['body']['data'];
        $this->assertEquals($experimentId, $experiment['id']);
        $this->assertEquals($key, $experiment['key']);
        $this->assertEquals('Read By ID Test', $experiment['name']);
        $this->assertArrayHasKey('status', $experiment);
    }

    public function testReadExperimentByIdReturns404WhenMissing(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $response = $this->request('GET', 'experiments/99999', [], $token);
        $this->assertEquals(404, $response['status']);
        $this->assertFalse($response['body']['success'] ?? true);
        $this->assertEquals('NOT_FOUND', $response['body']['error_code']);
    }

    // PATCH /experiments/:id

    public function testPatchExperimentUpdatesFieldAndPersists(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $createResp = $this->request('POST', 'experiments', [
            'key' => 'patch_target_' . time(),
            'name' => 'Original Name',
            'holdout_percent' => 10,
            'variants' => [
                ['variant_key' => 'control', 'traffic_percent' => 45],
                ['variant_key' => 'treatment', 'traffic_percent' => 45],
            ],
        ], $token);
        $experimentId = $createResp['body']['data']['id'] ?? null;
        $this->assertNotNull($experimentId);

        $patchResp = $this->request('PATCH', "experiments/{$experimentId}", [
            'name' => 'Updated Name',
        ], $token);
        $this->assertEquals(200, $patchResp['status']);
        $this->assertTrue($patchResp['body']['success'] ?? false);

        $readResp = $this->request('GET', "experiments/{$experimentId}", [], $token);
        $this->assertEquals(200, $readResp['status']);
        $this->assertEquals('Updated Name', $readResp['body']['data']['name']);
    }

    public function testPatchExperimentRejectedForNonAdmin(): void
    {
        // Create as admin so the target exists
        $adminToken = $this->loginAs('admin');
        $createResp = $this->request('POST', 'experiments', [
            'key' => 'patch_rbac_' . time(),
            'name' => 'RBAC Test',
            'holdout_percent' => 10,
            'variants' => [
                ['variant_key' => 'control', 'traffic_percent' => 45],
                ['variant_key' => 'treatment', 'traffic_percent' => 45],
            ],
        ], $adminToken);
        $experimentId = $createResp['body']['data']['id'] ?? null;
        $this->assertNotNull($experimentId);

        $managerToken = $this->loginAs('manager1');
        if (!$managerToken) { $this->markTestSkipped('manager1 not available'); }

        $patchResp = $this->request('PATCH', "experiments/{$experimentId}", [
            'name' => 'Hijacked',
        ], $managerToken);
        $this->assertEquals(403, $patchResp['status']);
        $this->assertEquals('FORBIDDEN', $patchResp['body']['error_code']);
    }
}
