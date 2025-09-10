<?php

declare(strict_types=1);

use iamfarhad\LaravelRabbitMQ\Support\MessageHelpers;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;

// Test only the message helpers and other non-connection dependent functionality
// Connection-dependent tests are covered in Feature tests
class RabbitQueueTest extends UnitTestCase
{
    public function testCorrelationIdGeneration(): void
    {
        $correlationId = MessageHelpers::generateCorrelationId();

        $this->assertIsString($correlationId);
        $this->assertNotEmpty($correlationId);

        // Test that multiple calls generate different IDs
        $correlationId2 = MessageHelpers::generateCorrelationId();
        $this->assertNotEquals($correlationId, $correlationId2);
    }

    public function testExtractsCorrelationIdFromValidPayload(): void
    {
        $payload = json_encode(['id' => 'test-123', 'job' => 'TestJob']);
        $extractedId = MessageHelpers::extractCorrelationId($payload);

        $this->assertEquals('test-123', $extractedId);
    }

    public function testReturnsNullForPayloadWithoutId(): void
    {
        $payload = json_encode(['job' => 'TestJob', 'data' => []]);
        $extractedId = MessageHelpers::extractCorrelationId($payload);

        $this->assertNull($extractedId);
    }

    public function testHandlesInvalidJsonGracefullyInHelper(): void
    {
        $invalidPayload = 'invalid-json';
        $extractedId = MessageHelpers::extractCorrelationId($invalidPayload);

        $this->assertNull($extractedId);
    }

    public function testValidatesJsonCorrectly(): void
    {
        $validJson = json_encode(['test' => 'data']);
        $this->assertTrue(MessageHelpers::isValidJson($validJson));

        $invalidJson = 'not-json';
        $this->assertFalse(MessageHelpers::isValidJson($invalidJson));
    }

    public function testExtractsCorrelationIdFromComplexPayload(): void
    {
        $complexPayload = json_encode([
            'id' => 'complex-id-456',
            'job' => 'App\\Jobs\\ComplexJob',
            'data' => [
                'nested' => ['array' => 'value'],
                'number' => 123,
                'boolean' => true
            ],
            'attempts' => 1,
            'timeout' => null
        ]);

        $extractedId = MessageHelpers::extractCorrelationId($complexPayload);
        $this->assertEquals('complex-id-456', $extractedId);
    }

    public function testHandlesEmptyStringPayload(): void
    {
        $extractedId = MessageHelpers::extractCorrelationId('');
        $this->assertNull($extractedId);
    }

    public function testHandlesNullPayload(): void
    {
        $extractedId = MessageHelpers::extractCorrelationId(null);
        $this->assertNull($extractedId);
    }

    public function testValidatesEmptyJson(): void
    {
        $emptyJson = '{}';
        $this->assertTrue(MessageHelpers::isValidJson($emptyJson));

        $emptyArray = '[]';
        $this->assertTrue(MessageHelpers::isValidJson($emptyArray));
    }

    public function testValidatesJsonWithSpecialCharacters(): void
    {
        $jsonWithSpecialChars = json_encode(['message' => 'Hello "World" with \n newlines']);
        $this->assertTrue(MessageHelpers::isValidJson($jsonWithSpecialChars));
    }
}
