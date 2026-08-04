<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Facades;

use iamfarhad\LaravelRabbitMQ\LaravelRabbitQueueServiceProvider;
use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use Illuminate\Support\Facades\Facade;

/**
 * Resolves the application's RabbitMQ queue connection: the default queue
 * connection when that is a RabbitMQ connection, otherwise the connection named
 * `rabbitmq`.
 *
 * @method static ?string push($job, $data = '', ?string $queue = null)
 * @method static ?string pushRaw(string $payload, ?string $queue = null, array $options = [])
 * @method static ?string later($delay, $job, $data = '', ?string $queue = null)
 * @method static ?string laterRaw($delay, string $payload, ?string $queue = null, int $attempts = 0)
 * @method static void bulk(iterable $jobs, $data = '', ?string $queue = null)
 * @method static ?\Illuminate\Contracts\Queue\Job pop(?string $queue = null)
 * @method static int size(?string $queue = null)
 * @method static bool queueExists(string $queueName)
 * @method static mixed purgeQueue(string $queueName)
 * @method static mixed deleteQueue(string $queueName)
 * @method static void declareQueue(string $name, bool $durable = true, bool $autoDelete = false, array $arguments = [])
 * @method static void declareAdvancedQueue(string $name, bool $durable = true, bool $autoDelete = false, bool $lazy = false, ?int $priority = null, ?array $deadLetterConfig = null, array $additionalArguments = [])
 * @method static void setupDeadLetterExchange(string $queueName, ?string $dlxName = null, ?string $dlxRoutingKey = null)
 * @method static bool publishToExchange(string $exchangeName, string $payload, string $routingKey = '', array $headers = [])
 * @method static ?string publishDelayed(string $queue, string $payload, int $delay, array $headers = [])
 * @method static string rpcCall(string $queue, string $message, array $headers = [])
 * @method static mixed transaction(callable $callback)
 * @method static mixed connectionConfig(string $key, mixed $default = null)
 * @method static \iamfarhad\LaravelRabbitMQ\Support\ExchangeManager getExchangeManager()
 * @method static \AMQPChannel getChannel()
 *
 * @see RabbitQueue
 */
class RabbitMQ extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return LaravelRabbitQueueServiceProvider::QUEUE_BINDING;
    }
}
