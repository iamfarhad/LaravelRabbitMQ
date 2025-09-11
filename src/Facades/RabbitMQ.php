<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static ?string push($job, $data = '', ?string $queue = null)
 * @method static ?string pushRaw(string $payload, ?string $queue = null, array $options = [])
 * @method static ?string later($delay, $job, $data = '', ?string $queue = null)
 * @method static ?string laterRaw($delay, string $payload, ?string $queue = null, int $attempts = 2)
 * @method static ?\Illuminate\Contracts\Queue\Job pop(?string $queue = null)
 * @method static int size(?string $queue = null)
 * @method static bool queueExists(string $queueName)
 * @method static mixed purgeQueue(string $queueName)
 * @method static mixed deleteQueue(string $queueName)
 * @method static void declareQueue(string $name, bool $durable = true, bool $autoDelete = false, array $arguments = [])
 *
 * @see \iamfarhad\LaravelRabbitMQ\RabbitQueue
 */
class RabbitMQ extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'rabbitmq.queue';
    }
}
