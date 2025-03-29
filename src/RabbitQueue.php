<?php

namespace iamfarhad\LaravelRabbitMQ;

use ErrorException;
use Exception;
use iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
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
    private AMQPChannel $amqpChannel;

    private ?RabbitMQJob $rabbitMQJob = null;

    public function __construct(
        protected readonly AbstractConnection $connection,
        protected readonly string $defaultQueue = 'default',
        protected array $options = [],
        bool $dispatchAfterCommit = false,
    ) {
        $this->amqpChannel = $connection->channel();
        $this->dispatchAfterCommit = $dispatchAfterCommit;
    }

    public function getConnection(): AbstractConnection
    {
        return $this->connection;
    }

    /**
     * @throws AMQPProtocolChannelException
     */
    public function size($queue = null): int
    {
        $getQueue = $this->getQueue($queue);

        try {
            [, $size] = $this->amqpChannel->queue_declare($getQueue, true);

            return $size;
        } catch (AMQPProtocolChannelException $exception) {
            // If queue doesn't exist
            if ($exception->amqp_reply_code === 404) {
                return 0;
            }

            throw $exception;
        }
    }

    /**
     * Push a new job onto the queue.
     *
     * @param mixed $job
     * @param mixed $data
     * @param string|null $queue
     * @return string|null
     * @throws JsonException
     */
    public function push($job, $data = '', $queue = null): ?string
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data),
            $queue,
            null,
            fn ($payload, $queue) => $this->pushRaw($payload, $queue)
        );
    }

    /**
     * Push a raw payload onto the queue.
     *
     * @param string $payload
     * @param string $queue
     * @param array $options
     * @return string|null
     * @throws JsonException
     */
    public function pushRaw($payload, $queue = null, array $options = []): ?string
    {
        [$destination, $exchange, $exchangeType, $attempts] = $this->publishProperties($queue, $options);

        $this->declareDestination($destination, $exchange, $exchangeType);

        try {
            [$message, $correlationId] = $this->createMessage($payload, $attempts);
            $this->amqpChannel->basic_publish($message, $exchange, $destination, true, false);

            return $correlationId;
        } catch (AMQPChannelClosedException | AMQPConnectionClosedException $exception) {
            // Reopen channel and try again
            $this->amqpChannel = $this->connection->channel();
            [$message, $correlationId] = $this->createMessage($payload, $attempts);
            $this->amqpChannel->basic_publish($message, $exchange, $destination, true, false);

            return $correlationId;
        }
    }

    /**
     * Push a new job onto the queue after a delay.
     *
     * @param \DateTimeInterface|\DateInterval|int $delay
     * @param mixed $job
     * @param mixed $data
     * @param string|null $queue
     * @return string|null
     * @throws JsonException
     */
    public function later($delay, $job, $data = '', $queue = null): ?string
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data),
            $queue,
            $delay,
            fn ($payload, $queue, $delay) => $this->laterRaw($delay, $payload, $queue)
        );
    }

    /**
     * Push a raw job onto the queue after a delay.
     *
     * @param \DateTimeInterface|\DateInterval|int $delay
     * @param string $payload
     * @param string|null $queue
     * @param int $attempts
     * @return string|null
     * @throws JsonException|AMQPProtocolChannelException
     */
    public function laterRaw($delay, $payload, $queue = null, $attempts = 2): ?string
    {
        $ttl = $this->secondsUntil($delay) * 1000;

        // When no ttl just publish a new message to the exchange or queue
        if ($ttl <= 0) {
            return $this->pushRaw($payload, $queue, ['delay' => $delay, 'attempts' => $attempts]);
        }

        $destination = $this->getQueue($queue) . '.delay.' . $ttl;

        $this->declareQueue($destination, true, false, $this->getDelayQueueArguments($this->getQueue($queue), $ttl));

        try {
            [$message, $correlationId] = $this->createMessage($payload, $attempts);
            // Publish directly on the delayQueue, no need to publish through an exchange.
            $this->amqpChannel->basic_publish($message, null, $destination, true, false);

            return $correlationId;
        } catch (AMQPChannelClosedException | AMQPConnectionClosedException $exception) {
            // Reopen channel and try again
            $this->amqpChannel = $this->connection->channel();
            $this->declareQueue($destination, true, false, $this->getDelayQueueArguments($this->getQueue($queue), $ttl));
            [$message, $correlationId] = $this->createMessage($payload, $attempts);
            $this->amqpChannel->basic_publish($message, null, $destination, true, false);

            return $correlationId;
        }
    }

    /**
     * Pop the next job off of the queue.
     *
     * @param string|null $queue
     * @return Job|null
     * @throws AMQPProtocolChannelException|Throwable
     */
    public function pop($queue = null)
    {
        try {
            $queue = $this->getQueue($queue);

            // Create queue if it doesn't exist yet
            if (! $this->queueExists($queue)) {
                $this->declareDestination($queue);
            }

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
            // If there is no exchange or queue AMQP will throw exception with code 404
            if ($amqpProtocolChannelException->amqp_reply_code === 404) {
                // Create a new channel since the old one is closed
                $this->amqpChannel = $this->connection->channel();

                // Try to create the queue and retry
                try {
                    $this->declareDestination($queue);

                    return $this->pop($queue);
                } catch (Throwable) {
                    return null;
                }
            }

            throw $amqpProtocolChannelException;
        } catch (AMQPChannelClosedException | AMQPConnectionClosedException $exception) {
            // Replace with a more specific exception that Laravel's worker can detect as a lost connection
            throw new AMQPRuntimeException(
                'Lost connection: ' . $exception->getMessage(),
                $exception->getCode(),
                $exception
            );
        }

        return null;
    }

    /**
     * Get the queue name.
     *
     * @param string|null $queue
     * @return string
     */
    public function getQueue(?string $queue = null): string
    {
        return $queue ?? $this->defaultQueue;
    }

    /**
     * Check if a queue exists.
     *
     * @param string $queue
     * @return bool
     */
    public function queueExists(string $queue): bool
    {
        try {
            $channel = $this->connection->channel();
            $channel->queue_declare($queue, true);
            $channel->close();

            return true;
        } catch (Throwable $throwable) {
            if ($throwable instanceof AMQPProtocolChannelException && $throwable->amqp_reply_code === 404) {
                return false;
            }

            // If another error occurred, we'll assume the queue doesn't exist
            // to avoid false positives
            return false;
        }
    }

    /**
     * Close the connection.
     */
    public function close(): void
    {
        if ($this->rabbitMQJob !== null && ! $this->rabbitMQJob->isDeletedOrReleased()) {
            $this->reject($this->rabbitMQJob, true);
        }

        try {
            if ($this->amqpChannel && $this->amqpChannel->is_open()) {
                $this->amqpChannel->close();
            }

            if ($this->connection && $this->connection->isConnected()) {
                $this->connection->close();
            }
        } catch (ErrorException|Exception) {
            // Ignore connection errors on close
        }
    }

    /**
     * Get the AMQP channel.
     *
     * @return AMQPChannel
     */
    public function getChannel(): AMQPChannel
    {
        // Ensure channel is open, re-open if closed
        if (! $this->amqpChannel->is_open()) {
            $this->amqpChannel = $this->connection->channel();
        }

        return $this->amqpChannel;
    }

    /**
     * Generate a random ID.
     *
     * @return string
     */
    private function getRandomId(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Declare a queue.
     *
     * @param string $name
     * @param bool $durable
     * @param bool $autoDelete
     * @param array $arguments
     * @throws AMQPProtocolChannelException
     */
    public function declareQueue(
        string $name,
        bool $durable = true,
        bool $autoDelete = false,
        array $arguments = []
    ): void {
        try {
            $this->amqpChannel->queue_declare(
                $name,
                false,
                $durable,
                false,
                $autoDelete,
                false,
                new AMQPTable($arguments)
            );

            // Only declare exchange and bind queue if not already done
            if (! isset($arguments['x-dead-letter-exchange'])) {
                $this->declareExchange($name);
                $this->bindQueue($name, $name, $name);
            }
        } catch (AMQPProtocolChannelException $exception) {
            // If it's not a "queue already exists" case, re-throw
            if ($exception->amqp_reply_code !== 406) {
                throw $exception;
            }
        } catch (AMQPChannelClosedException | AMQPConnectionClosedException $exception) {
            // Reopen channel and try again
            $this->amqpChannel = $this->connection->channel();
            $this->declareQueue($name, $durable, $autoDelete, $arguments);
        }
    }

    /**
     * Declare an exchange.
     *
     * @param string $name
     * @param string $type
     * @param bool $durable
     * @param bool $autoDelete
     * @param array $arguments
     */
    private function declareExchange(
        string $name,
        string $type = AMQPExchangeType::TOPIC,
        bool $durable = true,
        bool $autoDelete = false,
        array $arguments = []
    ): void {
        try {
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
        } catch (AMQPChannelClosedException | AMQPConnectionClosedException $exception) {
            // Reopen channel and try again
            $this->amqpChannel = $this->connection->channel();
            $this->declareExchange($name, $type, $durable, $autoDelete, $arguments);
        }
    }

    /**
     * Get the job class.
     *
     * @return string
     * @throws Throwable
     */
    public function getJobClass(): string
    {
        $job = config('queue.connections.rabbitmq.options.queue.job', RabbitMQJob::class);

        if (! is_a($job, RabbitMQJob::class, true)) {
            throw new Exception(sprintf('Class %s must extend: %s', $job, RabbitMQJob::class));
        }

        return $job;
    }

    /**
     * Reject a job.
     *
     * @param RabbitMQJob $rabbitMQJob
     * @param bool $requeue
     */
    public function reject(RabbitMQJob $rabbitMQJob, bool $requeue = false): void
    {
        try {
            $this->amqpChannel->basic_reject($rabbitMQJob->getRabbitMQMessage()->getDeliveryTag(), $requeue);
        } catch (AMQPChannelClosedException | AMQPConnectionClosedException $exception) {
            // Reopen channel and try again
            $this->amqpChannel = $this->connection->channel();
            $this->amqpChannel->basic_reject($rabbitMQJob->getRabbitMQMessage()->getDeliveryTag(), $requeue);
        }
    }

    /**
     * Acknowledge a job with retry logic.
     *
     * @param RabbitMQJob $rabbitMQJob
     * @param int $maxRetries
     * @param int $retryDelay
     */
    public function ack(RabbitMQJob $rabbitMQJob, int $maxRetries = 3, int $retryDelay = 1000): void
    {
        $deliveryTag = $rabbitMQJob->getRabbitMQMessage()->getDeliveryTag();
        $attempts = 0;
        while ($attempts < $maxRetries) {
            try {
                $this->getChannel()->basic_ack($deliveryTag);
                Log::debug('Acknowledged job with delivery tag: ' . $deliveryTag);

                return; // Acknowledgment successful
            } catch (AMQPChannelClosedException | AMQPConnectionClosedException $exception) {
                Log::warning(
                    sprintf(
                        'RabbitMQ connection or channel closed during job acknowledgment (attempt %d/%d): %s',
                        $attempts + 1,
                        $maxRetries,
                        $exception->getMessage()
                    )
                );
                $this->amqpChannel = null; // Force channel re-creation
                $attempts++;
                if ($attempts < $maxRetries) {
                    usleep($retryDelay * 1000); // Wait before retrying
                }
            } catch (Throwable $e) {
                Log::error('Error during job acknowledgment: ' . $e->getMessage());

                break; // Stop retrying for other errors
            }
        }
        Log::error('Failed to acknowledge job with delivery tag: ' . $deliveryTag . ' after multiple retries.');
        // Consider what to do if acknowledgment consistently fails (e.g., log and potentially let RabbitMQ handle re-delivery)
    }

    /**
     * Set queue options.
     *
     * @param array $options
     */
    public function setOptions(array $options): void
    {
        $this->options = $options;
    }

    /**
     * Bind a queue to an exchange.
     *
     * @param string $queue
     * @param string $exchange
     * @param string $routingKey
     */
    private function bindQueue(string $queue, string $exchange, string $routingKey = ''): void
    {
        try {
            $this->amqpChannel->queue_bind($queue, $exchange, $routingKey);
        } catch (AMQPChannelClosedException | AMQPConnectionClosedException $exception) {
            // Reopen channel and try again
            $this->amqpChannel = $this->connection->channel();
            $this->amqpChannel->queue_bind($queue, $exchange, $routingKey);
        }
    }

    /**
     * Get the arguments for a delay queue.
     *
     * @param string $destination
     * @param int $ttl
     * @return array
     */
    private function getDelayQueueArguments(string $destination, int $ttl): array
    {
        return [
            'x-dead-letter-exchange' => $destination,
            'x-dead-letter-routing-key' => $destination,
            'x-message-ttl' => $ttl,
            'x-expires' => $ttl * 2,
        ];
    }

    /**
     * Declare a destination.
     *
     * @param string $destination
     * @param string|null $exchange
     * @param string $exchangeType
     */
    private function declareDestination(
        string $destination,
        ?string $exchange = null,
        string $exchangeType = AMQPExchangeType::TOPIC
    ): void {
        // When exchange is null, use destination as exchange name
        $exchange = $exchange ?? $destination;

        // Create a queue for amq.direct publishing
        $this->declareQueue($destination, true, false, $this->getQueueArguments($destination));

        // Ensure exchange exists and is bound to queue
        $this->declareExchange($exchange, $exchangeType);
        $this->bindQueue($destination, $exchange, $destination);
    }

    /**
     * Get the queue arguments.
     *
     * @param string $destination
     * @return array
     */
    private function getQueueArguments(string $destination): array
    {
        $arguments = [];

        // Only use priorities if it's not a quorum queue
        if ($this->isPrioritizeDelayed() && ! $this->isQuorum()) {
            $arguments['x-max-priority'] = $this->getQueueMaxPriority();
        }

        if ($this->isRerouteFailed()) {
            $failedExchange = $this->getFailedExchange($destination);
            if ($failedExchange) {
                $arguments['x-dead-letter-exchange'] = $failedExchange;
                $arguments['x-dead-letter-routing-key'] = $this->getFailedRoutingKey($destination);
            }
        }

        if ($this->isQuorum()) {
            $arguments['x-queue-type'] = 'quorum';

            // Add recommended settings for quorum queues in production
            $arguments['x-delivery-limit'] = $this->getQuorumDeliveryLimit();

            // Only add if not already set for delayed queues
            if (! isset($arguments['x-dead-letter-exchange']) && $this->isRerouteFailed()) {
                $arguments['x-dead-letter-exchange'] = $destination;
                $arguments['x-dead-letter-routing-key'] = $this->getFailedRoutingKey($destination);
            }
        }

        return $arguments;
    }

    /**
     * Get the failed exchange.
     *
     * @param string|null $exchange
     * @return string|null
     */
    private function getFailedExchange(?string $exchange = null): ?string
    {
        return $exchange ?: (Arr::get($this->options, 'failed_exchange') ?: null);
    }

    /**
     * Get the maximum priority for the queue.
     *
     * @return int
     */
    private function getQueueMaxPriority(): int
    {
        return (int) (Arr::get($this->options, 'queue_max_priority') ?: 2);
    }

    /**
     * Get the delivery limit for quorum queues.
     *
     * @return int
     */
    private function getQuorumDeliveryLimit(): int
    {
        return (int) (Arr::get($this->options, 'quorum_delivery_limit') ?: 10);
    }

    /**
     * Check if the queue is a quorum queue.
     *
     * @return bool
     */
    private function isQuorum(): bool
    {
        return (bool) (Arr::get($this->options, 'quorum') ?: false);
    }

    /**
     * Check if failed messages should be rerouted.
     *
     * @return bool
     */
    private function isRerouteFailed(): bool
    {
        return (bool) (Arr::get($this->options, 'reroute_failed') ?: false);
    }

    /**
     * Check if delayed messages should be prioritized.
     *
     * @return bool
     */
    private function isPrioritizeDelayed(): bool
    {
        return (bool) (Arr::get($this->options, 'prioritize_delayed') ?: false);
    }

    /**
     * Get the routing key for failed messages.
     *
     * @param string $destination
     * @return string
     */
    private function getFailedRoutingKey(string $destination): string
    {
        return sprintf('%s.failed', $destination);
    }

    /**
     * Create a message.
     *
     * @param string $payload
     * @param int $attempts
     * @return array
     * @throws JsonException
     */
    public function createMessage($payload, int $attempts = 2): array
    {
        $properties = [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ];

        $correlationId = null;

        try {
            $currentPayload = json_decode((string) $payload, true, 512, JSON_THROW_ON_ERROR);

            if ($id = $currentPayload['id'] ?? null) {
                $properties['correlation_id'] = $correlationId = $id;
            } else {
                // Generate a correlation ID if none exists
                $properties['correlation_id'] = $correlationId = $this->getRandomId();
            }

            if ($this->isPrioritizeDelayed()) {
                $properties['priority'] = $attempts;
            }

            if (isset($currentPayload['data']['command'])) {
                $commandData = @unserialize($currentPayload['data']['command'], ['allowed_classes' => true]);
                if ($commandData !== false && property_exists($commandData, 'priority')) {
                    $properties['priority'] = $commandData->priority;
                }
            }
        } catch (JsonException) {
            // If we can't decode the payload, just generate a correlation ID
            $properties['correlation_id'] = $correlationId = $this->getRandomId();
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

    /**
     * Get the properties for publishing.
     *
     * @param string|null $queue
     * @param array $options
     * @return array
     */
    private function publishProperties(?string $queue = null, array $options = []): array
    {
        $queue = $this->getQueue($queue);
        $attempts = Arr::get($options, 'attempts') ?: 0;

        $destination = $queue;
        $exchange = $queue;
        $exchangeType = AMQPExchangeType::TOPIC;

        return [$destination, $exchange, $exchangeType, $attempts];
    }

    /**
     * Purge a queue.
     *
     * @param string $queue
     * @return mixed
     */
    public function purgeQueue(string $queue)
    {
        try {
            return $this->amqpChannel->queue_purge($queue, true);
        } catch (AMQPProtocolChannelException $exception) {
            // If queue doesn't exist, just return
            if ($exception->amqp_reply_code === 404) {
                return null;
            }

            throw $exception;
        } catch (AMQPChannelClosedException | AMQPConnectionClosedException $exception) {
            // Reopen channel and try again
            $this->amqpChannel = $this->connection->channel();

            return $this->purgeQueue($queue);
        }
    }

    /**
     * Delete a queue.
     *
     * @param string $queue
     * @return mixed
     */
    public function deleteQueue(string $queue)
    {
        try {
            return $this->amqpChannel->queue_delete($queue);
        } catch (AMQPProtocolChannelException $exception) {
            // If queue doesn't exist, just return
            if ($exception->amqp_reply_code === 404) {
                return null;
            }

            throw $exception;
        } catch (AMQPChannelClosedException | AMQPConnectionClosedException $exception) {
            // Reopen channel and try again
            $this->amqpChannel = $this->connection->channel();

            return $this->deleteQueue($queue);
        }
    }
}
