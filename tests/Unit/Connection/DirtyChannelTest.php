<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit\Connection;

use AMQPChannel;
use AMQPConnection;
use iamfarhad\LaravelRabbitMQ\Connection\ChannelPool;
use iamfarhad\LaravelRabbitMQ\Connection\ConnectionPool;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Mockery;

/**
 * confirm.select, tx.select and basic.qos permanently change a channel and AMQP
 * offers no way to undo them. A channel carrying that state used to be returned
 * to the pool, so the next borrower silently inherited confirm mode (plus the
 * previous owner's confirm callback), an open transaction, or a prefetch.
 */
class DirtyChannelTest extends UnitTestCase
{
    private int $closeCount = 0;

    private function openChannel(): AMQPChannel
    {
        $connection = Mockery::mock(AMQPConnection::class);
        $connection->shouldReceive('isConnected')->andReturn(true);

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('isConnected')->andReturn(true);
        $channel->shouldReceive('getConnection')->andReturn($connection);
        $channel->shouldReceive('close')->andReturnUsing(function (): void {
            $this->closeCount++;
        });

        return $channel;
    }

    private function poolWith(AMQPChannel ...$channels): ChannelPool
    {
        $connectionPool = Mockery::mock(ConnectionPool::class);
        $connectionPool->shouldReceive('getConnection')->andReturn(
            Mockery::mock(AMQPConnection::class)->shouldReceive('isConnected')->andReturn(true)->getMock()
        );
        $connectionPool->shouldReceive('releaseConnection')->andReturnNull();

        return new ChannelPool($connectionPool, ['pool' => ['health_check_enabled' => false]]);
    }

    public function testCleanChannelIsReturnedToThePoolForReuse(): void
    {
        $channel = $this->openChannel();
        $pool = $this->poolWith($channel);

        $this->seedActiveChannel($pool, $channel);
        $pool->releaseChannel($channel);

        $this->assertSame(1, $pool->getStats()['available_channels']);
        $this->assertSame($channel, $pool->getChannel(), 'A clean channel is reusable.');
        $this->assertSame(0, $this->closeCount);
    }

    public function testDirtyChannelIsClosedInsteadOfPooled(): void
    {
        $channel = $this->openChannel();
        $pool = $this->poolWith($channel);
        $this->seedActiveChannel($pool, $channel);

        $pool->markDirty($channel);

        $this->assertTrue($pool->isDirty($channel));

        $pool->releaseChannel($channel);

        $this->assertSame(1, $this->closeCount, 'The channel is retired, not pooled.');
        $this->assertSame(
            0,
            $pool->getStats()['available_channels'],
            'A channel in confirm/transaction mode must never be handed to another borrower.'
        );
        $this->assertFalse($pool->isDirty($channel), 'Retired channels stop being tracked.');
    }

    public function testChannelCounterNeverGoesNegativeOnRepeatedCloses(): void
    {
        $channel = $this->openChannel();
        $pool = $this->poolWith($channel);
        $this->seedActiveChannel($pool, $channel);

        $pool->closeChannel($channel);
        $pool->closeChannel($channel);
        $pool->closeChannel($channel);

        $this->assertGreaterThanOrEqual(0, $pool->getStats()['current_channels']);
    }

    /**
     * Put a channel into the pool's active set the way createNewChannel() would,
     * without needing a real AMQP connection to construct one.
     */
    private function seedActiveChannel(ChannelPool $pool, AMQPChannel $channel): void
    {
        $active = new \ReflectionProperty($pool, 'activeChannels');
        $active->setValue($pool, [spl_object_id($channel) => $channel]);

        $counter = new \ReflectionProperty($pool, 'currentChannels');
        $counter->setValue($pool, 1);
    }
}
