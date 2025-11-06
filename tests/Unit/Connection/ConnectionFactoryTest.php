<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit\Connection;

use AMQPConnection;
use AMQPConnectionException;
use iamfarhad\LaravelRabbitMQ\Connection\ConnectionFactory;
use iamfarhad\LaravelRabbitMQ\Exceptions\ConnectionException;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Mockery;

class ConnectionFactoryTest extends UnitTestCase
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
                'heartbeat' => 0,
            ],
            'pool' => [
                'max_retries' => 3,
                'retry_delay' => 1000,
            ],
        ];
    }

    public function testCreatesConnectionFactorySuccessfully(): void
    {
        $factory = new ConnectionFactory($this->config);

        $this->assertInstanceOf(ConnectionFactory::class, $factory);
    }

    public function testBuildsConnectionConfigCorrectly(): void
    {
        $factory = new ConnectionFactory($this->config);

        // Use reflection to test private method
        $reflection = new \ReflectionClass($factory);
        $method = $reflection->getMethod('buildConnectionConfig');
        $method->setAccessible(true);

        $result = $method->invoke($factory);

        $this->assertEquals('localhost', $result['host']);
        $this->assertEquals(5672, $result['port']);
        $this->assertEquals('guest', $result['login']);
        $this->assertEquals('guest', $result['password']);
        $this->assertEquals('/', $result['vhost']);
    }

    public function testCreatesConnectionWithRetryOnFailure(): void
    {
        $this->config['pool']['max_retries'] = 2;
        $factory = new ConnectionFactory($this->config);

        // Mock AMQPConnection to fail first time, succeed second time
        $mockConnection = Mockery::mock('overload:'.AMQPConnection::class);
        $mockConnection->shouldReceive('__construct')->twice();
        $mockConnection->shouldReceive('connect')
            ->once()
            ->andThrow(new AMQPConnectionException('Connection failed'))
            ->once()
            ->andReturn(true);

        $connection = $factory->createConnection();

        $this->assertInstanceOf(AMQPConnection::class, $connection);
    }

    public function testThrowsExceptionAfterMaxRetries(): void
    {
        $this->config['pool']['max_retries'] = 2;
        $factory = new ConnectionFactory($this->config);

        // Mock AMQPConnection to always fail
        $mockConnection = Mockery::mock('overload:'.AMQPConnection::class);
        $mockConnection->shouldReceive('__construct')->twice();
        $mockConnection->shouldReceive('connect')
            ->twice()
            ->andThrow(new AMQPConnectionException('Connection failed'));

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Failed to connect to RabbitMQ after 2 attempts');

        $factory->createConnection();
    }

    public function testChecksConnectionHealth(): void
    {
        $factory = new ConnectionFactory($this->config);

        $mockConnection = Mockery::mock(AMQPConnection::class);
        $mockConnection->shouldReceive('isConnected')->once()->andReturn(true);

        $result = $factory->isConnectionAlive($mockConnection);

        $this->assertTrue($result);
    }

    public function testHandlesConnectionHealthCheckException(): void
    {
        $factory = new ConnectionFactory($this->config);

        $mockConnection = Mockery::mock(AMQPConnection::class);
        $mockConnection->shouldReceive('isConnected')
            ->once()
            ->andThrow(new \Exception('Connection error'));

        $result = $factory->isConnectionAlive($mockConnection);

        $this->assertFalse($result);
    }

    public function testClosesConnectionSafely(): void
    {
        $factory = new ConnectionFactory($this->config);

        $mockConnection = Mockery::mock(AMQPConnection::class);
        $mockConnection->shouldReceive('isConnected')->once()->andReturn(true);
        $mockConnection->shouldReceive('disconnect')->once();

        $factory->closeConnection($mockConnection);

        // No exception should be thrown
        $this->assertTrue(true);
    }

    public function testHandlesCloseConnectionException(): void
    {
        $factory = new ConnectionFactory($this->config);

        $mockConnection = Mockery::mock(AMQPConnection::class);
        $mockConnection->shouldReceive('isConnected')
            ->once()
            ->andThrow(new \Exception('Connection error'));

        $factory->closeConnection($mockConnection);

        // Should not throw exception
        $this->assertTrue(true);
    }
}
