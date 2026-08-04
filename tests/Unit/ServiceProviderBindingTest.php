<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit;

use iamfarhad\LaravelRabbitMQ\Connectors\RabbitMQConnector;
use iamfarhad\LaravelRabbitMQ\LaravelRabbitQueueServiceProvider;
use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use Illuminate\Queue\Events\WorkerStopping;
use Orchestra\Testbench\TestCase;
use ReflectionProperty;

/**
 * Boots the package against a real container but never opens a socket: the
 * connection pool is lazy, so resolving a connection only builds objects.
 */
class ServiceProviderBindingTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelRabbitQueueServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('queue.default', 'rabbitmq');
        $app['config']->set('queue.connections.rabbitmq', [
            'driver' => 'rabbitmq',
            'queue' => 'default',
            'hosts' => ['host' => '127.0.0.1', 'lazy' => true],
            'pool' => ['lazy' => true, 'min_connections' => 0],
        ]);

        // A second, independently configured RabbitMQ connection.
        $app['config']->set('queue.connections.rabbitmq_analytics', [
            'driver' => 'rabbitmq',
            'queue' => 'analytics',
            'exchange' => 'analytics-events',
            'quorum' => true,
            'hosts' => ['host' => '127.0.0.1', 'lazy' => true],
            'pool' => ['lazy' => true, 'min_connections' => 0],
        ]);
    }

    protected function tearDown(): void
    {
        $property = new ReflectionProperty(RabbitMQConnector::class, 'poolManagers');
        $property->setValue(null, []);

        parent::tearDown();
    }

    /**
     * The RabbitMQ facade is auto-registered as an alias through composer.json,
     * but its accessor was never bound, so every call threw
     * BindingResolutionException.
     */
    public function testFacadeAccessorIsBound(): void
    {
        $this->assertTrue($this->app->bound(LaravelRabbitQueueServiceProvider::QUEUE_BINDING));
        $this->assertInstanceOf(
            RabbitQueue::class,
            $this->app->make(LaravelRabbitQueueServiceProvider::QUEUE_BINDING)
        );
    }

    public function testFacadeResolvesTheDefaultQueueConnectionWhenItIsRabbitMq(): void
    {
        $this->app['config']->set('queue.default', 'rabbitmq_analytics');

        $queue = $this->app->make(LaravelRabbitQueueServiceProvider::QUEUE_BINDING);

        $this->assertSame('rabbitmq_analytics', $queue->getConnectionName());
    }

    public function testFacadeFallsBackToTheRabbitmqConnectionForOtherDefaults(): void
    {
        $this->app['config']->set('queue.default', 'sync');

        $queue = $this->app->make(LaravelRabbitQueueServiceProvider::QUEUE_BINDING);

        $this->assertSame('rabbitmq', $queue->getConnectionName());
    }

    /**
     * Package defaults used to be merged into `queue.connections.rabbitmq` only,
     * so any additional RabbitMQ connection started with nothing at all.
     */
    public function testPackageDefaultsAreResolvableForEveryRabbitMqConnection(): void
    {
        foreach (['rabbitmq', 'rabbitmq_analytics'] as $name) {
            $queue = $this->app['queue']->connection($name);

            $this->assertIsArray(
                $queue->connectionConfig('pool'),
                "Connection [{$name}] should have pool defaults."
            );
            $this->assertNotNull(
                $queue->connectionConfig('failed.ownership'),
                "Connection [{$name}] should have failure-ownership defaults."
            );
            $this->assertNotNull(
                $queue->connectionConfig('delay_queue_granularity'),
                "Connection [{$name}] should have delay bucketing defaults."
            );
        }
    }

    public function testApplicationConfigurationWinsOverPackageDefaults(): void
    {
        $this->assertSame(
            'analytics-events',
            $this->app['queue']->connection('rabbitmq_analytics')->connectionConfig('exchange')
        );

        // Not set on this connection, so the package default (publish through
        // the default exchange) applies.
        $this->assertSame(
            '',
            $this->app['queue']->connection('rabbitmq')->connectionConfig('exchange')
        );
    }

    /**
     * Every topology and feature setting used to be read from the hardcoded
     * `queue.connections.rabbitmq` block, so a second connection silently
     * inherited the first one's exchange, quorum mode and job class.
     */
    public function testEachConnectionResolvesItsOwnConfiguration(): void
    {
        $default = $this->app['queue']->connection('rabbitmq');
        $analytics = $this->app['queue']->connection('rabbitmq_analytics');

        $this->assertSame('', (string) $default->connectionConfig('exchange'));
        $this->assertSame('analytics-events', $analytics->connectionConfig('exchange'));

        $this->assertFalse((bool) $default->connectionConfig('quorum'));
        $this->assertTrue((bool) $analytics->connectionConfig('quorum'));
    }

    public function testUnsetKeysFallBackToThePackageDefaultConnection(): void
    {
        $analytics = $this->app['queue']->connection('rabbitmq_analytics');

        // Not declared on the analytics connection; comes from the seeded
        // defaults rather than resolving to null.
        $this->assertSame(5, (int) $analytics->connectionConfig('publisher_confirms.timeout'));
    }

    /**
     * Distinct hosts/pool settings must not share a pool, but connect() runs on
     * every resolution and used to stack another cleanup listener each time.
     */
    public function testCleanupListenersAreRegisteredOnlyOncePerDispatcher(): void
    {
        $connector = new RabbitMQConnector($this->app['events']);
        $config = $this->app['config']->get('queue.connections.rabbitmq');
        $config['name'] = 'rabbitmq';

        for ($i = 0; $i < 5; $i++) {
            $connector->connect($config);
        }

        $listeners = $this->app['events']->getListeners(WorkerStopping::class);

        $this->assertCount(
            1,
            $listeners,
            'connect() runs on every queue-connection resolution; its listeners must not stack.'
        );
    }
}
