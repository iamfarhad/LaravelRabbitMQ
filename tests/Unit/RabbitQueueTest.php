<?php

declare(strict_types=1);

use AMQPChannel;
use AMQPConnection;
use iamfarhad\LaravelRabbitMQ\Connection\PoolManager;
use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use iamfarhad\LaravelRabbitMQ\Support\MessageHelpers;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Mockery;

class RabbitQueueTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfAmqpExtensionLoaded();
    }

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
                'boolean' => true,
            ],
            'attempts' => 1,
            'timeout' => null,
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

    public function testRabbitQueueUsesPoolManager(): void
    {
        $mockPoolManager = Mockery::mock(PoolManager::class);
        $mockConnection = Mockery::mock(AMQPConnection::class);
        $mockChannel = Mockery::mock(AMQPChannel::class);

        $mockPoolManager->shouldReceive('getConnection')
            ->once()
            ->andReturn($mockConnection);

        $mockPoolManager->shouldReceive('getChannel')
            ->once()
            ->andReturn($mockChannel);

        $queue = new RabbitQueue($mockPoolManager, 'test-queue');

        $connection = $queue->getConnection();
        $channel = $queue->getChannel();

        $this->assertSame($mockConnection, $connection);
        $this->assertSame($mockChannel, $channel);
    }

    public function testRabbitQueueReleasesChannelOnClose(): void
    {
        $mockPoolManager = Mockery::mock(PoolManager::class);
        $mockChannel = Mockery::mock(AMQPChannel::class);

        $mockPoolManager->shouldReceive('getChannel')
            ->once()
            ->andReturn($mockChannel);

        $mockPoolManager->shouldReceive('releaseChannel')
            ->once()
            ->with($mockChannel);

        $queue = new RabbitQueue($mockPoolManager, 'test-queue');

        // Get a channel to initialize it
        $queue->getChannel();

        // Close should release the channel
        $queue->close();

        $this->assertTrue(true); // Test passes if no exceptions thrown
    }

    public function testRabbitQueueHandlesMultipleChannelRequests(): void
    {
        $mockPoolManager = Mockery::mock(PoolManager::class);
        $mockChannel = Mockery::mock(AMQPChannel::class);

        // Should only call getChannel once due to internal caching
        $mockPoolManager->shouldReceive('getChannel')
            ->once()
            ->andReturn($mockChannel);

        $queue = new RabbitQueue($mockPoolManager, 'test-queue');

        $channel1 = $queue->getChannel();
        $channel2 = $queue->getChannel();

        // Should return the same channel instance
        $this->assertSame($channel1, $channel2);
    }

    public function testRabbitQueueConstructorSetsProperties(): void
    {
        $mockPoolManager = Mockery::mock(PoolManager::class);

        $queue = new RabbitQueue(
            $mockPoolManager,
            'custom-queue',
            ['option1' => 'value1'],
            true,
            'custom-connection'
        );

        // Use reflection to check private properties
        $reflection = new \ReflectionClass($queue);

        $defaultQueueProperty = $reflection->getProperty('defaultQueue');
        $defaultQueueProperty->setAccessible(true);

        $optionsProperty = $reflection->getProperty('options');
        $optionsProperty->setAccessible(true);

        $connectionNameProperty = $reflection->getProperty('connectionName');
        $connectionNameProperty->setAccessible(true);

        $this->assertEquals('custom-queue', $defaultQueueProperty->getValue($queue));
        $this->assertEquals(['option1' => 'value1'], $optionsProperty->getValue($queue));
        $this->assertEquals('custom-connection', $connectionNameProperty->getValue($queue));
    }
}
