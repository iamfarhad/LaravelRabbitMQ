<?php

declare(strict_types=1);

use AMQPConnection;
use iamfarhad\LaravelRabbitMQ\Connection\ConnectionFactory;
use iamfarhad\LaravelRabbitMQ\Connection\ConnectionPool;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Mockery;

class ConnectionPoolTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfAmqpExtensionLoaded();
    }

    public function testLazyPoolDoesNotCreateMinimumConnectionsOnConstruction(): void
    {
        $factory = Mockery::mock(ConnectionFactory::class);
        $factory->shouldNotReceive('createConnection');

        $pool = new ConnectionPool($factory, [
            'hosts' => [
                'lazy' => true,
            ],
            'pool' => [
                'min_connections' => 2,
                'max_connections' => 5,
                'lazy' => true,
                'health_check_enabled' => false,
            ],
        ]);

        $stats = $pool->getStats();

        $this->assertTrue($stats['lazy']);
        $this->assertSame(0, $stats['current_connections']);
        $this->assertSame(0, $stats['available_connections']);
    }

    public function testNonLazyPoolCreatesMinimumConnectionsOnConstruction(): void
    {
        $connection = Mockery::mock(AMQPConnection::class);
        $factory = Mockery::mock(ConnectionFactory::class);
        $factory->shouldReceive('createConnection')->twice()->andReturn($connection);

        $pool = new ConnectionPool($factory, [
            'hosts' => [
                'lazy' => false,
            ],
            'pool' => [
                'min_connections' => 2,
                'max_connections' => 5,
                'lazy' => false,
                'health_check_enabled' => false,
            ],
        ]);

        $stats = $pool->getStats();

        $this->assertFalse($stats['lazy']);
        $this->assertSame(2, $stats['current_connections']);
        $this->assertSame(2, $stats['available_connections']);
    }
}
