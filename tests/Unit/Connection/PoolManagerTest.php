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

/**
 * Exercises the pool against a real broker: PoolManager builds its own
 * collaborators, and real pooling behaviour (liveness, multiplexing, exhaustion)
 * is exactly what these assertions are about.
 *
 * Connection details come from the same environment variables as the rest of the
 * suite, so this runs wherever a broker is available.
 */
class PoolManagerTest extends UnitTestCase
{
    private array $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = [
            'hosts' => [
                'host' => env('RABBITMQ_HOST', '127.0.0.1'),
                'port' => (int) env('RABBITMQ_PORT', 5672),
                'user' => env('RABBITMQ_USER', 'guest'),
                'password' => env('RABBITMQ_PASSWORD', 'guest'),
                'vhost' => env('RABBITMQ_VHOST', '/'),
            ],
            'pool' => [
                'max_connections' => 10,
                'min_connections' => 2,
                // Eager initialisation is opt-in since 1.5; the bookkeeping
                // tests below are written against a pre-warmed pool.
                'lazy' => false,
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

        $poolManager = new PoolManager($this->config);
        $channel = $poolManager->getChannel();

        $this->assertInstanceOf(AMQPChannel::class, $channel);
    }

    public function testReleasesChannelToPool(): void
    {

        $poolManager = new PoolManager($this->config);

        $channel = $poolManager->getChannel();
        $poolManager->releaseChannel($channel);

        $stats = $poolManager->getStats();
        $this->assertEquals(0, $stats['channel_pool']['active_channels']);
        $this->assertEquals(1, $stats['channel_pool']['available_channels']);
    }

    public function testGetsConnectionFromPool(): void
    {

        $poolManager = new PoolManager($this->config);
        $connection = $poolManager->getConnection();

        $this->assertInstanceOf(AMQPConnection::class, $connection);
    }

    public function testReleasesConnectionToPool(): void
    {

        $poolManager = new PoolManager($this->config);

        $connection = $poolManager->getConnection();
        $statsAfterGet = $poolManager->getStats();

        $poolManager->releaseConnection($connection);
        $statsAfterRelease = $poolManager->getStats();

        $this->assertEquals(1, $statsAfterGet['connection_pool']['available_connections']);
        $this->assertEquals(2, $statsAfterRelease['connection_pool']['available_connections']);
    }

    public function testClosesSpecificChannel(): void
    {

        $poolManager = new PoolManager($this->config);

        $channel = $poolManager->getChannel();
        $poolManager->closeChannel($channel);

        $stats = $poolManager->getStats();
        $this->assertEquals(0, $stats['channel_pool']['current_channels']);
    }

    public function testClosesSpecificConnection(): void
    {

        $poolManager = new PoolManager($this->config);

        $connection = $poolManager->getConnection();
        $poolManager->closeConnection($connection);

        $stats = $poolManager->getStats();
        $this->assertEquals(1, $stats['connection_pool']['current_connections']);
    }

    public function testClosesAllPools(): void
    {

        $poolManager = new PoolManager($this->config);
        $poolManager->closeAll();

        $stats = $poolManager->getStats();
        $this->assertEquals(0, $stats['connection_pool']['current_connections']);
        $this->assertEquals(0, $stats['channel_pool']['current_channels']);
    }

    public function testReturnsComprehensiveStats(): void
    {

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

        $poolManager = new PoolManager($this->config);
        $isHealthy = $poolManager->isHealthy();

        $this->assertTrue($isHealthy);
    }

    public function testDetectsUnhealthyPool(): void
    {
        // A pool is unhealthy when it can neither hand out an idle connection
        // nor open another one. Sitting below min_connections is not a fault on
        // its own — that is the normal resting state of a lazy pool.
        $this->config['pool']['max_connections'] = 1;
        $this->config['pool']['min_connections'] = 0;
        $this->config['pool']['lazy'] = true;

        $poolManager = new PoolManager($this->config);

        $this->assertTrue($poolManager->isHealthy(), 'An empty lazy pool can still open a connection.');

        // Check the only permitted connection out and never return it.
        $poolManager->getConnection();

        $this->assertFalse($poolManager->isHealthy());
    }

    public function testLazyPoolBelowMinimumConnectionsIsStillHealthy(): void
    {
        $this->config['pool']['min_connections'] = 5;
        $this->config['pool']['lazy'] = true;

        $poolManager = new PoolManager($this->config);

        $this->assertSame(0, $poolManager->getStats()['connection_pool']['current_connections']);
        $this->assertTrue($poolManager->isHealthy());
    }

    public function testProvidesAccessToIndividualPools(): void
    {
        $this->config['pool']['min_connections'] = 0; // avoid eager connections

        $poolManager = new PoolManager($this->config);

        $this->assertInstanceOf(ConnectionFactory::class, $poolManager->getConnectionFactory());
        $this->assertInstanceOf(ConnectionPool::class, $poolManager->getConnectionPool());
        $this->assertInstanceOf(ChannelPool::class, $poolManager->getChannelPool());
    }
}
