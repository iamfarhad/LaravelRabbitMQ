<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit;

use AMQPChannel;
use iamfarhad\LaravelRabbitMQ\Connection\PoolManager;
use iamfarhad\LaravelRabbitMQ\Tests\Doubles\TestableRabbitQueue;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Mockery;

/**
 * Topology declaration, binding and memoisation.
 */
class RabbitQueueTopologyTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    private function bindConfig(array $connection = []): void
    {
        $container = new Container;
        $container->instance('config', new ConfigRepository([
            'queue' => ['connections' => ['rabbitmq' => $connection]],
        ]));
        Container::setInstance($container);
    }

    /**
     * Build the driver with its ext-amqp construction seams pointed at the
     * supplied doubles.
     */
    private function makeQueue(
        PoolManager $poolManager,
        ?\AMQPQueue $amqpQueue = null,
        ?\AMQPExchange $amqpExchange = null,
        string $defaultQueue = 'default'
    ): TestableRabbitQueue {
        $queue = TestableRabbitQueue::make($poolManager, $defaultQueue);

        if ($amqpQueue !== null) {
            $queue->useQueueFactory(fn (): \AMQPQueue => $amqpQueue);
        }

        if ($amqpExchange !== null) {
            $queue->useExchangeFactory(fn (): \AMQPExchange => $amqpExchange);
        }

        return $queue;
    }

    private function poolManagerWithChannel(): PoolManager
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('isConnected')->andReturn(true);
        $channel->shouldReceive('getConnection')->andReturn(
            Mockery::mock(\AMQPConnection::class)->shouldReceive('isConnected')->andReturn(true)->getMock()
        );

        $poolManager = Mockery::mock(PoolManager::class);
        $poolManager->shouldReceive('getChannel')->andReturn($channel);
        $poolManager->shouldReceive('markChannelDirty')->andReturnNull();

        return $poolManager;
    }

    /**
     * Publishing through a configured exchange used to declare the exchange
     * *instead of* the queue and never bind the two, so the broker silently
     * discarded every message — publisher confirms ACK an unroutable message.
     */
    public function testPublishingThroughAConfiguredExchangeDeclaresAndBindsTheQueue(): void
    {
        $this->bindConfig(['exchange' => 'jobs', 'exchange_type' => 'direct']);

        $declaredQueues = [];
        $bindings = [];

        $amqpQueue = Mockery::mock(\AMQPQueue::class);
        $amqpQueue->shouldReceive('setName')->andReturnUsing(function (string $name) use (&$declaredQueues): void {
            $declaredQueues[] = $name;
        });
        $amqpQueue->shouldReceive('setFlags');
        $amqpQueue->shouldReceive('getFlags')->andReturn(2);
        $amqpQueue->shouldReceive('setArguments');
        $amqpQueue->shouldReceive('declareQueue')->andReturn(0);
        $amqpQueue->shouldReceive('bind')->andReturnUsing(
            function (string $exchange, string $routingKey) use (&$bindings): void {
                $bindings[] = $exchange.'|'.$routingKey;
            }
        );

        $amqpExchange = Mockery::mock(\AMQPExchange::class);
        $amqpExchange->shouldReceive('setName');
        $amqpExchange->shouldReceive('setType');
        $amqpExchange->shouldReceive('setFlags');
        $amqpExchange->shouldReceive('declareExchange');
        $amqpExchange->shouldReceive('publish');

        $queue = $this->makeQueue($this->poolManagerWithChannel(), $amqpQueue, $amqpExchange);
        $queue->pushRaw('{"id":"a"}', 'orders');

        $this->assertContains('orders', $declaredQueues, 'The queue consumers read from must exist.');
        $this->assertSame(['jobs|orders'], $bindings, 'The queue must be bound to the configured exchange.');
    }

    /**
     * The default exchange routes on the literal queue name, so the configured
     * routing-key pattern must not be applied there — it would produce a key
     * that matches nothing and the message would vanish.
     */
    public function testDefaultExchangePublishesOnTheLiteralQueueName(): void
    {
        $this->bindConfig(['exchange' => '', 'exchange_routing_key' => 'jobs.%s']);

        $routingKeys = [];

        $amqpQueue = Mockery::mock(\AMQPQueue::class);
        $amqpQueue->shouldReceive('setName');
        $amqpQueue->shouldReceive('setFlags');
        $amqpQueue->shouldReceive('getFlags')->andReturn(2);
        $amqpQueue->shouldReceive('setArguments');
        $amqpQueue->shouldReceive('declareQueue')->andReturn(0);

        $amqpExchange = Mockery::mock(\AMQPExchange::class);
        $amqpExchange->shouldReceive('setName');
        $amqpExchange->shouldReceive('publish')->andReturnUsing(
            function (string $payload, string $routingKey) use (&$routingKeys): void {
                $routingKeys[] = $routingKey;
            }
        );

        $queue = $this->makeQueue($this->poolManagerWithChannel(), $amqpQueue, $amqpExchange);
        $queue->pushRaw('{"id":"a"}', 'orders');

        $this->assertSame(['orders'], $routingKeys);
    }

    /**
     * pop() used to passively probe the queue before every basic.get, costing
     * two broker round trips per poll forever. Topology is now memoised per
     * channel, so only the first poll declares.
     */
    public function testRepeatedPollsDeclareTheQueueOnlyOnce(): void
    {
        $this->bindConfig([]);

        $declareCalls = 0;

        $amqpQueue = Mockery::mock(\AMQPQueue::class);
        $amqpQueue->shouldReceive('setName');
        $amqpQueue->shouldReceive('setFlags');
        $amqpQueue->shouldReceive('getFlags')->andReturn(2);
        $amqpQueue->shouldReceive('setArguments');
        $amqpQueue->shouldReceive('declareQueue')->andReturnUsing(function () use (&$declareCalls): int {
            $declareCalls++;

            return 0;
        });
        $amqpQueue->shouldReceive('get')->andReturn(null);

        $queue = $this->makeQueue($this->poolManagerWithChannel(), $amqpQueue, null, 'orders');

        $this->assertNull($queue->pop());
        $this->assertNull($queue->pop());
        $this->assertNull($queue->pop());

        $this->assertSame(1, $declareCalls, 'Declared topology must be remembered for the channel.');
    }

    /**
     * Delay queues are named after their TTL, so arbitrary or jittered backoff
     * values would create an unbounded number of broker-side queues. Rounding up
     * to the configured bucket collapses them and never fires a job early.
     */
    public function testDelayedPublishesShareABucketedDelayQueue(): void
    {
        $this->bindConfig(['delay_queue_granularity' => 1000]);

        $declaredNames = [];

        $amqpQueue = Mockery::mock(\AMQPQueue::class);
        $amqpQueue->shouldReceive('setName')->andReturnUsing(function (string $name) use (&$declaredNames): void {
            $declaredNames[] = $name;
        });
        $amqpQueue->shouldReceive('setFlags');
        $amqpQueue->shouldReceive('getFlags')->andReturn(2);
        $amqpQueue->shouldReceive('setArguments');
        $amqpQueue->shouldReceive('declareQueue')->andReturn(0);

        $amqpExchange = Mockery::mock(\AMQPExchange::class);
        $amqpExchange->shouldReceive('setName');
        $amqpExchange->shouldReceive('publish');

        $queue = $this->makeQueue($this->poolManagerWithChannel(), $amqpQueue, $amqpExchange);

        // 4s and 5s: distinct delays that fall in different one-second buckets.
        $queue->laterRaw(4, '{"id":"a"}', 'orders');
        $queue->laterRaw(5, '{"id":"b"}', 'orders');
        // Same bucket as the 4s publish, so it must reuse that delay queue.
        $queue->laterRaw(4, '{"id":"c"}', 'orders');

        $delayQueues = array_values(array_unique(array_filter(
            $declaredNames,
            static fn (string $name): bool => str_contains($name, '.delay.')
        )));

        sort($delayQueues);

        $this->assertSame(['orders.delay.4000', 'orders.delay.5000'], $delayQueues);
    }

    /**
     * bulk() enables confirm mode once and waits once for the whole batch,
     * instead of paying a broker round trip per message.
     */
    public function testBulkConfirmsTheWholeBatchWithASingleWait(): void
    {
        $this->bindConfig(['publisher_confirms' => ['enabled' => true, 'timeout' => 5]]);

        $confirmSelects = 0;
        $waits = 0;
        $publishes = 0;
        $ackCallback = null;

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('isConnected')->andReturn(true);
        $channel->shouldReceive('getConnection')->andReturn(
            Mockery::mock(\AMQPConnection::class)->shouldReceive('isConnected')->andReturn(true)->getMock()
        );
        $channel->shouldReceive('setConfirmCallback')->andReturnUsing(
            function (callable $ack) use (&$ackCallback): void {
                $ackCallback = $ack;
            }
        );
        $channel->shouldReceive('setReturnCallback');
        $channel->shouldReceive('confirmSelect')->andReturnUsing(function () use (&$confirmSelects): void {
            $confirmSelects++;
        });
        $channel->shouldReceive('waitForConfirm')->andReturnUsing(function () use (&$waits, &$ackCallback): void {
            $waits++;
            // The broker confirms the batch cumulatively.
            ($ackCallback)(3, true);
        });

        $poolManager = Mockery::mock(PoolManager::class);
        $poolManager->shouldReceive('getChannel')->andReturn($channel);
        $poolManager->shouldReceive('markChannelDirty')->andReturnNull();

        $amqpQueue = Mockery::mock(\AMQPQueue::class);
        $amqpQueue->shouldReceive('setName');
        $amqpQueue->shouldReceive('setFlags');
        $amqpQueue->shouldReceive('getFlags')->andReturn(2);
        $amqpQueue->shouldReceive('setArguments');
        $amqpQueue->shouldReceive('declareQueue')->andReturn(0);

        $amqpExchange = Mockery::mock(\AMQPExchange::class);
        $amqpExchange->shouldReceive('setName');
        $amqpExchange->shouldReceive('publish')->andReturnUsing(function () use (&$publishes): void {
            $publishes++;
        });

        $queue = $this->makeQueue($poolManager, $amqpQueue, $amqpExchange, 'orders');
        // push() consults the container for the after-commit decision.
        $queue->setContainer(Container::getInstance());
        $queue->bulk(['{"id":"a"}', '{"id":"b"}', '{"id":"c"}']);

        $this->assertSame(3, $publishes);
        $this->assertSame(1, $confirmSelects, 'Confirm mode is enabled once per channel.');
        $this->assertSame(1, $waits, 'The batch is confirmed with one wait, not one per message.');
    }

    /**
     * Under `after_commit` every publish is deferred past bulk(), leaving nothing
     * outstanding. Waiting anyway would block for the full confirm timeout.
     */
    public function testBulkDoesNotWaitWhenNothingWasPublished(): void
    {
        $this->bindConfig(['publisher_confirms' => ['enabled' => true, 'timeout' => 5]]);

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('isConnected')->andReturn(true);
        $channel->shouldNotReceive('waitForConfirm');

        $poolManager = Mockery::mock(PoolManager::class);
        $poolManager->shouldReceive('getChannel')->andReturn($channel);
        $poolManager->shouldReceive('markChannelDirty')->andReturnNull();

        // No doubles needed: nothing is published, which is the point.
        $queue = $this->makeQueue($poolManager, null, null, 'orders');
        $queue->setContainer(Container::getInstance());
        $queue->bulk([]);

        $this->assertTrue(true);
    }

    public function testDelayGranularityRoundsSubSecondDelaysIntoOneQueue(): void
    {
        $this->bindConfig(['delay_queue_granularity' => 1000]);

        $declaredNames = [];

        $amqpQueue = Mockery::mock(\AMQPQueue::class);
        $amqpQueue->shouldReceive('setName')->andReturnUsing(function (string $name) use (&$declaredNames): void {
            $declaredNames[] = $name;
        });
        $amqpQueue->shouldReceive('setFlags');
        $amqpQueue->shouldReceive('getFlags')->andReturn(2);
        $amqpQueue->shouldReceive('setArguments');
        $amqpQueue->shouldReceive('declareQueue')->andReturn(0);

        $amqpExchange = Mockery::mock(\AMQPExchange::class);
        $amqpExchange->shouldReceive('setName');
        $amqpExchange->shouldReceive('publish');

        $queue = $this->makeQueue($this->poolManagerWithChannel(), $amqpQueue, $amqpExchange);

        foreach ([1, 1, 1] as $delay) {
            $queue->laterRaw($delay, '{"id":"a"}', 'orders');
        }

        $delayQueues = array_values(array_unique(array_filter(
            $declaredNames,
            static fn (string $name): bool => str_contains($name, '.delay.')
        )));

        $this->assertSame(['orders.delay.1000'], $delayQueues);
    }
}
