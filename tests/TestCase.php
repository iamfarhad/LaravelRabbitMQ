<?php

namespace iamfarhad\LaravelRabbitMQ\Tests;

use iamfarhad\LaravelRabbitMQ\LaravelRabbitQueueServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureAMQPExtension();

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
        ]);
    }

    private function ensureAMQPExtension(): void
    {
        if (! extension_loaded('amqp')) {
            $this->markTestSkipped('AMQP extension is not loaded');
        }
    }

    private function ensureRabbitMQConnection(): void
    {
        $maxAttempts = 5;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            try {
                $connection = \Queue::connection('rabbitmq');
                // Try to get queue size to test connection
                $connection->size('test-connection');
                break;
            } catch (\Exception $e) {
                $attempt++;
                if ($attempt >= $maxAttempts) {
                    $this->markTestSkipped('RabbitMQ connection not available: '.$e->getMessage());
                }
                sleep(1);
            }
        }
    }

    protected function tearDown(): void
    {
        // Clean up any test queues
        try {
            $testQueues = ['test-queue', 'priority-queue', 'size-test-queue', 'default'];
            foreach ($testQueues as $queue) {
                \Queue::connection('rabbitmq')->purgeQueue($queue);
            }
        } catch (\Exception $e) {
            // Ignore cleanup errors in tests
        }

        parent::tearDown();
    }

    /**
     * Determine if this test should check RabbitMQ connection.
     */
    protected function shouldCheckRabbitMQConnection(): bool
    {
        // Check if this is a Feature test or a test that actually needs RabbitMQ
        $reflection = new \ReflectionClass($this);
        $testPath = $reflection->getFileName();

        // Skip RabbitMQ connection check for unit tests that don't need it
        return str_contains($testPath, 'Feature') ||
            str_contains($testPath, 'ConnectorTest') ||
            str_contains($testPath, 'RabbitQueueTest') ||
            str_contains($testPath, 'ConsumerTest') ||
            str_contains($testPath, 'RabbitMQJobTest');
    }
}
