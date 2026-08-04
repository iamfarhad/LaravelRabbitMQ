<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Jobs;

use AMQPEnvelope;
use iamfarhad\LaravelRabbitMQ\Exceptions\SettlementException;
use iamfarhad\LaravelRabbitMQ\RabbitQueue;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Support\Arr;
use JsonException;
use Throwable;

class RabbitMQJob extends Job implements JobContract
{
    /**
     * Terminal failures are owned by the broker's dead-letter routing.
     */
    public const FAILURE_OWNER_BROKER = 'broker';

    /**
     * Terminal failures are additionally copied to a package-published
     * failure exchange/queue (pre-1.4.0 behaviour).
     */
    public const FAILURE_OWNER_EXCHANGE = 'exchange';

    private const DEFAULT_FAILED_EXCHANGE = 'failed_messages';

    protected array $decoded = [];

    public function __construct(
        Container $container,
        protected RabbitQueue $rabbitQueue,
        protected AMQPEnvelope $amqpEnvelope,
        string $connectionName,
        string $queue
    ) {
        $this->container = $container;
        $this->connectionName = $connectionName;
        $this->queue = $queue;
        $this->decoded = $this->payload();
    }

    public function payload(): array
    {
        try {
            $payload = json_decode($this->getRawBody(), true, 512, JSON_THROW_ON_ERROR);

            if (is_array($payload)) {
                return $payload;
            }
        } catch (JsonException) {
        }

        return [
            'id' => $this->amqpEnvelope->getCorrelationId() ?: null,
            'raw' => $this->getRawBody(),
            'headers' => $this->headers(),
            'displayName' => static::class,
            'job' => static::class,
            'maxTries' => null,
            'maxExceptions' => null,
            'failOnTimeout' => false,
            'backoff' => null,
            'timeout' => null,
            'retryUntil' => null,
            'data' => [],
        ];
    }

    public function getJobId(): ?string
    {
        return $this->decoded['id'] ?? $this->amqpEnvelope->getCorrelationId() ?: null;
    }

    public function getRawBody(): string
    {
        return $this->amqpEnvelope->getBody();
    }

    public function headers(): array
    {
        return (array) ($this->amqpEnvelope->getHeaders() ?: []);
    }

    public function exchangeName(): ?string
    {
        $exchange = $this->amqpEnvelope->getExchangeName();

        return $exchange !== '' ? $exchange : null;
    }

    public function routingKey(): ?string
    {
        if (! method_exists($this->amqpEnvelope, 'getRoutingKey')) {
            return null;
        }

        $routingKey = $this->amqpEnvelope->getRoutingKey();

        return $routingKey !== '' ? $routingKey : null;
    }

    public function deliveryTag(): ?string
    {
        $deliveryTag = $this->amqpEnvelope->getDeliveryTag();

        return $deliveryTag !== null ? (string) $deliveryTag : null;
    }

    private function convertMessageToFailed(): void
    {
        $failedExchange = $this->failedExchange();

        if ($failedExchange === '') {
            return;
        }

        // A message that already lives in the failure destination must never be
        // copied again, otherwise consuming that destination republishes
        // forever. Deliveries through the default exchange carry an empty
        // exchange name, so the queue has to be checked as well.
        if ($this->amqpEnvelope->getExchangeName() === $failedExchange || $this->queue === $failedExchange) {
            return;
        }

        try {
            if (! $this->rabbitQueue->getConnection()->isConnected()) {
                return;
            }

            $this->rabbitQueue->declareQueue($failedExchange);

            // Publish through the default exchange so the copy actually lands
            // in the failure queue declared above, instead of being routed by
            // the connection's configured publishing exchange.
            $this->rabbitQueue->pushRaw($this->amqpEnvelope->getBody(), $failedExchange, ['exchange' => '']);
        } catch (Throwable) {
        }
    }

