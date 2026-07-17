<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit\Connection;

use AMQPChannel;
use AMQPConnection;
use AMQPConnectionException;
use iamfarhad\LaravelRabbitMQ\Connection\ChannelPool;
use iamfarhad\LaravelRabbitMQ\Connection\ConnectionFactory;
use iamfarhad\LaravelRabbitMQ\Connection\ConnectionPool;
use iamfarhad\LaravelRabbitMQ\Connection\PoolManager;
use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Each test runs in its own process because Mockery `overload:` mocks can
 * only be defined once per process. Expectations are set on the overload
 * template, which applies them to every instance the pool creates.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
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

    /**
     * Overload template applied to every `new AMQPConnection()` the pool makes.
     */
    private function mockConnectionTemplate(): MockInterface
    {
        $template = Mockery::mock('overload:'.AMQPConnection::class);
        $template->shouldReceive('__construct');
        $template->shouldReceive('connect');
        $template->shouldReceive('isConnected')->andReturn(true);
        $template->shouldReceive('disconnect');

        return $template;
    }

    /**
     * Overload template applied to every `new AMQPChannel()` the pool makes.
     */
    private function mockChannelTemplate(): MockInterface
    {
        // A plain Mockery mock of AMQPConnection would collide with the
        // overload template's generated class, so use a simple stand-in.
        $connectionForChannel = new class
        {
            public function isConnected(): bool
            {
                return true;
            }
        };

        $template = Mockery::mock('overload:'.AMQPChannel::class);
        $template->shouldReceive('__construct');
        $template->shouldReceive('isConnected')->andReturn(true);
        $template->shouldReceive('getConnection')->andReturn($connectionForChannel);
        $template->shouldReceive('close');

        return $template;
    }

    public function testCreatesPoolManagerSuccessfully(): void
    {
        $this->mockConnectionTemplate();

        $poolManager = new PoolManager($this->config);

        $this->assertInstanceOf(PoolManager::class, $poolManager);
    }

    public function testGetsChannelFromPool(): void
    {
        $this->mockConnectionTemplate();
        $this->mockChannelTemplate();

        $poolManager = new PoolManager($this->config);
        $channel = $poolManager->getChannel();

        $this->assertInstanceOf(AMQPChannel::class, $channel);
    }

    public function testReleasesChannelToPool(): void
    {
        $this->mockConnectionTemplate();
        $this->mockChannelTemplate();

        $poolManager = new PoolManager($this->config);

        $channel = $poolManager->getChannel();
        $poolManager->releaseChannel($channel);

        $stats = $poolManager->getStats();
        $this->assertEquals(0, $stats['channel_pool']['active_channels']);
        $this->assertEquals(1, $stats['channel_pool']['available_channels']);
    }

    public function testGetsConnectionFromPool(): void
    {
        $this->mockConnectionTemplate();

        $poolManager = new PoolManager($this->config);
        $connection = $poolManager->getConnection();

        $this->assertInstanceOf(AMQPConnection::class, $connection);
    }

    public function testReleasesConnectionToPool(): void
    {
        $this->mockConnectionTemplate();

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
        $this->mockConnectionTemplate();
        $this->mockChannelTemplate();

        $poolManager = new PoolManager($this->config);

        $channel = $poolManager->getChannel();
        $poolManager->closeChannel($channel);

        $stats = $poolManager->getStats();
        $this->assertEquals(0, $stats['channel_pool']['current_channels']);
    }

    public function testClosesSpecificConnection(): void
    {
        $this->mockConnectionTemplate();

        $poolManager = new PoolManager($this->config);

        $connection = $poolManager->getConnection();
        $poolManager->closeConnection($connection);

        $stats = $poolManager->getStats();
        $this->assertEquals(1, $stats['connection_pool']['current_connections']);
    }

    public function testClosesAllPools(): void
    {
        $this->mockConnectionTemplate();

        $poolManager = new PoolManager($this->config);
        $poolManager->closeAll();

        $stats = $poolManager->getStats();
        $this->assertEquals(0, $stats['connection_pool']['current_connections']);
        $this->assertEquals(0, $stats['channel_pool']['current_channels']);
    }

    public function testReturnsComprehensiveStats(): void
    {
        $this->mockConnectionTemplate();

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
        $this->mockConnectionTemplate();

        $poolManager = new PoolManager($this->config);
        $isHealthy = $poolManager->isHealthy();

        $this->assertTrue($isHealthy);
    }

    public function testDetectsUnhealthyPool(): void
    {
        $this->config['pool']['min_connections'] = 5;
        $this->config['pool']['retry_delay'] = 1; // keep the failing retries fast

        // Only the first two connection attempts succeed, leaving the pool
        // below its minimum size.
        $template = Mockery::mock('overload:'.AMQPConnection::class);
        $template->shouldReceive('__construct');
        $template->shouldReceive('connect')->andReturnUsing(function (): void {
            static $attempts = 0;
            if (++$attempts > 2) {
                throw new AMQPConnectionException('Connection failed');
            }
        });

        $poolManager = new PoolManager($this->config);
        $isHealthy = $poolManager->isHealthy();

        $this->assertFalse($isHealthy);
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
