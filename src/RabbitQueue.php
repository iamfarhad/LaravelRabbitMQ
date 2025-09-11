<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ;

use AMQPChannel;
use AMQPChannelException;
use AMQPConnection;
use AMQPConnectionException;
use AMQPExchange;
use AMQPQueue;
use AMQPQueueException;
use Exception;
use iamfarhad\LaravelRabbitMQ\Contracts\RabbitQueueInterface;
use iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob;
use iamfarhad\LaravelRabbitMQ\Support\MessageHelpers;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Queue;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

class RabbitQueue extends Queue implements RabbitQueueInterface
{
    private const DELIVERY_MODE_PERSISTENT = 2;

    private const QUEUE_NOT_FOUND_CODE = 404;

    private const QUEUE_ALREADY_EXISTS_CODE = 406;

    private const DEFAULT_RETRY_DELAY = 1000;

    private const MAX_RETRY_ATTEMPTS = 3;

    private AMQPChannel $amqpChannel;

    private ?RabbitMQJob $rabbitMQJob = null;

    public function __construct(
        protected readonly AMQPConnection $connection,
        protected readonly string $defaultQueue = 'default',
        protected array $options = [],
        bool $dispatchAfterCommit = false,
    ) {
        $this->amqpChannel = new AMQPChannel($this->connection);
        $this->dispatchAfterCommit = $dispatchAfterCommit;
    }

    public function getConnection(): AMQPConnection
    {
        return $this->connection;
    }

    /**
     * @throws AMQPChannelException
     */
    public function size($queue = null): int
    {
        $queueName = $this->getQueue($queue);

        try {
            $amqpQueue = new AMQPQueue($this->amqpChannel);
            $amqpQueue->setName($queueName);
            $amqpQueue->setFlags(AMQP_PASSIVE);

            return $amqpQueue->declareQueue();
        } catch (AMQPChannelException $exception) {
            // If queue doesn't exist
            if ($exception->getCode() === self::QUEUE_NOT_FOUND_CODE) {
                return 0;
            }

            throw $exception;
        }
    }

    /**
     * Push a new job onto the queue.
     *
     * @param  mixed  $job
     * @param  mixed  $data
     * @param  string|null  $queue
     *
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
     * @param  string  $payload
     * @param  string|null  $queue
     *
     * @throws JsonException
     */
    public function pushRaw($payload, $queue = null, array $options = []): ?string
    {
        $queueName = $this->getQueue($queue);
        $attempts = Arr::get($options, 'attempts', 0);

        $this->declareQueue($queueName);

        return $this->publishMessage($payload, $queueName, $attempts);
    }

    /**
     * Push a new job onto the queue after a delay.
     *
     * @param  \DateTimeInterface|\DateInterval|int  $delay
     * @param  mixed  $job
     * @param  mixed  $data
     * @param  string|null  $queue
     *
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
     * @param  \DateTimeInterface|\DateInterval|int  $delay
     * @param  string  $payload
     * @param  string|null  $queue
     * @param  int  $attempts
     *
     * @throws JsonException|AMQPChannelException
     */
    public function laterRaw($delay, $payload, $queue = null, $attempts = 2): ?string
    {
        $ttl = $this->secondsUntil($delay) * 1000;

        // When no ttl just publish a new message to the queue
        if ($ttl <= 0) {
            return $this->pushRaw($payload, $queue, ['delay' => $delay, 'attempts' => $attempts]);
        }

        $queueName = $this->getQueue($queue);
        $delayQueueName = $queueName.'.delay.'.$ttl;

        $this->declareDelayQueue($delayQueueName, $queueName, $ttl);

        return $this->publishMessage($payload, $delayQueueName, $attempts);
    }

    /**
     * Pop the next job off of the queue.
     *
     * @param  string|null  $queue
     * @return Job|null
     *
     * @throws AMQPChannelException|Throwable
     */
    public function pop($queue = null)
    {
        try {
            $queueName = $this->getQueue($queue);

            // Create queue if it doesn't exist yet
            if (! $this->queueExists($queueName)) {
                $this->declareQueue($queueName);
            }

            $jobClass = $this->getJobClass();

            $amqpQueue = new AMQPQueue($this->amqpChannel);
            $amqpQueue->setName($queueName);

            if (($envelope = $amqpQueue->get(AMQP_AUTOACK)) !== false && $envelope !== null) {
                $this->rabbitMQJob = new $jobClass(
                    $this->container,
                    $this,
                    $envelope,
                    $this->connectionName,
                    $queueName
                );

                return $this->rabbitMQJob;
            }

            return null;
        } catch (AMQPChannelException $exception) {
            // If there is no queue AMQP will throw exception with code 404
            if ($exception->getCode() === self::QUEUE_NOT_FOUND_CODE) {
                // Create a new channel since the old one is closed
                $this->amqpChannel = new AMQPChannel($this->connection);

                // Try to create the queue and retry
                try {
                    $this->declareQueue($queueName);

                    return $this->pop($queueName);
                } catch (Throwable) {
                    return null;
                }
            }

            throw $exception;
        } catch (AMQPConnectionException $exception) {
            // Replace with a more specific exception that Laravel's worker can detect as a lost connection
            throw new Exception(
                'Lost connection: '.$exception->getMessage(),
                $exception->getCode(),
                $exception
            );
        }

        return null;
    }

