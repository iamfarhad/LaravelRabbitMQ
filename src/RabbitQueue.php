<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ;

use AMQPChannel;
use AMQPChannelException;
use AMQPConnection;
use AMQPConnectionException;
use AMQPExchange;
use AMQPExchangeException;
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
use Illuminate\Queue\Queue;
use Illuminate\Support\Arr;
use JsonException;
use Throwable;

class RabbitQueue extends Queue implements RabbitQueueInterface
{
    private const DELIVERY_MODE_PERSISTENT = 2;

    private const QUEUE_NOT_FOUND_CODE = 404;

    private const QUEUE_ALREADY_EXISTS_CODE = 406;

    private const RECONNECT_MAX_ATTEMPTS = 5;

    private const RECONNECT_RETRY_DELAY_MS = 500;

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

    public function getChannel(): AMQPChannel
    {
        // A cached channel can outlive its TCP connection (broker restart,
        // idle disconnect, missed heartbeats) — especially under Octane where
        // this object lives across many requests. Handing out such a channel
        // makes every AMQP*::__construct fail with "... No channel available.",
        // so validate before reuse and transparently replace it.
        if ($this->amqpChannel !== null && ! $this->isChannelUsable($this->amqpChannel)) {
            $this->releaseChannel();
        }

        if ($this->amqpChannel === null) {
            $this->amqpChannel = $this->poolManager->getChannel();
        }

        return $this->amqpChannel;
    }

