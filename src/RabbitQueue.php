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
use iamfarhad\LaravelRabbitMQ\Connection\PoolManager;
use iamfarhad\LaravelRabbitMQ\Contracts\RabbitQueueInterface;
use iamfarhad\LaravelRabbitMQ\Jobs\RabbitMQJob;
use iamfarhad\LaravelRabbitMQ\Support\ExchangeManager;
use iamfarhad\LaravelRabbitMQ\Support\ExponentialBackoff;
use iamfarhad\LaravelRabbitMQ\Support\MessageHelpers;
use iamfarhad\LaravelRabbitMQ\Support\PublisherConfirms;
use iamfarhad\LaravelRabbitMQ\Support\RpcClient;
use iamfarhad\LaravelRabbitMQ\Support\TransactionManager;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Queue;
use Illuminate\Support\Arr;
use JsonException;
use Throwable;

class RabbitQueue extends Queue implements RabbitQueueInterface
{
    private const DELIVERY_MODE_PERSISTENT = 2;

    private const QUEUE_NOT_FOUND_CODE = 404;

    private const QUEUE_ALREADY_EXISTS_CODE = 406;

    private const DEFAULT_RETRY_DELAY = 1000;

    private const MAX_RETRY_ATTEMPTS = 3;

    private ?AMQPChannel $amqpChannel = null;

    private ?RabbitMQJob $rabbitMQJob = null;

    private ?ExchangeManager $exchangeManager = null;

    private ?ExponentialBackoff $backoff = null;

    private ?PublisherConfirms $publisherConfirms = null;

    private ?TransactionManager $transactionManager = null;

    private ?RpcClient $rpcClient = null;

    public function __construct(
        protected readonly PoolManager $poolManager,
        protected readonly string $defaultQueue = 'default',
        protected array $options = [],
        bool $dispatchAfterCommit = false,
        string $connectionName = 'rabbitmq',
    ) {
        $this->connectionName = $connectionName;
        $this->dispatchAfterCommit = $dispatchAfterCommit;
    }

    public function getConnection(): AMQPConnection
    {
        return $this->poolManager->getConnection();
    }

    /**
     * Get a channel from the pool
     */
    public function getChannel(): AMQPChannel
    {
        if ($this->amqpChannel === null) {
            $this->amqpChannel = $this->poolManager->getChannel();
        }

        return $this->amqpChannel;
    }

    /**
     * Release the current channel back to the pool
     */
    private function releaseChannel(): void
    {
        if ($this->amqpChannel !== null) {
            $this->poolManager->releaseChannel($this->amqpChannel);
            $this->amqpChannel = null;
        }
    }

