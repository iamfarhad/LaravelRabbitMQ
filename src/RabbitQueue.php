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
use iamfarhad\LaravelRabbitMQ\Exceptions\SettlementException;
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

    private const RECONNECT_BASE_DELAY_MS = 500;

    private const RECONNECT_MAX_DELAY_MS = 5000;

    /**
     * Default granularity, in milliseconds, that delayed-message TTLs are
     * rounded up to. Every distinct TTL needs its own broker-side delay queue,
     * so bucketing keeps arbitrary/jittered backoff values from creating an
     * unbounded number of queues.
     */
    private const DEFAULT_DELAY_GRANULARITY_MS = 1000;

    private ?AMQPChannel $amqpChannel = null;

    private ?RabbitMQJob $rabbitMQJob = null;

    private ?ExchangeManager $exchangeManager = null;

    private ?ExponentialBackoff $backoff = null;

    private ?PublisherConfirms $publisherConfirms = null;

    private ?TransactionManager $transactionManager = null;

    private ?RpcClient $rpcClient = null;

    /**
     * Topology this process has already declared on the current channel, so a
     * hot publish/poll path costs one round trip instead of re-declaring (or
     * passively probing) the same queue, exchange and binding every call.
     * Cleared whenever the channel is replaced — declarations are only known
     * to have reached the broker over the channel that carried them.
     *
     * @var array<string, true>
     */
    private array $declaredTopology = [];

    /**
     * While true, individual publishes skip the confirm wait so a batch can be
     * confirmed once at the end instead of paying a broker round trip each.
     */
    private bool $deferPublisherConfirms = false;

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

    /**
     * Read a setting for *this* connection.
     *
     * Resolution order:
     *   1. this connection's own block — so additional named connections get
     *      their own topology, publisher-confirm, RPC and job-class settings
     *      instead of silently inheriting the first connection's;
     *   2. the package's default `rabbitmq` connection block, keeping
     *      single-connection setups and shared settings working;
     *   3. the package's own published defaults under the `rabbitmq` root key.
     *
     * Step 3 matters because the service provider seeds `queue.connections.*`
     * during register(): anything that rewrites a connection block afterwards
     * would otherwise drop every default. Reading through here makes the result
     * independent of configuration mutation order.
     */
    public function connectionConfig(string $key, mixed $default = null): mixed
    {
        $value = config("queue.connections.{$this->connectionName}.{$key}");

        if ($value !== null) {
            return $value;
        }

        $value = config("queue.connections.rabbitmq.{$key}");

        if ($value !== null) {
            return $value;
        }

        return config("rabbitmq.{$key}", $default);
    }

    /**
     * The connection behind the channel currently in use.
     *
     * Deliberately *not* taken from the connection pool: that would check out a
     * connection nothing ever hands back, exhausting the pool after
     * `pool.max_connections` calls.
     */
    public function getConnection(): AMQPConnection
    {
        return $this->getChannel()->getConnection();
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
     * Flag the current channel as carrying connection-scoped state that cannot
     * be undone (publisher confirms, an AMQP transaction, a QoS prefetch), so
     * the pool retires it instead of handing that state to the next borrower.
     */
    public function markChannelDirty(): void
    {
        if ($this->amqpChannel !== null) {
            $this->poolManager->markChannelDirty($this->amqpChannel);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | ext-amqp object-creation seams
    |--------------------------------------------------------------------------
    | Every ext-amqp object is constructed through these methods and nowhere
    | else, so tests can substitute doubles by subclassing. The alternative —
    | Mockery's `overload:` instance mocking — requires the AMQP classes *not*
    | to exist, which made those tests unrunnable on any machine that actually
    | has the extension the package requires.
    */

    protected function newAmqpQueue(AMQPChannel $channel): AMQPQueue
    {
        return new AMQPQueue($channel);
    }

    protected function newAmqpExchange(AMQPChannel $channel): AMQPExchange
    {
        return new AMQPExchange($channel);
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
        $backoff = $this->reconnectBackoff();

        while (true) {
            try {
                return $operation();
            } catch (AMQPConnectionException|AMQPChannelException|AMQPQueueException|AMQPExchangeException $exception) {
                if (! $this->isDeadChannelFailure($exception)) {
                    throw $exception;
                }

                $this->releaseChannel();

                if (++$attempt >= self::RECONNECT_MAX_ATTEMPTS) {
                    throw $exception;
                }

                // Jittered so a fleet of workers reconnecting after a broker
                // restart does not synchronise into a thundering herd.
                usleep($backoff->getDelayForAttempt($attempt - 1) * 1000);
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

        // Declarations are only proven to have reached the broker over the
        // channel that carried them.
        $this->declaredTopology = [];
    }

    /**
     * @throws AMQPChannelException
     */
    public function size($queue = null): int
    {
        $queueName = $this->getQueue($queue);

        return $this->retryOnDeadChannel(function () use ($queueName): int {
            try {
                $amqpQueue = $this->newAmqpQueue($this->getChannel());
                $amqpQueue->setName($queueName);
                $amqpQueue->setFlags(AMQP_PASSIVE);

                return $amqpQueue->declareQueue();
            } catch (AMQPChannelException|AMQPQueueException $exception) {
                // ext-amqp reports NOT_FOUND on a passive declare as an
                // AMQPQueueException, so catching only AMQPChannelException made
                // size() throw for a queue that simply does not exist yet —
                // which Horizon polls for metrics.
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

    /**
     * Delayed jobs live in short-lived per-TTL delay queues whose names are not
     * enumerable over AMQP 0-9-1, so this cannot be answered without the
     * management HTTP API. Reported as zero rather than guessed.
     */
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
        return $this->publishRaw((string) $payload, $queue, $options);
    }

    /**
     * The actual raw publish.
     *
     * Kept separate from pushRaw() so laterRaw() can reach it without
     * re-entering a subclass override of pushRaw() — HorizonRabbitQueue
     * would otherwise wrap the payload and dispatch its events twice for any
     * delayed job whose delay resolves to zero.
     *
     * @throws JsonException
     */
    protected function publishRaw(string $payload, ?string $queue = null, array $options = []): string
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
    public function laterRaw($delay, $payload, $queue = null, $attempts = 0): ?string
    {
        $ttl = $this->secondsUntil($delay) * 1000;
        $options = ['delay' => $delay, 'attempts' => $attempts];

        if ($ttl <= 0) {
            return $this->publishRaw((string) $payload, $queue, $options);
        }

        $ttl = $this->bucketDelayTtl($ttl);
        $queueName = $this->getQueue($queue);
        $delayQueueName = $queueName.'.delay.'.$ttl;

        $this->declareDestination($queueName, $options);
        $this->declareDelayQueue($delayQueueName, $queueName, $ttl);

        // Published straight to the delay queue through the default exchange,
        // which routes on the literal queue name.
        return $this->publishMessage(
            (string) $payload,
            $delayQueueName,
            (int) $attempts,
            $options + ['exchange' => '', 'routing_key' => $delayQueueName]
        );
    }

    /**
     * Round a delay up to the configured bucket so distinct-but-similar delays
     * share one broker-side delay queue. Rounding up never fires a job early.
     */
    private function bucketDelayTtl(int $ttl): int
    {
        $granularity = (int) $this->connectionConfig(
            'delay_queue_granularity',
            self::DEFAULT_DELAY_GRANULARITY_MS
        );

        if ($granularity <= 1) {
            return $ttl;
        }

        return (int) (ceil($ttl / $granularity) * $granularity);
    }

    /**
     * Publish many jobs while reusing declared topology and channel state.
     *
     * With publisher confirms enabled the whole batch is confirmed once at the
     * end instead of paying a broker round trip per message.
     *
     * @param  iterable<mixed>  $jobs
     */
    public function bulk($jobs, $data = '', $queue = null): void
    {
        $this->deferPublisherConfirms = $this->isPublisherConfirmsEnabled();

        try {
            foreach ($jobs as $job) {
                $this->push($job, $data, $queue);
            }

            // Only wait when something is actually outstanding. waitForConfirm()
            // blocks for the full timeout when the broker has nothing to
            // confirm, which is exactly the case under `after_commit`, where
            // enqueueUsing() defers every publish past this point.
            if ($this->publisherConfirms !== null && $this->publisherConfirms->getPendingCount() > 0) {
                $this->publisherConfirms->waitForConfirms();
            }
        } finally {
            $this->deferPublisherConfirms = false;
        }
    }

    public function pop($queue = null)
    {
        $queueName = $this->getQueue($queue);

        try {
            // Idempotent and memoised: the first poll declares, every later one
            // is a single basic.get instead of a passive probe plus a get.
            $this->declareConfiguredQueue($queueName);

            $jobClass = $this->getJobClass();

            $amqpQueue = $this->newAmqpQueue($this->getChannel());
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
                    $this->declareConfiguredQueue($queueName);

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

    /**
     * Whether the queue exists, using a passive declare.
     *
     * Any channel-level error closes the channel broker-side, so the channel is
     * always retired here. Only 404 means "absent": every other broker refusal
     * (403 access-refused, for example) is reported rather than disguised as a
     * missing queue.
     *
     * @throws AMQPChannelException|AMQPQueueException
     */
    public function queueExists(string $queueName): bool
    {
        try {
            $amqpQueue = $this->newAmqpQueue($this->getChannel());
            $amqpQueue->setName($queueName);
            $amqpQueue->setFlags(AMQP_PASSIVE);
            $amqpQueue->declareQueue();

            return true;
        } catch (AMQPChannelException|AMQPQueueException $exception) {
            $this->releaseChannel();

            if ($exception->getCode() === self::QUEUE_NOT_FOUND_CODE) {
                return false;
            }

            throw $exception;
        }
    }

    public function close(): void
    {
        if ($this->rabbitMQJob !== null && ! $this->rabbitMQJob->isDeletedOrReleased()) {
            try {
                $this->reject($this->rabbitMQJob, true);
            } catch (SettlementException) {
                // Shutdown path: closing the channel below is itself enough for
                // the broker to requeue an unresolved delivery, and throwing
                // here would abort the rest of the shutdown.
            }
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
        $memoKey = 'queue:'.$name.':'.md5(serialize([$durable, $autoDelete, $mergedArguments]));

        if (isset($this->declaredTopology[$memoKey])) {
            return;
        }

        $this->retryOnDeadChannel(function () use ($name, $durable, $autoDelete, $mergedArguments): void {
            try {
                $amqpQueue = $this->newAmqpQueue($this->getChannel());
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
                    $this->reportTopologyMismatch('queue', $name, $exception);

                    return;
                }

                throw $exception;
            }
        });

        $this->declaredTopology[$memoKey] = true;
    }

    /**
     * Declare a queue honouring its `queues.<queue>.durable` and
     * `queues.<queue>.auto_delete` configuration.
     *
     * Prefer this over declareQueue() for queues named in the configuration:
     * declareQueue()'s own defaults would otherwise contradict a queue
     * configured as non-durable or auto-delete and be refused with a 406.
     */
    public function declareConfiguredQueue(string $queueName): void
    {
        $queueConfig = (array) $this->connectionConfig("queues.{$queueName}", []);

        $this->declareQueue(
            $queueName,
            (bool) ($queueConfig['durable'] ?? true),
            (bool) ($queueConfig['auto_delete'] ?? false)
        );
    }

    /**
     * Queue and exchange arguments are immutable in RabbitMQ, so a changed
     * `quorum`, `lazy`, priority or dead-letter setting is refused with 406 and
     * the existing topology keeps its original arguments. Swallowing that
     * silently makes the configuration look applied when it is not, so it is
     * always surfaced through the application log.
     *
     * The broker also closes the channel on 406, so it must not be reused.
     */
    private function reportTopologyMismatch(string $kind, string $name, Throwable $exception): void
    {
        $this->releaseChannel();

        $this->warning(sprintf(
            'RabbitMQ refused to redeclare %s [%s] with the configured arguments (PRECONDITION_FAILED). '
            .'The existing %s keeps its original arguments; delete and redeclare it to apply the new configuration. Broker said: %s',
            $kind,
            $name,
            $kind,
            $exception->getMessage()
        ));
    }

    private function warning(string $message, array $context = []): void
    {
        if ($this->container === null || ! $this->container->bound('log')) {
            return;
        }

        try {
            $this->container->make('log')->warning($message, $context);
        } catch (Throwable) {
            // Logging must never break a publish or a poll.
        }
    }

    /**
     * Make sure a published message can actually reach the queue.
     *
     * The queue is always declared, because it is what consumers read from.
     * When a non-default exchange is configured the exchange is declared *and*
     * the queue is bound to it — without that binding the broker silently drops
     * every published message (publisher confirms still ACK an unroutable
     * message, so nothing surfaces the loss).
     */
    private function declareDestination(string $queueName, array $options = []): void
    {
        $this->declareConfiguredQueue($queueName);
        $this->declareConfiguredBindings($queueName);

        $exchange = $this->getExchange(Arr::get($options, 'exchange'));

        if ($exchange === '') {
            // Every queue is implicitly bound to the default exchange by name.
            return;
        }

        $this->declareExchange($exchange, $this->getExchangeType(Arr::get($options, 'exchange_type')));
        $this->bindQueue($queueName, $exchange, $this->getRoutingKey($queueName));
    }

    /**
     * Apply any additional `queues.<queue>.bindings` from the configuration.
     */
    private function declareConfiguredBindings(string $queueName): void
    {
        $bindings = Arr::get((array) $this->connectionConfig("queues.{$queueName}", []), 'bindings', []);

        if (! is_array($bindings)) {
            return;
        }

        foreach ($bindings as $binding) {
            if (! is_array($binding)) {
                continue;
            }

            $exchange = (string) ($binding['exchange'] ?? '');

            if ($exchange === '') {
                continue;
            }

            $this->declareExchange($exchange, $this->getExchangeType($binding['exchange_type'] ?? null));
            $this->bindQueue(
                $queueName,
                $exchange,
                (string) ($binding['routing_key'] ?? ''),
                (array) ($binding['arguments'] ?? [])
            );
        }
    }

    private function declareExchange(string $name, string $type = AMQP_EX_TYPE_DIRECT): void
    {
        $memoKey = 'exchange:'.$name.':'.$type;

        if (isset($this->declaredTopology[$memoKey])) {
            return;
        }

        $this->retryOnDeadChannel(function () use ($name, $type): void {
            try {
                $exchange = $this->newAmqpExchange($this->getChannel());
                $exchange->setName($name);
                $exchange->setType($type);
                $exchange->setFlags(AMQP_DURABLE);
                $exchange->declareExchange();
            } catch (AMQPChannelException|AMQPExchangeException $exception) {
                if ($exception->getCode() === self::QUEUE_ALREADY_EXISTS_CODE) {
                    $this->reportTopologyMismatch('exchange', $name, $exception);

                    return;
                }

                throw $exception;
            }
        });

        $this->declaredTopology[$memoKey] = true;
    }

    private function bindQueue(string $queueName, string $exchangeName, string $routingKey, array $arguments = []): void
    {
        $memoKey = 'binding:'.$queueName.':'.$exchangeName.':'.$routingKey.':'.md5(serialize($arguments));

        if (isset($this->declaredTopology[$memoKey])) {
            return;
        }

        $this->retryOnDeadChannel(function () use ($queueName, $exchangeName, $routingKey, $arguments): void {
            $amqpQueue = $this->newAmqpQueue($this->getChannel());
            $amqpQueue->setName($queueName);
            $amqpQueue->bind($exchangeName, $routingKey, $arguments);
        });

        $this->declaredTopology[$memoKey] = true;
    }

    private function declareDelayQueue(string $delayQueueName, string $targetQueueName, int $ttl): void
    {
        $arguments = [
            'x-message-ttl' => $ttl,
            'x-expires' => max($ttl * 2, $ttl + 1000),
            'x-dead-letter-exchange' => $this->getExchange(),
            'x-dead-letter-routing-key' => $this->deadLetterRoutingKeyFor($targetQueueName),
        ];

        // Passed explicitly so the delay queue never inherits the target
        // queue's own arguments (quorum type, priority, its own DLX, ...).
        $this->declareRawQueue($delayQueueName, $arguments);
    }

    /**
     * The routing key that gets a dead-lettered message back to its target
     * queue: the literal queue name over the default exchange, the configured
     * pattern over a real exchange the queue is bound to.
     */
    private function deadLetterRoutingKeyFor(string $queueName): string
    {
        return $this->getExchange() === '' ? $queueName : $this->getRoutingKey($queueName);
    }

    /**
     * Declare a queue with exactly the given arguments, bypassing the
     * connection-wide queue argument defaults.
     */
    private function declareRawQueue(string $name, array $arguments): void
    {
        $memoKey = 'queue:'.$name.':'.md5(serialize([true, false, $arguments]));

        if (isset($this->declaredTopology[$memoKey])) {
            return;
        }

        $this->retryOnDeadChannel(function () use ($name, $arguments): void {
            try {
                $amqpQueue = $this->newAmqpQueue($this->getChannel());
                $amqpQueue->setName($name);
                $amqpQueue->setFlags(AMQP_DURABLE);
                $amqpQueue->setArguments($arguments);
                $amqpQueue->declareQueue();
            } catch (AMQPChannelException|AMQPQueueException $exception) {
                if ($exception->getCode() === self::QUEUE_ALREADY_EXISTS_CODE) {
                    $this->reportTopologyMismatch('queue', $name, $exception);

                    return;
                }

                throw $exception;
            }
        });

        $this->declaredTopology[$memoKey] = true;
    }

    /**
     * @return class-string<RabbitMQJob>
     */
    public function getJobClass(): string
    {
        // Deliberately untyped: this is unvalidated configuration, so the checks
        // below are what establish the class-string<RabbitMQJob> guarantee.
        $job = $this->connectionConfig('options.queue.job', RabbitMQJob::class);

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
     * On failure we release the dead channel so the pool doesn't hand it out
     * again — that release is also what lets the broker redeliver the
     * unresolved delivery — and throw, because releasing a channel proves
     * nothing about whether the broker settled the delivery (issues #31, #33).
     *
     * @throws SettlementException when the delivery could not be settled
     */
    public function reject(RabbitMQJob $rabbitMQJob, bool $requeue = false): void
    {
        $this->settle(
            'reject',
            $rabbitMQJob,
            static function (AMQPQueue $amqpQueue, int|string $deliveryTag) use ($requeue): void {
                $amqpQueue->reject($deliveryTag, $requeue ? AMQP_REQUEUE : AMQP_NOPARAM);
            }
        );
    }

    /**
     * @see reject() for why this does not retry on a replacement channel.
     *
     * @throws SettlementException when the delivery could not be settled
     */
    public function ack(RabbitMQJob $rabbitMQJob): void
    {
        $this->settle(
            'ack',
            $rabbitMQJob,
            static function (AMQPQueue $amqpQueue, int|string $deliveryTag): void {
                $amqpQueue->ack($deliveryTag);
            }
        );
    }

    /**
     * Settle a delivery with the broker, reporting failure instead of hiding it.
     *
     * A settlement that does not reach the broker leaves the delivery unresolved
     * and eligible for redelivery, so silently returning would let callers treat
     * an unresolved delivery as handled. Every failure path therefore releases
     * the unusable delivering channel — which is what lets the broker redeliver —
     * and throws.
     *
     * @param  callable(AMQPQueue, int|string): void  $settlement
     *
     * @throws SettlementException
     */
    private function settle(string $operation, RabbitMQJob $rabbitMQJob, callable $settlement): void
    {
        $queueName = $rabbitMQJob->getQueue();
        $deliveryTag = $rabbitMQJob->getRabbitMQMessage()->getDeliveryTag();

        if ($deliveryTag === null) {
            throw SettlementException::missingDeliveryTag($operation, $queueName);
        }

        // Use the cached channel directly — never getChannel(), which may
        // swap in a replacement where this delivery tag is meaningless or,
        // worse, refers to an unrelated delivery.
        $channel = $this->amqpChannel;

        if ($channel === null || ! $this->isChannelUsable($channel)) {
            $this->releaseChannel();

            throw SettlementException::channelUnusable($operation, $queueName);
        }

        try {
            $amqpQueue = $this->newAmqpQueue($channel);
            $amqpQueue->setName($queueName);
            $settlement($amqpQueue, $deliveryTag);
        } catch (AMQPChannelException|AMQPConnectionException|AMQPQueueException $exception) {
            $this->releaseChannel();

            throw SettlementException::brokerRefused($operation, $queueName, $exception);
        }
    }

    public function setOptions(array $options): void
    {
        $this->options = $options;
    }

    /**
     * The correlation ID a payload will be published with: the Laravel job's
     * own UUID when present, otherwise a fresh one.
     */
    public function correlationIdFor(?string $payload): string
    {
        return MessageHelpers::extractCorrelationId($payload) ?? $this->getRandomId();
    }

    /**
     * @deprecated Use correlationIdFor(); this never created a message and
     *             ignores $attempts. Kept for backward compatibility.
     */
    public function createMessage($payload, int $attempts = 2): string
    {
        return $this->correlationIdFor($payload);
    }

    public function purgeQueue(string $queueName)
    {
        return $this->retryOnDeadChannel(function () use ($queueName) {
            try {
                $amqpQueue = $this->newAmqpQueue($this->getChannel());
                $amqpQueue->setName($queueName);

                return $amqpQueue->purge();
            } catch (AMQPChannelException|AMQPQueueException $exception) {
                if ($exception->getCode() === self::QUEUE_NOT_FOUND_CODE) {
                    $this->releaseChannel();

                    return null;
                }

                throw $exception;
            }
        });
    }

    public function deleteQueue(string $queueName)
    {
        $result = $this->retryOnDeadChannel(function () use ($queueName) {
            try {
                $amqpQueue = $this->newAmqpQueue($this->getChannel());
                $amqpQueue->setName($queueName);

                return $amqpQueue->delete();
            } catch (AMQPChannelException|AMQPQueueException $exception) {
                if ($exception->getCode() === self::QUEUE_NOT_FOUND_CODE) {
                    $this->releaseChannel();

                    return null;
                }

                throw $exception;
            }
        });

        // The queue is gone; anything memoised about it no longer holds.
        $this->declaredTopology = [];

        return $result;
    }

    private function publishMessage(string $payload, string $queueName, int $attempts = 2, array $options = []): string
    {
        $correlationId = $this->correlationIdFor($payload);
        $messageAttributes = [
            'correlation_id' => $correlationId,
            'delivery_mode' => self::DELIVERY_MODE_PERSISTENT,
            'content_type' => 'application/json',
            // Mirrors the attempt counter carried in the payload body so
            // attempts survive for producers that do not use Laravel's payload
            // shape at all.
            'headers' => ['laravel' => ['attempts' => $attempts]],
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
        $routingKey = $this->resolveRoutingKey($exchangeName, $queueName, $options);
        $confirmsEnabled = $this->isPublisherConfirmsEnabled();

        $amqpExchange = $this->newAmqpExchange($this->getChannel());
        $amqpExchange->setName($exchangeName);

        if ($confirmsEnabled) {
            $confirms = $this->getPublisherConfirms();
            $confirms->enable();
            $confirms->registerPendingConfirm($messageAttributes['correlation_id']);
        }

        $amqpExchange->publish($payload, $routingKey, $this->publishFlags(), $messageAttributes);

        if ($confirmsEnabled && ! $this->deferPublisherConfirms) {
            $this->getPublisherConfirms()->waitForConfirms();
        }

        return $messageAttributes['correlation_id'];
    }

    /**
     * The default exchange routes solely on the literal queue name; applying the
     * configured routing-key pattern there would produce a key that matches
     * nothing and the message would be dropped without any error.
     */
    private function resolveRoutingKey(string $exchangeName, string $queueName, array $options): string
    {
        $explicit = Arr::get($options, 'routing_key');

        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        return $exchangeName === '' ? $queueName : $this->getRoutingKey($queueName);
    }

    /**
     * AMQP_MANDATORY makes the broker return an unroutable message instead of
     * discarding it. It is only safe with publisher confirms enabled, because
     * that is where the basic.return handler lives that turns the return into
     * an error the caller can see.
     */
    private function publishFlags(): int
    {
        if (! $this->isPublisherConfirmsEnabled()) {
            return AMQP_NOPARAM;
        }

        return (bool) $this->connectionConfig('publisher_confirms.mandatory', false)
            ? AMQP_MANDATORY
            : AMQP_NOPARAM;
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
            $config = (array) $this->connectionConfig('backoff', []);
            $this->backoff = new ExponentialBackoff(
                (int) ($config['base_delay'] ?? 1000),
                (int) ($config['max_delay'] ?? 60000),
                (float) ($config['multiplier'] ?? 2.0),
                (bool) ($config['jitter'] ?? true)
            );
        }

        return $this->backoff;
    }

    /**
     * Bounded, jittered backoff used only for replacing a dead channel. Kept
     * separate from the job backoff so a 60s job backoff never turns a channel
     * swap into a minute-long stall.
     */
    private function reconnectBackoff(): ExponentialBackoff
    {
        $config = (array) $this->connectionConfig('backoff', []);

        return new ExponentialBackoff(
            self::RECONNECT_BASE_DELAY_MS,
            self::RECONNECT_MAX_DELAY_MS,
            2.0,
            (bool) ($config['jitter'] ?? true)
        );
    }

    public function getPublisherConfirms(): PublisherConfirms
    {
        if ($this->publisherConfirms === null) {
            $timeout = (int) $this->connectionConfig('publisher_confirms.timeout', 5);
            $this->publisherConfirms = new PublisherConfirms($this->getChannel(), $timeout);

            // confirm.select cannot be undone on a channel, so this channel must
            // not be handed to a borrower that does not expect confirm mode.
            $this->markChannelDirty();
        }

        return $this->publisherConfirms;
    }

    public function getTransactionManager(): TransactionManager
    {
        if ($this->transactionManager === null) {
            $this->transactionManager = new TransactionManager($this->getChannel());
            $this->markChannelDirty();
        }

        return $this->transactionManager;
    }

    public function getRpcClient(): RpcClient
    {
        if ($this->rpcClient === null) {
            $timeout = (int) $this->connectionConfig('rpc.timeout', 30);
            $prefix = (string) $this->connectionConfig('rpc.callback_queue_prefix', '');
            $this->rpcClient = new RpcClient($this->getChannel(), $timeout, $prefix);
        }

        return $this->rpcClient;
    }

    public function publishToExchange(
        string $exchangeName,
        string $payload,
        string $routingKey = '',
        array $headers = []
    ): bool {
        $attributes = [
            'correlation_id' => $this->correlationIdFor($payload),
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

        // A channel cannot be in confirm mode and transaction mode at once;
        // RabbitMQ refuses the second select and closes the channel.
        if ($this->isPublisherConfirmsEnabled()) {
            throw new Exception(
                'AMQP transactions cannot be used while publisher confirms are enabled on the same connection. '
                .'Disable publisher_confirms.enabled or transactions.enabled.'
            );
        }

        return $this->getTransactionManager()->transaction($callback);
    }

    private function getQueueArguments(string $queueName): array
    {
        $queueConfig = (array) $this->connectionConfig("queues.{$queueName}", []);
        $arguments = (array) ($queueConfig['arguments'] ?? []);

        if (($queueConfig['lazy'] ?? $this->connectionConfig('options.queue.lazy', false)) === true) {
            $arguments['x-queue-mode'] = 'lazy';
        }

        $priority = $queueConfig['priority'] ?? ($this->shouldPrioritizeDelayed() ? $this->getQueueMaxPriority() : null);
        if (is_numeric($priority) && (int) $priority > 0 && ! $this->isQuorumQueue($queueConfig)) {
            $arguments['x-max-priority'] = min((int) $priority, 255);
        }

        if ($this->isQuorumQueue($queueConfig)) {
            $arguments['x-queue-type'] = 'quorum';
        }

        if ($this->connectionConfig('reroute_failed', false)) {
            $arguments['x-dead-letter-exchange'] = (string) $this->connectionConfig('failed_exchange', '');
            $arguments['x-dead-letter-routing-key'] = $this->getFailedRoutingKey($queueName);
        }

        return $arguments;
    }

    private function isQuorumQueue(array $queueConfig = []): bool
    {
        return (bool) ($queueConfig['quorum'] ?? $this->connectionConfig('quorum', false));
    }

    private function getExchange(?string $exchange = null): string
    {
        return $exchange ?? (string) $this->connectionConfig('exchange', '');
    }

    private function getExchangeType(?string $type = null): string
    {
        $type = strtolower($type ?? (string) $this->connectionConfig('exchange_type', 'direct'));

        return match ($type) {
            'fanout' => AMQP_EX_TYPE_FANOUT,
            'topic' => AMQP_EX_TYPE_TOPIC,
            'headers' => AMQP_EX_TYPE_HEADERS,
            default => AMQP_EX_TYPE_DIRECT,
        };
    }

    private function getRoutingKey(string $queueName): string
    {
        $pattern = (string) $this->connectionConfig('exchange_routing_key', '%s');

        return ltrim(sprintf($pattern, $queueName), '.');
    }

    private function getFailedRoutingKey(string $queueName): string
    {
        $pattern = (string) $this->connectionConfig('failed_routing_key', '%s.failed');

        return ltrim(sprintf($pattern, $queueName), '.');
    }

    private function shouldPrioritizeDelayed(): bool
    {
        return (bool) $this->connectionConfig('prioritize_delayed', false);
    }

    private function getQueueMaxPriority(): int
    {
        return max(1, (int) $this->connectionConfig('queue_max_priority', 10));
    }

    private function isPublisherConfirmsEnabled(): bool
    {
        return (bool) $this->connectionConfig('publisher_confirms.enabled', false);
    }

    private function isRpcEnabled(): bool
    {
        return (bool) $this->connectionConfig('rpc.enabled', false);
    }

    private function isTransactionsEnabled(): bool
    {
        return (bool) $this->connectionConfig('transactions.enabled', false);
    }

    public function setupDeadLetterExchange(
        string $queueName,
        ?string $dlxName = null,
        ?string $dlxRoutingKey = null
    ): void {
        $dlxConfig = (array) $this->connectionConfig('dead_letter', []);

        if (! ($dlxConfig['enabled'] ?? true)) {
            return;
        }

        $this->getExchangeManager()->setupDeadLetterExchange(
            $queueName,
            $dlxName ?? (string) ($dlxConfig['exchange'] ?? 'dlx'),
            (string) ($dlxConfig['exchange_type'] ?? 'direct'),
            $dlxRoutingKey,
            (string) ($dlxConfig['queue_suffix'] ?? '.dlq'),
            isset($dlxConfig['ttl']) && is_numeric($dlxConfig['ttl']) ? (int) $dlxConfig['ttl'] : null
        );

        $this->declaredTopology = [];
    }

    public function publishDelayed(
        string $queue,
        string $payload,
        int $delay,
        array $headers = []
    ): ?string {
        $delayedConfig = (array) $this->connectionConfig('delayed_message', []);

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
        $delayedConfig = (array) $this->connectionConfig('delayed_message', []);
        $exchangeName = (string) ($delayedConfig['exchange'] ?? 'delayed');
        $exchangeType = $this->getExchangeType($delayedConfig['exchange_type'] ?? null);

        $this->declareDelayedExchange($exchangeName, $exchangeType);

        // The plugin routes on the wrapped exchange type, so the target queue
        // still has to exist and be bound to the delayed exchange.
        $this->declareConfiguredQueue($queue);
        $this->bindQueue($queue, $exchangeName, $queue);

        $correlationId = $this->correlationIdFor($payload);

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
     * already has is a no-op; only a genuine mismatch (406) is reported here,
     * matching declareQueue()'s already-exists handling.
     */
    private function declareDelayedExchange(string $exchangeName, string $exchangeType): void
    {
        $memoKey = 'delayed-exchange:'.$exchangeName.':'.$exchangeType;

        if (isset($this->declaredTopology[$memoKey])) {
            return;
        }

        try {
            $this->getExchangeManager()->setupDelayedExchange($exchangeName, $exchangeType);
        } catch (AMQPChannelException|AMQPExchangeException $exception) {
            if ($exception->getCode() !== self::QUEUE_ALREADY_EXISTS_CODE) {
                throw $exception;
            }

            $this->reportTopologyMismatch('exchange', $exchangeName, $exception);

            return;
        }

        $this->declaredTopology[$memoKey] = true;
    }
}
