<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit\Connection;

use AMQPChannel;
use AMQPConnection;
use iamfarhad\LaravelRabbitMQ\Connection\ChannelPool;
use iamfarhad\LaravelRabbitMQ\Connection\ConnectionFactory;
use iamfarhad\LaravelRabbitMQ\Connection\ConnectionPool;
use iamfarhad\LaravelRabbitMQ\Connection\PoolManager;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Mockery;

class PoolManagerTest extends UnitTestCase
{
    private array $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfAmqpExtensionLoaded();

        $this->config = [
            'hosts' => [
                'host' => 'localhost',
                'port' => 5672,
                'user' => 'guest',
                'password' => 'guest',
                'vhost' => '/',
            ],
            'pool' => [
                'max_connections' => 10,
                'min_connections' => 2,
                'max_channels_per_connection' => 100,
                'max_retries' => 3,
                'retry_delay' => 1000,
                'health_check_enabled' => true,
                'health_check_interval' => 30,
            ],
        ];
    }

    public function testCreatesPoolManagerSuccessfully(): void
    {
        $poolManager = new PoolManager($this->config);

        $this->assertInstanceOf(PoolManager::class, $poolManager);
    }

    public function testGetsChannelFromPool(): void
    {
        // Mock the dependencies
        $mockConnection = Mockery::mock(AMQPConnection::class);
        $mockChannel = Mockery::mock(AMQPChannel::class);

        // Mock AMQPConnection constructor
        $mockConnectionClass = Mockery::mock('overload:'.AMQPConnection::class);
        $mockConnectionClass->shouldReceive('__construct')->andReturn($mockConnection);
        $mockConnection->shouldReceive('connect')->once();

        // Mock AMQPChannel constructor
        $mockChannelClass = Mockery::mock('overload:'.AMQPChannel::class);
        $mockChannelClass->shouldReceive('__construct')->andReturn($mockChannel);
        $mockChannel->shouldReceive('getChannelId')->andReturn(1);

        $poolManager = new PoolManager($this->config);
        $channel = $poolManager->getChannel();

        $this->assertInstanceOf(AMQPChannel::class, $channel);
    }

    public function testReleasesChannelToPool(): void
    {
        $mockConnection = Mockery::mock(AMQPConnection::class);
        $mockChannel = Mockery::mock(AMQPChannel::class);

        // Mock AMQPConnection constructor
        $mockConnectionClass = Mockery::mock('overload:'.AMQPConnection::class);
        $mockConnectionClass->shouldReceive('__construct')->andReturn($mockConnection);
        $mockConnection->shouldReceive('connect')->once();

        // Mock AMQPChannel constructor
        $mockChannelClass = Mockery::mock('overload:'.AMQPChannel::class);
        $mockChannelClass->shouldReceive('__construct')->andReturn($mockChannel);
        $mockChannel->shouldReceive('getChannelId')->twice(); // Once for get, once for release check

        $poolManager = new PoolManager($this->config);

        $channel = $poolManager->getChannel();
        $poolManager->releaseChannel($channel);

        // No exception should be thrown
        $this->assertTrue(true);
    }

    public function testGetsConnectionFromPool(): void
    {
        $mockConnection = Mockery::mock(AMQPConnection::class);

        // Mock AMQPConnection constructor
        $mockConnectionClass = Mockery::mock('overload:'.AMQPConnection::class);
        $mockConnectionClass->shouldReceive('__construct')->andReturn($mockConnection);
        $mockConnection->shouldReceive('connect')->once();
        $mockConnection->shouldReceive('isConnected')->once()->andReturn(true);

        $poolManager = new PoolManager($this->config);
        $connection = $poolManager->getConnection();

        $this->assertInstanceOf(AMQPConnection::class, $connection);
    }

    public function testReleasesConnectionToPool(): void
    {
        $mockConnection = Mockery::mock(AMQPConnection::class);

        // Mock AMQPConnection constructor
        $mockConnectionClass = Mockery::mock('overload:'.AMQPConnection::class);
        $mockConnectionClass->shouldReceive('__construct')->andReturn($mockConnection);
        $mockConnection->shouldReceive('connect')->once();
        $mockConnection->shouldReceive('isConnected')->twice()->andReturn(true);

        $poolManager = new PoolManager($this->config);

        $connection = $poolManager->getConnection();
        $poolManager->releaseConnection($connection);

        // No exception should be thrown
        $this->assertTrue(true);
    }

    public function testClosesSpecificChannel(): void
    {
        $mockConnection = Mockery::mock(AMQPConnection::class);
        $mockChannel = Mockery::mock(AMQPChannel::class);

        // Mock AMQPConnection constructor
        $mockConnectionClass = Mockery::mock('overload:'.AMQPConnection::class);
        $mockConnectionClass->shouldReceive('__construct')->andReturn($mockConnection);
        $mockConnection->shouldReceive('connect')->once();

        // Mock AMQPChannel constructor
        $mockChannelClass = Mockery::mock('overload:'.AMQPChannel::class);
        $mockChannelClass->shouldReceive('__construct')->andReturn($mockChannel);
        $mockChannel->shouldReceive('getChannelId')->twice(); // Once for get, once for close check
        $mockChannel->shouldReceive('close')->once();

        $poolManager = new PoolManager($this->config);

        $channel = $poolManager->getChannel();
        $poolManager->closeChannel($channel);

        // No exception should be thrown
        $this->assertTrue(true);
    }

    public function testClosesSpecificConnection(): void
    {
        $mockConnection = Mockery::mock(AMQPConnection::class);

        // Mock AMQPConnection constructor
        $mockConnectionClass = Mockery::mock('overload:'.AMQPConnection::class);
        $mockConnectionClass->shouldReceive('__construct')->andReturn($mockConnection);
        $mockConnection->shouldReceive('connect')->once();
        $mockConnection->shouldReceive('isConnected')->twice()->andReturn(true);
        $mockConnection->shouldReceive('disconnect')->once();

        $poolManager = new PoolManager($this->config);

        $connection = $poolManager->getConnection();
        $poolManager->closeConnection($connection);

        // No exception should be thrown
        $this->assertTrue(true);
    }

    public function testClosesAllPools(): void
    {
        $mockConnection = Mockery::mock(AMQPConnection::class);

        // Mock AMQPConnection constructor for min_connections
        $mockConnectionClass = Mockery::mock('overload:'.AMQPConnection::class);
        $mockConnectionClass->shouldReceive('__construct')->andReturn($mockConnection);
        $mockConnection->shouldReceive('connect')->twice(); // min_connections = 2
        $mockConnection->shouldReceive('disconnect')->twice();

        $poolManager = new PoolManager($this->config);
        $poolManager->closeAll();

        // No exception should be thrown
        $this->assertTrue(true);
    }

    public function testReturnsComprehensiveStats(): void
    {
        $mockConnection = Mockery::mock(AMQPConnection::class);

        // Mock AMQPConnection constructor for min_connections
        $mockConnectionClass = Mockery::mock('overload:'.AMQPConnection::class);
        $mockConnectionClass->shouldReceive('__construct')->andReturn($mockConnection);
        $mockConnection->shouldReceive('connect')->twice(); // min_connections = 2

        $poolManager = new PoolManager($this->config);
        $stats = $poolManager->getStats();

        $this->assertArrayHasKey('connection_pool', $stats);
        $this->assertArrayHasKey('channel_pool', $stats);
        $this->assertArrayHasKey('config', $stats);

        $this->assertEquals(10, $stats['config']['max_connections']);
        $this->assertEquals(2, $stats['config']['min_connections']);
        $this->assertEquals(100, $stats['config']['max_channels_per_connection']);
        $this->assertEquals(3, $stats['config']['max_retries']);
        $this->assertEquals(1000, $stats['config']['retry_delay']);
        $this->assertTrue($stats['config']['health_check_enabled']);
        $this->assertEquals(30, $stats['config']['health_check_interval']);
    }

    public function testChecksPoolHealth(): void
    {
        $mockConnection = Mockery::mock(AMQPConnection::class);

        // Mock AMQPConnection constructor for min_connections
        $mockConnectionClass = Mockery::mock('overload:'.AMQPConnection::class);
        $mockConnectionClass->shouldReceive('__construct')->andReturn($mockConnection);
        $mockConnection->shouldReceive('connect')->twice(); // min_connections = 2

        $poolManager = new PoolManager($this->config);
        $isHealthy = $poolManager->isHealthy();

        $this->assertTrue($isHealthy);
    }

    public function testDetectsUnhealthyPool(): void
    {
        $this->config['pool']['min_connections'] = 5;

        $mockConnection = Mockery::mock(AMQPConnection::class);

        // Mock AMQPConnection constructor but only create 2 connections (less than min)
        $mockConnectionClass = Mockery::mock('overload:'.AMQPConnection::class);
        $mockConnectionClass->shouldReceive('__construct')->times(2)->andReturn($mockConnection);
        $mockConnection->shouldReceive('connect')
            ->twice()
            ->andReturn(true)
            ->andThrow(new \Exception('Connection failed')); // Fail after 2 connections

        $poolManager = new PoolManager($this->config);
        $isHealthy = $poolManager->isHealthy();

        $this->assertFalse($isHealthy);
    }

    public function testProvidesAccessToIndividualPools(): void
    {
        $poolManager = new PoolManager($this->config);

        $this->assertInstanceOf(ConnectionFactory::class, $poolManager->getConnectionFactory());
        $this->assertInstanceOf(ConnectionPool::class, $poolManager->getConnectionPool());
        $this->assertInstanceOf(ChannelPool::class, $poolManager->getChannelPool());
    }
}