    /**
     * @throws AMQPChannelException
     */
    public function size($queue = null): int
    {
        $queueName = $this->getQueue($queue);

        try {
            $channel = $this->getChannel();
            $amqpQueue = new AMQPQueue($channel);
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

            $amqpQueue = new AMQPQueue($this->getChannel());
            $amqpQueue->setName($queueName);

            // Use AMQP_NOPARAM to get message without auto-ack
            // This allows the job to manually acknowledge after processing
            if (($envelope = $amqpQueue->get(AMQP_NOPARAM)) !== false && $envelope !== null) {
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
                $this->releaseChannel();

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
            $channel = $this->getChannel();
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
     * Close the connection and release resources.
     */
    public function close(): void
    {
        if ($this->rabbitMQJob !== null && ! $this->rabbitMQJob->isDeletedOrReleased()) {
            $this->reject($this->rabbitMQJob, true);
        }

        // Release the channel back to the pool
        $this->releaseChannel();
    }

    /**
     * Get the current AMQP channel (public API).
     */
    public function getAmqpChannel(): AMQPChannel
    {
        return $this->getChannel();
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
            // Connection health is managed by the pool

            // Channel health is managed by the pool

            $amqpQueue = new AMQPQueue($this->getChannel());
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
            $this->releaseChannel();
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
        /** @var class-string<RabbitMQJob> $job */
        $job = config('queue.connections.rabbitmq.options.queue.job', RabbitMQJob::class);

        if (! is_string($job) || ! is_a($job, RabbitMQJob::class, true)) {
            throw new Exception(sprintf('Class %s must extend: %s', is_string($job) ? $job : gettype($job), RabbitMQJob::class));
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
            $amqpQueue = new AMQPQueue($this->getChannel());
            $amqpQueue->setName($rabbitMQJob->getQueue());
            $amqpQueue->reject($deliveryTag, $requeue ? AMQP_REQUEUE : AMQP_NOPARAM);
        } catch (AMQPChannelException|AMQPConnectionException $exception) {
            // Reopen channel and try again
            $this->releaseChannel();
            $amqpQueue = new AMQPQueue($this->getChannel());
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
                $amqpQueue = new AMQPQueue($this->getChannel());
                $amqpQueue->setName($rabbitMQJob->getQueue());
                $amqpQueue->ack($deliveryTag);

                return; // Acknowledgment successful
            } catch (AMQPChannelException|AMQPConnectionException $exception) {
                // Recreate channel instead of setting to null
                $this->releaseChannel();
                $attempts++;
                if ($attempts < $maxRetries) {
                    usleep($retryDelay * 1000); // Wait before retrying
                }
            } catch (Throwable $e) {

                break; // Stop retrying for other errors
            }
        }
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
            $amqpQueue = new AMQPQueue($this->getChannel());
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
            $this->releaseChannel();

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
            $amqpQueue = new AMQPQueue($this->getChannel());
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
            $this->releaseChannel();

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
            $this->releaseChannel();

            return $this->doPublish($payload, $queueName, $messageAttributes);
        }
    }

    /**
     * Perform the actual message publishing.
     */
    private function doPublish(string $payload, string $queueName, array $messageAttributes): string
    {
        $amqpExchange = new AMQPExchange($this->getChannel());
        $amqpExchange->setName('');

        // Enable publisher confirms if configured
        if ($this->isPublisherConfirmsEnabled()) {
            $this->getPublisherConfirms()->enable();
        }

        $amqpExchange->publish($payload, $queueName, AMQP_NOPARAM, $messageAttributes);

        // Wait for confirm if enabled
        if ($this->isPublisherConfirmsEnabled()) {
            $this->getPublisherConfirms()->waitForConfirms();
        }

        return $messageAttributes['correlation_id'];
    }

    /**
     * Declare a queue with advanced options (lazy, priority, DLX)
     */
    public function declareAdvancedQueue(
        string $name,
        bool $durable = true,
        bool $autoDelete = false,
        bool $lazy = false,
        ?int $priority = null,
        ?array $deadLetterConfig = null,
        array $additionalArguments = []
    ): void {
        $arguments = $additionalArguments;

        // Lazy queue
        if ($lazy) {
            $arguments['x-queue-mode'] = 'lazy';
        }

        // Priority queue
        if ($priority !== null && $priority > 0) {
            $arguments['x-max-priority'] = min($priority, 255);
        }

        // Dead letter exchange
        if ($deadLetterConfig !== null) {
            $arguments['x-dead-letter-exchange'] = $deadLetterConfig['exchange'] ?? '';
            if (isset($deadLetterConfig['routing_key'])) {
                $arguments['x-dead-letter-routing-key'] = $deadLetterConfig['routing_key'];
            }
            if (isset($deadLetterConfig['ttl'])) {
                $arguments['x-message-ttl'] = $deadLetterConfig['ttl'];
            }
        }

        $this->declareQueue($name, $durable, $autoDelete, $arguments);
    }

    /**
     * Get the exchange manager
     */
    public function getExchangeManager(): ExchangeManager
    {
        if ($this->exchangeManager === null) {
            $this->exchangeManager = new ExchangeManager($this->getChannel());
        }

        return $this->exchangeManager;
    }

    /**
     * Get the exponential backoff instance
     */
    public function getBackoff(): ExponentialBackoff
    {
        if ($this->backoff === null) {
            $config = config('queue.connections.rabbitmq.backoff', []);
            $this->backoff = new ExponentialBackoff(
                $config['base_delay'] ?? 1000,
                $config['max_delay'] ?? 60000,
                $config['multiplier'] ?? 2.0,
                $config['jitter'] ?? true
            );
        }

        return $this->backoff;
    }

    /**
     * Get the publisher confirms instance
     */
    public function getPublisherConfirms(): PublisherConfirms
    {
        if ($this->publisherConfirms === null) {
            $timeout = config('queue.connections.rabbitmq.publisher_confirms.timeout', 5);
            $this->publisherConfirms = new PublisherConfirms($this->getChannel(), $timeout);
        }

        return $this->publisherConfirms;
    }

    /**
     * Get the transaction manager
     */
    public function getTransactionManager(): TransactionManager
    {
        if ($this->transactionManager === null) {
            $this->transactionManager = new TransactionManager($this->getChannel());
        }

        return $this->transactionManager;
    }

    /**
     * Get the RPC client
     */
    public function getRpcClient(): RpcClient
    {
        if ($this->rpcClient === null) {
            $timeout = config('queue.connections.rabbitmq.rpc.timeout', 30);
            $this->rpcClient = new RpcClient($this->getChannel(), $timeout);
        }

        return $this->rpcClient;
    }

    /**
     * Publish to a specific exchange
     */
    public function publishToExchange(
        string $exchangeName,
        string $payload,
        string $routingKey = '',
        array $headers = []
    ): bool {
        $correlationId = $this->createMessage($payload);

        $attributes = [
            'correlation_id' => $correlationId,
            'delivery_mode' => self::DELIVERY_MODE_PERSISTENT,
            'content_type' => 'application/json',
        ];

        if (! empty($headers)) {
            $attributes['headers'] = $headers;
        }

        return $this->getExchangeManager()->publish(
            $exchangeName,
            $payload,
            $routingKey,
            $attributes
        );
    }

    /**
     * Make an RPC call
     */
    public function rpcCall(string $queue, string $message, array $headers = []): string
    {
        if (! $this->isRpcEnabled()) {
            throw new Exception('RPC is not enabled in configuration');
        }

        return $this->getRpcClient()->call($queue, $message, $headers);
    }

    /**
     * Execute a callback within a transaction
     */
    public function transaction(callable $callback): mixed
    {
        if (! $this->isTransactionsEnabled()) {
            throw new Exception('Transactions are not enabled in configuration');
        }

        return $this->getTransactionManager()->transaction($callback);
    }

    /**
     * Check if publisher confirms are enabled
     */
    private function isPublisherConfirmsEnabled(): bool
    {
        return config('queue.connections.rabbitmq.publisher_confirms.enabled', false);
    }

    /**
     * Check if RPC is enabled
     */
    private function isRpcEnabled(): bool
    {
        return config('queue.connections.rabbitmq.rpc.enabled', false);
    }

    /**
     * Check if transactions are enabled
     */
    private function isTransactionsEnabled(): bool
    {
        return config('queue.connections.rabbitmq.transactions.enabled', false);
    }

    /**
     * Setup dead letter exchange for a queue
     */
    public function setupDeadLetterExchange(
        string $queueName,
        ?string $dlxName = null,
        ?string $dlxRoutingKey = null
    ): void {
        $dlxConfig = config('queue.connections.rabbitmq.dead_letter', []);

        if (! ($dlxConfig['enabled'] ?? true)) {
            return;
        }

        $dlxName = $dlxName ?? ($dlxConfig['exchange'] ?? 'dlx');
        $dlxType = $dlxConfig['exchange_type'] ?? 'direct';

        $this->getExchangeManager()->setupDeadLetterExchange(
            $queueName,
            $dlxName,
            $dlxType,
            $dlxRoutingKey
        );
    }

    /**
     * Publish a delayed message
     */
    public function publishDelayed(
        string $queue,
        string $payload,
        int $delay,
        array $headers = []
    ): ?string {
        $delayedConfig = config('queue.connections.rabbitmq.delayed_message', []);

        if ($delayedConfig['plugin_enabled'] ?? false) {
            // Use RabbitMQ delayed message plugin
            return $this->publishDelayedWithPlugin($queue, $payload, $delay, $headers);
        }

        // Use TTL-based delay (existing implementation)
        return $this->laterRaw($delay, $payload, $queue);
    }

    /**
     * Publish delayed message using RabbitMQ delayed message exchange plugin
     */
    private function publishDelayedWithPlugin(
        string $queue,
        string $payload,
        int $delay,
        array $headers = []
    ): string {
        $delayedConfig = config('queue.connections.rabbitmq.delayed_message', []);
        $exchangeName = $delayedConfig['exchange'] ?? 'delayed';

        $correlationId = $this->createMessage($payload);

        $attributes = [
            'correlation_id' => $correlationId,
            'delivery_mode' => self::DELIVERY_MODE_PERSISTENT,
            'content_type' => 'application/json',
            'headers' => array_merge($headers, [
                'x-delay' => $delay * 1000, // Convert to milliseconds
            ]),
        ];

        $this->getExchangeManager()->publish(
            $exchangeName,
            $payload,
            $queue,
            $attributes
        );

        return $correlationId;
    }
}
