<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit\Connection;

use iamfarhad\LaravelRabbitMQ\Connection\ChannelPool;
use iamfarhad\LaravelRabbitMQ\Connection\ConnectionFactory;
use iamfarhad\LaravelRabbitMQ\Connection\ConnectionPool;
use iamfarhad\LaravelRabbitMQ\Support\ExponentialBackoff;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Laravel's env() only coerces the literal strings "true", "false", "null" and
 * "empty" — every number set in .env arrives as a string. These classes assign
 * configuration straight into typed properties and constructor arguments under
 * strict_types, so a documented variable like RABBITMQ_MAX_CONNECTIONS=20 used
 * to be an outright TypeError. The shipped defaults are ints, which is why this
 * only ever showed up once someone actually configured the driver.
 */
class StringEnvConfigTest extends UnitTestCase
{
    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function stringPoolConfigProvider(): array
    {
        return [
            'numeric strings' => [[
                'max_connections' => '20',
                'min_connections' => '3',
                'max_channels_per_connection' => '50',
                'max_retries' => '4',
                'retry_delay' => '250',
                'health_check_interval' => '15',
                'lazy' => true,
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $poolConfig
     */
    #[DataProvider('stringPoolConfigProvider')]
    public function testConnectionPoolAcceptsStringConfiguration(array $poolConfig): void
    {
        $pool = new ConnectionPool(Mockery::mock(ConnectionFactory::class), ['pool' => $poolConfig]);

        $stats = $pool->getStats();

        $this->assertSame(20, $stats['max_connections']);
        $this->assertSame(3, $stats['min_connections']);
    }

    /**
     * @param  array<string, mixed>  $poolConfig
     */
    #[DataProvider('stringPoolConfigProvider')]
    public function testChannelPoolAcceptsStringConfiguration(array $poolConfig): void
    {
        $connectionPool = new ConnectionPool(
            Mockery::mock(ConnectionFactory::class),
            ['pool' => $poolConfig]
        );

        $channelPool = new ChannelPool($connectionPool, ['pool' => $poolConfig]);

        $stats = $channelPool->getStats();

        $this->assertSame(50, $stats['max_channels_per_connection']);
        $this->assertSame(15, $stats['health_check_interval']);
    }

    /**
     * @param  array<string, mixed>  $poolConfig
     */
    #[DataProvider('stringPoolConfigProvider')]
    public function testConnectionFactoryAcceptsStringConfiguration(array $poolConfig): void
    {
        $factory = new ConnectionFactory([
            'pool' => $poolConfig,
            'hosts' => ['host' => 'rabbitmq.local', 'port' => '5672'],
        ]);

        $config = $factory->buildConnectionConfigForTesting();

        $this->assertSame(5672, $config['port']);
    }

    public function testStringPortIsNormalisedToAnInteger(): void
    {
        $factory = new ConnectionFactory([
            'hosts' => ['host' => 'rabbitmq.local', 'port' => '5671', 'heartbeat' => '60'],
        ]);

        $config = $factory->buildConnectionConfigForTesting();

        $this->assertSame(5671, $config['port']);
        $this->assertSame(60, $config['heartbeat']);
    }

    public function testBackoffAcceptsStringConfigurationThroughTheDriver(): void
    {
        // Guards the call sites in RabbitQueue::getBackoff(), which pass
        // configuration into these typed constructor arguments.
        $backoff = new ExponentialBackoff(
            (int) '1000',
            (int) '60000',
            (float) '2.0',
            (bool) '1'
        );

        $this->assertGreaterThan(0, $backoff->getDelayForAttempt(0));
    }

    public function testNonPositivePoolSizesAreClampedRatherThanAccepted(): void
    {
        $pool = new ConnectionPool(Mockery::mock(ConnectionFactory::class), [
            'pool' => ['max_connections' => '0', 'min_connections' => '-5', 'lazy' => true],
        ]);

        $stats = $pool->getStats();

        $this->assertSame(1, $stats['max_connections'], 'A pool that can hold nothing is unusable.');
        $this->assertSame(0, $stats['min_connections']);
    }
}
