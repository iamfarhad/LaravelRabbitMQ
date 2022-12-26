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

class RabbitQueue extends Queue implements QueueContract
{
    protected AbstractConnection $connection;

    protected AMQPChannel $channel;

    protected array $options;
    protected string $defaultQueue;
    protected RabbitMQJob $currentJob;

    public function __construct(
        AbstractConnection $connection,
        string $defaultQueue = 'default',
        array $options = [],
        bool $dispatchAfterCommit = false,
    ) {
        $this->connection = $connection;
        $this->channel = $connection->channel();
        $this->dispatchAfterCommit = $dispatchAfterCommit;
        $this->defaultQueue = $defaultQueue;
        $this->options = $options;
    }

    public function size($queue = null): int
    {
        $getQueue = $this->getQueue($queue);
        [, $size] = $this->channel->queue_declare($getQueue, true);

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
            function ($payload, $queue) {
                return $this->pushRaw($payload, $queue);
            }
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

        $this->channel->basic_publish($message, $exchange, $destination, true, false);

        return $correlationId;
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data),
            $queue,
            $delay,
            function ($payload, $queue, $delay) {
                return $this->laterRaw($delay, $payload, $queue);
            }
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
        $this->channel->basic_publish($message, null, $destination, true, false);

        return $correlationId;
    }

    /**
     * @throws AMQPProtocolChannelException|Throwable
     */
    public function pop($queue = null)
    {
        try {
            $queue = $this->getQueue($queue);

            $job = $this->getJobClass();

            /** @var AMQPMessage|null $message */
            if ($message = $this->channel->basic_get($queue)) {
                return $this->currentJob = new $job(
                    $this->container,
                    $this,
                    $message,
                    $this->connectionName,
                    $queue
                );
            }
        } catch (AMQPProtocolChannelException $exception) {
            // If there is not exchange or queue AMQP will throw exception with code 404
            // We need to catch it and return null
            if ($exception->amqp_reply_code === 404) {
                // Because of the channel exception the channel was closed and removed.
                // We have to open a new channel. Because else the worker(s) are stuck in a loop, without processing.
                $this->channel = $this->connection->channel();

                return null;
            }

            throw $exception;
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

    public function queueExists($queue = null): bool
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
        if ($this->currentJob && ! $this->currentJob->isDeletedOrReleased()) {
            $this->reject($this->currentJob, true);
        }

        try {
            $this->connection->close();
        } catch (ErrorException $exception) {
            // Ignore the exception
        } catch (Exception $e) {
        }
    }

    public function getChannel(): AMQPChannel
    {
        return $this->channel;
    }

    protected function getRandomId(): string
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

        $this->channel->queue_declare(
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

        $this->channel->exchange_declare(
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
        $job = Arr::get($this->options, 'job', RabbitMQJob::class);

        throw_if(
            ! is_a($job, RabbitMQJob::class, true),
            Exception::class,
            sprintf('Class %s must extend: %s', $job, RabbitMQJob::class)
        );

        return $job;
    }

    public function reject(RabbitMQJob $job, bool $requeue = false): void
    {
        $this->channel->basic_reject($job->getRabbitMQMessage()->getDeliveryTag(), $requeue);
    }

    public function ack(RabbitMQJob $job): void
    {
        $this->channel->basic_ack($job->getRabbitMQMessage()->getDeliveryTag());
    }

    public function setOptions(array $options): void
    {
        $this->options = $options;
    }

    private function bindQueue(string $queue, string $exchange, string $routingKey = ''): void
    {
        $this->channel->queue_bind($queue, $exchange, $routingKey);
    }

    protected function getDelayQueueArguments(string $destination, int $ttl): array
    {
        return [
            'x-dead-letter-exchange' => $destination,
            'x-dead-letter-routing-key' => $destination,
            'x-message-ttl' => $ttl,
            'x-expires' => $ttl * 2,
        ];
    }

    protected function declareDestination(
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

    protected function getQueueArguments(string $destination): array
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

    protected function getFailedExchange(string $exchange = null): ?string
    {
        return $exchange ?: Arr::get($this->options, 'failed_exchange') ?: null;
    }

    protected function getQueueMaxPriority(): int
    {
        return (int) (Arr::get($this->options, 'queue_max_priority') ?: 2);
    }

    protected function isQuorum(): bool
    {
        return (bool) (Arr::get($this->options, 'quorum') ?: false);
    }

    protected function isRerouteFailed(): bool
    {
        return (bool) (Arr::get($this->options, 'reroute_failed') ?: false);
    }

    protected function isPrioritizeDelayed(): bool
    {
        return (bool) (Arr::get($this->options, 'prioritize_delayed') ?: false);
    }

    protected function getFailedRoutingKey(string $destination): string
    {
        return sprintf('%s.failed', $destination);
    }

    /**
     * @throws JsonException
     */
    protected function createMessage($payload, int $attempts = 2): array
    {
        $properties = [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ];

        $currentPayload = json_decode($payload, true, 512);
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

        $message = new AMQPMessage($payload, $properties);

        $message->set('application_headers', new AMQPTable([
            'laravel' => [
                'attempts' => $attempts,
            ],
        ]));

        return [
            $message,
            $correlationId,
        ];
    }

    protected function publishProperties($queue, array $options = []): array
    {
        $queue = $this->getQueue($queue);
        $attempts = Arr::get($options, 'attempts') ?: 0;

        $destination = $queue;
        $exchange = $queue;
        $exchangeType = AMQPExchangeType::TOPIC;

        return [$destination, $exchange, $exchangeType, $attempts];
    }
}