    /**
     * Get the queue name.
     */
    public function getQueue(?string $queue = null): string
    {
        return $queue ?? $this->defaultQueue;
    }

    /**
     * Check if a queue exists.
     */
    public function queueExists(string $queueName): bool
    {
        try {
            $channel = new AMQPChannel($this->connection);
            $amqpQueue = new AMQPQueue($channel);
            $amqpQueue->setName($queueName);
            $amqpQueue->setFlags(AMQP_PASSIVE);
            $amqpQueue->declareQueue();

            return true;
        } catch (Throwable $throwable) {
            if ($throwable instanceof AMQPChannelException && $throwable->getCode() === 404) {
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
            if ($this->connection && $this->connection->isConnected()) {
                $this->connection->disconnect();
            }
        } catch (Exception) {
            // Ignore connection errors on close
        }
    }

    /**
     * Get the AMQP channel.
     */
    public function getChannel(): AMQPChannel
    {
        return $this->amqpChannel;
    }

    /**
     * Generate a random ID.
     */
    private function getRandomId(): string
    {
        return MessageHelpers::generateCorrelationId();
    }

    /**
     * Declare a queue.
     *
     * @throws AMQPChannelException
     */
    public function declareQueue(
        string $name,
        bool $durable = true,
        bool $autoDelete = false,
        array $arguments = []
    ): void {
        try {
            // Ensure connection is available before creating channel
            if (! $this->connection->isConnected()) {
                $this->connection->connect();
            }

            // Ensure channel is valid
            if (! isset($this->amqpChannel) || ! $this->amqpChannel->getConnection()->isConnected()) {
                $this->amqpChannel = new AMQPChannel($this->connection);
            }

            $amqpQueue = new AMQPQueue($this->amqpChannel);
            $amqpQueue->setName($name);
            $amqpQueue->setFlags($durable ? AMQP_DURABLE : AMQP_NOPARAM);

            if ($autoDelete) {
                $amqpQueue->setFlags($amqpQueue->getFlags() | AMQP_AUTODELETE);
            }

            if (! empty($arguments)) {
                $amqpQueue->setArguments($arguments);
            }

            $amqpQueue->declareQueue();
        } catch (AMQPChannelException|AMQPQueueException $exception) {
            // If it's not a "queue already exists" or "unknown delivery tag" case, re-throw
            if ($exception->getCode() !== self::QUEUE_ALREADY_EXISTS_CODE) {
                throw $exception;
            }
            // Ignore 406 errors (queue already exists or unknown delivery tag)
        } catch (AMQPConnectionException $exception) {
            // Reopen channel and try again
            $this->amqpChannel = new AMQPChannel($this->connection);
            $this->declareQueue($name, $durable, $autoDelete, $arguments);
        }
    }

    /**
     * Declare a delay queue with TTL.
     */
    private function declareDelayQueue(string $delayQueueName, string $targetQueueName, int $ttl): void
    {
        $arguments = [
            'x-message-ttl' => $ttl,
            'x-dead-letter-exchange' => '',
            'x-dead-letter-routing-key' => $targetQueueName,
        ];

        $this->declareQueue($delayQueueName, true, false, $arguments);
        $this->declareQueue($targetQueueName); // Ensure target queue exists
    }

    /**
     * Get the job class.
     *
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
     */
    public function reject(RabbitMQJob $rabbitMQJob, bool $requeue = false): void
    {
        $envelope = $rabbitMQJob->getRabbitMQMessage();
        $deliveryTag = $envelope->getDeliveryTag();

        if ($deliveryTag === null) {
            return;
        }

        try {
            $amqpQueue = new AMQPQueue($this->amqpChannel);
            $amqpQueue->setName($rabbitMQJob->getQueue());
            $amqpQueue->reject($deliveryTag, $requeue ? AMQP_REQUEUE : AMQP_NOPARAM);
        } catch (AMQPChannelException|AMQPConnectionException $exception) {
            // Reopen channel and try again
            $this->amqpChannel = new AMQPChannel($this->connection);
            $amqpQueue = new AMQPQueue($this->amqpChannel);
            $amqpQueue->setName($rabbitMQJob->getQueue());
            $amqpQueue->reject($deliveryTag, $requeue ? AMQP_REQUEUE : AMQP_NOPARAM);
        }
    }

    /**
     * Acknowledge a job with retry logic.
     */
    public function ack(RabbitMQJob $rabbitMQJob, int $maxRetries = self::MAX_RETRY_ATTEMPTS, int $retryDelay = self::DEFAULT_RETRY_DELAY): void
    {
        $envelope = $rabbitMQJob->getRabbitMQMessage();
        $deliveryTag = $envelope->getDeliveryTag();

        if ($deliveryTag === null) {
            return;
        }

        $attempts = 0;

        while ($attempts < $maxRetries) {
            try {
                $amqpQueue = new AMQPQueue($this->amqpChannel);
                $amqpQueue->setName($rabbitMQJob->getQueue());
                $amqpQueue->ack($deliveryTag);
                Log::debug('Acknowledged job with delivery tag: '.$deliveryTag);

                return; // Acknowledgment successful
            } catch (AMQPChannelException|AMQPConnectionException $exception) {
                Log::warning(
                    sprintf(
                        'RabbitMQ connection or channel closed during job acknowledgment (attempt %d/%d): %s',
                        $attempts + 1,
                        $maxRetries,
                        $exception->getMessage()
                    )
                );
                // Recreate channel instead of setting to null
                $this->amqpChannel = new AMQPChannel($this->connection);
                $attempts++;
                if ($attempts < $maxRetries) {
                    usleep($retryDelay * 1000); // Wait before retrying
                }
            } catch (Throwable $e) {
                Log::error('Error during job acknowledgment: '.$e->getMessage());

                break; // Stop retrying for other errors
            }
        }
        Log::error('Failed to acknowledge job with delivery tag: '.$deliveryTag.' after multiple retries.');
    }

    /**
     * Set queue options.
     */
    public function setOptions(array $options): void
    {
        $this->options = $options;
    }

    /**
     * Create a message.
     *
     * @param  string  $payload
     *
     * @throws JsonException
     */
    public function createMessage($payload, int $attempts = 2): string
    {
        $correlationId = null;

        $correlationId = MessageHelpers::extractCorrelationId($payload) ?? $this->getRandomId();

        return $correlationId;
    }

    /**
     * Purge a queue.
     *
     * @return mixed
     */
    public function purgeQueue(string $queueName)
    {
        try {
            $amqpQueue = new AMQPQueue($this->amqpChannel);
            $amqpQueue->setName($queueName);

            return $amqpQueue->purge();
        } catch (AMQPChannelException $exception) {
            // If queue doesn't exist, just return
            if ($exception->getCode() === self::QUEUE_NOT_FOUND_CODE) {
                return null;
            }

            throw $exception;
        } catch (AMQPConnectionException $exception) {
            // Reopen channel and try again
            $this->amqpChannel = new AMQPChannel($this->connection);

            return $this->purgeQueue($queueName);
        }
    }

    /**
     * Delete a queue.
     *
     * @return mixed
     */
    public function deleteQueue(string $queueName)
    {
        try {
            $amqpQueue = new AMQPQueue($this->amqpChannel);
            $amqpQueue->setName($queueName);

            return $amqpQueue->delete();
        } catch (AMQPChannelException $exception) {
            // If queue doesn't exist, just return
            if ($exception->getCode() === self::QUEUE_NOT_FOUND_CODE) {
                return null;
            }

            throw $exception;
        } catch (AMQPConnectionException $exception) {
            // Reopen channel and try again
            $this->amqpChannel = new AMQPChannel($this->connection);

            return $this->deleteQueue($queueName);
        }
    }

    /**
     * Publish a message to the queue with retry logic.
     *
     * @throws JsonException
     */
    private function publishMessage(string $payload, string $queueName, int $attempts = 2): string
    {
        $correlationId = $this->createMessage($payload, $attempts);
        $messageAttributes = [
            'correlation_id' => $correlationId,
            'delivery_mode' => self::DELIVERY_MODE_PERSISTENT,
            'content_type' => 'application/json',
        ];

        try {
            return $this->doPublish($payload, $queueName, $messageAttributes);
        } catch (AMQPChannelException|AMQPConnectionException) {
            // Reopen channel and try again
            $this->amqpChannel = new AMQPChannel($this->connection);

            return $this->doPublish($payload, $queueName, $messageAttributes);
        }
    }

    /**
     * Perform the actual message publishing.
     */
    private function doPublish(string $payload, string $queueName, array $messageAttributes): string
    {
        $amqpExchange = new AMQPExchange($this->amqpChannel);
        $amqpExchange->setName('');
        $amqpExchange->publish($payload, $queueName, AMQP_NOPARAM, $messageAttributes);

        return $messageAttributes['correlation_id'];
    }
}
