<?php

declare(strict_types=1);

use iamfarhad\LaravelRabbitMQ\Connection\ConnectionFactory;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;

class ConnectionFactoryTest extends UnitTestCase
{
    public function testBuildsDefaultTcpConnectionConfig(): void
    {
        $factory = new ConnectionFactory([
            'hosts' => [
                'host' => 'rabbitmq.local',
                'user' => 'laravel',
                'password' => 'secret',
                'vhost' => 'app',
            ],
        ]);

        $config = $factory->buildConnectionConfigForTesting();

        $this->assertSame('rabbitmq.local', $config['host']);
        $this->assertSame(5672, $config['port']);
        $this->assertSame('laravel', $config['login']);
        $this->assertSame('secret', $config['password']);
        $this->assertSame('app', $config['vhost']);
        $this->assertArrayNotHasKey('ssl', $config);
    }

    public function testSecureHostMapsToSslTransport(): void
    {
        $factory = new ConnectionFactory([
            'hosts' => [
                'host' => 'rabbitmq.local',
                'secure' => true,
            ],
        ]);

        $config = $factory->buildConnectionConfigForTesting();

        $this->assertSame(5671, $config['port']);
        $this->assertTrue($config['ssl']);

        // connection_name is the label RabbitMQ shows in the management UI, not
        // the transport: overwriting it with "ssl" made every TLS connection
        // anonymous there.
        $this->assertArrayNotHasKey('connection_name', $config);
    }

    public function testConfiguredConnectionNameIsUsedAsTheBrokerSideLabel(): void
    {
        $factory = new ConnectionFactory([
            'connection_name' => 'checkout-api',
            'hosts' => [
                'host' => 'rabbitmq.local',
                'secure' => true,
            ],
        ]);

        $config = $factory->buildConnectionConfigForTesting();

        $this->assertSame('checkout-api', $config['connection_name']);
    }
}