    /**
     * Which sink owns a permanently failed job. Anything other than an explicit
     * "exchange" selection leaves ownership with the broker, so a queue with
     * dead-letter routing never gets a second, divergent failure record.
     *
     * Override this in a custom job class (`options.queue.job`) to decide
     * ownership per job instead of per connection.
     */
    protected function failureOwner(): string
    {
        $owner = $this->failedConfig('ownership', self::FAILURE_OWNER_BROKER);

        return is_string($owner) && strtolower($owner) === self::FAILURE_OWNER_EXCHANGE
            ? self::FAILURE_OWNER_EXCHANGE
            : self::FAILURE_OWNER_BROKER;
    }

    private function failedExchange(): string
    {
        return (string) $this->failedConfig('exchange', self::DEFAULT_FAILED_EXCHANGE);
    }

    /**
     * Read a `failed.*` setting from this job's own connection, falling back to
     * the package's default connection name for single-connection setups.
     */
    private function failedConfig(string $key, mixed $default): mixed
    {
        return config(
            "queue.connections.{$this->connectionName}.failed.{$key}",
            config("queue.connections.rabbitmq.failed.{$key}", $default)
        );
    }

    public function attempts(): int
    {
        if ($rabbitMQMessageHeaders = $this->getRabbitMQMessageHeaders()) {
            $laravelAttempts = (int) Arr::get($rabbitMQMessageHeaders, 'laravel.attempts', 0);

            return $laravelAttempts + 1;
        }

        $laravelAttempts = (int) Arr::get($this->decoded, 'laravel.attempts', 0);

        return $laravelAttempts + 1;
    }

    /**
     * A terminal failure has exactly one owner. The message is always rejected
     * without requeue — which hands it to the queue's configured
     * `x-dead-letter-exchange`, if any — and only an explicit `exchange`
     * ownership mode additionally publishes the package's own failure copy.
     */
    public function markAsFailed(): void
    {
        parent::markAsFailed();

        // A settlement failure must not escape this method. Laravel calls
        // markAsFailed() *before* the try/finally in Job::fail() that dispatches
        // JobFailed, so throwing here would abort the lifecycle that writes the
        // authoritative failed-job record — losing the durable explanation for a
        // failure that operators need (issue #32). Report it and let the broker
        // redeliver the unresolved delivery instead.
        try {
            $this->rabbitQueue->reject($this);
        } catch (SettlementException $exception) {
            $this->reportSettlementFailure($exception);
        }

        if ($this->failureOwner() === self::FAILURE_OWNER_EXCHANGE) {
            $this->convertMessageToFailed();
        }
    }

    /**
     * Surface a settlement failure through the application's exception handler,
     * the only reporting channel available without aborting the caller.
     */
    private function reportSettlementFailure(SettlementException $exception): void
    {
        if (! $this->container->bound(ExceptionHandler::class)) {
            return;
        }

        $this->container->make(ExceptionHandler::class)->report($exception);
    }

    public function delete(): void
    {
        parent::delete();

        if (! $this->failed) {
            $this->rabbitQueue->ack($this);
        }
    }

    public function release($delay = 0): void
    {
        parent::release();

        $attempts = $this->attempts();

        $this->rabbitQueue->laterRaw($delay, $this->payloadForRelease($attempts), $this->queue, $attempts);
        $this->rabbitQueue->ack($this);
    }

    public function getRabbitMQ(): RabbitQueue
    {
        return $this->rabbitQueue;
    }

    public function getRabbitMQMessage(): AMQPEnvelope
    {
        return $this->amqpEnvelope;
    }

    protected function getRabbitMQMessageHeaders(): ?array
    {
        $headers = $this->headers();

        if ($headers === [] || ! isset($headers['laravel'])) {
            return null;
        }

        return $headers;
    }

    private function payloadForRelease(int $attempts): string
    {
        try {
            $payload = json_decode($this->getRawBody(), true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($payload)) {
                return $this->getRawBody();
            }

            Arr::set($payload, 'laravel.attempts', $attempts);

            $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);

            return is_string($encodedPayload) ? $encodedPayload : $this->getRawBody();
        } catch (JsonException) {
            return $this->getRawBody();
        }
    }
}
