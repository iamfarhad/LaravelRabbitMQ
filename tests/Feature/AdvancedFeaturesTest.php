<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Feature;

use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use iamfarhad\LaravelRabbitMQ\Support\ExchangeManager;
use iamfarhad\LaravelRabbitMQ\Tests\TestCase;

class AdvancedFeaturesTest extends TestCase
{
    private RabbitQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queue = $this->app['queue']->connection('rabbitmq');
    }

    public function testDeclareAdvancedQueue(): void
    {
        $queueName = 'test-advanced-queue';

        $this->queue->declareAdvancedQueue(
            $queueName,
            durable: true,
            autoDelete: false,
            lazy: true,
            priority: 10
        );

        $this->assertTrue($this->queue->queueExists($queueName));

        // Cleanup
        $this->queue->deleteQueue($queueName);
    }

    public function testDeclareQueueWithDeadLetter(): void
    {
        $queueName = 'test-dlx-queue';

        $this->queue->declareAdvancedQueue(
            $queueName,
            durable: true,
            autoDelete: false,
            deadLetterConfig: [
                'exchange' => 'dlx',
                'routing_key' => $queueName,
                'ttl' => 60000,
            ]
        );

        $this->assertTrue($this->queue->queueExists($queueName));

        // Cleanup
        $this->queue->deleteQueue($queueName);
    }

    public function testExchangeManager(): void
    {
        $exchangeManager = $this->queue->getExchangeManager();

        $this->assertInstanceOf(ExchangeManager::class, $exchangeManager);

        // Declare a topic exchange
        $exchange = $exchangeManager->declareExchange(
            'test-topic-exchange',
            ExchangeManager::TYPE_TOPIC,
            true,
            false
        );

        $this->assertNotNull($exchange);

        // Cleanup
        $exchangeManager->deleteExchange('test-topic-exchange');
    }

    public function testPublishToExchange(): void
    {
        $exchangeName = 'test-publish-exchange';
        $queueName = 'test-publish-queue';

        // Setup exchange and queue
        $exchangeManager = $this->queue->getExchangeManager();
        $exchangeManager->declareExchange($exchangeName, ExchangeManager::TYPE_DIRECT, true, false);
        $this->queue->declareQueue($queueName);
        $exchangeManager->bindQueue($queueName, $exchangeName, 'test-key');

        // Publish message
        $payload = json_encode(['test' => 'data']);
        $result = $this->queue->publishToExchange($exchangeName, $payload, 'test-key');

        $this->assertTrue($result);

        // Cleanup
        $exchangeManager->unbindQueue($queueName, $exchangeName, 'test-key');
        $this->queue->deleteQueue($queueName);
        $exchangeManager->deleteExchange($exchangeName);
    }

    public function testLazyQueue(): void
    {
        $queueName = 'test-lazy-queue';

        $this->queue->declareAdvancedQueue(
            $queueName,
            durable: true,
            autoDelete: false,
            lazy: true
        );

        $this->assertTrue($this->queue->queueExists($queueName));

        // Cleanup
        $this->queue->deleteQueue($queueName);
    }

    public function testPriorityQueue(): void
    {
        $queueName = 'test-priority-queue';

        $this->queue->declareAdvancedQueue(
            $queueName,
            durable: true,
            autoDelete: false,
            priority: 10
        );

        $this->assertTrue($this->queue->queueExists($queueName));

        // Cleanup
        $this->queue->deleteQueue($queueName);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }
}
