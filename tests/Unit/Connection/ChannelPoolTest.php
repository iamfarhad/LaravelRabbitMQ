<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit\Connection;

use AMQPChannel;
use AMQPChannelException;
use AMQPConnection;
use iamfarhad\LaravelRabbitMQ\Connection\ChannelPool;
use iamfarhad\LaravelRabbitMQ\Connection\ConnectionPool;
use iamfarhad\LaravelRabbitMQ\Exceptions\QueueException;
use iamfarhad\LaravelRabbitMQ\Tests\Doubles\TestableChannelPool;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Mockery;

/**
 * Channel creation is redirected through TestableChannelPool's factory seam, so
 * these run with the real ext-amqp loaded — which is the only configuration the
 * package supports.
 */
class ChannelPoolTest extends UnitTestCase
{
    private array $config;

    private ConnectionPool $mockConnectionPool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = [
            'pool' => [
                'max_channels_per_connection' => 100,
                'health_check_enabled' => false,
            ],
        ];

        $this->mockConnectionPool = Mockery::mock(ConnectionPool::class);
    }

    public function testCreatesChannelPoolSuccessfully(): void
    {
        $pool = TestableChannelPool::make($this->mockConnectionPool, $this->config);

        $this->assertInstanceOf(ChannelPool::class, $pool);
    }

    public function testGetsChannelFromPool(): void
    {
        $mockConnection = Mockery::mock(AMQPConnection::class);

        $this->mockConnectionPool->shouldReceive('getConnection')
            ->once()
            ->andReturn($mockConnection);

        $amqpChannel = Mockery::mock(AMQPChannel::class);

        $pool = TestableChannelPool::make($this->mockConnectionPool, $this->config)
            ->useChannelFactory(fn (): AMQPChannel => $amqpChannel);
        $channel = $pool->getChannel();

        $this->assertInstanceOf(AMQPChannel::class, $channel);
        $this->assertSame(1, $pool->getStats()['active_channels']);
    }

    public function testReusesAvailableChannel(): void
    {
        $mockConnection = Mockery::mock(AMQPConnection::class);
        $mockConnection->shouldReceive('isConnected')->andReturn(true);

        $this->mockConnectionPool->shouldReceive('getConnection')
            ->once()
            ->andReturn($mockConnection);

        $amqpChannel = Mockery::mock(AMQPChannel::class);
        $amqpChannel->shouldReceive('isConnected')->andReturn(true);
        $amqpChannel->shouldReceive('getConnection')->andReturn($mockConnection);

        $pool = TestableChannelPool::make($this->mockConnectionPool, $this->config)
            ->useChannelFactory(fn (): AMQPChannel => $amqpChannel);

        // Get channel first time
        $channel1 = $pool->getChannel();

        // Release it back to pool
        $pool->releaseChannel($channel1);

        // Get channel second time (should reuse)
        $channel2 = $pool->getChannel();

        $this->assertSame($channel1, $channel2);
    }

    public function testThrowsExceptionOnChannelCreationFailure(): void
    {
        $mockConnection1 = Mockery::mock(AMQPConnection::class);
        $mockConnection2 = Mockery::mock(AMQPConnection::class);

        // Creation is retried once on a fresh connection before giving up.
        $this->mockConnectionPool->shouldReceive('getConnection')
            ->twice()
            ->andReturn($mockConnection1, $mockConnection2);

        $this->mockConnectionPool->shouldReceive('releaseConnection')
            ->once()
            ->with($mockConnection1);

        $pool = TestableChannelPool::make($this->mockConnectionPool, $this->config)
            ->useChannelFactory(function (): AMQPChannel {
                throw new AMQPChannelException('Channel creation failed');
            });

        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('Failed to create AMQP channel');

        $pool->getChannel();
    }

    public function testReleasesChannelBackToPool(): void
    {
        $mockConnection = Mockery::mock(AMQPConnection::class);
        $mockConnection->shouldReceive('isConnected')->andReturn(true);

        $this->mockConnectionPool->shouldReceive('getConnection')
            ->once()
            ->andReturn($mockConnection);

        $amqpChannel = Mockery::mock(AMQPChannel::class);
        $amqpChannel->shouldReceive('isConnected')->andReturn(true);
        $amqpChannel->shouldReceive('getConnection')->andReturn($mockConnection);

        $pool = TestableChannelPool::make($this->mockConnectionPool, $this->config)
            ->useChannelFactory(fn (): AMQPChannel => $amqpChannel);

        $channel = $pool->getChannel();
        $statsAfterGet = $pool->getStats();

        $pool->releaseChannel($channel);
        $statsAfterRelease = $pool->getStats();

        $this->assertEquals(1, $statsAfterGet['active_channels']);
        $this->assertEquals(0, $statsAfterRelease['active_channels']);
        $this->assertEquals(1, $statsAfterRelease['available_channels']);
    }

    public function testClosesSpecificChannel(): void
    {
        $mockConnection = Mockery::mock(AMQPConnection::class);
        $mockConnection->shouldReceive('isConnected')->andReturn(true);

        $this->mockConnectionPool->shouldReceive('getConnection')
            ->once()
            ->andReturn($mockConnection);

        // Closing the only channel on this connection releases it back to
        // the connection pool (see unbindChannelFromConnection()).
        $this->mockConnectionPool->shouldReceive('releaseConnection')
            ->once()
            ->with($mockConnection);

        $amqpChannel = Mockery::mock(AMQPChannel::class);
        $amqpChannel->shouldReceive('isConnected')->andReturn(true);
        $amqpChannel->shouldReceive('getConnection')->andReturn($mockConnection);
        $amqpChannel->shouldReceive('close');

        $pool = TestableChannelPool::make($this->mockConnectionPool, $this->config)
            ->useChannelFactory(fn (): AMQPChannel => $amqpChannel);

        $channel = $pool->getChannel();
        $pool->closeChannel($channel);

        $stats = $pool->getStats();
        $this->assertEquals(0, $stats['current_channels']);
    }

    public function testClosesAllChannels(): void
    {
        $mockConnection = Mockery::mock(AMQPConnection::class);
        $mockConnection->shouldReceive('isConnected')->andReturn(true);

        $this->mockConnectionPool->shouldReceive('getConnection')
            ->once()
            ->andReturn($mockConnection);

        $amqpChannel = Mockery::mock(AMQPChannel::class);
        $amqpChannel->shouldReceive('isConnected')->andReturn(true);
        $amqpChannel->shouldReceive('getConnection')->andReturn($mockConnection);
        $amqpChannel->shouldReceive('close');

        $pool = TestableChannelPool::make($this->mockConnectionPool, $this->config)
            ->useChannelFactory(fn (): AMQPChannel => $amqpChannel);

        $pool->getChannel();
        $pool->closeAll();

        $stats = $pool->getStats();
        $this->assertEquals(0, $stats['current_channels']);
        $this->assertEquals(0, $stats['available_channels']);
    }

    public function testReturnsCorrectStats(): void
    {
        $pool = TestableChannelPool::make($this->mockConnectionPool, $this->config);
        $stats = $pool->getStats();

        $this->assertEquals(100, $stats['max_channels_per_connection']);
        $this->assertEquals(0, $stats['current_channels']);
        $this->assertEquals(0, $stats['active_channels']);
        $this->assertEquals(0, $stats['available_channels']);
        $this->assertFalse($stats['health_check_enabled']);
    }

    public function testHandlesDeadChannelInPool(): void
    {
        $mockConnection1 = Mockery::mock(AMQPConnection::class);
        $mockConnection1->shouldReceive('isConnected')->andReturn(true);

        $mockConnection2 = Mockery::mock(AMQPConnection::class);

        $this->mockConnectionPool->shouldReceive('getConnection')
            ->twice()
            ->andReturn($mockConnection1, $mockConnection2);

        $this->mockConnectionPool->shouldReceive('releaseConnection')
            ->once()
            ->with($mockConnection1);

        // First channel: alive when released, dead when requested again.
        $deadChannel = Mockery::mock(AMQPChannel::class);
        $deadChannel->shouldReceive('isConnected')->andReturn(true, false);
        $deadChannel->shouldReceive('getConnection')->andReturn($mockConnection1);
        $deadChannel->shouldReceive('close')->andReturnNull();

        $freshChannel = Mockery::mock(AMQPChannel::class);
        $freshChannel->shouldReceive('isConnected')->andReturn(true);
        $freshChannel->shouldReceive('getConnection')->andReturn($mockConnection2);

        $channels = [$deadChannel, $freshChannel];

        $pool = TestableChannelPool::make($this->mockConnectionPool, $this->config)
            ->useChannelFactory(function () use (&$channels): AMQPChannel {
                return array_shift($channels);
            });

        // Get first channel and release it
        $channel1 = $pool->getChannel();
        $pool->releaseChannel($channel1);

        // Get channel again - should detect dead channel and create new one
        $channel2 = $pool->getChannel();

        $this->assertNotSame($channel1, $channel2);
    }

    public function testMultiplexesChannelsOntoSameConnectionUpToLimit(): void
    {
        $this->config['pool']['max_channels_per_connection'] = 2;

        $mockConnection1 = Mockery::mock(AMQPConnection::class);
        $mockConnection1->shouldReceive('isConnected')->andReturn(true);

        $mockConnection2 = Mockery::mock(AMQPConnection::class);

        // Only two connections should be requested for three channels: the
        // first two channels multiplex onto mockConnection1 (limit is 2),
        // the third exceeds it and needs mockConnection2.
        $this->mockConnectionPool->shouldReceive('getConnection')
            ->twice()
            ->andReturn($mockConnection1, $mockConnection2);

        $amqpChannel = Mockery::mock(AMQPChannel::class);

        $pool = TestableChannelPool::make($this->mockConnectionPool, $this->config)
            ->useChannelFactory(fn (): AMQPChannel => $amqpChannel);

        $pool->getChannel();
        $pool->getChannel();
        $pool->getChannel();

        $stats = $pool->getStats();
        $this->assertEquals(3, $stats['current_channels']);
    }
}
