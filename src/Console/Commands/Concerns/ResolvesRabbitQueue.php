<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Console\Commands\Concerns;

use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use Illuminate\Support\Facades\Queue;
use Throwable;

trait ResolvesRabbitQueue
{
    /**
     * Resolve the RabbitMQ queue connection this command should act on.
     *
     * Honours a `--connection` option so these commands work against any named
     * RabbitMQ connection instead of only the one literally called `rabbitmq`.
     */
    protected function resolveRabbitQueue(): ?RabbitQueue
    {
        $name = $this->rabbitConnectionName();

        try {
            $connection = Queue::connection($name);
        } catch (Throwable $exception) {
            $this->error(sprintf('Could not resolve queue connection [%s]: %s', $name, $exception->getMessage()));

            return null;
        }

        if (! $connection instanceof RabbitQueue) {
            $this->error(sprintf('Queue connection [%s] is not a RabbitMQ connection.', $name));

            return null;
        }

        return $connection;
    }

    private function rabbitConnectionName(): string
    {
        $option = $this->hasOption('connection') ? $this->option('connection') : null;

        if (is_string($option) && $option !== '') {
            return $option;
        }

        $default = (string) config('queue.default', '');

        return $default !== '' && config("queue.connections.{$default}.driver") === 'rabbitmq'
            ? $default
            : 'rabbitmq';
    }
}
