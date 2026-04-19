<?php
namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * EventApiTest - API tests for event management endpoints.
 * Routes: events (GET/POST), events/:id (GET/PATCH/DELETE), events/track (POST)
 */
class EventApiTest extends TestCase
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

    // Auth guard

    public function testListEventsRequiresAuth(): void
    {
        $response = $this->request('GET', 'events');
        $this->assertEquals(401, $response['status']);
    }

    public function testCreateEventRequiresAuth(): void
    {
        $response = $this->request('POST', 'events');
        $this->assertEquals(401, $response['status']);
    }

    public function testTrackEventRequiresAuth(): void
    {
        $response = $this->request('POST', 'events/track');
        $this->assertEquals(401, $response['status']);
    }

    // RBAC

    public function testAdminCanListEvents(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $response = $this->request('GET', 'events', [], $token);
        $this->assertEquals(200, $response['status']);
    }

    public function testAdminCanCreateEvent(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $eventName = 'test_event_' . time();
        $response = $this->request('POST', 'events', [
            // EventController::create requires both `event_key` (the stable
            // identifier used by the `track` endpoint) and `name` (the
            // display label). The earlier form of this test sent only
            // `name` and `description`, tripping the controller's empty-
            // event_key validation path and returning a 400.
            'event_key' => $eventName,
            'name' => $eventName,
            'description' => 'Test event for automated testing',
        ], $token);
        // Controller returns 201 for resource creation.
        $this->assertContains($response['status'], [200, 201]);
        $this->assertTrue($response['body']['success']);
        $this->assertArrayHasKey('id', $response['body']['data'] ?? []);

        // Verify event is retrievable
        $eventId = $response['body']['data']['id'];
        $showResp = $this->request('GET', "events/{$eventId}", [], $token);
        $this->assertEquals(200, $showResp['status']);
        $this->assertTrue($showResp['body']['success']);
    }

    public function testManagerCanListEvents(): void
    {
        $token = $this->loginAs('manager1');
        if (!$token) { $this->markTestSkipped('manager1 not available'); }

        $response = $this->request('GET', 'events', [], $token);
        $this->assertEquals(200, $response['status']);
    }

    public function testManagerCannotCreateEvent(): void
    {
        $token = $this->loginAs('manager1');
        if (!$token) { $this->markTestSkipped('manager1 not available'); }

        $response = $this->request('POST', 'events', [
            'name' => 'unauthorized_event',
        ], $token);
        $this->assertEquals(403, $response['status']);
        $this->assertFalse($response['body']['success'] ?? true);
        $this->assertEquals('FORBIDDEN', $response['body']['error_code'] ?? '');
    }

    // Track event (all roles)

    public function testAnyAuthenticatedUserCanTrackEvent(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        // Create a real event definition first so the track endpoint has
        // something to look up — `track` 404s on an unknown event_key.
        $eventKey = 'track_test_' . time();
        $createResp = $this->request('POST', 'events', [
            'event_key'   => $eventKey,
            'name'        => 'Track Test',
            'description' => 'Seed for trackEvent test',
        ], $token);
        $this->assertContains($createResp['status'], [200, 201]);

        $response = $this->request('POST', 'events/track', [
            // EventController::track reads `event_key`, not `event_name`.
            'event_key'  => $eventKey,
            'properties' => ['page' => 'dashboard'],
        ], $token);
        $this->assertContains($response['status'], [200, 201]);
        $this->assertTrue($response['body']['success']);
    }

    // Show/update/delete

    public function testGetEventNotFound(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $response = $this->request('GET', 'events/99999', [], $token);
        $this->assertEquals(404, $response['status']);
        $this->assertFalse($response['body']['success']);
    }

    public function testDeleteEventRequiresAdmin(): void
    {
        $token = $this->loginAs('manager1');
        if (!$token) { $this->markTestSkipped('manager1 not available'); }

        $response = $this->request('DELETE', 'events/1', [], $token);
        $this->assertEquals(403, $response['status']);
        $this->assertFalse($response['body']['success']);
        $this->assertEquals('FORBIDDEN', $response['body']['error_code']);
    }

    // PATCH /events/:id

    private function createEvent(string $token, string $keySuffix = ''): ?int
    {
        $suffix = $keySuffix !== '' ? $keySuffix : (string) time();
        $resp = $this->request('POST', 'events', [
            'event_key' => 'patch_ev_' . $suffix . '_' . rand(1000, 9999),
            'name' => 'PATCH target',
            'description' => 'original description',
            'category' => 'operational',
            'active' => 1,
        ], $token);
        return $resp['body']['data']['id'] ?? null;
    }

    public function testPatchEventUpdatesFields(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $eventId = $this->createEvent($token);
        $this->assertNotNull($eventId, 'Could not create seed event');

        $resp = $this->request('PATCH', "events/{$eventId}", [
            'description' => 'updated description',
            'active' => 0,
        ], $token);
        $this->assertEquals(200, $resp['status']);
        $this->assertTrue($resp['body']['success'] ?? false);
        $this->assertEquals('updated description', $resp['body']['data']['description']);
        $this->assertEquals(0, (int) $resp['body']['data']['active']);

        // Independent read-back confirms the update persisted to the DB.
        $read = $this->request('GET', "events/{$eventId}", [], $token);
        $this->assertEquals(200, $read['status']);
        $this->assertEquals('updated description', $read['body']['data']['description']);
    }

    public function testPatchEventRejectedForNonAdmin(): void
    {
        $admin = $this->loginAs('admin');
        $eventId = $this->createEvent($admin, 'rbac');
        $this->assertNotNull($eventId);

        $manager = $this->loginAs('manager1');
        if (!$manager) { $this->markTestSkipped('manager1 not available'); }

        $resp = $this->request('PATCH', "events/{$eventId}", [
            'description' => 'Hijacked',
        ], $manager);
        $this->assertEquals(403, $resp['status']);
        $this->assertEquals('FORBIDDEN', $resp['body']['error_code']);
    }

    public function testPatchEventMissingReturnsNotFound(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $resp = $this->request('PATCH', 'events/99999', ['description' => 'x'], $token);
        $this->assertEquals(404, $resp['status']);
        $this->assertEquals('NOT_FOUND', $resp['body']['error_code']);
    }
}
