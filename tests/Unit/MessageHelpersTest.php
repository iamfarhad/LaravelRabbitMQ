<?php

declare(strict_types=1);

use iamfarhad\LaravelRabbitMQ\Support\MessageHelpers;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;

class MessageHelpersTest extends UnitTestCase
{
    public function testGeneratesCorrelationId(): void
    {
        $correlationId = MessageHelpers::generateCorrelationId();

        $this->assertIsString($correlationId);
        $this->assertNotEmpty($correlationId);

        // Test that it generates unique IDs
        $correlationId2 = MessageHelpers::generateCorrelationId();
        $this->assertNotEquals($correlationId, $correlationId2);
    }

    public function testExtractsCorrelationIdFromValidPayload(): void
    {
        $id = 'test-id-123';
        $payload = json_encode(['id' => $id, 'job' => 'TestJob']);

        $extractedId = MessageHelpers::extractCorrelationId($payload);

        $this->assertEquals($id, $extractedId);
    }

    public function testReturnsNullForPayloadWithoutId(): void
    {
        $payload = json_encode(['job' => 'TestJob', 'data' => []]);

        $extractedId = MessageHelpers::extractCorrelationId($payload);

        $this->assertNull($extractedId);
    }

    public function testReturnsNullForInvalidJson(): void
    {
        $payload = 'invalid-json';

        $extractedId = MessageHelpers::extractCorrelationId($payload);

        $this->assertNull($extractedId);
    }

    public function testValidatesValidJson(): void
    {
        $payload = json_encode(['job' => 'TestJob']);

        $isValid = MessageHelpers::isValidJson($payload);

        $this->assertTrue($isValid);
    }

    public function testInvalidatesInvalidJson(): void
    {
        $payload = 'invalid-json';

        $isValid = MessageHelpers::isValidJson($payload);

        $this->assertFalse($isValid);
    }

    public function testCorrelationIdFormat(): void
    {
        $correlationId = MessageHelpers::generateCorrelationId();

        // Should be a valid UUID-like format or similar
        $this->assertMatchesRegularExpression('/^[a-f0-9\-]+$/i', $correlationId);
        $this->assertGreaterThan(10, strlen($correlationId)); // Should be reasonably long
    }

    public function testExtractsCorrelationIdWithNestedData(): void
    {
        $id = 'nested-test-456';
        $payload = json_encode([
            'id' => $id,
            'job' => 'TestJob',
            'data' => [
                'nested' => ['deep' => 'value'],
                'array' => [1, 2, 3],
            ],
        ]);

        $extractedId = MessageHelpers::extractCorrelationId($payload);

        $this->assertEquals($id, $extractedId);
    }

    public function testHandlesNullPayloadGracefully(): void
    {
        $extractedId = MessageHelpers::extractCorrelationId(null);
        $this->assertNull($extractedId);
    }

    public function testHandlesEmptyPayloadGracefully(): void
    {
        $extractedId = MessageHelpers::extractCorrelationId('');
        $this->assertNull($extractedId);
    }

    public function testValidatesComplexValidJson(): void
    {
        $complexJson = json_encode([
            'level1' => [
                'level2' => [
                    'level3' => 'deep value',
                ],
            ],
            'array' => [1, 2, 3, ['nested' => true]],
            'null_value' => null,
            'boolean' => false,
            'number' => 42.5,
        ]);

        $this->assertTrue(MessageHelpers::isValidJson($complexJson));
    }

    public function testValidatesEmptyJsonObjects(): void
    {
        $this->assertTrue(MessageHelpers::isValidJson('{}'));
        $this->assertTrue(MessageHelpers::isValidJson('[]'));
        $this->assertTrue(MessageHelpers::isValidJson('null'));
        $this->assertTrue(MessageHelpers::isValidJson('true'));
        $this->assertTrue(MessageHelpers::isValidJson('false'));
        $this->assertTrue(MessageHelpers::isValidJson('"string"'));
        $this->assertTrue(MessageHelpers::isValidJson('123'));
    }
}
