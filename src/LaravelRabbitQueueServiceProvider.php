<?php

namespace iamfarhad\LaravelRabbitMQ;

use iamfarhad\LaravelRabbitMQ\Connectors\RabbitMQConnector;
use iamfarhad\LaravelRabbitMQ\Console\Commands\ExchangeDeclareCommand;
use iamfarhad\LaravelRabbitMQ\Console\Commands\PoolStatsCommand;
use iamfarhad\LaravelRabbitMQ\Console\Commands\QueueDeclareCommand;
use iamfarhad\LaravelRabbitMQ\Console\Commands\QueueDeleteCommand;
use iamfarhad\LaravelRabbitMQ\Console\Commands\QueuePurgeCommand;
use iamfarhad\LaravelRabbitMQ\Console\ConsumeCommand;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\ServiceProvider;

final class LaravelRabbitQueueServiceProvider extends ServiceProvider
{
    /**
     * Container key backing the RabbitMQ facade.
     */
    public const QUEUE_BINDING = 'rabbitmq.queue';

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/rabbitmq.php',
            'rabbitmq'
        );

        $this->configureRabbitMqConnections();
        $this->registerFacadeBinding();

        if ($this->app->runningInConsole()) {
            $this->app->singleton('rabbitmq.consumer', function ($app): Consumer {
                $isDownForMaintenance = fn (): bool => $app->isDownForMaintenance();

                return new Consumer(
                    $app['queue'],
                    $app['events'],
                    $app[ExceptionHandler::class],
                    $isDownForMaintenance
                );
            });

            $this->app->singleton(ConsumeCommand::class, static function ($app): ConsumeCommand {
                return new ConsumeCommand(
                    $app['rabbitmq.consumer'],
                    $app['cache.store']
                );
            });

            $this->commands([
                ConsumeCommand::class,
                PoolStatsCommand::class,
                ExchangeDeclareCommand::class,
                QueueDeclareCommand::class,
                QueuePurgeCommand::class,
                QueueDeleteCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        /** @var QueueManager $queue */
        $queue = $this->app['queue'];
        $queue->addConnector('rabbitmq', function () {
            return new RabbitMQConnector($this->app['events']);
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/rabbitmq.php' => config_path('rabbitmq.php'),
            ], 'config');
        }
    }

    /**
     * Back the RabbitMQ facade with the application's RabbitMQ queue connection.
     *
     * Without this binding the facade — auto-registered as an alias through
     * composer.json — resolved nothing and every call threw
     * BindingResolutionException.
     */
    private function registerFacadeBinding(): void
    {
        $this->app->bind(self::QUEUE_BINDING, function ($app) {
            return $app['queue']->connection($this->resolveRabbitMqConnectionName($app));
        });
    }

    /**
     * Prefer the application's default queue connection when it is a RabbitMQ
     * connection, otherwise fall back to the connection literally named
     * `rabbitmq`.
     */
    private function resolveRabbitMqConnectionName(Application $app): string
    {
        $default = (string) $app['config']->get('queue.default', '');

        if ($default !== '' && $app['config']->get("queue.connections.{$default}.driver") === 'rabbitmq') {
            return $default;
        }

        return 'rabbitmq';
    }

    /**
     * Seed the package defaults into every RabbitMQ queue connection.
     *
     * Previously only `queue.connections.rabbitmq` was seeded, so any second
     * named RabbitMQ connection started with no defaults at all.
     */
    private function configureRabbitMqConnections(): void
    {
        $config = $this->app['config'];
        $defaults = (array) $config->get('rabbitmq', []);

        foreach ($this->rabbitMqConnectionNames() as $name) {
            $existing = (array) $config->get("queue.connections.{$name}", []);

            $config->set("queue.connections.{$name}", array_merge($defaults, $existing));
        }
    }

    /**
     * @return list<string>
     */
    private function rabbitMqConnectionNames(): array
    {
        // The package's own default connection is always seeded, even when the
        // application never declared it.
        $names = ['rabbitmq'];

        foreach ((array) $this->app['config']->get('queue.connections', []) as $name => $connection) {
            if (is_array($connection) && ($connection['driver'] ?? null) === 'rabbitmq') {
                $names[] = (string) $name;
            }
        }

        return array_values(array_unique($names));
    }
}