    /**
     * Whether the channel's ext-amqp "is connected" flag — the exact flag
     * every AMQP*::__construct verifies — still holds, on both the channel
     * and its underlying connection. No network I/O involved.
     */
    private function isChannelUsable(AMQPChannel $channel): bool
    {
        try {
            return $channel->isConnected() && $channel->getConnection()->isConnected();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Run an AMQP operation, transparently swapping in a fresh channel and
     * retrying when the operation failed because the channel's underlying
     * connection died. Broker-reported semantic errors (404 not-found,
     * 406 precondition-failed, ...) are never retried here — a new channel
     * would only repeat them.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $operation
     * @return TReturn
     */
    private function retryOnDeadChannel(callable $operation): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $operation();
            } catch (AMQPConnectionException|AMQPChannelException|AMQPQueueException|AMQPExchangeException $exception) {
                if (! $this->isDeadChannelFailure($exception)) {
                    throw $exception;
                }

                $this->releaseChannel();
                $attempt++;

                if ($attempt >= self::RECONNECT_MAX_ATTEMPTS) {
                    throw $exception;
                }

                usleep(self::RECONNECT_RETRY_DELAY_MS * 1000 * $attempt);
            }
        }
    }

    /**
     * A connection exception always means the transport died. Channel-level
     * exceptions are only retryable when they carry no broker error code and
     * the current channel is actually dead — that combination is ext-amqp's
     * "... No channel available." guard failing on a channel orphaned by a
     * closed connection (see issue #23).
     */
    private function isDeadChannelFailure(Throwable $exception): bool
    {
        if ($exception instanceof AMQPConnectionException) {
            return true;
        }

        if ($exception->getCode() !== 0) {
            return false;
        }

        return $this->amqpChannel === null || ! $this->isChannelUsable($this->amqpChannel);
    }

    private function releaseChannel(): void
    {
        if ($this->amqpChannel !== null) {
            $this->poolManager->releaseChannel($this->amqpChannel);
            $this->amqpChannel = null;
        }

        // These helpers hold the channel that was active when they were built;
        // once it's replaced they must be rebuilt against the new one instead
        // of silently operating on a dead channel.
        $this->exchangeManager = null;
        $this->publisherConfirms = null;
        $this->transactionManager = null;
        $this->rpcClient = null;
    }

    /**
     * @throws AMQPChannelException
     */
    public function size($queue = null): int
    {
        $queueName = $this->getQueue($queue);

        return $this->retryOnDeadChannel(function () use ($queueName): int {
            try {
                $amqpQueue = new AMQPQueue($this->getChannel());
                $amqpQueue->setName($queueName);
                $amqpQueue->setFlags(AMQP_PASSIVE);

                return $amqpQueue->declareQueue();
            } catch (AMQPChannelException $exception) {
                if ($exception->getCode() === self::QUEUE_NOT_FOUND_CODE) {
                    $this->releaseChannel();

                    return 0;
                }

                throw $exception;
            }
        });
    }

    public function pendingSize($queue = null): int
    {
        return $this->size($queue);
    }

    public function delayedSize($queue = null): int
    {
        return 0;
    }

    public function reservedSize($queue = null): int
    {
        return 0;
    }

    public function creationTimeOfOldestPendingJob($queue = null): ?int
    {
        return null;
    }

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
     * @throws JsonException
     */
    public function pushRaw($payload, $queue = null, array $options = []): ?string
    {
        $queueName = $this->getQueue($queue);
        $attempts = (int) Arr::get($options, 'attempts', 0);

        $this->declareDestination($queueName, $options);

        return $this->publishMessage($payload, $queueName, $attempts, $options);
    }

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
     * @throws JsonException|AMQPChannelException
     */
    public function laterRaw($delay, $payload, $queue = null, $attempts = 2): ?string
    {
        $ttl = $this->secondsUntil($delay) * 1000;
        $options = ['delay' => $delay, 'attempts' => $attempts];

        if ($ttl <= 0) {
            return $this->pushRaw($payload, $queue, $options);
        }

        $queueName = $this->getQueue($queue);
        $delayQueueName = $queueName.'.delay.'.$ttl;

        $this->declareDestination($queueName, $options);
        $this->declareDelayQueue($delayQueueName, $queueName, $ttl);

        return $this->publishMessage($payload, $delayQueueName, (int) $attempts, $options + ['exchange' => '']);
    }

    /**
     * Publish many jobs while reusing declared topology and channel state.
     *
     * @param  iterable<mixed>  $jobs
     */
    public function bulk($jobs, $data = '', $queue = null): void
    {
        foreach ($jobs as $job) {
            $this->push($job, $data, $queue);
        }
    }

    public function pop($queue = null)
    {
        try {
            $queueName = $this->getQueue($queue);

            if (! $this->queueExists($queueName)) {
                $this->declareQueue($queueName);
            }

            $jobClass = $this->getJobClass();

            $amqpQueue = new AMQPQueue($this->getChannel());
            $amqpQueue->setName($queueName);

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
            if ($exception->getCode() === self::QUEUE_NOT_FOUND_CODE) {
                $this->releaseChannel();

                try {
                    $this->declareQueue($queueName);

                    return $this->pop($queueName);
                } catch (Throwable) {
                    return null;
                }
            }

            throw $exception;
        } catch (AMQPConnectionException $exception) {
            throw new Exception(
                'Lost connection: '.$exception->getMessage(),
                $exception->getCode(),
                $exception
            );
        }
    }

    public function getQueue(?string $queue = null): string
    {
        return $queue ?? $this->defaultQueue;
    }

    public function queueExists(string $queueName): bool
    {
        try {
            $amqpQueue = new AMQPQueue($this->getChannel());
            $amqpQueue->setName($queueName);
            $amqpQueue->setFlags(AMQP_PASSIVE);
            $amqpQueue->declareQueue();

            return true;
        } catch (Throwable $throwable) {
            if ($throwable instanceof AMQPChannelException && $throwable->getCode() === self::QUEUE_NOT_FOUND_CODE) {
                $this->releaseChannel();

                return false;
            }

            return false;
        }
    }

    public function close(): void
    {
        if ($this->rabbitMQJob !== null && ! $this->rabbitMQJob->isDeletedOrReleased()) {
            $this->reject($this->rabbitMQJob, true);
        }

        $this->releaseChannel();
    }

    public function getAmqpChannel(): AMQPChannel
    {
        return $this->getChannel();
    }

    private function getRandomId(): string
    {
        return MessageHelpers::generateCorrelationId();
    }

    public function declareQueue(
        string $name,
        bool $durable = true,
        bool $autoDelete = false,
        array $arguments = []
    ): void {
        $mergedArguments = array_merge($this->getQueueArguments($name), $arguments);

        $this->retryOnDeadChannel(function () use ($name, $durable, $autoDelete, $mergedArguments): void {
            try {
                $amqpQueue = new AMQPQueue($this->getChannel());
                $amqpQueue->setName($name);
                $amqpQueue->setFlags($durable ? AMQP_DURABLE : AMQP_NOPARAM);

                if ($autoDelete) {
                    $amqpQueue->setFlags($amqpQueue->getFlags() | AMQP_AUTODELETE);
                }

                if ($mergedArguments !== []) {
                    $amqpQueue->setArguments($mergedArguments);
                }

                $amqpQueue->declareQueue();
            } catch (AMQPChannelException|AMQPQueueException $exception) {
                if ($exception->getCode() === self::QUEUE_ALREADY_EXISTS_CODE) {
                    return;
                }

                throw $exception;
            }
        });
    }

    private function declareDestination(string $queueName, array $options = []): void
    {
        $exchange = $this->getExchange(Arr::get($options, 'exchange'));

        if ($exchange !== '') {
            $this->declareExchange($exchange, $this->getExchangeType(Arr::get($options, 'exchange_type')));

            return;
        }

        $this->declareQueue($queueName);
    }

    private function declareExchange(string $name, string $type = AMQP_EX_TYPE_DIRECT): void
    {
        $this->retryOnDeadChannel(function () use ($name, $type): void {
            $exchange = new AMQPExchange($this->getChannel());
            $exchange->setName($name);
            $exchange->setType($type);
            $exchange->setFlags(AMQP_DURABLE);
            $exchange->declareExchange();
        });
    }

    private function declareDelayQueue(string $delayQueueName, string $targetQueueName, int $ttl): void
    {
        $arguments = [
            'x-message-ttl' => $ttl,
            'x-expires' => max($ttl * 2, $ttl + 1000),
            'x-dead-letter-exchange' => $this->getExchange(),
            'x-dead-letter-routing-key' => $this->getRoutingKey($targetQueueName),
        ];

        $this->declareQueue($delayQueueName, true, false, $arguments);
        $this->declareDestination($targetQueueName);
    }

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
     * Delivery tags are only meaningful on the channel that delivered the
     * message, so a failed ack/reject can never be retried on a replacement
     * channel: the tag either won't exist there (broker error) or, worse,
     * could collide with an unrelated later delivery on that new channel.
     * On failure we just release the dead channel so the pool doesn't
     * hand it out again; the broker will requeue the message once it
     * notices the channel is gone.
     */
    public function reject(RabbitMQJob $rabbitMQJob, bool $requeue = false): void
    {
        $envelope = $rabbitMQJob->getRabbitMQMessage();
        $deliveryTag = $envelope->getDeliveryTag();

        if ($deliveryTag === null) {
            return;
        }

        // Use the cached channel directly — never getChannel(), which may
        // swap in a replacement where this delivery tag is meaningless or,
        // worse, refers to an unrelated delivery. If the delivering channel
        // is already gone, dropping it is enough: the broker requeues the
        // unacked message once it notices the channel died.
        $channel = $this->amqpChannel;

        if ($channel === null || ! $this->isChannelUsable($channel)) {
            $this->releaseChannel();

            return;
        }

        try {
            $amqpQueue = new AMQPQueue($channel);
            $amqpQueue->setName($rabbitMQJob->getQueue());
            $amqpQueue->reject($deliveryTag, $requeue ? AMQP_REQUEUE : AMQP_NOPARAM);
        } catch (AMQPChannelException|AMQPConnectionException) {
            $this->releaseChannel();
        }
    }

    /**
     * @see reject() for why this does not retry on a replacement channel.
     */
    public function ack(RabbitMQJob $rabbitMQJob): void
    {
        $envelope = $rabbitMQJob->getRabbitMQMessage();
        $deliveryTag = $envelope->getDeliveryTag();

        if ($deliveryTag === null) {
            return;
        }

        $channel = $this->amqpChannel;

        if ($channel === null || ! $this->isChannelUsable($channel)) {
            $this->releaseChannel();

            return;
        }

        try {
            $amqpQueue = new AMQPQueue($channel);
            $amqpQueue->setName($rabbitMQJob->getQueue());
            $amqpQueue->ack($deliveryTag);
        } catch (AMQPChannelException|AMQPConnectionException) {
            $this->releaseChannel();
        }
    }

    public function setOptions(array $options): void
    {
        $this->options = $options;
    }

    public function createMessage($payload, int $attempts = 2): string
    {
        return MessageHelpers::extractCorrelationId($payload) ?? $this->getRandomId();
    }

    public function purgeQueue(string $queueName)
    {
        return $this->retryOnDeadChannel(function () use ($queueName) {
            try {
                $amqpQueue = new AMQPQueue($this->getChannel());
                $amqpQueue->setName($queueName);

                return $amqpQueue->purge();
            } catch (AMQPChannelException $exception) {
                if ($exception->getCode() === self::QUEUE_NOT_FOUND_CODE) {
                    return null;
                }

                throw $exception;
            }
        });
    }

    public function deleteQueue(string $queueName)
    {
        return $this->retryOnDeadChannel(function () use ($queueName) {
            try {
                $amqpQueue = new AMQPQueue($this->getChannel());
                $amqpQueue->setName($queueName);

                return $amqpQueue->delete();
            } catch (AMQPChannelException $exception) {
                if ($exception->getCode() === self::QUEUE_NOT_FOUND_CODE) {
                    return null;
                }

                throw $exception;
            }
        });
    }

    private function publishMessage(string $payload, string $queueName, int $attempts = 2, array $options = []): string
    {
        $correlationId = $this->createMessage($payload, $attempts);
        $messageAttributes = [
            'correlation_id' => $correlationId,
            'delivery_mode' => self::DELIVERY_MODE_PERSISTENT,
            'content_type' => 'application/json',
        ];

        if ($this->shouldPrioritizeDelayed()) {
            $messageAttributes['priority'] = max(0, min($attempts, $this->getQueueMaxPriority()));
        }

        return $this->retryOnDeadChannel(
            fn (): string => $this->doPublish($payload, $queueName, $messageAttributes, $options)
        );
    }

    private function doPublish(string $payload, string $queueName, array $messageAttributes, array $options = []): string
    {
        $exchangeName = $this->getExchange(Arr::get($options, 'exchange'));
        $routingKey = $this->getRoutingKey($queueName);

        $amqpExchange = new AMQPExchange($this->getChannel());
        $amqpExchange->setName($exchangeName);

        if ($this->isPublisherConfirmsEnabled()) {
            $this->getPublisherConfirms()->enable();
        }

        $amqpExchange->publish($payload, $routingKey, AMQP_NOPARAM, $messageAttributes);

        if ($this->isPublisherConfirmsEnabled()) {
            $this->getPublisherConfirms()->waitForConfirms();
        }

        return $messageAttributes['correlation_id'];
    }

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

        if ($lazy) {
            $arguments['x-queue-mode'] = 'lazy';
        }

        if ($priority !== null && $priority > 0) {
            $arguments['x-max-priority'] = min($priority, 255);
        }

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

    public function getExchangeManager(): ExchangeManager
    {
        if ($this->exchangeManager === null) {
            $this->exchangeManager = new ExchangeManager($this->getChannel());
        }

        return $this->exchangeManager;
    }

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

    public function getPublisherConfirms(): PublisherConfirms
    {
        if ($this->publisherConfirms === null) {
            $timeout = config('queue.connections.rabbitmq.publisher_confirms.timeout', 5);
            $this->publisherConfirms = new PublisherConfirms($this->getChannel(), $timeout);
        }

        return $this->publisherConfirms;
    }

    public function getTransactionManager(): TransactionManager
    {
        if ($this->transactionManager === null) {
            $this->transactionManager = new TransactionManager($this->getChannel());
        }

        return $this->transactionManager;
    }

    public function getRpcClient(): RpcClient
    {
        if ($this->rpcClient === null) {
            $timeout = config('queue.connections.rabbitmq.rpc.timeout', 30);
            $this->rpcClient = new RpcClient($this->getChannel(), $timeout);
        }

        return $this->rpcClient;
    }

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

        if ($headers !== []) {
            $attributes['headers'] = $headers;
        }

        return $this->getExchangeManager()->publish(
            $exchangeName,
            $payload,
            $routingKey,
            $attributes
        );
    }

    public function rpcCall(string $queue, string $message, array $headers = []): string
    {
        if (! $this->isRpcEnabled()) {
            throw new Exception('RPC is not enabled in configuration');
        }

        return $this->getRpcClient()->call($queue, $message, $headers);
    }

    public function transaction(callable $callback): mixed
    {
        if (! $this->isTransactionsEnabled()) {
            throw new Exception('Transactions are not enabled in configuration');
        }

        return $this->getTransactionManager()->transaction($callback);
    }

    private function getQueueArguments(string $queueName): array
    {
        $queueConfig = config("queue.connections.rabbitmq.queues.{$queueName}", []);
        $arguments = $queueConfig['arguments'] ?? [];

        if (($queueConfig['lazy'] ?? config('queue.connections.rabbitmq.options.queue.lazy', false)) === true) {
            $arguments['x-queue-mode'] = 'lazy';
        }

        $priority = $queueConfig['priority'] ?? ($this->shouldPrioritizeDelayed() ? $this->getQueueMaxPriority() : null);
        if (is_numeric($priority) && (int) $priority > 0 && ! $this->isQuorumQueue($queueConfig)) {
            $arguments['x-max-priority'] = min((int) $priority, 255);
        }

        if ($this->isQuorumQueue($queueConfig)) {
            $arguments['x-queue-type'] = 'quorum';
        }

        if (config('queue.connections.rabbitmq.reroute_failed', false)) {
            $arguments['x-dead-letter-exchange'] = config('queue.connections.rabbitmq.failed_exchange', '');
            $arguments['x-dead-letter-routing-key'] = $this->getFailedRoutingKey($queueName);
        }

        return $arguments;
    }

    private function isQuorumQueue(array $queueConfig = []): bool
    {
        return (bool) ($queueConfig['quorum'] ?? config('queue.connections.rabbitmq.quorum', false));
    }

    private function getExchange(?string $exchange = null): string
    {
        return $exchange ?? config('queue.connections.rabbitmq.exchange', '');
    }

    private function getExchangeType(?string $type = null): string
    {
        $type = strtolower($type ?? config('queue.connections.rabbitmq.exchange_type', 'direct'));

        return match ($type) {
            'fanout' => AMQP_EX_TYPE_FANOUT,
            'topic' => AMQP_EX_TYPE_TOPIC,
            'headers' => AMQP_EX_TYPE_HEADERS,
            default => AMQP_EX_TYPE_DIRECT,
        };
    }

    private function getRoutingKey(string $queueName): string
    {
        $pattern = (string) config('queue.connections.rabbitmq.exchange_routing_key', '%s');

        return ltrim(sprintf($pattern, $queueName), '.');
    }

    private function getFailedRoutingKey(string $queueName): string
    {
        $pattern = (string) config('queue.connections.rabbitmq.failed_routing_key', '%s.failed');

        return ltrim(sprintf($pattern, $queueName), '.');
    }

    private function shouldPrioritizeDelayed(): bool
    {
        return (bool) config('queue.connections.rabbitmq.prioritize_delayed', false);
    }

    private function getQueueMaxPriority(): int
    {
        return max(1, (int) config('queue.connections.rabbitmq.queue_max_priority', 10));
    }

    private function isPublisherConfirmsEnabled(): bool
    {
        return (bool) config('queue.connections.rabbitmq.publisher_confirms.enabled', false);
    }

    private function isRpcEnabled(): bool
    {
        return (bool) config('queue.connections.rabbitmq.rpc.enabled', false);
    }

    private function isTransactionsEnabled(): bool
    {
        return (bool) config('queue.connections.rabbitmq.transactions.enabled', false);
    }

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

    public function publishDelayed(
        string $queue,
        string $payload,
        int $delay,
        array $headers = []
    ): ?string {
        $delayedConfig = config('queue.connections.rabbitmq.delayed_message', []);

        if ($delayedConfig['plugin_enabled'] ?? false) {
            return $this->publishDelayedWithPlugin($queue, $payload, $delay, $headers);
        }

        return $this->laterRaw($delay, $payload, $queue);
    }

    private function publishDelayedWithPlugin(
        string $queue,
        string $payload,
        int $delay,
        array $headers = []
    ): string {
        $delayedConfig = config('queue.connections.rabbitmq.delayed_message', []);
        $exchangeName = $delayedConfig['exchange'] ?? 'delayed';

        $this->declareDelayedExchange($exchangeName, $this->getExchangeType($delayedConfig['exchange_type'] ?? null));

        $correlationId = $this->createMessage($payload);

        $attributes = [
            'correlation_id' => $correlationId,
            'delivery_mode' => self::DELIVERY_MODE_PERSISTENT,
            'content_type' => 'application/json',
            'headers' => array_merge($headers, [
                'x-delay' => $delay * 1000,
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

    /**
     * Idempotently declare the x-delayed-message exchange the delayed-message
     * plugin path publishes to. Declaring it with the same type/arguments it
     * already has is a no-op; only a genuine mismatch (406) is swallowed here,
     * matching declareQueue()'s already-exists handling.
     */
    private function declareDelayedExchange(string $exchangeName, string $exchangeType): void
    {
        try {
            $this->getExchangeManager()->setupDelayedExchange($exchangeName, $exchangeType);
        } catch (AMQPChannelException $exception) {
            if ($exception->getCode() !== self::QUEUE_ALREADY_EXISTS_CODE) {
                throw $exception;
            }
        }
    }
}
