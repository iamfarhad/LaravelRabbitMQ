<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit\Connection;

use AMQPChannel;
use AMQPChannelException;
use AMQPConnection;
use iamfarhad\LaravelRabbitMQ\Connection\ChannelPool;
use iamfarhad\LaravelRabbitMQ\Connection\ConnectionPool;
use iamfarhad\LaravelRabbitMQ\Exceptions\QueueException;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Each test runs in its own process because Mockery `overload:` mocks can
 * only be defined once per process. Expectations are set on the overload
 * template, which applies them to every instance `new AMQPChannel()` creates.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class ChannelPoolTest extends UnitTestCase
{
    private array $config;

    private ConnectionPool $mockConnectionPool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfAmqpExtensionLoaded();

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
        $pool = new ChannelPool($this->mockConnectionPool, $this->config);

        $this->assertInstanceOf(ChannelPool::class, $pool);
    }

    public function testGetsChannelFromPool(): void
    {
        $mockConnection = Mockery::mock(AMQPConnection::class);

        $this->mockConnectionPool->shouldReceive('getConnection')
            ->once()
            ->andReturn($mockConnection);

        $channelTemplate = Mockery::mock('overload:'.AMQPChannel::class);
        $channelTemplate->shouldReceive('__construct');

        $pool = new ChannelPool($this->mockConnectionPool, $this->config);
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

        $channelTemplate = Mockery::mock('overload:'.AMQPChannel::class);
        $channelTemplate->shouldReceive('__construct');
        $channelTemplate->shouldReceive('isConnected')->andReturn(true);
        $channelTemplate->shouldReceive('getConnection')->andReturn($mockConnection);

        $pool = new ChannelPool($this->mockConnectionPool, $this->config);

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

        $channelTemplate = Mockery::mock('overload:'.AMQPChannel::class);
        $channelTemplate->shouldReceive('__construct')
            ->andThrow(new AMQPChannelException('Channel creation failed'));

        $pool = new ChannelPool($this->mockConnectionPool, $this->config);

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

        $channelTemplate = Mockery::mock('overload:'.AMQPChannel::class);
        $channelTemplate->shouldReceive('__construct');
        $channelTemplate->shouldReceive('isConnected')->andReturn(true);
        $channelTemplate->shouldReceive('getConnection')->andReturn($mockConnection);

        $pool = new ChannelPool($this->mockConnectionPool, $this->config);

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

        $channelTemplate = Mockery::mock('overload:'.AMQPChannel::class);
        $channelTemplate->shouldReceive('__construct');
        $channelTemplate->shouldReceive('isConnected')->andReturn(true);
        $channelTemplate->shouldReceive('getConnection')->andReturn($mockConnection);
        $channelTemplate->shouldReceive('close');

        $pool = new ChannelPool($this->mockConnectionPool, $this->config);

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

        $channelTemplate = Mockery::mock('overload:'.AMQPChannel::class);
        $channelTemplate->shouldReceive('__construct');
        $channelTemplate->shouldReceive('isConnected')->andReturn(true);
        $channelTemplate->shouldReceive('getConnection')->andReturn($mockConnection);
        $channelTemplate->shouldReceive('close');

        $pool = new ChannelPool($this->mockConnectionPool, $this->config);

        $pool->getChannel();
        $pool->closeAll();

        $stats = $pool->getStats();
        $this->assertEquals(0, $stats['current_channels']);
        $this->assertEquals(0, $stats['available_channels']);
    }

    public function testReturnsCorrectStats(): void
    {
        $pool = new ChannelPool($this->mockConnectionPool, $this->config);
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

        // Alive when released, dead when requested again.
        $channelTemplate = Mockery::mock('overload:'.AMQPChannel::class);
        $channelTemplate->shouldReceive('__construct');
        $channelTemplate->shouldReceive('isConnected')->andReturn(true, false);
        $channelTemplate->shouldReceive('getConnection')->andReturn($mockConnection1);

        $pool = new ChannelPool($this->mockConnectionPool, $this->config);

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

        $channelTemplate = Mockery::mock('overload:'.AMQPChannel::class);
        $channelTemplate->shouldReceive('__construct');

        $pool = new ChannelPool($this->mockConnectionPool, $this->config);

        $pool->getChannel();
        $pool->getChannel();
        $pool->getChannel();

        $stats = $pool->getStats();
        $this->assertEquals(3, $stats['current_channels']);
    }
}
