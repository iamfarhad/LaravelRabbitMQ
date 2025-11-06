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
                'health_check_enabled' => true,
                'health_check_interval' => 30,
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
        $mockChannel = Mockery::mock(AMQPChannel::class);

        $this->mockConnectionPool->shouldReceive('getConnection')
            ->once()
            ->andReturn($mockConnection);

        // Mock AMQPChannel constructor
        $mockChannelClass = Mockery::mock('overload:'.AMQPChannel::class);
        $mockChannelClass->shouldReceive('__construct')
            ->once()
            ->with($mockConnection)
            ->andReturn($mockChannel);

        $mockChannel->shouldReceive('getChannelId')
            ->once()
            ->andReturn(1);

        $pool = new ChannelPool($this->mockConnectionPool, $this->config);
        $channel = $pool->getChannel();

        $this->assertInstanceOf(AMQPChannel::class, $channel);
    }

    public function testReusesAvailableChannel(): void
    {
        $mockConnection = Mockery::mock(AMQPConnection::class);
        $mockChannel = Mockery::mock(AMQPChannel::class);

        $this->mockConnectionPool->shouldReceive('getConnection')
            ->once()
            ->andReturn($mockConnection);

        // Mock AMQPChannel constructor
        $mockChannelClass = Mockery::mock('overload:'.AMQPChannel::class);
        $mockChannelClass->shouldReceive('__construct')
            ->once()
            ->with($mockConnection)
            ->andReturn($mockChannel);

        $mockChannel->shouldReceive('getChannelId')
            ->times(3) // Once for creation, twice for health checks
            ->andReturn(1);

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
        $mockConnection = Mockery::mock(AMQPConnection::class);

        $this->mockConnectionPool->shouldReceive('getConnection')
            ->once()
            ->andReturn($mockConnection);

        // Mock AMQPChannel constructor to throw exception
        $mockChannelClass = Mockery::mock('overload:'.AMQPChannel::class);
        $mockChannelClass->shouldReceive('__construct')
            ->once()
            ->with($mockConnection)
            ->andThrow(new AMQPChannelException('Channel creation failed'));

        $pool = new ChannelPool($this->mockConnectionPool, $this->config);

        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('Failed to create AMQP channel');

        $pool->getChannel();
    }

    public function testReleasesChannelBackToPool(): void
    {
        $mockConnection = Mockery::mock(AMQPConnection::class);
        $mockChannel = Mockery::mock(AMQPChannel::class);

        $this->mockConnectionPool->shouldReceive('getConnection')
            ->once()
            ->andReturn($mockConnection);

        // Mock AMQPChannel constructor
        $mockChannelClass = Mockery::mock('overload:'.AMQPChannel::class);
        $mockChannelClass->shouldReceive('__construct')
            ->once()
            ->with($mockConnection)
            ->andReturn($mockChannel);

        $mockChannel->shouldReceive('getChannelId')
            ->twice() // Once for creation, once for health check
            ->andReturn(1);

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
        $mockChannel = Mockery::mock(AMQPChannel::class);

        $this->mockConnectionPool->shouldReceive('getConnection')
            ->once()
            ->andReturn($mockConnection);

        // Mock AMQPChannel constructor
        $mockChannelClass = Mockery::mock('overload:'.AMQPChannel::class);
        $mockChannelClass->shouldReceive('__construct')
            ->once()
            ->with($mockConnection)
            ->andReturn($mockChannel);

        $mockChannel->shouldReceive('getChannelId')
            ->twice() // Once for creation, once for close check
            ->andReturn(1);

        $mockChannel->shouldReceive('close')
            ->once();

        $pool = new ChannelPool($this->mockConnectionPool, $this->config);

        $channel = $pool->getChannel();
        $pool->closeChannel($channel);

        $stats = $pool->getStats();
        $this->assertEquals(0, $stats['current_channels']);
    }

    public function testClosesAllChannels(): void
    {
        $mockConnection = Mockery::mock(AMQPConnection::class);
        $mockChannel = Mockery::mock(AMQPChannel::class);

        $this->mockConnectionPool->shouldReceive('getConnection')
            ->once()
            ->andReturn($mockConnection);

        // Mock AMQPChannel constructor
        $mockChannelClass = Mockery::mock('overload:'.AMQPChannel::class);
        $mockChannelClass->shouldReceive('__construct')
            ->once()
            ->with($mockConnection)
            ->andReturn($mockChannel);

        $mockChannel->shouldReceive('getChannelId')
            ->twice() // Once for creation, once for close check
            ->andReturn(1);

        $mockChannel->shouldReceive('close')
            ->once();

        $pool = new ChannelPool($this->mockConnectionPool, $this->config);

        $channel = $pool->getChannel();
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
        $this->assertTrue($stats['health_check_enabled']);
    }

    public function testHandlesDeadChannelInPool(): void
    {
        $mockConnection1 = Mockery::mock(AMQPConnection::class);
        $mockConnection2 = Mockery::mock(AMQPConnection::class);
        $mockChannel1 = Mockery::mock(AMQPChannel::class);
        $mockChannel2 = Mockery::mock(AMQPChannel::class);

        $this->mockConnectionPool->shouldReceive('getConnection')
            ->twice()
            ->andReturn($mockConnection1, $mockConnection2);

        $this->mockConnectionPool->shouldReceive('releaseConnection')
            ->once()
            ->with($mockConnection1);

        // Mock AMQPChannel constructor
        $mockChannelClass = Mockery::mock('overload:'.AMQPChannel::class);
        $mockChannelClass->shouldReceive('__construct')
            ->twice()
            ->andReturn($mockChannel1, $mockChannel2);

        $mockChannel1->shouldReceive('getChannelId')
            ->twice() // Once for creation, once for health check (fails)
            ->andReturn(1)
            ->andThrow(new \Exception('Channel closed'));

        $mockChannel2->shouldReceive('getChannelId')
            ->once()
            ->andReturn(2);

        $pool = new ChannelPool($this->mockConnectionPool, $this->config);

        // Get first channel and release it
        $channel1 = $pool->getChannel();
        $pool->releaseChannel($channel1);

        // Get channel again - should detect dead channel and create new one
        $channel2 = $pool->getChannel();

        $this->assertNotSame($channel1, $channel2);
    }
}
