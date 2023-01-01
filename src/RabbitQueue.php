<?php

namespace iamfarhad\LaravelRabbitMQ;

use ErrorException;
use Exception;
use iamfarhad\LaravelRabbitMQ\Connectors\RabbitMQConnector;
use iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob;
use Illuminate\Queue\Queue;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use JsonException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

final class RabbitQueue extends Queue implements QueueContract
{
    private AMQPChannel $amqpChannel;
    private RabbitMQJob $rabbitMQJob;

    public function __construct(
        protected AbstractConnection $connection,
        protected string $defaultQueue = 'default',
        protected array $options = [],
        bool $dispatchAfterCommit = false,
    ) {
        $this->amqpChannel = $connection->channel();
        $this->dispatchAfterCommit = $dispatchAfterCommit;
    }

    public function size($queue = null): int
    {
        $getQueue = $this->getQueue($queue);
        [, $size] = $this->amqpChannel->queue_declare($getQueue, true);

        return $size;
    }

    /**
     * @throws JsonException
     */
    public function push($job, $data = '', $queue = null)
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data),
            $queue,
            null,
            fn($payload, $queue) => $this->pushRaw($payload, $queue)
        );
    }

    /**
     * @throws JsonException
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        [$destination, $exchange, $exchangeType, $attempts] = $this->publishProperties($queue, $options);

        $this->declareDestination($destination, $exchange, $exchangeType);

        [$message, $correlationId] = $this->createMessage($payload, $attempts);

        $this->amqpChannel->basic_publish($message, $exchange, $destination, true, false);

        return $correlationId;
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data),
            $queue,
            $delay,
            fn($payload, $queue, $delay) => $this->laterRaw($delay, $payload, $queue)
        );
    }

    /**
     * @throws JsonException
     */
    public function laterRaw($delay, $payload, $queue = null, $attempts = 2)
    {
        $ttl = $this->secondsUntil($delay) * 1000;

        // When no ttl just publish a new message to the exchange or queue
        if ($ttl <= 0) {
            return $this->pushRaw($payload, $queue, ['delay' => $delay, 'attempts' => $attempts]);
        }

        $destination = $this->getQueue($queue) . '.delay.' . $ttl;

        $this->declareQueue($destination, true, false, $this->getDelayQueueArguments($this->getQueue($queue), $ttl));

        [$message, $correlationId] = $this->createMessage($payload, $attempts);

        // Publish directly on the delayQueue, no need to publish through an exchange.
        $this->amqpChannel->basic_publish($message, null, $destination, true, false);

        return $correlationId;
    }

    /**
     * @throws AMQPProtocolChannelException|Throwable
     */
    public function pop($queue = null)
    {
        try {
            $queue = $this->getQueue($queue);

            $jobClass = $this->getJobClass();

            /** @var AMQPMessage|null $amqpMessage */
            if (($amqpMessage = $this->amqpChannel->basic_get($queue)) !== null) {
                return $this->rabbitMQJob = new $jobClass(
                    $this->container,
                    $this,
                    $amqpMessage,
                    $this->connectionName,
                    $queue
                );
            }
        } catch (AMQPProtocolChannelException $amqpProtocolChannelException) {
            // If there is not exchange or queue AMQP will throw exception with code 404
            // We need to catch it and return null
            if ($amqpProtocolChannelException->amqp_reply_code === 404) {
                // Because of the channel exception the channel was closed and removed.
                // We have to open a new channel. Because else the worker(s) are stuck in a loop, without processing.
                $this->amqpChannel = $this->connection->channel();

                return null;
            }

            throw $amqpProtocolChannelException;
        } catch (AMQPChannelClosedException | AMQPConnectionClosedException $exception) {
            // Queue::pop used by worker to receive new job
            // Thrown exception is checked by Illuminate\Database\DetectsLostConnections::causedByLostConnection
            // Is has to contain one of the several phrases in exception message in order to restart worker
            // Otherwise worker continues to work with broken connection
            throw new AMQPRuntimeException(
                'Lost connection: ' . $exception->getMessage(),
                $exception->getCode(),
                $exception
            );
        }

        return null;
    }

    public function getQueue(string $queue = null): string
    {
        return $queue ?? $this->defaultQueue;
    }

    public function queueExists(string $queue = null): bool
    {
        $getQueue = $this->getQueue($queue);

        try {
            $channel = $this->connection->channel();
            $channel->queue_declare($getQueue, true);
        } catch (Throwable $throwable) {
            if ($throwable instanceof AMQPProtocolChannelException) {
                return false;
            }
        }

        return true;
    }

    public function close(): void
    {
        if ($this->rabbitMQJob && ! $this->rabbitMQJob->isDeletedOrReleased()) {
            $this->reject($this->rabbitMQJob, true);
        }

        try {
            $this->connection->close();
        } catch (ErrorException) {
            // Ignore the exception
        } catch (Exception) {
        }
    }

    public function getChannel(): AMQPChannel
    {
        return $this->amqpChannel;
    }

    private function getRandomId(): string
    {
        return Str::uuid();
    }

    public function declareQueue(
        string $name,
        bool $durable = true,
        bool $autoDelete = false,
        array $arguments = []
    ): void {
        if ($this->queueExists($name)) {
            return;
        }

        $this->amqpChannel->queue_declare(
            $name,
            false,
            $durable,
            false,
            $autoDelete,
            false,
            new AMQPTable($arguments)
        );

        $this->declareExchange($name);
        $this->bindQueue($name, $name, $name);
    }

    private function declareExchange(
        string $name,
        string $type = AMQPExchangeType::TOPIC,
        bool $durable = true,
        bool $autoDelete = false,
        array $arguments = []
    ): void {

        $this->amqpChannel->exchange_declare(
            $name,
            $type,
            false,
            $durable,
            $autoDelete,
            false,
            true,
            new AMQPTable($arguments)
        );
    }

    /**
     * @throws Throwable
     */
    public function getJobClass(): string
    {
        $job = config('queue.connections.rabbitmq.options.queue.job');

        throw_if(
            ! is_a($job, RabbitMQJob::class, true),
            Exception::class,
            sprintf('Class %s must extend: %s', $job, RabbitMQJob::class)
        );

        return $job;
    }

    public function reject(RabbitMQJob $rabbitMQJob, bool $requeue = false): void
    {
        $this->amqpChannel->basic_reject($rabbitMQJob->getRabbitMQMessage()->getDeliveryTag(), $requeue);
    }

    public function ack(RabbitMQJob $rabbitMQJob): void
    {
        $this->amqpChannel->basic_ack($rabbitMQJob->getRabbitMQMessage()->getDeliveryTag());
    }

    public function setOptions(array $options): void
    {
        $this->options = $options;
    }

    private function bindQueue(string $queue, string $exchange, string $routingKey = ''): void
    {
        $this->amqpChannel->queue_bind($queue, $exchange, $routingKey);
    }

    private function getDelayQueueArguments(string $destination, int $ttl): array
    {
        return [
            'x-dead-letter-exchange' => $destination,
            'x-dead-letter-routing-key' => $destination,
            'x-message-ttl' => $ttl,
            'x-expires' => $ttl * 2,
        ];
    }

    private function declareDestination(
        string $destination,
        ?string $exchange = null,
        string $exchangeType = AMQPExchangeType::DIRECT
    ): void {
        // When the queue already exists, just return.
        if ($this->queueExists($destination)) {
            return;
        }

        // Create a queue for amq.direct publishing.
        $this->declareQueue($destination, true, false, $this->getQueueArguments($destination));
    }

    private function getQueueArguments(string $destination): array
    {
        $arguments = [];

        // Messages without a priority property are treated as if their priority were 0.
        // Messages with a priority which is higher than the queue's maximum, are treated as if they were
        // published with the maximum priority.
        // Quorum queues does not support priority.
        if ($this->isPrioritizeDelayed() && ! $this->isQuorum()) {
            $arguments['x-max-priority'] = $this->getQueueMaxPriority();
        }

        if ($this->isRerouteFailed()) {
            $arguments['x-dead-letter-exchange'] = $this->getFailedExchange($destination);
            $arguments['x-dead-letter-routing-key'] = $this->getFailedRoutingKey($destination);
        }

        if ($this->isQuorum()) {
            $arguments['x-queue-type'] = 'quorum';
        }

        return $arguments;
    }

    private function getFailedExchange(string $exchange = null): ?string
    {
        return ($exchange ?: Arr::get($this->options, 'failed_exchange')) ?: null;
    }

    private function getQueueMaxPriority(): int
    {
        return (int) (Arr::get($this->options, 'queue_max_priority') ?: 2);
    }

    private function isQuorum(): bool
    {
        return (bool) (Arr::get($this->options, 'quorum') ?: false);
    }

    private function isRerouteFailed(): bool
    {
        return (bool) (Arr::get($this->options, 'reroute_failed') ?: false);
    }

    private function isPrioritizeDelayed(): bool
    {
        return (bool) (Arr::get($this->options, 'prioritize_delayed') ?: false);
    }

    private function getFailedRoutingKey(string $destination): string
    {
        return sprintf('%s.failed', $destination);
    }

    /**
     * @throws JsonException
     */
    private function createMessage($payload, int $attempts = 2): array
    {
        $properties = [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ];

        $currentPayload = json_decode((string) $payload, true, 512, JSON_THROW_ON_ERROR);
        if ($correlationId = $currentPayload['id'] ?? null) {
            $properties['correlation_id'] = $correlationId;
        }

        if ($this->isPrioritizeDelayed()) {
            $properties['priority'] = $attempts;
        }

        if (isset($currentPayload['data']['command'])) {
            $commandData = unserialize($currentPayload['data']['command'], ['allowed_classes' => true]);
            if (property_exists($commandData, 'priority')) {
                $properties['priority'] = $commandData->priority;
            }
        }

        $amqpMessage = new AMQPMessage($payload, $properties);

        $amqpMessage->set('application_headers', new AMQPTable([
            'laravel' => [
                'attempts' => $attempts,
            ],
        ]));

        return [
            $amqpMessage,
            $correlationId,
        ];
    }

    private function publishProperties(string $queue = '', array $options = []): array
    {
        $queue = $this->getQueue($queue);
        $attempts = Arr::get($options, 'attempts') ?: 0;

        $destination = $queue;
        $exchange = $queue;
        $exchangeType = AMQPExchangeType::TOPIC;

        return [$destination, $exchange, $exchangeType, $attempts];
    }
}
