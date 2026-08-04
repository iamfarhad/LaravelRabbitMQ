<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit;

use AMQPChannel;
use AMQPChannelException;
use AMQPConnection;
use AMQPConnectionException;
use AMQPEnvelope;
use iamfarhad\LaravelRabbitMQ\Connection\ConnectionPool;
use iamfarhad\LaravelRabbitMQ\Connection\PoolManager;
use iamfarhad\LaravelRabbitMQ\Exceptions\SettlementException;
use iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob;
use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use iamfarhad\LaravelRabbitMQ\Tests\Doubles\TestableChannelPool;
use iamfarhad\LaravelRabbitMQ\Tests\Doubles\TestableRabbitQueue;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Mockery;

/**
 * Regression tests for issue #23: "Could not create queue. No channel
 * available." under Laravel Octane / long-lived workers.
 *
 * The failure sequence was: a connection dies (broker restart, idle
 * disconnect, missed heartbeats) -> ext-amqp flips the channel's internal
 * is_connected flag -> the pool's liveness check (based on getChannelId(),
 * which never consults that flag) still reports the channel healthy -> the
 * dead channel is vended again -> AMQPQueue::__construct throws
 * "Could not create queue. No channel available." and no recovery happens.
 *
 * Each test runs in its own process because Mockery `overload:` mocks can
 * only be defined once per process.
 */
class DeadChannelRecoveryTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    private function bindEmptyConfig(): void
    {
        $container = new Container;
        $container->instance('config', new ConfigRepository([]));
        Container::setInstance($container);
    }

    /**
     * Build the driver with its ext-amqp construction seams pointed at the
     * supplied doubles, so these run with the real extension loaded.
     */
    private function makeQueue(
        PoolManager $poolManager,
        ?\AMQPQueue $amqpQueue = null,
        ?\AMQPExchange $amqpExchange = null
    ): TestableRabbitQueue {
        $queue = TestableRabbitQueue::make($poolManager, 'default');

        if ($amqpQueue !== null) {
            $queue->useQueueFactory(fn (): \AMQPQueue => $amqpQueue);
        }

        if ($amqpExchange !== null) {
            $queue->useExchangeFactory(fn (): \AMQPExchange => $amqpExchange);
        }

        return $queue;
    }

    public function testGetChannelReplacesDeadCachedChannel(): void
    {
        $deadChannel = Mockery::mock(AMQPChannel::class);
        $deadChannel->shouldReceive('isConnected')->andReturn(false);

        $freshChannel = Mockery::mock(AMQPChannel::class);

        $poolManager = Mockery::mock(PoolManager::class);
        $poolManager->shouldReceive('getChannel')->twice()->andReturn($deadChannel, $freshChannel);
        $poolManager->shouldReceive('releaseChannel')->once()->with($deadChannel);

        $queue = $this->makeQueue($poolManager, null, null);

        $this->assertSame($deadChannel, $queue->getChannel());
        $this->assertSame($freshChannel, $queue->getChannel());
    }

    public function testGetChannelKeepsHealthyCachedChannel(): void
    {
        $connection = Mockery::mock(AMQPConnection::class);
        $connection->shouldReceive('isConnected')->andReturn(true);

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('isConnected')->andReturn(true);
        $channel->shouldReceive('getConnection')->andReturn($connection);

        $poolManager = Mockery::mock(PoolManager::class);
        $poolManager->shouldReceive('getChannel')->once()->andReturn($channel);

        $queue = $this->makeQueue($poolManager, null, null);

        $this->assertSame($channel, $queue->getChannel());
        $this->assertSame($channel, $queue->getChannel());
    }

    public function testDeclareQueueRecoversFromNoChannelAvailable(): void
    {
        $this->bindEmptyConfig();

        $deadChannel = Mockery::mock(AMQPChannel::class);
        $deadChannel->shouldReceive('isConnected')->andReturn(false);

        $freshChannel = Mockery::mock(AMQPChannel::class);

        $poolManager = Mockery::mock(PoolManager::class);
        $poolManager->shouldReceive('getChannel')->twice()->andReturn($deadChannel, $freshChannel);
        $poolManager->shouldReceive('releaseChannel')->once()->with($deadChannel);

        $declareCalls = 0;

        $amqpQueue = Mockery::mock(\AMQPQueue::class);
        $amqpQueue->shouldReceive('setName')->with('orders');
        $amqpQueue->shouldReceive('setFlags');
        $amqpQueue->shouldReceive('declareQueue')->andReturnUsing(function () use (&$declareCalls): int {
            $declareCalls++;

            return 0;
        });

        // First construction fails exactly the way ext-amqp fails on a channel
        // whose connection died; the retry must land on the fresh channel.
        $constructions = 0;

        $queue = TestableRabbitQueue::make($poolManager, 'default')
            ->useQueueFactory(function () use ($amqpQueue, &$constructions): \AMQPQueue {
                if ($constructions++ === 0) {
                    throw new AMQPChannelException('Could not create queue. No channel available.');
                }

                return $amqpQueue;
            });

        $queue->declareQueue('orders');

        $this->assertSame(1, $declareCalls);
    }

    public function testDeclareQueueDoesNotRetryBrokerReportedErrors(): void
    {
        $this->bindEmptyConfig();

        $channel = Mockery::mock(AMQPChannel::class);

        $poolManager = Mockery::mock(PoolManager::class);
        $poolManager->shouldReceive('getChannel')->once()->andReturn($channel);
        $poolManager->shouldNotReceive('releaseChannel');

        // 403 ACCESS_REFUSED: a semantic broker error a new channel would only
        // repeat, so it must surface immediately instead of being retried.
        $amqpQueue = Mockery::mock(\AMQPQueue::class);
        $amqpQueue->shouldReceive('setName');
        $amqpQueue->shouldReceive('setFlags');
        $amqpQueue->shouldReceive('declareQueue')
            ->andThrow(new AMQPChannelException('ACCESS_REFUSED', 403));

        $queue = $this->makeQueue($poolManager, $amqpQueue, null);

        $this->expectException(AMQPChannelException::class);
        $this->expectExceptionCode(403);

        $queue->declareQueue('orders');
    }

    public function testAckReportsFailureAndReleasesTheChannelWhenTheDeliveringChannelIsDead(): void
    {
        $deadChannel = Mockery::mock(AMQPChannel::class);
        $deadChannel->shouldReceive('isConnected')->andReturn(false);

        $poolManager = Mockery::mock(PoolManager::class);
        $poolManager->shouldReceive('getChannel')->once()->andReturn($deadChannel);
        $poolManager->shouldReceive('releaseChannel')->once()->with($deadChannel);

        $envelope = Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getDeliveryTag')->andReturn(1);

        $job = Mockery::mock(RabbitMQJob::class);
        $job->shouldReceive('getRabbitMQMessage')->andReturn($envelope);
        $job->shouldReceive('getQueue')->andReturn('default');

        $queue = $this->makeQueue($poolManager, null, null);
        $queue->getChannel();

        // The delivery tag only exists on the dead channel: ack must release
        // it and let the broker requeue, never construct an AMQPQueue on a
        // replacement channel (AMQPQueue is not defined in this test, so any
        // construction attempt would fail loudly). It must also report the
        // failure rather than let the caller assume the delivery was settled.
        try {
            $queue->ack($job);
            $this->fail('ack() should report that the delivery could not be settled.');
        } catch (SettlementException $exception) {
            $this->assertStringContainsString('Cannot ack delivery on queue [default]', $exception->getMessage());
        }

        // $amqpChannel is private to RabbitQueue, so reflect on the declaring class.
        $cachedChannel = new \ReflectionProperty(RabbitQueue::class, 'amqpChannel');

        $this->assertNull($cachedChannel->getValue($queue));
    }

    public function testChannelPoolDiscardsDeadChannelInsteadOfReusingIt(): void
    {
        $firstConnection = Mockery::mock(AMQPConnection::class);
        $firstConnection->shouldReceive('isConnected')->andReturn(true);

        $secondConnection = Mockery::mock(AMQPConnection::class);

        $connectionPool = Mockery::mock(ConnectionPool::class);
        $connectionPool->shouldReceive('getConnection')->twice()->andReturn($firstConnection, $secondConnection);
        $connectionPool->shouldReceive('releaseConnection')->once()->with($firstConnection);

        // Alive when released back to the pool, dead by the time it is
        // requested again — the exact shape of an idle disconnect. The
        // replacement has to be a genuinely different channel object.
        $dying = Mockery::mock(AMQPChannel::class);
        $dying->shouldReceive('isConnected')->andReturn(true, false);
        $dying->shouldReceive('getConnection')->andReturn($firstConnection);
        $dying->shouldReceive('close')->andReturnNull();

        $replacement = Mockery::mock(AMQPChannel::class);
        $replacement->shouldReceive('isConnected')->andReturn(true);
        $replacement->shouldReceive('getConnection')->andReturn($secondConnection);

        $channels = [$dying, $replacement];

        $pool = TestableChannelPool::make($connectionPool, ['pool' => ['health_check_enabled' => false]])
            ->useChannelFactory(function () use (&$channels): AMQPChannel {
                return array_shift($channels);
            });

        $firstChannel = $pool->getChannel();
        $pool->releaseChannel($firstChannel);

        $secondChannel = $pool->getChannel();

        $this->assertNotSame($firstChannel, $secondChannel);
        $this->assertSame(1, $pool->getStats()['current_channels']);
    }

    public function testChannelPoolDoesNotMultiplexOntoDeadConnection(): void
    {
        $deadConnection = Mockery::mock(AMQPConnection::class);
        $deadConnection->shouldReceive('isConnected')->andReturn(false);

        $freshConnection = Mockery::mock(AMQPConnection::class);

        $connectionPool = Mockery::mock(ConnectionPool::class);
        $connectionPool->shouldReceive('getConnection')->twice()->andReturn($deadConnection, $freshConnection);

        $amqpChannel = Mockery::mock(AMQPChannel::class);

        $pool = TestableChannelPool::make($connectionPool, ['pool' => ['health_check_enabled' => false]]);

        $pool->getChannel();
        $pool->getChannel();

        $this->assertSame(2, $pool->getStats()['current_channels']);
    }

    public function testChannelPoolRetriesChannelCreationOnFreshConnection(): void
    {
        $staleConnection = Mockery::mock(AMQPConnection::class);
        $freshConnection = Mockery::mock(AMQPConnection::class);

        $connectionPool = Mockery::mock(ConnectionPool::class);
        $connectionPool->shouldReceive('getConnection')->twice()->andReturn($staleConnection, $freshConnection);
        $connectionPool->shouldReceive('releaseConnection')->once()->with($staleConnection);

        // A pooled connection can die between the liveness check and channel
        // creation; the pool must retry once on a fresh connection.
        $amqpChannel = Mockery::mock(AMQPChannel::class);
        $attempts = 0;

        $pool = TestableChannelPool::make($connectionPool, ['pool' => ['health_check_enabled' => false]])
            ->useChannelFactory(function () use ($amqpChannel, &$attempts): AMQPChannel {
                if ($attempts++ === 0) {
                    throw new AMQPConnectionException('Server connection error');
                }

                return $amqpChannel;
            });

        $channel = $pool->getChannel();

        $this->assertInstanceOf(AMQPChannel::class, $channel);
        $this->assertSame(1, $pool->getStats()['current_channels']);
    }

    public function testChannelPoolDrainsAllDeadChannelsAfterConnectionDeath(): void
    {
        $firstConnection = Mockery::mock(AMQPConnection::class);
        $firstConnection->shouldReceive('isConnected')->andReturn(true);

        $secondConnection = Mockery::mock(AMQPConnection::class);

        $connectionPool = Mockery::mock(ConnectionPool::class);
        $connectionPool->shouldReceive('getConnection')->twice()->andReturn($firstConnection, $secondConnection);
        $connectionPool->shouldReceive('releaseConnection')->once()->with($firstConnection);

        // Two channels multiplexed onto one connection; both are alive when
        // released and both are dead once the connection is gone. A single
        // getChannel() call must skip past every corpse.
        $makeDying = function () use ($firstConnection): AMQPChannel {
            $channel = Mockery::mock(AMQPChannel::class);
            // Alive for the liveness check on release, dead for the one on the
            // next acquisition.
            $channel->shouldReceive('isConnected')->andReturn(true, false);
            $channel->shouldReceive('getConnection')->andReturn($firstConnection);
            $channel->shouldReceive('close')->andReturnNull();

            return $channel;
        };

        $replacementChannel = Mockery::mock(AMQPChannel::class);
        $replacementChannel->shouldReceive('isConnected')->andReturn(true);
        $replacementChannel->shouldReceive('getConnection')->andReturn($secondConnection);

        $channels = [$makeDying(), $makeDying(), $replacementChannel];

        $pool = TestableChannelPool::make($connectionPool, ['pool' => ['health_check_enabled' => false]])
            ->useChannelFactory(function () use (&$channels): AMQPChannel {
                return array_shift($channels);
            });

        $firstChannel = $pool->getChannel();
        $secondChannel = $pool->getChannel();
        $pool->releaseChannel($firstChannel);
        $pool->releaseChannel($secondChannel);

        $replacement = $pool->getChannel();

        $this->assertNotSame($firstChannel, $replacement);
        $this->assertNotSame($secondChannel, $replacement);
        $this->assertSame(1, $pool->getStats()['current_channels']);
    }
}
