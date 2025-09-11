<?php

namespace iamfarhad\LaravelRabbitMQ;

use iamfarhad\LaravelRabbitMQ\Connectors\RabbitMQConnector;
use iamfarhad\LaravelRabbitMQ\Console\ConsumeCommand;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\ServiceProvider;

final class LaravelRabbitQueueServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/rabbitmq.php',
            'queue.connections.rabbitmq'
        );

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
            ]);
        }
    }

    public function boot(): void
    {
        /** @var \Illuminate\Queue\QueueManager $queue */
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
}
