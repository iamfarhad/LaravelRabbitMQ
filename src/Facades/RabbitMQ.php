<?php

namespace iamfarhad\LaravelRabbitMQ\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed push($job, $data = '', $queue = null)
 * @method static mixed pushRaw($payload, $queue = null, array $options = [])
 * @method static mixed later($delay, $job, $data = '', $queue = null)
 * @method static mixed laterRaw($delay, $payload, $queue = null, $attempts = 2)
 * @method static mixed pop($queue = null)
 * @method static int size($queue = null)
 * @method static bool queueExists(string $queueName)
 * @method static mixed purgeQueue(string $queueName)
 * @method static mixed deleteQueue(string $queueName)
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
