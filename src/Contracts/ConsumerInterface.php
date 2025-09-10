<?php

namespace iamfarhad\LaravelRabbitMQ\Contracts;

use Illuminate\Container\Container;
use Illuminate\Queue\WorkerOptions;

interface ConsumerInterface
{
    /**
     * Set the container instance.
     */
    public function setContainer(Container $container): void;

    /**
     * Set the consumer tag.
     */
    public function setConsumerTag(string $value): void;

    /**
     * Set the maximum priority.
     */
    public function setMaxPriority(int $value): void;

    /**
     * Listen to the given queue in a loop.
     */
    public function daemon($connectionName, $queue, WorkerOptions $options);

    /**
     * Stop the consumer.
     */
    public function stop(int $status = 0, array $options = []): int;
}
