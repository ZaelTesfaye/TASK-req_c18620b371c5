<?php
namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * AnnouncementApiTest - API tests for announcement endpoints.
 * Routes: announcements (GET/POST), announcements/:id (GET/PATCH/DELETE)
 */
class AnnouncementApiTest extends TestCase
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

    public function testListAnnouncementsRequiresAuth(): void
    {
        $response = $this->request('GET', 'announcements');
        $this->assertEquals(401, $response['status']);
    }

    public function testCreateAnnouncementRequiresAuth(): void
    {
        $response = $this->request('POST', 'announcements');
        $this->assertEquals(401, $response['status']);
    }

    // RBAC - list (front_desk, store_manager, administrator)

    public function testAdminCanListAnnouncements(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $response = $this->request('GET', 'announcements', [], $token);
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
    }

    public function testFrontDeskCanListAnnouncements(): void
    {
        $token = $this->loginAs('frontdesk1');
        if (!$token) { $this->markTestSkipped('frontdesk1 not available'); }

        $response = $this->request('GET', 'announcements', [], $token);
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
    }

    public function testCustomerCannotListAnnouncements(): void
    {
        $token = $this->loginAs('customer1');
        if (!$token) { $this->markTestSkipped('customer1 not available'); }

        $response = $this->request('GET', 'announcements', [], $token);
        $this->assertEquals(403, $response['status']);
        $this->assertFalse($response['body']['success']);
        $this->assertEquals('FORBIDDEN', $response['body']['error_code']);
    }

    // RBAC - create (store_manager, administrator)

    public function testAdminCanCreateAnnouncement(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $title = 'Test Announcement ' . time();
        $response = $this->request('POST', 'announcements', [
            'title' => $title,
            'body' => 'This is a test announcement for automated testing.',
        ], $token);
        $this->assertEquals(201, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertArrayHasKey('id', $response['body']['data']);
    }

    public function testFrontDeskCannotCreateAnnouncement(): void
    {
        $token = $this->loginAs('frontdesk1');
        if (!$token) { $this->markTestSkipped('frontdesk1 not available'); }

        $response = $this->request('POST', 'announcements', [
            'title' => 'Unauthorized',
            'body' => 'Should fail',
        ], $token);
        $this->assertEquals(403, $response['status']);
        $this->assertFalse($response['body']['success']);
        $this->assertEquals('FORBIDDEN', $response['body']['error_code']);
    }

    // Show

    public function testGetAnnouncementNotFound(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $response = $this->request('GET', 'announcements/99999', [], $token);
        $this->assertEquals(404, $response['status']);
        $this->assertFalse($response['body']['success']);
    }

    // Delete (administrator only)

    public function testDeleteAnnouncementRequiresAdmin(): void
    {
        $token = $this->loginAs('manager1');
        if (!$token) { $this->markTestSkipped('manager1 not available'); }

        $response = $this->request('DELETE', 'announcements/1', [], $token);
        $this->assertEquals(403, $response['status']);
        $this->assertFalse($response['body']['success']);
        $this->assertEquals('FORBIDDEN', $response['body']['error_code']);
    }

    // Full lifecycle

    // PATCH /announcements/:id

    public function testPatchAnnouncementUpdatesFieldAndPersists(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $createResp = $this->request('POST', 'announcements', [
            'title' => 'Original title ' . time(),
            'body' => 'Original body',
        ], $token);
        $id = $createResp['body']['data']['id'] ?? null;
        $this->assertNotNull($id);

        $patchResp = $this->request('PATCH', "announcements/{$id}", [
            'title' => 'Updated title',
        ], $token);
        $this->assertEquals(200, $patchResp['status']);
        $this->assertTrue($patchResp['body']['success'] ?? false);
        $this->assertEquals('Updated title', $patchResp['body']['data']['title']);
        // Unpatched fields must remain untouched
        $this->assertEquals('Original body', $patchResp['body']['data']['body']);

        // Verify persistence via GET
        $readResp = $this->request('GET', "announcements/{$id}", [], $token);
        $this->assertEquals(200, $readResp['status']);
        $this->assertEquals('Updated title', $readResp['body']['data']['title']);
    }

    public function testPatchAnnouncementReturnsNotFoundForUnknownId(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        $patchResp = $this->request('PATCH', 'announcements/99999', [
            'title' => 'Does not matter',
        ], $token);
        $this->assertEquals(404, $patchResp['status']);
        $this->assertEquals('NOT_FOUND', $patchResp['body']['error_code']);
    }

    public function testCreateAndReadAnnouncement(): void
    {
        $token = $this->loginAs('admin');
        $this->assertNotNull($token);

        // Create
        $createResp = $this->request('POST', 'announcements', [
            'title' => 'Lifecycle Test ' . time(),
            'body' => 'Testing full create-read flow.',
        ], $token);
        $this->assertEquals(201, $createResp['status']);
        $id = $createResp['body']['data']['id'] ?? null;
        $this->assertNotNull($id);

        // Read
        $readResp = $this->request('GET', "announcements/{$id}", [], $token);
        $this->assertEquals(200, $readResp['status']);
        $this->assertTrue($readResp['body']['success'] ?? false);
    }
}
