<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit;

use AMQPChannel;
use Carbon\Carbon;
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
        Carbon::setTestNow();
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
     * A delay queue carries `x-expires`, so the broker deletes it once it falls
     * idle and tells no one. Remembering it past that lifetime means publishing
     * into a queue that is no longer there, and the default exchange discards
     * an unroutable message silently — the publish looks like it worked.
     */
    public function testDelayQueueIsRedeclaredOnceItsBrokerSideLifetimeHasPassed(): void
    {
        Carbon::setTestNow('2026-08-18 09:40:57');
        $this->bindConfig([]);

        $declaredNames = [];

        $amqpQueue = $this->recordingQueue($declaredNames);
        $amqpExchange = Mockery::mock(\AMQPExchange::class);
        $amqpExchange->shouldReceive('setName');
        $amqpExchange->shouldReceive('publish');

        $queue = $this->makeQueue($this->poolManagerWithChannel(), $amqpQueue, $amqpExchange);

        $queue->laterRaw(10, '{"id":"a"}', 'orders');

        // x-expires is 20s for a 10s delay, so the queue is gone by now.
        Carbon::setTestNow('2026-08-18 09:41:47');

        $queue->laterRaw(10, '{"id":"b"}', 'orders');

        $this->assertSame(
            2,
            $this->countDeclarations($declaredNames, 'orders.delay.10000'),
            'An expired delay queue must be redeclared before it is published to again.',
        );
    }

    /**
     * The redeclare is scoped to what the broker can remove on its own: a queue
     * with no `x-expires` is never garbage-collected, so it must still cost one
     * round trip however long the process runs.
     */
    public function testQueueWithoutBrokerSideLifetimeIsNeverRedeclared(): void
    {
        Carbon::setTestNow('2026-08-18 09:40:57');
        $this->bindConfig([]);

        $declaredNames = [];

        $amqpQueue = $this->recordingQueue($declaredNames);
        $amqpQueue->shouldReceive('get')->andReturn(null);

        $queue = $this->makeQueue($this->poolManagerWithChannel(), $amqpQueue, null, 'orders');

        $this->assertNull($queue->pop());

        Carbon::setTestNow('2026-08-18 11:40:57');

        $this->assertNull($queue->pop());

        $this->assertSame(
            1,
            $this->countDeclarations($declaredNames, 'orders'),
            'A queue the broker never deletes must stay memoised.',
        );
    }

    /**
     * Within the lifetime the memo still has to hold, or the fix would trade a
     * lost message for a redundant round trip on every publish.
     */
    public function testDelayQueueIsNotRedeclaredWhileItIsStillAlive(): void
    {
        Carbon::setTestNow('2026-08-18 09:40:57');
        $this->bindConfig([]);

        $declaredNames = [];

        $amqpQueue = $this->recordingQueue($declaredNames);
        $amqpExchange = Mockery::mock(\AMQPExchange::class);
        $amqpExchange->shouldReceive('setName');
        $amqpExchange->shouldReceive('publish');

        $queue = $this->makeQueue($this->poolManagerWithChannel(), $amqpQueue, $amqpExchange);

        $queue->laterRaw(10, '{"id":"a"}', 'orders');
        $queue->laterRaw(10, '{"id":"b"}', 'orders');

        $this->assertSame(
            1,
            $this->countDeclarations($declaredNames, 'orders.delay.10000'),
            'A delay queue that cannot have expired yet must be declared once.',
        );
    }

    /**
     * What bounds the memo is not `x-expires` on its own: a message published
     * at the very end of the window still needs its full `x-message-ttl` to
     * dead-letter out, and a queue removed on `x-expires` discards its contents
     * instead of dead-lettering them. A TTL that is not a whole number of
     * seconds — reachable through `delay_queue_granularity` — is where a window
     * derived from `x-expires` alone overshoots that bound.
     */
    public function testDelayQueueIsRedeclaredBeforeItsMessagesCouldOutliveIt(): void
    {
        Carbon::setTestNow('2026-08-18 09:40:57.000');
        $this->bindConfig(['delay_queue_granularity' => 300]);

        $declaredNames = [];

        $amqpQueue = $this->recordingQueue($declaredNames);
        $amqpExchange = Mockery::mock(\AMQPExchange::class);
        $amqpExchange->shouldReceive('setName');
        $amqpExchange->shouldReceive('publish');

        $queue = $this->makeQueue($this->poolManagerWithChannel(), $amqpQueue, $amqpExchange);

        // 1s rounds up to a 1200ms bucket, so x-expires is 2400ms and the
        // declare may only be trusted for the 1200ms before the messages of a
        // publish made at the end of it would still be in flight.
        $queue->laterRaw(1, '{"id":"a"}', 'orders');

        Carbon::setTestNow('2026-08-18 09:40:58.500');

        $queue->laterRaw(1, '{"id":"b"}', 'orders');

        $this->assertSame(
            2,
            $this->countDeclarations($declaredNames, 'orders.delay.1200'),
            'A delay queue must be redeclared once its messages could outlive it.',
        );
    }

    /**
     * The broker deletes an auto-delete queue once a consumer has come and
     * gone. Nothing bounds when that happens, so a publisher holding a memo has
     * no point at which it can decide the queue is stale — it must redeclare.
     */
    public function testAutoDeleteQueueIsNeverMemoised(): void
    {
        Carbon::setTestNow('2026-08-18 09:40:57');
        $this->bindConfig(['queues' => ['orders' => ['auto_delete' => true]]]);

        $declaredNames = [];

        $amqpQueue = $this->recordingQueue($declaredNames);
        $amqpQueue->shouldReceive('get')->andReturn(null);

        $queue = $this->makeQueue($this->poolManagerWithChannel(), $amqpQueue, null, 'orders');

        $this->assertNull($queue->pop());
        $this->assertNull($queue->pop());

        $this->assertSame(
            2,
            $this->countDeclarations($declaredNames, 'orders'),
            'An auto-delete queue must be redeclared rather than memoised.',
        );
    }

    /**
     * An AMQPQueue double that records every name it is asked to declare.
     *
     * @param  list<string>  $declaredNames
     */
    private function recordingQueue(array &$declaredNames): \AMQPQueue
    {
        $name = null;

        $amqpQueue = Mockery::mock(\AMQPQueue::class);
        $amqpQueue->shouldReceive('setName')->andReturnUsing(function (string $given) use (&$name): void {
            $name = $given;
        });
        $amqpQueue->shouldReceive('setFlags');
        $amqpQueue->shouldReceive('getFlags')->andReturn(2);
        $amqpQueue->shouldReceive('setArguments');
        $amqpQueue->shouldReceive('declareQueue')->andReturnUsing(function () use (&$name, &$declaredNames): int {
            $declaredNames[] = $name;

            return 0;
        });

        return $amqpQueue;
    }

    /**
     * @param  list<string>  $declaredNames
     */
    private function countDeclarations(array $declaredNames, string $queueName): int
    {
        return count(array_filter($declaredNames, static fn (?string $name): bool => $name === $queueName));
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
