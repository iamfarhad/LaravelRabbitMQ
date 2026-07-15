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

    public function testShowsNoPoolManagerMessageWhenNoneActive(): void
    {
        // Use reflection to clear the static poolManagers map
        $reflection = new \ReflectionClass(RabbitMQConnector::class);
        $property = $reflection->getProperty('poolManagers');
        $property->setAccessible(true);
        $property->setValue(null, []);

        $this->artisan('rabbitmq:pool-stats')
            ->expectsOutput('No active RabbitMQ pool manager found. Make sure a RabbitMQ connection is active.')
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

    public function testDisplaysWarningStatusWhenPoolUnhealthy(): void
    {
        $mockPoolManager = Mockery::mock(PoolManager::class);

        $mockStats = [
            'connection_pool' => [
                'max_connections' => 10,
                'min_connections' => 2,
                'current_connections' => 1, // Below minimum
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
            ->expectsOutput('🟡 Pool Status: Warning - Check connection count')
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
