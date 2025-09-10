<?php

namespace iamfarhad\LaravelRabbitMQ\Contracts;

use Illuminate\Contracts\Queue\Queue as QueueContract;

interface RabbitQueueInterface extends QueueContract
{
    /**
     * Get the AMQP channel.
     */
    public function getChannel();

    /**
     * Declare a queue.
     */
    public function declareQueue(string $name, bool $durable = true, bool $autoDelete = false, array $arguments = []): void;

    /**
     * Check if a queue exists.
     */
    public function queueExists(string $queueName): bool;

    /**
     * Purge a queue.
     */
    public function purgeQueue(string $queueName);

    /**
     * Delete a queue.
     */
    public function deleteQueue(string $queueName);

    /**
     * Set queue options.
     */
    public function setOptions(array $options): void;
}
