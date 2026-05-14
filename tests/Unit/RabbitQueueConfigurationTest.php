<?php

declare(strict_types=1);

use iamfarhad\LaravelRabbitMQ\Connection\PoolManager;
use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use iamfarhad\LaravelRabbitMQ\Tests\TestCase;
use Mockery;

class RabbitQueueConfigurationTest extends TestCase
{
    public function testBuildsQueueArgumentsForLazyPriorityAndFailedReroute(): void
    {
        config([
            'queue.connections.rabbitmq.prioritize_delayed' => true,
            'queue.connections.rabbitmq.queue_max_priority' => 8,
            'queue.connections.rabbitmq.reroute_failed' => true,
            'queue.connections.rabbitmq.failed_exchange' => 'failed.jobs',
            'queue.connections.rabbitmq.failed_routing_key' => '%s.failed',
            'queue.connections.rabbitmq.queues.default.lazy' => true,
        ]);

        $queue = $this->makeQueue();
        $arguments = $this->invokePrivate($queue, 'getQueueArguments', ['default']);

        $this->assertSame('lazy', $arguments['x-queue-mode']);
        $this->assertSame(8, $arguments['x-max-priority']);
        $this->assertSame('failed.jobs', $arguments['x-dead-letter-exchange']);
        $this->assertSame('default.failed', $arguments['x-dead-letter-routing-key']);
    }

    public function testBuildsQuorumQueueArgumentsWithoutPriority(): void
    {
        config([
            'queue.connections.rabbitmq.prioritize_delayed' => true,
            'queue.connections.rabbitmq.queue_max_priority' => 8,
            'queue.connections.rabbitmq.quorum' => true,
        ]);

        $queue = $this->makeQueue();
        $arguments = $this->invokePrivate($queue, 'getQueueArguments', ['default']);

        $this->assertSame('quorum', $arguments['x-queue-type']);
        $this->assertArrayNotHasKey('x-max-priority', $arguments);
    }

    public function testResolvesExchangeTypeRoutingKeyAndFailedRoutingKey(): void
    {
        config([
            'queue.connections.rabbitmq.exchange' => 'jobs',
            'queue.connections.rabbitmq.exchange_type' => 'topic',
            'queue.connections.rabbitmq.exchange_routing_key' => 'jobs.%s',
            'queue.connections.rabbitmq.failed_routing_key' => 'failed.%s',
        ]);

        $queue = $this->makeQueue();

        $this->assertSame('jobs', $this->invokePrivate($queue, 'getExchange'));
        $this->assertSame(AMQP_EX_TYPE_TOPIC, $this->invokePrivate($queue, 'getExchangeType'));
        $this->assertSame('jobs.default', $this->invokePrivate($queue, 'getRoutingKey', ['default']));
        $this->assertSame('failed.default', $this->invokePrivate($queue, 'getFailedRoutingKey', ['default']));
    }

    private function makeQueue(): RabbitQueue
    {
        return new RabbitQueue(Mockery::mock(PoolManager::class), 'default');
    }

    private function invokePrivate(RabbitQueue $queue, string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionClass($queue);
        $method = $reflection->getMethod($method);
        $method->setAccessible(true);

        return $method->invokeArgs($queue, $arguments);
    }
}
