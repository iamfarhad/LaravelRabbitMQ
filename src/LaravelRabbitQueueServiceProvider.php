<?php

namespace iamfarhad\LaravelRabbitMQ;

use iamfarhad\LaravelRabbitMQ\Connectors\RabbitMQConnector;
use iamfarhad\LaravelRabbitMQ\Console\ConsumeCommand;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\ServiceProvider;

final class LaravelRabbitQueueServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../Config/RabbitMQConnectionConfig.php',
            'queue.connections.rabbitmq'
        );

        if ($this->app->runningInConsole()) {
            $this->app->singleton('rabbitmq.consumer', function (): \iamfarhad\LaravelRabbitMQ\Consumer {
                $isDownForMaintenance = fn(): bool => $this->app->isDownForMaintenance();

                return new Consumer(
                    $this->app['queue'],
                    $this->app['events'],
                    $this->app[ExceptionHandler::class],
                    $isDownForMaintenance
                );
            });

            $this->app->singleton(ConsumeCommand::class, static fn($app): \iamfarhad\LaravelRabbitMQ\Console\ConsumeCommand => new ConsumeCommand(
                $app['rabbitmq.consumer'],
                $app['cache.store']
            ));

            $this->commands([
                ConsumeCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        /** @var QueueManager $queue */
        $queue = $this->app['queue'];

        $queue->addConnector('rabbitmq', fn(): \iamfarhad\LaravelRabbitMQ\Connectors\RabbitMQConnector => new RabbitMQConnector($this->app['events']));
    }
}
