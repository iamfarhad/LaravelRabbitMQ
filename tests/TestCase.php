<?php

namespace iamfarhad\LaravelRabbitMQ\Tests;

use iamfarhad\LaravelRabbitMQ\LaravelRabbitQueueServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Only check RabbitMQ connection for integration tests
        if ($this->shouldCheckRabbitMQConnection()) {
            $this->ensureRabbitMQConnection();
        }
    }

    protected function getPackageProviders($app): array
    {
        return [
            LaravelRabbitQueueServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Setup the application configuration
        $app['config']->set('queue.default', 'rabbitmq');
        $app['config']->set('queue.connections.rabbitmq', [
            'driver' => 'rabbitmq',
            'queue' => env('RABBITMQ_QUEUE', 'default'),
            'hosts' => [
                'host' => env('RABBITMQ_HOST', '127.0.0.1'),
                'port' => env('RABBITMQ_PORT', 5672),
                'user' => env('RABBITMQ_USER', 'laravel'),
                'password' => env('RABBITMQ_PASSWORD', 'secret'),
                'vhost' => env('RABBITMQ_VHOST', 'b2b-field'),
                'heartbeat' => env('RABBITMQ_HEARTBEAT', 0),
                'read_timeout' => env('RABBITMQ_READ_TIMEOUT', 3),
                'write_timeout' => env('RABBITMQ_WRITE_TIMEOUT', 3),
                'connect_timeout' => env('RABBITMQ_CONNECT_TIMEOUT', 5),
            ],
            'options' => [
                'queue' => [
                    'job' => \iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob::class,
                    'prefetch_count' => 10,
                ],
            ],
            'pool' => [
                'max_connections' => 50, // Increased for tests
                'min_connections' => 1,  // Reduced for tests
                'max_channels_per_connection' => 100,
                'max_retries' => 3,
                'retry_delay' => 1000,
                'health_check_enabled' => false, // Disabled for tests
                'health_check_interval' => 30,
            ],
        ]);
    }

    private function ensureRabbitMQConnection(): void
    {
        // AMQP extension is required and should be available
        // No fallback needed - tests should run with real RabbitMQ
    }

    protected function tearDown(): void
    {
        // Clean up any test queues - only if RabbitMQ connection is available
        try {
            $connection = \Queue::connection('rabbitmq');
            if ($connection instanceof \iamfarhad\LaravelRabbitMQ\RabbitQueue) {
                $testQueues = ['test-queue', 'priority-queue', 'size-test-queue', 'default'];
                foreach ($testQueues as $queue) {
                    $connection->purgeQueue($queue);
                }
            }
        } catch (\Exception $e) {
            // Ignore cleanup errors in tests
        }

        // Reset the static pool manager to prevent connection accumulation between tests
        try {
            $reflection = new \ReflectionClass(\iamfarhad\LaravelRabbitMQ\Connectors\RabbitMQConnector::class);
            $property = $reflection->getProperty('poolManager');
            $property->setAccessible(true);
            $poolManager = $property->getValue(null);
            if ($poolManager) {
                $poolManager->closeAll();
            }
            $property->setValue(null, null);
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }

        // Reset Mockery to prevent mocks from leaking between tests
        if (class_exists('Mockery')) {
            \Mockery::close();
        }

        parent::tearDown();
    }

    /**
     * Determine if this test should check RabbitMQ connection.
     */
    protected function shouldCheckRabbitMQConnection(): bool
    {
        // Only check RabbitMQ connection for Feature tests
        $reflection = new \ReflectionClass($this);
        $testPath = $reflection->getFileName();

        return str_contains($testPath, 'Feature');
    }
}
