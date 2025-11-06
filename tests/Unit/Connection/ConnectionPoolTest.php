<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit\Connection;

use AMQPConnection;
use iamfarhad\LaravelRabbitMQ\Connection\ConnectionFactory;
use iamfarhad\LaravelRabbitMQ\Connection\ConnectionPool;
use iamfarhad\LaravelRabbitMQ\Exceptions\ConnectionException;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Mockery;

class ConnectionPoolTest extends UnitTestCase
{
    private array $config;

    private ConnectionFactory $mockFactory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = [
            'pool' => [
                'max_connections' => 5,
                'min_connections' => 2,
                'health_check_enabled' => true,
                'health_check_interval' => 30,
            ],
        ];

        $this->mockFactory = Mockery::mock(ConnectionFactory::class);
    }

    public function testCreatesConnectionPoolSuccessfully(): void
    {
        $mockConnection1 = Mockery::mock(AMQPConnection::class);
        $mockConnection2 = Mockery::mock(AMQPConnection::class);

        // Expect calls during initialization (min_connections = 2)
        $this->mockFactory->shouldReceive('createConnection')
            ->times(2)
            ->andReturn($mockConnection1, $mockConnection2);

        $pool = new ConnectionPool($this->mockFactory, $this->config);

        $this->assertInstanceOf(ConnectionPool::class, $pool);
    }

    public function testInitializesMinimumConnections(): void
    {
        $mockConnection1 = Mockery::mock(AMQPConnection::class);
        $mockConnection2 = Mockery::mock(AMQPConnection::class);

        $this->mockFactory->shouldReceive('createConnection')
            ->twice()
            ->andReturn($mockConnection1, $mockConnection2);

        $pool = new ConnectionPool($this->mockFactory, $this->config);
        $stats = $pool->getStats();

        $this->assertEquals(2, $stats['available_connections']);
    }

    public function testGetsConnectionFromPool(): void
    {
        $mockConnection = Mockery::mock(AMQPConnection::class);

        $this->mockFactory->shouldReceive('createConnection')
            ->times(2) // min_connections
            ->andReturn($mockConnection);

        $this->mockFactory->shouldReceive('isConnectionAlive')
            ->atLeast()->once()
            ->with($mockConnection)
            ->andReturn(true);

        $pool = new ConnectionPool($this->mockFactory, $this->config);
        $connection = $pool->getConnection();

        $this->assertSame($mockConnection, $connection);
    }

    public function testCreatesNewConnectionWhenPoolEmpty(): void
    {
        $this->config['pool']['min_connections'] = 0;

        $mockConnection = Mockery::mock(AMQPConnection::class);

        $this->mockFactory->shouldReceive('createConnection')
            ->once()
            ->andReturn($mockConnection);

        $pool = new ConnectionPool($this->mockFactory, $this->config);
        $connection = $pool->getConnection();

        $this->assertSame($mockConnection, $connection);
    }

    public function testThrowsExceptionWhenMaxConnectionsReached(): void
    {
        $this->config['pool']['max_connections'] = 1;
        $this->config['pool']['min_connections'] = 0;

        $mockConnection = Mockery::mock(AMQPConnection::class);

        $this->mockFactory->shouldReceive('createConnection')
            ->once()
            ->andReturn($mockConnection);

        $pool = new ConnectionPool($this->mockFactory, $this->config);

        // Get first connection
        $connection1 = $pool->getConnection();

        // Try to get second connection (should fail)
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Connection pool exhausted');

        $pool->getConnection();
    }

    public function testReleasesConnectionBackToPool(): void
    {
        $mockConnection = Mockery::mock(AMQPConnection::class);

        $this->mockFactory->shouldReceive('createConnection')
            ->times(2) // min_connections
            ->andReturn($mockConnection);

        $this->mockFactory->shouldReceive('isConnectionAlive')
            ->atLeast()->times(2)
            ->with($mockConnection)
            ->andReturn(true);

        $pool = new ConnectionPool($this->mockFactory, $this->config);

        $connection = $pool->getConnection();
        $statsAfterGet = $pool->getStats();

        $pool->releaseConnection($connection);
        $statsAfterRelease = $pool->getStats();

        $this->assertEquals(1, $statsAfterGet['available_connections']);
        $this->assertEquals(2, $statsAfterRelease['available_connections']);
    }

    public function testClosesSpecificConnection(): void
    {
        $mockConnection = Mockery::mock(AMQPConnection::class);

        $this->mockFactory->shouldReceive('createConnection')
            ->times(2) // min_connections
            ->andReturn($mockConnection);

        $this->mockFactory->shouldReceive('isConnectionAlive')
            ->atLeast()->once()
            ->with($mockConnection)
            ->andReturn(true);

        $this->mockFactory->shouldReceive('closeConnection')
            ->once()
            ->with($mockConnection);

        $pool = new ConnectionPool($this->mockFactory, $this->config);

        $connection = $pool->getConnection();
        $pool->closeConnection($connection);

        $stats = $pool->getStats();
        $this->assertEquals(1, $stats['current_connections']);
    }

    public function testClosesAllConnections(): void
    {
        $mockConnection = Mockery::mock(AMQPConnection::class);

        $this->mockFactory->shouldReceive('createConnection')
            ->times(2) // min_connections
            ->andReturn($mockConnection);

        $this->mockFactory->shouldReceive('closeConnection')
            ->times(2)
            ->with($mockConnection);

        $pool = new ConnectionPool($this->mockFactory, $this->config);
        $pool->closeAll();

        $stats = $pool->getStats();
        $this->assertEquals(0, $stats['current_connections']);
        $this->assertEquals(0, $stats['available_connections']);
    }

    public function testReturnsCorrectStats(): void
    {
        $mockConnection = Mockery::mock(AMQPConnection::class);

        $this->mockFactory->shouldReceive('createConnection')
            ->times(2) // min_connections
            ->andReturn($mockConnection);

        $pool = new ConnectionPool($this->mockFactory, $this->config);
        $stats = $pool->getStats();

        $this->assertEquals(5, $stats['max_connections']);
        $this->assertEquals(2, $stats['min_connections']);
        $this->assertEquals(2, $stats['current_connections']);
        $this->assertEquals(0, $stats['active_connections']);
        $this->assertEquals(2, $stats['available_connections']);
        $this->assertTrue($stats['health_check_enabled']);
    }

    public function testPerformsHealthCheck(): void
    {
        $this->config['pool']['health_check_interval'] = 0; // Force immediate health check

        $mockConnection = Mockery::mock(AMQPConnection::class);

        $this->mockFactory->shouldReceive('createConnection')
            ->times(2) // min_connections
            ->andReturn($mockConnection);

        $this->mockFactory->shouldReceive('isConnectionAlive')
            ->times(3) // 2 for initial pool + 1 for health check
            ->with($mockConnection)
            ->andReturn(true, false, true); // Second connection is unhealthy

        $this->mockFactory->shouldReceive('closeConnection')
            ->once()
            ->with($mockConnection);

        $pool = new ConnectionPool($this->mockFactory, $this->config);

        // Trigger health check by getting a connection
        $connection = $pool->getConnection();

        $this->assertSame($mockConnection, $connection);
    }
}
