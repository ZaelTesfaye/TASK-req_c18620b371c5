<?php
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\common\ResponseHelper;

/**
 * ResponseHelperTest - Tests standardized API response formatting.
 */
class ResponseHelperTest extends TestCase
{
    public function testSuccessResponseStructure(): void
    {
        $result = ResponseHelper::success(['id' => 1]);
        $this->assertEquals(200, $result['code']);
        $this->assertTrue($result['data']['success']);
        $this->assertEquals(['id' => 1], $result['data']['data']);
        $this->assertNotEmpty($result['data']['request_id']);
    }

    public function testSuccessWithCustomStatusCode(): void
    {
        $result = ResponseHelper::success(null, 201);
        $this->assertEquals(201, $result['code']);
    }

    public function testSuccessWithNullData(): void
    {
        $result = ResponseHelper::success(null);
        $this->assertNull($result['data']['data']);
    }

    public function testPaginatedResponseStructure(): void
    {
        $items = [['id' => 1], ['id' => 2]];
        $result = ResponseHelper::paginated($items, 50, 1, 20);
        $this->assertEquals(200, $result['code']);
        $this->assertTrue($result['data']['success']);
        $this->assertEquals($items, $result['data']['data']['items']);
        $this->assertEquals(50, $result['data']['data']['total']);
        $this->assertEquals(1, $result['data']['data']['page']);
        $this->assertEquals(20, $result['data']['data']['page_size']);
    }

    public function testErrorResponseStructure(): void
    {
        $result = ResponseHelper::error('VALIDATION_ERROR', 'Invalid input', 400);
        $this->assertEquals(400, $result['code']);
        $this->assertFalse($result['data']['success']);
        $this->assertEquals('VALIDATION_ERROR', $result['data']['error_code']);
        $this->assertEquals('Invalid input', $result['data']['message']);
    }

    public function testErrorResponseWithFields(): void
    {
        $fields = ['email' => 'Email is required'];
        $result = ResponseHelper::error('VALIDATION_ERROR', 'Bad request', 400, $fields);
        $this->assertEquals($fields, $result['data']['fields']);
    }

    public function testErrorResponseWithoutFieldsOmitsKey(): void
    {
        $result = ResponseHelper::error('NOT_FOUND', 'Not found', 404);
        $this->assertArrayNotHasKey('fields', $result['data']);
    }

    public function testValidationErrorHelper(): void
    {
        $result = ResponseHelper::validationError('Bad input', ['name' => 'required']);
        $this->assertEquals(400, $result['code']);
        $this->assertEquals('VALIDATION_ERROR', $result['data']['error_code']);
    }

    public function testUnauthorizedHelper(): void
    {
        $result = ResponseHelper::unauthorized();
        $this->assertEquals(401, $result['code']);
        $this->assertEquals('UNAUTHORIZED', $result['data']['error_code']);
    }

    public function testForbiddenHelper(): void
    {
        $result = ResponseHelper::forbidden();
        $this->assertEquals(403, $result['code']);
        $this->assertEquals('FORBIDDEN', $result['data']['error_code']);
    }

    public function testNotFoundHelper(): void
    {
        $result = ResponseHelper::notFound();
        $this->assertEquals(404, $result['code']);
        $this->assertEquals('NOT_FOUND', $result['data']['error_code']);
    }

    public function testConflictHelper(): void
    {
        $result = ResponseHelper::conflict();
        $this->assertEquals(409, $result['code']);
        $this->assertEquals('CONFLICT', $result['data']['error_code']);
    }

    public function testInternalErrorHelper(): void
    {
        $result = ResponseHelper::internalError();
        $this->assertEquals(500, $result['code']);
        $this->assertEquals('INTERNAL_ERROR', $result['data']['error_code']);
    }

    public function testCustomUnauthorizedMessage(): void
    {
        $result = ResponseHelper::unauthorized('Token expired');
        $this->assertEquals('Token expired', $result['data']['message']);
    }

    public function testRequestIdIsConsistentWithinSameRequest(): void
    {
        $result1 = ResponseHelper::success(null);
        $result2 = ResponseHelper::success(null);
        $this->assertEquals($result1['data']['request_id'], $result2['data']['request_id']);
    }
}
