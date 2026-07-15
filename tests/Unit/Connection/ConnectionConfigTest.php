<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit\Connection;

use iamfarhad\LaravelRabbitMQ\Connection\ConnectionFactory;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use ReflectionClass;

class ConnectionConfigTest extends UnitTestCase
{
    public function testBuildsLegacySingleHostConnectionConfigWithTimeouts(): void
    {
        $factory = new ConnectionFactory([
            'hosts' => [
                'host' => 'rabbitmq.local',
                'port' => 5673,
                'user' => 'app',
                'password' => 'secret',
                'vhost' => 'production',
                'heartbeat' => 30,
                'read_timeout' => 5.5,
                'write_timeout' => 6,
                'connect_timeout' => 3,
            ],
        ]);

        $config = $this->invokeBuildConnectionConfig($factory);

        $this->assertSame('rabbitmq.local', $config['host']);
        $this->assertSame(5673, $config['port']);
        $this->assertSame('app', $config['login']);
        $this->assertSame('secret', $config['password']);
        $this->assertSame('production', $config['vhost']);
        $this->assertSame(30, $config['heartbeat']);
        $this->assertSame(5.5, $config['read_timeout']);
        $this->assertSame(6, $config['write_timeout']);
        $this->assertSame(3, $config['connect_timeout']);
    }

    public function testBuildsConnectionConfigFromMultiHostList(): void
    {
        $factory = new ConnectionFactory([
            'hosts' => [
                [
                    'host' => 'rabbitmq-a.local',
                    'port' => 5672,
                    'user' => 'app-a',
                    'password' => 'secret-a',
                    'vhost' => '/',
                ],
            ],
            'options' => [
                'read_timeout' => 10,
                'write_timeout' => 11,
                'connect_timeout' => 12,
            ],
        ]);

        $config = $this->invokeBuildConnectionConfig($factory);

        $this->assertSame('rabbitmq-a.local', $config['host']);
        $this->assertSame(5672, $config['port']);
        $this->assertSame('app-a', $config['login']);
        $this->assertSame('secret-a', $config['password']);
        $this->assertSame('/', $config['vhost']);
        $this->assertSame(10, $config['read_timeout']);
        $this->assertSame(11, $config['write_timeout']);
        $this->assertSame(12, $config['connect_timeout']);
    }

    public function testIgnoresNonPositiveOptionalConnectionValues(): void
    {
        $factory = new ConnectionFactory([
            'hosts' => [
                'heartbeat' => 0,
                'read_timeout' => -1,
                'write_timeout' => 0,
                'connect_timeout' => null,
            ],
        ]);

        $config = $this->invokeBuildConnectionConfig($factory);

        $this->assertArrayNotHasKey('heartbeat', $config);
        $this->assertArrayNotHasKey('read_timeout', $config);
        $this->assertArrayNotHasKey('write_timeout', $config);
        $this->assertArrayNotHasKey('connect_timeout', $config);
    }

    private function invokeBuildConnectionConfig(ConnectionFactory $factory): array
    {
        $reflection = new ReflectionClass($factory);

        $hostMethod = $reflection->getMethod('resolveHostConfigsForFailover');
        $hostMethod->setAccessible(true);
        $hostConfig = $hostMethod->invoke($factory)[0];

        $method = $reflection->getMethod('buildConnectionConfig');
        $method->setAccessible(true);

        return $method->invoke($factory, $hostConfig);
    }
}
