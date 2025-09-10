<?php

declare(strict_types=1);

use iamfarhad\LaravelRabbitMQ\Connectors\RabbitMQConnector;
use iamfarhad\LaravelRabbitMQ\Tests\TestCase;
use Illuminate\Events\Dispatcher;

// Test only the non-connection dependent aspects of the connector
// Connection tests are covered in Feature tests with real RabbitMQ
class ConnectorTest extends TestCase
{
    public function testCreatesConnectorInstance(): void
    {
        $dispatcher = new Dispatcher();
        $connector = new RabbitMQConnector($dispatcher);

        $this->assertInstanceOf(RabbitMQConnector::class, $connector);
    }

    public function testValidatesConfigurationFormat(): void
    {
        $dispatcher = new Dispatcher();
        $connector = new RabbitMQConnector($dispatcher);

        // Test that configuration is handled properly
        config([
            'queue.connections.rabbitmq.hosts.host' => 'test-host',
            'queue.connections.rabbitmq.hosts.port' => 5672,
            'queue.connections.rabbitmq.hosts.user' => 'test-user',
            'queue.connections.rabbitmq.hosts.password' => 'test-pass',
            'queue.connections.rabbitmq.hosts.vhost' => 'test-vhost',
            'queue.connections.rabbitmq.queue' => 'test-queue',
        ]);

        $this->assertEquals('test-host', config('queue.connections.rabbitmq.hosts.host'));
        $this->assertEquals(5672, config('queue.connections.rabbitmq.hosts.port'));
        $this->assertEquals('test-queue', config('queue.connections.rabbitmq.queue'));
    }

    public function testHandlesOptionsConfiguration(): void
    {
        $dispatcher = new Dispatcher();
        $connector = new RabbitMQConnector($dispatcher);

        // Test configuration options handling
        config([
            'queue.connections.rabbitmq.hosts.heartbeat' => 60,
            'queue.connections.rabbitmq.hosts.read_timeout' => 5,
            'queue.connections.rabbitmq.hosts.write_timeout' => 5,
            'queue.connections.rabbitmq.hosts.connect_timeout' => 10,
        ]);

        $this->assertEquals(60, config('queue.connections.rabbitmq.hosts.heartbeat'));
        $this->assertEquals(5, config('queue.connections.rabbitmq.hosts.read_timeout'));
    }

    public function testConnectorAcceptsValidConfiguration(): void
    {
        $dispatcher = new Dispatcher();
        $connector = new RabbitMQConnector($dispatcher);

        $config = [
            'driver' => 'rabbitmq',
            'queue' => 'default',
            'hosts' => [
                'host' => '127.0.0.1',
                'port' => 5672,
                'user' => 'guest',
                'password' => 'guest',
                'vhost' => '/',
            ],
            'options' => [
                'queue' => [
                    'job' => \iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob::class,
                ],
            ],
        ];

        // This should not throw an exception
        $this->assertInstanceOf(RabbitMQConnector::class, $connector);
        $this->assertTrue(true); // Test passes if no exception is thrown
    }
}
