<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit\Console\Commands;

use iamfarhad\LaravelRabbitMQ\Connection\PoolManager;
use iamfarhad\LaravelRabbitMQ\Connectors\RabbitMQConnector;
use iamfarhad\LaravelRabbitMQ\Console\Commands\PoolStatsCommand;
use iamfarhad\LaravelRabbitMQ\Tests\TestCase;
use Mockery;

class PoolStatsCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        // Reset the static pool managers after each test
        try {
            $reflection = new \ReflectionClass(RabbitMQConnector::class);
            $property = $reflection->getProperty('poolManagers');
            $property->setAccessible(true);
            $property->setValue(null, []);
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }

        parent::tearDown();
    }

    /**
     * Pools are per-process, so a fresh artisan process has none — the command
     * used to do nothing but say so. It now resolves the queue connection to
     * bring a pool into existence and reports on that.
     */
    public function testResolvesAPoolWhenTheProcessHasNoneYet(): void
    {
        $reflection = new \ReflectionClass(RabbitMQConnector::class);
        $property = $reflection->getProperty('poolManagers');
        $property->setValue(null, []);

        $this->artisan('rabbitmq:pool-stats')
            ->expectsOutput('📡 Connection Pool')
            ->expectsOutput('Pools are per-process: these numbers describe this artisan process, not your workers.')
            ->assertExitCode(0);
    }

    public function testFailsForAConnectionThatIsNotRabbitMq(): void
    {
        $reflection = new \ReflectionClass(RabbitMQConnector::class);
        $property = $reflection->getProperty('poolManagers');
        $property->setValue(null, []);

        config(['queue.connections.not-rabbit' => ['driver' => 'sync']]);

        $this->artisan('rabbitmq:pool-stats', ['connection' => 'not-rabbit'])
            ->expectsOutput('Queue connection [not-rabbit] is not a RabbitMQ connection.')
            ->assertExitCode(1);
    }

    public function testFailsForAnUnknownConnection(): void
    {
        $reflection = new \ReflectionClass(RabbitMQConnector::class);
        $property = $reflection->getProperty('poolManagers');
        $property->setValue(null, []);

        $this->artisan('rabbitmq:pool-stats', ['connection' => 'does-not-exist'])
            ->assertExitCode(1);
    }

    public function testDisplaysFormattedStatsWhenPoolManagerActive(): void
    {
        $mockPoolManager = Mockery::mock(PoolManager::class);

        $mockStats = [
            'connection_pool' => [
                'max_connections' => 10,
                'min_connections' => 2,
                'current_connections' => 3,
                'active_connections' => 1,
                'available_connections' => 2,
                'health_check_enabled' => true,
                'last_health_check' => time(),
            ],
            'channel_pool' => [
                'max_channels_per_connection' => 100,
                'current_channels' => 5,
                'active_channels' => 2,
                'available_channels' => 3,
                'health_check_enabled' => true,
                'last_health_check' => time(),
            ],
            'config' => [
                'max_connections' => 10,
                'min_connections' => 2,
                'max_channels_per_connection' => 100,
                'max_retries' => 3,
                'retry_delay' => 1000,
                'health_check_enabled' => true,
                'health_check_interval' => 30,
            ],
        ];

        $mockPoolManager->shouldReceive('getStats')
            ->once()
            ->andReturn($mockStats);

        $mockPoolManager->shouldReceive('isHealthy')
            ->once()
            ->andReturn(true);

        // Use reflection to set the static poolManagers map
        $reflection = new \ReflectionClass(RabbitMQConnector::class);
        $property = $reflection->getProperty('poolManagers');
        $property->setAccessible(true);
        $property->setValue(null, ['test' => $mockPoolManager]);

        $this->artisan('rabbitmq:pool-stats')
            ->expectsOutput('📡 Connection Pool')
            ->expectsOutput('├─ Max Connections: 10')
            ->expectsOutput('├─ Current Connections: 3')
            ->expectsOutput('🔀 Channel Pool')
            ->expectsOutput('├─ Current Channels: 5')
            ->expectsOutput('⚙️ Configuration')
            ->expectsOutput('├─ Max Retries: 3')
            ->expectsOutput('🟢 Pool Status: Healthy')
            ->assertExitCode(0);
    }

    /**
     * "Unhealthy" now means the pool is exhausted — every connection checked out
     * and no room to open another. It used to mean "holds fewer than
     * min_connections", which reported every idle lazy pool as unhealthy.
     */
    public function testDisplaysWarningStatusWhenPoolExhausted(): void
    {
        $mockPoolManager = Mockery::mock(PoolManager::class);

        $mockStats = [
            'connection_pool' => [
                'max_connections' => 10,
                'min_connections' => 2,
                'current_connections' => 10, // At capacity, none idle
                'active_connections' => 1,
                'available_connections' => 0,
                'health_check_enabled' => true,
                'last_health_check' => time(),
            ],
            'channel_pool' => [
                'max_channels_per_connection' => 100,
                'current_channels' => 1,
                'active_channels' => 1,
                'available_channels' => 0,
                'health_check_enabled' => true,
                'last_health_check' => time(),
            ],
            'config' => [
                'max_connections' => 10,
                'min_connections' => 2,
                'max_channels_per_connection' => 100,
                'max_retries' => 3,
                'retry_delay' => 1000,
                'health_check_enabled' => true,
                'health_check_interval' => 30,
            ],
        ];

        $mockPoolManager->shouldReceive('getStats')
            ->once()
            ->andReturn($mockStats);

        $mockPoolManager->shouldReceive('isHealthy')
            ->once()
            ->andReturn(false);

        // Use reflection to set the static poolManagers map
        $reflection = new \ReflectionClass(RabbitMQConnector::class);
        $property = $reflection->getProperty('poolManagers');
        $property->setAccessible(true);
        $property->setValue(null, ['test' => $mockPoolManager]);

        $this->artisan('rabbitmq:pool-stats')
            ->expectsOutput('🟡 Pool Status: Exhausted - every connection is checked out and the pool is at max_connections')
            ->assertExitCode(0);
    }

    public function testOutputsJsonFormatWhenRequested(): void
    {
        $mockPoolManager = Mockery::mock(PoolManager::class);

        $mockStats = [
            'connection_pool' => [
                'max_connections' => 10,
                'current_connections' => 3,
            ],
            'channel_pool' => [
                'current_channels' => 5,
            ],
            'config' => [
                'max_retries' => 3,
            ],
        ];

        $mockPoolManager->shouldReceive('getStats')
            ->once()
            ->andReturn($mockStats);

        // Use reflection to set the static poolManagers map
        $reflection = new \ReflectionClass(RabbitMQConnector::class);
        $property = $reflection->getProperty('poolManagers');
        $property->setAccessible(true);
        $property->setValue(null, ['test' => $mockPoolManager]);

        $expectedJson = json_encode($mockStats, JSON_PRETTY_PRINT);

        $this->artisan('rabbitmq:pool-stats --json')
            ->expectsOutput($expectedJson)
            ->assertExitCode(0);
    }

    public function testCommandSignatureIncludesAllOptions(): void
    {
        $command = new PoolStatsCommand;

        $this->assertStringContainsString('--json', $command->getDefinition()->getSynopsis());
        $this->assertStringContainsString('--watch', $command->getDefinition()->getSynopsis());
        $this->assertStringContainsString('--interval', $command->getDefinition()->getSynopsis());
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the static method calls
        Mockery::getConfiguration()->allowMockingNonExistentMethods(true);
    }
}
